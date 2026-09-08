# Requirements Document

## Introduction

The Marthrown Enquiry Hub currently treats FluentCRM as the source of record for event enquiries. A website form adds a contact to the "Event Enquiries" list with the "Event Enquiry" tag, and workflow state is stored on that contact as a subscriber custom field (`meh_enquiry_status`).

FluentCRM is contact-centric: one row per person. An enquiry is an event with its own lifecycle. When the same person enquires a second time, the workflow state on the contact is overwritten and the earlier enquiry is lost. There is no way to model a history of enquiries for one person.

This feature introduces a plugin-owned database layer that sits between the website enquiry form and FluentCRM. The Enquiry Store becomes the source of record for enquiries and holds a one-to-many relationship from contact to enquiries. FluentCRM is reduced to contact management concerns only: the contact record, list membership, tags, email and automation. No enquiry workflow state is stored in FluentCRM after this feature ships.

The feature covers schema ownership, intake from a webhook posted by the website enquiry form, contact linkage into FluentCRM, the enquiry management flow in the hub (list, view, status, notes, conversion to a booking), migration of enquiries already captured as FluentCRM contacts, and access control consistent with the existing `Auth` rules and the `marthrown-enquiry-hub/v1` REST namespace.

### Confirmed decisions

These points were confirmed with the product owner and are treated as fixed inputs:

- Lifecycle statuses are `new`, `contacted`, `quoted`, `converted`, `lost`, `closed`. The lifecycle is forward-only: no transition returns an Enquiry to a status it has already left.
- `quoted` is an active status, not a Settled Enquiry. An Enquiry holding `quoted` never auto-closes.
- An enquiry moves to `closed` automatically 7 days after entering `converted` or `lost`.
- A closed enquiry is immutable. Re-raising is done by duplicating the enquiry into a new record.
- Auto-closure is driven by a single daily WP-Cron event (cron is deliberately reintroduced for this purpose only).
- Intake arrives as an HTTP webhook POST sent by the enquiry form's Submit Actions, not through a form plugin's in-process submission hook, so the plugin depends on no form plugin's internal hook signature. The plugin exposes one public REST intake endpoint that the form posts to. Fields are `first_name`, `last_name`, `email`, `phone`, `total_guests`, `date_ranges`, `event_type`, `site_exclusivity`, `message`. All nine fields are required for a webhook submission.
- The originating form sends the webhook once and performs no retry. The form plugin's own stored entry is the recovery source when a webhook is not received or not processed.
- The intake endpoint is authenticated by a shared secret rather than by a WordPress user, because the form posts to it unauthenticated.
- `date_ranges` holds one to three Candidate Date Ranges per enquiry, as a ranked list: the first is the ideal range and is required, the second and third are optional alternatives. A single day is a range whose start and end are the same date. The order is rank, never chronology, so a reordered list is a different answer.
- `event_type` and `site_exclusivity` are multi-select fields.
- The existing WP Booking System "Event Enquiry calendar" provisional-hold flow is replaced. Conversion creates the booking directly from the enquiry record.
- Uninstalling the plugin retains enquiry data. Enquiry data is never dropped automatically.
- The management team can create an Enquiry by hand from the hub, for an enquiry arriving by telephone or direct email. Manual creation requires `first_name`, `last_name`, `email` and the ideal Candidate Date Range; `phone`, `total_guests`, `message`, `event_type` and `site_exclusivity` are optional. This is why the Validator has two required-field profiles.
- Duplicate detection and rate limiting apply to Intake Webhook Requests only. A manual creation request comes from an authenticated staff member rather than a public form, so neither guard applies to it. A repeat enquirer is spotted instead through the same-email sibling summary the single-enquiry route already returns (Requirement 13).
- Stored enquiry field values can be corrected after creation, for any Enquiry regardless of `source`. An edit never alters the Enquiry Payload Snapshot: that snapshot stays the verbatim record of what originally arrived and is the audit trail.

## Glossary

### Domain terms

- **Enquiry**: A single event enquiry submitted by one person at one point in time, with its own lifecycle, Candidate Date Ranges, notes and history. The unit of work for the management team.
- **Contact**: A person record held in FluentCRM, identified by email address. One Contact relates to zero or more Enquiries.
- **Candidate Date Range**: One span of dates a person has indicated as possible for the event described by an Enquiry, held as a start date and an end date at day precision; a single day is a range whose bounds are equal. An Enquiry holds one to three Candidate Date Ranges as a ranked list, the first being the ideal range and the rest alternatives in preference order.
- **Booking**: A WP Booking System booking record. Created by WP Booking System's own data layer, never duplicated into the Enquiry Store.
- **Conversion**: The act of creating a Booking from an Enquiry and recording the resulting booking identifier against that Enquiry.
- **Settled Enquiry**: An Enquiry whose status is `converted` or `lost`.
- **Closed Enquiry**: An Enquiry whose status is `closed`. Read-only.
- **Enquiry Hub**: The React single-page application served at the front-end route `/bookings`.
- **Test Record**: A record created while the plugin runs in staging mode, marked so staging data can be identified and removed before go-live.
- **Enquiry Payload Snapshot**: The verbatim field values captured when the Enquiry was created, retained for audit and reprocessing, and unaltered by any later edit.
- **Manual Enquiry**: An Enquiry created by an authenticated Enquiry Hub user through the manual enquiry creation route, rather than from an Intake Webhook Request.
- **Webhook Validation Profile**: The Validator required-field profile that treats all nine enquiry fields as required. Applied to a submission arriving as an Intake Webhook Request.
- **Manual Validation Profile**: The Validator required-field profile that treats `first_name`, `last_name`, `email` and `date_ranges` as required and treats `phone`, `total_guests`, `message`, `event_type` and `site_exclusivity` as optional. Applied to a submission arriving through the manual enquiry creation route or the enquiry edit route.
- **Intake Webhook Request**: The HTTP POST request the website enquiry form sends to the plugin when a visitor submits that form, carrying the submitted field values as its payload.
- **Intake Secret**: The shared secret value, stored in Settings, that the website enquiry form sends with each Intake Webhook Request and that authenticates that request.

### System names

