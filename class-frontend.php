<?php
if (!defined('ABSPATH')) {
    exit;
}

class TT_Frontend {

    private const NONCE_ACTION = 'tt_booking_nonce';

    /**
     * Cache en memoria SOLO por request (NO usa transients ni opciones)
     */
    private static $transfer_types_cache = null;
    private static $vehicle_types_cache  = null;

    public static function init() {
        add_shortcode('tur_transportes_form', array(__CLASS__, 'booking_form_shortcode'));
        add_shortcode('tur_transportes_calculator', array(__CLASS__, 'quick_calculator_shortcode'));
        add_shortcode('tur_transportes_vehicles', array(__CLASS__, 'vehicles_list_shortcode'));
        add_shortcode('tur_transportes_contact', array(__CLASS__, 'contact_info_shortcode'));

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_scripts'));

        // Cálculo de precio principal (formulario moderno)
        add_action('wp_ajax_calculate_price', array(__CLASS__, 'calculate_price'));
        add_action('wp_ajax_nopriv_calculate_price', array(__CLASS__, 'calculate_price'));

        // Cálculo de distancia (fallback / usos secundarios)
        add_action('wp_ajax_calculate_distance', array(__CLASS__, 'calculate_distance'));
        add_action('wp_ajax_nopriv_calculate_distance', array(__CLASS__, 'calculate_distance'));

        // Obtener ID de reserva por código
        add_action('wp_ajax_tt_get_booking_id', array(__CLASS__, 'get_booking_id'));
        add_action('wp_ajax_nopriv_tt_get_booking_id', array(__CLASS__, 'get_booking_id'));

        // Listado de vehículos vía AJAX
        add_action('wp_ajax_tt_get_vehicle_types', array(__CLASS__, 'get_vehicle_types_ajax'));
        add_action('wp_ajax_nopriv_tt_get_vehicle_types', array(__CLASS__, 'get_vehicle_types_ajax'));

        // Proceso de pago con Transbank
        add_action('wp_ajax_tt_process_payment', array(__CLASS__, 'process_payment'));
        add_action('wp_ajax_nopriv_tt_process_payment', array(__CLASS__, 'process_payment'));
    }

    /* ============================================================
     * DATA HELPERS
     * ============================================================ */

    private static function get_active_transfer_types() {
        global $wpdb;

        if (self::$transfer_types_cache !== null) {
            return self::$transfer_types_cache;
        }

        $table = $wpdb->prefix . 'tt_transfer_types';

        $rows = $wpdb->get_results(
            "SELECT id, name, description, base_price, price_per_km, stop_price, allows_stops
             FROM {$table}
             WHERE active = 1
             ORDER BY name ASC"
        );

        self::$transfer_types_cache = is_array($rows) ? $rows : array();

        return self::$transfer_types_cache;
    }

    private static function get_active_vehicle_types() {
        global $wpdb;

        if (self::$vehicle_types_cache !== null) {
            return self::$vehicle_types_cache;
        }

        $table = $wpdb->prefix . 'tt_vehicle_types';

        $rows = $wpdb->get_results(
            "SELECT id, name, description, capacity, base_price, price_per_km, price_per_passenger, factor_vehiculo, image_url
             FROM {$table}
             WHERE active = 1
             ORDER BY capacity ASC, name ASC"
        );

        self::$vehicle_types_cache = is_array($rows) ? $rows : array();

        return self::$vehicle_types_cache;
    }

    /* ============================================================
     * AJAX: VEHICLE TYPES
     * ============================================================ */

