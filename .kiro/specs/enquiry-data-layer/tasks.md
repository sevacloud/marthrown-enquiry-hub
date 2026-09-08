# Implementation Plan: Enquiry Data Layer

## Overview

Implementation proceeds from the bottom of the stack upward: test tooling, then schema, then the store, then the pure logic layers (validation, field mapping, query building), then lifecycle and history, then intake and contact linkage, then the scheduled and conversion paths, then the REST surface, then the bootstrap, settings and hub UI.

All PHP lives in `c:\git-pers\marthrown-enquiry-hub\includes\` as `class-*.php` files declaring static classes in the `\MarthrownEnquiryHub` namespace, matching the existing plugin convention. Tests live under `tests/` and are excluded from the SFTP deploy.

The design defines 43 correctness properties. Each one gets its own property-based test sub-task, placed immediately after the code it exercises so failures surface early.

## Tasks

- [x] 1. Set up test tooling and shared infrastructure
  - [x] 1.1 Add the PHP and JS test harness
    - Create `composer.json` with `phpunit/phpunit` and `giorgiosironi/eris` as dev dependencies
    - Create `phpunit.xml` with separate `pure` and `wordpress` test suites
    - Create `.wp-env.json` and `tests/bootstrap.php` loading the WordPress PHPUnit test suite and the plugin
    - Add `vendor/`, `composer.lock`, `tests/`, `phpunit.xml`, `.wp-env.json` to `.lftp_ignore` so nothing test-related deploys
    - Add `test:php` and `test:js` scripts to `package.json`, the latter running `wp-scripts test-unit-js`
    - _Requirements: 1.9_

  - [x] 1.2 Add the injectable clock and logging wrapper
    - Create `includes/class-clock.php` with `Clock::now()`, `Clock::mysql()` and a test-only override, all times in the site timezone
    - Create `includes/class-log.php` wrapping `error_log()` behind a `WP_DEBUG` check with the `[MEH]` prefix
    - _Requirements: 1.6, 2.2, 5.6_

  - [x] 1.3 Add test fakes and shared generators
    - Create `tests/fakes/FakeCrm.php` recording every `createOrUpdate`, list and tag call and returning configurable subscriber ids or failures
    - Create `tests/fakes/FakeWpbs.php` recording `wpbs_insert_booking`/`wpbs_insert_event` calls and exposing calendar and legend fixtures
    - Create `tests/Generators.php` with Eris generators for valid enquiries, Candidate Date Range lists of one to three ranges, including a single-day range, term sets of 1 to 20, capacity-length field values, `total_guests` of 1 and 10000, and the adversarial string set (`'`, `"`, `\`, `--`, `;`, `%`, `_`, `%s`, `%d`)
    - _Requirements: 1.18, 3.10_

- [x] 2. Implement the schema manager
  - [x] 2.1 Implement `Schema`
    - Create `includes/class-schema.php` with `table()`, `install()`, `maybe_upgrade()`, `migrations()`, `stored_version()` and `init()`
    - `install()` uses `dbDelta()` for the six `{prefix}meh_` tables with the columns, types and indexes in the design data model
    - Declare `phone` as `VARCHAR(32) NOT NULL DEFAULT ''` and `message` as `TEXT NULL`, so an unsupplied value stores and reads back as the empty string
    - Declare `total_guests` as `SMALLINT UNSIGNED NULL DEFAULT NULL` rather than `NOT NULL DEFAULT 0`, because 0 sits outside the valid 1–10000 range and would be indistinguishable from "not supplied"; `NULL` is the only value in the column's domain that can never be mistaken for a guest count
    - Accept 0 to 20 rows per taxonomy in `{prefix}meh_enquiry_terms`, zero rows being a valid state that a read returns as an empty array rather than as an error or a null
    - `maybe_upgrade()` reads `meh_db_version` first and returns before any `$wpdb` call when it equals `CURRENT_VERSION`
    - Apply migrations in ascending version order, record the version only after every step succeeds, and log `[MEH] schema: {table} — {reason}` on failure
    - Expose no drop method
    - _Requirements: 1.1, 1.2, 1.3, 1.7, 1.9, 1.10, 1.11, 1.13, 1.14, 1.15, 1.16, 1.17, 1.18, 1.19_

  - [x] 2.2 Write property test for schema upgrades
    - **Property 4: Schema upgrades are ordered, atomic and non-destructive**
    - **Validates: Requirements 1.9, 1.10, 1.11, 1.12, 1.16**

  - [x] 2.3 Write property test for matched schema versions
    - **Property 5: A matched schema version performs no work**
    - **Validates: Requirements 1.17**

  - [x] 2.4 Write unit tests for schema facts
    - Table names carry the WordPress prefix plus `meh_` and stay within 64 characters, including under a long prefix
    - The indexes named in the design exist on the enquiry and Candidate Date Range tables
    - `install()` completes inside 30 seconds against an empty database
    - No code path drops an Enquiry Store table
    - _Requirements: 1.13, 1.14, 1.15, 1.9_

