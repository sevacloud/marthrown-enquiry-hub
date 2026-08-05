<?php
/**
 * Settings screen.
 *
 * Registers a submenu under "Enquiry Hub" for configuring the Microsoft Graph
 * mailbox credentials used by SourceEmail. Built on the WordPress Settings API
 * so validation, nonces and saving are handled by core.
 *
 * The Graph client secret is obfuscated at rest and never echoed back into the
 * form; only a masked placeholder is shown.
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
	const PARENT_SLUG  = 'marthrown-enquiry-hub';
	const CAPABILITY   = 'manage_options';
	const OPTION_GROUP = 'meh_settings';

	/**
	 * Sentinel value shown in the secret field so we never leak the real one.
	 */
	const SECRET_MASK = '********';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings submenu under the Enquiry Hub top-level menu.
	 */
	public static function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Enquiry Hub Settings', 'marthrown-enquiry-hub' ),
			__( 'Settings', 'marthrown-enquiry-hub' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings, sections and fields.
	 */
	public static function register_settings() {
		$text_args = array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_text' ),
			'default'           => '',
		);

		register_setting( self::OPTION_GROUP, SourceEmail::OPT_TENANT, $text_args );
		register_setting( self::OPTION_GROUP, SourceEmail::OPT_CLIENT, $text_args );
		register_setting(
			self::OPTION_GROUP,
			SourceEmail::OPT_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ),
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			SourceEmail::OPT_MAILBOX,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mailbox' ),
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			SourceEmail::OPT_FOLDER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_text' ),
				'default'           => 'inbox',
			)
		);

		add_settings_section(
			'meh_graph_section',
			__( 'Microsoft Graph (Email Enquiries)', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_graph_section_intro' ),
			self::MENU_SLUG
		);

		$fields = array(
			SourceEmail::OPT_TENANT  => array(
				'label'    => __( 'Tenant ID', 'marthrown-enquiry-hub' ),
				'callback' => 'render_text_field',
				'desc'     => __( 'Azure AD directory (tenant) ID for the Entra app registration.', 'marthrown-enquiry-hub' ),
			),
			SourceEmail::OPT_CLIENT  => array(
				'label'    => __( 'Client ID', 'marthrown-enquiry-hub' ),
				'callback' => 'render_text_field',
				'desc'     => __( 'Application (client) ID of the app registration.', 'marthrown-enquiry-hub' ),
			),
			SourceEmail::OPT_SECRET  => array(
				'label'    => __( 'Client Secret', 'marthrown-enquiry-hub' ),
				'callback' => 'render_secret_field',
				'desc'     => __( 'Client secret value. Stored server-side and never displayed after saving.', 'marthrown-enquiry-hub' ),
			),
			SourceEmail::OPT_MAILBOX => array(
				'label'    => __( 'Mailbox', 'marthrown-enquiry-hub' ),
				'callback' => 'render_text_field',
				'desc'     => __( 'Mailbox to poll, e.g. enquiries@example.com (UPN or email).', 'marthrown-enquiry-hub' ),
			),
			SourceEmail::OPT_FOLDER  => array(
				'label'    => __( 'Mail folder', 'marthrown-enquiry-hub' ),
				'callback' => 'render_text_field',
				'desc'     => __( 'Folder to poll for unread messages. Use a well-known name like "inbox" or a folder ID.', 'marthrown-enquiry-hub' ),
			),
		);

		foreach ( $fields as $option => $field ) {
			add_settings_field(
				$option,
				$field['label'],
				array( __CLASS__, $field['callback'] ),
				self::MENU_SLUG,
				'meh_graph_section',
				array(
					'label_for'   => $option,
					'description' => $field['desc'],
				)
			);
		}

		self::register_bookings_settings();
	}

	/**
	 * Register the Bookings settings section (guest fields + enquiry calendar).
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
		register_setting(
			self::OPTION_GROUP,
			'meh_event_enquiry_calendar',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
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
		add_settings_field(
			'meh_event_enquiry_calendar',
			__( 'Event Enquiry calendar', 'marthrown-enquiry-hub' ),
			array( __CLASS__, 'render_calendar_select' ),
			self::MENU_SLUG,
			'meh_bookings_section',
			array(
				'label_for'   => 'meh_event_enquiry_calendar',
				'description' => __( 'Website enquiries arrive as bookings on this calendar. Bookings here get a "Convert to booking" action.', 'marthrown-enquiry-hub' ),
			)
		);
	}

	/**
	 * Bookings section intro.
	 */
	public static function render_bookings_section_intro() {
		echo '<p>' . esc_html__( 'Map WP Booking System form fields to the guest name/email shown in the hub, and identify the Event Enquiry calendar.', 'marthrown-enquiry-hub' ) . '</p>';
	}

	/**
	 * Render a calendar <select> bound to an option.
	 *
	 * @param array $args Field args (label_for, description).
	 */
	public static function render_calendar_select( $args ) {
		$option   = $args['label_for'];
		$value    = (int) get_option( $option, 0 );
		$calendars = function_exists( 'wpbs_get_calendars' ) ? wpbs_get_calendars() : array();

		echo '<select id="' . esc_attr( $option ) . '" name="' . esc_attr( $option ) . '">';
		echo '<option value="0">' . esc_html__( '— None —', 'marthrown-enquiry-hub' ) . '</option>';
		foreach ( $calendars as $calendar ) {
			$id   = (int) $calendar->get( 'id' );
			$name = method_exists( $calendar, 'get_name' ) ? $calendar->get_name() : $calendar->get( 'name' );
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$id,
				selected( $value, $id, false ),
				esc_html( $name )
			);
		}
		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sanitizers.
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
	 * Mailbox sanitizer (email address).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_mailbox( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( $value && ! is_email( $value ) ) {
			add_settings_error(
				SourceEmail::OPT_MAILBOX,
				'meh_invalid_mailbox',
				__( 'The mailbox must be a valid email address.', 'marthrown-enquiry-hub' )
			);
			return (string) get_option( SourceEmail::OPT_MAILBOX, '' );
		}
		return $value;
	}

	/**
	 * Secret sanitizer.
	 *
	 * Keeps the existing stored secret when the mask (or an empty value) is
	 * submitted, so the field never has to render the real value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_secret( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || self::SECRET_MASK === $value ) {
			return (string) get_option( SourceEmail::OPT_SECRET, '' );
		}

		return self::encode_secret( sanitize_text_field( $value ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Secret storage helpers.
	 *
	 * The secret is lightly obfuscated at rest using AUTH_KEY as an XOR pad so
	 * it is not stored as plain text. This is obfuscation, not strong crypto —
	 * treat database access as sensitive regardless.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Retrieve the decoded secret for use by the Graph client.
	 *
	 * @return string
	 */
	public static function get_secret() {
		$stored = get_option( SourceEmail::OPT_SECRET, '' );
		return $stored ? self::decode_secret( $stored ) : '';
	}

	/**
	 * Obfuscate a secret for storage.
	 *
	 * @param string $plain Plain secret.
	 * @return string
	 */
	protected static function encode_secret( $plain ) {
		if ( ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return $plain;
		}
		return 'meh1:' . base64_encode( self::xor_cipher( $plain, AUTH_KEY ) );
	}

	/**
	 * Reverse encode_secret().
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	protected static function decode_secret( $stored ) {
		if ( 0 !== strpos( $stored, 'meh1:' ) ) {
			return $stored;
		}
		if ( ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 5 ), true );
		return false === $raw ? '' : self::xor_cipher( $raw, AUTH_KEY );
	}

	/**
	 * Symmetric XOR cipher.
	 *
	 * @param string $data Input bytes.
	 * @param string $key  Key bytes.
	 * @return string
	 */
	protected static function xor_cipher( $data, $key ) {
		$out     = '';
		$key_len = strlen( $key );
		$len     = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $data[ $i ] ^ $key[ $i % $key_len ];
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Renderers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Section intro copy.
	 */
	public static function render_graph_section_intro() {
		echo '<p>' . esc_html__( 'Credentials for the Entra (Azure AD) app registration used to poll a mailbox for incoming enquiries. The app needs the Mail.Read (and Mail.ReadWrite to mark messages read) application permission with admin consent.', 'marthrown-enquiry-hub' ) . '</p>';
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
	 * Render the secret field with a masked placeholder.
	 *
	 * @param array $args Field args.
	 */
	public static function render_secret_field( $args ) {
		$option    = $args['label_for'];
		$has_value = (bool) get_option( $option, '' );

		printf(
			'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $option ),
			$has_value ? esc_attr( self::SECRET_MASK ) : ''
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
		if ( $has_value ) {
			echo '<p class="description">' . esc_html__( 'A secret is saved. Leave the masked value to keep it, or type a new one to replace it.', 'marthrown-enquiry-hub' ) . '</p>';
		}
	}

	/**
	 * Render the settings page shell.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$last_poll = get_option( SourceEmail::OPT_LAST_POLL, '' );
		?>
		<div class="wrap meh-settings-wrap">
			<h1><?php esc_html_e( 'Enquiry Hub Settings', 'marthrown-enquiry-hub' ); ?></h1>

			<?php settings_errors(); ?>

			<?php if ( $last_poll ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: date/time of last mailbox poll */
						esc_html__( 'Last mailbox poll: %s', 'marthrown-enquiry-hub' ),
						esc_html( $last_poll )
					);
					?>
				</p>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
