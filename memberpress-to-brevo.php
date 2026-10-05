<?php
/**
 * Plugin Name: MemberPress to Brevo Integration
 * Plugin URI: https://app.codeable.io/tasks/new?preferredContractor=49688
 * Description: Integrates MemberPress with Brevo (SendInBlue).
 * Version: 1.1.0
 * Author: Fahad Murtaza
 * Author URI: https://app.codeable.io/tasks/new?preferredContractor=49688
 * Text Domain: memberpress-to-brevo
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mepr_Brevo {

	const OPTION       = 'mepr_brevo_settings';
	const FAILURES     = 'mepr_brevo_failures';
	const CRON_HOOK    = 'mepr_brevo_retry_sync';
	const MAX_ATTEMPTS = 5;
	const API_URL      = 'https://api.brevo.com/v3/contacts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'failure_notice' ) );
		add_action( 'admin_post_mepr_brevo_clear_failures', array( __CLASS__, 'clear_failures' ) );

		// MemberPress: a completed (paid or free) transaction = an active member.
		add_action( 'mepr-event-transaction-completed', array( __CLASS__, 'on_transaction_completed' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'retry' ), 10, 3 );
	}

	/* ---------------------------------------------------------------- settings */

	public static function get_settings() {
		$defaults = array( 'api_key' => '', 'list_id' => 0 );
		$saved    = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/** API key: wp-config constant wins over the (non-autoloaded) option. */
	public static function api_key() {
		if ( defined( 'MEPR_BREVO_API_KEY' ) && MEPR_BREVO_API_KEY ) {
			return (string) MEPR_BREVO_API_KEY;
		}

		return (string) self::get_settings()['api_key'];
	}

	public static function admin_menu() {
		add_options_page(
			__( 'MP to Brevo', 'memberpress-to-brevo' ),
			__( 'MP to Brevo', 'memberpress-to-brevo' ),
			'manage_options',
			'mepr-brevo',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'mepr_brevo', self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			'default'           => array(),
		) );
	}

	public static function sanitize_settings( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::get_settings();
		}

		$current = self::get_settings();
		$input   = is_array( $input ) ? $input : array();

		$api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';

		$clean = array(
			// Blank submission keeps the stored key (the form only shows the last 4 chars).
			'api_key' => '' === $api_key ? $current['api_key'] : $api_key,
			'list_id' => isset( $input['list_id'] ) ? absint( $input['list_id'] ) : 0,
		);

		// Make sure the option is stored with autoload=no: register_setting() creates it autoloaded.
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $clean, '', 'no' );
		}

		return $clean;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = self::get_settings();
		$const_key   = defined( 'MEPR_BREVO_API_KEY' ) && MEPR_BREVO_API_KEY;
		$stored_key  = (string) $settings['api_key'];
		$masked      = '' === $stored_key ? '' : str_repeat( '*', 8 ) . substr( $stored_key, - 4 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MemberPress to Brevo', 'memberpress-to-brevo' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'mepr_brevo' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mepr-brevo-api-key"><?php esc_html_e( 'Brevo API key', 'memberpress-to-brevo' ); ?></label></th>
						<td>
							<?php if ( $const_key ) : ?>
								<p><code>MEPR_BREVO_API_KEY</code> <?php esc_html_e( 'is defined in wp-config.php and takes precedence.', 'memberpress-to-brevo' ); ?></p>
							<?php else : ?>
								<input type="password" id="mepr-brevo-api-key" class="regular-text" autocomplete="new-password"
									name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value=""
									placeholder="<?php echo esc_attr( $masked ? $masked : 'xkeysib-...' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank to keep the current key. Prefer defining MEPR_BREVO_API_KEY in wp-config.php.', 'memberpress-to-brevo' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mepr-brevo-list-id"><?php esc_html_e( 'Brevo list ID', 'memberpress-to-brevo' ); ?></label></th>
						<td><input type="number" min="1" id="mepr-brevo-list-id" name="<?php echo esc_attr( self::OPTION ); ?>[list_id]" value="<?php echo esc_attr( $settings['list_id'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------- sync */

	/** @param MeprEvent $event */
	public static function on_transaction_completed( $event ) {
		$txn = is_object( $event ) && method_exists( $event, 'get_data' ) ? $event->get_data() : null;

		if ( empty( $txn ) || empty( $txn->user_id ) ) {
			return;
		}

		$user = get_userdata( (int) $txn->user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$attributes = array_filter( array(
			'FIRSTNAME' => $user->first_name,
			'LASTNAME'  => $user->last_name,
		) );

		self::sync_contact( $user->user_email, $attributes, 1 );
	}

	/**
	 * Upsert a contact into the configured list. Transient failures are retried via WP-Cron.
	 *
	 * @return true|WP_Error
	 */
	public static function sync_contact( $email, array $attributes, $attempt = 1 ) {
		$api_key = self::api_key();
		$list_id = (int) self::get_settings()['list_id'];

		if ( '' === $api_key || $list_id < 1 ) {
			return self::fail( $email, 'not_configured', 'API key or list ID is not configured.', false, $attributes, $attempt );
		}

		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 15,
			'headers' => array(
				'accept'       => 'application/json',
				'content-type' => 'application/json',
				'api-key'      => $api_key,
			),
			'body'    => wp_json_encode( array(
				'email'         => $email,
				'attributes'    => (object) $attributes,
				'listIds'       => array( $list_id ),
				'updateEnabled' => true,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return self::fail( $email, 'http_error', $response->get_error_message(), true, $attributes, $attempt );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $body['message'] ) ? (string) $body['message'] : 'HTTP ' . $code;
		$bcode   = isset( $body['code'] ) ? (string) $body['code'] : 'http_' . $code;

		// Rate limits and server errors are worth retrying; 400/401/403 are configuration problems.
		$retry = ( 429 === $code || $code >= 500 );

		return self::fail( $email, $bcode, $message, $retry, $attributes, $attempt );
	}

	public static function retry( $email, $attributes, $attempt ) {
		self::sync_contact( (string) $email, is_array( $attributes ) ? $attributes : array(), (int) $attempt );
	}

	private static function fail( $email, $code, $message, $retry, array $attributes, $attempt ) {
		if ( $retry && $attempt < self::MAX_ATTEMPTS ) {
			// 1, 4, 16, 64 minutes
			$delay = MINUTE_IN_SECONDS * pow( 4, $attempt - 1 );
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $email, $attributes, $attempt + 1 ) );
		} else {
			self::record_failure( $email, $code, $message );
		}

		// Never log the API key; the message from Brevo does not contain it.
		error_log( sprintf( 'memberpress-to-brevo: sync of %s failed (attempt %d, %s): %s', $email, $attempt, $code, $message ) );

		return new WP_Error( 'mepr_brevo_' . $code, $message );
	}

	private static function record_failure( $email, $code, $message ) {
		$failures = get_option( self::FAILURES, array() );
		$failures = is_array( $failures ) ? $failures : array();

		$failures[] = array(
			'time'    => current_time( 'mysql', true ),
			'email'   => $email,
			'code'    => $code,
			'message' => $message,
		);

		update_option( self::FAILURES, array_slice( $failures, - 50 ), false );
	}

	/* ---------------------------------------------------------------- notices */

	public static function failure_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failures = get_option( self::FAILURES, array() );

		if ( empty( $failures ) || ! is_array( $failures ) ) {
			return;
		}

		$last = end( $failures );
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=mepr_brevo_clear_failures' ), 'mepr_brevo_clear_failures' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <em>%s</em> &mdash; <a href="%s">%s</a> | <a href="%s">%s</a></p></div>',
			esc_html__( 'MemberPress to Brevo:', 'memberpress-to-brevo' ),
			esc_html( sprintf( _n( '%d contact could not be synced to Brevo. Last error:', '%d contacts could not be synced to Brevo. Last error:', count( $failures ), 'memberpress-to-brevo' ), count( $failures ) ) ),
			esc_html( $last['code'] . ': ' . $last['message'] ),
			esc_url( admin_url( 'options-general.php?page=mepr-brevo' ) ),
			esc_html__( 'Settings', 'memberpress-to-brevo' ),
			esc_url( $url ),
			esc_html__( 'Dismiss', 'memberpress-to-brevo' )
		);
	}

	public static function clear_failures() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'mepr_brevo_clear_failures' );
		delete_option( self::FAILURES );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}

Mepr_Brevo::init();