- [x] 3. Implement the enquiry store
  - [x] 3.1 Implement enquiry writes and hydrated reads
    - Create `includes/class-enquiry-store.php` with `create()`, `update_fields()` and `find()`
    - `create()` writes the parent row then Candidate Date Range, term and payload rows inside a transaction, rolling back or deleting written rows on any child failure and returning `WP_Error`
    - `find()` returns the hydrated shape in the design, with `date_ranges` as a rank-ordered list of `start`/`end` pairs, `event_type` and `site_exclusivity` as arrays and `payload` decoded
    - Serialize the payload snapshot as JSON into the `LONGTEXT` column
    - Bind every value through `$wpdb->prepare()`
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.18, 3.10, 3.13_

  - [x] 3.2 Write property test for enquiry storage round trip
    - **Property 1: Enquiry storage round trip**
    - **Validates: Requirements 1.1, 1.2, 1.3, 1.6, 1.18, 1.19**

  - [x] 3.3 Write property test for payload snapshot serialization
    - **Property 2: Payload snapshot serialization round trip**
    - **Validates: Requirements 1.7, 1.8**

  - [x] 3.4 Write property test for contact identity constraints
    - **Property 3: No uniqueness constraints on contact identity**
    - **Validates: Requirements 1.4, 1.5**

  - [x] 3.5 Write property test for parameter binding
    - **Property 12: Adversarial values are bound, not interpolated**
    - **Validates: Requirements 3.10**

  - [x] 3.6 Implement the remaining store operations
    - Add `duplicate()` copying scalars, dates and terms all-or-nothing, then writing the `duplicated_from_id`/`duplicated_to_id` pair only after every step succeeds
    - Add `record_rejection()` and `rejections()` against `{prefix}meh_enquiry_rejections`
    - Add `delete_test_records()` removing `is_test` enquiries and all their child rows
    - Add `siblings_by_email()`, `settled_before()`, `query()` and `status_counts()`
    - _Requirements: 3.8, 4.3, 4.7, 9.4, 9.5, 9.6, 9.7, 12.1, 12.9, 13.3, 17.7, 17.8_

  - [x] 3.7 Write property test for test-record deletion
    - **Property 38: Deleting test records spares live records**
    - **Validates: Requirements 17.7, 17.8**

  - [x] 3.8 Implement `EnquiryStore::update()`
    - Add `update( int $id, array $fields, ?array $dates = null, ?array $terms = null )` to `includes/class-enquiry-store.php` as the correction primitive, distinct from the single-column `update_fields()`
    - Partial: write only the keys present in `$fields`, leaving a key absent from `$fields` at its stored value; a `null` `$dates` or a `null` taxonomy in `$terms` means "not submitted, leave that set alone", an array means "replace the set with exactly this", replaced wholesale rather than diffed
    - Transactional and all-or-nothing: the scalar write, the date replacement and each taxonomy's term replacement run in one transaction, and any failure rolls back — or, without transaction support, restores the child rows read before the write — and returns `WP_Error` leaving every stored value as it was
    - Return a `changed` map, `field => [ from, to ]`, comparing each submitted value against the stored value and treating the date and term sets as set comparisons
    - Write none of `status`, `status_changed_at`, `created_at`, `source`, `is_test`, `booking_id` or `payload`
    - Issue no write at all when `changed` is empty — not an `UPDATE` setting a column to its own value — so `updated_at` is untouched
    - _Requirements: 3.10, 19.4, 19.5, 19.7, 19.8, 19.9, 19.13, 19.14, 19.18_

- [x] 4. Checkpoint - schema and store
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Implement pure intake and query logic
  - [x] 5.1 Implement `Validator`
    - Create `includes/class-enquiry-validator.php` with `validate( array $fields, string $profile = PROFILE_WEBHOOK, string $mode = MODE_FULL )`, `required_for()`, `is_empty()` and `allowed_terms()`
    - Declare `REQUIRED_BY_PROFILE`: `PROFILE_WEBHOOK` requires all nine fields, `PROFILE_MANUAL` requires exactly `first_name`, `last_name`, `email` and `date_ranges`
    - `$profile` selects the required-field set and nothing else; collect every failing field into one error set before returning
    - `$mode` decides only whether absence counts as a violation: under `MODE_FULL` (creation by either route) a required field that is absent fails, under `MODE_PARTIAL` (an edit) a required field that is absent is simply not being changed and produces no failure, while a required field present and holding an empty value fails in both modes
    - Implement `is_empty()` as the one emptiness predicate — key absent, empty string, whitespace-only, or empty collection — used by the presence check and the value-rule gate alike so the two can never disagree
    - Gate every value rule on the field being present and non-empty after trimming rather than on the profile, so an absent or empty optional field yields no error under `PROFILE_MANUAL` while the same field carrying a violating value is rejected identically under both profiles
    - Apply email validation, `total_guests` range 1 to 10000, one to three Candidate Date Ranges each a parseable pair whose end is no earlier than its start, a digit check on `phone`, and vocabulary checks on `event_type` and `site_exclusivity` filtered through `meh_enquiry_terms_{taxonomy}`
    - Keep `date_ranges` required under both profiles, so an edit submitting an empty list is a failure rather than a list-clearing operation
    - Strip HTML tags and trim, then truncate to the configured limits last, for every accepted value under either profile, so no length ever produces a failure
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.9, 3.11, 3.12, 3.14, 3.15, 3.16, 3.17, 3.18_

  - [x] 5.2 Write property test for field rule violations
    - **Property 10: Field rule violations name the offending field**
    - **Validates: Requirements 3.3, 3.4, 3.5, 3.6, 3.11, 3.12, 3.17, 18.6**

  - [x] 5.3 Write property test for sanitisation and truncation
    - **Property 11: Sanitisation and truncation never reject**
    - **Validates: Requirements 3.7, 3.9, 3.18, 19.6**

  - [x] 5.4 Implement `FieldMapper`
    - Create `includes/class-field-mapper.php` with `resolve()` and `mapping()`
    - Resolve the configured `meh_field_map` key first, then fall back to a case-insensitive and separator-insensitive match of the submitted label against the enquiry field name
    - Return null when unresolved so the Validator reports a presence failure
    - _Requirements: 2.7, 2.10, 2.11_

  - [x] 5.5 Write property test for label-based field resolution
    - **Property 8: Unmapped fields resolve by case-insensitive label**
    - **Validates: Requirements 2.10**

  - [x] 5.6 Implement `EnquiryQuery`
    - Create `includes/class-enquiry-query.php` with `normalise()` and `build()`, touching no `$wpdb`
    - `normalise()` applies defaults, caps `per_page` at 200 with a default of 25, and canonicalises key order so differently-ordered parameter sets produce identical output
    - `build()` returns `where`, `bindings`, `order`, `limit` and `warnings`, escaping `%` and `_` in search terms, joining Candidate Date Ranges only when both `date_from` and `date_to` are present, and testing overlap rather than containment, and warning naming the missing parameter otherwise
    - Order by `created_at` descending with a deterministic id tie-break
    - _Requirements: 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.10, 12.12, 12.13, 12.14, 12.15, 17.5_

  - [x] 5.7 Write property test for list filters against a reference implementation
    - **Property 28: List filters match a reference implementation and combine conjunctively**
    - **Validates: Requirements 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.12, 12.15, 17.5**

  - [x] 5.8 Write property test for filter parameter order independence
    - **Property 29: Filter parameter order does not matter**
    - **Validates: Requirements 12.13**

