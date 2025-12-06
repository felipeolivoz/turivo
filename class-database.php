<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal de acceso a datos del plugin
 * - Manejo de tablas (crear / sync / reparar)
 * - Motor de precios por vehículo
 * - CRUD básico de reservas
 *
 * IMPORTANTE:
 * - El flujo Webpay NO usa save_booking(), usa TT_Transbank_Handler::save_approved_booking()
 *   que escribe directamente en tt_bookings con booking_data guardado en tt_tbk_trans.
 *
 * NOTA SOBRE DISTANCIAS:
 * - A partir de esta versión, la única fuente de verdad es distance_km.
 *   El campo distance se considera legado y no se usa en nuevas escrituras.
 */
class TT_Database
{
    /* ============================================================
     * INIT (hooks AJAX públicos mínimos)
     * ============================================================ */
    public static function init() {
        // AJAX público para cálculo de precio por vehículo
        add_action('wp_ajax_tt_get_vehicle_price',        array(__CLASS__, 'get_vehicle_price'));
        add_action('wp_ajax_nopriv_tt_get_vehicle_price', array(__CLASS__, 'get_vehicle_price'));
    }

    /* ============================================================
     * LOGGING SEGURO (a archivo y a tabla tt_logs)
     * ============================================================ */
public static function log($message, $level = 'info', array $context = array()){

        $timestamp = current_time('mysql');
        $level     = strtoupper($level);

        // Serializar contexto
        $context_json = '';
        if (!empty($context)) {
            $context_json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($context_json === false) {
                $context_json = '';
            }
        }

        // 1) Log a archivo (solo si WP_DEBUG_LOG activo)
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $msg = "[$timestamp] Tur Transportes DB [$level]: $message";
            if ($context_json) {
                $msg .= ' | CONTEXT=' . $context_json;
            }
            error_log($msg);
        }

        // 2) Log a tabla tt_logs (si existe)
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $table_logs = $wpdb->prefix . 'tt_logs';

        // Verificar existencia tabla (cache simple en runtime)
        static $logs_table_checked = false;
        static $logs_table_exists  = false;

