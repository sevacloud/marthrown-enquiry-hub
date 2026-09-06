<?php
/**
 * Settings screen.
 *
 * Lives at wp-admin → Settings → Enquiry Hub (administrators only) and is
 * surfaced in the hub's side nav for admins. Five sections:
 *
 *   - Enquiries : which FluentCRM list/tag holds event enquiries.
 *   - Intake    : the Intake Secret, the form-identifier payload field and the
 *                 nine payload field mappings (Requirements 2.7, 2.8, 2.9).
 *   - Bookings  : WPBS guest field mapping.
 *   - Migration : run and preview controls for the FluentCRM migration
 *                 (Requirement 15.13).
 *   - Access    : which roles may use the hub.
 *
 * Two things about the Intake section are deliberate rather than incidental.
 * The Intake Secret control renders with an empty value and never echoes the
 * stored secret back into the page, and a submission carrying an empty value
 * retains the stored secret rather than clearing it, so saving any other
 * setting cannot silently break intake (Requirement 16.13). Next to it sits the
 * statement that a secret carried in the request URL lands in server access
 * logs, which is why the header transport is preferred (Requirement 16.15).
 *
 * The Event Enquiry calendar control is gone (Requirement 14.13). The
 * `meh_event_enquiry_calendar` option stays in the database, simply no longer
 * offered here.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	const MENU_SLUG    = 'marthrown-enquiry-hub-settings';
	const CAPABILITY   = 'manage_options';
	const OPTION_GROUP = 'meh_settings';

	const OPT_ROLES        = 'meh_allowed_roles';
	const OPT_ENQUIRY_LIST = 'meh_enquiry_list';
	const OPT_ENQUIRY_TAG  = 'meh_enquiry_tag';

	/**
	 * Intake options, named by the components that read them.
	 *
	 * Taking the names from `IntakeEndpoint` and `FieldMapper` rather than
	 * restating the strings keeps the screen and the readers from drifting
	 * apart.
	 */
	const OPT_INTAKE_SECRET       = IntakeEndpoint::SECRET_OPTION;
	const OPT_INTAKE_SOURCE_FIELD = IntakeEndpoint::SOURCE_FIELD_OPTION;
	const OPT_FIELD_MAP           = FieldMapper::OPTION;

	/**
	 * `admin-post.php` actions behind the two migration controls.
	 */
	const MIGRATION_RUN_ACTION     = 'meh_migration_run';
	const MIGRATION_PREVIEW_ACTION = 'meh_migration_preview';

	/**
	 * Nonce action and field name shared by both migration controls.
	 */
	const MIGRATION_NONCE_ACTION = 'meh_migration';
	const MIGRATION_NONCE_FIELD  = 'meh_migration_nonce';

	/**
	 * Transient prefix holding the last migration outcome for one user.
	 */
	const MIGRATION_RESULT_TRANSIENT = 'meh_migration_result_';

	/**
	 * Default titles used to auto-detect the list/tag before anything is saved.
	 */
	const DEFAULT_LIST_TITLE = 'Event Enquiries';
	const DEFAULT_TAG_TITLE  = 'Event Enquiry';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::MIGRATION_RUN_ACTION, array( __CLASS__, 'handle_migration_run' ) );
		add_action( 'admin_post_' . self::MIGRATION_PREVIEW_ACTION, array( __CLASS__, 'handle_migration_preview' ) );
	}

	/**
	 * Register the settings page under wp-admin → Settings.
	 */
	public static function register_menu() {
		add_options_page(
			__( 'Enquiry Hub Settings', 'marthrown-enquiry-hub' ),
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Register settings, sections and fields.
	 */
	public static function register_settings() {
		self::register_enquiry_settings();
		self::register_intake_settings();
		self::register_bookings_settings();
		self::register_access_settings();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Enquiries (FluentCRM source).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the Enquiries section.
	 */
	public static function register_enquiry_settings() {
		foreach ( array( self::OPT_ENQUIRY_LIST, self::OPT_ENQUIRY_TAG ) as $option ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				)
			);
		}

		add_settings_section(
			'meh_enquiries_section',
			__( 'Enquiries', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_enquiries_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPT_ENQUIRY_LIST,
			__( 'Event Enquiries list', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_list_select' ),
			self::MENU_SLUG,
			'meh_enquiries_section',
			array(
				'label_for'   => self::OPT_ENQUIRY_LIST,
				'description' => __( 'FluentCRM list your enquiry form adds contacts to.', 'marthrown-enquiry-hub' ),
			)
		);

		add_settings_field(
			self::OPT_ENQUIRY_TAG,
			__( 'Event Enquiry tag', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_tag_select' ),
			self::MENU_SLUG,
			'meh_enquiries_section',
			array(
				'label_for'   => self::OPT_ENQUIRY_TAG,
				'description' => __( 'FluentCRM tag applied by the enquiry form.', 'marthrown-enquiry-hub' ),
			)
		);
	}

	/**
	 * Enquiries section intro.
	 */
	public static function render_enquiries_section_intro() {
		echo '<p>' . esc_html__( 'FluentCRM is the source of record for event enquiries. Your website form should add the contact to this list and apply this tag; the hub reads those contacts. If both are set, a contact must match both to appear.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/**
	 * Resolve the configured enquiry list id, falling back to title match.
	 *
	 * @return int
	 */
	public static function enquiry_list_id() {
		$saved = (int) get_option( self::OPT_ENQUIRY_LIST, 0 );
		if ( $saved ) {
			return $saved;
		}
		return self::find_by_title( 'lists', self::DEFAULT_LIST_TITLE );
	}

	/**
	 * Resolve the configured enquiry tag id, falling back to title match.
	 *
	 * @return int
	 */
	public static function enquiry_tag_id() {
		$saved = (int) get_option( self::OPT_ENQUIRY_TAG, 0 );
		if ( $saved ) {
			return $saved;
		}
		return self::find_by_title( 'tags', self::DEFAULT_TAG_TITLE );
	}

	/**
	 * Find a FluentCRM list/tag id by title.
	 *
	 * @param string $type  'lists' or 'tags'.
	 * @param string $title Title to match.
	 * @return int 0 when not found.
	 */
	protected static function find_by_title( $type, $title ) {
		foreach ( self::taxonomy_options( $type ) as $id => $label ) {
			if ( strtolower( $label ) === strtolower( $title ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * FluentCRM lists or tags as id => title.
	 *
	 * @param string $type 'lists' or 'tags'.
	 * @return array
	 */
	protected static function taxonomy_options( $type ) {
		$class = ( 'lists' === $type )
			? '\FluentCrm\App\Models\Lists'
			: '\FluentCrm\App\Models\Tag';

		if ( ! class_exists( $class ) ) {
			return array();
		}

		$out = array();
		foreach ( $class::orderBy( 'title', 'asc' )->get() as $item ) {
			$out[ (int) $item->id ] = (string) $item->title;
		}
		return $out;
	}

	/**
	 * Render the list picker.
	 *
	 * @param array $args Field args.
	 */
	public static function render_list_select( $args ) {
		self::render_taxonomy_select( $args, 'lists', self::enquiry_list_id() );
	}

	/**
	 * Render the tag picker.
	 *
	 * @param array $args Field args.
	 */
	public static function render_tag_select( $args ) {
		self::render_taxonomy_select( $args, 'tags', self::enquiry_tag_id() );
	}

	/**
	 * Render a FluentCRM list/tag <select>.
	 *
	 * @param array  $args     Field args (label_for, description).
	 * @param string $type     'lists' or 'tags'.
	 * @param int    $selected Currently selected id.
	 */
	protected static function render_taxonomy_select( $args, $type, $selected ) {
		$option  = $args['label_for'];
		$options = self::taxonomy_options( $type );

		if ( empty( $options ) ) {
			echo '<p class="description">' . esc_html__( 'FluentCRM was not detected, so nothing can be listed here.', 'marthrown-enquiry-hub' ) . '</p>';
			return;
		}

		echo '<select id="' . esc_attr( $option ) . '" name="' . esc_attr( $option ) . '">';
		echo '<option value="0">' . esc_html__( '— None —', 'marthrown-enquiry-hub' ) . '</option>';
		foreach ( $options as $id => $label ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $id,
				selected( $selected, $id, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}

		if ( ! get_option( $option ) && $selected ) {
			echo '<p class="description">' . esc_html__( 'Auto-detected by name; save to lock it in.', 'marthrown-enquiry-hub' ) . '</p>';
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Intake (webhook secret, form identifier, payload field mapping).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the Intake section.
	 */
	public static function register_intake_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPT_INTAKE_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_intake_secret' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			self::OPT_INTAKE_SOURCE_FIELD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text' ),
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			self::OPT_FIELD_MAP,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_field_map' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'meh_intake_section',
			__( 'Intake', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_intake_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPT_INTAKE_SECRET,
			__( 'Intake Secret', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_intake_secret_field' ),
			self::MENU_SLUG,
			'meh_intake_section',
			array( 'label_for' => self::OPT_INTAKE_SECRET )
		);

		add_settings_field(
			self::OPT_INTAKE_SOURCE_FIELD,
			__( 'Form identifier field', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_text_field' ),
			self::MENU_SLUG,
			'meh_intake_section',
			array(
				'label_for'   => self::OPT_INTAKE_SOURCE_FIELD,
				'description' => __( 'Payload field holding the identifier of the sending form. Its value sets the enquiry source. Left unset, or absent from a payload, the enquiry is recorded as webhook:unidentified.', 'marthrown-enquiry-hub' ),
			)
		);

		foreach ( FieldMapper::FIELDS as $field ) {
			add_settings_field(
				self::OPT_FIELD_MAP . '_' . $field,
				sprintf(
					/* translators: %s: enquiry field label, e.g. "First name". */
					__( '%s payload field', 'marthrown-enquiry-hub' ),
					self::field_label( $field )
				),
				array( __CLASS__, 'render_field_map_field' ),
				self::MENU_SLUG,
				'meh_intake_section',
				array(
					'label_for' => self::OPT_FIELD_MAP . '_' . $field,
					'field'     => $field,
				)
			);
		}
	}

	/**
	 * Intake section intro.
	 */
	public static function render_intake_section_intro() {
		echo '<p>' . esc_html__( 'The website enquiry form posts each submission to the plugin\'s intake endpoint. The secret below authenticates those requests, and the mappings say which payload field supplies each enquiry field.', 'marthrown-enquiry-hub' ) . '</p>';
		printf(
			'<p><code>%s</code></p>',
			esc_html( self::intake_url() )
		);
		echo '<p class="description">' . esc_html__( 'A field left unmapped is resolved by matching the payload label against the enquiry field name, ignoring case and separators, so "First Name" supplies first_name with nothing configured here.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/**
	 * The intake endpoint URL, shown so the form can be pointed at it.
	 *
	 * @return string
	 */
	public static function intake_url() {
		return IntakeEndpoint::url();
	}

	/**
	 * Render the Intake Secret control.
	 *
	 * Always rendered holding an empty value: the stored secret is never echoed
	 * back into the page, in a response body or in a response header
	 * (Requirements 16.12, 16.13). Whether a secret is stored is stated in
	 * words instead, which is what an administrator actually needs to know.
	 *
	 * @param array $args Field args (label_for).
	 */
	public static function render_intake_secret_field( $args ) {
		$option = isset( $args['label_for'] ) ? $args['label_for'] : self::OPT_INTAKE_SECRET;
		$stored = (string) get_option( self::OPT_INTAKE_SECRET, '' );

		printf(
			'<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />',
			esc_attr( $option )
		);

		echo '<p class="description">';
		echo '' !== trim( $stored )
			? esc_html__( 'A secret is stored. Leave this blank to keep it, or enter a new value to replace it.', 'marthrown-enquiry-hub' )
			: esc_html__( 'No secret is stored yet, so every intake request is rejected. Enter a long random value and configure the enquiry form to send it.', 'marthrown-enquiry-hub' );
		echo '</p>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: request header name, 2: query parameter name. */
					__( 'Send the secret in the %1$s request header where the form supports custom headers. Where it does not, it can be sent as the %2$s query parameter instead.', 'marthrown-enquiry-hub' ),
					IntakeEndpoint::SECRET_HEADER,
					IntakeEndpoint::SECRET_QUERY
				)
			)
		);

		// Requirement 16.15: the weakness of the query-parameter transport is
		// stated next to the control, not buried in documentation.
		printf(
			'<p class="description meh-intake-secret-warning"><strong>%s</strong></p>',
			esc_html__( 'An Intake Secret carried in the request URL is recorded in server access logs and is therefore weaker than an Intake Secret carried in a request header.', 'marthrown-enquiry-hub' )
		);
	}

	/**
	 * Render one payload field mapping control.
	 *
	 * @param array $args Field args (label_for, field).
	 */
	public static function render_field_map_field( $args ) {
		$field = isset( $args['field'] ) ? (string) $args['field'] : '';

		if ( ! in_array( $field, FieldMapper::FIELDS, true ) ) {
			return;
		}

		$id      = isset( $args['label_for'] ) ? $args['label_for'] : self::OPT_FIELD_MAP . '_' . $field;
		$mapping = FieldMapper::mapping();
		$value   = isset( $mapping[ $field ] ) ? $mapping[ $field ] : '';

		printf(
			'<input type="text" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" autocomplete="off" />',
			esc_attr( $id ),
			esc_attr( self::OPT_FIELD_MAP ),
			esc_attr( $field ),
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: enquiry field name, e.g. "first_name". */
					__( 'Payload field supplying %s. Leave blank to match by label.', 'marthrown-enquiry-hub' ),
					$field
				)
			)
		);
	}

	/**
	 * The label for one enquiry field.
	 *
	 * @param string $field Enquiry field name.
	 * @return string
	 */
	protected static function field_label( $field ) {
		$labels = array(
			'first_name'       => __( 'First name', 'marthrown-enquiry-hub' ),
			'last_name'        => __( 'Last name', 'marthrown-enquiry-hub' ),
			'email'            => __( 'Email', 'marthrown-enquiry-hub' ),
			'phone'            => __( 'Phone', 'marthrown-enquiry-hub' ),
			'total_guests'     => __( 'Total guests', 'marthrown-enquiry-hub' ),
			'selected_dates'   => __( 'Candidate dates', 'marthrown-enquiry-hub' ),
			'event_type'       => __( 'Event type', 'marthrown-enquiry-hub' ),
			'site_exclusivity' => __( 'Site exclusivity', 'marthrown-enquiry-hub' ),
			'message'          => __( 'Message', 'marthrown-enquiry-hub' ),
		);

		if ( isset( $labels[ $field ] ) ) {
			return $labels[ $field ];
		}

		return ucfirst( str_replace( '_', ' ', (string) $field ) );
	}

	/**
	 * Sanitize the submitted Intake Secret, retaining the stored one when empty.
	 *
	 * An empty submission means "leave it alone" rather than "clear it"
	 * (Requirement 16.13), which is the only reading that works given the
	 * control renders empty: saving any other setting on this screen submits an
	 * empty secret field, and that must not disable intake.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_intake_secret( $value ) {
		$submitted = is_scalar( $value ) ? (string) $value : '';

		if ( '' === trim( $submitted ) ) {
			return (string) get_option( self::OPT_INTAKE_SECRET, '' );
		}

		return sanitize_text_field( $submitted );
	}

	/**
	 * Sanitize the submitted field map against the nine recognised fields.
	 *
	 * Unrecognised keys are dropped and a blank entry is omitted rather than
	 * stored empty, so "unset" is one condition for `FieldMapper` to test.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,string>
	 */
	public static function sanitize_field_map( $value ) {
		$out = array();

		if ( ! is_array( $value ) ) {
			return $out;
		}

		foreach ( FieldMapper::FIELDS as $field ) {
			if ( ! isset( $value[ $field ] ) || ! is_scalar( $value[ $field ] ) ) {
				continue;
			}

			$key = trim( sanitize_text_field( (string) $value[ $field ] ) );

			if ( '' !== $key ) {
				$out[ $field ] = $key;
			}
		}

		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Bookings.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the Bookings settings section.
	 */
	public static function register_bookings_settings() {
		register_setting(
			self::OPTION_GROUP,
			'meh_guest_name_field',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text' ),
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'meh_guest_email_field',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text' ),
				'default'           => '',
			)
		);
		add_settings_section(
			'meh_bookings_section',
			__( 'Bookings', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_bookings_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'meh_guest_name_field',
			__( 'Guest name field', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_text_field' ),
			self::MENU_SLUG,
			'meh_bookings_section',
			array(
				'label_for'   => 'meh_guest_name_field',
				'description' => __( 'Booking form field label (or field ID) holding the guest name.', 'marthrown-enquiry-hub' ),
			)
		);
		add_settings_field(
			'meh_guest_email_field',
			__( 'Guest email field', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_text_field' ),
			self::MENU_SLUG,
			'meh_bookings_section',
			array(
				'label_for'   => 'meh_guest_email_field',
				'description' => __( 'Booking form field label (or field ID) holding the guest email.', 'marthrown-enquiry-hub' ),
			)
		);
	}

	/**
	 * Bookings section intro.
	 *
	 * The Event Enquiry calendar control that used to sit here is gone
	 * (Requirement 14.13): an enquiry is a record in the Enquiry Store rather
	 * than a provisional hold on a calendar, so there is nothing to select.
	 */
	public static function render_bookings_section_intro() {
		echo '<p>' . esc_html__( 'Map WP Booking System form fields to the guest name/email shown in the hub.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Access.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the Access section — which roles may use the hub.
	 */
	public static function register_access_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPT_ROLES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => Auth::DEFAULT_ROLES,
			)
		);

		add_settings_section(
			'meh_access_section',
			__( 'Access', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_access_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPT_ROLES,
			__( 'Roles with access', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_roles_field' ),
			self::MENU_SLUG,
			'meh_access_section'
		);
	}

	/**
	 * Access section intro.
	 */
	public static function render_access_section_intro() {
		echo '<p>' . esc_html__( 'Choose which roles can open the hub at /bookings and use its REST API. Administrators always have access (so you cannot lock yourself out) — untick other roles to disable them for testing.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/**
	 * Render the roles checkbox list.
	 */
	public static function render_roles_field() {
		$selected = Auth::allowed_roles();
		$roles    = wp_roles()->get_names();

		echo '<fieldset>';
		foreach ( $roles as $slug => $label ) {
			$is_admin = ( 'administrator' === $slug );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s %4$s /> %5$s%6$s</label>',
				esc_attr( self::OPT_ROLES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ) || $is_admin, true, false ),
				disabled( $is_admin, true, false ),
				esc_html( $label ),
				$is_admin ? ' <em>' . esc_html__( '(always allowed)', 'marthrown-enquiry-hub' ) . '</em>' : ''
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Roles listed here come from WordPress, so roles added by a user role editor plugin appear automatically.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/**
	 * Sanitize the roles array against existing WordPress roles.
	 *
	 * @param mixed $value Submitted value.
	 * @return array
	 */
	public static function sanitize_roles( $value ) {
		$valid = array_keys( wp_roles()->get_names() );
		$out   = array();

		foreach ( (array) $value as $slug ) {
			$slug = sanitize_key( $slug );
			if ( in_array( $slug, $valid, true ) ) {
				$out[] = $slug;
			}
		}

		if ( ! in_array( 'administrator', $out, true ) ) {
			$out[] = 'administrator';
		}

		return array_values( array_unique( $out ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Migration.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether the current user may run or preview the migration.
	 *
	 * Requirement 15.13 names `manage_options`, so the controls are tested
	 * against that capability directly rather than against hub role membership.
	 *
	 * @return bool
	 */
	public static function user_can_migrate() {
		return function_exists( 'current_user_can' ) && current_user_can( self::CAPABILITY );
	}

	/**
	 * Render the Migration section: a preview control and a run control.
	 *
	 * Rendered only for a user holding `manage_options` (Requirement 15.13);
	 * for anybody else this outputs nothing at all rather than a disabled
	 * control, so the screen makes no offer it would refuse.
	 *
	 * It sits in its own form rather than in a settings section, because
	 * starting a run is an action rather than a stored setting, and the
	 * settings form posts to `options.php`.
	 */
	public static function render_migration_section() {
		if ( ! self::user_can_migrate() ) {
			return;
		}

		$completed = (string) get_option( MigrationRunner::COMPLETED_OPTION, '' );
		?>
		<h2 class="meh-migration-heading"><?php esc_html_e( 'Migration', 'marthrown-enquiry-hub' ); ?></h2>

		<p>
			<?php esc_html_e( 'Import enquiries held as FluentCRM contacts into the Enquiry Store. A preview reports what a run would create and writes nothing. Running the migration twice creates nothing the second time.', 'marthrown-enquiry-hub' ); ?>
		</p>

		<?php if ( ! MigrationRunner::available() ) : ?>
			<p class="description">
				<?php esc_html_e( 'FluentCRM was not detected, so there is nothing to read and a run would create nothing.', 'marthrown-enquiry-hub' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $completed ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date and time of the last completed migration run. */
					esc_html__( 'Last migration run completed %s.', 'marthrown-enquiry-hub' ),
					esc_html( $completed )
				);
				?>
			</p>
		<?php endif; ?>

		<?php self::render_migration_result(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::MIGRATION_NONCE_ACTION, self::MIGRATION_NONCE_FIELD ); ?>
			<p class="submit">
				<button type="submit" class="button" name="action" value="<?php echo esc_attr( self::MIGRATION_PREVIEW_ACTION ); ?>">
					<?php esc_html_e( 'Preview migration', 'marthrown-enquiry-hub' ); ?>
				</button>
				<button type="submit" class="button button-primary" name="action" value="<?php echo esc_attr( self::MIGRATION_RUN_ACTION ); ?>">
					<?php esc_html_e( 'Run migration', 'marthrown-enquiry-hub' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the outcome of the last run or preview, once.
	 */
	protected static function render_migration_result() {
		$key    = self::MIGRATION_RESULT_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) || empty( $result['mode'] ) ) {
			return;
		}

		delete_transient( $key );

		$eligible = isset( $result['eligible'] ) ? (int) $result['eligible'] : 0;
		$skipped  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

		if ( self::MIGRATION_PREVIEW_ACTION === $result['mode'] ) {
			$message = sprintf(
				/* translators: 1: eligible contacts, 2: enquiries a run would create, 3: contacts skipped. */
				__( 'Preview: %1$d contact(s) read, %2$d enquiry(ies) would be created, %3$d skipped. Nothing was written.', 'marthrown-enquiry-hub' ),
				$eligible,
				isset( $result['would_create'] ) ? (int) $result['would_create'] : 0,
				$skipped
			);
		} else {
			$message = sprintf(
				/* translators: 1: eligible contacts, 2: enquiries created, 3: contacts skipped. */
				__( 'Migration run: %1$d contact(s) read, %2$d enquiry(ies) created, %3$d skipped.', 'marthrown-enquiry-hub' ),
				$eligible,
				isset( $result['created'] ) ? (int) $result['created'] : 0,
				$skipped
			);
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Start a migration run (Requirement 15.13).
	 */
	public static function handle_migration_run() {
		self::handle_migration( self::MIGRATION_RUN_ACTION );
	}

	/**
	 * Start a migration preview run (Requirements 15.8, 15.13).
	 */
	public static function handle_migration_preview() {
		self::handle_migration( self::MIGRATION_PREVIEW_ACTION );
	}

	/**
	 * Capability check, nonce check, then the run or the preview.
	 *
	 * @param string $mode One of the two migration actions.
	 */
	protected static function handle_migration( $mode ) {
		if ( ! self::user_can_migrate() ) {
			wp_die(
				esc_html__( 'You do not have permission to migrate enquiries.', 'marthrown-enquiry-hub' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::MIGRATION_NONCE_ACTION, self::MIGRATION_NONCE_FIELD );

		$result = ( self::MIGRATION_RUN_ACTION === $mode )
			? MigrationRunner::run()
			: MigrationRunner::preview();

		$result['mode'] = $mode;

		set_transient( self::MIGRATION_RESULT_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'meh_migration', $mode, self::url() ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared renderers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Generic text sanitizer.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_text( $value ) {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Render a standard text input bound to an option.
	 *
	 * @param array $args Field args (label_for, description).
	 */
	public static function render_text_field( $args ) {
		$option = $args['label_for'];
		$value  = get_option( $option, '' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( $option ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render the settings page shell.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap meh-settings-wrap">
			<h1><?php esc_html_e( 'Enquiry Hub Settings', 'marthrown-enquiry-hub' ); ?></h1>

			<?php settings_errors(); ?>

			<p>
				<a href="<?php echo esc_url( home_url( '/' . FrontendBookings::ROUTE . '/' ) ); ?>">
					<?php esc_html_e( 'Open the Enquiry Hub', 'marthrown-enquiry-hub' ); ?>
				</a>
			</p>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>

			<?php self::render_migration_section(); ?>
		</div>
		<?php
	}
}