- [x] 6. Implement lifecycle, history and notes
  - [x] 6.1 Implement `HistoryRecorder`
    - Create `includes/class-history-recorder.php` with `record()` and `for_enquiry()`, insert-only
    - Accept the eight recognised entry types `created`, `status_changed`, `auto_closed`, `note_added`, `crm_linked`, `booking_linked`, `duplicated` and `fields_edited`, store a JSON context blob, and set `actor_id` to 0 when no user is authenticated
    - Store a `fields_edited` entry's changed fields, with the previous and the new value of each, in the existing JSON `context` column — it is already `LONGTEXT`, so `fields_edited` needs no schema change
    - Return entries oldest first
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 19.13_

  - [x] 6.2 Write property test for the history log
    - **Property 27: History is an append-only, attributed, ordered log**
    - **Validates: Requirements 11.1, 11.2, 11.3, 11.4, 11.5**

  - [x] 6.3 Implement `Lifecycle`
    - Create `includes/class-lifecycle.php` with `STATUSES` holding the six statuses `new`, `contacted`, `quoted`, `converted`, `lost` and `closed`, plus `allowed_from()`, `can()`, `is_settled()` and `transition()`
    - Declare `TRANSITIONS` as the forward-only table: `new` → `contacted`, `quoted`, `converted`, `lost`; `contacted` → `quoted`, `converted`, `lost`; `quoted` → `converted`, `lost`; `converted` → `closed`; `lost` → `closed`; `closed` → none, so `closed` is terminal through the same table lookup that rejects any other unpermitted pair, with no special case
    - Declare `SETTLED` holding `converted` and `lost` only, and make `is_settled()` its single reader, so an enquiry holding `new`, `contacted` or `quoted` is never settled however long it sits
    - Have `EnquiryStore::settled_before()` derive its status list from the same `SETTLED` constant, so the job and the lifecycle cannot hold different opinions about what settled means
    - `transition()` returns success without writing when the enquiry already holds the requested status
    - On a real change, write `status` and `status_changed_at`, record `status_changed` history and fire `meh_enquiry_status_changed`
    - Reject an impermissible transition with an error naming both statuses and changing nothing
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9, 7.10, 7.11, 7.12_

  - [x] 6.4 Write property test for permitted transitions
    - **Property 20: Transitions succeed exactly when permitted**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.10, 7.11, 7.12**

  - [x] 6.5 Write property test for repeated transitions
    - **Property 21: Repeating a transition is a no-op**
    - **Validates: Requirements 7.9**

  - [x] 6.6 Implement `NoteService`
    - Create `includes/class-note-service.php` with `add()` and `for_enquiry()`
    - Reject a body that is whitespace-only after tag stripping with 400, and a body over 5000 characters with 400 naming the limit
    - Store body, author id and creation time, record `note_added` history, and return notes most recent first
    - _Requirements: 10.2, 10.3, 10.4, 10.5, 10.6, 10.7_

  - [x] 6.7 Write property test for notes
    - **Property 26: Notes storage, validation and ordering**
    - **Validates: Requirements 10.2, 10.3, 10.4, 10.5, 10.6, 10.7**

- [x] 7. Checkpoint - pure logic and lifecycle
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Implement intake
  - [x] 8.1 Implement `IntakeEndpoint`
    - Create `includes/class-intake-endpoint.php` with `init()`, `register_routes()`, `authenticate()`, `handle()`, `normalise()` and `presented_secret()`
    - Register one route, `POST marthrown-enquiry-hub/v1/intake`, with `authenticate()` as its `permission_callback` so it runs before any other work
    - `presented_secret()` resolves the secret header-first from `X-MEH-Intake-Secret`, then falls back to the `meh_secret` query parameter
    - `authenticate()` compares the presented value against the stored `meh_intake_secret` with `hash_equals()` and returns `WP_Error( 'meh_intake_unauthorized', …, [ 'status' => 401 ] )` when the secret is absent or does not match, creating no enquiry, no rejection row and no payload log
    - `normalise()` flattens a JSON or form-encoded body into a flat field map, decodes a delimited `date_ranges`, `event_type` or `site_exclusivity` value into an array, and reads the three start/end field pairs into the ranked range list, and reads the form identifier from the payload field named in `meh_intake_source_field`, falling back to the fixed value `webhook:unidentified`; nothing in it is specific to a sending form plugin
    - `handle()` calls `IntakeHandler::receive()` with the normalised fields, the resolved source and the receipt time, wraps that call in `try`/`catch ( \Throwable )` answering 500 with no partial rows, and answers 201 with the enquiry identifier or 200 when no enquiry was created
    - Emit the secret value in no response body and no response header
    - _Requirements: 2.1, 2.3, 2.4, 2.12, 5.7, 16.8, 16.9, 16.10, 16.11, 16.12, 16.14, 16.15_

  - [x] 8.2 Write property test for intake endpoint authentication
    - **Property 7: Intake endpoint authentication**
    - **Validates: Requirements 16.10, 16.11, 16.12, 16.14, 16.15**

  - [x] 8.3 Implement `DuplicateDetector`
    - Create `includes/class-duplicate-detector.php` with `find_duplicate()` and `is_rate_limited()`
    - Match on email plus the exact set of Candidate Date Ranges, order-insensitively, within a window filtered by `meh_duplicate_window`, defaulting to 900 seconds
    - `is_rate_limited()` takes the submitted email address rather than a client IP, because a server-to-server webhook always presents the site's own address
    - Count requests per hashed submitted email address in a transient against `meh_rate_limit_per_email`, defaulting to 6 per 900 seconds per email, so the two intake guards share one time horizon
    - _Requirements: 4.2, 4.4, 4.5, 4.6, 4.7_

  - [x] 8.4 Write property test for duplicate detection
    - **Property 14: Duplicate detection is exactly email plus date set within the window**
    - **Validates: Requirements 4.2, 4.3, 4.4, 4.5**

  - [x] 8.5 Write property test for rate limiting
    - **Property 15: Rate limiting keys on the submitted email address**
    - **Validates: Requirements 4.6, 4.7**

  - [x] 8.6 Implement `StagingMarker`
    - Create `includes/class-staging-marker.php` with `is_staging()`, `prefix()` and `apply_name()`, wrapping the existing `meh_is_staging()` helper
    - _Requirements: 17.1, 17.2_

  - [x] 8.7 Implement `IntakeHandler`
    - Create `includes/class-intake-handler.php` exposing `receive( array $fields, string $source, string $received_at )`, called by `IntakeEndpoint`, binding no form hooks and registering no `init()` hook of its own
    - Order of work: rate limit by submitted email, duplicate, validate, store, `created` history, then `ContactLinker`; there is no spam check, because a webhook body carries no spam verdict
    - Write exactly one rejection row on every outcome other than a created enquiry, with reason `duplicate`, `rate_limited`, `validation` or `storage`, carrying the payload and the receipt time, and create no enquiry
    - Set status `new`, `created_at`/`updated_at` from the receipt time in the site timezone, `source` from the form identifier the endpoint resolved, and `is_test` from `StagingMarker`
    - On a store failure, log the payload snapshot and reason, skip the linker and set no `crm_sync_state`
    - Return the outcome (created flag, enquiry id, reason, errors) so the endpoint can choose its status code; the endpoint, not the handler, owns the `try`/`catch ( \Throwable )` that answers 500 leaving no partial rows
    - Fire `meh_enquiry_created`
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.11, 2.13, 3.8, 3.13, 4.1, 4.3, 4.6, 5.1, 5.6, 5.8, 17.1, 17.2_

  - [x] 8.8 Write property test for valid submission intake
    - **Property 6: Intake outcome for a valid submission**
    - **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.13, 16.8**

  - [x] 8.9 Write property test for required-field rejection
    - **Property 9: Required-field validation is profile-scoped and names every failure**
    - **Validates: Requirements 2.11, 3.1, 3.2, 3.8, 3.14, 3.15, 3.16, 18.2, 18.3, 18.15, 19.3**

  - [x] 8.10 Write property test for failed intake
    - **Property 13: Failed intake leaves nothing behind and nothing untraced**
    - **Validates: Requirements 3.13, 4.1, 5.6, 5.7, 5.8**

  - [x] 8.11 Write unit tests for the intake secret transports and sender-agnostic normalisation
    - Four secret transport tests: the correct secret in the `X-MEH-Intake-Secret` header is accepted, the correct secret in the `meh_secret` query parameter is accepted, a request presenting no secret is answered 401, a request presenting a mismatched secret is answered 401
    - A source-level assertion that the secret comparison uses `hash_equals()` rather than `===`
    - A normalisation test asserting a Kadence-shaped webhook body and a generic JSON body carrying the same values produce the same normalised field map and the same stored enquiry apart from `source`, with no sender-specific configuration
    - _Requirements: 2.12, 16.10, 16.11, 16.14, 16.15_

