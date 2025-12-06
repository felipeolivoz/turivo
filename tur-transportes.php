<?php
/**
 * Plugin Name: Tur Transportes
 * Plugin URI:
 * Description: Sistema de reservas de traslados turísticos
 * Version: 2.0.0
 * Author: Turivo
 * License: GPL v2 or later
 * Text Domain: tur-transportes
 */

// ============================
// DETECTOR DE BOM / ESPACIOS (solo en activación)
// ============================
function tt_scan_for_output_issues() {

    $root = plugin_dir_path(__FILE__);

    $dir = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($dir);

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;

        $path = $file->getPathname();
        $content = file_get_contents($path);

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            error_log("⚠️ BOM DETECTADO EN: $path");
        }

        if (preg_match('/^\s+<\?php/', $content)) {
            error_log("⚠️ ESPACIOS ANTES DE <?php EN: $path");
        }

        if (preg_match('/\?>\s+$/', $content)) {
            error_log("⚠️ ESPACIOS DESPUÉS DE ?> EN: $path");
        }
    }
}
register_activation_hook(__FILE__, 'tt_scan_for_output_issues');

if (!defined('ABSPATH')) exit;

// ============================
// CONSTANTES
// ============================
define('TT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TT_PLUGIN_VERSION', '1.0.5'); 

// ============================
// LIMPIEZA DE TRANSIENTS (ACTIVAR + DESACTIVAR)
// ============================
function tt_clear_old_tt_transients() {
    delete_transient('tt_active_transfer_types');
    delete_transient('tt_active_vehicle_types');
    delete_transient('tt_db_integrity_checked');
}

// ============================
// VERIFICACIÓN DE ENTORNO
// ============================
if (!function_exists('tt_tur_transportes_check_environment')) {
    function tt_tur_transportes_check_environment() {
        if (!is_admin()) return;

        $min_php = '7.4';
        if (version_compare(PHP_VERSION, $min_php, '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                sprintf(
                    'El plugin <strong>Tur Transportes</strong> requiere PHP %s o superior. Tu servidor está usando PHP %s.',
                    esc_html($min_php), esc_html(PHP_VERSION)
                )
            );
        }

        $required_extensions = ['curl', 'json'];
        $missing = [];

        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }

        if (!empty($missing) && WP_DEBUG) {
            error_log('Tur Transportes: Faltan extensiones: ' . implode(', ', $missing));
        }
    }
    add_action('admin_init', 'tt_tur_transportes_check_environment');
}

// ============================
// AUTOLOAD
// ============================
$composer_autoload = TT_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// ============================
// INCLUDES
// ============================
require_once TT_PLUGIN_PATH . 'includes/class-database.php';
require_once TT_PLUGIN_PATH . 'includes/class-admin.php';
require_once TT_PLUGIN_PATH . 'includes/class-frontend.php';
require_once TT_PLUGIN_PATH . 'includes/class-google-maps.php';

// Orden correcto: primero SERVICE, luego los handlers
require_once TT_PLUGIN_PATH . 'includes/class-tt-tbk-service.php';

require_once TT_PLUGIN_PATH . 'includes/class-transbank.php';
require_once TT_PLUGIN_PATH . 'includes/class-transbank-compatibility.php';
require_once TT_PLUGIN_PATH . 'includes/class-transbank-handler.php';

require_once TT_PLUGIN_PATH . 'includes/class-email-handler.php';
require_once TT_PLUGIN_PATH . 'includes/export-handler.php';


// ============================
// CLASE PRINCIPAL
// ============================
class TurTransportes {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        load_plugin_textdomain(
            'tur-transportes',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        $this->init_components();
    }

    private function init_components() {
        TT_Database::init();
        TT_Admin::init();
        TT_Frontend::init();
        TT_Google_Maps::init();

        if (class_exists('\Transbank\Webpay\WebpayPlus\Transaction')) {
            TT_Transbank::init();
        }

        TT_Transbank_Handler::init();
        TT_Email_Handler::init();
    }

    public function activate() {

        // Crear o actualizar tablas
        TT_Database::create_tables();

        // LIMPIAR TRANSIENTS AL ACTIVAR
        tt_clear_old_tt_transients();

        // Crear página TBK
        $this->create_transbank_response_page();

        flush_rewrite_rules();
    }

    public function deactivate() {

        // LIMPIAR TRANSIENTS AL DESACTIVAR
        tt_clear_old_tt_transients();

        flush_rewrite_rules();
    }

    private function create_transbank_response_page() {
        $slug = 'transbank-response';
        $existing = get_page_by_path($slug);

        if (!$existing) {
            wp_insert_post([
                'post_title'   => 'Respuesta de Pago',
                'post_content' => '[transbank_response]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ]);
        }
    }
}

TurTransportes::get_instance();

// ============================
// INTEGRIDAD BD
// ============================
add_action('plugins_loaded', 'tt_verify_database_integrity');
function tt_verify_database_integrity() {
    $checked = get_transient('tt_db_integrity_checked');
    if (is_admin() || !$checked) {
        if (class_exists('TT_Database')) TT_Database::sync_all_tables();
        set_transient('tt_db_integrity_checked', true, 24 * HOUR_IN_SECONDS);
    }
}
