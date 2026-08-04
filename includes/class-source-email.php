<?php
/**
 * Source: Email (Microsoft Graph API).
 *
 * Authenticates against Microsoft Graph using an Entra (Azure AD) app
 * registration's client credentials (stored as WP options), pulls UNREAD
 * messages from a specified mail folder, logs each into FluentCRM via
 * FluentCrmWriter::log_enquiry() with source 'email', then marks the message
 * as read.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SourceEmail
 */
class SourceEmail {

	const SOURCE          = 'email';
	const OPT_TENANT      = 'meh_graph_tenant_id';
	const OPT_CLIENT      = 'meh_graph_client_id';
	const OPT_SECRET      = 'meh_graph_client_secret';
	const OPT_MAILBOX     = 'meh_graph_mailbox';
	const OPT_FOLDER      = 'meh_graph_folder';
	const OPT_LAST_POLL   = 'meh_graph_last_poll';
	const TOKEN_TRANSIENT = 'meh_graph_token';

	const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Poll the mailbox folder for unread messages and log them.
	 *
	 * @return int|\WP_Error Count of processed messages, or WP_Error.
	 */
	public static function poll() {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$mailbox = get_option( self::OPT_MAILBOX, '' );
		if ( ! $mailbox ) {
			return new \WP_Error( 'meh_no_mailbox', __( 'No Graph mailbox configured.', 'marthrown-enquiry-hub' ) );
		}

		$folder   = get_option( self::OPT_FOLDER, 'inbox' );
		$messages = self::fetch_unread( $token, $mailbox, $folder );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		$processed = 0;
		foreach ( $messages as $message ) {
			$logged = self::record_message( $message );
			if ( is_wp_error( $logged ) ) {
				continue;
			}

			// Mark as read only after a successful log, so failures get retried.
			if ( ! empty( $message['id'] ) ) {
				self::mark_read( $token, $mailbox, $message['id'] );
			}
			$processed++;
		}

		update_option( self::OPT_LAST_POLL, current_time( 'mysql' ), false );

		return $processed;
	}

	/**
	 * Obtain (and cache) an OAuth2 client-credentials access token.
	 *
	 * @return string|\WP_Error Bearer token or error.
	 */
	protected static function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$tenant = get_option( self::OPT_TENANT, '' );
		$client = get_option( self::OPT_CLIENT, '' );
		// Secret is obfuscated at rest; decode via the settings helper.
		$secret = class_exists( __NAMESPACE__ . '\\Settings' )
			? Settings::get_secret()
			: get_option( self::OPT_SECRET, '' );

		if ( ! $tenant || ! $client || ! $secret ) {
			return new \WP_Error( 'meh_graph_config', __( 'Microsoft Graph credentials are not fully configured.', 'marthrown-enquiry-hub' ) );
		}

		$endpoint = sprintf( 'https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode( $tenant ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $client,
					'client_secret' => $secret,
					'scope'         => 'https://graph.microsoft.com/.default',
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'meh_graph_token', __( 'Could not obtain a Graph access token.', 'marthrown-enquiry-hub' ) );
		}

		$expires = isset( $body['expires_in'] ) ? absint( $body['expires_in'] ) : 3600;
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, $expires - 60 ) );

		return $body['access_token'];
	}

	/**
	 * Fetch unread messages from a specific mail folder.
	 *
	 * @param string $token   Bearer token.
	 * @param string $mailbox Mailbox (UPN / email).
	 * @param string $folder  Folder id or well-known name (e.g. 'inbox').
	 * @return array|\WP_Error List of message arrays.
	 */
	protected static function fetch_unread( $token, $mailbox, $folder ) {
		$url = sprintf(
			'%s/users/%s/mailFolders/%s/messages?$filter=%s&$top=25&$orderby=receivedDateTime asc',
			self::GRAPH_BASE,
			rawurlencode( $mailbox ),
			rawurlencode( $folder ),
			rawurlencode( 'isRead eq false' )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['value'] ) || ! is_array( $body['value'] ) ) {
			return new \WP_Error( 'meh_graph_fetch', __( 'Unexpected Graph API response when listing messages.', 'marthrown-enquiry-hub' ) );
		}

		return $body['value'];
	}

	/**
	 * Mark a message as read.
	 *
	 * @param string $token      Bearer token.
	 * @param string $mailbox    Mailbox.
	 * @param string $message_id Graph message id.
	 * @return bool
	 */
	protected static function mark_read( $token, $mailbox, $message_id ) {
		$url = sprintf(
			'%s/users/%s/messages/%s',
			self::GRAPH_BASE,
			rawurlencode( $mailbox ),
			rawurlencode( $message_id )
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PATCH',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'isRead' => true ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Log a single Graph message into FluentCRM.
	 *
	 * @param array $message Graph message resource.
	 * @return int|\WP_Error
	 */
	protected static function record_message( array $message ) {
		$from_email = '';
		$from_name  = '';
		if ( isset( $message['from']['emailAddress'] ) ) {
			$from_email = isset( $message['from']['emailAddress']['address'] ) ? $message['from']['emailAddress']['address'] : '';
			$from_name  = isset( $message['from']['emailAddress']['name'] ) ? $message['from']['emailAddress']['name'] : '';
		}

		if ( ! $from_email ) {
			return new \WP_Error( 'meh_graph_no_from', __( 'Message has no sender address.', 'marthrown-enquiry-hub' ) );
		}

		$subject = isset( $message['subject'] ) ? $message['subject'] : __( '(no subject)', 'marthrown-enquiry-hub' );
		$preview = isset( $message['bodyPreview'] ) ? $message['bodyPreview'] : '';
		$body    = trim( $subject . "\n\n" . $preview );

		return FluentCrmWriter::log_enquiry( $from_email, $from_name, $body, self::SOURCE );
	}
}
