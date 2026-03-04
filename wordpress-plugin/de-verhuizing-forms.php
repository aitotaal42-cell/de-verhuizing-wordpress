<?php
/**
 * Plugin Name: De Verhuizing - Formulieren
 * Plugin URI: https://deverhuizing.nl
 * Description: Ontvangt en beheert formulierinzendingen (offerte, terugbel, contact) van de De Verhuizing website.
 * Version: 2.0.0
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
        add_action('init', [$this, 'handle_cors']);
        add_action('admin_init', [$this, 'handle_export']);
    }

    public function enqueue_admin_styles($hook) {
        if (strpos($hook, 'deverhuizing') === false) return;
        wp_add_inline_style('wp-admin', '
            .dv-toolbar { display:flex; gap:8px; align-items:center; margin:12px 0; flex-wrap:wrap; }
            .dv-toolbar .button { display:inline-flex; align-items:center; gap:4px; }
            .dv-count { background:#2271b1; color:#fff; padding:2px 8px; border-radius:10px; font-size:12px; margin-left:4px; }
            .dv-check-all { width:18px; height:18px; }
            .dv-check-row { width:18px; height:18px; }
            .dv-detail-row td { background:#f6f7f7 !important; padding:12px 16px !important; }
            .dv-detail-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
            .dv-detail-grid div { font-size:13px; }
            .dv-detail-grid strong { display:block; color:#50575e; font-size:11px; text-transform:uppercase; margin-bottom:2px; }
            .dv-status-nieuw { border-left:3px solid #2271b1; }
            .dv-status-in_behandeling { border-left:3px solid #dba617; }
            .dv-status-offerte_verstuurd { border-left:3px solid #00a32a; }
            .dv-status-afgerond { border-left:3px solid #50575e; }
            .dv-status-geannuleerd { border-left:3px solid #d63638; }
        ');
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

    private function handle_bulk_delete($table, $nonce_action) {
        if (!isset($_POST['dv_bulk_action']) || $_POST['dv_bulk_action'] !== 'delete') return;
        if (!wp_verify_nonce($_POST['_wpnonce'], $nonce_action)) return;
        if (!current_user_can('manage_options')) return;
        if (empty($_POST['dv_selected']) || !is_array($_POST['dv_selected'])) return;

        global $wpdb;
        $ids = array_map('intval', $_POST['dv_selected']);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders)", ...$ids));

        $count = count($ids);
        echo "<div class='notice notice-success'><p>{$count} record(s) verwijderd.</p></div>";
    }

    private function handle_single_delete($table, $nonce_action) {
        if (!isset($_GET['dv_delete'])) return;
        if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) return;
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $id = intval($_GET['dv_delete']);
        $wpdb->delete($table, ['id' => $id]);

        echo "<div class='notice notice-success'><p>Record verwijderd.</p></div>";
    }

    public function handle_export() {
        if (!isset($_GET['dv_export'])) return;
        if (!current_user_can('manage_options')) return;
        if (!wp_verify_nonce($_GET['_wpnonce'], 'dv_export')) return;

        global $wpdb;
        $type = sanitize_text_field($_GET['dv_export']);
        $format = sanitize_text_field($_GET['dv_format'] ?? 'csv');

        switch ($type) {
            case 'quotes':
                $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_quote_requests ORDER BY created_at DESC", ARRAY_A);
                $filename = 'offerte-aanvragen';
                $headers = ['ID', 'Voornaam', 'Achternaam', 'Email', 'Telefoon', 'Van Adres', 'Van Postcode', 'Van Plaats', 'Naar Adres', 'Naar Postcode', 'Naar Plaats', 'Type', 'Datum', 'Opmerkingen', 'Status', 'Ontvangen'];
                $fields = ['id', 'first_name', 'last_name', 'email', 'phone', 'move_from_address', 'move_from_postcode', 'move_from_city', 'move_to_address', 'move_to_postcode', 'move_to_city', 'move_type', 'move_date', 'additional_notes', 'status', 'created_at'];
                break;
            case 'callbacks':
                $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_callback_requests ORDER BY created_at DESC", ARRAY_A);
                $filename = 'terugbelverzoeken';
                $headers = ['ID', 'Voornaam', 'Achternaam', 'Telefoon', 'Email', 'Voorkeurstijd', 'Notities', 'Status', 'Ontvangen'];
                $fields = ['id', 'first_name', 'last_name', 'phone', 'email', 'preferred_time', 'notes', 'status', 'created_at'];
                break;
            case 'contact':
                $items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dv_contact_messages ORDER BY created_at DESC", ARRAY_A);
                $filename = 'contactberichten';
                $headers = ['ID', 'Naam', 'Email', 'Telefoon', 'Onderwerp', 'Bericht', 'Status', 'Ontvangen'];
                $fields = ['id', 'name', 'email', 'phone', 'subject', 'message', 'status', 'created_at'];
                break;
            default:
                return;
        }

        if ($format === 'xlsx') {
            $this->export_xlsx($items, $headers, $fields, $filename);
        } else {
            $this->export_csv($items, $headers, $fields, $filename);
        }
        exit;
    }

    private function export_csv($items, $headers, $fields, $filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, $headers, ';');

        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $item[$field] ?? '';
            }
            fputcsv($output, $row, ';');
        }

        fclose($output);
    }

    private function export_xlsx($items, $headers, $fields, $filename) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.xlsx"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $sheet_data = [];
        $sheet_data[] = $headers;
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $item[$field] ?? '';
            }
            $sheet_data[] = $row;
        }

        echo $this->generate_xlsx($sheet_data, $filename);
    }

    private function generate_xlsx($data, $sheet_name = 'Sheet1') {
        $col_letters = range('A', 'Z');
        $rows_xml = '';
        foreach ($data as $r => $row) {
            $cells_xml = '';
            foreach ($row as $c => $value) {
                $col = $col_letters[$c] ?? 'A';
                $row_num = $r + 1;
                $ref = $col . $row_num;
                $escaped = htmlspecialchars((string)$value, ENT_XML1, 'UTF-8');
                if ($r === 0) {
                    $cells_xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $escaped . '</t></is></c>';
                } else {
                    $cells_xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
                }
            }
            $rows_xml .= '<row r="' . ($r + 1) . '">' . $cells_xml . '</row>';
        }

        $num_cols = count($data[0] ?? []);
        $cols_xml = '';
        for ($i = 1; $i <= $num_cols; $i++) {
            $cols_xml .= '<col min="' . $i . '" max="' . $i . '" width="18" customWidth="1"/>';
        }

        $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols>' . $cols_xml . '</cols>'
            . '<sheetData>' . $rows_xml . '</sheetData>'
            . '</worksheet>';

        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1A365D"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center"/></xf></cellXfs>'
            . '</styleSheet>';

        $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheet_name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $temp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $content_types);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook_xml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->addFromString('xl/styles.xml', $styles_xml);
        $zip->close();

        $content = file_get_contents($temp);
        unlink($temp);
        return $content;
    }

    private function render_export_buttons($type) {
        $csv_url = wp_nonce_url(admin_url('admin.php?dv_export=' . $type . '&dv_format=csv'), 'dv_export');
        $xlsx_url = wp_nonce_url(admin_url('admin.php?dv_export=' . $type . '&dv_format=xlsx'), 'dv_export');
        echo '<a href="' . esc_url($csv_url) . '" class="button"><span class="dashicons dashicons-media-spreadsheet" style="margin-top:4px"></span> Export CSV</a>';
        echo '<a href="' . esc_url($xlsx_url) . '" class="button"><span class="dashicons dashicons-media-spreadsheet" style="margin-top:4px"></span> Export XLSX</a>';
    }

    private function render_select_js() {
        ?>
        <script>
        (function(){
            var checkAll = document.querySelector('.dv-check-all');
            if (!checkAll) return;
            checkAll.addEventListener('change', function(){
                var boxes = document.querySelectorAll('.dv-check-row');
                for(var i=0;i<boxes.length;i++) boxes[i].checked = this.checked;
                updateCount();
            });
            document.querySelectorAll('.dv-check-row').forEach(function(cb){
                cb.addEventListener('change', updateCount);
            });
            function updateCount(){
                var checked = document.querySelectorAll('.dv-check-row:checked').length;
                var el = document.getElementById('dv-selected-count');
                if(el) el.textContent = checked > 0 ? checked + ' geselecteerd' : '';
                var btn = document.getElementById('dv-delete-btn');
                if(btn) btn.style.display = checked > 0 ? 'inline-flex' : 'none';
            }
            var form = document.getElementById('dv-bulk-form');
            if(form) form.addEventListener('submit', function(e){
                var checked = document.querySelectorAll('.dv-check-row:checked').length;
                if(checked === 0){ e.preventDefault(); return; }
                if(!confirm('Weet je zeker dat je ' + checked + ' record(s) wilt verwijderen?')) e.preventDefault();
            });
            document.querySelectorAll('.dv-toggle-detail').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id = this.dataset.id;
                    var row = document.getElementById('dv-detail-' + id);
                    if(row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
                });
            });
        })();
        </script>
        <?php
    }

    public function render_admin_page() {
        global $wpdb;
        $table = "{$wpdb->prefix}dv_quote_requests";

        $this->handle_bulk_delete($table, 'dv_bulk_quotes');
        $this->handle_single_delete($table, 'dv_delete_quote');

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update($table, ['status' => $new_status], ['id' => intval($_POST['id'])]);
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'afgerond', 'geannuleerd'];
        ?>
        <div class="wrap">
            <h1>Offerte Aanvragen <span class="dv-count"><?php echo count($items); ?></span></h1>
            <div class="dv-toolbar">
                <?php $this->render_export_buttons('quotes'); ?>
                <span id="dv-selected-count" style="margin-left:8px;color:#50575e"></span>
            </div>
            <form method="post" id="dv-bulk-form">
                <?php wp_nonce_field('dv_bulk_quotes'); ?>
                <input type="hidden" name="dv_bulk_action" value="delete">
                <button type="submit" id="dv-delete-btn" class="button button-link-delete" style="display:none;margin-bottom:8px">
                    <span class="dashicons dashicons-trash" style="margin-top:4px"></span> Geselecteerde verwijderen
                </button>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30px"><input type="checkbox" class="dv-check-all"></th>
                            <th style="width:30px">#</th>
                            <th>Naam</th>
                            <th>Email</th>
                            <th>Telefoon</th>
                            <th>Van</th>
                            <th>Naar</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Ontvangen</th>
                            <th style="width:80px">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="11">Nog geen offerte aanvragen ontvangen.</td></tr>
                        <?php else: foreach ($items as $item): ?>
                            <tr class="dv-status-<?php echo esc_attr($item->status); ?>">
                                <td><input type="checkbox" class="dv-check-row" name="dv_selected[]" value="<?php echo esc_attr($item->id); ?>"></td>
                                <td><?php echo esc_html($item->id); ?></td>
                                <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong></td>
                                <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                                <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                                <td><?php echo esc_html($item->move_from_city ?: $item->move_from_postcode); ?></td>
                                <td><?php echo esc_html($item->move_to_city ?: $item->move_to_postcode); ?></td>
                                <td><?php echo esc_html($item->move_type); ?></td>
                                <td>
                                    <?php echo '</form>'; ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('dv_update_status'); ?>
                                        <input type="hidden" name="dv_update_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                        <select name="status" onchange="this.form.submit()" style="font-size:12px;padding:2px 4px">
                                            <?php foreach ($statuses as $s): ?>
                                                <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <?php echo '<form method="post" id="dv-bulk-form-cont">'; ?>
                                </td>
                                <td><?php echo esc_html(date('d-m-Y', strtotime($item->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small dv-toggle-detail" data-id="<?php echo esc_attr($item->id); ?>" title="Details">
                                        <span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;margin-top:3px"></span>
                                    </button>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=deverhuizing&dv_delete=' . $item->id), 'dv_delete_quote')); ?>" class="button button-small button-link-delete" onclick="return confirm('Weet je zeker dat je dit record wilt verwijderen?')" title="Verwijderen">
                                        <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;margin-top:3px"></span>
                                    </a>
                                </td>
                            </tr>
                            <tr id="dv-detail-<?php echo esc_attr($item->id); ?>" class="dv-detail-row" style="display:none">
                                <td colspan="11">
                                    <div class="dv-detail-grid">
                                        <div><strong>Van adres</strong><?php echo esc_html($item->move_from_address); ?></div>
                                        <div><strong>Van postcode</strong><?php echo esc_html($item->move_from_postcode); ?></div>
                                        <div><strong>Van plaats</strong><?php echo esc_html($item->move_from_city); ?></div>
                                        <div><strong>Naar adres</strong><?php echo esc_html($item->move_to_address); ?></div>
                                        <div><strong>Naar postcode</strong><?php echo esc_html($item->move_to_postcode); ?></div>
                                        <div><strong>Naar plaats</strong><?php echo esc_html($item->move_to_city); ?></div>
                                        <div><strong>Verhuisdatum</strong><?php echo esc_html($item->move_date); ?></div>
                                        <div><strong>Ontvangen</strong><?php echo esc_html(date('d-m-Y H:i', strtotime($item->created_at))); ?></div>
                                        <div><strong>Opmerkingen</strong><?php echo esc_html($item->additional_notes ?: '-'); ?></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php $this->render_select_js();
    }

    public function render_callbacks_page() {
        global $wpdb;
        $table = "{$wpdb->prefix}dv_callback_requests";

        $this->handle_bulk_delete($table, 'dv_bulk_callbacks');
        $this->handle_single_delete($table, 'dv_delete_callback');

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update($table, ['status' => $new_status], ['id' => intval($_POST['id'])]);
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
        ?>
        <div class="wrap">
            <h1>Terugbelverzoeken <span class="dv-count"><?php echo count($items); ?></span></h1>
            <div class="dv-toolbar">
                <?php $this->render_export_buttons('callbacks'); ?>
                <span id="dv-selected-count" style="margin-left:8px;color:#50575e"></span>
            </div>
            <form method="post" id="dv-bulk-form">
                <?php wp_nonce_field('dv_bulk_callbacks'); ?>
                <input type="hidden" name="dv_bulk_action" value="delete">
                <button type="submit" id="dv-delete-btn" class="button button-link-delete" style="display:none;margin-bottom:8px">
                    <span class="dashicons dashicons-trash" style="margin-top:4px"></span> Geselecteerde verwijderen
                </button>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30px"><input type="checkbox" class="dv-check-all"></th>
                            <th style="width:30px">#</th>
                            <th>Naam</th>
                            <th>Telefoon</th>
                            <th>Email</th>
                            <th>Voorkeurstijd</th>
                            <th>Notities</th>
                            <th>Status</th>
                            <th>Ontvangen</th>
                            <th style="width:80px">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="10">Nog geen terugbelverzoeken ontvangen.</td></tr>
                        <?php else: foreach ($items as $item): ?>
                            <tr class="dv-status-<?php echo esc_attr($item->status); ?>">
                                <td><input type="checkbox" class="dv-check-row" name="dv_selected[]" value="<?php echo esc_attr($item->id); ?>"></td>
                                <td><?php echo esc_html($item->id); ?></td>
                                <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong></td>
                                <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                                <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                                <td><?php echo esc_html($item->preferred_time); ?></td>
                                <td><?php echo esc_html($item->notes); ?></td>
                                <td>
                                    <?php echo '</form>'; ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('dv_update_status'); ?>
                                        <input type="hidden" name="dv_update_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                        <select name="status" onchange="this.form.submit()" style="font-size:12px;padding:2px 4px">
                                            <?php foreach ($statuses as $s): ?>
                                                <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <?php echo '<form method="post" id="dv-bulk-form-cont">'; ?>
                                </td>
                                <td><?php echo esc_html(date('d-m-Y', strtotime($item->created_at))); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=deverhuizing-callbacks&dv_delete=' . $item->id), 'dv_delete_callback')); ?>" class="button button-small button-link-delete" onclick="return confirm('Weet je zeker dat je dit record wilt verwijderen?')" title="Verwijderen">
                                        <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;margin-top:3px"></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php $this->render_select_js();
    }

    public function render_contact_page() {
        global $wpdb;
        $table = "{$wpdb->prefix}dv_contact_messages";

        $this->handle_bulk_delete($table, 'dv_bulk_contact');
        $this->handle_single_delete($table, 'dv_delete_contact');

        if (isset($_POST['dv_update_status']) && wp_verify_nonce($_POST['_wpnonce'], 'dv_update_status') && current_user_can('manage_options')) {
            $valid_statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
            $new_status = sanitize_text_field($_POST['status']);
            if (in_array($new_status, $valid_statuses)) {
                $wpdb->update($table, ['status' => $new_status], ['id' => intval($_POST['id'])]);
                echo '<div class="notice notice-success"><p>Status bijgewerkt.</p></div>';
            }
        }

        $items = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        $statuses = ['nieuw', 'in_behandeling', 'afgerond', 'geannuleerd'];
        ?>
        <div class="wrap">
            <h1>Contactberichten <span class="dv-count"><?php echo count($items); ?></span></h1>
            <div class="dv-toolbar">
                <?php $this->render_export_buttons('contact'); ?>
                <span id="dv-selected-count" style="margin-left:8px;color:#50575e"></span>
            </div>
            <form method="post" id="dv-bulk-form">
                <?php wp_nonce_field('dv_bulk_contact'); ?>
                <input type="hidden" name="dv_bulk_action" value="delete">
                <button type="submit" id="dv-delete-btn" class="button button-link-delete" style="display:none;margin-bottom:8px">
                    <span class="dashicons dashicons-trash" style="margin-top:4px"></span> Geselecteerde verwijderen
                </button>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30px"><input type="checkbox" class="dv-check-all"></th>
                            <th style="width:30px">#</th>
                            <th>Naam</th>
                            <th>Email</th>
                            <th>Telefoon</th>
                            <th>Onderwerp</th>
                            <th>Bericht</th>
                            <th>Status</th>
                            <th>Ontvangen</th>
                            <th style="width:80px">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="10">Nog geen contactberichten ontvangen.</td></tr>
                        <?php else: foreach ($items as $item): ?>
                            <tr class="dv-status-<?php echo esc_attr($item->status); ?>">
                                <td><input type="checkbox" class="dv-check-row" name="dv_selected[]" value="<?php echo esc_attr($item->id); ?>"></td>
                                <td><?php echo esc_html($item->id); ?></td>
                                <td><strong><?php echo esc_html($item->name); ?></strong></td>
                                <td><a href="mailto:<?php echo esc_attr($item->email); ?>"><?php echo esc_html($item->email); ?></a></td>
                                <td><a href="tel:<?php echo esc_attr($item->phone); ?>"><?php echo esc_html($item->phone); ?></a></td>
                                <td><?php echo esc_html($item->subject); ?></td>
                                <td><?php echo esc_html(wp_trim_words($item->message, 15)); ?></td>
                                <td>
                                    <?php echo '</form>'; ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('dv_update_status'); ?>
                                        <input type="hidden" name="dv_update_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                        <select name="status" onchange="this.form.submit()" style="font-size:12px;padding:2px 4px">
                                            <?php foreach ($statuses as $s): ?>
                                                <option value="<?php echo esc_attr($s); ?>" <?php selected($item->status, $s); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <?php echo '<form method="post" id="dv-bulk-form-cont">'; ?>
                                </td>
                                <td><?php echo esc_html(date('d-m-Y', strtotime($item->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small dv-toggle-detail" data-id="<?php echo esc_attr($item->id); ?>" title="Details">
                                        <span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;margin-top:3px"></span>
                                    </button>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=deverhuizing-contact&dv_delete=' . $item->id), 'dv_delete_contact')); ?>" class="button button-small button-link-delete" onclick="return confirm('Weet je zeker dat je dit record wilt verwijderen?')" title="Verwijderen">
                                        <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;margin-top:3px"></span>
                                    </a>
                                </td>
                            </tr>
                            <tr id="dv-detail-<?php echo esc_attr($item->id); ?>" class="dv-detail-row" style="display:none">
                                <td colspan="10">
                                    <div><strong>Volledig bericht:</strong></div>
                                    <div style="margin-top:8px;white-space:pre-wrap;max-width:800px"><?php echo esc_html($item->message); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php $this->render_select_js();
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