- **Enquiry_Store**: The plugin-owned persistence layer for enquiries, Candidate Date Ranges, notes and history.
- **Schema_Manager**: The component that creates and upgrades the Enquiry Store database schema.
- **Intake_Endpoint**: The public REST route that receives an Intake Webhook Request, authenticates that request against the Intake Secret, and passes an authenticated request to the Intake_Handler.
- **Intake_Handler**: The component that creates an Enquiry from an authenticated Intake Webhook Request.
- **Validator**: The component that checks submitted field values against the required field rules of the applied required-field profile.
- **Duplicate_Detector**: The component that identifies repeat submissions of the same Enquiry.
- **Contact_Linker**: The component that upserts the Contact in FluentCRM and records the FluentCRM subscriber identifier against the Enquiry.
- **Lifecycle_Manager**: The component that applies and validates Enquiry status transitions.
- **Auto_Close_Job**: The daily scheduled task that closes Settled Enquiries after the closure interval elapses.
- **Note_Service**: The component that records internal staff notes against an Enquiry.
- **History_Recorder**: The component that records an append-only audit trail of Enquiry changes.
- **Enquiry_API**: The REST controller exposing enquiry routes under the `marthrown-enquiry-hub/v1` namespace.
- **Booking_Creator**: The component that creates a WP Booking System Booking from an Enquiry.
- **Migration_Runner**: The component that imports enquiries currently represented as FluentCRM Contacts into the Enquiry Store.
- **Auth**: The existing shared authorization component (`\MarthrownEnquiryHub\Auth`).
- **Settings**: The existing settings screen at wp-admin → Settings → Enquiry Hub.
- **Staging_Marker**: The component that applies test-record marking when the plugin runs in staging mode.

## Requirements

### Requirement 1: Enquiry Data Store and Schema Lifecycle

**User Story:** As the plugin owner, I want the plugin to own its enquiry tables, so that enquiries have a durable source of record independent of FluentCRM.

#### Acceptance Criteria

1. THE Enquiry_Store SHALL persist each Enquiry as one row holding an identifier that is a positive whole number assigned by the Enquiry_Store, unique across all Enquiries and never reused, together with `first_name`, `last_name`, `email`, `phone`, `total_guests`, `message`, `status`, `fluentcrm_subscriber_id`, `booking_id`, `crm_sync_state`, `created_at`, `updated_at`, `status_changed_at`, `source`, `is_test`, the identifier of the Enquiry this Enquiry was copied from, and the identifier of the Enquiry copied from this Enquiry.
2. THE Enquiry_Store SHALL persist each Candidate Date Range as a separate row related to exactly one Enquiry by that Enquiry's identifier, holding a start date and an end date at day precision and the rank position of that range within the Enquiry's list, and SHALL accept between 1 and 3 Candidate Date Range rows for a single Enquiry.
3. THE Enquiry_Store SHALL persist each selected `event_type` value and each selected `site_exclusivity` value as a separate row related to exactly one Enquiry by that Enquiry's identifier, each row holding one value of up to 100 characters, and SHALL accept between 0 and 20 rows per field for a single Enquiry.
4. THE Enquiry_Store SHALL apply no uniqueness constraint to `email` and SHALL permit at least 100 Enquiries to hold the same `email` value.
5. THE Enquiry_Store SHALL apply no uniqueness constraint to `fluentcrm_subscriber_id`, SHALL permit at least 100 Enquiries to hold the same `fluentcrm_subscriber_id` value, and SHALL permit `fluentcrm_subscriber_id` and `booking_id` to hold an empty value.
6. WHEN an Enquiry is written to the Enquiry_Store and then read back by identifier, THE Enquiry_Store SHALL return every stored field value character-for-character equal to the written value, SHALL return `created_at`, `updated_at` and `status_changed_at` equal to the written date-time to the nearest second in the site timezone, SHALL return the Candidate Date Ranges as a list equal to the written list in rank order, and SHALL return the `event_type` values and `site_exclusivity` values as sets equal to the written sets irrespective of row order (round-trip property).
7. THE Enquiry_Store SHALL store the Enquiry Payload Snapshot as a serialized text value against the Enquiry, in a column able to hold at least 65,535 characters.
8. WHEN an Enquiry Payload Snapshot is serialized and then deserialized, THE Enquiry_Store SHALL produce a structure holding the same keys, the same value for each key, and the same nesting depth as the structure that was serialized, with no key added and no key removed (round-trip property).
9. WHEN the plugin is activated, THE Schema_Manager SHALL create every Enquiry_Store table that is absent within 30 seconds, SHALL leave every table that is already present and all rows within it unchanged, and SHALL record the current schema version.
10. WHEN the stored schema version is absent or equal to 0, THE Schema_Manager SHALL treat the schema as not yet installed, SHALL create every Enquiry_Store table that is absent, and SHALL record the current schema version.
11. WHEN the plugin loads and the stored schema version is lower than the current schema version, THE Schema_Manager SHALL apply the schema changes defined for each version between the stored version exclusive and the current version inclusive in ascending version order, and SHALL record the current schema version only after every one of those changes succeeds.
12. WHEN the Schema_Manager applies schema changes, THE Schema_Manager SHALL retain all existing Enquiry rows, Candidate Date Range rows, note rows and history rows, with every field value in those rows unchanged except where a change explicitly transforms that field.
13. THE Enquiry_Store SHALL apply the WordPress table prefix followed by the `meh_` component prefix to every table name, and SHALL keep every resulting table name within 64 characters.
14. WHEN the plugin is uninstalled, THE Schema_Manager SHALL retain every Enquiry_Store table, all rows within those tables, and the stored schema version.
15. THE Enquiry_Store SHALL index `email`, `status`, `created_at`, `fluentcrm_subscriber_id` and `is_test` on the Enquiry table and the start date and the end date on the Candidate Date Range table, to support the list queries defined in Requirement 12.
16. IF creating or altering an Enquiry_Store table fails, THEN THE Schema_Manager SHALL leave the stored schema version unchanged, SHALL retain all existing rows in every Enquiry_Store table, and SHALL record an error indicating which table failed and the failure reason.
17. IF the stored schema version equals the current schema version when the plugin loads, THEN THE Schema_Manager SHALL apply no schema change and SHALL execute no table creation or table alteration statement (idempotence property).
18. THE Enquiry_Store SHALL store at least 100 characters for `first_name`, at least 100 characters for `last_name`, at least 254 characters for `email`, at least 32 characters for `phone`, at least 5000 characters for `message`, and whole numbers from 1 to 10000 for `total_guests`.
19. THE Enquiry_Store SHALL permit `phone`, `total_guests` and `message` to hold an empty value, and SHALL return that empty value when the Enquiry is read back by identifier.

### Requirement 2: Enquiry Intake from the Website Form

**User Story:** As a member of the management team, I want every website enquiry submission to create an enquiry record, so that no enquiry depends on FluentCRM to exist.

#### Acceptance Criteria

