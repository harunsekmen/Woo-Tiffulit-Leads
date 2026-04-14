<?php
/**
 * Plugin Name: WooCommerce to Tiffulit Leads
 * Plugin URI: https://harunsekmen.com/
 * Description: Sends WooCommerce orders to a Tiffulit lead endpoint and keeps an activity log in wp-admin.
 * Version: 1.0.0
 * Author: Harun Sekmen
 * Author URI: https://www.linkedin.com/in/harun-sekmen/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.2
 * Text Domain: woo-tiffulit-leads
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Woo_Tiffulit_Leads')) {
    final class Woo_Tiffulit_Leads {
        const VERSION = '1.0.0';
        const OPTION_KEY = 'twl_settings';
        const LOG_TABLE_SUFFIX = 'twl_activity_log';
        const META_SENT_AT = '_twl_lead_sent_at';
        const META_SENT_STATUS = '_twl_lead_sent_status';
        const SEND_LOCK_OPTION_PREFIX = 'twl_send_lock_';
        const SEND_LOCK_TTL = 300;

        /** @var Woo_Tiffulit_Leads|null */
        private static $instance = null;

        public static function instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action('plugins_loaded', [$this, 'init']);
        }

        public function init() {
            if (!class_exists('WooCommerce')) {
                return;
            }

            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_twl_clear_logs', [$this, 'handle_clear_logs']);
            add_filter('option_page_capability_twl_settings_group', [$this, 'get_settings_capability']);

            add_action('woocommerce_order_status_changed', [$this, 'maybe_send_lead'], 20, 4);
        }

        public static function activate() {
            self::create_log_table();

            if (!get_option(self::OPTION_KEY)) {
                add_option(self::OPTION_KEY, [
                    'endpoint' => 'https://app.tiffulit.co.il/api/leads/action/add',
                    'token' => '',
                    'statuses' => ['processing', 'completed'],
                ]);
            }
        }

        public static function deactivate() {
            // Intentionally left blank.
        }

        private static function create_log_table() {
            global $wpdb;

            $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $sql = "CREATE TABLE {$table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                order_id BIGINT UNSIGNED NULL,
                level VARCHAR(20) NOT NULL,
                event VARCHAR(100) NOT NULL,
                message TEXT NOT NULL,
                http_code INT NULL,
                response_text LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY order_id (order_id),
                KEY created_at (created_at)
            ) {$charset_collate};";

            dbDelta($sql);
        }

        public function register_admin_menu() {
            add_submenu_page(
                'woocommerce',
                __('Tiffulit Leads', 'woo-tiffulit-leads'),
                __('Tiffulit Leads', 'woo-tiffulit-leads'),
                'manage_woocommerce',
                'twl-tiffulit-leads',
                [$this, 'render_admin_page']
            );
        }

        public function register_settings() {
            register_setting(
                'twl_settings_group',
                self::OPTION_KEY,
                [$this, 'sanitize_settings']
            );
        }

        public function get_settings_capability() {
            return 'manage_woocommerce';
        }

        public function sanitize_settings($input) {
            $existing = $this->get_settings();
            $input = is_array($input) ? $input : [];

            $endpoint = isset($input['endpoint']) ? esc_url_raw(trim((string) $input['endpoint'])) : $existing['endpoint'];
            if (!$this->is_valid_endpoint($endpoint)) {
                add_settings_error(
                    self::OPTION_KEY,
                    'twl_invalid_endpoint',
                    __('Endpoint URL must use HTTPS.', 'woo-tiffulit-leads'),
                    'error'
                );
                $endpoint = $existing['endpoint'];
            }

            $token = $existing['token'];
            if (array_key_exists('token', $input)) {
                $submitted_token = trim((string) $input['token']);
                if ('' !== $submitted_token) {
                    $token = sanitize_text_field($submitted_token);
                }
            }

            $output = [
                'endpoint'   => $endpoint,
                'token'      => $token,
                'statuses'   => isset($input['statuses']) && is_array($input['statuses']) ? array_values(array_intersect(array_map('sanitize_key', $input['statuses']), ['processing', 'completed'])) : $existing['statuses'],
            ];

            if (empty($output['statuses'])) {
                $output['statuses'] = ['processing', 'completed'];
            }

            $this->log_event(null, 'info', 'settings_saved', 'Plugin settings were updated.');

            return $output;
        }

        public function get_settings() {
            $defaults = [
                'endpoint'   => 'https://app.tiffulit.co.il/api/leads/action/add',
                'token'      => '',
                'statuses'   => ['processing', 'completed'],
            ];

            $settings = get_option(self::OPTION_KEY, []);
            if (!is_array($settings)) {
                $settings = [];
            }

            return wp_parse_args($settings, $defaults);
        }

        public function render_admin_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'settings';
            $settings = $this->get_settings();
            $logs = $this->get_logs(100);
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__('Tiffulit Leads', 'woo-tiffulit-leads'); ?></h1>
                <?php settings_errors(self::OPTION_KEY); ?>
                <?php $this->render_admin_notices(); ?>

                <nav class="nav-tab-wrapper">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=twl-tiffulit-leads&tab=settings')); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Settings', 'woo-tiffulit-leads'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=twl-tiffulit-leads&tab=logs')); ?>" class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Activity Log', 'woo-tiffulit-leads'); ?></a>
                </nav>

                <?php if ('logs' === $tab) : ?>
                    <div style="margin-top: 20px;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Are you sure you want to clear the activity log?');">
                            <?php wp_nonce_field('twl_clear_logs_action', 'twl_clear_logs_nonce'); ?>
                            <input type="hidden" name="action" value="twl_clear_logs">
                            <button type="submit" class="button button-secondary"><?php echo esc_html__('Clear Log', 'woo-tiffulit-leads'); ?></button>
                        </form>
                    </div>

                    <table class="widefat striped" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Date', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('Order', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('Level', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('Event', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('Message', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('HTTP Code', 'woo-tiffulit-leads'); ?></th>
                                <th><?php echo esc_html__('Response', 'woo-tiffulit-leads'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($logs)) : ?>
                                <?php foreach ($logs as $log) : ?>
                                    <tr>
                                        <td><?php echo esc_html($log->created_at); ?></td>
                                        <td>
                                            <?php if (!empty($log->order_id)) : ?>
                                                <a href="<?php echo esc_url(admin_url('post.php?post=' . absint($log->order_id) . '&action=edit')); ?>">#<?php echo esc_html(absint($log->order_id)); ?></a>
                                            <?php else : ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(strtoupper((string) $log->level)); ?></td>
                                        <td><?php echo esc_html($log->event); ?></td>
                                        <td><?php echo esc_html($log->message); ?></td>
                                        <td><?php echo isset($log->http_code) ? esc_html((string) $log->http_code) : '—'; ?></td>
                                        <td><code style="white-space: pre-wrap; word-break: break-word;"><?php echo esc_html(wp_trim_words((string) $log->response_text, 30, '...')); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="7"><?php echo esc_html__('No log entries found.', 'woo-tiffulit-leads'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <form method="post" action="options.php" style="margin-top: 20px; max-width: 800px;">
                        <?php settings_fields('twl_settings_group'); ?>
                        <?php $option_name = self::OPTION_KEY; ?>

                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="twl_endpoint"><?php echo esc_html__('Endpoint URL', 'woo-tiffulit-leads'); ?></label></th>
                                    <td>
                                        <input name="<?php echo esc_attr($option_name); ?>[endpoint]" id="twl_endpoint" type="url" class="regular-text" value="<?php echo esc_attr($settings['endpoint']); ?>">
                                        <p class="description"><?php echo esc_html__('Only HTTPS endpoints are allowed.', 'woo-tiffulit-leads'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="twl_token"><?php echo esc_html__('Token', 'woo-tiffulit-leads'); ?></label></th>
                                    <td>
                                        <input name="<?php echo esc_attr($option_name); ?>[token]" id="twl_token" type="password" class="regular-text" value="" placeholder="<?php echo !empty($settings['token']) ? esc_attr__('Saved token is hidden. Enter a new token to replace it.', 'woo-tiffulit-leads') : ''; ?>" autocomplete="new-password" spellcheck="false">
                                        <p class="description"><?php echo esc_html__('The token is stored in WordPress options and never hardcoded in the plugin. Leave this field empty to keep the current token.', 'woo-tiffulit-leads'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Send lead on status', 'woo-tiffulit-leads'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[statuses][]" value="processing" <?php checked(in_array('processing', $settings['statuses'], true)); ?>> <?php echo esc_html__('Processing', 'woo-tiffulit-leads'); ?></label><br>
                                        <label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[statuses][]" value="completed" <?php checked(in_array('completed', $settings['statuses'], true)); ?>> <?php echo esc_html__('Completed', 'woo-tiffulit-leads'); ?></label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <?php submit_button(__('Save Settings', 'woo-tiffulit-leads')); ?>
                    </form>
                <?php endif; ?>
            </div>
            <?php
        }

        private function render_admin_notices() {
            $notice = isset($_GET['twl_notice']) ? sanitize_key((string) $_GET['twl_notice']) : '';

            if ('logs-cleared' === $notice) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Activity log cleared.', 'woo-tiffulit-leads') . '</p></div>';
            } elseif ('logs-clear-failed' === $notice) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Activity log could not be cleared.', 'woo-tiffulit-leads') . '</p></div>';
            }
        }

        public function handle_clear_logs() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You are not allowed to do this.', 'woo-tiffulit-leads'));
            }

            check_admin_referer('twl_clear_logs_action', 'twl_clear_logs_nonce');

            global $wpdb;
            $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
            $result = $wpdb->query("DELETE FROM {$table_name}");

            $notice = false === $result ? 'logs-clear-failed' : 'logs-cleared';

            wp_safe_redirect(add_query_arg('twl_notice', $notice, admin_url('admin.php?page=twl-tiffulit-leads&tab=logs')));
            exit;
        }

        public function maybe_send_lead($order_id, $old_status, $new_status, $order) {
            $settings = $this->get_settings();

            if (empty($settings['token']) || empty($settings['endpoint'])) {
                $this->log_event($order_id, 'error', 'settings_missing', 'Lead send skipped because token or endpoint is missing.');
                return;
            }

            if (!in_array($new_status, $settings['statuses'], true)) {
                $this->log_event($order_id, 'info', 'status_skipped', sprintf('Order status changed from %s to %s. No send rule matched.', $old_status, $new_status));
                return;
            }

            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order_id);
            }

            if (!$order) {
                $this->log_event($order_id, 'error', 'order_missing', 'Lead send failed because the order could not be loaded.');
                return;
            }

            if (!$this->acquire_send_lock($order_id)) {
                $this->log_event($order_id, 'info', 'send_locked', 'Lead send skipped because another send is already in progress for this order.');
                return;
            }

            try {
                $order = wc_get_order($order_id);

                if (!$order) {
                    $this->log_event($order_id, 'error', 'order_missing', 'Lead send failed because the order could not be loaded.');
                    return;
                }

                $already_sent_at = (string) $order->get_meta(self::META_SENT_AT);
                if (!empty($already_sent_at)) {
                    $this->log_event($order_id, 'info', 'duplicate_skipped', sprintf('Lead send skipped because the order was already sent at %s.', $already_sent_at));
                    return;
                }

                $payload = $this->build_payload($order, $settings['token']);

                $this->log_event($order_id, 'info', 'lead_send_started', sprintf('Lead send started for order status %s.', $new_status));

                $response = wp_safe_remote_post($settings['endpoint'], [
                    'timeout' => 20,
                    'body'    => $payload,
                ]);

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    $this->log_event($order_id, 'error', 'lead_send_failed', 'Lead send failed with WP_Error: ' . $error_message);
                    $order->add_order_note('Tiffulit lead send failed: ' . $error_message);
                    return;
                }

                $http_code = (int) wp_remote_retrieve_response_code($response);
                $response_summary = $this->summarize_response_body((string) wp_remote_retrieve_body($response));

                if ($http_code >= 200 && $http_code < 300) {
                    $order->update_meta_data(self::META_SENT_AT, current_time('mysql'));
                    $order->update_meta_data(self::META_SENT_STATUS, $new_status);
                    $order->save();

                    $this->log_event($order_id, 'info', 'lead_sent', sprintf('Lead sent successfully for order #%d.', $order_id), $http_code, $response_summary);
                    $order->add_order_note('Tiffulit lead sent successfully.');
                    return;
                }

                $this->log_event($order_id, 'error', 'lead_send_failed_http', sprintf('Lead send failed with HTTP code %d.', $http_code), $http_code, $response_summary);
                $order->add_order_note(sprintf('Tiffulit lead send failed. HTTP %d', $http_code));
            } finally {
                $this->release_send_lock($order_id);
            }
        }

        private function build_payload(WC_Order $order, $token) {
            $first_name = trim((string) $order->get_billing_first_name());
            $last_name  = trim((string) $order->get_billing_last_name());
            $full_name  = trim($first_name . ' ' . $last_name);

            $phone = trim((string) $order->get_billing_phone());
            $email = trim((string) $order->get_billing_email());
            $city  = trim((string) $order->get_billing_city());

            $product_names = [];
            foreach ($order->get_items() as $item) {
                if (is_callable([$item, 'get_name'])) {
                    $product_names[] = $item->get_name();
                }
            }
            $products_text = implode(', ', array_filter($product_names));

            $utm_source   = (string) $order->get_meta('utm_source');
            $utm_campaign = (string) $order->get_meta('utm_campaign');
            $utm_content  = (string) $order->get_meta('utm_content');
            $adset_name   = (string) $order->get_meta('adset_name');

            if ('' === $adset_name) {
                $adset_name = (string) $order->get_meta('utm_term');
            }

            $notes = implode(' | ', array_filter([
                'מספר הזמנה: #' . $order->get_id(),
                'סכום: ' . wc_format_decimal((float) $order->get_total(), 2) . ' ' . $order->get_currency(),
                'סטטוס הזמנה: ' . $this->translate_order_status_to_hebrew($order->get_status()),
            ]));

            $payload = [
                'token'         => $token,
                'name'          => $full_name !== '' ? $full_name : 'לקוח WooCommerce',
                'phone'         => $phone,
                'email'         => $email,
                'notes'         => $notes,
                'city'          => $city,
                'utm_source'    => $utm_source,
                'utm_campaign'  => $utm_campaign,
                'adset_name'    => $adset_name,
                'utm_content'   => $utm_content,
                'Interested_in' => $products_text,
            ];

            return array_filter($payload, static function ($value) {
                return $value !== '' && $value !== null;
            });
        }

        private function translate_order_status_to_hebrew($status) {
            $map = [
                'pending'    => 'ממתין לתשלום',
                'failed'     => 'נכשל',
                'on-hold'    => 'בהמתנה',
                'processing' => 'בטיפול',
                'completed'  => 'הושלם',
                'refunded'   => 'הוחזר',
                'cancelled'  => 'בוטל',
                'checkout-draft' => 'טיוטת תשלום',
            ];

            return isset($map[$status]) ? $map[$status] : $status;
        }

        private function is_valid_endpoint($endpoint) {
            if (empty($endpoint)) {
                return true;
            }

            $parts = wp_parse_url($endpoint);

            return is_array($parts)
                && !empty($parts['scheme'])
                && 'https' === strtolower((string) $parts['scheme'])
                && !empty($parts['host']);
        }

        private function acquire_send_lock($order_id) {
            $order_id = absint($order_id);
            if ($order_id < 1) {
                return false;
            }

            $lock_key = self::SEND_LOCK_OPTION_PREFIX . $order_id;
            $now = time();

            if (add_option($lock_key, (string) $now, '', 'no')) {
                return true;
            }

            $existing_timestamp = (int) get_option($lock_key);
            if ($existing_timestamp > 0 && ($now - $existing_timestamp) < self::SEND_LOCK_TTL) {
                return false;
            }

            delete_option($lock_key);

            return add_option($lock_key, (string) $now, '', 'no');
        }

        private function release_send_lock($order_id) {
            $order_id = absint($order_id);
            if ($order_id < 1) {
                return;
            }

            delete_option(self::SEND_LOCK_OPTION_PREFIX . $order_id);
        }

        private function summarize_response_body($response_body) {
            $response_body = trim((string) $response_body);

            if ('' === $response_body) {
                return '';
            }

            $decoded = json_decode($response_body, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $keys = array_slice(array_map('sanitize_key', array_keys($decoded)), 0, 10);

                return sprintf(
                    'JSON response received. Keys: %s',
                    empty($keys) ? 'none' : implode(', ', $keys)
                );
            }

            return sprintf(
                'Non-JSON response received (%d chars).',
                strlen($response_body)
            );
        }

        private function log_event($order_id, $level, $event, $message, $http_code = null, $response_text = null) {
            global $wpdb;
            $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

            $wpdb->insert(
                $table_name,
                [
                    'created_at'    => current_time('mysql'),
                    'order_id'      => $order_id ? absint($order_id) : null,
                    'level'         => sanitize_key($level),
                    'event'         => sanitize_key($event),
                    'message'       => wp_strip_all_tags((string) $message),
                    'http_code'     => null !== $http_code ? (int) $http_code : null,
                    'response_text' => null !== $response_text ? wp_strip_all_tags((string) $response_text) : null,
                ],
                [
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                ]
            );
        }

        private function get_logs($limit = 100) {
            global $wpdb;
            $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
            $limit = max(1, absint($limit));

            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", $limit));
        }
    }
}

register_activation_hook(__FILE__, ['Woo_Tiffulit_Leads', 'activate']);
register_deactivation_hook(__FILE__, ['Woo_Tiffulit_Leads', 'deactivate']);

Woo_Tiffulit_Leads::instance();
