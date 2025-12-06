<?php
if (!defined('ABSPATH')) exit;

class TT_Email_Handler {

    /* ================================
     *  INIT
     * ================================ */
    public static function init() {
        add_action('tt_booking_confirmed',  [__CLASS__, 'send_booking_confirmation'], 10, 2);
        add_action('tt_payment_approved',   [__CLASS__, 'send_payment_confirmation'], 10, 2);
        add_action('tt_payment_rejected',   [__CLASS__, 'send_payment_rejection'],    10, 2);
        add_action('tt_booking_cancelled',  [__CLASS__, 'send_booking_cancellation'], 10, 2);
    }

    /* ================================
     *  HELPERS COMUNES
     * ================================ */

    /** Evita errores y valores vacíos, ignorando objetos/arrays */
    private static function safe($value, $fallback = '—') {
        if (!isset($value)) {
            return $fallback;
        }

        if (is_object($value) || is_array($value)) {
            return $fallback;
        }

        $value = (string) $value;

        return ($value === '')
            ? $fallback
            : esc_html($value);
    }

    /** Sanitizar HTML simple para correos */
    private static function clean_html($html) {
        return wp_kses_post($html);
    }

    /** Formato moneda CLP */
    private static function format_currency($amount, $symbol = '$') {
        if ($amount === null || $amount === '') {
            $amount = 0;
        }
        $amount = (float) $amount;
        return $symbol . number_format($amount, 0, ',', '.');
    }

    /** Formato distancia km */
    private static function format_km($km) {
        $km = (float) $km;
        return number_format($km, 2, ',', '.') . ' km';
    }

    /** Headers estándar */
    private static function headers() {
        $from_name  = get_option('tt_email_from_name', 'Tur Transportes');
        $from_email = get_option('tt_contact_email', get_option('admin_email'));

        $from_name  = sanitize_text_field($from_name);
        $from_email = sanitize_email($from_email);

        return [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>"
        ];
    }

    /** Envía un correo seguro */
    private static function send_mail($to, $subject, $message) {

        error_log("Enviando a: $to | Asunto: $subject");

        $to = sanitize_email($to);
        if (empty($to) || !is_email($to)) {
            error_log("Email inválido: $to");
            return false;
        }

        $result = wp_mail(
            $to,
            sanitize_text_field($subject),
            self::clean_html($message),
            self::headers()
        );

        error_log("Resultado wp_mail: " . var_export($result, true));

        return $result;
    }

    /** Envío múltiple a administradores (optimizado) */
    private static function send_to_admins($subject, $message) {
        $raw    = get_option('tt_admin_emails', '');
        $emails = array_filter(array_map('trim', explode(',', (string) $raw)));

        if (empty($emails)) {
            $emails[] = get_option('tt_contact_email');
        }
        if (empty($emails[0])) {
            $emails[0] = get_option('admin_email');
        }

        $emails  = array_unique(array_map('sanitize_email', $emails));
        $all_ok  = true;

        foreach ($emails as $email) {
            if (!$email || !is_email($email)) {
                continue;
            }
            $ok = self::send_mail($email, $subject, $message);
            if (!$ok) {
                $all_ok = false;
            }
        }

        return $all_ok;
    }

    /* ================================
     *  OBTENCIÓN DE BOOKING + TBK/JSON
     * ================================ */