1. WHEN the Intake_Endpoint receives an Intake Webhook Request that authenticates against the Intake Secret, THE Intake_Handler SHALL create one Enquiry in the Enquiry_Store with status `new`.
2. WHEN the Intake_Handler creates an Enquiry, THE Intake_Handler SHALL set `created_at` and `updated_at` to the time the Intake_Endpoint received the Intake Webhook Request, expressed in the site timezone.
3. WHEN the Intake_Handler creates an Enquiry, THE Intake_Handler SHALL set `source` to a value holding the form identifier carried in the Intake Webhook Request payload.
4. IF the Intake Webhook Request payload carries no form identifier, THEN THE Intake_Handler SHALL set `source` to the fixed value `webhook:unidentified`.
5. WHEN the Intake_Handler creates an Enquiry, THE Intake_Handler SHALL store one Candidate Date Range row for each range present in the submitted `date_ranges` value, holding that range's position in the submitted list as its rank.
6. WHEN the Intake_Handler creates an Enquiry, THE Intake_Handler SHALL store the Enquiry Payload Snapshot containing every field value present in the Intake Webhook Request payload.
7. THE Settings SHALL provide a mapping control for each of the nine enquiry fields that records which Intake Webhook Request payload field supplies that enquiry field, and SHALL supply `date_ranges` through three start/end control pairs — one per rank position — because a sending form carries each bound of a range as a payload field of its own.
8. THE Settings SHALL provide a control that records the Intake Secret.
9. THE Settings SHALL provide a control that records which Intake Webhook Request payload field holds the form identifier used to set `source`.
10. WHERE a mapping control is unset for a given enquiry field, THE Intake_Handler SHALL resolve that enquiry field by matching the payload field label against the enquiry field name, case-insensitively.
11. IF label matching resolves no submitted value for a required enquiry field, THEN THE Intake_Handler SHALL treat the Intake Webhook Request as a validation failure under Requirement 3 and SHALL create no Enquiry.
12. THE Intake_Endpoint SHALL create Enquiries from Intake Webhook Requests sent by any form plugin, using the mapping controls and label matching defined in this requirement and requiring no configuration specific to the sending form plugin.
13. WHEN the Intake_Handler creates an Enquiry, THE History_Recorder SHALL record a history entry of type `created`.
14. WHERE both payload fields named by a start/end control pair resolve to parseable dates and the end date is no earlier than the start date, THE Intake_Handler SHALL read one Candidate Date Range from that pair at that pair's rank position; and WHERE either payload field is absent, holds no parseable date, or names an end date earlier than the start date, THE Intake_Handler SHALL read no range from that pair.

### Requirement 3: Intake Validation

**User Story:** As a member of the management team, I want submitted enquiry data validated before storage, so that the enquiry list holds usable, well-formed information.

#### Acceptance Criteria

1. THE Validator SHALL apply exactly one required-field profile to each submission, either the Webhook Validation Profile or the Manual Validation Profile, where a field is present only when the submission contains that field key.
2. IF a field required by the applied required-field profile is absent, or holds a value that is empty after removal of leading and trailing whitespace, or holds an empty collection, THEN THE Validator SHALL return a validation failure naming that field and SHALL name every other field that also fails validation in the same result.
3. IF the submitted `email` value fails WordPress email validation, THEN THE Validator SHALL return a validation failure naming the `email` field.
4. IF the submitted `total_guests` value is not a whole number between 1 and 10000 inclusive, THEN THE Validator SHALL return a validation failure naming the `total_guests` field.
5. IF the submitted `date_ranges` value contains fewer than 1 or more than 3 ranges, THEN THE Validator SHALL return a validation failure naming the `date_ranges` field.
6. IF a range in the submitted `date_ranges` value omits either bound, holds a start date or an end date that does not parse as a calendar date, or holds an end date earlier than its start date, THEN THE Validator SHALL return a validation failure naming the `date_ranges` field.
7. WHEN the Validator accepts a submission, THE Validator SHALL truncate `first_name` and `last_name` to 100 characters, `email` to 254 characters, `phone` to 32 characters, and `message` to 5000 characters, counting characters after HTML tag removal and whitespace trimming, and SHALL apply truncation after all other criteria in this requirement have been evaluated so that no field length causes a validation failure.
8. WHEN the Validator returns a validation failure, THE Intake_Handler SHALL record the submission as a rejected intake attempt holding the Enquiry Payload Snapshot and the failure reasons for every failing field, and SHALL create no Enquiry.
9. WHEN the Intake_Handler stores field values, THE Intake_Handler SHALL strip HTML tags from `first_name`, `last_name`, `phone` and `message`.
10. WHEN the Enquiry_Store executes a query containing submitted values, THE Enquiry_Store SHALL bind those values as query parameters.
11. IF the submitted `phone` value contains no digits, THEN THE Validator SHALL return a validation failure naming the `phone` field.
12. IF the submitted `event_type` or `site_exclusivity` value is not one of the values permitted for that field, THEN THE Validator SHALL return a validation failure naming that field.
13. IF the Enquiry_Store fails to persist an Enquiry after the Validator accepted the submission, THEN THE Intake_Handler SHALL create no Enquiry, SHALL leave stored enquiry data unchanged, and SHALL return a failure result indicating that storage did not complete.
14. WHERE the submission arrived as an Intake Webhook Request, THE Validator SHALL apply the Webhook Validation Profile and SHALL treat `first_name`, `last_name`, `email`, `phone`, `total_guests`, `date_ranges`, `event_type`, `site_exclusivity` and `message` as required fields.
15. WHERE the submission arrived through the manual enquiry creation route defined in Requirement 18 or the enquiry edit route defined in Requirement 19, THE Validator SHALL apply the Manual Validation Profile and SHALL treat `first_name`, `last_name`, `email` and `date_ranges` as required fields.
16. WHERE the Manual Validation Profile is applied and `phone`, `total_guests`, `message`, `event_type` or `site_exclusivity` is absent, or holds a value that is empty after removal of leading and trailing whitespace, or holds an empty collection, THE Validator SHALL return a result holding no validation failure naming that field.
17. WHERE a field named by criterion 3, 4, 6, 11 or 12 of this requirement is present and holds a value that is not empty after removal of leading and trailing whitespace, THE Validator SHALL evaluate the criterion naming that field against the submitted value under both the Webhook Validation Profile and the Manual Validation Profile.
18. WHEN the Validator accepts a submission under either required-field profile, THE Validator SHALL apply the HTML tag removal defined in criterion 9 and the truncation defined in criterion 7 of this requirement to every accepted value.

### Requirement 4: Duplicate and Repeated Submission Handling

**User Story:** As a member of the management team, I want accidental resubmissions and repeated submissions kept out of my enquiry list, so that the list reflects genuine enquiries.

#### Acceptance Criteria

1. WHEN the Intake_Endpoint receives an Intake Webhook Request that authenticates against the Intake Secret, THE Intake_Endpoint SHALL treat that request as a submission the originating form has already accepted, and SHALL record any resulting rejected intake attempt with reason `duplicate`, `rate_limited`, `validation` or `storage`.
2. WHEN a submission holds the same `email` value and the same set of Candidate Date Ranges as an existing Enquiry created within the preceding 15 minutes, THE Duplicate_Detector SHALL report that submission as a duplicate.
3. WHEN the Duplicate_Detector reports a submission as a duplicate, THE Intake_Handler SHALL create no Enquiry and SHALL record a rejected intake attempt with reason `duplicate` referencing the existing Enquiry identifier.
4. THE Duplicate_Detector SHALL expose the duplicate detection window as a filterable value with a default of 900 seconds.
5. WHEN a submission holds the same `email` value as an existing Enquiry created more than 15 minutes earlier and the rate limit defined in criterion 6 is not exceeded, THE Intake_Handler SHALL create a new Enquiry.
6. IF the Intake_Endpoint has received 6 or more Intake Webhook Requests holding the same submitted `email` value within the preceding 900 seconds, THEN THE Intake_Handler SHALL create no Enquiry for the sixth and each subsequent request holding that `email` value within that period and SHALL record a rejected intake attempt with reason `rate_limited`.
7. THE Duplicate_Detector SHALL expose the rate limit as a filterable value with a default of 6 Intake Webhook Requests per submitted `email` value per 900 seconds.
8. THE Enquiry_API SHALL expose rejected intake attempts to users authorized by Auth, so that a genuine enquiry rejected with reason `duplicate`, `rate_limited` or `validation` can be found.

