<?php
/**
 * Plugin Name: De Verhuizing - Formulieren
 * Plugin URI: https://deverhuizing.nl
 * Description: Ontvangt en beheert formulierinzendingen (offerte, terugbel, contact) van de De Verhuizing website.
 * Version: 1.0.0
 * Author: De Verhuizing
 * License: GPL v2 or later
 * Text Domain: deverhuizing
 */

if (!defined('ABSPATH')) {
    exit;
}

class DeVerhuizingForms {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'create_tables']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('init', [$this, 'handle_cors']);
    }

    public function handle_cors() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($request_uri, '/wp-json/deverhuizing/') === false) {
            return;
        }

        $allowed = get_option('deverhuizing_allowed_origins', 'https://deverhuizing.nl,https://www.deverhuizing.nl');
        $origins = array_map('trim', explode(',', $allowed));
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        header("Vary: Origin");

        if (in_array($origin, $origins)) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Methods: POST, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type");
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }

    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_quotes = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dv_quote_requests (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) DEFAULT '',
            email varchar(200) NOT NULL,
            phone varchar(50) NOT NULL,
            move_from_address varchar(255) DEFAULT '',
            move_from_postcode varchar(20) DEFAULT '',
            move_from_city varchar(100) DEFAULT '',
            move_to_address varchar(255) DEFAULT '',
            move_to_postcode varchar(20) DEFAULT '',
            move_to_city varchar(100) DEFAULT '',
            move_type varchar(50) DEFAULT '',
            move_date varchar(50) DEFAULT '',
            additional_notes text DEFAULT '',
            status varchar(30) DEFAULT 'nieuw',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql_callbacks = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dv_callback_requests (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) DEFAULT '',
            phone varchar(50) NOT NULL,
            email varchar(200) DEFAULT '',
            preferred_time varchar(100) DEFAULT '',
            notes text DEFAULT '',
            status varchar(30) DEFAULT 'nieuw',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql_contact = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dv_contact_messages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            email varchar(200) NOT NULL,
            phone varchar(50) DEFAULT '',
            subject varchar(255) DEFAULT '',
            message text NOT NULL,
            status varchar(30) DEFAULT 'nieuw',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_quotes);
        dbDelta($sql_callbacks);
        dbDelta($sql_contact);

        add_option('deverhuizing_admin_email', get_option('admin_email'));
        add_option('deverhuizing_allowed_origins', 'https://deverhuizing.nl,https://www.deverhuizing.nl');
    }

    public function register_routes() {
        register_rest_route('deverhuizing/v1', '/quote', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_quote'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('deverhuizing/v1', '/callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('deverhuizing/v1', '/contact', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_contact'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_quote($request) {
        global $wpdb;
        $data = $request->get_json_params();

        $required = ['firstName', 'email', 'phone'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Veld '$field' is verplicht.", ['status' => 400]);
            }
        }

        $email = sanitize_email($data['email']);
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Ongeldig e-mailadres.', ['status' => 400]);
        }

        $clean = [
            'first_name' => sanitize_text_field($data['firstName']),
            'last_name' => sanitize_text_field($data['lastName'] ?? ''),
            'email' => $email,
            'phone' => sanitize_text_field($data['phone']),
            'move_from_address' => sanitize_text_field($data['moveFromAddress'] ?? ''),
            'move_from_postcode' => sanitize_text_field($data['moveFromPostcode'] ?? ''),
            'move_from_city' => sanitize_text_field($data['moveFromCity'] ?? ''),
            'move_to_address' => sanitize_text_field($data['moveToAddress'] ?? ''),
            'move_to_postcode' => sanitize_text_field($data['moveToPostcode'] ?? ''),
            'move_to_city' => sanitize_text_field($data['moveToCity'] ?? ''),
            'move_type' => sanitize_text_field($data['moveType'] ?? ''),
            'move_date' => sanitize_text_field($data['moveDate'] ?? ''),
            'additional_notes' => sanitize_textarea_field($data['additionalNotes'] ?? ''),
        ];

        $result = $wpdb->insert("{$wpdb->prefix}dv_quote_requests", $clean);
        if ($result === false) {
            return new WP_Error('db_error', 'Er ging iets mis bij het opslaan.', ['status' => 500]);
        }

        $this->send_notification('Nieuwe Offerte Aanvraag', $clean);

        return new WP_REST_Response(['success' => true, 'message' => 'Offerte aanvraag ontvangen.'], 200);
    }

    public function handle_callback($request) {
        global $wpdb;
        $data = $request->get_json_params();

        $required = ['firstName', 'phone'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Veld '$field' is verplicht.", ['status' => 400]);
            }
        }

        $clean = [
            'first_name' => sanitize_text_field($data['firstName']),
            'last_name' => sanitize_text_field($data['lastName'] ?? ''),
            'phone' => sanitize_text_field($data['phone']),
            'email' => sanitize_email($data['email'] ?? ''),
            'preferred_time' => sanitize_text_field($data['preferredTime'] ?? ''),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
        ];

        $result = $wpdb->insert("{$wpdb->prefix}dv_callback_requests", $clean);
        if ($result === false) {
            return new WP_Error('db_error', 'Er ging iets mis bij het opslaan.', ['status' => 500]);
        }

        $this->send_notification('Nieuw Terugbelverzoek', $clean);

        return new WP_REST_Response(['success' => true, 'message' => 'Terugbelverzoek ontvangen.'], 200);
    }

    public function handle_contact($request) {
        global $wpdb;
        $data = $request->get_json_params();

        $required = ['name', 'email', 'message'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Veld '$field' is verplicht.", ['status' => 400]);
            }
        }

        $email = sanitize_email($data['email']);
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Ongeldig e-mailadres.', ['status' => 400]);
        }

        $clean = [
            'name' => sanitize_text_field($data['name']),
            'email' => $email,
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'subject' => sanitize_text_field($data['subject'] ?? ''),
            'message' => sanitize_textarea_field($data['message']),
        ];

        $result = $wpdb->insert("{$wpdb->prefix}dv_contact_messages", $clean);
        if ($result === false) {
            return new WP_Error('db_error', 'Er ging iets mis bij het opslaan.', ['status' => 500]);
        }

        $this->send_notification('Nieuw Contactbericht', $clean);

        return new WP_REST_Response(['success' => true, 'message' => 'Bericht ontvangen.'], 200);
    }

    private function send_notification($subject, $data) {
        $admin_email = get_option('deverhuizing_admin_email', get_option('admin_email'));
        $body = "Er is een nieuw formulier ingevuld op deverhuizing.nl:\n\n";
        foreach ($data as $key => $value) {
            if (!empty($value) && is_string($value)) {
                $label = ucfirst(preg_replace('/([A-Z])/', ' $1', $key));
                $safe_value = str_replace(["\r", "\n"], ' ', sanitize_text_field($value));
                $body .= "$label: $safe_value\n";
            }
        }
        $body .= "\nBekijk alle inzendingen in je WordPress dashboard onder 'De Verhuizing'.";
        $safe_subject = str_replace(["\r", "\n"], '', "[De Verhuizing] $subject");
        wp_mail($admin_email, $safe_subject, $body);
    }

    public function add_admin_menu() {
        add_menu_page(
            'De Verhuizing',
            'De Verhuizing',
            'manage_options',
            'deverhuizing',
            [$this, 'render_admin_page'],
            'dashicons-truck',
            30
        );

        add_submenu_page('deverhuizing', 'Offerte Aanvragen', 'Offertes', 'manage_options', 'deverhuizing', [$this, 'render_admin_page']);
        add_submenu_page('deverhuizing', 'Terugbelverzoeken', 'Terugbellen', 'manage_options', 'deverhuizing-callbacks', [$this, 'render_callbacks_page']);
        add_submenu_page('deverhuizing', 'Contactberichten', 'Contact', 'manage_options', 'deverhuizing-contact', [$this, 'render_contact_page']);
        add_submenu_page('deverhuizing', 'Instellingen', 'Instellingen', 'manage_options', 'deverhuizing-settings', [$this, 'render_settings_page']);
    }

    public function render_admin_page() {
        global $wpdb;

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update("{$wpdb->prefix}dv_quote_requests",
                    ['status' => $new_status],
                    ['id' => intval($_POST['id'])]
                );
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_quote_requests ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'afgerond', 'geannuleerd'];
        $status_colors = ['nieuw' => '#2271b1', 'in_behandeling' => '#dba617', 'offerte_verstuurd' => '#00a32a', 'afgerond' => '#50575e', 'geannuleerd' => '#d63638'];
        ?>
        <div class="wrap">
            <h1>Offerte Aanvragen</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Telefoon</th>
                        <th>Van</th>
                        <th>Naar</th>
                        <th>Type</th>
                        <th>Datum</th>
                        <th>Status</th>
                        <th>Ontvangen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="10">Nog geen offerte aanvragen ontvangen.</td></tr>
                    <?php else: foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item->id); ?></td>
                            <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong></td>
                            <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                            <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                            <td><?php echo esc_html($item->move_from_city ?: $item->move_from_postcode); ?></td>
                            <td><?php echo esc_html($item->move_to_city ?: $item->move_to_postcode); ?></td>
                            <td><?php echo esc_html($item->move_type); ?></td>
                            <td><?php echo esc_html($item->move_date); ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('dv_update_status'); ?>
                                    <input type="hidden" name="dv_update_status" value="1">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <select name="status" onchange="this.form.submit()" style="border-left:3px solid <?php echo esc_attr($status_colors[$item->status] ?? '#50575e'); ?>">
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo esc_html(date('d-m-Y H:i', strtotime($item->created_at))); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_callbacks_page() {
        global $wpdb;

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update("{$wpdb->prefix}dv_callback_requests",
                    ['status' => $new_status],
                    ['id' => intval($_POST['id'])]
                );
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_callback_requests ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
        $status_colors = ['nieuw' => '#2271b1', 'in_behandeling' => '#dba617', 'afgerond' => '#50575e', 'geannuleerd' => '#d63638'];
        ?>
        <div class="wrap">
            <h1>Terugbelverzoeken</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Naam</th>
                        <th>Telefoon</th>
                        <th>Email</th>
                        <th>Voorkeurstijd</th>
                        <th>Notities</th>
                        <th>Status</th>
                        <th>Ontvangen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="8">Nog geen terugbelverzoeken ontvangen.</td></tr>
                    <?php else: foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item->id); ?></td>
                            <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong></td>
                            <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                            <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                            <td><?php echo esc_html($item->preferred_time); ?></td>
                            <td><?php echo esc_html($item->notes); ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('dv_update_status'); ?>
                                    <input type="hidden" name="dv_update_status" value="1">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <select name="status" onchange="this.form.submit()" style="border-left:3px solid <?php echo esc_attr($status_colors[$item->status] ?? '#50575e'); ?>">
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo esc_html(date('d-m-Y H:i', strtotime($item->created_at))); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_contact_page() {
        global $wpdb;

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update("{$wpdb->prefix}dv_contact_messages",
                    ['status' => $new_status],
                    ['id' => intval($_POST['id'])]
                );
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_contact_messages ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
        $status_colors = ['nieuw' => '#2271b1', 'in_behandeling' => '#dba617', 'afgerond' => '#50575e', 'geannuleerd' => '#d63638'];
        ?>
        <div class="wrap">
            <h1>Contactberichten</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Telefoon</th>
                        <th>Onderwerp</th>
                        <th>Bericht</th>
                        <th>Status</th>
                        <th>Ontvangen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="8">Nog geen contactberichten ontvangen.</td></tr>
                    <?php else: foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item->id); ?></td>
                            <td><strong><?php echo esc_html($item->name); ?></strong></td>
                            <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                            <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                            <td><?php echo esc_html($item->subject); ?></td>
                            <td><?php echo esc_html(wp_trim_words($item->message, 15)); ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('dv_update_status'); ?>
                                    <input type="hidden" name="dv_update_status" value="1">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <select name="status" onchange="this.form.submit()" style="border-left:3px solid <?php echo esc_attr($status_colors[$item->status] ?? '#50575e'); ?>">
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo esc_html(date('d-m-Y H:i', strtotime($item->created_at))); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (isset($_POST['dv_save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_save_settings')) {
            update_option('deverhuizing_admin_email', sanitize_email($_POST['admin_email']));
            update_option('deverhuizing_allowed_origins', sanitize_text_field($_POST['allowed_origins']));
            echo '<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>';
        }

        $admin_email = get_option('deverhuizing_admin_email', get_option('admin_email'));
        $allowed_origins = get_option('deverhuizing_allowed_origins', 'https://deverhuizing.nl,https://www.deverhuizing.nl');
        ?>
        <div class="wrap">
            <h1>De Verhuizing - Instellingen</h1>
            <form method="post">
                <?php wp_nonce_field('dv_save_settings'); ?>
                <input type="hidden" name="dv_save_settings" value="1">
                <table class="form-table">
                    <tr>
                        <th><label for="admin_email">Notificatie e-mail</label></th>
                        <td>
                            <input type="email" id="admin_email" name="admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                            <p class="description">E-mailadres waar meldingen van nieuwe aanvragen naartoe worden gestuurd.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="allowed_origins">Toegestane domeinen (CORS)</label></th>
                        <td>
                            <input type="text" id="allowed_origins" name="allowed_origins" value="<?php echo esc_attr($allowed_origins); ?>" class="large-text">
                            <p class="description">Komma-gescheiden lijst van domeinen die formulieren mogen insturen. Voorbeeld: https://deverhuizing.nl,https://www.deverhuizing.nl</p>
                        </td>
                    </tr>
                </table>
                <h2>API Endpoints</h2>
                <p>Gebruik deze URLs in je website-formulieren:</p>
                <table class="form-table">
                    <tr>
                        <th>Offerte aanvragen</th>
                        <td><code><?php echo esc_html(rest_url('deverhuizing/v1/quote')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Terugbelverzoeken</th>
                        <td><code><?php echo esc_html(rest_url('deverhuizing/v1/callback')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Contactberichten</th>
                        <td><code><?php echo esc_html(rest_url('deverhuizing/v1/contact')); ?></code></td>
                    </tr>
                </table>
                <?php submit_button('Opslaan'); ?>
            </form>
        </div>
        <?php
    }
}

DeVerhuizingForms::get_instance();
