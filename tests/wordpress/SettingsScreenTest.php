<?php
/**
 * The settings screen, asserted as rendered markup and as stored options
 * (Requirements 2.7, 2.8, 2.9, 14.13, 15.13, 16.13, 16.15).
 *
 * Everything here is read off the screen the way an administrator meets it: the
 * settings sections are rendered through `do_settings_sections()` into a buffer
 * and the resulting markup is what gets asserted, rather than the registration
 * tables behind it. A control that is registered but renders nothing would pass
 * a registration check and fail an administrator, so the markup is the subject.
 *
 * Two shapes of assertion recur, and the distinction matters:
 *
 * - **Renders** — the control's `<input>` is present in the settings markup,
 *   named so that `options.php` will carry its value.
 * - **Persists** — a submitted value survives `update_option()`, which is what
 *   runs the `sanitize_option_{$option}` filter `register_setting()` installs.
 *   Going through `update_option()` rather than calling the sanitizer directly
 *   is what proves the sanitizer is actually wired to the option, so a
 *   `register_setting()` call losing its `sanitize_callback` would be caught.
 *
 * The Intake Secret gets the strongest form of the confidentiality assertion
 * available: not that its `value` attribute is empty, but that the stored secret
 * appears nowhere in the markup at all (Requirements 16.12, 16.13). A control
 * rendering `value=""` beside a `data-` attribute or a description holding the
 * secret would satisfy the weaker check and leak the secret anyway.
 *
 * The migration controls sit outside `do_settings_sections()`, in a form of
 * their own posting to `admin-post.php`, because starting a run is an action
 * rather than a stored setting and a form cannot be nested inside the settings
 * form. They are therefore asserted through `Settings::render_migration_section()`
 * and through the whole page, not by looking for a settings section
 * (Requirement 15.13).
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\MigrationRunner;
use MarthrownEnquiryHub\Settings;

/**
 * Class SettingsScreenTest
 */
class SettingsScreenTest extends WP_UnitTestCase {

	/**
	 * The option the removed Event Enquiry calendar control used to write
	 * (Requirement 14.13). Named as a literal rather than through a constant,
	 * because the point is that nothing reads it any more.
	 */
	const REMOVED_CALENDAR_OPTION = 'meh_event_enquiry_calendar';

	/**
	 * The statement Requirement 16.15 puts next to the secret control, verbatim.
	 */
	const ACCESS_LOG_WARNING = 'An Intake Secret carried in the request URL is recorded in server access logs and is therefore weaker than an Intake Secret carried in a request header.';

	/**
	 * A stored Intake Secret, distinctive enough to be searched for.
	 */
	const STORED_SECRET = 'Qv7ZmT2xLp9RdWn4Hb6K';

	/**
	 * An administrator: holds `manage_options`.
	 *
	 * @var int
	 */
	private static $administrator = 0;

	/**
	 * An editor: a hub user who holds no `manage_options`.
	 *
	 * @var int
	 */
	private static $editor = 0;

	/**
	 * Create the two users the capability split is read through.
	 *
	 * @param WP_UnitTest_Factory $factory WordPress fixture factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$administrator = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor        = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();

		// `admin_init` does not fire in a test request, so the sections and the
		// sanitize callbacks are registered here. Registration is keyed by id,
		// so repeating it between tests replaces rather than duplicates.
		Settings::register_settings();

		delete_option( Settings::OPT_INTAKE_SECRET );
		delete_option( Settings::OPT_INTAKE_SOURCE_FIELD );
		delete_option( Settings::OPT_FIELD_MAP );
		delete_option( self::REMOVED_CALENDAR_OPTION );

		wp_set_current_user( self::$administrator );
	}

	public function tear_down() {
		delete_option( Settings::OPT_INTAKE_SECRET );
		delete_option( Settings::OPT_INTAKE_SOURCE_FIELD );
		delete_option( Settings::OPT_FIELD_MAP );
		delete_option( self::REMOVED_CALENDAR_OPTION );
		delete_transient( Settings::MIGRATION_RESULT_TRANSIENT . get_current_user_id() );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Requirement 2.7 — the nine mapping controls
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 2.7: one mapping control per enquiry field, named so that
	 * `options.php` carries it into `meh_field_map`.
	 *
	 * @return void
	 */
	public function test_the_nine_mapping_controls_render() {
		$markup = $this->render_sections();

		$this->assertCount( 9, FieldMapper::FIELDS, 'There are nine enquiry fields to map.' );

		foreach ( FieldMapper::FIELDS as $field ) {
			$this->assertStringContainsString(
				'name="' . Settings::OPT_FIELD_MAP . '[' . $field . ']"',
				$markup,
				$field . ' should have a mapping control on the settings screen.'
			);
		}
	}