### Requirement 5: Intake Resilience

**User Story:** As a member of the management team, I want an enquiry captured even when FluentCRM or a downstream step fails, so that a technical fault never loses business.

#### Acceptance Criteria

1. THE Intake_Handler SHALL create the Enquiry in the Enquiry_Store before invoking the Contact_Linker.
2. IF the Contact_Linker returns a failure, THEN THE Intake_Handler SHALL retain the created Enquiry and SHALL set the Enquiry field `crm_sync_state` to `pending`.
3. THE Enquiry_API SHALL include the `crm_sync_state` value in every Enquiry representation, for both the `pending` and `synced` states.
4. THE Enquiry_API SHALL expose a route that retries contact linkage for an Enquiry whose `crm_sync_state` is `pending`.
5. WHEN contact linkage succeeds for an Enquiry, THE Contact_Linker SHALL set `crm_sync_state` to `synced` and SHALL record the FluentCRM subscriber identifier.
6. IF writing the Enquiry to the Enquiry_Store fails, THEN THE Intake_Handler SHALL write the Enquiry Payload Snapshot and the failure reason to the WordPress error log, SHALL skip the Contact_Linker, and SHALL set no `crm_sync_state` value.
7. IF an unhandled error occurs while the Intake_Endpoint processes an Intake Webhook Request, THEN THE Intake_Endpoint SHALL catch that error, SHALL write the Enquiry Payload Snapshot and the error message to the WordPress error log, SHALL return an error response with HTTP status 500, and SHALL leave no Enquiry row, Candidate Date Range row, `event_type` row, `site_exclusivity` row, note row or history entry from that request in the Enquiry_Store.
8. WHEN the Intake_Endpoint finishes processing an Intake Webhook Request that authenticated against the Intake Secret and no Enquiry was created, THE Intake_Endpoint SHALL record the received payload, the failure reason and the receipt time as a rejected intake attempt exposed by the route defined in Requirement 4, so that the Enquiry can be recreated from the originating form's own stored entry after a webhook the originating form does not retry.

### Requirement 6: Contact Linkage with FluentCRM

**User Story:** As a marketing user, I want each enquiry linked to a single FluentCRM contact, so that email and automation continue to work while enquiry workflow stays out of the CRM.

#### Acceptance Criteria

1. WHEN an Enquiry is created, THE Contact_Linker SHALL upsert a FluentCRM Contact matched on the Enquiry `email` value.
2. WHEN a FluentCRM Contact already exists for the Enquiry `email` value, THE Contact_Linker SHALL reuse that Contact and SHALL create no additional Contact.
3. WHEN the Contact_Linker upserts a Contact, THE Contact_Linker SHALL write `first_name`, `last_name`, `email` and `phone` to that Contact.
4. WHEN the Contact_Linker upserts a Contact, THE Contact_Linker SHALL add that Contact to the FluentCRM list configured in Settings and SHALL apply the FluentCRM tag configured in Settings.
5. WHEN the Contact_Linker completes an upsert, THE Contact_Linker SHALL record the FluentCRM subscriber identifier in the Enquiry field `fluentcrm_subscriber_id`.
6. IF an upsert returns no FluentCRM subscriber identifier, THEN THE Contact_Linker SHALL treat the upsert as a failure and SHALL leave `crm_sync_state` set to `pending`.
7. IF FluentCRM is inactive or its subscriber API is unavailable, THEN THE Contact_Linker SHALL return a failure and SHALL leave `fluentcrm_subscriber_id` empty.
8. THE Contact_Linker SHALL write no enquiry status value to FluentCRM.
9. THE Contact_Linker SHALL write no Candidate Date Range, `event_type`, `site_exclusivity` or `message` value to FluentCRM subscriber custom fields.
10. WHERE the Staging_Marker reports staging mode, THE Contact_Linker SHALL prefix the Contact `first_name` value with the configured test prefix and SHALL apply the `test-record` tag.
11. WHERE the Staging_Marker reports production mode, THE Contact_Linker SHALL write the Contact `first_name` value without a test prefix and SHALL apply no `test-record` tag.

### Requirement 7: Enquiry Lifecycle Status Transitions

**User Story:** As a member of the management team, I want to move an enquiry through a defined lifecycle, so that the team can see where each enquiry stands.

#### Acceptance Criteria

1. THE Lifecycle_Manager SHALL recognise exactly the statuses `new`, `contacted`, `quoted`, `converted`, `lost` and `closed`.
2. THE Lifecycle_Manager SHALL permit a transition from `new` to `contacted`, `quoted`, `converted` or `lost`.
3. THE Lifecycle_Manager SHALL permit a transition from `contacted` to `quoted`, `converted` or `lost`.
4. THE Lifecycle_Manager SHALL permit a transition from `converted` to `closed` and from `lost` to `closed`.
5. IF a requested transition is absent from the permitted transitions, THEN THE Lifecycle_Manager SHALL reject the request with an error identifying the current status and the requested status.
6. WHEN the Lifecycle_Manager applies a transition, THE Lifecycle_Manager SHALL set `status` to the requested status and SHALL set `status_changed_at` to the transition time.
7. WHEN the Lifecycle_Manager applies a transition, THE History_Recorder SHALL record a history entry holding the previous status, the new status, the acting user identifier and the transition time.
8. WHEN the Lifecycle_Manager applies a transition, THE Lifecycle_Manager SHALL fire an action hook carrying the Enquiry identifier, the previous status and the new status.
9. WHEN the Lifecycle_Manager applies the same transition request twice to an Enquiry already holding the requested status, THE Lifecycle_Manager SHALL leave `status` and `status_changed_at` unchanged and SHALL record no additional history entry (idempotence property).
10. THE Lifecycle_Manager SHALL permit a transition from `quoted` to `converted` or `lost`.
11. IF a transition is requested for an Enquiry holding status `closed`, THEN THE Lifecycle_Manager SHALL reject the request with an error identifying the current status and the requested status.
12. THE Lifecycle_Manager SHALL treat an Enquiry holding status `new`, `contacted` or `quoted` as an Enquiry that is not a Settled Enquiry, so that the Auto_Close_Job defined in Requirement 8 leaves that Enquiry status unchanged.

