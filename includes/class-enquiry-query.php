<?php
/**
 * Pure filter-to-SQL translation for the enquiry list.
 *
 * The list route combines up to seven filters, has to return the same rows in
 * the same order for two requests that supply the same parameters in a
 * different order (Requirement 12.13), and has to bind every submitted value
 * rather than concatenate it into SQL (Requirement 3.10).
 *
 * This class does that translation and nothing else: it touches no `$wpdb`,
 * runs no query and reads no option, so the whole filter matrix is exercisable
 * without a database. `EnquiryStore` owns composing the fragments returned here
 * into a statement and handing the bindings to `$wpdb->prepare()`.
 *
 * Two conventions the caller has to honour:
 *
 * - The enquiry table is aliased `e` (self::ALIAS) in the composed statement,
 *   because every column reference produced here is alias-qualified.
 * - The candidate-date condition names the dates table through the
 *   self::DATES_TABLE token, which the caller replaces with the real, prefixed
 *   table name. A caller that already knows that name can pass it as
 *   `build( $args, array( 'dates' => … ) )` and get final SQL back instead.
 *   The token exists because resolving `{$wpdb->prefix}meh_enquiry_dates` here
 *   would mean reading `$wpdb`, which is exactly what this class must not do.
 *
 * `bindings` covers the placeholders in `where` only, in the order they appear,
 * so the same pair drives both the list query and the matching count query.
 * `order` and `limit` carry no placeholders: their values are whole numbers and
 * a fixed column whitelist, never submitted text.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnquiryQuery
 */
class EnquiryQuery {

	/**
	 * Alias the composed statement must give the enquiry table.
	 */
	const ALIAS = 'e';

	/**
	 * Alias used for the candidate-date table inside the EXISTS subquery.
	 */
	const DATES_ALIAS = 'd';

	/**
	 * Placeholder for the candidate-date table name.
	 *
	 * Deliberately not a `$wpdb->prepare()` placeholder, so a caller that
	 * forgets to substitute it gets a loud SQL error rather than a silently
	 * wrong result set.
	 */
	const DATES_TABLE = '{{dates}}';

	/**
	 * The `status` value meaning "every status" (Requirement 12.3).
	 */
	const STATUS_ALL = 'all';

	/**
	 * Recognised enquiry statuses, used when `Lifecycle` is not loaded.
	 *
	 * self::statuses() prefers `Lifecycle::STATUSES` so the two cannot drift.
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * Columns a search term is matched against (Requirement 12.4).
	 */
	const SEARCH_COLUMNS = array( 'first_name', 'last_name', 'email', 'phone', 'message' );

	/**
	 * Columns that may be sorted on.
	 */
	const ORDERBY_COLUMNS = array( 'created_at', 'updated_at', 'status_changed_at', 'id' );

	/**
	 * Default page size (Requirement 12.10).
	 */
	const PER_PAGE_DEFAULT = 25;

	/**
	 * Maximum page size (Requirement 12.10).
	 */
	const PER_PAGE_MAX = 200;

	/**
	 * The full argument set with its defaults, in canonical key order.
	 */
	const DEFAULTS = array(
		'date_from' => '',
		'date_to'   => '',
		'from'      => '',
		'hide_test' => false,
		'order'     => 'desc',
		'orderby'   => 'created_at',
		'page'      => 1,
		'per_page'  => self::PER_PAGE_DEFAULT,
		's'         => '',
		'status'    => self::STATUS_ALL,
		'to'        => '',
	);