    public static function get_vehicle_types_ajax() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido']);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Error de seguridad']);
        }

        $types = self::get_active_vehicle_types();

        if (!empty($types)) {
            wp_send_json_success($types);
        }

        wp_send_json_error(['message' => 'No se encontraron vehículos activos.']);
    }

    /* ============================================================
     * FRONTEND SCRIPTS
     * ============================================================ */
    public static function enqueue_frontend_scripts() {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $api_key = get_option('tt_google_maps_api_key');

        if (!empty($api_key)) {
            // IMPORTANTE: dejamos callback explícito y definimos la función ANTES del script
            $maps_url = "https://maps.googleapis.com/maps/api/js?key={$api_key}&libraries=places&callback=__ttgmaps_init";

            wp_register_script(
                'tt-google-maps',
                $maps_url,
                [],
                null,
                false // en el header
            );

            // Definimos el callback global ANTES del script de Google
            wp_add_inline_script(
                'tt-google-maps',
                'function __ttgmaps_init(){ 
                    function __ttgmaps_wait(retries){
                        if (window.TTBooking && typeof TTBooking._gmapsInit === "function") {
                            TTBooking._gmapsInit();
                        } else if (retries > 0) {
                            setTimeout(function(){ __ttgmaps_wait(retries-1); }, 300);
                        }
                    }
                    __ttgmaps_wait(40);
                }',
                'before' // <-- CLAVE: ahora la función existe cuando Google la busca
            );

            wp_enqueue_script('tt-google-maps');
        }

        // CSS principal
        wp_enqueue_style(
            'tt-frontend',
            TT_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            time()
        );

        // JS principal
        wp_enqueue_script(
            'tt-booking',
            TT_PLUGIN_URL . 'assets/js/tt-booking.js',
            ['jquery'],
            time(),
            true
        );

        // Datos de pricing base (tipos de traslado y vehículos)
        $transfer_types = self::get_active_transfer_types();
        $vehicle_types  = self::get_active_vehicle_types();

        wp_localize_script('tt-booking', 'tt_pricing_data', [
            'transfer_types' => $transfer_types,
            'vehicle_types'  => $vehicle_types,
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
        ]);

        // Config AJAX genérica
        wp_localize_script('tt-booking', 'tt_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    /* ============================================================
     * SHORTCODES
     * ============================================================ */

    public static function booking_form_shortcode($atts) {
        if (!headers_sent()) {
            nocache_headers();
        }

        ob_start();
        include TT_PLUGIN_PATH . 'templates/form-principal.php';
        return ob_get_clean();
    }

    public static function quick_calculator_shortcode($atts) {
        if (!headers_sent()) {
            nocache_headers();
        }

        ob_start();
        include TT_PLUGIN_PATH . 'templates/quick-calculator.php';
        return ob_get_clean();
    }

    public static function vehicles_list_shortcode($atts) {
        $atts = shortcode_atts([
            'show_prices' => 'yes',
            'layout'      => 'grid',
        ], $atts);

        ob_start();
        include TT_PLUGIN_PATH . 'templates/vehicles-list.php';
        return ob_get_clean();
    }

    public static function contact_info_shortcode($atts) {
        $atts = shortcode_atts([
            'show_phone'   => 'yes',
            'show_email'   => 'yes',
            'show_address' => 'no',
        ], $atts);

        ob_start();
        include TT_PLUGIN_PATH . 'templates/contact-info.php';
        return ob_get_clean();
    }

    /* ============================================================
     * AJAX — PRICE CALCULATOR (Frontend principal)
     * ============================================================ */

    public static function calculate_price() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido']);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Error de seguridad']);
        }

        $distance        = isset($_POST['distance']) ? floatval($_POST['distance']) : 0;
        $vehicle_type_id = isset($_POST['vehicle_type_id']) ? intval($_POST['vehicle_type_id']) : 0;
        $passengers      = isset($_POST['passengers']) ? intval($_POST['passengers']) : 1;
        $transfer_date   = sanitize_text_field($_POST['transfer_date'] ?? '');
        $transfer_time   = sanitize_text_field($_POST['transfer_time'] ?? '');

        if ($vehicle_type_id <= 0 || $distance <= 0) {
            wp_send_json_error(['message' => 'Datos insuficientes para calcular el precio.']);
        }

        // Si existe TT_Database, usamos SIEMPRE el motor único de precios
        if (class_exists('TT_Database')) {
            $result = TT_Database::calculate_vehicle_price(
                $vehicle_type_id,
                $distance,
                $passengers,
                $transfer_date,
                $transfer_time
                // transfer_type_id y stops_count se quedan en 0 por ahora
            );

            if (!$result || !is_array($result) || !isset($result['total_price'])) {
                wp_send_json_error(['message' => 'No se pudo calcular el precio para el vehículo seleccionado.']);
            }

            $total_price           = floatval($result['total_price']);
            $base_price            = isset($result['base_price']) ? floatval($result['base_price']) : 0;
            $distance_price        = isset($result['distance_price']) ? floatval($result['distance_price']) : 0;
            $passenger_price       = isset($result['passengers_price']) ? floatval($result['passengers_price']) : 0;
            $vehicle_factor_amount = isset($result['vehicle_factor_amount']) ? floatval($result['vehicle_factor_amount']) : 0;

            // Formateo para frontend
            $formatted_price = number_format($total_price, 0, ',', '.');

            wp_send_json_success([
                'price'           => $formatted_price,
                'currency_symbol' => '$',
                'distance'        => number_format($distance, 2),
                'breakdown'       => [
                    'base'               => $base_price,
                    'distance'           => $distance_price,
                    'passengers'         => $passenger_price,
                    'vehicleFactorAmount'=> $vehicle_factor_amount,
                    'final'              => $total_price,
                ],
                'vehicle_factor_amount' => $vehicle_factor_amount,
                'extra_passengers'      => max(0, $passengers - 1),
            ]);
        }

        // Fallback (si por alguna razón no existe TT_Database)
        global $wpdb;
        $vehicle = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT base_price, price_per_km, price_per_passenger, capacity, factor_vehiculo
                 FROM {$wpdb->prefix}tt_vehicle_types
                 WHERE id = %d AND active = 1",
                $vehicle_type_id
            )
        );

        if (!$vehicle) {
            wp_send_json_error(['message' => 'Vehículo no encontrado o inactivo.']);
        }

        if (!empty($vehicle->capacity) && $passengers > intval($vehicle->capacity)) {
            wp_send_json_error(['message' => 'El número de pasajeros excede la capacidad del vehículo.']);
        }

        $base_price      = floatval($vehicle->base_price);
        $distance_price  = floatval($vehicle->price_per_km) * $distance;

        $passenger_price = ($vehicle->price_per_passenger > 0 && $passengers > 1)
            ? ($passengers - 1) * floatval($vehicle->price_per_passenger)
            : 0;

        $vehicle_factor        = floatval($vehicle->factor_vehiculo);
        $vehicle_factor_amount = $base_price * $vehicle_factor;

        $calculated_price = $base_price + $distance_price + $passenger_price + $vehicle_factor_amount;

        if (class_exists('TT_Database')) {
            $rounded_price = TT_Database::round_chilean_price($calculated_price);
        } else {
            $rounded_price = round($calculated_price);
        }

        $formatted_price = number_format($rounded_price, 0, ',', '.');

        wp_send_json_success([
            'price'                 => $formatted_price,
            'currency_symbol'       => '$',
            'distance'              => number_format($distance, 2),
            'breakdown'             => [
                'base'               => $base_price,
                'distance'           => $distance_price,
                'passengers'         => $passenger_price,
                'vehicleFactorAmount'=> $vehicle_factor_amount,
                'final'              => $rounded_price,
            ],
            'vehicle_factor_amount' => $vehicle_factor_amount,
            'extra_passengers'      => max(0, $passengers - 1),
        ]);
    }

    /* ============================================================
     * AJAX — DISTANCE CALCULATION
     * ============================================================ */

    public static function calculate_distance() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido']);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Error de seguridad']);
        }

        $origin      = sanitize_text_field($_POST['origin'] ?? '');
        $destination = sanitize_text_field($_POST['destination'] ?? '');
        $api_key     = get_option('tt_google_maps_api_key');

        if (!$origin || !$destination) {
            wp_send_json_error(['message' => 'Origen y destino son obligatorios.']);
        }

        if (!$api_key) {
            wp_send_json_error(['message' => 'API key de Google Maps no configurada.']);
        }

        $url = add_query_arg([
            'origins'      => rawurlencode($origin),
            'destinations' => rawurlencode($destination),
            'key'          => $api_key,
        ], 'https://maps.googleapis.com/maps/api/distancematrix/json');

        $res = wp_safe_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => 'Error al calcular la distancia.']);
        }

        $data = json_decode(wp_remote_retrieve_body($res));

        if (empty($data->rows[0]->elements[0]) ||
            $data->rows[0]->elements[0]->status !== 'OK') {
            wp_send_json_error(['message' => 'No se pudo calcular la ruta.']);
        }

        $el          = $data->rows[0]->elements[0];
        $distance_km = $el->distance->value / 1000;
        $duration    = $el->duration->text;

        wp_send_json_success([
            'distance' => $distance_km,
            'duration' => $duration,
        ]);
    }

    /* ============================================================
     * AJAX — PROCESS PAYMENT
     * ============================================================ */

    public static function process_payment() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido']);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Error de seguridad']);
        }

        if (empty($_POST['booking_data'])) {
            wp_send_json_error(['message' => 'Datos de reserva no recibidos']);
        }

        $raw = wp_unslash($_POST['booking_data']);

        // Aceptamos tanto array como JSON string
        $data = is_array($raw) ? $raw : json_decode(stripslashes($raw), true);

        if (empty($data) || !is_array($data)) {
            wp_send_json_error(['message' => 'Datos de reserva inválidos']);
        }

        // Normalizar y compactar booking_data con whitelist + purga profunda
        $booking_data = self::normalize_and_compact_booking_data($data);

        if (empty($booking_data) || !isset($booking_data['totalPrice'])) {
            wp_send_json_error(['message' => 'No se pudo normalizar la reserva.']);
        }

        $amount = floatval($booking_data['totalPrice']);

        if ($amount <= 0 || $amount > 100000000) {
            wp_send_json_error(['message' => 'Monto inválido']);
        }

        if (class_exists('TT_Database')) {
            $amount = TT_Database::round_chilean_price($amount);
        } else {
            $amount = round($amount);
        }

        // Aseguramos que el total normalizado coincida con el monto a pagar
        $booking_data['totalPrice'] = $amount;

        if (!class_exists('\Transbank\Webpay\WebpayPlus\Transaction')) {
            wp_send_json_error(['message' => 'Transbank no está disponible']);
        }

        if (class_exists('TT_Transbank')) {
            TT_Transbank::configure_webpay();
        }

        $ts     = time();
        $buy    = 'TT-' . $ts . '-' . wp_rand(1000, 9999);
        $sess   = 'TTSESS-' . $ts . '-' . wp_rand(1000, 9999);
        $return = home_url('/transbank-response');

        // JSON compacto para guardar en BD
        $json = wp_json_encode($booking_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $tb = new \Transbank\Webpay\WebpayPlus\Transaction();
            $r  = $tb->create($buy, $sess, $amount, $return);

            $token = method_exists($r, 'getToken') ? $r->getToken() : null;
            $url   = method_exists($r, 'getUrl')   ? $r->getUrl()   : null;

            if (!$token || !$url) {
                wp_send_json_error(['message' => 'Error al iniciar la transacción']);
            }

            global $wpdb;

            // Registrar ambiente si TT_Transbank está disponible
            $environment = 'integration';
            if (class_exists('TT_Transbank')) {
                $environment = TT_Transbank::get_environment();
            }

            $wpdb->insert(
                $wpdb->prefix . 'tt_tbk_trans',
                [
                    'buy_order'    => $buy,
                    'session_id'   => $sess,
                    'amount'       => $amount,
                    'token'        => $token,
                    'status'       => 'initialized',
                    'environment'  => $environment,
                    'created_at'   => current_time('mysql'),
                    'booking_data' => $json,
                ]
            );

            wp_send_json_success([
                'token' => $token,
                'url'   => $url,
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error al crear la transacción']);
        }
    }

    /* ============================================================
     * BOOKING DATA NORMALIZATION & COMPACT (AVANZADO)
     * ============================================================ */

    /**
     * BOOKING DATA WHITELIST (Purga Total)
     *
     * Solo se permiten las claves listadas en $allowed_keys.
     */
    private static function normalize_and_compact_booking_data(array $data) {
        // Claves permitidas a nivel raíz
        $allowed_keys = [
            'step1',
            'step2',

            'distance',
            'duration',

            'stops',
            'route_polyline',
            'route_points',

            'priceDetails',
            'surchargeData',
            'stopsPrice',

            'transferType',  // si en el futuro se usa a nivel raíz

            'totalPrice',
        ];

        $clean = [];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            switch ($key) {
                case 'transferType':
                    $clean['transferType'] = intval($value);
                    break;

                case 'distance':
                    $clean['distance'] = max(0, floatval($value));
                    break;

                case 'stopsPrice':
                    $clean['stopsPrice'] = max(0, floatval($value));
                    break;

                case 'totalPrice':
                    $clean['totalPrice'] = max(0, floatval($value));
                    break;

                case 'step1':
                case 'step2':
                case 'stops':
                case 'route_points':
                case 'priceDetails':
                case 'surchargeData':
                    $clean[$key] = self::deep_clean_value($value);
                    break;

                case 'route_polyline':
                case 'duration':
                    $clean[$key] = is_string($value) ? trim($value) : '';
                    break;

                default:
                    $clean[$key] = self::deep_clean_value($value);
                    break;
            }
        }

        // Normalización mínima de step1 / step2 (asegurar arrays)
        if (isset($clean['step1']) && !is_array($clean['step1'])) {
            $clean['step1'] = [];
        }
        if (isset($clean['step2']) && !is_array($clean['step2'])) {
            $clean['step2'] = [];
        }

        // Fallbacks básicos si distance o duration no están presentes, pero vienen en raíz original
        if (!isset($clean['distance']) && isset($data['distance'])) {
            $clean['distance'] = max(0, floatval($data['distance']));
        }
        if (!isset($clean['duration']) && isset($data['duration']) && is_string($data['duration'])) {
            $clean['duration'] = trim($data['duration']);
        }

        return $clean;
    }

    /**
     * Purga profunda recursiva:
     *  - Elimina claves internas (_*, temp_*, debug*)
     *  - Elimina null, '', arrays/objetos vacíos
     *  - Normaliza arrays/objetos a arrays PHP simples
     */
    private static function deep_clean_value($value) {
        // Null → fuera
        if ($value === null) {
            return null;
        }

        // Objetos → arrays
        if (is_object($value)) {
            $value = (array) $value;
        }

        // Arrays: limpieza recursiva
        if (is_array($value)) {
            $result = [];

            foreach ($value as $k => $v) {
                // Claves internas de debug/cache
                if (is_string($k) && preg_match('/^(_|temp_|debug)/i', $k)) {
                    continue;
                }

                $clean_v = self::deep_clean_value($v);

                // Eliminar null / vacíos
                if ($clean_v === null) {
                    continue;
                }
                if (is_string($clean_v) && trim($clean_v) === '') {
                    continue;
                }
                if (is_array($clean_v) && empty($clean_v)) {
                    continue;
                }

                $result[$k] = $clean_v;
            }

            return $result;
        }

        // Strings: trim
        if (is_string($value)) {
            $value = trim($value);
            return ($value === '') ? null : $value;
        }

        // Números / booleanos se devuelven tal cual
        return $value;
    }

    /* ============================================================
     * AJAX — GET BOOKING ID BY CODE
     * ============================================================ */

    public static function get_booking_id() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido']);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Error de seguridad']);
        }

        $code = sanitize_text_field($_POST['booking_code'] ?? '');

        if (!$code) {
            wp_send_json_error(['message' => 'Código no proporcionado']);
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, booking_code FROM {$wpdb->prefix}tt_bookings
                 WHERE booking_code = %s",
                $code
            )
        );

        if ($row) {
            wp_send_json_success([
                'booking_id'   => intval($row->id),
                'booking_code' => $code,
            ]);
        }

        wp_send_json_error(['message' => 'Reserva no encontrada']);
    }
}

/* ============================================================
 * Agregar async/defer al script de Google Maps
 * ============================================================ */
add_filter('script_loader_tag', function ($tag, $handle) {
    if ($handle === 'tt-google-maps') {
        return str_replace('<script ', '<script async defer ', $tag);
    }
    return $tag;
}, 10, 2);
