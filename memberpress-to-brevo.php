<?php
/**
 * Plugin Name: MemberPress to Brevo Integration
 * Plugin URI: https://app.codeable.io/tasks/new?preferredContractor=49688
 * Description: Integrates MemberPress with Brevo (SendInBlue).
 * Version: 1.0
 * Author: Fahad Murtaza
 * Author URI: https://app.codeable.io/tasks/new?preferredContractor=49688
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Create Menu and Settings Page
function h2sp_plugin_menu() {
	error_log('MemberPress to Brevo: Adding admin menu');
	add_menu_page(
		'MemberPress to Brevo Settings',
		'MP to Brevo',
		'manage_options',
		'h2sp-settings',
		'h2sp_settings_page',
		'dashicons-admin-generic'
	);
}

add_action('admin_menu', 'h2sp_plugin_menu');

function h2sp_settings_page() {
	?>
    <div class="wrap">
        <h1>MemberPress to Brevo Settings</h1>
        <form method="post" action="options.php">
			<?php
			settings_fields('h2sp-settings-group');
			do_settings_sections('h2sp-settings-group');
			?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Brevo API Key</th>
                    <td><input type="text" name="h2sp_brevo_api_key" value="<?php echo esc_attr(get_option('h2sp_brevo_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Brevo List ID</th>
                    <td><input type="text" name="h2sp_brevo_list_id" value="<?php echo esc_attr(get_option('h2sp_brevo_list_id')); ?>" /></td>
                </tr>
            </table>
			<?php submit_button(); ?>
        </form>
    </div>
	<?php
}

function h2sp_register_settings() {
	error_log('MemberPress to Brevo: Registering settings');
	register_setting('h2sp-settings-group', 'h2sp_brevo_api_key');
	register_setting('h2sp-settings-group', 'h2sp_brevo_list_id');
}

add_action('admin_init', 'h2sp_register_settings');

// MemberPress Hook Handling
function h2sp_add_user_to_brevo($txn) {
	error_log('MemberPress to Brevo: Handling MemberPress signup event');

	$brevo_api_key = get_option('h2sp_brevo_api_key');
	$brevo_list_id = get_option('h2sp_brevo_list_id');

	if (!$brevo_api_key || !$brevo_list_id) {
		error_log('MemberPress to Brevo: API key or List ID is not set');
		return; // API key or List ID is not set
	}

	// Extract user email from the transaction object
	$user = get_userdata($txn->user_id);
	if ($user) {
		$user_email = $user->user_email;
		h2sp_add_to_brevo_list($user_email, $brevo_api_key, $brevo_list_id);
	} else {
		error_log('MemberPress to Brevo: Failed to retrieve user data from transaction');
	}
}

add_action('mepr-signup', 'h2sp_add_user_to_brevo');

// Brevo API Integration
function h2sp_add_to_brevo_list($email, $api_key, $list_id) {
	error_log("MemberPress to Brevo: Preparing to add email {$email} to Brevo list");

	$url = 'https://api.sendinblue.com/v3/contacts'; // Brevo API endpoint for adding contacts

	$headers = array(
		'Content-Type' => 'application/json',
		'api-key' => $api_key
	);

	$body = json_encode(array(
		'email' => $email,
		'listIds' => array((int)$list_id),
		// Add other parameters as needed, based on Brevo's API requirements
	));

	$response = wp_remote_post($url, array(
		'method' => 'POST',
		'headers' => $headers,
		'body' => $body,
		'data_format' => 'body'
	));

	if (is_wp_error($response)) {
		error_log('MemberPress to Brevo: Error in sending data to Brevo - ' . $response->get_error_message());
		return;
	}

	$response_body = wp_remote_retrieve_body($response);
	$data = json_decode($response_body);

	if (isset($data->id)) {
		error_log('MemberPress to Brevo: Successfully added email to Brevo list');
	} else {
		error_log('MemberPress to Brevo: Failed to add email to Brevo list - ' . $response_body);
	}
}
