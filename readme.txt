=== WooCommerce to Tiffulit Leads ===
Contributors: harunsekmen
Tags: woocommerce, webhook, leads, crm
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce order data to a Tiffulit-compatible lead endpoint and automate lead delivery from your WordPress store.

== Description ==

WooCommerce to Tiffulit Leads is a WordPress plugin for WooCommerce stores that need automatic lead delivery to a Tiffulit-compatible CRM or webhook endpoint.

It helps convert WooCommerce orders into lead records by sending customer details, order information, product names, and marketing attribution fields such as UTM values.

Features:
- Sends leads on Processing and/or Completed status.
- Maps billing data and products to lead fields.
- Sends UTM and campaign-related data when available.
- Creates Hebrew lead notes for order number, amount, and order status.
- Adds an English admin settings screen.
- Lets you add the API token from the WordPress admin without hardcoding it in the plugin.
- Stores only a privacy-safe summary of API responses in the activity log.
- Prevents duplicate sends when the same order status hook runs concurrently.
- Accepts only HTTPS endpoint URLs for safer API delivery.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Go to WooCommerce > Tiffulit Leads.
4. Enter your HTTPS endpoint URL and token from the admin screen.

== Changelog ==

= 1.0.0 =
* Initial release.