### Requirement 8: Automatic Closure of Settled Enquiries

**User Story:** As a member of the management team, I want settled enquiries to close themselves after a week, so that the active list only holds enquiries needing attention.

#### Acceptance Criteria

1. WHEN the plugin is activated, THE Auto_Close_Job SHALL register a single daily scheduled event.
2. WHEN the plugin is deactivated, THE Auto_Close_Job SHALL clear the daily scheduled event.
3. WHEN the daily scheduled event runs, THE Auto_Close_Job SHALL transition every Settled Enquiry whose `status_changed_at` is more than 7 days before the run time to status `closed`.
4. THE Auto_Close_Job SHALL be the only component that closes an Enquiry on the basis of the elapsed closure interval.
5. WHEN the Auto_Close_Job closes an Enquiry, THE History_Recorder SHALL record a history entry of type `auto_closed` attributing the change to the system rather than a user.
6. WHILE a Settled Enquiry has held its status for 7 days or less, THE Auto_Close_Job SHALL leave that Enquiry status unchanged.
7. WHEN a Settled Enquiry has held its status for exactly 7 days at the run time, THE Auto_Close_Job SHALL leave that Enquiry status unchanged.
8. WHEN the daily scheduled event runs and no Settled Enquiry exceeds the closure interval, THE Auto_Close_Job SHALL leave every Enquiry status unchanged.
9. WHEN the daily scheduled event runs twice without an intervening status change, THE Auto_Close_Job SHALL produce the same set of Enquiry statuses as a single run (idempotence property).
10. THE Auto_Close_Job SHALL expose the closure interval as a filterable value with a default of 7 days.

### Requirement 9: Closed Enquiry Immutability and Re-raising

**User Story:** As a member of the management team, I want closed enquiries frozen and re-raisable as a copy, so that history stays intact when a past enquirer comes back.

#### Acceptance Criteria

1. WHILE an Enquiry holds status `closed`, THE Enquiry_API SHALL reject requests to change that Enquiry's status, notes, linked booking, or field values through the enquiry edit route defined in Requirement 19, with HTTP status 409.
2. WHILE an Enquiry holds status `closed`, THE Enquiry_API SHALL return that Enquiry's stored values, notes and history for reading.
3. THE Enquiry_API SHALL expose a route that creates a new Enquiry from an existing Enquiry.
4. WHEN a new Enquiry is created from an existing Enquiry, THE Enquiry_Store SHALL copy `first_name`, `last_name`, `email`, `phone`, `total_guests`, `message`, the Candidate Date Ranges, the `event_type` values and the `site_exclusivity` values from the source Enquiry.
5. WHEN a new Enquiry is created from an existing Enquiry, THE Enquiry_Store SHALL set the new Enquiry status to `new`, SHALL set `booking_id` to empty, and SHALL set `created_at` to the copy time.
6. WHEN every copy step for a new Enquiry created from an existing Enquiry succeeds, THE Enquiry_Store SHALL record the source Enquiry identifier against the new Enquiry and the new Enquiry identifier against the source Enquiry.
7. IF any copy step fails while creating a new Enquiry from an existing Enquiry, THEN THE Enquiry_Store SHALL discard the partially created Enquiry and SHALL record no relationship against the source Enquiry.
8. WHEN a new Enquiry is created from an existing Enquiry, THE Contact_Linker SHALL reuse the FluentCRM subscriber identifier held by the source Enquiry when that identifier is present.
9. WHEN a new Enquiry is created from an existing Enquiry, THE Enquiry_Store SHALL leave the source Enquiry status, notes and history unchanged.

### Requirement 10: Internal Notes

**User Story:** As a member of the management team, I want to record internal notes against an enquiry, so that colleagues can see what has been discussed.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose a route that adds a note to an Enquiry.
2. WHEN a note is added, THE Note_Service SHALL store the note body, the authoring user identifier and the creation time against the Enquiry identifier.
3. THE Note_Service SHALL store two or more notes against a single Enquiry.
4. IF a submitted note body holds no characters other than whitespace after HTML tags are stripped, THEN THE Note_Service SHALL reject the request with HTTP status 400.
5. IF a submitted note body exceeds 5000 characters, THEN THE Note_Service SHALL reject the request with HTTP status 400 and a message stating the 5000 character limit.
6. WHEN a note is added, THE History_Recorder SHALL record a history entry of type `note_added`.
7. THE Enquiry_API SHALL return notes for an Enquiry ordered by creation time, most recent first.

### Requirement 11: Enquiry Audit History

**User Story:** As a manager, I want an audit trail on each enquiry, so that I can see what changed, when and by whom.

#### Acceptance Criteria

1. THE History_Recorder SHALL store each history entry with the Enquiry identifier, an entry type, an acting user identifier, a creation time, and a description of the change.
2. THE History_Recorder SHALL append history entries without modifying or removing existing history entries.
3. THE History_Recorder SHALL record entries of type `created`, `status_changed`, `auto_closed`, `note_added`, `crm_linked`, `booking_linked`, `duplicated` and `fields_edited`.
4. WHEN an Enquiry is read through the Enquiry_API single-enquiry route, THE Enquiry_API SHALL return that Enquiry's history entries ordered by creation time, oldest first.
5. THE History_Recorder SHALL attribute an entry to the system when no WordPress user is authenticated at the time of the change.

### Requirement 12: Enquiry List, Search and Filter

**User Story:** As a member of the management team, I want to list, search and filter enquiries in the hub, so that I can find the enquiries needing attention.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose a route returning a paginated list of Enquiries read from the Enquiry_Store.
2. WHEN a `status` parameter matching a recognised status is supplied, THE Enquiry_API SHALL return only Enquiries holding that status.
3. WHEN a `status` parameter equal to `all` is supplied, THE Enquiry_API SHALL return Enquiries of every status.
4. WHEN a search term is supplied, THE Enquiry_API SHALL return only Enquiries whose `first_name`, `last_name`, `email`, `phone` or `message` contains that term, matched case-insensitively.
5. WHEN a `from` date is supplied, THE Enquiry_API SHALL return only Enquiries whose `created_at` date is that date or later.
6. WHEN a `to` date is supplied, THE Enquiry_API SHALL return only Enquiries whose `created_at` date is that date or earlier.
7. WHEN both a `date_from` and a `date_to` parameter are supplied, THE Enquiry_API SHALL return only Enquiries holding at least one Candidate Date Range that overlaps that range inclusive.
8. IF only one of `date_from` and `date_to` is supplied, THEN THE Enquiry_API SHALL apply no Candidate Date Range filter and SHALL return a warning naming the missing parameter.
9. THE Enquiry_API SHALL return a count of Enquiries per status alongside the list.
10. THE Enquiry_API SHALL accept a `per_page` parameter, SHALL default `per_page` to 25, and SHALL cap `per_page` at 200.
11. THE Enquiry_API SHALL return the total matching Enquiry count and total page count as response headers.
12. WHEN two or more filter parameters are supplied, THE Enquiry_API SHALL return only Enquiries satisfying every supplied filter.
13. WHEN two requests supply filter parameter sets that hold the same parameter names and the same parameter values in any order, THE Enquiry_API SHALL return the same set of Enquiries in the same order for both requests (confluence property).
14. THE Enquiry_API SHALL return Enquiries ordered by `created_at`, most recent first, when no sort parameter is supplied.
15. WHEN a `hide_test` parameter is supplied with a true value, THE Enquiry_API SHALL exclude Enquiries whose `is_test` value is true.

