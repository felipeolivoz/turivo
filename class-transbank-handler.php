<?php
if (!defined('ABSPATH')) {
    exit;
}

class TT_Transbank_Handler {

    private static $instance = null;
    private static $bk_columns = null;
    private static $tbk_columns = null;

    /** @var \Transbank\Webpay\WebpayPlus\Transaction|null */
    private $transaction = null;

    /* ==========================================================
     * INIT (SINGLETON)
     * ========================================================== */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->init_transbank();
    }

    /* ==========================================================
     * HOOKS
     * ========================================================== */
    private function init_hooks() {

        add_action('rest_api_init', [$this, 'register_routes']);

        // Página retorno Webpay (flujo clásico)
        add_action('init', [$this, 'handle_transbank_response_page']);

        add_shortcode('tt_transbank_response', [$this, 'render_transbank_response']);
    }

    /* ==========================================================
     * INIT TRANSBANK
     * ========================================================== */
    private function init_transbank() {
        if (!class_exists('\Transbank\Webpay\WebpayPlus\Transaction')) {
            return;
        }

        if (class_exists('TT_Transbank')) {
            TT_Transbank::configure_webpay();
        }

        $this->transaction = new \Transbank\Webpay\WebpayPlus\Transaction();
    }

    /* ==========================================================
     * REST ROUTES
     * ========================================================== */
    public function register_routes() {

        register_rest_route('tt/v1', '/transbank/create', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_transaction_rest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('tt/v1', '/transbank/commit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'commit_transaction_rest'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ==========================================================
     * HELPERS COLUMNAS BD
     * ========================================================== */
    private static function get_booking_columns() {
        if (self::$bk_columns !== null) {
            return self::$bk_columns;
        }

        global $wpdb;
        self::$bk_columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}tt_bookings", 0);

        return is_array(self::$bk_columns) ? self::$bk_columns : [];
    }

    private static function get_tbk_columns() {
        if (self::$tbk_columns !== null) {
            return self::$tbk_columns;
        }

        global $wpdb;
        self::$tbk_columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}tt_tbk_trans", 0);

        return is_array(self::$tbk_columns) ? self::$tbk_columns : [];
    }

    /* ==========================================================
     * CREATE TRANSACTION — REST
     * ========================================================== */
    public function create_transaction_rest($request) {

        if (!$this->transaction) {
            return new WP_Error('transbank_error', 'Transbank no disponible', ['status' => 500]);
        }

        $params = $request->get_json_params();
        if (empty($params['bookingData'])) {
            return new WP_Error('invalid_data', 'bookingData vacío', ['status' => 400]);
        }

        $raw_booking = $params['bookingData'];

        // Aceptar array o JSON string
        if (!is_array($raw_booking) && is_string($raw_booking)) {
            $decoded = json_decode($raw_booking, true);
            if (is_array($decoded)) {
                $raw_booking = $decoded;
            }
        }

        if (!is_array($raw_booking)) {
            return new WP_Error('invalid_data', 'bookingData inválido', ['status' => 400]);
        }

        // Normalización oficial
        $booking_data = self::normalize_and_compact_booking_data($raw_booking);

        if (!isset($booking_data['totalPrice'])) {
            return new WP_Error('invalid_data', 'totalPrice inválido', ['status' => 400]);
        }

        $amount = max(0, floatval($booking_data['totalPrice']));

        if ($amount <= 0 || $amount > 100000000) {
            return new WP_Error('invalid_amount', 'Monto inválido', ['status' => 400]);
        }

        if (class_exists('TT_Database')) {
            $amount = TT_Database::round_chilean_price($amount);
        }

        $booking_data['totalPrice'] = $amount;

        $buy_order  = 'TT-' . time() . '-' . wp_rand(1000, 9999);
        $session_id = 'TTSESS-' . time() . '-' . wp_rand(1000, 9999);
        $return_url = home_url('/transbank-response');

        try {
            $response = $this->transaction->create($buy_order, $session_id, $amount, $return_url);

            $token = $response->getToken();
            $url   = $response->getUrl();

            if (!$token || !$url) {
                return new WP_Error('transbank_error', 'No se pudo iniciar la transacción', ['status' => 500]);
            }

            $saved = $this->save_transaction_row($buy_order, $session_id, $amount, $token, $booking_data);

            if (!$saved) {
                return new WP_Error('db_error', 'No se pudo registrar la transacción', ['status' => 500]);
            }

            return [
                'token' => $token,
                'url'   => $url,
            ];

        } catch (Exception $e) {
            return new WP_Error('transbank_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /* ==========================================================
     * SAVE ROW IN tt_tbk_trans
     * ========================================================== */
    public function save_transaction_row($buy_order, $session_id, $amount, $token, $booking_data = null) {
        global $wpdb;

        $columns = self::get_tbk_columns();
        $table   = $wpdb->prefix . 'tt_tbk_trans';

        $environment = class_exists('TT_Transbank')
            ? TT_Transbank::get_environment()
            : 'integration';

        $db_data = [
            'buy_order'   => $buy_order,
            'session_id'  => $session_id,
            'amount'      => floatval($amount),
            'token'       => $token,
            'status'      => 'initialized',
            'environment' => $environment,
            'created_at'  => current_time('mysql'),
        ];

        $formats = ['%s','%s','%f','%s','%s','%s','%s'];

        if ($booking_data !== null && in_array('booking_data', $columns, true)) {

            if (is_array($booking_data)) {
                $booking_data = self::normalize_and_compact_booking_data($booking_data);
                $db_data['booking_data'] = wp_json_encode($booking_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $db_data['booking_data'] = (string)$booking_data;
            }

            $formats[] = '%s';
        }

        $wpdb->insert($table, $db_data, $formats);

        return !empty($wpdb->insert_id);
    }

    /* ==========================================================
     * COMMIT TRANSACTION — REST
     * ========================================================== */
    public function commit_transaction_rest($request) {

        if (!$this->transaction) {
            return new WP_Error('transbank_error', 'Transbank no disponible', ['status' => 500]);
        }

        $params = $request->get_json_params();
        if (empty($params['token'])) {
            return new WP_Error('invalid_data', 'Token vacío', ['status' => 400]);
        }

        $token = sanitize_text_field($params['token']);

        global $wpdb;

        $trans = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tt_tbk_trans WHERE token = %s",
                $token
            )
        );

        if (!$trans) {
            return new WP_Error('not_found', 'Transacción no encontrada', ['status' => 404]);
        }

        try {
            $response = $this->transaction->commit($token);

            $approved = $response->isApproved();

            $this->update_transaction_status($token, $approved ? 'approved' : 'rejected', $response);

            if ($approved) {

                if (!class_exists('TT_Tbk_Service')) {
                    throw new Exception('Servicio de Transbank no disponible.');
                }

                $booking_id = TT_Tbk_Service::process_approved_transaction($trans, $response);

                $this->send_booking_emails($booking_id, $response);

                return [
                    'status'     => 'success',
                    'booking_id' => $booking_id,
                    'buy_order'  => $response->getBuyOrder(),
                    'amount'     => $response->getAmount(),
                ];
            }

            return [
                'status'        => 'failed',
                'response_code' => $response->getResponseCode(),
            ];

        } catch (Exception $e) {

            return new WP_Error('transbank_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /* ==========================================================
     * UPDATE STATUS IN tt_tbk_trans
     * ========================================================== */
    private function update_transaction_status($token, $status, $response) {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_tbk_trans';

        $data = [
            'status'              => $status,
            'authorization_code'  => $response->getAuthorizationCode(),
            'response_code'       => $response->getResponseCode(),
            'payment_type_code'   => $response->getPaymentTypeCode(),
            'installments_number' => $response->getInstallmentsNumber(),
            'card_number'         => $response->getCardNumber(),
            'updated_at'          => current_time('mysql'),
        ];

        if (method_exists($response, 'getTransactionDate')) {
            $data['transaction_date'] = $response->getTransactionDate();
        }

        $wpdb->update(
            $table,
            $data,
            ['token' => $token],
            ['%s','%s','%s','%s','%d','%s','%s'],
            ['%s']
        );
    }

    /* ==========================================================
     * EMAILS
     * ========================================================== */
    private function send_booking_emails($booking_id, $response) {
        do_action('tt_booking_confirmed', $booking_id, $response);
        do_action('tt_payment_approved', $booking_id, $response);

        if (class_exists('TT_Email_Handler')) {
            TT_Email_Handler::send_booking_confirmation($booking_id, $response);
            TT_Email_Handler::send_payment_confirmation($booking_id, $response);
        }
    }

    /* ==========================================================
     * /transbank-response — FLUJO CLÁSICO
     * ========================================================== */
    public function handle_transbank_response_page() {

        $token = null;

        if (!empty($_POST['token_ws'])) {
            $token = sanitize_text_field($_POST['token_ws']);
        } elseif (!empty($_GET['token_ws'])) {
            $token = sanitize_text_field($_GET['token_ws']);
        }

        if (!$token) {
            return;
        }

        if (!$this->transaction) {
            wp_die("Transbank no está configurado correctamente");
        }

        global $wpdb;

        $trans = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tt_tbk_trans WHERE token = %s",
                $token
            )
        );

        if (!$trans) {
            wp_die("Error: transacción no encontrada");
        }

        try {
            $response = $this->transaction->commit($token);

            $approved = $response->isApproved();

            $this->update_transaction_status($token, $approved ? 'approved' : 'rejected', $response);

            if ($approved) {

                if (!class_exists('TT_Tbk_Service')) {
                    throw new Exception('Servicio de Transbank no disponible.');
                }

                $booking_id = TT_Tbk_Service::process_approved_transaction($trans, $response);

                $this->send_booking_emails($booking_id, $response);

                $vars = [
                    'booking_id'       => $booking_id,
                    'amount_formatted' => number_format($response->getAmount(), 0, ',', '.'),
                    'buy_order'        => $response->getBuyOrder(),
                    'auth_code'        => $response->getAuthorizationCode(),
                ];

                $this->load_template('tbk-success.php', $vars);

            } else {

                $vars = [
                    'amount_formatted' => number_format($response->getAmount(), 0, ',', '.'),
                    'buy_order'        => $response->getBuyOrder(),
                    'response_code'    => $response->getResponseCode(),
                ];

                $this->load_template('tbk-error.php', $vars);
            }

        } catch (Exception $e) {
            wp_die("Error al procesar el pago: " . esc_html($e->getMessage()));
        }

        exit;
    }

    /* ==========================================================
     * SHORTCODE RESPALDO
     * ========================================================== */
    public function render_transbank_response() {
        ob_start();
        echo '<div class="tt-transbank-response"><h2>Procesando pago...</h2></div>';
        return ob_get_clean();
    }

    /* ==========================================================
     * TEMPLATE LOADER
     * ========================================================== */
    private function load_template($template_name, $vars = []) {

        $template_path = TT_PLUGIN_PATH . 'templates/' . $template_name;

        if (!file_exists($template_path)) {
            wp_die("No se encontró la plantilla: " . esc_html($template_name));
        }

        extract($vars);
        include $template_path;
        exit;
    }

    /* ==========================================================
     * BOOKING DATA NORMALIZATION
     * ========================================================== */
    private static function normalize_and_compact_booking_data(array $data) {

        $allowed_keys = [
            'step1', 'step2',
            'distance', 'duration',
            'stops',
            'route_polyline', 'route_points',
            'priceDetails', 'surchargeData',
            'stopsPrice',
            'transferType',
            'totalPrice',
        ];

        $clean = [];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $data)) continue;

            $value = $data[$key];

            switch ($key) {
                case 'transferType':
                case 'stopsPrice':
                case 'distance':
                case 'totalPrice':
                    $clean[$key] = max(0, floatval($value));
                    break;

                case 'route_polyline':
                case 'duration':
                    $clean[$key] = is_string($value) ? trim($value) : '';
                    break;

                default:
                    $clean[$key] = self::deep_clean_value($value);
            }
        }

        if (isset($clean['step1']) && !is_array($clean['step1'])) {
            $clean['step1'] = [];
        }
        if (isset($clean['step2']) && !is_array($clean['step2'])) {
            $clean['step2'] = [];
        }

        return $clean;
    }

    private static function deep_clean_value($value) {

        if ($value === null) return null;

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $k => $v) {

                if (is_string($k) && preg_match('/^(_|temp_|debug)/i', $k)) {
                    continue;
                }

                $clean_v = self::deep_clean_value($v);

                if ($clean_v === null) continue;
                if (is_string($clean_v) && trim($clean_v) === '') continue;
                if (is_array($clean_v) && empty($clean_v)) continue;

                $result[$k] = $clean_v;
            }

            return $result;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        return $value;
    }
}
