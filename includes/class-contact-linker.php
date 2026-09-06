<?php
/**
 * FluentCRM contact linkage.
 *
 * The one place the enquiry layer talks to FluentCRM, and it only ever upserts:
 * a contact matched on the enquiry `email` value, carrying `first_name`,
 * `last_name`, `email` and `phone`, added to the configured list and given the
 * configured tag (Requirements 6.1–6.5). Nothing else goes across. No status, no
 * candidate date, no `event_type`, no `site_exclusivity`, no `message`
 * (Requirements 6.8, 6.9) — enquiry workflow lives in the Enquiry Store, and the
 * CRM stays a mailing tool rather than a second, disagreeing record of where an
 * enquiry stands.
 *
 * Two rules shape every method here:
 *
 * - **The enquiry is already stored.** Linkage runs after the store write, never
 *   before (Requirement 5.1), so a CRM fault can only ever downgrade an enquiry
 *   to `crm_sync_state = pending` and put it in front of the hub's retry action.
 *   It can never lose the enquiry.
 * - **Every FluentCRM call is guarded twice.** An existence check first, because
 *   FluentCRM may be inactive or absent, then `try`/`catch ( \Throwable )`,
 *   because an active FluentCRM can still raise. A failure of either kind is a
 *   failure of linkage and nothing more (Requirements 6.6, 6.7).
 *
 * A failure leaves `crm_sync_state` at `pending` and never clears
 * `fluentcrm_subscriber_id`: an enquiry that was linked before and whose re-link
 * has just failed keeps pointing at the contact it was linked to
 * (Requirement 19.16), and an enquiry that was never linked has nothing to
 * clear (Requirement 6.7).
 *
 * `link()`, `retry()` and `relink()` are the same upsert reached three ways —
 * on creation, from the retry route, and after an edit — so a `pending`
 * creation and a `pending` edit recover by the identical route.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContactLinker
 */
class ContactLinker {

	/**
	 * Fields whose change requires a re-link (Requirement 19.14).
	 *
	 * Also the complete set of contact fields the linker writes
	 * (Requirement 6.3), which is what makes Requirements 6.8 and 6.9 a property
	 * of this list rather than of each call site: a field absent from here
	 * reaches FluentCRM through no path at all.
	 *
	 * @var string[]
	 */
	const LINKED_FIELDS = array( 'first_name', 'last_name', 'email', 'phone' );

	/**
	 * `crm_sync_state` after a successful upsert (Requirement 5.5).
	 */
	const STATE_SYNCED = 'synced';

	/**
	 * `crm_sync_state` after a failure (Requirements 5.2, 6.6, 19.16).
	 */
	const STATE_PENDING = 'pending';

	/**
	 * The history entry type a successful link appends.
	 */
	const HISTORY_TYPE = 'crm_linked';

	/**
	 * The FluentCRM API module the linker uses.
	 */
	const API_MODULE = 'contacts';

	/**
	 * Tag applied to a contact linked from the staging copy (Requirement 6.10).
	 */
	const TEST_TAG = 'test-record';

	/**
	 * Filter naming the staging tag, so a site whose FluentCRM needs a tag
	 * identifier rather than a title can map it.
	 */
	const TEST_TAG_FILTER = 'meh_crm_test_tag';

	/**
	 * Whether FluentCRM is present and its contacts API resolves.
	 *
	 * Deliberately cheap and deliberately silent: an inactive FluentCRM is a
	 * normal state of the site, not an error, and this is the check that keeps
	 * the enquiry layer working without it (Requirement 6.7).
	 *
	 * @return bool
	 */
	public static function available() {
		return null !== self::contacts();
	}

