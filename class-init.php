<?php
if (!defined('ABSPATH')) {
    exit;
}

class TT_Init {

    /**
     * Inicialización general del plugin
     */
    public static function init() {
        self::verify_database_tables();
        self::maybe_sync_database_structure();
        self::init_ajax_handlers();
        self::init_shortcodes();
    }

    /* ==========================================================
     * VERIFICACIÓN DE TABLAS
     * ========================================================== */
    private static function verify_database_tables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'tt_bookings',
            $wpdb->prefix . 'tt_vehicle_types',
            $wpdb->prefix . 'tt_transfer_types',
            $wpdb->prefix . 'tt_tbk_trans'
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );

            if ($exists !== $table) {
                TT_Database::create_tables();
                return;
            }
        }
    }

    /* ==========================================================
     * SINCRONIZACIÓN DE ESTRUCTURA — SOLO SI CAMBIA LA VERSIÓN
     * ========================================================== */
    private static function maybe_sync_database_structure() {

        $version_saved = get_option('tt_db_version');
        $version_code  = TT_DATABASE_VERSION;

        if ($version_saved !== $version_code) {
            TT_Database::sync_all_tables();
            update_option('tt_db_version', $version_code);
        }
    }

    /* ==========================================================
     * AJAX HANDLERS
     * ========================================================== */
    private static function init_ajax_handlers() {

        add_action('wp_ajax_tt_get_vehicle_price', ['TT_Database', 'get_vehicle_price']);
        add_action('wp_ajax_nopriv_tt_get_vehicle_price', ['TT_Database', 'get_vehicle_price']);

        add_action('wp_ajax_tt_process_payment', ['TT_Frontend', 'process_payment']);
        add_action('wp_ajax_nopriv_tt_process_payment', ['TT_Frontend', 'process_payment']);

        add_action('wp_ajax_tt_save_booking', ['TT_Database', 'save_booking']);
        add_action('wp_ajax_nopriv_tt_save_booking', ['TT_Database', 'save_booking']);
    }

    /* ==========================================================
     * SHORTCODES
     * ========================================================== */
    private static function init_shortcodes() {

        add_shortcode('transbank_response', ['TT_Transbank_Handler', 'render_transbank_response']);

        add_shortcode('booking_form',        ['TT_Frontend', 'booking_form_shortcode']);
        add_shortcode('tur_transportes_form',['TT_Frontend', 'booking_form_shortcode']);
    }
}