- [x] 9. Implement contact linkage
  - [x] 9.1 Implement `ContactLinker`
    - Create `includes/class-contact-linker.php` with `available()`, `link()` and `retry()`
    - Guard every FluentCRM call with an existence check and `try`/`catch`, upsert via `FluentCrmApi( 'contacts' )->createOrUpdate()` matched on email
    - Write only `first_name`, `last_name`, `email`, `phone`, the configured list and the configured tag
    - On success record `fluentcrm_subscriber_id`, set `crm_sync_state` to `synced` and record `crm_linked` history
    - Treat a missing subscriber id or an unavailable API as a failure leaving `crm_sync_state` at `pending`
    - In staging mode prefix `first_name` and apply the `test-record` tag
    - Reuse the source enquiry's subscriber id when linking a duplicated enquiry
    - Add the `LINKED_FIELDS` constant holding `first_name`, `last_name`, `email` and `phone`, and `relink( int $enquiry_id, array $changed )` taking the `changed` map `EnquiryStore::update()` returned
    - `relink()` returns null and makes no FluentCRM call when the `changed` map intersects `LINKED_FIELDS` in nothing, leaving `fluentcrm_subscriber_id` and `crm_sync_state` exactly as they were
    - On a non-empty intersection perform the same `createOrUpdate()` upsert as `link()`, with the changed values, under every criterion of Requirement 6
    - When a changed `email` resolves a subscriber id differing from the stored one, replace the stored id and set `crm_sync_state` to `synced`, leaving the previously linked contact untouched — no delete, no merge, no tag removal
    - On a re-link failure retain every changed field value, set `crm_sync_state` to `pending` and leave `fluentcrm_subscriber_id` at the value it held before the edit rather than clearing it
    - _Requirements: 5.2, 5.4, 5.5, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 6.11, 9.8, 19.14, 19.15, 19.16, 19.17_

  - [x] 9.2 Write property test for linkage failure resilience
    - **Property 16: Contact linkage failure never loses the enquiry**
    - **Validates: Requirements 5.1, 5.2, 5.4, 5.5, 6.6, 6.7**

  - [x] 9.3 Write property test for contact upsert and membership
    - **Property 17: One contact per email, with the configured membership**
    - **Validates: Requirements 6.1, 6.2, 6.3, 6.4, 6.5**

  - [x] 9.4 Write property test for CRM write confinement
    - **Property 18: No enquiry workflow data reaches FluentCRM**
    - **Validates: Requirements 6.8, 6.9**

  - [x] 9.5 Write property test for re-linking after an edit
    - **Property 42: An edit re-links the contact exactly when identity changed**
    - **Validates: Requirements 19.14, 19.15, 19.16, 19.17**