### Requirement 13: Single Enquiry View

**User Story:** As a member of the management team, I want to open one enquiry and see everything about it, so that I can respond with full context.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose a route returning a single Enquiry by identifier.
2. WHEN a single Enquiry is returned, THE Enquiry_API SHALL include the stored field values, every Candidate Date Range in rank order, every `event_type` value, every `site_exclusivity` value, the notes, the history entries, the `crm_sync_state`, the linked booking identifier and the FluentCRM contact URL.
3. WHEN a single Enquiry is returned and the Enquiry `email` matches other Enquiries, THE Enquiry_API SHALL include a summary of those other Enquiries holding identifier, `created_at` and `status`.
4. IF the requested Enquiry identifier matches no Enquiry, THEN THE Enquiry_API SHALL return HTTP status 404.
5. IF a stored Enquiry is missing a value for `email`, `status` or `created_at`, THEN THE Enquiry_API SHALL return HTTP status 500 with an error identifying the missing field and SHALL return no partial Enquiry representation.
6. THE Enquiry Hub SHALL display the permitted status transitions for the displayed Enquiry as determined by the Lifecycle_Manager.

### Requirement 14: Conversion to a WP Booking System Booking

**User Story:** As a member of the management team, I want to convert an enquiry into a WP Booking System booking, so that a won enquiry becomes a real booking with the dates blocked out.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose a route that creates a Booking from an Enquiry, accepting a target calendar identifier, a booking start date and an optional booking end date, and SHALL treat an omitted end date as equal to the start date.
2. WHEN a Booking is created from an Enquiry, THE Booking_Creator SHALL set the Booking start date and end date to the submitted start date and end date, whether or not those dates fall within a Candidate Date Range of that Enquiry.
3. WHEN a Booking is created from an Enquiry, THE Booking_Creator SHALL populate the Booking guest name and guest email from the Enquiry `first_name`, `last_name` and `email` values.
4. WHEN a Booking is created from an Enquiry, THE Booking_Creator SHALL block every day of the Booking date range, from the start date to the end date inclusive, on the target calendar using that calendar's booked legend item.
5. WHEN a Booking is created from an Enquiry, THE Enquiry_Store SHALL record the resulting booking identifier in the Enquiry field `booking_id`.
6. WHEN a Booking is created from an Enquiry, THE Lifecycle_Manager SHALL transition that Enquiry to status `converted`.
7. WHEN a Booking is created from an Enquiry, THE History_Recorder SHALL record a history entry of type `booking_linked` holding the booking identifier and the Booking start and end dates.
8. IF the target calendar identifier matches no WP Booking System calendar, THEN THE Booking_Creator SHALL return HTTP status 400 and SHALL create no Booking.
9. IF WP Booking System is inactive, THEN THE Booking_Creator SHALL return HTTP status 503 and SHALL leave the Enquiry status unchanged.
10. IF the Enquiry already holds a `booking_id` value, THEN THE Booking_Creator SHALL return HTTP status 409 and SHALL create no additional Booking.
11. THE Booking_Creator SHALL create the Booking without triggering WP Booking System emails, payments, pricing or inventory side effects.
12. THE Enquiry Hub SHALL remove the Event Enquiry calendar convert control from the bookings list view.
13. THE Settings SHALL remove the Event Enquiry calendar selection control.
14. IF the submitted booking start date or end date does not parse as a calendar date, THEN THE Booking_Creator SHALL return HTTP status 400 and SHALL create no Booking.
15. IF the submitted booking end date falls before the submitted booking start date, THEN THE Booking_Creator SHALL return HTTP status 400 and SHALL create no Booking.
16. THE Enquiry Hub SHALL offer the displayed Enquiry's Candidate Date Ranges as the booking date choices, SHALL set the booking start and end dates to the bounds of the chosen range, SHALL permit either bound to be edited and a range outside every Candidate Date Range to be entered, and SHALL refuse to submit a range whose end date falls before its start date.
17. THE Enquiry Hub SHALL offer only the WP Booking System calendars whose names correspond to the displayed Enquiry's `site_exclusivity` values, and SHALL offer every calendar known to the plugin WHERE no calendar name corresponds to any of those values.

### Requirement 15: Migration of Existing FluentCRM Enquiries

**User Story:** As the plugin owner, I want enquiries already held as FluentCRM contacts imported into the Enquiry Store, so that nothing captured before this change is lost.

#### Acceptance Criteria

1. THE Migration_Runner SHALL read FluentCRM Contacts belonging to the configured enquiry list or holding the configured enquiry tag.
2. WHEN the Migration_Runner processes a Contact, THE Migration_Runner SHALL create one Enquiry holding that Contact's `first_name`, `last_name`, `email`, `phone` and creation time.
3. WHEN the Migration_Runner processes a Contact, THE Migration_Runner SHALL record that Contact's FluentCRM subscriber identifier in the created Enquiry field `fluentcrm_subscriber_id`.
4. WHEN a Contact holds the subscriber custom field `meh_enquiry_status`, THE Migration_Runner SHALL map value `new` to `new`, `replied` to `contacted`, `quoted` to `quoted`, `converted` to `converted`, and `closed` to `closed`.
5. IF a Contact holds no recognised `meh_enquiry_status` value, THEN THE Migration_Runner SHALL set the created Enquiry status to `new`.
6. WHEN the Migration_Runner processes a Contact holding FluentCRM subscriber notes, THE Migration_Runner SHALL create one Enquiry note per subscriber note holding that note's body and creation time.
7. WHEN the Migration_Runner runs a second time, THE Migration_Runner SHALL create no additional Enquiry for a Contact already migrated (idempotence property).
8. THE Migration_Runner SHALL support a preview mode that reports the Enquiry count that would be created and creates no Enquiry.
9. WHEN preview mode finds no Contact to migrate, THE Migration_Runner SHALL report a count of 0.
10. WHEN a migration run completes, THE Migration_Runner SHALL report the count of Enquiries created, the count of Contacts skipped and the reasons for skipping.
11. THE Migration_Runner SHALL leave every FluentCRM Contact record, list membership and tag unchanged.
12. WHEN a migration run completes, THE Migration_Runner SHALL record the completion time and the migrated subscriber identifiers so a later run can identify migrated Contacts.
13. THE Settings SHALL provide a control that starts a migration run and a control that starts a preview run, available to users holding the `manage_options` capability.

