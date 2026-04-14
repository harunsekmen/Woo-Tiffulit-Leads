# WooCommerce to Tiffulit Leads for WordPress

WooCommerce to Tiffulit Leads is a custom WordPress plugin that sends WooCommerce order data to a Tiffulit-compatible lead endpoint automatically. It is built for stores that want a simple WooCommerce lead integration, CRM lead sync, and order-to-lead automation without hardcoding API credentials in the plugin code.

## WooCommerce Lead Integration Features

- Send WooCommerce orders to a Tiffulit-compatible API endpoint automatically.
- Trigger lead creation on selected WooCommerce order statuses such as `processing` and `completed`.
- Map customer billing details, product names, UTM data, and order notes into the lead payload.
- Manage endpoint URL and API token from the WordPress admin dashboard.
- Keep API credentials out of the repository so the plugin can be published safely on GitHub.
- Prevent duplicate lead submissions when the same order event is triggered concurrently.
- Store an admin activity log with privacy-safe response summaries.
- Restrict endpoint configuration to `HTTPS` URLs only.

## Who This Plugin Is For

This plugin is useful for:

- WooCommerce stores that need automatic lead creation in an external CRM
- agencies building custom WooCommerce integrations for clients
- WordPress projects that need a lightweight webhook-style order sync
- businesses using paid traffic and wanting UTM parameters passed into their lead system

## How It Works

When an order reaches one of the selected WooCommerce statuses, the plugin prepares a lead payload using order data such as:

- customer name
- phone number
- email address
- city
- purchased products
- UTM source, campaign, content, and term data
- order amount and order status

The plugin then sends this data to your configured Tiffulit endpoint and records the result in the admin log.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress admin panel.
3. Go to `WooCommerce > Tiffulit Leads`.
4. Enter your `HTTPS` endpoint URL.
5. Enter your API token in the admin settings screen.
6. Choose which order statuses should trigger lead sending.
7. Save settings.

## Configuration Notes

- The API token is added from the WordPress dashboard.
- The token is not hardcoded in the plugin files.
- The saved token is hidden in the admin form.
- Leaving the token field empty keeps the currently saved token.
- If the endpoint or token is missing, the plugin skips sending and records the reason in the log.

## Security and Reliability

- No API token is stored inside the source code.
- Only secure `HTTPS` endpoints are accepted.
- Duplicate send protection is included for concurrent order events.
- Activity logs store a safe summary instead of raw API response bodies.
- Admin actions are protected with WordPress capability and nonce checks.

## Requirements

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher

## Plugin Use Case

If you need a WooCommerce to CRM integration, WooCommerce webhook alternative, or WordPress lead automation plugin for Tiffulit, this plugin is designed for that workflow.

## License

GPLv2 or later

## Contact

For custom WooCommerce integration work, WordPress plugin development, or support requests, you can reach me on LinkedIn:

[Harun Sekmen](https://www.linkedin.com/in/harunsekmen/)