- [x] 20. Implement the shared creation path and the two authenticated write paths
  - [x] 20.1 Implement `EnquiryCreator`
    - Create `includes/class-enquiry-creator.php` with `create( array $fields, string $profile, string $source, string $at, int $actor )`, the single store-then-link sequence both creation callers share
    - Order of work: validate under the given profile, `EnquiryStore::create()` writing the enquiry row, Candidate Date Ranges, terms and payload snapshot in one transaction, `is_test` from `StagingMarker`, a `created` history entry attributed to `$actor`, then `ContactLinker::link()` setting `crm_sync_state` to `synced` or `pending`
    - Set `status` to `new` and `created_at`, `updated_at` and `status_changed_at` all to `$at`, for either caller
    - Run no duplicate or rate-limit guard and write no rejection row; both belong to the caller, which is what confines the webhook-only behaviour to `IntakeHandler`
    - Fire `meh_enquiry_created` once per created enquiry, whichever caller invoked it
    - _Requirements: 2.1, 2.2, 5.1, 5.2, 5.5, 18.1, 18.7, 18.9, 18.10, 18.11, 18.12, 18.16, 18.17, 18.18, 18.19, 18.20_

  - [x] 20.2 Refactor `IntakeHandler` onto `EnquiryCreator`
    - Replace everything from validate onward in `includes/class-intake-handler.php` with one `EnquiryCreator::create()` call passing `Validator::PROFILE_WEBHOOK`, the derived `source`, the receipt time and actor 0
    - Leave `IntakeHandler` only the webhook-specific work: the rate-limit and duplicate guards, choosing the Webhook profile, deriving `source` from the form identifier the endpoint resolved, and writing the one rejection row on every outcome other than a created enquiry
    - Perform no enquiry insert of its own, so the `created` history entry and `meh_enquiry_created` both come from the shared path
    - Keep `receive()`'s return shape unchanged so `IntakeEndpoint` still chooses its own status code
    - _Requirements: 2.1, 4.1, 5.8, 18.12_

  - [x] 20.3 Implement `EnquiryEditor`
    - Create `includes/class-enquiry-editor.php` with `apply( int $enquiry_id, array $fields, int $actor )`
    - Order of work: `guard_writable()` first — 404 for an unknown identifier, 409 for `status === 'closed'`, before anything is read or validated — then `Validator::validate( $fields, PROFILE_MANUAL, MODE_PARTIAL )` over the fields present in the request, returning 400 with every failing field named and nothing written, then `EnquiryStore::update()`
    - Drive three decisions off the returned `changed` map: set `updated_at` to the request time only when it is non-empty, record one `fields_edited` history entry carrying that map and `$actor`, and call `ContactLinker::relink( $id, $changed )`
    - Return having written nothing when `changed` is empty, so `updated_at` is untouched, no history entry exists and no FluentCRM call is made
    - Read `source` nowhere, so a webhook, manual or migration enquiry is equally editable
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 19.8, 19.9, 19.10, 19.12, 19.13, 19.14, 19.18_

  - [x] 20.4 Write property test for manual enquiry creation
    - **Property 39: Manual creation is a full enquiry, unguarded and attributed**
    - **Validates: Requirements 18.1, 18.4, 18.5, 18.7, 18.8, 18.9, 18.10, 18.11, 18.12, 18.13, 18.14, 18.15, 18.16, 18.17, 18.18, 18.19, 18.20**

  - [x] 20.5 Write property test for an applied edit
    - **Property 40: An applied edit changes only what it names and records what it changed**
    - **Validates: Requirements 19.1, 19.2, 19.6, 19.7, 19.8, 19.9, 19.10, 19.11, 19.13**

  - [x] 20.6 Write property test for a rejected edit
    - **Property 41: A rejected edit writes nothing at all**
    - **Validates: Requirements 19.4, 19.5, 19.12**

  - [x] 20.7 Write property test for a no-op edit
    - **Property 43: An edit that changes nothing is a no-op**
    - **Validates: Requirements 19.18**

  - [x] 20.8 Write unit tests asserting the creation path is genuinely shared
    - Source-level assertion that `IntakeHandler` and the manual creation route both reach the store through `EnquiryCreator::create()` and that neither performs its own enquiry insert
    - A webhook submission and a manual submission carrying equivalent field values produce enquiries differing only in `source`, in the `created` entry's `actor_id`, and in whether a guard ran
    - _Requirements: 18.1, 18.8, 18.18_

  - [x] 20.9 Write unit test asserting the guards are webhook-only
    - Source-level assertion that no `DuplicateDetector` call and no `record_rejection()` call is reachable from the manual creation route
    - _Requirements: 18.12, 18.13, 18.14, 18.15_

  - [x] 20.10 Write unit test for edit guard ordering
    - A closed enquiry receiving an edit request whose body would fail validation is answered 409, not 400, confirming `guard_writable()` runs before the validator
    - _Requirements: 9.1, 19.12_

- [x] 10. Checkpoint - intake and linkage
  - Ensure all tests pass, ask the user if questions arise.

- [x] 11. Implement automatic closure
  - [x] 11.1 Implement `AutoCloseJob`
    - Create `includes/class-auto-close-job.php` with `init()`, `schedule()`, `unschedule()` and `run()`
    - Close settled enquiries whose `status_changed_at` is strictly more than the `meh_auto_close_days` interval (default 7) before the run time, by calling `Lifecycle::transition()`
    - Record `auto_closed` history attributed to the system, process in batches, and continue past an individual failure while logging it
    - _Requirements: 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 8.10, 11.5_

  - [x] 11.2 Write property test for auto-closure selection
    - **Property 22: Auto-closure closes exactly the overdue settled enquiries**
    - **Validates: Requirements 7.12, 8.3, 8.5, 8.6, 8.7, 8.8, 8.10**

  - [x] 11.3 Write property test for auto-closure idempotence
    - **Property 23: Auto-closure is idempotent**
    - **Validates: Requirements 8.9**

  - [x] 11.4 Write unit tests for cron wiring
    - Activation schedules exactly one daily `meh_cron_auto_close`; deactivation clears it; `meh_cron_email_poll` and `meh_cron_wpbs_poll` stay cleared
    - _Requirements: 8.1, 8.2_

  - [x] 11.5 Write architectural test for sole closure authority
    - Source scan asserting no component other than `AutoCloseJob` closes an enquiry on an elapsed-interval condition
    - _Requirements: 8.4_