### Requirement 16: Access Control and REST API Surface

**User Story:** As a site administrator, I want enquiry routes protected by the same rules as the rest of the hub, so that enquiry data stays with the team that should see it.

#### Acceptance Criteria

1. THE Enquiry_API SHALL register every enquiry route under the `marthrown-enquiry-hub/v1` namespace.
2. THE Enquiry_API SHALL use `Auth::rest_permission` as the permission callback for every enquiry route.
3. IF a request to an enquiry route carries no authenticated WordPress user, THEN THE Enquiry_API SHALL return HTTP status 401 without evaluating role membership.
4. IF a request to an enquiry route carries an authenticated user who holds no role present in the roles returned by `Auth::allowed_roles`, THEN THE Enquiry_API SHALL return HTTP status 403, including when that user holds no role at all.
5. THE Enquiry_API SHALL require a valid WordPress REST nonce in the `X-WP-Nonce` header for every request that changes stored data.
6. THE Enquiry_API SHALL require the `manage_options` capability for the migration routes defined in Requirement 15.
7. THE Enquiry_API SHALL sanitize every request parameter before use.
8. THE Intake_Endpoint SHALL create Enquiries from Intake Webhook Requests that carry no authenticated WordPress user.
9. THE Intake_Endpoint SHALL register its route under the `marthrown-enquiry-hub/v1` namespace with a permission callback that authenticates the Intake Secret, and SHALL be the only route in that namespace that uses a permission callback other than `Auth::rest_permission`.
10. WHEN the Intake_Endpoint receives an Intake Webhook Request, THE Intake_Endpoint SHALL compare the secret carried by that request against the Intake Secret held in Settings using a comparison whose execution time is independent of the position of the first differing character.
11. IF an Intake Webhook Request carries no secret, or carries a secret that differs from the Intake Secret held in Settings, THEN THE Intake_Endpoint SHALL return HTTP status 401, SHALL create no Enquiry, and SHALL record no rejected intake attempt.
12. THE Enquiry_API SHALL omit the Intake Secret value from the response body and the response headers of every route in the `marthrown-enquiry-hub/v1` namespace.
13. THE Settings SHALL render the Intake Secret control with an empty value, and SHALL retain the stored Intake Secret when that control is submitted holding an empty value.
14. WHERE the originating form's webhook action supports custom request headers, THE Intake_Endpoint SHALL accept the Intake Secret from a request header.
15. WHERE the originating form's webhook action supports no custom request header, THE Intake_Endpoint SHALL accept the Intake Secret from a request query parameter, and THE Settings SHALL display a statement that an Intake Secret carried in the request URL is recorded in server access logs and is therefore weaker than an Intake Secret carried in a request header.

### Requirement 17: Staging Test Record Marking

**User Story:** As the plugin owner, I want staging enquiries distinguishable from live enquiries, so that test data can be removed before go-live.

#### Acceptance Criteria

1. WHERE the Staging_Marker reports staging mode, THE Intake_Handler SHALL set the created Enquiry field `is_test` to true.
2. WHERE the Staging_Marker reports production mode, THE Intake_Handler SHALL set the created Enquiry field `is_test` to false.
3. WHERE the Staging_Marker reports staging mode, THE Booking_Creator SHALL prefix the created Booking guest name with the configured test prefix.
4. THE Enquiry Hub SHALL display a visible marker on each listed Enquiry whose `is_test` value is true.
5. THE Enquiry_API SHALL include Enquiries whose `is_test` value is true in list responses unless the `hide_test` parameter is supplied with a true value.
6. THE Enquiry Hub SHALL provide a control that excludes Enquiries whose `is_test` value is true from the displayed list, defaulting that control to including test Enquiries.
7. THE Enquiry_API SHALL expose a route, available to users holding the `manage_options` capability, that deletes every Enquiry whose `is_test` value is true.
8. WHEN the delete-test-records route runs, THE Enquiry_Store SHALL leave every Enquiry whose `is_test` value is false unchanged.

### Requirement 18: Manual Enquiry Creation from the Enquiry Hub

**User Story:** As a member of the management team, I want to create an enquiry by hand, so that an enquiry arriving by telephone or direct email is held and worked in the same place as a website enquiry.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose a manual enquiry creation route that creates one Enquiry from field values submitted by an authenticated user.
2. WHEN the Enquiry_API receives a manual enquiry creation request, THE Validator SHALL apply the Manual Validation Profile defined in Requirement 3.
3. IF a manual enquiry creation request omits `first_name`, `last_name`, `email` or `date_ranges`, or holds any of those fields with a value that is empty after removal of leading and trailing whitespace or with an empty collection, THEN THE Enquiry_API SHALL reject the request with HTTP status 400 naming every field that fails validation and SHALL create no Enquiry.
4. WHEN a manual enquiry creation request omits `phone`, `total_guests` or `message`, or holds any of those fields with a value that is empty after removal of leading and trailing whitespace, THE Enquiry_API SHALL create the Enquiry and THE Enquiry_Store SHALL store an empty value for each such field.
5. WHEN a manual enquiry creation request omits `event_type` or `site_exclusivity`, or holds either of those fields with an empty collection, THE Enquiry_API SHALL create the Enquiry and THE Enquiry_Store SHALL store zero rows for each such field.
6. WHERE a manual enquiry creation request holds `phone`, `total_guests`, `message`, `event_type` or `site_exclusivity` with a value that is not empty after removal of leading and trailing whitespace, THE Validator SHALL evaluate that value against every criterion in Requirement 3 that names that field, and THE Enquiry_API SHALL reject the request with HTTP status 400 naming every field that fails validation when that evaluation returns a validation failure.
7. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_Store SHALL set `status` to `new`.
8. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_Store SHALL set `source` to a value identifying manual creation and holding the identifier of the WordPress user that submitted the request.
9. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_Store SHALL set `created_at`, `updated_at` and `status_changed_at` to the time the Enquiry_API received the request, expressed in the site timezone.
10. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_Store SHALL store one Candidate Date Range row for each range present in the submitted `date_ranges` value, holding that range's position in the submitted list as its rank, one row for each submitted `event_type` value and one row for each submitted `site_exclusivity` value.
11. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_Store SHALL store the Enquiry Payload Snapshot containing every field value that request submitted.
12. THE Enquiry_API SHALL apply the duplicate detection defined in Requirement 4 and the rate limit defined in Requirement 4 to Intake Webhook Requests only.
13. WHEN the Enquiry_API receives a manual enquiry creation request holding the same `email` value and the same set of Candidate Date Ranges as an existing Enquiry created within the preceding 900 seconds, THE Enquiry_API SHALL create a new Enquiry.
14. WHEN the Enquiry_API has received 6 or more manual enquiry creation requests holding the same submitted `email` value within the preceding 900 seconds, THE Enquiry_API SHALL create one Enquiry for the sixth manual enquiry creation request and for each subsequent manual enquiry creation request holding that `email` value within that period.
15. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Enquiry_API SHALL record no rejected intake attempt for that request.
16. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE Contact_Linker SHALL upsert the FluentCRM Contact under every criterion in Requirement 6, SHALL set `crm_sync_state` to `synced` on success under Requirement 5, and SHALL set `crm_sync_state` to `pending` on failure under Requirement 5.
17. WHERE the Staging_Marker reports staging mode and the Contact_Linker links a Contact for a manually created Enquiry, THE Contact_Linker SHALL prefix the Contact `first_name` value with the configured test prefix and SHALL apply the `test-record` tag.
18. WHEN the Enquiry_API creates an Enquiry from a manual enquiry creation request, THE History_Recorder SHALL record a history entry of type `created` holding the identifier of the WordPress user that submitted the request as the acting user identifier.
19. WHERE the Staging_Marker reports staging mode, THE Enquiry_Store SHALL set the field `is_test` to true for an Enquiry created from a manual enquiry creation request.
20. WHERE the Staging_Marker reports production mode, THE Enquiry_Store SHALL set the field `is_test` to false for an Enquiry created from a manual enquiry creation request.
21. THE Enquiry_API SHALL register the manual enquiry creation route with `Auth::rest_permission` as its permission callback and SHALL require a valid WordPress REST nonce in the `X-WP-Nonce` header for that route, under every criterion in Requirement 16.
22. IF a manual enquiry creation request carries the Intake Secret and no authenticated WordPress user, THEN THE Enquiry_API SHALL return HTTP status 401 and SHALL create no Enquiry.
23. THE Enquiry Hub SHALL provide a control that opens a manual enquiry form and a control that submits that form to the manual enquiry creation route.

