<?php
/**
 * In-memory FluentCRM fake.
 *
 * Records every contact upsert, list attachment and tag attachment ContactLinker
 * performs, so the CRM properties (16, 17, 18, 42) run with no FluentCRM
 * installed. Nothing here talks to a database: the whole contact universe is an
 * array keyed by lower-cased email address, which is exactly the identity
 * FluentCRM itself matches on.
 *
 * Usage:
 *
 *     $crm = FakeCrm::install();                 // registers the FluentCrmApi() shim
 *     $crm->next_subscriber_id( 91 );
 *     ContactLinker::link( $enquiry_id );
 *     $this->assertCount( 1, $crm->create_or_update_calls() );
 *     $this->assertSame( array( 'Event Enquiries' ), $crm->lists_for( 'ada@example.com' ) );
 *     FakeCrm::uninstall();                      // in tearDown
 *
 * Failure modes are configured rather than simulated by the caller:
 *
 *     $crm->will_throw();                  // the API raises — ContactLinker must catch
 *     $crm->will_return_no_subscriber_id() // a response carrying no id: a failure
 *     $crm->will_be_unavailable();         // FluentCrmApi() resolves to nothing
 *
 * Loading: the file is picked up by the Composer classmap over tests/, so the
 * global FluentCrmApi() shim at the bottom is declared the moment FakeCrm is
 * first referenced. Install the fake in setUp(), before exercising any code that
 * gates on function_exists( 'FluentCrmApi' ).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub\Tests\Fakes {

	/**
	 * Class FakeCrm
	 *
	 * The recording surface. One instance is "current" at a time; the global
	 * FluentCrmApi() shim at the bottom of this file resolves to it.
	 */
	class FakeCrm {

		/** Every call is answered normally. */
		const MODE_OK = 'ok';

		/** Every call raises, standing in for a FluentCRM internal error. */
		const MODE_THROW = 'throw';

		/** Calls answer with a subscriber carrying no identifier. */
		const MODE_NO_ID = 'no_id';

		/** FluentCrmApi() resolves to nothing, standing in for an inactive plugin. */
		const MODE_UNAVAILABLE = 'unavailable';

		/**
		 * The installed instance, or null when the fake is not installed.
		 *
		 * @var FakeCrm|null
		 */
		protected static $current = null;

		/**
		 * Ordered log of every call, oldest first.
		 *
		 * Each entry: array{ method: string, args: array }.
		 *
		 * @var array
		 */
		protected $calls = array();

		/**
		 * Contact universe, keyed by lower-cased email.
		 *
		 * Each entry: array{ subscriber_id: int, data: array, lists: array, tags: array }.
		 *
		 * @var array
		 */
		protected $contacts = array();

		/**
		 * Seeded read universe, keyed by subscriber id.
		 *
		 * Deliberately separate from self::$contacts, which records what was
		 * *written*. A migration only ever reads, so a test seeds subscribers
		 * here and then asserts that self::$contacts and the call log hold no
		 * write at all.
		 *
		 * Each entry: array{ id, first_name, last_name, email, phone,
		 * created_at, lists, tags, custom_fields, notes }.
		 *
		 * @var array
		 */
		protected $subscribers = array();

		/**
		 * Identifier handed to the next seeded subscriber with no id of its own.
		 *
		 * @var int
		 */
		protected $next_subscriber_id = 1;

		/**
		 * Subscriber ids pinned to a given email before the call is made.
		 *
		 * @var array email => int
		 */
		protected $assigned = array();

		/**
		 * Identifier handed to the next contact with no pinned id.
		 *
		 * @var int
		 */
		protected $next_id = 1001;

		/**
		 * Current behaviour mode.
		 *
		 * @var string
		 */
		protected $mode = self::MODE_OK;

		/**
		 * Message carried by the exception raised in MODE_THROW.
		 *
		 * @var string
		 */
		protected $exception_message = 'FluentCRM API failure (FakeCrm)';

		/**
		 * Install a fresh fake and make it current.
		 *
		 * @return FakeCrm
		 */
		public static function install() {
			self::$current = new self();
			return self::$current;
		}

		/**
		 * Remove the current fake. Call from tearDown().
		 */
		public static function uninstall() {
			self::$current = null;
		}

		/**
		 * The current fake, or null when none is installed.
		 *
		 * @return FakeCrm|null
		 */
		public static function current() {
			return self::$current;
		}

		/**
		 * Forget every recorded call and every contact, keeping configuration.
		 *
		 * Seeded subscribers go too: they are contacts, and a run that re-seeds
		 * expects to start from an empty CRM.
		 *
		 * @return FakeCrm
		 */
		public function reset() {
			$this->calls       = array();
			$this->contacts    = array();
			$this->subscribers = array();
			return $this;
		}

		/* ---------------------------------------------------------------------
		 * The read universe: contacts that already exist in the CRM
		 * ------------------------------------------------------------------ */

		/**
		 * Seed one contact for the migration reads to find.
		 *
		 * Recognised keys, all optional bar the email:
		 *
		 *   id             int    Subscriber id; assigned when absent.
		 *   first_name     string
		 *   last_name      string
		 *   email          string
		 *   phone          string
		 *   created_at     string MySQL DATETIME.
		 *   lists          array  List identifiers the contact belongs to.
		 *   tags           array  Tag identifiers the contact holds.
		 *   custom_fields  array  Custom field map, e.g. meh_enquiry_status.
		 *   notes          array  Each entry a string, or an array holding
		 *                         `body`/`description`, `title` and `created_at`.
		 *
		 * @param array $data Contact data.
		 * @return FakeCrmContact The seeded contact, as a read would return it.
		 */
		public function add_subscriber( array $data ) {
			$id = isset( $data['id'] ) ? (int) $data['id'] : 0;

			if ( $id <= 0 ) {
				$id = $this->next_subscriber_id;
			}

			if ( $id >= $this->next_subscriber_id ) {
				$this->next_subscriber_id = $id + 1;
			}

			$notes = array();

			foreach ( isset( $data['notes'] ) ? (array) $data['notes'] : array() as $note ) {
				$notes[] = is_array( $note ) ? $note : array( 'description' => (string) $note );
			}

			$this->subscribers[ $id ] = array(
				'id'            => $id,
				'first_name'    => isset( $data['first_name'] ) ? (string) $data['first_name'] : '',
				'last_name'     => isset( $data['last_name'] ) ? (string) $data['last_name'] : '',
				'email'         => isset( $data['email'] ) ? (string) $data['email'] : '',
				'phone'         => isset( $data['phone'] ) ? (string) $data['phone'] : '',
				'created_at'    => isset( $data['created_at'] ) ? (string) $data['created_at'] : '',
				'lists'         => isset( $data['lists'] ) ? array_values( (array) $data['lists'] ) : array(),
				'tags'          => isset( $data['tags'] ) ? array_values( (array) $data['tags'] ) : array(),
				'custom_fields' => isset( $data['custom_fields'] ) ? (array) $data['custom_fields'] : array(),
				'notes'         => $notes,
			);

			return new FakeCrmContact( $this->subscribers[ $id ] );
		}

		/**
		 * Every seeded subscriber, keyed by id, exactly as stored.
		 *
		 * The snapshot a test compares before and after a migration to assert
		 * that no contact record, list membership or tag changed.
		 *
		 * @return array
		 */
		public function subscribers() {
			return $this->subscribers;
		}

		/**
		 * One seeded subscriber as a read would return it, or null.
		 *
		 * @param int $id Subscriber id.
		 * @return FakeCrmContact|null
		 */
		public function subscriber( $id ) {
			$id = (int) $id;

			return isset( $this->subscribers[ $id ] ) ? new FakeCrmContact( $this->subscribers[ $id ] ) : null;
		}

		/**
		 * Seeded subscribers whose membership intersects the given values.
		 *
		 * @param string $bucket 'lists' or 'tags'.
		 * @param array  $values Identifiers to match.
		 * @return FakeCrmContact[] In seeding order.
		 */
		public function subscribers_by( $bucket, array $values ) {
			$bucket = 'tags' === $bucket ? 'tags' : 'lists';
			$wanted = array();

			foreach ( $values as $value ) {
				$wanted[] = (string) $value;
			}

			$found = array();

			foreach ( $this->subscribers as $subscriber ) {
				foreach ( $subscriber[ $bucket ] as $held ) {
					if ( in_array( (string) $held, $wanted, true ) ) {
						$found[] = new FakeCrmContact( $subscriber );
						break;
					}
				}
			}

			return $found;
		}

		/* ---------------------------------------------------------------------
		 * Configuration
		 * ------------------------------------------------------------------ */

		/**
		 * Answer every call normally.
		 *
		 * @return FakeCrm
		 */
		public function will_succeed() {
			$this->mode = self::MODE_OK;
			return $this;
		}

		/**
		 * Raise on every call.
		 *
		 * @param string $message Exception message.
		 * @return FakeCrm
		 */
		public function will_throw( $message = '' ) {
			$this->mode = self::MODE_THROW;
			if ( '' !== $message ) {
				$this->exception_message = (string) $message;
			}
			return $this;
		}

		/**
		 * Answer with a subscriber carrying no identifier.
		 *
		 * @return FakeCrm
		 */
		public function will_return_no_subscriber_id() {
			$this->mode = self::MODE_NO_ID;
			return $this;
		}

		/**
		 * Resolve FluentCrmApi() to nothing, as an inactive plugin would.
		 *
		 * @return FakeCrm
		 */
		public function will_be_unavailable() {
			$this->mode = self::MODE_UNAVAILABLE;
			return $this;
		}

		/**
		 * Identifier handed to the next contact created with no pinned id.
		 *
		 * @param int $id Subscriber id.
		 * @return FakeCrm
		 */
		public function next_subscriber_id( $id ) {
			$this->next_id = (int) $id;
			return $this;
		}

		/**
		 * Pin the subscriber id an email resolves to.
		 *
		 * This is how the changed-email case is set up: the corrected address is
		 * pinned to a different id, so the upsert returns an id differing from
		 * the one stored on the enquiry.
		 *
		 * @param string $email Email address.
		 * @param int    $id    Subscriber id.
		 * @return FakeCrm
		 */
		public function assign_subscriber_id( $email, $id ) {
			$this->assigned[ self::key( $email ) ] = (int) $id;
			return $this;
		}

		/**
		 * Whether the fake presents an available API.
		 *
		 * @return bool
		 */
		public function is_available() {
			return self::MODE_UNAVAILABLE !== $this->mode;
		}

		/**
		 * Current behaviour mode.
		 *
		 * @return string
		 */
		public function mode() {
			return $this->mode;
		}

		/* ---------------------------------------------------------------------
		 * The API surface FluentCrmApi() exposes
		 * ------------------------------------------------------------------ */

		/**
		 * Resolve an API module by key, as FluentCrmApi() does.
		 *
		 * @param string $key Module key. Only 'contacts' is modelled.
		 * @return FakeCrmContacts|null
		 */
		public function api( $key ) {
			$this->record( 'api', array( 'key' => (string) $key ) );

			if ( ! $this->is_available() ) {
				return null;
			}
			if ( 'contacts' !== $key ) {
				return null;
			}
			return new FakeCrmContacts( $this );
		}

		/**
		 * Upsert a contact matched on email.
		 *
		 * @param array $data Contact data as handed to createOrUpdate().
		 * @return FakeCrmSubscriber|null Null when the data carries no email.
		 * @throws \RuntimeException When the fake is configured to raise.
		 */
		public function upsert( array $data ) {
			if ( self::MODE_THROW === $this->mode ) {
				throw new \RuntimeException( $this->exception_message );
			}

			$email = isset( $data['email'] ) ? self::key( $data['email'] ) : '';
			if ( '' === $email ) {
				return null;
			}

			if ( ! isset( $this->contacts[ $email ] ) ) {
				$this->contacts[ $email ] = array(
					'subscriber_id' => $this->resolve_id( $email ),
					'data'          => array(),
					'lists'         => array(),
					'tags'          => array(),
				);
			}

			$scalars = $data;
			unset( $scalars['lists'], $scalars['tags'] );
			$this->contacts[ $email ]['data'] = array_merge( $this->contacts[ $email ]['data'], $scalars );

			if ( isset( $data['lists'] ) ) {
				$this->attach( $email, 'lists', (array) $data['lists'] );
			}
			if ( isset( $data['tags'] ) ) {
				$this->attach( $email, 'tags', (array) $data['tags'] );
			}

			$id = self::MODE_NO_ID === $this->mode ? 0 : (int) $this->contacts[ $email ]['subscriber_id'];

			return new FakeCrmSubscriber( $this, $email, $id );
		}

		/**
		 * Attach list or tag values to a contact, recording the call.
		 *
		 * @param string $email  Contact key.
		 * @param string $bucket 'lists' or 'tags'.
		 * @param array  $values Values to attach.
		 */
		public function attach( $email, $bucket, array $values ) {
			$email  = self::key( $email );
			$bucket = 'tags' === $bucket ? 'tags' : 'lists';

			$this->record(
				'lists' === $bucket ? 'attachLists' : 'attachTags',
				array(
					'email'  => $email,
					'values' => array_values( $values ),
				)
			);

			if ( ! isset( $this->contacts[ $email ] ) ) {
				$this->contacts[ $email ] = array(
					'subscriber_id' => $this->resolve_id( $email ),
					'data'          => array(),
					'lists'         => array(),
					'tags'          => array(),
				);
			}

			foreach ( $values as $value ) {
				if ( ! in_array( $value, $this->contacts[ $email ][ $bucket ], true ) ) {
					$this->contacts[ $email ][ $bucket ][] = $value;
				}
			}
		}

		/**
		 * Record a call.
		 *
		 * @param string $method Method name.
		 * @param array  $args   Arguments.
		 */
		public function record( $method, array $args = array() ) {
			$this->calls[] = array(
				'method' => (string) $method,
				'args'   => $args,
			);
		}

		/* ---------------------------------------------------------------------
		 * Assertion helpers
		 * ------------------------------------------------------------------ */

		/**
		 * Recorded calls, optionally filtered by method, oldest first.
		 *
		 * @param string|null $method Method name, or null for every call.
		 * @return array
		 */
		public function calls( $method = null ) {
			if ( null === $method ) {
				return $this->calls;
			}
			$out = array();
			foreach ( $this->calls as $call ) {
				if ( $call['method'] === $method ) {
					$out[] = $call;
				}
			}
			return $out;
		}

		/**
		 * The contact data of every createOrUpdate call, oldest first.
		 *
		 * @return array
		 */
		public function create_or_update_calls() {
			$out = array();
			foreach ( $this->calls( 'createOrUpdate' ) as $call ) {
				$out[] = $call['args']['data'];
			}
			return $out;
		}

		/**
		 * Every list attachment call, oldest first.
		 *
		 * @return array
		 */
		public function list_calls() {
			return $this->calls( 'attachLists' );
		}

		/**
		 * Every tag attachment call, oldest first.
		 *
		 * @return array
		 */
		public function tag_calls() {
			return $this->calls( 'attachTags' );
		}

		/**
		 * Number of recorded calls, optionally for one method.
		 *
		 * @param string|null $method Method name.
		 * @return int
		 */
		public function call_count( $method = null ) {
			return count( $this->calls( $method ) );
		}

		/**
		 * The whole contact universe, keyed by lower-cased email.
		 *
		 * @return array
		 */
		public function contacts() {
			return $this->contacts;
		}

		/**
		 * One contact, or null when the email was never written.
		 *
		 * @param string $email Email address.
		 * @return array|null
		 */
		public function contact( $email ) {
			$key = self::key( $email );
			return isset( $this->contacts[ $key ] ) ? $this->contacts[ $key ] : null;
		}

		/**
		 * Number of distinct contacts written.
		 *
		 * @return int
		 */
		public function contact_count() {
			return count( $this->contacts );
		}

		/**
		 * Every data key ever written to any contact.
		 *
		 * This is the check behind "no enquiry workflow data reaches FluentCRM":
		 * the union must hold nothing beyond the linked field set.
		 *
		 * @return array
		 */
		public function written_fields() {
			$keys = array();
			foreach ( $this->calls( 'createOrUpdate' ) as $call ) {
				foreach ( array_keys( $call['args']['data'] ) as $key ) {
					$keys[ $key ] = true;
				}
			}
			return array_keys( $keys );
		}

		/**
		 * Lists attached to a contact.
		 *
		 * @param string $email Email address.
		 * @return array
		 */
		public function lists_for( $email ) {
			$contact = $this->contact( $email );
			return $contact ? $contact['lists'] : array();
		}

		/**
		 * Tags attached to a contact.
		 *
		 * @param string $email Email address.
		 * @return array
		 */
		public function tags_for( $email ) {
			$contact = $this->contact( $email );
			return $contact ? $contact['tags'] : array();
		}

		/**
		 * Subscriber id held for an email, 0 when unknown.
		 *
		 * @param string $email Email address.
		 * @return int
		 */
		public function subscriber_id_for( $email ) {
			$contact = $this->contact( $email );
			return $contact ? (int) $contact['subscriber_id'] : 0;
		}

		/**
		 * Resolve the id an email should take.
		 *
		 * @param string $email Contact key.
		 * @return int
		 */
		protected function resolve_id( $email ) {
			if ( isset( $this->assigned[ $email ] ) ) {
				return (int) $this->assigned[ $email ];
			}
			$id = $this->next_id;
			++$this->next_id;
			return $id;
		}

		/**
		 * Canonical contact key: trimmed, lower-cased email.
		 *
		 * @param string $email Email address.
		 * @return string
		 */
		protected static function key( $email ) {
			return strtolower( trim( (string) $email ) );
		}
	}

	/**
	 * Class FakeCrmContacts
	 *
	 * Stands in for FluentCrmApi( 'contacts' ).
	 */
	class FakeCrmContacts {

		/**
		 * Owning fake.
		 *
		 * @var FakeCrm
		 */
		protected $crm;

		/**
		 * Constructor.
		 *
		 * @param FakeCrm $crm Owning fake.
		 */
		public function __construct( FakeCrm $crm ) {
			$this->crm = $crm;
		}

		/**
		 * Upsert a contact matched on email.
		 *
		 * @param array $data Contact data.
		 * @return FakeCrmSubscriber|null
		 */
		public function createOrUpdate( array $data ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$this->crm->record( 'createOrUpdate', array( 'data' => $data ) );
			return $this->crm->upsert( $data );
		}

		/**
		 * Read a contact by email, as the real API allows.
		 *
		 * @param string $email Email address.
		 * @return FakeCrmSubscriber|null
		 */
		public function getContact( $email ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$this->crm->record( 'getContact', array( 'email' => $email ) );
			$id = $this->crm->subscriber_id_for( $email );
			return $id ? new FakeCrmSubscriber( $this->crm, $email, $id ) : null;
		}

		/**
		 * The subscriber model a caller scopes its own query from.
		 *
		 * Mirrors `FluentCrmApi( 'contacts' )->getInstance()`: a fresh instance
		 * per call, so two scoped reads are two queries rather than one carrying
		 * both scopes.
		 *
		 * @return FakeCrmSubscriberQuery
		 */
		public function getInstance() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$this->crm->record( 'getInstance' );
			return new FakeCrmSubscriberQuery( $this->crm );
		}
	}

	/**
	 * Class FakeCrmSubscriberQuery
	 *
	 * Stands in for the Subscriber model as a query source: the `filterByLists`
	 * and `filterByTags` scopes, `limit()` and `get()`. Read-only by
	 * construction — there is no write method on it at all.
	 */
	class FakeCrmSubscriberQuery {

		/**
		 * Owning fake.
		 *
		 * @var FakeCrm
		 */
		protected $crm;

		/**
		 * Matched contacts, or null before any scope was applied.
		 *
		 * @var FakeCrmContact[]|null
		 */
		protected $matched = null;

		/**
		 * Row cap, 0 for none.
		 *
		 * @var int
		 */
		protected $cap = 0;

		/**
		 * Constructor.
		 *
		 * @param FakeCrm $crm Owning fake.
		 */
		public function __construct( FakeCrm $crm ) {
			$this->crm = $crm;
		}

		/**
		 * Scope to contacts in any of the given lists.
		 *
		 * @param array $lists List identifiers.
		 * @return FakeCrmSubscriberQuery
		 */
		public function filterByLists( $lists ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM scope.
			return $this->scope( 'lists', (array) $lists );
		}

		/**
		 * Scope to contacts holding any of the given tags.
		 *
		 * @param array $tags Tag identifiers.
		 * @return FakeCrmSubscriberQuery
		 */
		public function filterByTags( $tags ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM scope.
			return $this->scope( 'tags', (array) $tags );
		}

		/**
		 * Cap the number of rows returned.
		 *
		 * @param int $count Row cap.
		 * @return FakeCrmSubscriberQuery
		 */
		public function limit( $count ) {
			$this->cap = (int) $count;
			$this->crm->record( 'limit', array( 'count' => (int) $count ) );
			return $this;
		}

		/**
		 * The matched contacts.
		 *
		 * An unscoped query answers nothing rather than the whole CRM, so a
		 * caller that forgot to scope migrates nothing instead of everything.
		 *
		 * @return FakeCrmContact[]
		 */
		public function get() {
			$rows = null === $this->matched ? array() : $this->matched;

			if ( $this->cap > 0 ) {
				$rows = array_slice( $rows, 0, $this->cap );
			}

			$this->crm->record( 'get', array( 'count' => count( $rows ) ) );

			return $rows;
		}

		/**
		 * Apply one scope, intersecting with any already applied.
		 *
		 * Intersecting is what FluentCRM does when both scopes are chained, so a
		 * caller wanting the union has to read twice — as the real one does.
		 *
		 * @param string $bucket 'lists' or 'tags'.
		 * @param array  $values Identifiers to match.
		 * @return FakeCrmSubscriberQuery
		 */
		protected function scope( $bucket, array $values ) {
			$this->crm->record(
				'lists' === $bucket ? 'filterByLists' : 'filterByTags',
				array( 'values' => array_values( $values ) )
			);

			$found = $this->crm->subscribers_by( $bucket, $values );

			if ( null === $this->matched ) {
				$this->matched = $found;

				return $this;
			}

			$ids  = array();
			$keep = array();

			foreach ( $found as $contact ) {
				$ids[ (int) $contact->id ] = true;
			}

			foreach ( $this->matched as $contact ) {
				if ( isset( $ids[ (int) $contact->id ] ) ) {
					$keep[] = $contact;
				}
			}

			$this->matched = $keep;

			return $this;
		}
	}

	/**
	 * Class FakeCrmContact
	 *
	 * One contact as a read returns it: the attributes the Subscriber model
	 * exposes as properties, the `custom_fields()` accessor, and the `notes`
	 * relation. Nothing on it writes.
	 */
	class FakeCrmContact {

		/**
		 * Subscriber id.
		 *
		 * @var int
		 */
		public $id;

		/**
		 * Given name.
		 *
		 * @var string
		 */
		public $first_name;

		/**
		 * Family name.
		 *
		 * @var string
		 */
		public $last_name;

		/**
		 * Email address.
		 *
		 * @var string
		 */
		public $email;

		/**
		 * Telephone number.
		 *
		 * @var string
		 */
		public $phone;

		/**
		 * Contact creation time.
		 *
		 * @var string
		 */
		public $created_at;

		/**
		 * Notes, oldest first.
		 *
		 * @var FakeCrmNote[]
		 */
		public $notes = array();

		/**
		 * Custom field map.
		 *
		 * @var array
		 */
		protected $fields = array();

		/**
		 * Constructor.
		 *
		 * @param array $data Seeded subscriber data.
		 */
		public function __construct( array $data ) {
			$this->id         = isset( $data['id'] ) ? (int) $data['id'] : 0;
			$this->first_name = isset( $data['first_name'] ) ? (string) $data['first_name'] : '';
			$this->last_name  = isset( $data['last_name'] ) ? (string) $data['last_name'] : '';
			$this->email      = isset( $data['email'] ) ? (string) $data['email'] : '';
			$this->phone      = isset( $data['phone'] ) ? (string) $data['phone'] : '';
			$this->created_at = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
			$this->fields     = isset( $data['custom_fields'] ) ? (array) $data['custom_fields'] : array();

			foreach ( isset( $data['notes'] ) ? (array) $data['notes'] : array() as $note ) {
				$this->notes[] = new FakeCrmNote( (array) $note );
			}
		}

		/**
		 * The contact's custom field values.
		 *
		 * @return array
		 */
		public function custom_fields() {
			return $this->fields;
		}
	}

	/**
	 * Class FakeCrmNote
	 *
	 * Stands in for a SubscriberNote row: FluentCRM keeps the text in
	 * `description` and the subject in `title`.
	 */
	class FakeCrmNote {

		/**
		 * Note text.
		 *
		 * @var string
		 */
		public $description;

		/**
		 * Note subject.
		 *
		 * @var string
		 */
		public $title;

		/**
		 * Creation time.
		 *
		 * @var string
		 */
		public $created_at;

		/**
		 * Constructor.
		 *
		 * @param array $data Note data. `body` is accepted for `description`.
		 */
		public function __construct( array $data ) {
			$text = '';

			if ( isset( $data['description'] ) ) {
				$text = (string) $data['description'];
			} elseif ( isset( $data['body'] ) ) {
				$text = (string) $data['body'];
			}

			$this->description = $text;
			$this->title       = isset( $data['title'] ) ? (string) $data['title'] : '';
			$this->created_at  = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		}
	}

	/**
	 * Class FakeCrmSubscriber
	 *
	 * Stands in for the subscriber model createOrUpdate() returns.
	 */
	class FakeCrmSubscriber {

		/**
		 * Subscriber identifier. 0 stands for "the response carried no id".
		 *
		 * @var int
		 */
		public $id;

		/**
		 * Contact email.
		 *
		 * @var string
		 */
		public $email;

		/**
		 * Owning fake.
		 *
		 * @var FakeCrm
		 */
		protected $crm;

		/**
		 * Constructor.
		 *
		 * @param FakeCrm $crm   Owning fake.
		 * @param string  $email Contact email.
		 * @param int     $id    Subscriber id.
		 */
		public function __construct( FakeCrm $crm, $email, $id ) {
			$this->crm   = $crm;
			$this->email = (string) $email;
			$this->id    = (int) $id;
		}

		/**
		 * Attach lists to this contact.
		 *
		 * @param array $lists List identifiers or names.
		 * @return FakeCrmSubscriber
		 */
		public function attachLists( $lists ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$this->crm->attach( $this->email, 'lists', (array) $lists );
			return $this;
		}

		/**
		 * Attach tags to this contact.
		 *
		 * @param array $tags Tag identifiers or names.
		 * @return FakeCrmSubscriber
		 */
		public function attachTags( $tags ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$this->crm->attach( $this->email, 'tags', (array) $tags );
			return $this;
		}

		/**
		 * Read a stored contact value.
		 *
		 * @param string $key Field name.
		 * @return mixed|null
		 */
		public function get( $key ) {
			if ( 'id' === $key ) {
				return $this->id;
			}
			$contact = $this->crm->contact( $this->email );
			if ( ! $contact || ! array_key_exists( $key, $contact['data'] ) ) {
				return null;
			}
			return $contact['data'][ $key ];
		}

		/**
		 * Property access, as an Eloquent model allows.
		 *
		 * @param string $key Field name.
		 * @return mixed|null
		 */
		public function __get( $key ) {
			return $this->get( $key );
		}

		/**
		 * The contact as an array.
		 *
		 * @return array
		 */
		public function toArray() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the FluentCRM API.
			$contact = $this->crm->contact( $this->email );
			$data    = $contact ? $contact['data'] : array();
			return array_merge( array( 'id' => $this->id ), $data );
		}
	}
}

namespace {

	/**
	 * FluentCRM API entry point, resolved against the installed FakeCrm.
	 *
	 * Declared only when the real FluentCRM is absent, so a test run inside a
	 * site that has FluentCRM active still exercises the real API.
	 */
	if ( ! function_exists( 'FluentCrmApi' ) ) {
		/**
		 * Resolve a FluentCRM API module.
		 *
		 * @param string $key Module key, e.g. 'contacts'.
		 * @return mixed|null Null when no fake is installed or the fake is set unavailable.
		 */
		function FluentCrmApi( $key = 'contacts' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors the FluentCRM API.
			$crm = \MarthrownEnquiryHub\Tests\Fakes\FakeCrm::current();
			if ( ! $crm ) {
				return null;
			}
			return $crm->api( $key );
		}
	}
}