	/**
	 * Recognised statuses, taken from the lifecycle when it is available.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		$class = __NAMESPACE__ . '\Lifecycle';

		if ( class_exists( $class ) && defined( $class . '::STATUSES' ) ) {
			$statuses = constant( $class . '::STATUSES' );

			if ( is_array( $statuses ) && $statuses ) {
				return array_values( $statuses );
			}
		}

		return self::STATUSES;
	}

	/**
	 * Reduce a submitted argument set to the canonical normalised set.
	 *
	 * Applies every default, coerces each value to the one type its filter
	 * accepts, caps `per_page`, drops any key that is not a supported filter,
	 * and returns the result in a fixed key order. Two argument sets holding
	 * the same names and values in any order therefore normalise to arrays that
	 * are identical under `===`, which is what makes the confluence property
	 * hold (Requirement 12.13).
	 *
	 * Idempotent: normalising an already-normalised set returns it unchanged.
	 *
	 * @param array $args Raw arguments, typically the sanitized request params.
	 * @return array Normalised arguments in canonical key order.
	 */
	public static function normalise( array $args ) {
		$normalised = array(
			'date_from' => self::to_date( self::arg( $args, 'date_from' ) ),
			'date_to'   => self::to_date( self::arg( $args, 'date_to' ) ),
			'from'      => self::to_date( self::arg( $args, 'from' ) ),
			'hide_test' => self::to_bool( self::arg( $args, 'hide_test' ) ),
			'order'     => self::to_order( self::arg( $args, 'order' ) ),
			'orderby'   => self::to_orderby( self::arg( $args, 'orderby' ) ),
			'page'      => self::to_page( self::arg( $args, 'page' ) ),
			'per_page'  => self::to_per_page( self::arg( $args, 'per_page' ) ),
			's'         => self::to_search( self::arg( $args, 's' ) ),
			'status'    => self::to_status( self::arg( $args, 'status' ) ),
			'to'        => self::to_date( self::arg( $args, 'to' ) ),
		);

		// Canonical order regardless of the literal above, so a key added later
		// cannot leave the order depending on where it was written.
		ksort( $normalised );

		return $normalised;
	}

	/**
	 * Translate an argument set into SQL fragments and their bindings.
	 *
	 * Every supplied filter is applied conjunctively (Requirement 12.12) and
	 * every submitted value goes into `bindings` (Requirement 3.10). A lone
	 * `date_from` or `date_to` applies no candidate-date filter and reports a
	 * warning naming the parameter that is missing (Requirement 12.8).
	 *
	 * @param array $args   Raw or normalised arguments; normalised internally.
	 * @param array $tables Optional real table names, currently only `dates`.
	 *                      Defaults to the self::DATES_TABLE token.
	 * @return array{where:string, bindings:array, order:string, limit:string, warnings:array}
	 */
	public static function build( array $args, array $tables = array() ) {
		$args     = self::normalise( $args );
		$where    = array();
		$bindings = array();
		$warnings = array();

		// Requirements 12.2, 12.3: a recognised status filters, `all` does not.
		if ( self::STATUS_ALL !== $args['status'] ) {
			$where[]    = self::ALIAS . '.status = %s';
			$bindings[] = $args['status'];
		}

		// Requirement 12.4: case-insensitive substring match across five
		// columns. Case-insensitivity comes from the utf8mb4 collation of the
		// columns themselves; esc_like() escapes `%` and `_` in the term so a
		// term containing them matches literally instead of wildcarding.
		if ( '' !== $args['s'] ) {
			$like    = '%' . self::esc_like( $args['s'] ) . '%';
			$matches = array();

			foreach ( self::SEARCH_COLUMNS as $column ) {
				$matches[]  = self::ALIAS . '.' . $column . ' LIKE %s';
				$bindings[] = $like;
			}

			$where[] = '( ' . implode( ' OR ', $matches ) . ' )';
		}

		// Requirements 12.5, 12.6: `from` and `to` bound the `created_at` date
		// inclusively, so the whole of the boundary day is inside the range.
		if ( '' !== $args['from'] ) {
			$where[]    = self::ALIAS . '.created_at >= %s';
			$bindings[] = $args['from'] . ' 00:00:00';
		}

		if ( '' !== $args['to'] ) {
			$where[]    = self::ALIAS . '.created_at <= %s';
			$bindings[] = $args['to'] . ' 23:59:59';
		}

		$date_condition = self::candidate_dates( $args, $tables );

		if ( '' !== $date_condition ) {
			// Requirement 12.7: at least one candidate range meeting the window.
			$where[]    = $date_condition;
			$bindings[] = $args['date_from'];
			$bindings[] = $args['date_to'];
		} elseif ( '' !== $args['date_from'] ) {
			$warnings[] = 'date_to missing';
		} elseif ( '' !== $args['date_to'] ) {
			$warnings[] = 'date_from missing';
		}

		// Requirements 12.15, 17.5: test records are included unless excluded
		// explicitly. `0` is a literal here rather than a binding because it is
		// this class's own value, not anything the request supplied.
		if ( $args['hide_test'] ) {
			$where[] = self::ALIAS . '.is_test = 0';
		}

		return array(
			'where'    => $where ? implode( ' AND ', $where ) : '1 = 1',
			'bindings' => $bindings,
			'order'    => self::order( $args ),
			'limit'    => self::limit( $args ),
			'warnings' => $warnings,
		);
	}