        if (!$logs_table_checked) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_logs));
            $logs_table_exists  = ($exists === $table_logs);
            $logs_table_checked = true;
        }

        if (!$logs_table_exists) {
            return;
        }

        // Insert seguro (nunca lanzar excepción desde aquí)
        $wpdb->insert(
            $table_logs,
            array(
                'type'       => 'db',
                'level'      => $level,
                'message'    => $message,
                'context'    => $context_json,
                'created_at' => $timestamp,
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    /* ============================================================
     * AJAX: tt_get_vehicle_price
     *   → entrypoint AJAX
     *   → usa el motor interno calculate_vehicle_price()
     * ============================================================ */
    public static function get_vehicle_price() {
        try {
            // Seguridad: nonce obligatorio
            if (empty($_POST['nonce']) || !check_ajax_referer('tt_booking_nonce', 'nonce', false)) {
                throw new Exception('Error de verificación de seguridad.');
            }

            // Validar datos mínimos
            if (!isset($_POST['vehicle_id'], $_POST['distance'])) {
                throw new Exception('Datos incompletos para calcular el precio.');
            }

            // Sanitizar entradas
            $vehicle_id       = intval(wp_unslash($_POST['vehicle_id']));
            $distance         = floatval(wp_unslash($_POST['distance']));
            $passengers       = isset($_POST['passengers'])        ? intval(wp_unslash($_POST['passengers']))        : 1;
            $transfer_date    = isset($_POST['transfer_date'])     ? self::sanitize_date(wp_unslash($_POST['transfer_date'])) : '';
            $transfer_time    = isset($_POST['transfer_time'])     ? self::sanitize_time(wp_unslash($_POST['transfer_time'])) : '';
            $transfer_type_id = isset($_POST['transfer_type_id'])  ? intval(wp_unslash($_POST['transfer_type_id']))  : 0;
            $stops_count      = isset($_POST['stops_count'])       ? intval(wp_unslash($_POST['stops_count']))       : 0;

            // Validaciones de negocio
            if ($vehicle_id <= 0) {
                throw new Exception('ID de vehículo inválido.');
            }
            if ($distance <= 0 || $distance > 1000) {
                throw new Exception('La distancia debe estar entre 0.1 y 1000 km.');
            }
            if ($passengers < 1 || $passengers > 50) {
                throw new Exception('Número de pasajeros inválido.');
            }
            if ($stops_count < 0 || $stops_count > 10) {
                throw new Exception('Cantidad de paradas inválida.');
            }
            if (!empty($transfer_date) && !self::validate_future_date($transfer_date)) {
                throw new Exception('La fecha del traslado debe ser hoy o futura.');
            }

            // Motor de cálculo
            $result = self::calculate_vehicle_price(
                $vehicle_id,
                $distance,
                $passengers,
                $transfer_date,
                $transfer_time,
                $transfer_type_id,
                $stops_count
            );

            if (!$result || !is_array($result) || !isset($result['total_price'])) {
                throw new Exception('No se pudo calcular el precio para el vehículo seleccionado.');
            }

            wp_send_json_success($result);

        } catch (Exception $e) {
            self::log('Error en get_vehicle_price (AJAX): ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /* ============================================================
     * SANITIZAR FECHA (YYYY-MM-DD)
     * ============================================================ */
    private static function sanitize_date($date) {
        $sanitized = sanitize_text_field($date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sanitized)) {
            return '';
        }

        list($y, $m, $d) = array_map('intval', explode('-', $sanitized));

        if (!checkdate($m, $d, $y)) {
            return '';
        }

        return $sanitized;
    }

    /* ============================================================
     * SANITIZAR HORA (HH:MM)
     * ============================================================ */
    private static function sanitize_time($time) {
        $sanitized = sanitize_text_field($time);

        if (!preg_match('/^\d{2}:\d{2}$/', $sanitized)) {
            return '';
        }

        list($h, $m) = array_map('intval', explode(':', $sanitized));

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return '';
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /* ============================================================
     * VALIDAR FECHA FUTURA
     * ============================================================ */
    private static function validate_future_date($date) {
        $date = self::sanitize_date($date);
        if (empty($date)) {
            return false;
        }

        // Usamos current_time() para respetar timezone de WP
        $input_ts = strtotime($date . ' 00:00:00');
        $today_ts = strtotime(current_time('Y-m-d') . ' 00:00:00');

        return ($input_ts >= $today_ts);
    }

    /* ============================================================
     * MOTOR DE PRECIOS PRINCIPAL (uso interno y desde otros PHP)
     * ============================================================ */
    public static function calculate_vehicle_price(
        $vehicle_id,
        $distance,
        $passengers       = 1,
        $transfer_date    = '',
        $transfer_time    = '',
        $transfer_type_id = 0,
        $stops_count      = 0
    ) {
        global $wpdb;

        if (!$wpdb) return false;

        // Vehículo
        $vehicle_table = $wpdb->prefix . 'tt_vehicle_types';
        $vehicle_data  = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, base_price, price_per_km, price_per_passenger, capacity, factor_vehiculo
                 FROM $vehicle_table 
                 WHERE id = %d AND active = 1",
                $vehicle_id
            )
        );

        if (!$vehicle_data) return false;

        // Validación ligera de capacidad
        if (!empty($vehicle_data->capacity) && $passengers > intval($vehicle_data->capacity)) {
            return false;
        }

        // Cálculo base
        $base_price      = floatval($vehicle_data->base_price);
        $distance_price  = floatval($vehicle_data->price_per_km) * $distance;
        $passenger_price = ($passengers > 1 && $vehicle_data->price_per_passenger > 0)
            ? floatval($vehicle_data->price_per_passenger) * ($passengers - 1)
            : 0;

        // Subtotal SIN factor
        $subtotal_base = $base_price + $distance_price + $passenger_price;

        // FACTOR VEHÍCULO
        $vehicle_factor        = isset($vehicle_data->factor_vehiculo) ? floatval($vehicle_data->factor_vehiculo) : 0;
        $vehicle_factor_amount = ($vehicle_factor !== 0.0) ? ($base_price * $vehicle_factor) : 0;

        // Subtotal FINAL (incluye factor)
        $subtotal = $subtotal_base + $vehicle_factor_amount;

        // Recargos
        $surcharges      = [];
        $total_surcharge = 0;

        // Agregar recargo Factor Vehículo
        if ($vehicle_factor_amount > 0) {
            $surcharges[] = [
                'name'        => 'Factor Vehículo',
                'type'        => 'vehicle_factor',
                'percent'     => 0,
                'amount'      => $vehicle_factor_amount,
                'description' => 'Recargo adicional por factor del vehículo',
            ];
        }

        // Reglas dinámicas (seasonal, weekly, etc.)
        $pricing_rules   = self::get_applicable_pricing_rules($transfer_date, $transfer_time, $vehicle_id);
        $applied_hashes  = [];

// WHITELIST para evitar recargos no válidos (coherente con el frontend)
$allowed_types = [
    'weekly',
    'seasonal',
    'specific',
    'time_range',
    'vehicle',
    'stops'
];

foreach ($pricing_rules as $type => $rules) {

    // Si el tipo no está permitido, se ignora completamente.
    if (!in_array($type, $allowed_types, true)) {
        continue;
    }

    foreach ($rules as $rule) {

        $name    = isset($rule['name'])    ? $rule['name']    : '';
        $percent = isset($rule['percent']) ? $rule['percent'] : 0;

        // Hash para evitar duplicados
        $hash = md5($name . $percent . $type);
        if (isset($applied_hashes[$hash])) continue;
        $applied_hashes[$hash] = true;

        // Monto basado en el subtotal completo
        $amount = round($subtotal * (floatval($percent) / 100));
        $total_surcharge += $amount;

        $surcharges[] = [
            'name'        => sanitize_text_field($name),
            'type'        => $type,
            'percent'     => floatval($percent),
            'amount'      => $amount,
            'description' => isset($rule['description']) ? sanitize_text_field($rule['description']) : '',
        ];
    }
}


        // Recargo por paradas
        $stops_price = 0;
        if ($stops_count > 0 && $transfer_type_id > 0) {
            $transfer_table = $wpdb->prefix . 'tt_transfer_types';
            $transfer_data  = $wpdb->get_row(
                $wpdb->prepare("SELECT stop_price FROM $transfer_table WHERE id = %d", $transfer_type_id)
            );

            if ($transfer_data && floatval($transfer_data->stop_price) > 0) {
                $stops_price      = $stops_count * floatval($transfer_data->stop_price);
                $total_surcharge += $stops_price;

                $surcharges[] = [
                    'name'        => 'Paradas intermedias (' . intval($stops_count) . ')',
                    'type'        => 'stops',
                    'percent'     => 0,
                    'amount'      => $stops_price,
                    'description' => 'Recargo por paradas intermedias',
                ];
            }
        }

        // Total final
        $total = $subtotal + $total_surcharge;
        $total = self::round_chilean_price($total);

        return [
            'total_price'           => $total,
            'base_price'            => $base_price,
            'distance_price'        => $distance_price,
            'passengers_price'      => $passenger_price,
            'stops_price'           => $stops_price,
            'surcharge_amount'      => $total_surcharge,
            'surcharge_details'     => $surcharges,
            'vehicle_name'          => sanitize_text_field($vehicle_data->name),
            'has_surcharges'        => ($total_surcharge > 0),
            'vehicle_factor'        => $vehicle_factor,
            'vehicle_factor_amount' => $vehicle_factor_amount,

            'price_details' => [
                'basePrice'           => $base_price,
                'distancePrice'       => $distance_price,
                'passengersPrice'     => $passenger_price,
                'vehicleFactorAmount' => $vehicle_factor_amount,
                'subtotal'            => $subtotal, // incluye factor
            ],
        ];
    }

    /* ============================================================
     * REGLAS DINÁMICAS (por día, hora, temporada, vehículo, fecha)
     * ============================================================ */
    public static function get_applicable_pricing_rules($date, $time, $vehicle_id) {
        global $wpdb;

        if (!$wpdb) {
            return array();
        }

        $current_date = !empty($date) ? self::sanitize_date($date) : current_time('Y-m-d');
        $current_time = !empty($time) ? self::sanitize_time($time) : current_time('H:i:s');
        $week_day     = date('w', strtotime($current_date));

        $prefix   = $wpdb->prefix . 'tur_';
        $rules    = array();
        $found    = array();

        // Helper local para evitar duplicados
        $add_rule = function ($type, $row) use (&$rules, &$found) {
            $rule_name = isset($row->rule_name)     ? $row->rule_name     : '';
            $percent   = isset($row->extra_percent) ? $row->extra_percent : 0;
            $desc      = isset($row->description)   ? $row->description   : '';

            $key = md5($rule_name . $percent . $type);
            if (isset($found[$key])) {
                return;
            }
            $found[$key] = true;

            if (!isset($rules[$type])) {
                $rules[$type] = array();
            }

            $rules[$type][] = array(
                'name'        => sanitize_text_field($rule_name),
                'percent'     => floatval($percent),
                'description' => sanitize_text_field($desc),
            );
        };

        // 1) Reglas semanales
        $tbl_w = $prefix . 'pricing_rules_weekly';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_w)) === $tbl_w) {
            $weekly = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_name, extra_percent, description
                     FROM $tbl_w
                     WHERE active = 1
                       AND (days_of_week = '' OR days_of_week LIKE %s)",
                    '%' . $wpdb->esc_like($week_day) . '%'
                )
            );
            if (!empty($weekly)) {
                foreach ($weekly as $row) {
                    $add_rule('weekly', $row);
                }
            }
        }

        // 2) Reglas por rango horario
        $tbl_t = $prefix . 'pricing_rules_time_range';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_t)) === $tbl_t) {
            $hour_rules = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_name, extra_percent, description
                     FROM $tbl_t
                     WHERE active = 1
                       AND start_time <= %s
                       AND end_time   >= %s
                       AND (vehicle_type_id IS NULL OR vehicle_type_id = %d)",
                    $current_time,
                    $current_time,
                    $vehicle_id
                )
            );
            if (!empty($hour_rules)) {
                foreach ($hour_rules as $row) {
                    $add_rule('time_range', $row);
                }
            }
        }

        // 3) Reglas estacionales
        $tbl_s = $prefix . 'pricing_rules_seasonal';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_s)) === $tbl_s) {
            $seasonal = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_name, extra_percent, description
                     FROM $tbl_s
                     WHERE active = 1
                       AND ((start_date IS NULL AND end_date IS NULL)
                         OR (start_date <= %s AND end_date >= %s))
                       AND (vehicle_type_id IS NULL OR vehicle_type_id = %d)",
                    $current_date,
                    $current_date,
                    $vehicle_id
                )
            );
            if (!empty($seasonal)) {
                foreach ($seasonal as $row) {
                    $add_rule('seasonal', $row);
                }
            }
        }

        // 4) Reglas por tipo de vehículo
        $tbl_v = $prefix . 'pricing_rules_vehicle';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_v)) === $tbl_v) {
            $vehicle_rules = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_name, extra_percent, description
                     FROM $tbl_v
                     WHERE active = 1
                       AND vehicle_type_id = %d",
                    $vehicle_id
                )
            );
            if (!empty($vehicle_rules)) {
                foreach ($vehicle_rules as $row) {
                    $add_rule('vehicle', $row);
                }
            }
        }

        // 5) Reglas por fecha específica
        $tbl_f = $prefix . 'pricing_rules_specific';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_f)) === $tbl_f) {
            $specific_rules = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT extra_percent, description
                     FROM $tbl_f
                     WHERE date = %s
                       AND start_hour <= %s
                       AND end_hour   >= %s
                       AND (vehicle_type_id IS NULL OR vehicle_type_id = %d)",
                    $current_date,
                    $current_time,
                    $current_time,
                    $vehicle_id
                )
            );
            if (!empty($specific_rules)) {
                foreach ($specific_rules as $row) {
                    $fake = (object) array(
                        'rule_name'     => 'Recargo por fecha específica',
                        'extra_percent' => $row->extra_percent,
                        'description'   => $row->description,
                    );
                    $add_rule('specific', $fake);
                }
            }
        }

        return $rules;
    }

    /* ============================================================
     * REDONDEO DE PRECIOS (formato CLP)
     * ============================================================ */
    public static function round_chilean_price($price) {
        return round($price / 50) * 50;
    }

    /* ============================================================
     * CREAR / ACTUALIZAR TABLAS
     * ============================================================ */
    public static function create_tables() {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();
        self::log('Iniciando creación/verificación de tablas...');

        $table_transfer_types = $wpdb->prefix . 'tt_transfer_types';
        $table_vehicle_types  = $wpdb->prefix . 'tt_vehicle_types';
        $table_bookings       = $wpdb->prefix . 'tt_bookings';
        $table_transbank      = $wpdb->prefix . 'tt_tbk_trans';
        $table_logs           = $wpdb->prefix . 'tt_logs';

        $sql_transfer_types = "CREATE TABLE $table_transfer_types (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            description text,
            base_price decimal(10,2) DEFAULT 0,
            price_per_km decimal(10,2) DEFAULT 0,
            stop_price decimal(10,2) DEFAULT 0,
            allows_stops tinyint(1) DEFAULT 0,
            active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY active (active)
        ) $charset_collate;";

        $sql_vehicle_types = "CREATE TABLE $table_vehicle_types (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            description text,
            capacity smallint(3) DEFAULT 4,
            base_price decimal(10,2) DEFAULT 0,
            price_per_km decimal(10,2) DEFAULT 0,
            price_per_passenger decimal(10,2) DEFAULT 0,
            factor_vehiculo decimal(10,2) DEFAULT 1,
            image_url varchar(255),
            active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY capacity (capacity)
        ) $charset_collate;";

        // IMPORTANTE: solo distance_km como campo oficial de distancia
        $sql_bookings = "CREATE TABLE $table_bookings (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            booking_code varchar(20) NOT NULL,
            customer_rut varchar(20),
            customer_name varchar(100) NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_phone varchar(20) NOT NULL,
            origin_address text NOT NULL,
            destination_address text NOT NULL,
            transfer_date date NOT NULL,
            transfer_time time NOT NULL,
            passengers smallint(2) DEFAULT 1,
            distance_km decimal(8,2) DEFAULT 0,
            duration varchar(50),
            route_polyline mediumtext NULL,
            route_points   mediumtext NULL,
            vehicle_type_id mediumint(9),
            transfer_type_id mediumint(9),
            total_price decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            payment_status varchar(20) DEFAULT 'pending',
            payment_method varchar(50),
            payment_reference varchar(100),
            stops_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY booking_code (booking_code),
            KEY vehicle_type_id (vehicle_type_id),
            KEY transfer_type_id (transfer_type_id),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY created_at (created_at),
            KEY customer_email (customer_email)
        ) $charset_collate;";

        $sql_transbank = "CREATE TABLE $table_transbank (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            buy_order varchar(50) NOT NULL,
            session_id varchar(100),
            amount decimal(10,2) NOT NULL,
            token varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'initialized',
            response_code varchar(10),
            authorization_code varchar(20),
            payment_type_code varchar(10),
            installments_number int(11) DEFAULT 0,
            card_number varchar(20),
            transaction_date datetime,
            booking_data text,
            booking_id mediumint(9),
            environment varchar(20) DEFAULT 'integration',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY token (token),
            KEY buy_order (buy_order),
            KEY status (status),
            KEY booking_id (booking_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Tabla de logs PRO
        $sql_logs = "CREATE TABLE $table_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            level varchar(10) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";

        self::log('Ejecutando dbDelta para actualizar/crear tablas...');

        dbDelta($sql_transfer_types);
        dbDelta($sql_vehicle_types);
        dbDelta($sql_bookings);
        dbDelta($sql_transbank);
        dbDelta($sql_logs);

        self::log('Proceso de creación/actualización de tablas completado');

        return true;
    }

    /* ============================================================
     * SINCRONIZAR TODAS LAS TABLAS (llamado desde TT_Init)
     * ============================================================ */
    public static function sync_all_tables() {
        global $wpdb;

        self::log('Verificando estructura de tablas (sync_all_tables)...');

        $tables_to_check = array(
            $wpdb->prefix . 'tt_transfer_types',
            $wpdb->prefix . 'tt_vehicle_types',
            $wpdb->prefix . 'tt_bookings',
            $wpdb->prefix . 'tt_tbk_trans',
            $wpdb->prefix . 'tt_logs',
        );

        $all_ok = true;

        foreach ($tables_to_check as $table_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));

            if ($exists !== $table_name) {
                self::log("La tabla $table_name no existe.", 'warning');
                $all_ok = false;
                continue;
            }

            $test_result = $wpdb->query("SELECT 1 FROM `$table_name` LIMIT 1");
            if ($test_result === false) {
                self::log("Tabla $table_name existe pero no es accesible.", 'error');
                $all_ok = false;
            } else {
                self::log("Tabla $table_name existe y es accesible.");
            }
        }

        if (!$all_ok) {
            self::log('Problemas detectados, ejecutando create_tables() para reparar/esquematizar...', 'warning');
            self::create_tables();
        } else {
            self::log('Todas las tablas están OK (no se requiere acción).');
        }

        return $all_ok;
    }

    /* ============================================================
     * CRUD DE RESERVAS (uso general / panel / integraciones)
     *  ⚠️ Webpay no llama esto, usa TT_Transbank_Handler::save_approved_booking()
     * ============================================================ */

    /**
     * Guarda una reserva genérica (modo manual / API interna).
     * NO SE USA PARA WEBPAY.
     *
     * A nivel de distancia se usa SOLO distance_km como campo oficial.
     *
     * @param array $booking_data
     * @return int|false
     */
    public static function save_booking($booking_data = array()) {
        global $wpdb;

        try {
            if (empty($booking_data) || !is_array($booking_data)) {
                self::log('save_booking llamado sin datos válidos', 'warning');
                return false;
            }

            $table_name = $wpdb->prefix . 'tt_bookings';

            // Normalizar distancia: distance_km es la fuente oficial
            $distance_km = 0;
            if (isset($booking_data['distance_km'])) {
                $distance_km = floatval($booking_data['distance_km']);
            } elseif (isset($booking_data['distance'])) {
                $distance_km = floatval($booking_data['distance']);
            }

            $sanitized_data = array(
                'booking_code'       => substr(sanitize_text_field($booking_data['booking_code'] ?? ''), 0, 20),
                'customer_rut'       => isset($booking_data['customer_rut']) ? substr(sanitize_text_field($booking_data['customer_rut']), 0, 20) : '',
                'customer_name'      => substr(sanitize_text_field($booking_data['customer_name'] ?? ''), 0, 100),
                'customer_email'     => substr(sanitize_email($booking_data['customer_email'] ?? ''), 0, 100),
                'customer_phone'     => substr(sanitize_text_field($booking_data['customer_phone'] ?? ''), 0, 20),
                'origin_address'     => wp_strip_all_tags($booking_data['origin_address'] ?? ''),
                'destination_address'=> wp_strip_all_tags($booking_data['destination_address'] ?? ''),
                'transfer_date'      => self::sanitize_date($booking_data['transfer_date'] ?? ''),
                'transfer_time'      => self::sanitize_time($booking_data['transfer_time'] ?? ''),
                'passengers'         => intval($booking_data['passengers'] ?? 1),
                'distance_km'        => $distance_km,
                'duration'           => substr(sanitize_text_field($booking_data['duration'] ?? ''), 0, 50),
                'vehicle_type_id'    => intval($booking_data['vehicle_type_id'] ?? 0),
                'transfer_type_id'   => intval($booking_data['transfer_type_id'] ?? 0),
                'total_price'        => floatval($booking_data['total_price'] ?? 0),
                'stops_data'         => isset($booking_data['stops_data']) ? wp_json_encode($booking_data['stops_data']) : '',
                // Para reservas manuales dejamos estado pending/confirmed según integraciones
                'status'             => !empty($booking_data['status'])
                    ? sanitize_text_field($booking_data['status'])
                    : 'pending',
                'payment_status'     => !empty($booking_data['payment_status'])
                    ? sanitize_text_field($booking_data['payment_status'])
                    : 'pending',
            );

            if (empty($sanitized_data['booking_code'])) {
                $sanitized_data['booking_code'] = self::generate_booking_code();
            }

            if (empty($sanitized_data['customer_email']) || !is_email($sanitized_data['customer_email'])) {
                throw new Exception('Email del cliente inválido.');
            }

            if (empty($sanitized_data['transfer_date']) || empty($sanitized_data['transfer_time'])) {
                throw new Exception('Fecha u hora de traslado inválida.');
            }

            $result = $wpdb->insert($table_name, $sanitized_data);

            if ($result) {
                self::log(
                    'Reserva creada exitosamente (save_booking)',
                    'info',
                    array('booking_code' => $sanitized_data['booking_code'])
                );
                return (int) $wpdb->insert_id;
            }

            self::log('Error al guardar reserva: ' . $wpdb->last_error, 'error');
            return false;

        } catch (Exception $e) {
            self::log('Error en save_booking: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function get_booking_by_code($booking_code) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tt_bookings';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE booking_code = %s",
                sanitize_text_field($booking_code)
            )
        );
    }

    public static function update_booking_status($booking_id, $status, $payment_status = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tt_bookings';

        $data   = array(
            'status' => sanitize_text_field($status),
        );
        $format = array('%s');

        if ($payment_status !== null) {
            $data['payment_status'] = sanitize_text_field($payment_status);
            $format[] = '%s';
        }

        $where        = array('id' => intval($booking_id));
        $where_format = array('%d');

        $result = $wpdb->update(
            $table_name,
            $data,
            $where,
            $format,
            $where_format
        );

        if ($result !== false) {
            self::log('Estado de reserva actualizado', 'info', array(
                'booking_id'     => $booking_id,
                'status'         => $status,
                'payment_status' => $payment_status,
            ));
            return true;
        }

        self::log(
            'Error al actualizar estado de reserva - ID: ' . $booking_id . ' - ' . $wpdb->last_error,
            'error'
        );
        return false;
    }

    public static function generate_booking_code() {
        return 'TT' . date('Ymd') . strtoupper(wp_generate_password(6, false, false));
    }
}
