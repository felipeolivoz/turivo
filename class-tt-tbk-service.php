<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Servicio centralizado para manejar la lógica de:
 * - Validar transacciones aprobadas
 * - Desencriptar / decodificar booking_data
 * - Crear la reserva en tt_bookings
 * - Vincular transacción ↔ reserva
 * - Compactar booking_data en tt_tbk_trans
 *
 * NO modifica diseños ni HTML del frontend/correos.
 */
class TT_Tbk_Service {

    /**
     * Punto de entrada principal desde el handler:
     *
     * @param object $trans    Fila original de tt_tbk_trans (al menos con id o token).
     * @param object $response Respuesta del SDK de Transbank (commit).
     * @return int             booking_id creado
     * @throws Exception       En caso de error lógico o de datos.
     */
    public static function process_approved_transaction($trans, $response) {
        global $wpdb;

        if (!$trans || !isset($trans->id)) {
            throw new Exception('Transacción inválida o sin ID.');
        }

        $table_tbk      = $wpdb->prefix . 'tt_tbk_trans';
        $table_bookings = $wpdb->prefix . 'tt_bookings';

        // 1) Releer la fila de tt_tbk_trans para tener datos frescos
        $trans_db = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_tbk} WHERE id = %d LIMIT 1",
                (int) $trans->id
            )
        );

        if (!$trans_db) {
            throw new Exception('No se encontró la transacción en la base de datos.');
        }

        // 2) Validaciones críticas
        self::validate_transaction_state($trans_db, $response);

        // 3) Desencriptar (si aplica) y decodificar booking_data
        if (empty($trans_db->booking_data)) {
            throw new Exception('No se encontraron datos de reserva en la transacción.');
        }

        $plain        = self::decrypt_booking_payload($trans_db->booking_data);
        $booking_data = self::decode_booking_json($plain);

        // 4) Construir campos para tt_bookings (manteniendo la lógica actual)
        $fields = self::build_booking_fields($booking_data, $response);

        // 5) Insertar en tt_bookings respetando columnas existentes
        $columns_bookings = self::get_booking_columns();
        $data             = [];
        $format           = [];

        foreach ($fields as $key => $value) {
            if (in_array($key, $columns_bookings, true)) {
                $data[$key] = $value;

                if (in_array($key, ['passengers', 'vehicle_type_id', 'transfer_type_id'], true)) {
                    $format[] = '%d';
                } elseif (in_array($key, ['distance', 'distance_km', 'total_price'], true)) {
                    $format[] = '%f';
                } else {
                    $format[] = '%s';
                }
            }
        }

        $ok = $wpdb->insert($table_bookings, $data, $format);
        if (!$ok) {
            throw new Exception('No se pudo guardar la reserva en la base de datos.');
        }

        $booking_id = (int) $wpdb->insert_id;

        // 6) Vincular transacción ↔ reserva + marcar status si procede
        $wpdb->update(
            $table_tbk,
            [
                'booking_id' => $booking_id,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $trans_db->id],
            ['%d', '%s'],
            ['%d']
        );

        // 7) Compactar booking_data en tt_tbk_trans (manteniendo estructura útil)
        self::compact_and_update_booking_data($trans_db, $booking_data);

        // 8) Logging básico (si existe TT_Database::log)
        if (class_exists('TT_Database') && method_exists('TT_Database', 'log')) {
          TT_Database::log(
    'TBK_BOOKING_CREATED',
    'info',
    [
        'description' => 'Reserva creada desde transacción aprobada',
        'tbk_id'      => $trans_db->id,
        'booking_id'  => $booking_id,
        'amount'      => $response->getAmount(),
    ]
);

        }

        return $booking_id;
    }

    /* ==========================================================
     * VALIDACIONES
     * ========================================================== */

    /**
     * Validaciones críticas previas a crear la reserva.
     *
     * - Transacción no debe tener booking_id ya asociado
     * - Monto de commit debe coincidir con amount registrado
     * - Respuesta de Transbank debe ser aprobada
     */
    private static function validate_transaction_state($trans_db, $response) {
        // Si ya tiene booking_id asociado, evita doble reserva
        if (!empty($trans_db->booking_id)) {
            throw new Exception('La transacción ya tiene una reserva asociada.');
        }

        // Validación de monto
        $amount_db  = (int) $trans_db->amount;
        $amount_tbk = (int) $response->getAmount();

        if ($amount_db <= 0 || $amount_tbk <= 0 || $amount_db !== $amount_tbk) {
            throw new Exception('Inconsistencia en el monto de la transacción.');
        }

        // Debe estar aprobada por Transbank
        if (!method_exists($response, 'isApproved') || !$response->isApproved()) {
            throw new Exception('El pago no fue aprobado por Transbank.');
        }
    }

    /* ==========================================================
     * CRYPTO / BOOKING_DATA
     * ========================================================== */

    /**
     * Devuelve una key binaria de 32 bytes para AES-256 a partir
     * de constantes de WP / plugin.
     */
    private static function get_crypto_key() {
        if (defined('TT_BOOKING_DATA_KEY') && TT_BOOKING_DATA_KEY) {
            return hash('sha256', TT_BOOKING_DATA_KEY, true);
        }
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return hash('sha256', AUTH_KEY, true);
        }
        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) {
            return hash('sha256', SECURE_AUTH_KEY, true);
        }
        return null;
    }

    /**
     * Desencripta payload con formato:
     *   ENC1: base64( iv[16] + ciphertext )
     *
     * Si NO comienza con ENC1:, se asume texto plano (JSON).
     *
     * @param string $stored
     * @return string|null  Texto plano o null si irrecuperable.
     */
    private static function decrypt_booking_payload($stored) {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        // Reserva antigua o sin encriptar → devolver tal cual
        if (strpos($stored, 'ENC1:') !== 0) {
            return $stored;
        }

        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        $b64 = substr($stored, 5);
        $raw = base64_decode($b64, true);

        if ($raw === false || strlen($raw) < 17) {
            return null;
        }

        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);

        $key = self::get_crypto_key();
        if (!$key) {
            return null;
        }

        $plain = openssl_decrypt(
            $ciphertext,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plain === false) {
            return null;
        }

        return $plain;
    }

    /**
     * Intenta decodificar el JSON de booking_data con varias estrategias.
     *
     * @param string|null $plain
     * @return array
     * @throws Exception
     */
    private static function decode_booking_json($plain) {
        if ($plain === null) {
            throw new Exception('Los datos de reserva están dañados o no pueden desencriptarse.');
        }

        $plain = wp_unslash($plain);

        $decoded = json_decode($plain, true);

        if (!is_array($decoded)) {
            $decoded = json_decode(stripslashes($plain), true);
        }
        if (!is_array($decoded)) {
            $plain_html = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded    = json_decode($plain_html, true);
        }

        if (!is_array($decoded)) {
            throw new Exception('Los datos de reserva están corruptos o en un formato inválido.');
        }

        return $decoded;
    }

    /* ==========================================================
     * CAMPOS PARA tt_bookings (mantiene lógica actual)
     * ========================================================== */

    /**
     * Replica la lógica del método original save_approved_booking,
     * pero solo construyendo el array $fields.
     */
    private static function build_booking_fields(array $booking_data, $response) {
        $step1       = isset($booking_data['step1']) ? $booking_data['step1'] : [];
        $step2       = isset($booking_data['step2']) ? $booking_data['step2'] : [];
        $distance_km = floatval($booking_data['distance'] ?? 0);
        $duration    = sanitize_text_field($booking_data['duration'] ?? '');

        $stops        = isset($booking_data['stops']) ? $booking_data['stops'] : [];
        $route_poly   = isset($booking_data['route_polyline']) ? $booking_data['route_polyline'] : '';
        $route_points = isset($booking_data['route_points'])   ? $booking_data['route_points']   : '';

        $customer_name  = sanitize_text_field($step2['customerName']  ?? '');
        $customer_email = sanitize_email($step2['customerEmail']      ?? '');
        $customer_phone = sanitize_text_field($step2['customerPhone'] ?? '');
        $customer_rut   = sanitize_text_field($step2['customerRut']   ?? '');

        $origin_address      = sanitize_text_field($step1['origin']      ?? '');
        $destination_address = sanitize_text_field($step1['destination'] ?? '');
        $transfer_date       = sanitize_text_field($step1['date']        ?? '');
        $transfer_time       = sanitize_text_field($step1['time']        ?? '');
        $passengers          = intval($step1['passengers'] ?? 1);
        $vehicle_type_id     = intval($step2['vehicleTypeId'] ?? 0);
        $transfer_type_id    = intval($step1['transferType'] ?? 0);

        $booking_code = 'TT-' . strtoupper(wp_generate_password(8, false, false));

        $fields = [
            'booking_code'        => $booking_code,
            'customer_name'       => $customer_name,
            'customer_email'      => $customer_email,
            'customer_phone'      => $customer_phone,
            'customer_rut'        => $customer_rut,
            'origin_address'      => $origin_address,
            'destination_address' => $destination_address,
            'transfer_date'       => $transfer_date,
            'transfer_time'       => $transfer_time,
            'passengers'          => $passengers,
            'vehicle_type_id'     => $vehicle_type_id,
            'transfer_type_id'    => $transfer_type_id,
            'distance_km'         => $distance_km,
            'distance'            => $distance_km,
            'duration'            => $duration,
            'total_price'         => floatval($response->getAmount()),
            'status'              => 'confirmed',
            'payment_status'      => 'paid',
            'payment_method'      => 'webpay',
            'payment_reference'   => sanitize_text_field($response->getAuthorizationCode()),
            'created_at'          => current_time('mysql'),
            'stops_data'          => !empty($stops) ? wp_json_encode($stops, JSON_UNESCAPED_UNICODE) : '',
            'route_polyline'      => $route_poly,
            'route_points'        => is_array($route_points)
                ? wp_json_encode($route_points, JSON_UNESCAPED_UNICODE)
                : $route_points,
        ];

        return $fields;
    }

    /* ==========================================================
     * COMPACTACIÓN DE booking_data EN tt_tbk_trans
     * ========================================================== */

    /**
     * Compacta booking_data en la tabla tt_tbk_trans, manteniendo solo
     * la información útil para emails / reportes y eliminando ruido.
     */
    private static function compact_and_update_booking_data($trans_db, array $booking_data) {
        global $wpdb;

        $table_tbk = $wpdb->prefix . 'tt_tbk_trans';

        // Construimos un snapshot compacto con los campos realmente usados por emails:
        $compact = [];

        if (isset($booking_data['priceDetails']) && is_array($booking_data['priceDetails'])) {
            $compact['priceDetails'] = $booking_data['priceDetails'];
        }

        if (isset($booking_data['surchargeData']) && is_array($booking_data['surchargeData'])) {
            $compact['surchargeData'] = $booking_data['surchargeData'];
        }

        if (isset($booking_data['stops']) && is_array($booking_data['stops'])) {
            $compact['stops'] = $booking_data['stops'];
        }

        if (isset($booking_data['stopsPrice'])) {
            $compact['stopsPrice'] = $booking_data['stopsPrice'];
        }

        if (isset($booking_data['route_polyline'])) {
            $compact['route_polyline'] = $booking_data['route_polyline'];
        }

        // Eliminamos route_points del snapshot para ahorrar espacio (no se usan en emails)
        // Otros campos ruidosos (debug, step1/step2 completos, etc.) se omiten.

        if (empty($compact)) {
            // Si no hay nada útil, no tocamos booking_data para no perder datos históricos
            return;
        }

        $json_compact = wp_json_encode($compact, JSON_UNESCAPED_UNICODE);
        if (!$json_compact) {
            return;
        }

        $wpdb->update(
            $table_tbk,
            ['booking_data' => $json_compact],
            ['id' => $trans_db->id],
            ['%s'],
            ['%d']
        );
    }

    /* ==========================================================
     * HELPERS COLUMNAS BD
     * ========================================================== */

    private static $bk_columns = null;

    private static function get_booking_columns() {
        if (self::$bk_columns !== null) {
            return self::$bk_columns;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tt_bookings';

        self::$bk_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array(self::$bk_columns)) {
            self::$bk_columns = [];
        }

        return self::$bk_columns;
    }
}