- [x] 12. Replace the booking conversion path
  - [x] 12.1 Move the shared WPBS helpers into `SourceWpbs`
    - Move the legend-lookup and date-blocking helpers out of `class-booking-converter.php` into `includes/class-source-wpbs.php`
    - Keep `calendar_names()` as the calendar existence check used by the guards
    - _Requirements: 14.4, 14.8_

  - [x] 12.2 Implement `BookingCreator`
    - Create `includes/class-booking-creator.php` with `create_from_enquiry()`
    - Guard in order: WPBS available (503), enquiry exists (404), not closed (409), no existing `booking_id` (409), calendar known (400), both dates parse (400 `meh_booking_invalid_date`), end no earlier than start (400 `meh_booking_invalid_range`) — the booked range is deliberately *not* confined to the enquiry's Candidate Date Ranges
    - Insert the booking via `wpbs_insert_booking()` with start and end set to the chosen date and guest name/email from the enquiry, test-prefixed in staging
    - Block the date with the calendar's `booked` legend item via `wpbs_insert_event()`, keeping the booking and warning if blocking fails
    - Record `booking_id`, append `booking_linked` history, and transition the enquiry to `converted`
    - Trigger no WPBS email, payment, pricing or inventory flow
    - _Requirements: 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.8, 14.9, 14.10, 14.11, 17.3_

  - [x] 12.3 Write property test for conversion
    - **Property 33: Conversion creates the booking and records it**
    - **Validates: Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.11**

  - [x] 12.4 Write property test for conversion guards
    - **Property 34: Conversion guards reject without side effects**
    - **Validates: Requirements 14.8, 14.9, 14.10**

  - [x] 12.5 Retire the legacy converter
    - Delete `includes/class-booking-converter.php`
    - Remove the `POST /bookings/{id}/convert` route and its handler from `includes/class-rest-bookings.php`
    - Remove the require from the bootstrap
    - _Requirements: 14.12_

  - [x] 12.6 Write property test for staging test marking
    - **Property 19: Test marking follows the environment**
    - **Validates: Requirements 6.10, 6.11, 17.1, 17.2, 17.3**

- [x] 13. Implement the FluentCRM migration
  - [x] 13.1 Implement `MigrationRunner`
    - Create `includes/class-migration-runner.php` with `preview()` and `run()`
    - Read contacts in the configured list or holding the configured tag, performing no FluentCRM write
    - Create one enquiry per eligible contact holding `first_name`, `last_name`, `email`, `phone`, creation time and subscriber id, with `source` set to `migration:fluentcrm`
    - Map `meh_enquiry_status` per `STATUS_MAP` — `new`→`new`, `replied`→`contacted`, `quoted`→`quoted`, `converted`→`converted`, `closed`→`closed` — so every legacy value except `replied` maps to itself and a migrated enquiry does not lose the fact that a quote had already gone out, defaulting unrecognised or absent values to `new`
    - Create one enquiry note per subscriber note holding body and creation time
    - Skip contacts already in `meh_migrated_subscribers` or already carrying an enquiry with that subscriber id and migration source, recording a reason per skip
    - Report created and skipped counts, record `meh_migration_completed_at` and the migrated subscriber ledger
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7, 15.8, 15.9, 15.10, 15.11, 15.12_

  - [x] 13.2 Write property test for migration fidelity
    - **Property 35: Migration reproduces contacts faithfully without touching them**
    - **Validates: Requirements 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.10, 15.11, 15.12**

  - [x] 13.3 Write property test for migration idempotence and preview
    - **Property 36: Migration is idempotent and preview is read-only**
    - **Validates: Requirements 15.7, 15.8, 15.9**

- [x] 14. Checkpoint - closure, conversion and migration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 15. Rewrite the enquiry REST controller
  - [x] 15.1 Rewrite registration and the list route
    - Rewrite `includes/class-rest-enquiries.php` against the store, registering under `marthrown-enquiry-hub/v1` with `Auth::rest_permission` on every route and a `sanitize_callback` on every declared arg
    - Add the `guard_writable( $id )` helper returning 404 for unknown and 409 for closed enquiries
    - Implement `GET /enquiries` returning items, per-status counts and warnings, with `X-WP-Total` and `X-WP-TotalPages` headers
    - _Requirements: 12.1, 12.9, 12.10, 12.11, 12.14, 16.1, 16.2, 16.7, 17.5_

  - [x] 15.2 Write property test for pagination, ordering and counts
    - **Property 30: Pagination, ordering and counts are consistent**
    - **Validates: Requirements 12.9, 12.10, 12.11, 12.14**

  - [x] 15.3 Implement the single-enquiry route
    - `GET /enquiries/{id}` returning fields, Candidate Date Ranges, terms, notes, history, `crm_sync_state`, `booking_id`, the FluentCRM contact URL, `allowed_transitions` from `Lifecycle` and same-email siblings
    - Return 404 for an unknown identifier and 500 with `meh_enquiry_incomplete` naming the missing field when `email`, `status` or `created_at` is absent, with no partial representation
    - _Requirements: 5.3, 13.1, 13.2, 13.3, 13.4, 13.5_

  - [x] 15.4 Write property test for the single-enquiry view
    - **Property 31: The single-enquiry view is complete**
    - **Validates: Requirements 5.3, 13.2, 13.3, 13.6**

  - [x] 15.5 Write property test for incomplete stored rows
    - **Property 32: Incomplete rows fail loudly**
    - **Validates: Requirements 13.5**

  - [x] 15.6 Implement the write routes
    - `POST /enquiries/{id}/status`, `/notes`, `/duplicate`, `/convert` and `/retry-crm`, each calling `guard_writable()` first
    - Delegate to `Lifecycle`, `NoteService`, `EnquiryStore::duplicate()`, `BookingCreator` and `ContactLinker::retry()`
    - Record `duplicated` history on both enquiries and leave the source status, notes and history otherwise unchanged
    - _Requirements: 5.4, 7.5, 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7, 9.8, 9.9, 10.1, 14.1, 16.5_

  - [x] 15.7 Write property test for closed enquiry immutability
    - **Property 24: Closed enquiries are frozen but readable**
    - **Validates: Requirements 9.1, 9.2, 19.12**

  - [x] 15.8 Write property test for re-raising an enquiry
    - **Property 25: Re-raising copies forward and leaves the source intact**
    - **Validates: Requirements 9.4, 9.5, 9.6, 9.7, 9.8, 9.9**

  - [x] 15.9 Implement the rejection and administrative routes
    - `GET /enquiries/rejections` listing rejected intake attempts with reason and detail
    - `POST /enquiries/migration` supporting a `preview` flag, and `DELETE /enquiries/test-records`, both behind a composed permission callback adding `current_user_can( 'manage_options' )`
    - _Requirements: 4.7, 15.13, 16.6, 17.7_

  - [x] 15.10 Write property test for access control
    - **Property 37: Access control holds for every enquiry route**
    - **Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7, 16.9, 18.21, 18.22, 19.19**

  - [x] 15.11 Write unit tests for route registration
    - Assert the duplicate, note, migration and rejections routes exist with the expected methods
    - Assert the manual creation route is registered as `POST /enquiries` and the edit route as `PATCH /enquiries/{id}`, both with `Auth::rest_permission` as their permission callback
    - Assert the intake route is registered as `POST /intake` with the secret-authenticating permission callback rather than `Auth::rest_permission`
    - _Requirements: 4.8, 9.3, 10.1, 15.13, 16.9, 18.1, 18.21, 19.1, 19.19_

  - [x] 15.12 Implement the manual enquiry creation route
    - `POST /enquiries`, kept thin: read and sanitise the submitted fields, then one `EnquiryCreator::create()` call passing `Validator::PROFILE_MANUAL`, `'manual:' . get_current_user_id()`, the request time and the submitting user as the history actor
    - Answer 201 with the created enquiry representation, or 400 `meh_invalid_enquiry` carrying an `errors` map naming every failing field
    - Call `DuplicateDetector` nowhere and `record_rejection()` nowhere on this path, so a second identical creation inside the duplicate window is a legitimate second enquiry
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.8, 18.9, 18.10, 18.11, 18.12, 18.13, 18.14, 18.15, 18.21, 18.22_

  - [x] 15.13 Implement the enquiry edit route
    - `PATCH /enquiries/{id}` accepting a partial body of `first_name`, `last_name`, `email`, `phone`, `total_guests`, `message`, `date_ranges`, `event_type` and `site_exclusivity`, each declared arg carrying a `sanitize_callback`
    - Call `guard_writable()` before validation and before reading any stored value, so a closed enquiry is answered 409 whether the submitted body would have validated or not
    - Delegate to `EnquiryEditor::apply()`, answering 200 with the updated enquiry or 400 `meh_invalid_enquiry` carrying an `errors` map naming every failing field
    - Report an applied edit whose re-link failed as applied with a CRM warning rather than as a failure, the correction itself being stored
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.12, 19.19_