	/**
	 * The `ORDER BY` fragment.
	 *
	 * Defaults to `created_at` descending (Requirement 12.14). `id` is appended
	 * as a tie-break so two enquiries sharing a `created_at` second — routine,
	 * since the column holds whole seconds — always come back in the same
	 * order, which is what stops a row appearing on two pages or on none.
	 *
	 * @param array $args Normalised arguments.
	 * @return string
	 */
	public static function order( array $args ) {
		$args      = self::normalise( $args );
		$direction = 'asc' === $args['order'] ? 'ASC' : 'DESC';
		$column    = self::ALIAS . '.' . $args['orderby'] . ' ' . $direction;

		if ( 'id' === $args['orderby'] ) {
			return 'ORDER BY ' . $column;
		}

		return 'ORDER BY ' . $column . ', ' . self::ALIAS . '.id ' . $direction;
	}

	/**
	 * The `LIMIT` fragment.
	 *
	 * Both numbers are whole numbers produced by normalise(), formatted by
	 * `sprintf()` here rather than left as placeholders, so `bindings` stays
	 * the set of values belonging to `where` alone.
	 *
	 * @param array $args Normalised arguments.
	 * @return string
	 */
	public static function limit( array $args ) {
		$args = self::normalise( $args );

		return sprintf(
			'LIMIT %d OFFSET %d',
			$args['per_page'],
			( $args['page'] - 1 ) * $args['per_page']
		);
	}

	/**
	 * Escape LIKE wildcards in a search term.
	 *
	 * Mirrors `wpdb::esc_like()` without needing `$wpdb`: `%`, `_` and `\` are
	 * backslash-escaped so a term containing them matches literally
	 * (Requirement 12.4, Property 28).
	 *
	 * @param string $text Search term.
	 * @return string
	 */
	public static function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * The candidate-date condition, or an empty string when it does not apply.
	 *
	 * An EXISTS subquery rather than a join: an enquiry holds up to three
	 * candidate ranges, more than one of which can meet the window, and a join
	 * would return that enquiry once per matching range — inflating the total,
	 * the page contents and the per-status counts alike.
	 *
	 * The test is overlap, not containment: an enquiry whose candidate fortnight
	 * begins before the window and ends inside it is an enquiry about days in the
	 * window, and someone filtering to next month wants it. So a range matches
	 * when it starts no later than the window ends and ends no earlier than the
	 * window starts, which is the negation of "entirely before or entirely
	 * after".
	 *
	 * Requirement 12.8 makes a lone bound apply no filter at all, so both
	 * bounds have to be present for a condition to be produced.
	 *
	 * @param array $args   Normalised arguments.
	 * @param array $tables Optional real table names.
	 * @return string
	 */
	protected static function candidate_dates( array $args, array $tables ) {
		if ( '' === $args['date_from'] || '' === $args['date_to'] ) {
			return '';
		}

		$table = self::DATES_TABLE;

		if ( isset( $tables['dates'] ) && is_string( $tables['dates'] ) && '' !== $tables['dates'] ) {
			$table = $tables['dates'];
		}

		$dates = self::DATES_ALIAS;

		// The end bound is compared first so the two placeholders take
		// `date_from` then `date_to`, the order `build()` binds them in.
		return 'EXISTS ( SELECT 1 FROM ' . $table . ' ' . $dates
			. ' WHERE ' . $dates . '.enquiry_id = ' . self::ALIAS . '.id'
			. ' AND ' . $dates . '.end_date >= %s'
			. ' AND ' . $dates . '.start_date <= %s )';
	}

