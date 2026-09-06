<?php
/**
 * Property 3: No uniqueness constraints on contact identity.
 *
 * Feature: enquiry-data-layer, Property 3: For any email address, any FluentCRM
 * subscriber identifier, and any count from 1 to 100, storing that many
 * enquiries all holding the same email and the same subscriber identifier
 * persists and returns all of them; and for any enquiry, storing it with an
 * empty `fluentcrm_subscriber_id` and an empty `booking_id` succeeds.
 *
 * **Validates: Requirements 1.4, 1.5**
 *
 * Four things about how the property is instantiated are worth stating plainly:
 *
 * - The claim is about what the *database* permits, so it runs against real
 *   tables. A uniqueness constraint is invisible to any amount of PHP-level
 *   inspection of `EnquiryStore`: it only shows up when the second row carrying
 *   a repeated value is written and MySQL refuses it.
 * - The "1 to 100" range is covered in two pieces rather than one. The 100-row
 *   ceiling of Requirements 1.4 and 1.5 is a single fact, asserted once; the
 *   quantifier over smaller counts, emails and subscriber identifiers runs the
 *   full 100 Eris iterations. Putting a 100-row write inside a 100-iteration
 *   loop would multiply out to ten thousand inserts to establish nothing the
 *   two pieces do not establish separately.
 * - The email set and the subscriber-identifier set are read back through
 *   composed `SELECT`s rather than through a store method, because the
 *   by-email read (`siblings_by_email()`) is not written yet. What is being
 *   asserted is that the rows are all there and all findable by the repeated
 *   value, which is a claim about the table, not about a particular reader.
 * - "Empty" is quantified over all three shapes a caller can submit — the key
 *   absent, the empty string, and null — and the read-back is asserted to be
 *   `null` in every case, checked at the column as well as through `find()`.
 *   `NULL` is the store's representation of "not supplied", and the distinction
 *   matters: a `0` in `booking_id` would be indistinguishable from a booking
 *   whose identifier happens to be 0, and multiple `NULL`s coexist even under a
 *   unique index, which is exactly why the same-value case above uses a real
 *   identifier rather than an empty one.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryContactIdentityPropertyTest
 */
class EnquiryContactIdentityPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehi_';

	/**
	 * The row count Requirements 1.4 and 1.5 name explicitly.
	 */
	const HUNDRED = 100;

	/**
	 * Most enquiries one Eris iteration writes for a repeated identity.
	 *
	 * The 100-row ceiling is asserted once, outside the loop; inside it, a
	 * smaller count is what makes the *quantifier* over emails and subscriber
	 * identifiers affordable.
	 */
	const SHARED_MAX = 8;

	/**
	 * The three shapes a caller can submit an empty identity value in.
	 *
	 * @var string[]
	 */
	const EMPTY_SHAPES = array( 'absent', 'empty_string', 'null' );

	/**
	 * Columns Requirements 1.4 and 1.5 forbid a uniqueness constraint on.
	 *
	 * @var string[]
	 */
	const NON_UNIQUE_COLUMNS = array( 'email', 'fluentcrm_subscriber_id' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		/*
		 * The WordPress test case rewrites CREATE TABLE into its TEMPORARY form,
		 * and `Schema::install()` verifies each table through `SHOW TABLES`,
		 * which cannot see a temporary table. This test therefore works against
		 * real tables at its own prefix and cleans them up itself.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 3: No uniqueness constraints on
	 * contact identity.
	 *
	 * **Validates: Requirements 1.4, 1.5**
	 *
	 * @eris-shrink 10
	 */
	public function test_no_uniqueness_constraints_on_contact_identity() {
		// Facts, not quantifiers: asserted once each.
		$this->assert_no_unique_index_on_identity_columns();
		$this->assert_hundred_enquiries_share_one_identity();

		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$this->clear();

					$shared = $this->store_sharing_identity(
						(string) $case['email'],
						(int) $case['subscriber_id'],
						(int) $case['count']
					);

					$empty = $this->store_with_empty_identity( (string) $case['email'], $case['empty_shape'] );

					// Every row carrying the repeated email is retrievable by it,
					// the empty-identity enquiry included (Requirement 1.4).
					$this->assert_ids(
						array_merge( $shared, array( $empty ) ),
						$this->ids_where( 'email = %s', (string) $case['email'] ),
						'Every enquiry holding the email should be retrievable by it.'
					);

					// And every row carrying the repeated subscriber identifier is
					// retrievable by it, the empty one excluded because an empty
					// identity is not that identity (Requirement 1.5).
					$this->assert_ids(
						$shared,
						$this->ids_where( 'fluentcrm_subscriber_id = %d', (int) $case['subscriber_id'] ),
						'Every enquiry holding the subscriber identifier should be retrievable by it.'
					);
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: an email, a subscriber identifier, how many enquiries share both,
	 * and the shape the further enquiry submits its empty identity in.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'email'         => Generators::email(),
				'subscriber_id' => self::subscriber_id(),
				'count'         => \Eris\Generators::choose( 1, self::SHARED_MAX ),
				'empty_shape'   => \Eris\Generators::elements( self::EMPTY_SHAPES ),
			)
		);
	}

	/**
	 * A FluentCRM subscriber identifier.
	 *
	 * The two constants are boundaries worth hitting deliberately: 1 is the
	 * lowest identifier FluentCRM assigns, and a value beyond the 32-bit signed
	 * range fails on a column narrower than the `BIGINT UNSIGNED` the schema
	 * declares — which would read back as a different identifier rather than as
	 * a write failure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function subscriber_id() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 1 ),
			\Eris\Generators::constant( 4294967295 ),
			\Eris\Generators::choose( 1, 999999 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Neither identity column takes part in a unique index (Requirements 1.4, 1.5).
	 *
	 * The behavioural halves below would catch a unique index on either column on
	 * their own. This reads the constraint straight off the installed table, so a
	 * failure says *what* is wrong rather than only that a write was refused.
	 *
	 * @return void
	 */
	private function assert_no_unique_index_on_identity_columns() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );
		$rows  = (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB

		$this->assertNotEmpty( $rows, 'The enquiry table should report its indexes.' );

		foreach ( $rows as $row ) {
			if ( '0' !== (string) $row['Non_unique'] ) {
				continue;
			}

			$this->assertNotContains(
				(string) $row['Column_name'],
				self::NON_UNIQUE_COLUMNS,
				sprintf(
					'Index %s should not impose uniqueness on %s.',
					(string) $row['Key_name'],
					(string) $row['Column_name']
				)
			);
		}
	}

	/**
	 * A hundred enquiries hold one email and one subscriber identifier, and all
	 * hundred persist and read back (Requirements 1.4, 1.5).
	 *
	 * @return void
	 */
	private function assert_hundred_enquiries_share_one_identity() {
		$this->clear();

		$email      = 'hundred.enquiries@example.com';
		$subscriber = 4242;

		$ids = $this->store_sharing_identity( $email, $subscriber, self::HUNDRED );

		$this->assertCount( self::HUNDRED, $ids, 'A hundred enquiries should share one email and one subscriber id.' );

		$this->assert_ids(
			$ids,
			$this->ids_where( 'email = %s', $email ),
			'All hundred enquiries should be retrievable by the shared email.'
		);

		$this->assert_ids(
			$ids,
			$this->ids_where( 'fluentcrm_subscriber_id = %d', $subscriber ),
			'All hundred enquiries should be retrievable by the shared subscriber id.'
		);

		$this->clear();
	}

	/**
	 * Two identifier lists hold the same identifiers.
	 *
	 * @param int[]  $expected Identifiers that should be present.
	 * @param int[]  $actual   Identifiers that were found.
	 * @param string $message  Failure message.
	 * @return void
	 */
	private function assert_ids( array $expected, array $actual, $message ) {
		sort( $expected, SORT_NUMERIC );
		sort( $actual, SORT_NUMERIC );

		$this->assertSame( $expected, $actual, $message );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Store `$count` enquiries all holding the same email and subscriber id, and
	 * assert each one persisted and reads back holding both.
	 *
	 * @param string $email      Shared email address.
	 * @param int    $subscriber Shared FluentCRM subscriber identifier.
	 * @param int    $count      How many enquiries to store.
	 * @return int[] The identifiers assigned, in write order.
	 */
	private function store_sharing_identity( $email, $subscriber, $count ) {
		$ids = array();

		for ( $index = 0; $index < (int) $count; $index++ ) {
			$id = $this->store(
				array(
					'email'                   => $email,
					'fluentcrm_subscriber_id' => $subscriber,
				)
			);

			// Requirement 1.1: identifiers are positive and never reused, so a
			// repeated identity must not collapse two enquiries onto one row.
			$this->assertNotContains( $id, $ids, 'Each enquiry should get its own identifier.' );

			$ids[] = $id;

			$stored = EnquiryStore::find( $id );

			$this->assertIsArray( $stored, 'Enquiry ' . $index . ' should read back.' );
			$this->assertSame( (string) $email, $stored['email'], 'The stored email should be the written one.' );
			$this->assertSame(
				(int) $subscriber,
				$stored['fluentcrm_subscriber_id'],
				'The stored subscriber id should be the written one.'
			);
		}

		return $ids;
	}

	/**
	 * Store one enquiry submitting an empty `fluentcrm_subscriber_id` and an
	 * empty `booking_id`, and assert both read back as null (Requirement 1.5).
	 *
	 * @param string $email Email address for the enquiry.
	 * @param string $shape One of self::EMPTY_SHAPES.
	 * @return int The identifier assigned.
	 */
	private function store_with_empty_identity( $email, $shape ) {
		$id = $this->store( array_merge( array( 'email' => $email ), self::empty_identity( $shape ) ) );

		$stored = EnquiryStore::find( $id );

		$this->assertIsArray( $stored, 'An enquiry with an empty identity should read back.' );
		$this->assertNull(
			$stored['fluentcrm_subscriber_id'],
			'An empty subscriber id should read back as null, submitted as ' . $shape . '.'
		);
		$this->assertNull(
			$stored['booking_id'],
			'An empty booking id should read back as null, submitted as ' . $shape . '.'
		);

		// Asserted at the column too: `find()` reports a stored `0` as `0`, so a
		// null here is the column holding no value rather than holding zero.
		$this->assert_ids(
			array( $id ),
			$this->ids_where( 'id = %d AND fluentcrm_subscriber_id IS NULL AND booking_id IS NULL', $id ),
			'Both identity columns should hold NULL rather than 0.'
		);

		return $id;
	}

	/**
	 * The submitted columns expressing an empty identity, in one of the three
	 * shapes a caller can present.
	 *
	 * @param string $shape One of self::EMPTY_SHAPES.
	 * @return array<string,mixed>
	 */
	private static function empty_identity( $shape ) {
		if ( 'absent' === $shape ) {
			// Neither key submitted at all.
			return array();
		}

		$value = 'empty_string' === $shape ? '' : null;

		return array(
			'fluentcrm_subscriber_id' => $value,
			'booking_id'              => $value,
		);
	}

	/**
	 * Store one enquiry through the store, and assert the write succeeded.
	 *
	 * One candidate date, so every write exercises the parent-plus-child path
	 * `create()` actually takes rather than a parent row on its own.
	 *
	 * @param array $overrides Column values overriding the fixture defaults.
	 * @return int The identifier assigned.
	 */
	private function store( array $overrides ) {
		$row = array_merge(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'phone'      => '07700 900000',
				'status'     => 'new',
				'source'     => 'webhook:fixture',
			),
			$overrides
		);

		$id = EnquiryStore::create( $row, array( Generators::date_at( 10 ) ) );

		$this->assertNotWPError( $id, 'Storing an enquiry should succeed.' );
		$this->assertGreaterThan( 0, $id, 'A stored enquiry should get a positive identifier.' );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The identifiers of every enquiry satisfying a condition.
	 *
	 * @param string $condition `WHERE` clause carrying `$wpdb->prepare()` placeholders.
	 * @param mixed  ...$bindings Values for those placeholders, in order.
	 * @return int[] Ascending identifiers.
	 */
	private function ids_where( $condition, ...$bindings ) {
		global $wpdb;

		$table = Schema::table( 'enquiries' );
		$sql   = "SELECT id FROM {$table} WHERE " . $condition . ' ORDER BY id ASC';

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB
		$ids = (array) $wpdb->get_col( $wpdb->prepare( $sql, $bindings ) );

		$this->assertSame( '', (string) $wpdb->last_error, 'The lookup should run without error.' );

		return array_map( 'intval', $ids );
	}

	/**
	 * Empty the tables this property writes to, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'enquiries', 'dates', 'terms' ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
