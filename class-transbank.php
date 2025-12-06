<?php
if (!defined('ABSPATH')) {
    exit;
}

class TT_Transbank {

    private static $instance = null;

    const INTEGRATION_COMMERCE_CODE = '597055555532';
    const INTEGRATION_API_KEY = '579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C';

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_notices', array(__CLASS__, 'maybe_show_admin_warnings'));
    }

    private static function log($message, $level = 'INFO') {
        return;
    }

    public static function is_available() {
        return class_exists('\Transbank\Webpay\WebpayPlus\Transaction');
    }

    public static function get_environment() {
        $env = get_option('tt_transbank_environment', 'integration');
        $env = is_string($env) ? strtolower(trim($env)) : 'integration';

        if (!in_array($env, array('integration', 'production'), true)) {
            $env = 'integration';
        }

        return $env;
    }

    public static function is_production() {
        return self::get_environment() === 'production';
    }

    public static function is_integration() {
        return self::get_environment() === 'integration';
    }

    public static function get_commerce_code() {
        if (self::is_integration()) {
            return self::INTEGRATION_COMMERCE_CODE;
        }

        $code = get_option('tt_transbank_commerce_code', '');
        $code = is_string($code) ? trim($code) : '';

        if (!preg_match('/^\d{12}$/', $code)) {
            return '';
        }

        return $code;
    }

    public static function get_api_key() {
        if (self::is_integration()) {
            return self::INTEGRATION_API_KEY;
        }

        $key = get_option('tt_transbank_api_key', '');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            return '';
        }

        return $key;
    }

    public static function configure_webpay() {
        if (!self::is_available()) {
            return false;
        }

        $env          = self::get_environment();
        $commerceCode = self::get_commerce_code();
        $apiKey       = self::get_api_key();

        if (empty($commerceCode) || empty($apiKey)) {
            return false;
        }

        try {
            if (class_exists('\Transbank\Webpay\WebpayPlus\WebpayPlus')) {
                if ($env === 'integration') {
                    \Transbank\Webpay\WebpayPlus\WebpayPlus::configureForIntegration($commerceCode, $apiKey);
                } else {
                    \Transbank\Webpay\WebpayPlus\WebpayPlus::configureForProduction($commerceCode, $apiKey);
                }
            } elseif (class_exists('\Transbank\Webpay\WebpayPlus')) {
                if ($env === 'integration') {
                    \Transbank\Webpay\WebpayPlus::configureForIntegration($commerceCode, $apiKey);
                } else {
                    \Transbank\Webpay\WebpayPlus::configureForProduction($commerceCode, $apiKey);
                }
            } else {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    public static function get_webpay_urls() {
        $response_url = home_url('/transbank-response');

        return array(
            'return_url' => $response_url,
            'final_url'  => $response_url,
        );
    }

    public static function check_requirements() {
        $errors = array();

        $required_extensions = array('curl', 'openssl', 'json');
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = sprintf(
                    __('La extensión PHP %s no está habilitada en el servidor.', 'tur-transportes'),
                    $ext
                );
            }
        }

        if (self::is_production() && !is_ssl()) {
            $errors[] = __('El sitio debe estar servido bajo HTTPS (SSL) para operar Webpay en producción.', 'tur-transportes');
        }

        if (!self::is_available()) {
            $errors[] = __('El SDK de Transbank (transbank/transbank-sdk) no está instalado o no se pudo cargar.', 'tur-transportes');
        }

        if (self::is_production()) {
            if (empty(self::get_commerce_code())) {
                $errors[] = __('El código de comercio de producción no está configurado correctamente.', 'tur-transportes');
            }
            if (empty(self::get_api_key())) {
                $errors[] = __('La API Key de producción no está configurada.', 'tur-transportes');
            }
        }

        return $errors;
    }

    public static function can_process_payments() {
        $errors = self::check_requirements();
        return empty($errors);
    }

    public static function maybe_show_admin_warnings() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos($screen->id, 'tur-transportes') === false) {
            return;
        }

        $errors = self::check_requirements();
        if (empty($errors)) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('Tur Transportes - Problemas con la configuración de Transbank', 'tur-transportes'); ?></strong></p>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?php echo wp_kses_post($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <?php esc_html_e('Revisa la sección "Transacciones Transbank" o la página de configuración del plugin para corregir estos problemas antes de ir a producción.', 'tur-transportes'); ?>
            </p>
        </div>
        <?php
    }
}
