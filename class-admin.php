<?php

class TT_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
    }

    /**
     * Crear menú y submenús del plugin
     */
    public static function add_admin_menu() {

        // Menú principal
        add_menu_page(
            'Tur Transportes',
            'Tur Transportes',
            'manage_options',
            'tur-transportes',
            array(__CLASS__, 'admin_dashboard'),
            'dashicons-location-alt',
            30
        );

        // Submenú: Configuración
        add_submenu_page(
            'tur-transportes',
            'Configuración',
            'Configuración',
            'manage_options',
            'tt-settings',
            array(__CLASS__, 'settings_page')
        );

        // Submenú: Tipos de Vehículos
        add_submenu_page(
            'tur-transportes',
            'Tipos de Vehículos',
            'Tipos de Vehículos',
            'manage_options',
            'tt-vehicles',
            array(__CLASS__, 'vehicles_page')
        );

        // Submenú: Tipos de Traslado
        add_submenu_page(
            'tur-transportes',
            'Tipos de Traslado',
            'Tipos de Traslado',
            'manage_options',
            'tt-transfers',
            array(__CLASS__, 'transfers_page')
        );

        // Submenú: Reservas
        add_submenu_page(
            'tur-transportes',
            'Reservas',
            'Reservas',
            'manage_options',
            'tt-bookings',
            array(__CLASS__, 'bookings_page')
        );

        // Submenú: Transbank
        add_submenu_page(
            'tur-transportes',
            'Transacciones Transbank',
            'Transbank',
            'manage_options',
            'tt-transbank',
            array(__CLASS__, 'transbank_page')
        );
    }

    /**
     * Registrar ajustes con sanitización
     */
    public static function register_settings() {

        // Ajustes generales
        register_setting('tt_settings', 'tt_google_maps_api_key', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_currency', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_company_name', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_contact_email', 'sanitize_email');
        register_setting('tt_settings', 'tt_contact_phone', 'sanitize_text_field');

        // Correos internos
        register_setting('tt_settings', 'tt_admin_emails', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_email_from_name', 'sanitize_text_field');

        // Ajustes Transbank (sensibles)
        register_setting('tt_settings', 'tt_transbank_environment', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_transbank_commerce_code', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_transbank_api_key', array(__CLASS__, 'sanitize_secure'));
        register_setting('tt_settings', 'tt_transbank_private_key_path', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_transbank_public_cert_path', 'sanitize_text_field');
        register_setting('tt_settings', 'tt_transbank_webpay_cert_path', 'sanitize_text_field');
    }

    /**
     * Sanitizador para claves sensibles
     */
    public static function sanitize_secure($value) {
        $value = trim($value);
        return sanitize_text_field($value);
    }

    /**
     * Cargar CSS y JS del admin SOLO en páginas del plugin
     */
    public static function enqueue_admin_scripts($hook) {

        if (strpos($hook, 'tur-transportes') === false) {
            return;
        }

        wp_enqueue_style('tt-admin-css', TT_PLUGIN_URL . 'assets/css/admin.css', array(), TT_PLUGIN_VERSION);

        wp_enqueue_script(
            'tt-admin-js',
            TT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            TT_PLUGIN_VERSION,
            true
        );

        wp_localize_script('tt-admin-js', 'tt_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tt_admin_nonce')
        ));
    }

    /**
     * Render: Dashboard
     */
    public static function admin_dashboard() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-dashboard.php';
        if (file_exists($file)) include $file;
    }

    /**
     * Render: Configuración
     */
    public static function settings_page() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-settings.php';
        if (file_exists($file)) include $file;
    }

    /**
     * Render: Vehículos
     */
    public static function vehicles_page() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-vehicles.php';
        if (file_exists($file)) include $file;
    }

    /**
     * Render: Traslados
     */
    public static function transfers_page() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-transfers.php';
        if (file_exists($file)) include $file;
    }

    /**
     * Render: Reservas
     */
    public static function bookings_page() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-bookings.php';
        if (file_exists($file)) include $file;
    }

    /**
     * Render: Transbank
     */
    public static function transbank_page() {
        if (!current_user_can('manage_options')) return;

        $file = TT_PLUGIN_PATH . 'templates/admin-transbank.php';
        if (file_exists($file)) include $file;
    }
}