	/**
	 * Requirement 2.7: a submitted mapping is stored against every one of the
	 * nine fields and comes back out of `FieldMapper`, which is the component
	 * that reads it.
	 *
	 * @return void
	 */
	public function test_the_nine_mapping_controls_persist() {
		$submitted = array();

		foreach ( FieldMapper::FIELDS as $field ) {
			$submitted[ $field ] = 'payload_' . $field;
		}

		update_option( Settings::OPT_FIELD_MAP, $submitted );

		$this->assertSame(
			$submitted,
			FieldMapper::mapping(),
			'Every submitted mapping should be stored and read back by FieldMapper.'
		);

		// Persisted and then rendered: what was saved is what the screen offers
		// back, so an administrator can see and correct it.
		$markup = $this->render_sections();

		foreach ( FieldMapper::FIELDS as $field ) {
			$this->assertMatchesRegularExpression(
				'/name="' . preg_quote( Settings::OPT_FIELD_MAP, '/' ) . '\[' . preg_quote( $field, '/' ) . '\]"\s+value="payload_' . preg_quote( $field, '/' ) . '"/',
				$markup,
				$field . "'s stored mapping should be rendered as its control's value."
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Requirement 2.9 — the form-identifier field control
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 2.9: the control recording which payload field holds the form
	 * identifier renders and persists.
	 *
	 * @return void
	 */
	public function test_the_form_identifier_field_control_renders_and_persists() {
		$this->assertStringContainsString(
			'name="' . Settings::OPT_INTAKE_SOURCE_FIELD . '"',
			$this->render_sections(),
			'The form identifier field control should render.'
		);

		update_option( Settings::OPT_INTAKE_SOURCE_FIELD, '  form_id  ' );

		$this->assertSame(
			'form_id',
			get_option( Settings::OPT_INTAKE_SOURCE_FIELD ),
			'The submitted payload field name should be stored, trimmed.'
		);
		$this->assertSame(
			'form_id',
			get_option( IntakeEndpoint::SOURCE_FIELD_OPTION ),
			'The option the Intake_Endpoint reads is the option the control writes.'
		);
		$this->assertStringContainsString(
			'name="' . Settings::OPT_INTAKE_SOURCE_FIELD . '" value="form_id"',
			$this->render_sections(),
			'The stored payload field name should be rendered back.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Requirements 2.8, 16.13 — the Intake Secret control
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 2.8, 16.12, 16.13: the secret control renders holding an
	 * empty value, and the stored secret appears nowhere in the markup.
	 *
	 * @return void
	 */
	public function test_the_intake_secret_control_renders_with_an_empty_value() {
		update_option( Settings::OPT_INTAKE_SECRET, self::STORED_SECRET );

		$markup = $this->render_sections();

		$this->assertStringContainsString(
			'name="' . Settings::OPT_INTAKE_SECRET . '"',
			$markup,
			'The Intake Secret control should render.'
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="password"[^>]+name="' . preg_quote( Settings::OPT_INTAKE_SECRET, '/' ) . '"[^>]+value=""/',
			$markup,
			'The Intake Secret control should be a password input holding an empty value.'
		);

		// The strong form: not merely an empty value attribute, but the secret
		// nowhere in the page at all.
		$this->assertStringNotContainsString(
			self::STORED_SECRET,
			$markup,
			'The stored Intake Secret should appear nowhere in the settings markup.'
		);
		$this->assertStringNotContainsString(
			self::STORED_SECRET,
			$this->render_page(),
			'The stored Intake Secret should appear nowhere in the whole page.'
		);
	}

	/**
	 * Requirement 16.13: a submission holding an empty value retains the stored
	 * secret, whether the field arrived empty, whitespace-only, or absent from
	 * the request altogether — `options.php` passes null for an option missing
	 * from the submitted form.
	 *
	 * @return void
	 */
	public function test_an_empty_submission_retains_the_stored_intake_secret() {
		update_option( Settings::OPT_INTAKE_SECRET, self::STORED_SECRET );

		foreach ( array( '', '   ' ) as $submitted ) {
			update_option( Settings::OPT_INTAKE_SECRET, $submitted );

			$this->assertSame(
				self::STORED_SECRET,
				get_option( Settings::OPT_INTAKE_SECRET ),
				'Submitting ' . var_export( $submitted, true ) . ' should retain the stored secret.'
			);
		}

		// An option absent from $_POST reaches the sanitizer as null.
		$this->assertSame(
			self::STORED_SECRET,
			Settings::sanitize_intake_secret( null ),
			'An absent secret field should retain the stored secret.'
		);

		// Retention is not stickiness: a real value still replaces it.
		update_option( Settings::OPT_INTAKE_SECRET, 'Yh4Wq8Bs3Nv6Tk2Lf9Dc' );

		$this->assertSame(
			'Yh4Wq8Bs3Nv6Tk2Lf9Dc',
			get_option( IntakeEndpoint::SECRET_OPTION ),
			'A non-empty submission should replace the stored secret.'
		);
	}

	/**
	 * Requirement 16.15: the access-log weakness of the query-parameter
	 * transport is stated on the screen, next to the control.
	 *
	 * @return void
	 */
	public function test_the_access_log_warning_is_displayed() {
		$this->assertStringContainsString(
			self::ACCESS_LOG_WARNING,
			$this->render_sections(),
			'The access-log statement should be displayed on the settings screen.'
		);
		$this->assertStringContainsString(
			IntakeEndpoint::SECRET_QUERY,
			$this->render_sections(),
			'The query parameter the statement is about should be named.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Requirement 14.13 — the removed calendar control
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 14.13: the Event Enquiry calendar selection control is gone.
	 *
	 * @return void
	 */
	public function test_the_event_enquiry_calendar_control_is_absent() {
		update_option( self::REMOVED_CALENDAR_OPTION, '42' );

		$this->assertStringNotContainsString(
			self::REMOVED_CALENDAR_OPTION,
			$this->render_sections(),
			'The Event Enquiry calendar control should not be offered in any section.'
		);
		$this->assertStringNotContainsString(
			self::REMOVED_CALENDAR_OPTION,
			$this->render_page(),
			'The Event Enquiry calendar control should not be offered anywhere on the page.'
		);

		// The option stays in the database, simply unread by this screen.
		$this->assertSame(
			'42',
			get_option( self::REMOVED_CALENDAR_OPTION ),
			'Removing the control should not remove the stored option.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Requirement 15.13 — the migration controls
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 15.13: a run control and a preview control, for a user
	 * holding `manage_options`.
	 *
	 * @return void
	 */
	public function test_the_migration_controls_render_for_a_manage_options_user() {
		wp_set_current_user( self::$administrator );

		$this->assertTrue( current_user_can( Settings::CAPABILITY ), 'The fixture administrator holds manage_options.' );
		$this->assertTrue( Settings::user_can_migrate() );

		$markup = $this->render_migration_section();

		$this->assertStringContainsString(
			'value="' . Settings::MIGRATION_RUN_ACTION . '"',
			$markup,
			'A control that starts a migration run should render.'
		);
		$this->assertStringContainsString(
			'value="' . Settings::MIGRATION_PREVIEW_ACTION . '"',
			$markup,
			'A control that starts a preview run should render.'
		);
		$this->assertStringContainsString(
			'name="' . Settings::MIGRATION_NONCE_FIELD . '"',
			$markup,
			'Both controls should be nonce-protected.'
		);
		$this->assertStringContainsString(
			'admin-post.php',
			$markup,
			'Starting a run is an action, so the controls post to admin-post.php rather than options.php.'
		);

		// And they are reached from the page an administrator actually opens.
		$this->assertStringContainsString(
			'value="' . Settings::MIGRATION_RUN_ACTION . '"',
			$this->render_page(),
			'The migration controls should be reached from the settings page.'
		);
	}

	/**
	 * Requirement 15.13: a user holding no `manage_options` is offered neither
	 * control — nothing renders at all, rather than a control that would be
	 * refused.
	 *
	 * @return void
	 */
	public function test_the_migration_controls_are_absent_for_a_user_without_manage_options() {
		wp_set_current_user( self::$editor );

		$this->assertFalse( current_user_can( Settings::CAPABILITY ), 'The fixture editor holds no manage_options.' );
		$this->assertFalse( Settings::user_can_migrate() );

		$this->assertSame(
			'',
			$this->render_migration_section(),
			'The migration section should render nothing for a user without manage_options.'
		);
	}

	/**
	 * Requirement 15.13: the last completion time is reported when one is
	 * recorded, so an administrator can see the migration has already run.
	 *
	 * @return void
	 */
	public function test_the_migration_section_reports_the_last_completed_run() {
		wp_set_current_user( self::$administrator );

		update_option( MigrationRunner::COMPLETED_OPTION, '2025-09-01 10:30:00' );

		$this->assertStringContainsString(
			'2025-09-01 10:30:00',
			$this->render_migration_section(),
			'A recorded completion time should be reported.'
		);

		delete_option( MigrationRunner::COMPLETED_OPTION );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The settings sections, rendered as `options.php`'s form renders them.
	 *
	 * @return string
	 */
	private function render_sections() {
		ob_start();
		do_settings_sections( Settings::MENU_SLUG );
		return (string) ob_get_clean();
	}

	/**
	 * The migration section, rendered on its own.
	 *
	 * @return string
	 */
	private function render_migration_section() {
		ob_start();
		Settings::render_migration_section();
		return (string) ob_get_clean();
	}

	/**
	 * The whole settings page, shell and all.
	 *
	 * @return string
	 */
	private function render_page() {
		ob_start();
		Settings::render_page();
		return (string) ob_get_clean();
	}
}
