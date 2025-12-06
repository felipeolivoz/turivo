<?php
if (!defined('ABSPATH')) {
    exit;
}

class TT_Google_Maps {

    const MAX_STOPS = 5;

    public static function init() {
        add_action('wp_ajax_tt_calculate_route', array(__CLASS__, 'calculate_route'));
        add_action('wp_ajax_nopriv_tt_calculate_route', array(__CLASS__, 'calculate_route'));
    }

    public static function calculate_route() {
        try {
            if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tt_booking_nonce')) {
                wp_send_json_error(array('message' => 'Error de seguridad (nonce inválido).'));
            }

            $origin      = isset($_POST['origin'])      ? sanitize_text_field(wp_unslash($_POST['origin']))      : '';
            $destination = isset($_POST['destination']) ? sanitize_text_field(wp_unslash($_POST['destination'])) : '';
            $stops_raw   = isset($_POST['stops'])       ? wp_unslash($_POST['stops'])                             : array();

            if (empty($origin) || empty($destination)) {
                wp_send_json_error(array('message' => 'Origen y destino son obligatorios.'));
            }

            $stops = array();

            if (is_array($stops_raw)) {
                $stops = $stops_raw;
            } elseif (is_string($stops_raw) && $stops_raw !== '') {
                $decoded = json_decode($stops_raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $stops = $decoded;
                }
            }

            $clean_stops = array();
            foreach ($stops as $s) {
                $s = trim(sanitize_text_field($s));
                if ($s !== '') {
                    $clean_stops[] = $s;
                }
                if (count($clean_stops) >= self::MAX_STOPS) {
                    break;
                }
            }
            $stops = $clean_stops;

            $api_key = get_option('tt_google_maps_api_key');
            if (empty($api_key)) {
                wp_send_json_error(array('message' => 'API key de Google Maps no configurada.'));
            }

            $waypoints_param = '';
            if (!empty($stops)) {
                $encoded_stops = array();
                foreach ($stops as $s) {
                    $encoded_stops[] = $s;
                }
                $waypoints_param = 'optimize:true|' . implode('|', $encoded_stops);
            }

            $args = array(
                'origin'      => $origin,
                'destination' => $destination,
                'key'         => $api_key,
                'language'    => 'es',
            );

            if ($waypoints_param !== '') {
                $args['waypoints'] = $waypoints_param;
            }

            $url = add_query_arg($args, 'https://maps.googleapis.com/maps/api/directions/json');

            $response = wp_safe_remote_get($url, array('timeout' => 15));

            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => 'Error al comunicarse con Google Maps.'));
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                wp_send_json_error(array('message' => 'Error al obtener la ruta desde Google Maps.'));
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(array('message' => 'Respuesta inválida de Google Maps.'));
            }

            if (!isset($data->status) || $data->status !== 'OK' || empty($data->routes[0])) {
                wp_send_json_error(array('message' => 'No se pudo calcular la ruta. Verifique las direcciones.'));
            }

            $route = $data->routes[0];

            $total_distance_m = 0;
            $total_duration_s = 0;
            $legs_info        = array();

            if (!empty($route->legs)) {
                foreach ($route->legs as $idx => $leg) {
                    $d = isset($leg->distance->value) ? (int) $leg->distance->value : 0;
                    $t = isset($leg->duration->value) ? (int) $leg->duration->value : 0;

                    $total_distance_m += $d;
                    $total_duration_s += $t;

                    $legs_info[] = array(
                        'index'          => $idx,
                        'distance_m'     => $d,
                        'distance_text'  => isset($leg->distance->text) ? $leg->distance->text : '',
                        'duration_s'     => $t,
                        'duration_text'  => isset($leg->duration->text) ? $leg->duration->text : '',
                        'start_address'  => isset($leg->start_address) ? $leg->start_address : '',
                        'end_address'    => isset($leg->end_address)   ? $leg->end_address   : '',
                    );
                }
            }

            $distance_km   = $total_distance_m / 1000;
            $duration_text = self::format_duration($total_duration_s);

            $polyline      = isset($route->overview_polyline->points) ? $route->overview_polyline->points : '';
            $waypoint_order = isset($route->waypoint_order) ? $route->waypoint_order : array();

            wp_send_json_success(array(
                'distance_km'     => $distance_km,
                'duration_text'   => $duration_text,
                'polyline'        => $polyline,
                'legs'            => $legs_info,
                'stops_original'  => $stops,
                'waypoint_order'  => $waypoint_order,
            ));

        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error al calcular la ruta: ' . $e->getMessage()));
        }
    }

    private static function format_duration($seconds) {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            return '0 min';
        }

        $hours = floor($seconds / 3600);
        $mins  = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%d h %02d min', $hours, $mins);
        }

        return sprintf('%d min', $mins);
    }
}