- [x] 16. Update the plugin bootstrap
  - [x] 16.1 Rework `marthrown-enquiry-hub.php`
    - Define `MEH_DB_VERSION`, require every new class file, and load the enquiry, intake, lifecycle and REST layers regardless of FluentCRM availability
    - Call `IntakeEndpoint::init()` to register the intake route, in place of any form-plugin intake hook binding
    - Call `Schema::maybe_upgrade()` on `plugins_loaded` and `Schema::install()` plus `AutoCloseJob::schedule()` on activation
    - Clear `meh_cron_auto_close` alongside the legacy hooks on deactivation, retaining all tables
    - Downgrade the missing-dependency admin notice to say contact linkage is disabled
    - Add no `uninstall.php`
    - _Requirements: 1.9, 1.10, 1.11, 1.14, 8.1, 8.2, 16.8, 16.9_

  - [x] 16.2 Write integration test for the soft dependency gate
    - Assert the enquiry layer registers its hooks and the intake route with FluentCRM absent, and that an authenticated intake webhook request still creates an enquiry
    - _Requirements: 5.2, 6.7, 16.8_

- [x] 17. Update the settings screen
  - [x] 17.1 Add the Intake and Migration sections
    - Add an Intake Secret control persisting to `meh_intake_secret` in `includes/class-settings.php`, rendered with an empty value and retaining the stored secret when submitted empty
    - Add a control recording which payload field holds the form identifier used to set `source`, persisting to `meh_intake_source_field`
    - Display, next to the secret control, the statement that an Intake Secret carried in the request URL is recorded in server access logs and is therefore weaker than one carried in a request header
    - Keep the nine field mapping controls persisting to `meh_field_map`
    - Add migration run and preview controls rendered only for `manage_options` users
    - Remove the Event Enquiry calendar selection control, leaving `meh_event_enquiry_calendar` in the database unread
    - _Requirements: 2.7, 2.8, 2.9, 14.13, 15.13, 16.13, 16.15_

  - [x] 17.2 Write unit tests for the settings screen
    - The nine mapping controls, the Intake Secret control and the form-identifier field control render and persist
    - The Intake Secret control renders with an empty value, and a submission holding an empty value retains the stored secret
    - The access-log warning for the query-parameter secret transport is displayed
    - The Event Enquiry calendar control is absent
    - Migration controls render for `manage_options` users only
    - _Requirements: 2.7, 2.8, 2.9, 14.13, 15.13, 16.13, 16.15_

  - [x] 17.3 Confirm the webhook secret transport on the live form
    - Check the Kadence version installed on the live site for whether its webhook Submit Action can send a custom request header, which the vendor documentation does not settle
    - Where it can, configure the Submit Action to send the Intake Secret in the `X-MEH-Intake-Secret` header and record that the header transport is in use
    - Where it cannot, configure the destination URL to carry the secret as the `meh_secret` query parameter and record that the weaker transport is in use, with the access-log consequence noted
    - _Requirements: 16.14, 16.15_