	/**
	 * Link one enquiry to its FluentCRM contact.
	 *
	 * Called once per created enquiry, by the shared creation path, after the
	 * enquiry row exists (Requirement 5.1).
	 *
	 * On success the subscriber identifier is recorded, `crm_sync_state` becomes
	 * `synced` and a `crm_linked` history entry is appended (Requirements 5.5,
	 * 6.5). On failure `crm_sync_state` becomes `pending`, no subscriber
	 * identifier is written, and the enquiry itself is untouched otherwise
	 * (Requirements 5.2, 6.6, 6.7).
	 *
	 * A duplicated enquiry reuses the subscriber identifier its source holds,
	 * when it holds one (Requirement 9.8). The upsert still runs, because the
	 * copy's contact data and its list and tag membership should be as current
	 * as any other enquiry's; the source's identifier simply wins over whatever
	 * the upsert resolved, so a copy and its source always name the same
	 * contact.
	 *
	 * @param int      $enquiry_id Enquiry to link.
	 * @param int|null $actor      Acting user identifier for the history entry.
	 *                             Null resolves the current user, which is 0 for
	 *                             the intake path.
	 * @return array{subscriber_id:int,reused:bool}|\WP_Error
	 */
	public static function link( $enquiry_id, $actor = null ) {
		$enquiry_id = (int) $enquiry_id;

		if ( $enquiry_id <= 0 ) {
			return self::error( 'meh_crm_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$enquiry = EnquiryStore::find( $enquiry_id );

		if ( null === $enquiry ) {
			return self::error( 'meh_crm_not_found', 'The enquiry does not exist.', 404 );
		}

		$result = self::upsert( $enquiry );

		if ( is_wp_error( $result ) ) {
			return self::fail( $enquiry_id, $result );
		}

		$inherited     = self::inherited_subscriber_id( $enquiry );
		$subscriber_id = $inherited > 0 ? $inherited : (int) $result['subscriber_id'];

		return self::succeed(
			$enquiry_id,
			$subscriber_id,
			array(
				'subscriber_id' => $subscriber_id,
				'reused'        => $inherited > 0,
			),
			array(
				'subscriber_id' => $subscriber_id,
				'email'         => isset( $enquiry['email'] ) ? (string) $enquiry['email'] : '',
				'reused'        => $inherited > 0,
			),
			$actor
		);
	}

	/**
	 * Retry linkage for an enquiry whose `crm_sync_state` is `pending`.
	 *
	 * The same contract as `link()`, and deliberately the same work: the upsert
	 * is idempotent on the email address, so re-running it from the current
	 * stored values is the whole of the recovery (Requirement 5.4). A `pending`
	 * creation and a `pending` edit therefore recover by the identical route.
	 *
	 * @param int      $enquiry_id Enquiry to retry.
	 * @param int|null $actor      Acting user identifier for the history entry.
	 * @return array{subscriber_id:int,reused:bool}|\WP_Error
	 */
	public static function retry( $enquiry_id, $actor = null ) {
		return self::link( $enquiry_id, $actor );
	}

	/**
	 * Re-link the contact after an applied edit.
	 *
	 * Takes the `changed` map `EnquiryStore::update()` returned, so the decision
	 * is made from what actually changed rather than from what was submitted.
	 *
	 * Three outcomes:
	 *
	 * - **Nothing linked changed.** The map intersects self::LINKED_FIELDS in
	 *   nothing, so `null` comes back, no FluentCRM call is made at all, and
	 *   `fluentcrm_subscriber_id` and `crm_sync_state` are left exactly as they
	 *   were (Requirement 19.17). Correcting a `message`, a candidate date or a
	 *   guest count touches the CRM not at all.
	 * - **Something linked changed and the upsert succeeded.** The same upsert as
	 *   `link()`, with the corrected values (Requirement 19.14). FluentCRM
	 *   matches on email, so a corrected address may resolve a *different*
	 *   subscriber — an existing contact under the new address or a newly created
	 *   one. When the returned identifier differs from the stored one it replaces
	 *   it and `crm_sync_state` becomes `synced` (Requirement 19.15); the
	 *   previously linked contact is left alone, with no delete, no merge and no
	 *   tag removal, because upserting is the only thing this class does.
	 * - **Something linked changed and the upsert failed.** The edit still
	 *   stands: every changed field value is retained, `crm_sync_state` becomes
	 *   `pending`, and `fluentcrm_subscriber_id` keeps the value it held before
	 *   the edit rather than being cleared (Requirement 19.16).
	 *
	 * @param int   $enquiry_id Enquiry that was edited.
	 * @param array $changed    The `changed` map, `field => array( from, to )`.
	 * @param int|null $actor   Acting user identifier for the history entry.
	 * @return array{subscriber_id:int,replaced:bool}|\WP_Error|null
	 */
	public static function relink( $enquiry_id, array $changed, $actor = null ) {
		$enquiry_id = (int) $enquiry_id;

		// Requirement 19.17: no linked field changed, so there is nothing to say
		// to FluentCRM and nothing to write. Checked before the enquiry is even
		// read, so this path cannot touch the store either.
		if ( ! self::touches_contact( $changed ) ) {
			return null;
		}

		if ( $enquiry_id <= 0 ) {
			return self::error( 'meh_crm_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$enquiry = EnquiryStore::find( $enquiry_id );

		if ( null === $enquiry ) {
			return self::error( 'meh_crm_not_found', 'The enquiry does not exist.', 404 );
		}

		// The stored values are the corrected ones: the editor writes before it
		// re-links, so reading the enquiry back is what "with the changed values"
		// means in practice (Requirement 19.14).
		$result = self::upsert( $enquiry );

		if ( is_wp_error( $result ) ) {
			return self::fail( $enquiry_id, $result );
		}

		$previous      = (int) self::stored_subscriber_id( $enquiry );
		$subscriber_id = (int) $result['subscriber_id'];
		$replaced      = $previous > 0 && $subscriber_id !== $previous;

		return self::succeed(
			$enquiry_id,
			$subscriber_id,
			array(
				'subscriber_id' => $subscriber_id,
				'replaced'      => $replaced,
			),
			array(
				'subscriber_id' => $subscriber_id,
				'email'         => isset( $enquiry['email'] ) ? (string) $enquiry['email'] : '',
				'replaced'      => $replaced,
				'previous_id'   => $previous,
				'fields'        => array_values( self::linked_changes( $changed ) ),
			),
			$actor
		);
	}

	/**
	 * Whether a `changed` map names any linked field.
	 *
	 * @param array $changed The `changed` map.
	 * @return bool
	 */
	public static function touches_contact( array $changed ) {
		return array() !== self::linked_changes( $changed );
	}

	/**
	 * The linked fields a `changed` map names, in self::LINKED_FIELDS order.
	 *
	 * @param array $changed The `changed` map.
	 * @return string[]
	 */
	public static function linked_changes( array $changed ) {
		$named = array();

		foreach ( self::LINKED_FIELDS as $field ) {
			if ( array_key_exists( $field, $changed ) ) {
				$named[] = $field;
			}
		}

		return $named;
	}

	/**
	 * Upsert the FluentCRM contact for one hydrated enquiry.
	 *
	 * The single FluentCRM call site of the class, so every guard is stated once:
	 * the API has to resolve, the enquiry has to carry an email address to match
	 * on, the call runs inside `try`/`catch ( \Throwable )`, and a response
	 * carrying no subscriber identifier is a failure rather than a success with a
	 * missing value (Requirement 6.6).
	 *
	 * List and tag membership is attached after the upsert rather than passed
	 * into it, which keeps the contact *data* the call writes to exactly
	 * self::LINKED_FIELDS (Requirements 6.8, 6.9) while still adding the contact
	 * to the configured list and applying the configured tag (Requirement 6.4).
	 * An attachment that raises fails the whole link, so the enquiry is left
	 * `pending` for the retry route rather than recorded as `synced` while
	 * sitting outside the list the hub reads.
	 *
	 * @param array $enquiry Hydrated enquiry, as `EnquiryStore::find()` returns.
	 * @return array{subscriber_id:int}|\WP_Error
	 */
	protected static function upsert( array $enquiry ) {
		$email = isset( $enquiry['email'] ) ? trim( (string) $enquiry['email'] ) : '';

		if ( '' === $email ) {
			return self::error( 'meh_crm_no_email', 'The enquiry carries no email address to match a contact on.', 422 );
		}

		$contacts = self::contacts();

		// Requirement 6.7: FluentCRM inactive, or its contacts API unavailable.
		if ( null === $contacts ) {
			return self::error( 'meh_crm_unavailable', 'FluentCRM is not available.', 503 );
		}

		$memberships = self::memberships();

		try {
			$subscriber    = $contacts->createOrUpdate( self::contact_data( $enquiry ) );
			$subscriber_id = self::subscriber_id( $subscriber );

			// Requirement 6.6: no identifier means no link, whatever else the
			// response carried.
			if ( $subscriber_id <= 0 ) {
				Log::write(
					'crm: upsert returned no subscriber id',
					array( 'email' => $email )
				);

				return self::error( 'meh_crm_no_subscriber_id', 'FluentCRM returned no subscriber identifier.', 502 );
			}

			self::attach( $subscriber, $memberships['lists'], $memberships['tags'] );
		} catch ( \Throwable $e ) {
			Log::write(
				'crm: upsert failed',
				array(
					'email' => $email,
					'error' => $e->getMessage(),
				)
			);

			return self::error( 'meh_crm_upsert_failed', 'The FluentCRM contact could not be written.', 502 );
		}

		return array( 'subscriber_id' => $subscriber_id );
	}

	/**
	 * The contact data one upsert writes.
	 *
	 * Exactly self::LINKED_FIELDS and nothing else, which is the whole of
	 * Requirements 6.8 and 6.9: there is no branch here that could add a status,
	 * a candidate date, a multi-select value or the message.
	 *
	 * An empty value is omitted rather than sent, so an enquiry carrying no phone
	 * number does not blank a number FluentCRM already holds for that contact.
	 * The email address is never omitted — it is the matching key, and the caller
	 * has already refused an enquiry without one.
	 *
	 * In staging mode the first name carries the configured test prefix
	 * (Requirement 6.10); in production it does not (Requirement 6.11).
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return array<string,string>
	 */
	protected static function contact_data( array $enquiry ) {
		$data = array();

		foreach ( self::LINKED_FIELDS as $field ) {
			$value = isset( $enquiry[ $field ] ) ? trim( (string) $enquiry[ $field ] ) : '';

			if ( 'first_name' === $field ) {
				$value = StagingMarker::apply_name( $value );
			}

			if ( '' === $value && 'email' !== $field ) {
				continue;
			}

			$data[ $field ] = $value;
		}

		return $data;
	}

	/**
	 * The list and tag membership one upsert applies (Requirement 6.4).
	 *
	 * An unconfigured list or tag contributes nothing rather than a zero
	 * identifier, so a site that has not chosen one yet still gets its contacts
	 * written.
	 *
	 * @return array{lists:array<int,int|string>,tags:array<int,int|string>}
	 */
	protected static function memberships() {
		$lists = array();
		$tags  = array();
		$list  = self::list_id();
		$tag   = self::tag_id();

		if ( $list > 0 ) {
			$lists[] = $list;
		}

		if ( $tag > 0 ) {
			$tags[] = $tag;
		}

		// Requirement 6.10: staging records are tagged for one-click removal
		// before go-live. Requirement 6.11: production records are not.
		if ( StagingMarker::is_staging() ) {
			$test_tag = self::test_tag();

			if ( '' !== (string) $test_tag ) {
				$tags[] = $test_tag;
			}
		}

		return array(
			'lists' => $lists,
			'tags'  => $tags,
		);
	}

	/**
	 * Attach list and tag membership to a subscriber.
	 *
	 * Guarded by `method_exists()` rather than assumed, because this runs against
	 * whatever FluentCRM version the site has. Raised exceptions are not caught
	 * here: the caller's `try` block owns them, so an attachment failure and an
	 * upsert failure are handled identically.
	 *
	 * @param mixed $subscriber Subscriber the upsert returned.
	 * @param array $lists      List identifiers.
	 * @param array $tags       Tag identifiers or titles.
	 * @return void
	 */
	protected static function attach( $subscriber, array $lists, array $tags ) {
		if ( ! is_object( $subscriber ) ) {
			return;
		}

		if ( $lists && method_exists( $subscriber, 'attachLists' ) ) {
			$subscriber->attachLists( $lists );
		}

		if ( $tags && method_exists( $subscriber, 'attachTags' ) ) {
			$subscriber->attachTags( $tags );
		}
	}

	/**
	 * The FluentCRM contacts API, or null when it does not resolve.
	 *
	 * Three things can go wrong and all three answer null: FluentCRM is not
	 * loaded, so the entry point does not exist; it is loaded but resolves the
	 * module to nothing; or resolving raises. None of them is an error worth
	 * failing an enquiry over (Requirement 6.7).
	 *
	 * @return object|null An object exposing `createOrUpdate()`.
	 */
	protected static function contacts() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return null;
		}

		try {
			$api = FluentCrmApi( self::API_MODULE );
		} catch ( \Throwable $e ) {
			Log::write( 'crm: contacts api did not resolve', array( 'error' => $e->getMessage() ) );

			return null;
		}

		if ( ! is_object( $api ) || ! method_exists( $api, 'createOrUpdate' ) ) {
			return null;
		}

		return $api;
	}

	/**
	 * Read the subscriber identifier out of whatever the upsert returned.
	 *
	 * FluentCRM returns an Eloquent subscriber model, but the shape is not worth
	 * depending on: an identifier reachable as a property, through `get()`, as an
	 * array key or as a bare number is all the same answer, and anything else is
	 * "no identifier", which Requirement 6.6 makes a failure.
	 *
	 * @param mixed $subscriber Value the upsert returned.
	 * @return int 0 when no identifier could be read.
	 */
	protected static function subscriber_id( $subscriber ) {
		if ( is_numeric( $subscriber ) ) {
			return (int) $subscriber;
		}

		if ( is_array( $subscriber ) ) {
			return isset( $subscriber['id'] ) && is_numeric( $subscriber['id'] ) ? (int) $subscriber['id'] : 0;
		}

		if ( ! is_object( $subscriber ) ) {
			return 0;
		}

		if ( isset( $subscriber->id ) && is_numeric( $subscriber->id ) ) {
			return (int) $subscriber->id;
		}

		if ( method_exists( $subscriber, 'get' ) ) {
			$value = $subscriber->get( 'id' );

			return is_numeric( $value ) ? (int) $value : 0;
		}

		return 0;
	}

	/**
	 * Record a successful link and answer the caller.
	 *
	 * The write comes first, then the history entry: a listener reading the
	 * enquiry after the entry appears always sees `synced` as stored, and a failed
	 * write appends no entry claiming a link that was not recorded.
	 *
	 * @param int      $enquiry_id    Enquiry linked.
	 * @param int      $subscriber_id Subscriber identifier to record.
	 * @param array    $answer        Value to return on success.
	 * @param array    $context       History context.
	 * @param int|null $actor         Acting user identifier.
	 * @return array|\WP_Error
	 */
	protected static function succeed( $enquiry_id, $subscriber_id, array $answer, array $context, $actor = null ) {
		// Requirements 5.5, 6.5, 19.15: the identifier and the state are written
		// together, so no enquiry can hold one without the other.
		$written = EnquiryStore::update_fields(
			$enquiry_id,
			array(
				'fluentcrm_subscriber_id' => (int) $subscriber_id,
				'crm_sync_state'          => self::STATE_SYNCED,
			)
		);

		if ( is_wp_error( $written ) ) {
			Log::write(
				'crm: linkage not recorded',
				array(
					'enquiry_id'    => (int) $enquiry_id,
					'subscriber_id' => (int) $subscriber_id,
					'error'         => $written->get_error_message(),
				)
			);

			return self::fail( $enquiry_id, $written );
		}

		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf( 'Linked to FluentCRM contact %d.', (int) $subscriber_id ),
			$context,
			$actor
		);

		return $answer;
	}

	/**
	 * Mark an enquiry `pending` after a failed link, and pass the failure on.
	 *
	 * `fluentcrm_subscriber_id` is deliberately not written. An enquiry that was
	 * never linked has nothing there to clear (Requirement 6.7), and one that was
	 * linked before keeps pointing at its contact rather than losing the link
	 * because a later re-link failed (Requirement 19.16).
	 *
	 * @param int       $enquiry_id Enquiry whose link failed.
	 * @param \WP_Error $error      The failure to pass on.
	 * @return \WP_Error
	 */
	protected static function fail( $enquiry_id, $error ) {
		$written = EnquiryStore::update_fields(
			(int) $enquiry_id,
			array( 'crm_sync_state' => self::STATE_PENDING )
		);

		if ( is_wp_error( $written ) ) {
			Log::write(
				'crm: pending state not recorded',
				array(
					'enquiry_id' => (int) $enquiry_id,
					'error'      => $written->get_error_message(),
				)
			);
		}

		return $error;
	}

	/**
	 * The subscriber identifier an enquiry already holds.
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return int 0 when none is held.
	 */
	protected static function stored_subscriber_id( array $enquiry ) {
		$stored = isset( $enquiry['fluentcrm_subscriber_id'] ) ? $enquiry['fluentcrm_subscriber_id'] : null;

		return is_numeric( $stored ) ? (int) $stored : 0;
	}

	/**
	 * The subscriber identifier a duplicated enquiry inherits (Requirement 9.8).
	 *
	 * Read from the source enquiry rather than copied by `EnquiryStore::duplicate()`,
	 * which deliberately leaves it unset: reusing it is the linker's decision, and
	 * making it here keeps a copy from holding a subscriber identifier that no CRM
	 * call has yet touched.
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return int 0 when the enquiry is not a copy, or its source holds none.
	 */
	protected static function inherited_subscriber_id( array $enquiry ) {
		$source_id = isset( $enquiry['duplicated_from_id'] ) ? (int) $enquiry['duplicated_from_id'] : 0;

		if ( $source_id <= 0 ) {
			return 0;
		}

		$source = EnquiryStore::find( $source_id );

		return null === $source ? 0 : self::stored_subscriber_id( $source );
	}

	/**
	 * The configured FluentCRM list identifier.
	 *
	 * Asks `Settings` when it is loaded, so the linker and the hub read the same
	 * configuration, and falls back to the stored option so the class also works
	 * on a partial load. Either way an unconfigured list answers 0.
	 *
	 * @return int
	 */
	protected static function list_id() {
		if ( class_exists( __NAMESPACE__ . '\\Settings' ) ) {
			try {
				return (int) Settings::enquiry_list_id();
			} catch ( \Throwable $e ) {
				Log::write( 'crm: list configuration unreadable', array( 'error' => $e->getMessage() ) );
			}
		}

		return function_exists( 'get_option' ) ? (int) get_option( 'meh_enquiry_list', 0 ) : 0;
	}

	/**
	 * The configured FluentCRM tag identifier.
	 *
	 * @return int
	 */
	protected static function tag_id() {
		if ( class_exists( __NAMESPACE__ . '\\Settings' ) ) {
			try {
				return (int) Settings::enquiry_tag_id();
			} catch ( \Throwable $e ) {
				Log::write( 'crm: tag configuration unreadable', array( 'error' => $e->getMessage() ) );
			}
		}

		return function_exists( 'get_option' ) ? (int) get_option( 'meh_enquiry_tag', 0 ) : 0;
	}

	/**
	 * The tag applied to a contact linked from the staging copy.
	 *
	 * Filterable, because a FluentCRM install that needs a tag identifier rather
	 * than a title has to be able to say so without the class knowing which.
	 *
	 * @return int|string
	 */
	protected static function test_tag() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::TEST_TAG;
		}

		/**
		 * Filter the tag applied to contacts linked from the staging copy.
		 *
		 * @param int|string $tag Tag title or identifier. Defaults to `test-record`.
		 */
		return apply_filters( self::TEST_TAG_FILTER, self::TEST_TAG );
	}

	/**
	 * Build a failure carrying an HTTP status, matching the store's shape.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	protected static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}
}
