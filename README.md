# MemberPress to Brevo Integration Plugin

This WordPress plugin integrates MemberPress with Brevo (formerly SendInBlue), allowing automatic addition of users' email addresses to a specified Brevo email list. This is particularly useful for users who sign up for newsletters or purchase courses through MemberPress.

## Features

- Automatically adds email addresses to a Brevo email list upon MemberPress sign-ups or newsletter subscriptions.
- Configurable through the WordPress admin area, allowing easy updates to API keys and list IDs.

## Installation

1. **Upload Plugin**: Upload the `memberpress-to-brevo-integration` folder to the `/wp-content/plugins/` directory.
2. **Activate Plugin**: Activate the plugin through the 'Plugins' menu in WordPress.
3. **Configure Settings**: Go to the 'MP to Brevo' settings page under the WordPress admin menu to configure the plugin.

## Configuration

1. **Brevo API Key**: Obtain your Brevo API key from your Brevo account.
2. **Brevo List ID**: Identify the list ID in Brevo where you want to add the email addresses.
3. **Enter API Key and List ID**: Navigate to the 'MP to Brevo' settings page in your WordPress admin area and enter the Brevo API Key and List ID.

## Usage

Once configured, the plugin will automatically add users' email addresses to the specified Brevo list in the following scenarios:

- When a user signs up for a course through MemberPress.
- When a user subscribes to a newsletter (additional integration may be required depending on the newsletter subscription method used).

## Customization

To customize the plugin for different triggers or additional functionality, modify the plugin files. Ensure you have adequate knowledge of WordPress action hooks and the Brevo API.

## Requirements

- WordPress
- MemberPress plugin
- Brevo (SendInBlue) account

## Disclaimer

This plugin is provided as-is without any guarantees or warranty. Use at your own risk. Ensure compliance with all applicable laws and Brevo's terms of service.

## Author

Fahad Murtaza