### Requirement 19: Correction of Stored Enquiry Field Values

**User Story:** As a member of the management team, I want to correct an enquiry's stored details, so that a misheard email address or a revised guest count can be fixed without losing the enquiry or its history.

#### Acceptance Criteria

1. THE Enquiry_API SHALL expose an enquiry edit route that updates the `first_name`, `last_name`, `email`, `phone`, `total_guests`, `message`, Candidate Date Ranges, `event_type` values and `site_exclusivity` values of an existing Enquiry.
2. THE Enquiry_API SHALL accept an enquiry edit request for an Enquiry holding any `source` value, including an Enquiry created from an Intake Webhook Request, an Enquiry created from a manual enquiry creation request and an Enquiry created by the Migration_Runner.
3. WHEN the Enquiry_API receives an enquiry edit request, THE Validator SHALL evaluate each field present in that request against every criterion in Requirement 3 that names that field, under the Manual Validation Profile.
4. IF an enquiry edit request holds `first_name`, `last_name`, `email` or `date_ranges` with a value that is empty after removal of leading and trailing whitespace or with an empty collection, THEN THE Enquiry_API SHALL reject the request with HTTP status 400 naming that field and THE Enquiry_Store SHALL leave every stored value of that Enquiry unchanged.
5. IF a value in an enquiry edit request fails a criterion in Requirement 3 that names that field, THEN THE Enquiry_API SHALL reject the request with HTTP status 400 naming every field that fails validation and THE Enquiry_Store SHALL leave every stored value of that Enquiry unchanged.
6. WHEN the Enquiry_API applies an enquiry edit request, THE Enquiry_Store SHALL store each submitted value with the HTML tag removal defined in Requirement 3 criterion 9 and the truncation defined in Requirement 3 criterion 7 applied to that value.
7. WHEN the Enquiry_API applies an enquiry edit request, THE Enquiry_Store SHALL change only the fields present in that request and SHALL leave the stored value of every field absent from that request unchanged.
8. WHEN the Enquiry_API applies an enquiry edit request that changes the stored value of at least one field, THE Enquiry_Store SHALL set `updated_at` to the time the Enquiry_API received the request, expressed in the site timezone.
9. WHEN the Enquiry_API applies an enquiry edit request, THE Enquiry_Store SHALL leave `status`, `status_changed_at`, `created_at`, `source`, `is_test`, `booking_id` and the Enquiry Payload Snapshot unchanged, and SHALL change `fluentcrm_subscriber_id` only as criterion 14 of this requirement defines.
10. WHEN the Enquiry_API applies an enquiry edit request, THE History_Recorder SHALL leave every existing history entry of that Enquiry unchanged.
11. THE Enquiry_Store SHALL retain the Enquiry Payload Snapshot as the verbatim field values captured when the Enquiry was created, after any number of applied enquiry edit requests (invariant property).
12. WHILE an Enquiry holds status `closed`, THE Enquiry_API SHALL reject an enquiry edit request for that Enquiry with HTTP status 409 and THE Enquiry_Store SHALL leave every stored value of that Enquiry unchanged, consistent with Requirement 9 criterion 1.
13. WHEN the Enquiry_API applies an enquiry edit request that changes the stored value of at least one field, THE History_Recorder SHALL record one history entry of type `fields_edited` naming each field whose stored value changed, holding the previous value and the new value of each such field, and holding the identifier of the WordPress user that submitted the request as the acting user identifier.
14. WHERE an applied enquiry edit request changes `first_name`, `last_name`, `email` or `phone`, THE Contact_Linker SHALL upsert the FluentCRM Contact under every criterion in Requirement 6 using the changed values.
15. WHERE an applied enquiry edit request changes `email` and the Contact_Linker upsert returns a FluentCRM subscriber identifier that differs from the Enquiry `fluentcrm_subscriber_id` value, THE Contact_Linker SHALL replace the Enquiry `fluentcrm_subscriber_id` value with the returned subscriber identifier and SHALL set `crm_sync_state` to `synced`.
16. IF the Contact_Linker returns a failure after an applied enquiry edit request, THEN THE Enquiry_Store SHALL retain the changed field values, SHALL set `crm_sync_state` to `pending` and SHALL leave `fluentcrm_subscriber_id` unchanged.
17. WHERE an applied enquiry edit request changes no value of `first_name`, `last_name`, `email` or `phone`, THE Enquiry_Store SHALL leave `fluentcrm_subscriber_id` and `crm_sync_state` unchanged.
18. WHEN the Enquiry_API applies an enquiry edit request holding values equal to the Enquiry's stored values for every field present in that request, THE Enquiry_Store SHALL leave `updated_at` unchanged and THE History_Recorder SHALL record no history entry (idempotence property).
19. THE Enquiry_API SHALL register the enquiry edit route with `Auth::rest_permission` as its permission callback and SHALL require a valid WordPress REST nonce in the `X-WP-Nonce` header for that route, under every criterion in Requirement 16.
20. THE Enquiry Hub SHALL provide a control that opens an edit form holding the stored field values of the displayed Enquiry and a control that submits that form to the enquiry edit route.