- [x] 18. Update the hub UI
  - [x] 18.1 Update `src/api.js`
    - Add the enquiry list, single, status, notes, duplicate, convert, retry-crm, rejections, migration and test-record calls, all sending `X-WP-Nonce`
    - Add the manual creation call posting to `POST /enquiries` and the correction call patching `PATCH /enquiries/{id}`, both sending `X-WP-Nonce` and both surfacing a 400 response's `errors` map to the caller
    - Remove the booking convert call
    - _Requirements: 12.1, 13.1, 14.1, 16.5, 18.1, 19.1_

  - [x] 18.2 Update `src/components/EnquiryManager.js`
    - Replace the status tabs with the six new statuses — `new`, `contacted`, `quoted`, `converted`, `lost`, `closed` — plus `all`, each with its count from the API payload, and cover the same six in the status badge colour map
    - Add a new-enquiry control to the toolbar that opens `EnquiryForm` empty in create mode
    - Render a visible test badge on rows whose `is_test` is true and open `EnquiryDetail` on row selection
    - _Requirements: 12.9, 17.4, 18.23_

  - [x] 18.3 Create `src/components/EnquiryDetail.js`
    - Render Candidate Date Ranges, multi-select values, message, notes, a collapsed history disclosure, `crm_sync_state` with a retry action, booking link and CRM link
    - Build action buttons solely from `allowed_transitions`, plus duplicate, convert and add-note controls
    - Add an edit control that opens `EnquiryForm` in edit mode pre-filled from the displayed enquiry's stored field values, Candidate Date Ranges and multi-select values, and render no edit control at all for an enquiry holding `closed`, so the 409 is a backstop against a stale view rather than the normal path to that message
    - _Requirements: 5.3, 9.1, 13.2, 13.3, 13.6, 19.12, 19.20_

  - [x] 18.4 Update `src/components/EnquiryFilters.js`
    - Add candidate-date range inputs and a hide-test toggle defaulting to including test enquiries
    - _Requirements: 12.7, 17.6_

  - [x] 18.5 Update `src/components/BookingsManager.js`
    - Remove the Event Enquiry calendar convert control and its handler
    - _Requirements: 14.12_

  - [x] 18.6 Write Jest component tests
    - `EnquiryManager` renders six status tabs plus `all` with counts, a test badge exactly when `is_test` is true, and the hide-test toggle defaulting to off
    - `EnquiryDetail` renders action buttons matching `allowed_transitions` and none beyond them, including the case where `quoted` is offered and the case of a `closed` enquiry where none is
    - `EnquiryManager` renders a new-enquiry control that opens `EnquiryForm` empty, and submitting that form posts to `POST /enquiries` with the entered values and without keys for the optional fields left blank
    - `EnquiryForm` in create mode marks `first_name`, `last_name`, `email` and the ideal Candidate Date Range required and the remaining five optional, and renders per-field messages from a 400 response's `errors` map against the fields it names
    - `EnquiryDetail` renders an edit control that opens `EnquiryForm` pre-filled with the displayed enquiry's stored values, and submitting it patches `/enquiries/{id}` with only the fields the user altered
    - `EnquiryDetail` renders no edit control for an enquiry holding `closed`
    - `BookingsManager` renders no convert control
    - _Requirements: 9.1, 13.6, 14.12, 17.4, 17.6, 18.23, 19.12, 19.20_

  - [x] 18.7 Create `src/components/EnquiryForm.js`
    - One component serving both write paths, create and edit mode differing only in submit target and initial values
    - Mark `first_name`, `last_name`, `email` and the ideal Candidate Date Range required, and `phone`, `total_guests`, `message`, `event_type` and `site_exclusivity` optional
    - In create mode omit an optional field from the request body when it is left blank, which is what the Manual Validation Profile expects, and `POST` to `/enquiries`
    - In edit mode pre-fill from the displayed enquiry's stored values and submit only the fields the user actually altered, keeping the request a genuine partial update, and `PATCH` to `/enquiries/{id}`
    - Render per-field errors from a 400 response's `errors` map against the fields it names, so a multi-field failure is reported in one pass
    - Render and submit none of `status`, `source`, `is_test`, `booking_id` or the payload snapshot
    - _Requirements: 18.23, 19.20_

- [x] 19. Final checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- The 43 correctness properties map one-to-one onto the property test sub-tasks; no property is split and no test covers two
- Every property test carries the `Feature: enquiry-data-layer, Property N: …` tag comment and runs at least 100 iterations
- Pure-logic property tests run without WordPress; store, intake, CRM and REST properties run against the wp-env database with the in-memory fakes
- Checkpoints sit after each layer so a failing property is caught before the next layer builds on it
- Within task 8 the endpoint (8.1) calls the handler (8.7), so the dependency graph schedules the handler first while the numbering keeps the endpoint at the head of the intake section
- Task 20 sits in the list between contact linkage and the checkpoint that follows it, where its dependencies put it, but carries the number 20 so that tasks 9 to 19 keep the numbers they already had. The dependency graph, not the numbering, is authoritative for execution order: `EnquiryCreator` (20.1) precedes the `IntakeHandler` refactor (20.2) and the manual creation route (15.12), `EnquiryStore::update()` (3.8) precedes `EnquiryEditor` (20.3), and `guard_writable()` (15.1) precedes it too
- Property 42 is tested in the contact linkage section (9.5) because it is `ContactLinker::relink()` behaviour, even though the edit path that triggers it is implemented in task 20

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["2.1", "5.1", "5.4", "5.6", "8.6"] },
    { "id": 2, "tasks": ["2.2", "2.3", "2.4", "3.1", "5.2", "5.3", "5.5", "5.7", "5.8", "6.1"] },
    { "id": 3, "tasks": ["3.2", "3.3", "3.4", "3.5", "3.6", "6.2", "6.3", "8.3"] },
    { "id": 4, "tasks": ["3.7", "3.8", "6.4", "6.5", "6.6", "8.4", "8.5", "8.7", "9.1", "11.1"] },
    { "id": 5, "tasks": ["6.7", "8.1", "9.2", "9.3", "9.4", "11.2", "11.3", "11.4", "11.5", "12.1", "13.1", "20.1"] },
    { "id": 6, "tasks": ["8.2", "8.8", "8.10", "8.11", "12.2", "12.5", "13.2", "13.3", "20.2"] },
    { "id": 7, "tasks": ["12.3", "12.4", "15.1"] },
    { "id": 8, "tasks": ["12.6", "15.2", "15.3", "20.3"] },
    { "id": 9, "tasks": ["15.4", "15.5", "15.6"] },
    { "id": 10, "tasks": ["15.8", "15.9"] },
    { "id": 11, "tasks": ["15.12"] },
    { "id": 12, "tasks": ["15.13"] },
    { "id": 13, "tasks": ["8.9", "9.5", "15.7", "15.10", "15.11", "16.1", "20.4", "20.5", "20.6", "20.7", "20.8", "20.9", "20.10"] },
    { "id": 14, "tasks": ["16.2", "17.1", "18.1"] },
    { "id": 15, "tasks": ["17.2", "18.4", "18.5", "18.7"] },
    { "id": 16, "tasks": ["18.2", "18.3"] },
    { "id": 17, "tasks": ["17.3", "18.6"] }
  ]
}
```