    /**
     * Obtiene booking + joins + último registro de Transbank (si existe)
     * y adjunta booking_data decodificado (priceDetails, surchargeData, stops, etc.)
     */
    private static function get_booking($id) {
        global $wpdb;

        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $table_b   = $wpdb->prefix . 'tt_bookings';
        $table_vt  = $wpdb->prefix . 'tt_vehicle_types';
        $table_tt  = $wpdb->prefix . 'tt_transfer_types';
        $table_tbk = $wpdb->prefix . 'tt_tbk_trans';

        // Booking + nombres amigables
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    b.*,
                    vt.name AS vehicle_name,
                    tt.name AS transfer_name
                 FROM {$table_b} b
                 LEFT JOIN {$table_vt} vt ON b.vehicle_type_id = vt.id
                 LEFT JOIN {$table_tt} tt ON b.transfer_type_id = tt.id
                 WHERE b.id = %d
                 LIMIT 1",
                $id
            )
        );

        if (!$booking) {
            return null;
        }

        // Última transacción TBK asociada (si la hay)
        $tbk_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_tbk}
                 WHERE booking_id = %d
                 ORDER BY id DESC
                 LIMIT 1",
                $booking->id
            )
        );

        if ($tbk_row) {
            $booking->tbk_raw = $tbk_row;

            $booking_data = [];
            if (!empty($tbk_row->booking_data)) {
                $decoded = json_decode($tbk_row->booking_data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $booking_data = $decoded;
                }
            }

            $booking->booking_data = $booking_data;

            // Normalización de priceDetails y surchargeData
            if (isset($booking_data['priceDetails']) && is_array($booking_data['priceDetails'])) {
                $booking->price_details = $booking_data['priceDetails'];
            }

            if (isset($booking_data['surchargeData']) && is_array($booking_data['surchargeData'])) {
                $booking->surcharge_data = $booking_data['surchargeData'];
            }

            if (isset($booking_data['stops']) && is_array($booking_data['stops'])) {
                $booking->stops_from_booking_data = $booking_data['stops'];
            }

            if (isset($booking_data['route_polyline'])) {
                $booking->route_polyline = $booking_data['route_polyline'];
            }

            if (isset($booking_data['route_points'])) {
                $booking->route_points = $booking_data['route_points'];
            }
        }

        // Stops desde columna stops_data (JSON) como fallback
        if (!empty($booking->stops_data) && empty($booking->stops_from_booking_data)) {
            $decoded_stops = json_decode($booking->stops_data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_stops)) {
                $booking->stops_from_booking_data = $decoded_stops;
            }
        }

        return $booking;
    }

    /* ================================
     *  SISTEMA DE PLANTILLAS OVERRIDE
     * ================================ */

    /**
     * Busca un template en:
     *  1) child-theme/tur-transportes/emails/{slug}.php
     *  2) theme/tur-transportes/emails/{slug}.php
     *  3) plugin/templates/emails/{slug}.php (por defecto)
     */
    private static function locate_template($slug) {
        $slug = sanitize_file_name($slug) . '.php';

        $paths = [
            trailingslashit(get_stylesheet_directory()) . 'tur-transportes/emails/' . $slug,
            trailingslashit(get_template_directory())   . 'tur-transportes/emails/' . $slug,
            plugin_dir_path(__FILE__) . '../templates/emails/' . $slug,
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    /**
     * Render genérico de template con fallback a HTML interno
     */
    private static function render_template_or_fallback($slug, $vars, callable $fallback_builder) {
        $template = self::locate_template($slug);

        if ($template) {
            ob_start();
            extract($vars, EXTR_SKIP);
            include $template;
            return ob_get_clean();
        }

        // Fallback: usar el builder interno
        return call_user_func_array($fallback_builder, $vars);
    }

    /* ================================
     *  PROTECCIÓN ANTI-DUPLICACIÓN
     * ================================ */

    private static function was_recently_sent($booking_id, $type) {
        $booking_id = (int) $booking_id;
        if ($booking_id <= 0) return false;

        $key = "tt_email_{$type}_{$booking_id}";
        return (bool) get_transient($key);
    }

    private static function mark_as_sent($booking_id, $type, $ttl = DAY_IN_SECONDS) {
        $booking_id = (int) $booking_id;
        if ($booking_id <= 0) return;

        $key = "tt_email_{$type}_{$booking_id}";
        set_transient($key, 1, $ttl);
    }

    /* ============================================================
     *  HELPERS DE CONTENIDO (MAPS, PRECIOS, STOPS)
     * ============================================================ */

    /**
     * Construye un link Google Maps de la ruta (origen, destino y paradas)
     */
    private static function build_maps_link($b) {
        $origin      = isset($b->origin_address)      ? $b->origin_address      : '';
        $destination = isset($b->destination_address) ? $b->destination_address : '';

        if (!$origin || !$destination) {
            return '';
        }

        $origin_enc      = rawurlencode($origin);
        $destination_enc = rawurlencode($destination);

        $waypoints_enc = '';
        $stops         = [];

        if (!empty($b->stops_from_booking_data) && is_array($b->stops_from_booking_data)) {
            foreach ($b->stops_from_booking_data as $stop) {
                if (is_array($stop) && !empty($stop['address'])) {
                    $stops[] = $stop['address'];
                } elseif (is_object($stop) && !empty($stop->address)) {
                    $stops[] = $stop->address;
                } elseif (is_string($stop)) {
                    $stops[] = $stop;
                }
            }
        }

        if (!empty($stops)) {
            $waypoints_enc = rawurlencode(implode('|', $stops));
        }

        $url = 'https://www.google.com/maps/dir/?api=1'
             . '&origin=' . $origin_enc
             . '&destination=' . $destination_enc;

        if ($waypoints_enc) {
            $url .= '&waypoints=' . $waypoints_enc;
        }

        return esc_url($url);
    }

    /**
     * Devuelve un array normalizado con desglose de precios usando booking_data->priceDetails y surchargeData
     */
    private static function build_price_breakdown($b) {
        $total = isset($b->total_price) ? (float) $b->total_price : 0;

        $price_details = [];
        if (isset($b->price_details) && is_array($b->price_details)) {
            $price_details = $b->price_details;
        } elseif (isset($b->booking_data['priceDetails']) && is_array($b->booking_data['priceDetails'])) {
            $price_details = $b->booking_data['priceDetails'];
        }

        $surcharge_data = [];
        if (isset($b->surcharge_data) && is_array($b->surcharge_data)) {
            $surcharge_data = $b->surcharge_data;
        } elseif (isset($b->booking_data['surchargeData']) && is_array($b->booking_data['surchargeData'])) {
            $surcharge_data = $b->booking_data['surchargeData'];
        }

        $basePrice           = isset($price_details['basePrice'])           ? (float) $price_details['basePrice']           : 0;
        $distancePrice       = isset($price_details['distancePrice'])       ? (float) $price_details['distancePrice']       : 0;
        $passengersPrice     = isset($price_details['passengersPrice'])     ? (float) $price_details['passengersPrice']     : 0;
        $vehicleFactorAmount = isset($price_details['vehicleFactorAmount']) ? (float) $price_details['vehicleFactorAmount'] : 0;
        $subtotal            = isset($price_details['subtotal'])            ? (float) $price_details['subtotal']            : ($basePrice + $distancePrice + $passengersPrice + $vehicleFactorAmount);

        $stopsPrice = 0;
        if (isset($b->booking_data['stopsPrice'])) {
            $stopsPrice = (float) $b->booking_data['stopsPrice'];
        } elseif (isset($b->stops_price)) {
            $stopsPrice = (float) $b->stops_price;
        }

        $surcharge_amount  = 0;
        $surcharge_details = [];

        if (!empty($surcharge_data)) {
            if (isset($surcharge_data['surcharge_amount'])) {
                $surcharge_amount = (float) $surcharge_data['surcharge_amount'];
            }
            if (!empty($surcharge_data['surcharge_details']) && is_array($surcharge_data['surcharge_details'])) {
                $surcharge_details = $surcharge_data['surcharge_details'];
            }
        }

        // Si no hay breakdown real, devolvemos sólo total
        $has_any_detail = $basePrice || $distancePrice || $passengersPrice || $vehicleFactorAmount || $stopsPrice || $surcharge_amount;
        if (!$has_any_detail) {
            return [
                'has_details'        => false,
                'total'              => $total,
            ];
        }

        return [
            'has_details'         => true,
            'total'               => $total,
            'basePrice'           => $basePrice,
            'distancePrice'       => $distancePrice,
            'passengersPrice'     => $passengersPrice,
            'vehicleFactorAmount' => $vehicleFactorAmount,
            'stopsPrice'          => $stopsPrice,
            'subtotal'            => $subtotal,
            'surcharge_amount'    => $surcharge_amount,
            'surcharge_details'   => $surcharge_details,
        ];
    }

    /**
     * Genera HTML de desglose de precios con diseño azul oscuro
     */
    private static function build_price_breakdown_html($b) {
        $bd = self::build_price_breakdown($b);

        // Sin detalles, solo total
        if (!$bd['has_details']) {
            return "
                <div style='background:#eef3fb;padding:14px;border-radius:10px;text-align:center;
                            font-size:17px;font-weight:700;color:#0a2540;'>
                    Total: " . self::format_currency($bd['total']) . "
                </div>";
        }

        $rows = "";

        $add_row = function($label, $amount) use (&$rows) {
            if ($amount <= 0) return;
            $rows .= "
                <tr>
                    <td style='padding:6px 0;color:#0a2540;font-weight:500;'>" . esc_html($label) . "</td>
                    <td style='padding:6px 0;text-align:right;color:#0a2540;'>" . TT_Email_Handler::format_currency($amount) . "</td>
                </tr>";
        };

        $add_row("Tarifa base",           $bd['basePrice']);
        $add_row("Distancia",             $bd['distancePrice']);
        $add_row("Pasajeros adicionales", $bd['passengersPrice']);
        $add_row("Factor vehículo",       $bd['vehicleFactorAmount']);
        $add_row("Paradas intermedias",   $bd['stopsPrice']);

        $rows .= "
            <tr>
                <td colspan='2' style='border-top:1px solid #d4ddee;padding:8px 0;'></td>
            </tr>
            <tr>
                <td style='padding:6px 0;font-weight:700;color:#0a2540;'>Subtotal</td>
                <td style='padding:6px 0;text-align:right;font-weight:700;color:#0a2540;'>" .
                    self::format_currency($bd['subtotal']) . "</td>
            </tr>";

        if (!empty($bd['surcharge_details'])) {
            foreach ($bd['surcharge_details'] as $sc) {
                if (!is_array($sc) && !is_object($sc)) {
                    continue;
                }

                $sc_name   = is_array($sc) ? ($sc['name']   ?? '') : ($sc->name   ?? '');
                $sc_amount = is_array($sc) ? ($sc['amount'] ?? 0)  : ($sc->amount ?? 0);

                if ($sc_amount <= 0) continue;

                $add_row($sc_name, $sc_amount);
            }

            $add_row("Total recargos", $bd['surcharge_amount']);
        }

        $rows .= "
            <tr>
                <td colspan='2' style='border-top:2px solid #0a2540;padding:10px 0;'></td>
            </tr>
            <tr>
                <td style='font-size:17px;font-weight:700;color:#0a2540;'>Total</td>
                <td style='font-size:17px;font-weight:700;color:#0a2540;text-align:right;'>" .
                    self::format_currency($bd['total']) . "</td>
            </tr>";

        return "
        <div style='background:#ffffff;border-radius:12px;padding:18px;
                    box-shadow:0 2px 4px rgba(0,0,0,0.06);border:1px solid #d0def5;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;'>
                {$rows}
            </table>
        </div>";
    }

    /**
     * HTML de listado de paradas intermedias (si existen)
     */
    private static function build_stops_html($b) {
        if (empty($b->stops_from_booking_data) || !is_array($b->stops_from_booking_data)) {
            return '';
        }

        $items = '';
        foreach ($b->stops_from_booking_data as $idx => $stop) {
            $label   = 'Parada ' . ($idx + 1);
            $address = '';

            if (is_array($stop) && !empty($stop['address'])) {
                $address = $stop['address'];
            } elseif (is_object($stop) && !empty($stop->address)) {
                $address = $stop->address;
            } elseif (is_string($stop)) {
                $address = $stop;
            }

            if (!$address) {
                continue;
            }

            $items .= "<li style='margin-bottom:4px;color:#0a2540;'><strong>" .
                        esc_html($label) . ":</strong> " . esc_html($address) . "</li>";
        }

        if (!$items) {
            return '';
        }

        return "
            <ul style='margin:8px 0 0 18px;padding:0;list-style:disc;color:#0a2540;'>{$items}</ul>";
    }

    /* ================================
     *  EVENTOS DE RESERVA Y PAGO
     * ================================ */

    /**
     * Confirmación de reserva (cliente + admin)
     */
    public static function send_booking_confirmation($booking_id, $transaction_data = null) {
        if (self::was_recently_sent($booking_id, 'booking_confirmation')) {
            return false;
        }

        $booking = self::get_booking($booking_id);
        if (!$booking) {
            return false;
        }

        // CLIENTE
        $html_client = self::render_template_or_fallback(
            'booking-confirmation-client',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_booking_confirmation_client']
        );

        // ADMIN
        $html_admin = self::render_template_or_fallback(
            'booking-confirmation-admin',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_booking_confirmation_admin']
        );

        $ok1 = self::send_mail($booking->customer_email, 'Confirmación de Reserva', $html_client);
        $ok2 = self::send_to_admins("Nueva Reserva Confirmada - {$booking->booking_code}", $html_admin);

        if ($ok1 || $ok2) {
            self::mark_as_sent($booking_id, 'booking_confirmation');
        }

        return $ok1 && $ok2;
    }

    /**
     * Confirmación de pago (cliente + admin)
     */
    public static function send_payment_confirmation($booking_id, $transaction_data) {
        if (self::was_recently_sent($booking_id, 'payment_confirmation')) {
            return false;
        }

        $booking = self::get_booking($booking_id);
        if (!$booking) {
            return false;
        }

        $html_client = self::render_template_or_fallback(
            'payment-confirmation-client',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_payment_confirmation_client']
        );

        $html_admin = self::render_template_or_fallback(
            'payment-confirmation-admin',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_payment_confirmation_admin']
        );

        $ok1 = self::send_mail($booking->customer_email, 'Comprobante de Pago', $html_client);
        $ok2 = self::send_to_admins("Pago Confirmado - {$booking->booking_code}", $html_admin);

        if ($ok1 || $ok2) {
            self::mark_as_sent($booking_id, 'payment_confirmation');
        }

        return $ok1 && $ok2;
    }

    /**
     * Pago rechazado (cliente + admin)
     */
    public static function send_payment_rejection($booking_id, $transaction_data) {
        if (self::was_recently_sent($booking_id, 'payment_rejection')) {
            return false;
        }

        $booking = self::get_booking($booking_id);
        if (!$booking) {
            return false;
        }

        $html_client = self::render_template_or_fallback(
            'payment-rejected-client',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_payment_rejected_client']
        );

        $html_admin = self::render_template_or_fallback(
            'payment-rejected-admin',
            ['b' => $booking, 't' => $transaction_data],
            [__CLASS__, 'tpl_payment_rejected_admin']
        );

        $ok1 = self::send_mail($booking->customer_email, 'Pago Rechazado', $html_client);
        $ok2 = self::send_to_admins("Pago Rechazado - {$booking->booking_code}", $html_admin);

        if ($ok1 || $ok2) {
            self::mark_as_sent($booking_id, 'payment_rejection');
        }

        return $ok1 && $ok2;
    }

    /**
     * Cancelación de reserva (solo cliente)
     */
    public static function send_booking_cancellation($booking_id, $reason = '') {
        if (self::was_recently_sent($booking_id, 'booking_cancellation')) {
            return false;
        }

        $booking = self::get_booking($booking_id);
        if (!$booking) {
            return false;
        }

        $html = self::render_template_or_fallback(
            'booking-cancellation-client',
            ['b' => $booking, 'reason' => $reason],
            [__CLASS__, 'tpl_booking_cancellation']
        );

        $ok = self::send_mail($booking->customer_email, 'Reserva Cancelada', $html);

        if ($ok) {
            self::mark_as_sent($booking_id, 'booking_cancellation');
        }

        return $ok;
    }

    /* ================================
     *  TEMPLATES INTERNOS (FALLBACK PRO)
     * ================================ */

    private static function email_header($title, $color) {
        $title   = esc_html($title);
        $brand   = esc_html(get_option('tt_email_from_name', 'Tur Transportes'));

        return "
        <div style=\"background:#020617;
                    background:linear-gradient(135deg,#020617 0%,#0b1f3b 45%,#14376b 100%);
                    padding:22px 24px 18px;
                    border-radius:16px 16px 0 0;
                    text-align:left;
                    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;\">
            <div style=\"font-size:11px;letter-spacing:.14em;text-transform:uppercase;
                         color:#93c5fd;opacity:0.85;margin-bottom:8px;font-weight:600;\">{$brand}</div>
            <h1 style=\"margin:0;font-size:22px;line-height:1.35;font-weight:600;color:#f9fafb;\">{$title}</h1>
        </div>";
    }

    private static function email_wrapper($body) {
        $contact_email = esc_html(get_option('tt_contact_email', get_option('admin_email')));

        return "
        <div style=\"background:#020617;padding:24px 12px;\">
            <div style=\"max-width:640px;margin:0 auto;
                        background:#f8fafc;
                        border-radius:0 0 16px 16px;
                        overflow:hidden;
                        box-shadow:0 18px 45px rgba(15,23,42,0.55);
                        border:1px solid rgba(15,23,42,0.7);
                        border-top:none;
                        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
                        font-size:14px;color:#0f172a;
                        padding:22px 22px 24px;\">
                {$body}
                <div style=\"margin-top:24px;padding-top:16px;border-top:1px solid #cbd5f5;
                            font-size:12px;color:#64748b;text-align:center;line-height:1.5;\">
                    <p style=\"margin:0 0 4px;\">Este es un correo autom&aacute;tico de Turivodigital.com. Por favor no respondas a este mensaje.</p>
                    <p style=\"margin:0;\">Ante cualquier duda, cont&aacute;ctanos a {$contact_email}</p>
                </div>
            </div>
        </div>";
    }

    private static function card($title, $content, $color = '#0a2540') {
        $title = esc_html($title);
        $color = esc_attr($color);

        return "
        <div style=\"background:#ffffff;
                    border-radius:12px;
                    padding:18px 20px;
                    margin-bottom:16px;
                    border:1px solid #d0def5;
                    box-shadow:0 4px 12px rgba(15,23,42,0.06);\">
            <h3 style=\"margin:0 0 8px;
                       color:{$color};
                       font-size:15px;
                       font-weight:600;
                       font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;\">{$title}</h3>
            <div style=\"font-size:13px;line-height:1.6;color:#0f172a;\">{$content}</div>
        </div>";
    }

    /* =========
     * 1) Confirmación reserva (cliente)
     * ========= */
    private static function tpl_booking_confirmation_client($b, $t = null) {

        $fecha = $b->transfer_date ? date('d/m/Y', strtotime($b->transfer_date)) : '—';
        $hora  = $b->transfer_time ? date('H:i', strtotime($b->transfer_time)) : '—';

        $header = self::email_header('¡Reserva Confirmada!', '#1e58a6');

        $maps_link  = self::build_maps_link($b);
        $stops_html = self::build_stops_html($b);
        $price_html = self::build_price_breakdown_html($b);

        $body  = self::card('Detalles de la Reserva', "
            <p><strong>Código:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Fecha:</strong> {$fecha}</p>
            <p><strong>Hora:</strong> {$hora}</p>
            <p><strong>Tipo de traslado:</strong> " . self::safe($b->transfer_name, 'No especificado') . "</p>
            <p><strong>Vehículo:</strong> " . self::safe($b->vehicle_name, 'Por asignar') . "</p>
            <p><strong>Pasajeros:</strong> " . intval($b->passengers) . "</p>
            <p><strong>Origen:</strong> " . self::safe($b->origin_address) . "</p>
            <p><strong>Destino:</strong> " . self::safe($b->destination_address) . "</p>" .
            ($stops_html ? "<p><strong>Paradas intermedias:</strong></p>{$stops_html}" : "") . "
            <p><strong>Distancia estimada:</strong> " . self::format_km($b->distance_km) . "</p>
            <p><strong>Duración estimada:</strong> " . self::safe($b->duration, '—') . "</p>" .
            ($maps_link ? "<p style='margin-top:12px;'>
                <a href=\"{$maps_link}\" target=\"_blank\" rel=\"noopener\" 
                   style=\"display:inline-block;background:#0f172a;color:#fff;text-decoration:none;
                          padding:8px 14px;border-radius:6px;font-size:13px;\">
                    Ver ruta en Google Maps
                </a>
            </p>" : "") . "
        ");

        $body .= self::card('Información del Cliente', "
            <p><strong>Nombre:</strong> " . self::safe($b->customer_name) . "</p>
            <p><strong>Email:</strong> " . self::safe($b->customer_email) . "</p>
            <p><strong>Teléfono:</strong> " . self::safe($b->customer_phone) . "</p>
            " . ($b->customer_rut ? "<p><strong>RUT:</strong> " . self::safe($b->customer_rut) . "</p>" : "") . "
        ");

        $body .= self::card('Estado y Pagos', "
            <p><strong>Estado de la reserva:</strong> " . self::safe($b->status) . "</p>
            <p><strong>Estado de pago:</strong> " . self::safe($b->payment_status) . "</p>
            {$price_html}
        ");

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 2) Confirmación reserva (admin)
     * ========= */
    private static function tpl_booking_confirmation_admin($b, $t = null) {

        $fecha = $b->transfer_date ? date('d/m/Y', strtotime($b->transfer_date)) : '—';
        $hora  = $b->transfer_time ? date('H:i', strtotime($b->transfer_time)) : '—';

        $header = self::email_header('Nueva Reserva Confirmada', '#1e58a6');

        $maps_link  = self::build_maps_link($b);
        $stops_html = self::build_stops_html($b);
        $price_html = self::build_price_breakdown_html($b);

        $body  = self::card('Cliente', "
            <p><strong>Nombre:</strong> " . self::safe($b->customer_name) . "</p>
            <p><strong>Email:</strong> " . self::safe($b->customer_email) . "</p>
            <p><strong>Teléfono:</strong> " . self::safe($b->customer_phone) . "</p>
            " . ($b->customer_rut ? "<p><strong>RUT:</strong> " . self::safe($b->customer_rut) . "</p>" : "") . "
        ");

        $body .= self::card('Viaje', "
            <p><strong>Código:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Fecha:</strong> {$fecha}</p>
            <p><strong>Hora:</strong> {$hora}</p>
            <p><strong>Tipo de traslado:</strong> " . self::safe($b->transfer_name, 'No especificado') . "</p>
            <p><strong>Vehículo:</strong> " . self::safe($b->vehicle_name, 'Por asignar') . "</p>
            <p><strong>Pasajeros:</strong> " . intval($b->passengers) . "</p>
            <p><strong>Origen:</strong> " . self::safe($b->origin_address) . "</p>
            <p><strong>Destino:</strong> " . self::safe($b->destination_address) . "</p>" .
            ($stops_html ? "<p><strong>Paradas intermedias:</strong></p>{$stops_html}" : "") . "
            <p><strong>Distancia estimada:</strong> " . self::format_km($b->distance_km) . "</p>
            <p><strong>Duración estimada:</strong> " . self::safe($b->duration, '—') . "</p>" .
            ($maps_link ? "<p style='margin-top:12px;'>
                <a href=\"{$maps_link}\" target=\"_blank\" rel=\"noopener\" 
                   style=\"display:inline-block;background:#0f172a;color:#fff;text-decoration:none;
                          padding:8px 14px;border-radius:6px;font-size:13px;\">
                    Ver ruta en Google Maps
                </a>
            </p>" : "") . "
        ");

        $body .= self::card('Pagos', $price_html);

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 3) Confirmación pago (cliente)
     * ========= */
    private static function tpl_payment_confirmation_client($b, $t = null) {

        $header = self::email_header('Pago Confirmado', '#0f766e');

        // Normalizar campos Transbank si vienen como objeto
        $auth_code = null;
        $buy_order = null;

        if (is_object($t)) {
            if (property_exists($t, 'authorizationCode')) {
                $auth_code = $t->authorizationCode;
            } elseif (method_exists($t, 'getAuthorizationCode')) {
                $auth_code = $t->getAuthorizationCode();
            }

            if (property_exists($t, 'buyOrder')) {
                $buy_order = $t->buyOrder;
            } elseif (method_exists($t, 'getBuyOrder')) {
                $buy_order = $t->getBuyOrder();
            }
        }

        // Como fallback, usamos tbk_raw
        if (!$auth_code && isset($b->tbk_raw->authorization_code)) {
            $auth_code = $b->tbk_raw->authorization_code;
        }
        if (!$buy_order && isset($b->tbk_raw->buy_order)) {
            $buy_order = $b->tbk_raw->buy_order;
        }

        $price_html = self::build_price_breakdown_html($b);

        $body  = self::card('Pago', "
            <p><strong>Monto:</strong> " . self::format_currency($b->total_price) . "</p>
            <p><strong>Código de autorización:</strong> " . self::safe($auth_code, 'N/D') . "</p>
            <p><strong>Orden de compra:</strong> " . self::safe($buy_order, 'N/D') . "</p>
        ");

        $body .= self::card('Reserva Asociada', "
            <p><strong>Código de reserva:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Estado de pago:</strong> " . self::safe($b->payment_status, 'paid') . "</p>
            {$price_html}
        ");

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 4) Confirmación pago (admin)
     * ========= */
    private static function tpl_payment_confirmation_admin($b, $t = null) {

        $header = self::email_header('Pago Confirmado', '#0f766e');

        $auth_code = null;
        $buy_order = null;

        if (is_object($t)) {
            if (property_exists($t, 'authorizationCode')) {
                $auth_code = $t->authorizationCode;
            } elseif (method_exists($t, 'getAuthorizationCode')) {
                $auth_code = $t->getAuthorizationCode();
            }

            if (property_exists($t, 'buyOrder')) {
                $buy_order = $t->buyOrder;
            } elseif (method_exists($t, 'getBuyOrder')) {
                $buy_order = $t->getBuyOrder();
            }
        }

        if (!$auth_code && isset($b->tbk_raw->authorization_code)) {
            $auth_code = $b->tbk_raw->authorization_code;
        }
        if (!$buy_order && isset($b->tbk_raw->buy_order)) {
            $buy_order = $b->tbk_raw->buy_order;
        }

        $price_html = self::build_price_breakdown_html($b);

        $body  = self::card('Pago Recibido', "
            <p><strong>Reserva:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Monto:</strong> " . self::format_currency($b->total_price) . "</p>
            <p><strong>Código de autorización:</strong> " . self::safe($auth_code, 'N/D') . "</p>
            <p><strong>Orden de compra:</strong> " . self::safe($buy_order, 'N/D') . "</p>
        ");

        $body .= self::card('Detalle del viaje', "
            <p><strong>Cliente:</strong> " . self::safe($b->customer_name) . " (" . self::safe($b->customer_email) . ")</p>
            <p><strong>Fecha:</strong> " . self::safe($b->transfer_date) . "</p>
            <p><strong>Hora:</strong> " . self::safe($b->transfer_time) . "</p>
        ");

        $body .= self::card('Desglose de precio', $price_html);

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 5) Rechazo pago (cliente)
     * ========= */
    private static function tpl_payment_rejected_client($b, $t = null) {

        $header = self::email_header('Pago Rechazado', '#b91c1c');

        $response_code = null;
        if (is_object($t)) {
            if (property_exists($t, 'response_code')) {
                $response_code = $t->response_code;
            } elseif (property_exists($t, 'responseCode')) {
                $response_code = $t->responseCode;
            } elseif (method_exists($t, 'getResponseCode')) {
                $response_code = $t->getResponseCode();
            }
        }
        if (!$response_code && isset($b->tbk_raw->response_code)) {
            $response_code = $b->tbk_raw->response_code;
        }

        $body  = self::card('Error en el pago', "
            <p>El pago no pudo ser procesado.</p>
            <p><strong>Código de error:</strong> " . self::safe($response_code, 'N/D') . "</p>
        ", '#b91c1c');

        $body .= self::card('Reserva', "
            <p><strong>Código de reserva:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Estado de pago:</strong> " . self::safe($b->payment_status) . "</p>
            <p><strong>Monto pendiente:</strong> " . self::format_currency($b->total_price) . "</p>
        ");

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 6) Rechazo pago (admin)
     * ========= */
    private static function tpl_payment_rejected_admin($b, $t = null) {

        $header = self::email_header('Pago Rechazado', '#b91c1c');

        $response_code = null;
        if (is_object($t)) {
            if (property_exists($t, 'response_code')) {
                $response_code = $t->response_code;
            } elseif (property_exists($t, 'responseCode')) {
                $response_code = $t->responseCode;
            } elseif (method_exists($t, 'getResponseCode')) {
                $response_code = $t->getResponseCode();
            }
        }
        if (!$response_code && isset($b->tbk_raw->response_code)) {
            $response_code = $b->tbk_raw->response_code;
        }

        $body  = self::card('Pago Rechazado', "
            <p><strong>Reserva:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Error:</strong> " . self::safe($response_code, 'N/D') . "</p>
            <p><strong>Cliente:</strong> " . self::safe($b->customer_name) . " (" . self::safe($b->customer_email) . ")</p>
            <p><strong>Monto:</strong> " . self::format_currency($b->total_price) . "</p>
        ", '#b91c1c');

        return self::clean_html($header . self::email_wrapper($body));
    }

    /* =========
     * 7) Cancelación reserva (cliente)
     * ========= */
    private static function tpl_booking_cancellation($b, $reason = '') {

        $header = self::email_header('Reserva Cancelada', '#b91c1c');

        $price_html = self::build_price_breakdown_html($b);

        $body  = self::card('Detalle de la cancelación', "
            <p>Tu reserva ha sido cancelada.</p>
            <p><strong>Código de reserva:</strong> " . self::safe($b->booking_code) . "</p>
            <p><strong>Motivo:</strong> " . self::safe($reason, 'No especificado') . "</p>
        ", '#b91c1c');

        $body .= self::card('Resumen de la reserva', "
            <p><strong>Origen:</strong> " . self::safe($b->origin_address) . "</p>
            <p><strong>Destino:</strong> " . self::safe($b->destination_address) . "</p>
            <p><strong>Fecha:</strong> " . self::safe($b->transfer_date) . "</p>
            <p><strong>Hora:</strong> " . self::safe($b->transfer_time) . "</p>
            <p><strong>Pasajeros:</strong> " . intval($b->passengers) . "</p>
            {$price_html}
        ");

        return self::clean_html($header . self::email_wrapper($body));
    }
}
