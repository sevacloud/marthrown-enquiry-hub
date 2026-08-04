<?php
/**
 * Unified admin dashboard.
 *
 * Adds a top-level "Enquiry Hub" admin page that queries FluentCRM subscribers
 * tagged with any `source-` tag and lists name, source, latest activity note,
 * and status. The table is filterable by source and date, and sortable by
 * source and date.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminDashboard
 */
class AdminDashboard {

	const MENU_SLUG  = 'marthrown-enquiry-hub';
	const CAPABILITY = 'manage_options';

	/**
	 * Register menu and assets.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level admin menu page.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-email-alt',
			26
		);
	}

	/**
	 * Enqueue CSS on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'meh-admin', MEH_PLUGIN_URL . 'assets/admin.css', array(), MEH_VERSION );
		wp_enqueue_script( 'meh-admin', MEH_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), MEH_VERSION, true );
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Read filter/sort params.
		$source_filter = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$date_from     = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$date_to       = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		$order         = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'asc' : 'desc';
		$hide_test     = ! empty( $_GET['hide_test'] );

		$source_tags = self::get_source_tags();
		$rows        = self::get_rows( $source_tags, $source_filter, $date_from, $date_to, $hide_test );
		$rows        = self::sort_rows( $rows, $orderby, $order );
		?>
		<div class="wrap meh-wrap">
			<h1><?php esc_html_e( 'Enquiry Hub', 'marthrown-enquiry-hub' ); ?></h1>

			<?php self::render_filters( $source_tags, $source_filter, $date_from, $date_to, $hide_test ); ?>

			<table class="widefat striped meh-enquiries-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'marthrown-enquiry-hub' ); ?></th>
						<th><?php echo self::sortable_header( __( 'Source', 'marthrown-enquiry-hub' ), 'source', $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th><?php esc_html_e( 'Latest Note', 'marthrown-enquiry-hub' ); ?></th>
						<th><?php esc_html_e( 'Status', 'marthrown-enquiry-hub' ); ?></th>
						<th><?php echo self::sortable_header( __( 'Date', 'marthrown-enquiry-hub' ), 'date', $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5" class="meh-empty"><?php esc_html_e( 'No enquiries found.', 'marthrown-enquiry-hub' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr class="<?php echo ! empty( $row['is_test'] ) ? 'meh-test-row' : ''; ?>">
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( $row['source_label'] ); ?></td>
								<td><?php echo esc_html( $row['note'] ); ?></td>
								<td><span class="meh-status meh-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( ucfirst( $row['status'] ) ); ?></span></td>
								<td><?php echo esc_html( $row['date'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the filter form.
	 *
	 * @param array  $source_tags   Source tag models.
	 * @param string $source_filter Current source filter slug.
	 * @param string $date_from     From date.
	 * @param string $date_to       To date.
	 * @param bool   $hide_test     Whether the "hide test records" toggle is on.
	 */
	protected static function render_filters( $source_tags, $source_filter, $date_from, $date_to, $hide_test = false ) {
		?>
		<form method="get" class="meh-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />

			<label for="meh-source">
				<?php esc_html_e( 'Source', 'marthrown-enquiry-hub' ); ?>
				<select name="source" id="meh-source">
					<option value=""><?php esc_html_e( 'All sources', 'marthrown-enquiry-hub' ); ?></option>
					<?php foreach ( $source_tags as $tag ) : ?>
						<?php $slug = self::source_slug_from_tag( $tag->slug ); ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $source_filter, $slug ); ?>>
							<?php echo esc_html( ucfirst( $slug ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label for="meh-from">
				<?php esc_html_e( 'From', 'marthrown-enquiry-hub' ); ?>
				<input type="date" name="from" id="meh-from" value="<?php echo esc_attr( $date_from ); ?>" />
			</label>

			<label for="meh-to">
				<?php esc_html_e( 'To', 'marthrown-enquiry-hub' ); ?>
				<input type="date" name="to" id="meh-to" value="<?php echo esc_attr( $date_to ); ?>" />
			</label>

			<label for="meh-hide-test" class="meh-checkbox">
				<input type="checkbox" name="hide_test" id="meh-hide-test" value="1" <?php checked( $hide_test ); ?> />
				<?php esc_html_e( 'Hide test records', 'marthrown-enquiry-hub' ); ?>
			</label>

			<?php submit_button( __( 'Filter', 'marthrown-enquiry-hub' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Build a sortable column header link.
	 *
	 * @param string $label   Column label.
	 * @param string $key     Sort key (source|date).
	 * @param string $orderby Current orderby.
	 * @param string $order   Current order.
	 * @return string HTML anchor.
	 */
	protected static function sortable_header( $label, $key, $orderby, $order ) {
		$next_order = ( $orderby === $key && 'asc' === $order ) ? 'desc' : 'asc';
		$args       = array_merge(
			$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			array(
				'orderby' => $key,
				'order'   => $next_order,
			)
		);
		$url   = esc_url( add_query_arg( array_map( 'sanitize_text_field', $args ) ) );
		$arrow = '';
		if ( $orderby === $key ) {
			$arrow = 'asc' === $order ? ' ▲' : ' ▼';
		}
		return '<a href="' . $url . '">' . esc_html( $label ) . esc_html( $arrow ) . '</a>';
	}

	/**
	 * Fetch all `source-*` tags.
	 *
	 * @return array Tag models.
	 */
	protected static function get_source_tags() {
		if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
			return array();
		}
		return \FluentCrm\App\Models\Tag::where( 'slug', 'like', 'source-%' )->get()->all();
	}

	/**
	 * Query subscribers carrying any source tag and build display rows.
	 *
	 * @param array  $source_tags   Source tag models.
	 * @param string $source_filter Optional source slug to restrict to.
	 * @param string $date_from     Optional from date (Y-m-d).
	 * @param string $date_to       Optional to date (Y-m-d).
	 * @param bool   $hide_test     When true, exclude records tagged test-record.
	 * @return array
	 */
	protected static function get_rows( $source_tags, $source_filter, $date_from, $date_to, $hide_test = false ) {
		if ( empty( $source_tags ) || ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return array();
		}

		// Restrict to matching tag ids.
		$tag_ids = self::resolve_tag_ids( $source_tags, $source_filter );
		if ( empty( $tag_ids ) ) {
			return array();
		}

		$query = \FluentCrm\App\Models\Subscriber::query()
			->whereHas(
				'tags',
				function ( $q ) use ( $tag_ids ) {
					$q->whereIn( 'fc_tags.id', $tag_ids );
				}
			);

		if ( $date_from ) {
			$query->where( 'created_at', '>=', $date_from . ' 00:00:00' );
		}
		if ( $date_to ) {
			$query->where( 'created_at', '<=', $date_to . ' 23:59:59' );
		}

		$subscribers = $query->orderBy( 'created_at', 'desc' )->limit( 200 )->get();

		$rows = array();
		foreach ( $subscribers as $subscriber ) {
			$is_test = self::subscriber_has_tag( $subscriber, FluentCrmWriter::STAGING_TAG );
			if ( $hide_test && $is_test ) {
				continue;
			}

			$note = FluentCrmWriter::get_latest_note( $subscriber->id );

			$rows[] = array(
				'name'         => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
				'source_label' => self::sources_for_subscriber( $subscriber ),
				'source_sort'  => self::primary_source_for_subscriber( $subscriber ),
				'note'         => $note && ! empty( $note['message'] ) ? wp_trim_words( $note['message'], 20 ) : '—',
				'status'       => $subscriber->status ? $subscriber->status : 'unknown',
				'date'         => $subscriber->created_at,
				'is_test'      => $is_test,
			);
		}

		return $rows;
	}

	/**
	 * Resolve the tag ids to query, honouring an optional source filter.
	 *
	 * @param array  $source_tags   Source tag models.
	 * @param string $source_filter Optional source slug to restrict to.
	 * @return int[]
	 */
	protected static function resolve_tag_ids( $source_tags, $source_filter ) {
		$tag_ids = array();
		foreach ( $source_tags as $tag ) {
			$slug = self::source_slug_from_tag( $tag->slug );
			if ( $source_filter && $slug !== $source_filter ) {
				continue;
			}
			$tag_ids[] = $tag->id;
		}
		return $tag_ids;
	}

	/**
	 * Sort rows by source or date.
	 *
	 * @param array  $rows    Rows.
	 * @param string $orderby 'source' | 'date'.
	 * @param string $order   'asc' | 'desc'.
	 * @return array
	 */
	protected static function sort_rows( $rows, $orderby, $order ) {
		$key = ( 'source' === $orderby ) ? 'source_sort' : 'date';

		usort(
			$rows,
			function ( $a, $b ) use ( $key ) {
				return strcmp( (string) $a[ $key ], (string) $b[ $key ] );
			}
		);

		if ( 'desc' === $order ) {
			$rows = array_reverse( $rows );
		}
		return $rows;
	}

	/**
	 * Human-readable list of sources for a subscriber (comma separated).
	 *
	 * @param object $subscriber Subscriber model.
	 * @return string
	 */
	protected static function sources_for_subscriber( $subscriber ) {
		$sources = array();
		foreach ( self::subscriber_source_slugs( $subscriber ) as $slug ) {
			$sources[] = ucfirst( $slug );
		}
		return $sources ? implode( ', ', $sources ) : '—';
	}

	/**
	 * Primary (first) source slug for sorting.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return string
	 */
	protected static function primary_source_for_subscriber( $subscriber ) {
		$slugs = self::subscriber_source_slugs( $subscriber );
		return $slugs ? $slugs[0] : '';
	}

	/**
	 * Whether a subscriber carries a given tag slug.
	 *
	 * @param object $subscriber Subscriber model.
	 * @param string $slug       Tag slug to look for.
	 * @return bool
	 */
	protected static function subscriber_has_tag( $subscriber, $slug ) {
		if ( ! isset( $subscriber->tags ) ) {
			return false;
		}
		foreach ( $subscriber->tags as $tag ) {
			if ( $slug === $tag->slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract source slugs from a subscriber's tags.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return array
	 */
	protected static function subscriber_source_slugs( $subscriber ) {
		$slugs = array();
		if ( ! isset( $subscriber->tags ) ) {
			return $slugs;
		}
		foreach ( $subscriber->tags as $tag ) {
			if ( 0 === strpos( $tag->slug, 'source-' ) ) {
				$slugs[] = self::source_slug_from_tag( $tag->slug );
			}
		}
		return $slugs;
	}

	/**
	 * Strip the `source-` prefix from a tag slug.
	 *
	 * @param string $tag_slug Tag slug.
	 * @return string
	 */
	protected static function source_slug_from_tag( $tag_slug ) {
		return preg_replace( '/^source-/', '', $tag_slug );
	}
}