	/**
	 * Read one argument, falling back to its default.
	 *
	 * A null value is treated as absent, because that is what a REST parameter
	 * that was never supplied looks like.
	 *
	 * @param array  $args Raw arguments.
	 * @param string $key  Argument key.
	 * @return mixed
	 */
	protected static function arg( array $args, $key ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return self::DEFAULTS[ $key ];
		}

		return $args[ $key ];
	}

	/**
	 * Normalise a status filter.
	 *
	 * An unrecognised value falls back to `all` rather than filtering on a
	 * status no enquiry can hold, which would return an empty list and read as
	 * "no enquiries" instead of "that is not a status".
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function to_status( $value ) {
		if ( ! is_scalar( $value ) ) {
			return self::STATUS_ALL;
		}

		$status = strtolower( trim( (string) $value ) );

		if ( in_array( $status, self::statuses(), true ) ) {
			return $status;
		}

		return self::STATUS_ALL;
	}

	/**
	 * Normalise a search term.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function to_search( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Normalise a date argument to `Y-m-d`, or to an empty string.
	 *
	 * An unparseable or impossible date normalises to empty, which means the
	 * filter is simply not applied — and, for `date_from`/`date_to`, means the
	 * lone-bound warning of Requirement 12.8 is what the caller sees.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function to_date( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Leading `Y-m-d` covers both a plain date and a full DATETIME.
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts ) ) {
			$year  = (int) $parts[1];
			$month = (int) $parts[2];
			$day   = (int) $parts[3];

			if ( ! checkdate( $month, $day, $year ) ) {
				return '';
			}

			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Normalise a boolean argument.
	 *
	 * Query strings carry booleans as text, so `1`, `true`, `yes` and `on` all
	 * mean true and everything else means false.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	protected static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	/**
	 * Normalise the page number to a whole number of at least 1.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	protected static function to_page( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 1;
		}

		return max( 1, (int) $value );
	}

	/**
	 * Normalise the page size: default 25, cap 200 (Requirement 12.10).
	 *
	 * A missing, non-numeric, zero or negative value takes the default. Zero
	 * and negative are not smaller page sizes — they are not page sizes at all,
	 * and honouring them literally would return an empty page while matching
	 * rows remained unread.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	protected static function to_per_page( $value ) {
		if ( ! is_numeric( $value ) ) {
			return self::PER_PAGE_DEFAULT;
		}

		$per_page = (int) $value;

		if ( $per_page < 1 ) {
			return self::PER_PAGE_DEFAULT;
		}

		return min( self::PER_PAGE_MAX, $per_page );
	}

	/**
	 * Normalise the sort column against the whitelist.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function to_orderby( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 'created_at';
		}

		$column = strtolower( trim( (string) $value ) );

		return in_array( $column, self::ORDERBY_COLUMNS, true ) ? $column : 'created_at';
	}

	/**
	 * Normalise the sort direction.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function to_order( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 'desc';
		}

		return 'asc' === strtolower( trim( (string) $value ) ) ? 'asc' : 'desc';
	}
}
