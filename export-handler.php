<?php
if (!defined('ABSPATH')) {
    exit;
}

// Registrar hooks para exportar reservas y transacciones Transbank
add_action('admin_post_tt_export_bookings_csv', 'tt_handle_bookings_csv_export');
add_action('admin_post_nopriv_tt_export_bookings_csv', 'tt_handle_bookings_csv_export');

add_action('admin_post_tt_export_transbank_csv', 'tt_handle_transbank_csv_export');
add_action('admin_post_nopriv_tt_export_transbank_csv', 'tt_handle_transbank_csv_export');

/**
 * Exportar reservas a CSV
 */
function tt_handle_bookings_csv_export() {

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tt_export_csv')) {
        wp_die('Error de seguridad');
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acci車n');
    }

    global $wpdb;

    $search_term   = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $start_date    = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date      = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

    $normalized_rut = preg_replace('/[^0-9kK]/', '', $search_term);

    $query = "
        SELECT b.*, vt.name AS vehicle_name, tt.name AS transfer_name
        FROM {$wpdb->prefix}tt_bookings b
        LEFT JOIN {$wpdb->prefix}tt_vehicle_types vt ON b.vehicle_type_id = vt.id
        LEFT JOIN {$wpdb->prefix}tt_transfer_types tt ON b.transfer_type_id = tt.id
        WHERE 1=1
    ";

    $query_params = array();

    if (!empty($search_term)) {
        $query .= " AND (
            b.booking_code LIKE %s OR
            b.customer_name LIKE %s OR
            b.customer_email LIKE %s OR
            b.customer_phone LIKE %s OR
            b.origin_address LIKE %s OR
            b.destination_address LIKE %s";

        if (!empty($normalized_rut)) {
            $query .= " OR REPLACE(REPLACE(REPLACE(LOWER(b.customer_rut), '.', ''), '-', ''), ' ', '') LIKE %s";
        }

        $query .= " )";

        $search_like = '%' . $wpdb->esc_like($search_term) . '%';

        $params = array_fill(0, 6, $search_like);

        if (!empty($normalized_rut)) {
            $rut_like = '%' . $wpdb->esc_like(strtolower($normalized_rut)) . '%';
            $params[] = $rut_like;
        }

        $query_params = array_merge($query_params, $params);
    }

    if (!empty($start_date)) {
        $query .= " AND b.transfer_date >= %s";
        $query_params[] = $start_date;
    }

    if (!empty($end_date)) {
        $query .= " AND b.transfer_date <= %s";
        $query_params[] = $end_date;
    }

    if (!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND b.status = %s";
        $query_params[] = $status_filter;
    }

    $query .= " ORDER BY b.created_at DESC";

    if (!empty($query_params)) {
        $export_bookings = $wpdb->get_results($wpdb->prepare($query, $query_params));
    } else {
        $export_bookings = $wpdb->get_results($query);
    }

    $filename = 'reservas-' . date('Y-m-d-His') . '.csv';
    $filename = sanitize_file_name($filename);

    if (ob_get_length()) {
        ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fwrite($output, "\xEF\xBB\xBF");

    $headers = array(
        'C車digo',
        'RUT',
        'Cliente',
        'Email',
        'Tel谷fono',
        'Origen',
        'Destino',
        'Tipo de Traslado',
        'Veh赤culo',
        'Pasajeros',
        'Total',
        'Estado',
        'Fecha Creaci車n',
    );

    fputcsv($output, $headers, ';');

    $status_labels = array(
        'confirmed' => 'Confirmada',
        'assigned'  => 'Asignada',
'completed' => 'Finalizada',
'cancelled' => 'Cancelada',
    );

    if (!empty($export_bookings)) {
        foreach ($export_bookings as $booking) {
            $row = array(
                $booking->booking_code,
                $booking->customer_rut ?: 'N/A',
                $booking->customer_name,
                $booking->customer_email,
                $booking->customer_phone,
                $booking->origin_address,
                $booking->destination_address,
                $booking->transfer_name ?: 'N/A',
                $booking->vehicle_name,
                $booking->passengers,
                number_format((float) $booking->total_price, 0, ',', '.'),
                $status_labels[$booking->status] ?? $booking->status,
                date('d/m/Y H:i', strtotime($booking->created_at)),
            );

            fputcsv($output, $row, ';');
        }
    }

    fclose($output);
    exit;
}

/**
 * Exportar transacciones Transbank a CSV
 */
function tt_handle_transbank_csv_export() {

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tt_export_csv')) {
        wp_die('Error de seguridad');
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acci車n');
    }

    global $wpdb;

    $search_term   = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $start_date    = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date      = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

    $normalized_rut = preg_replace('/[^0-9kK]/', '', $search_term);

    $query = "
        SELECT t.*, b.booking_code, b.customer_name, b.customer_email, b.customer_rut
        FROM {$wpdb->prefix}tt_tbk_trans t
        LEFT JOIN {$wpdb->prefix}tt_bookings b ON t.booking_id = b.id
        WHERE 1=1
    ";

    $query_params = array();

    if (!empty($search_term)) {
        $query .= " AND (
            t.buy_order LIKE %s OR
            t.token LIKE %s OR
            t.session_id LIKE %s OR
            b.booking_code LIKE %s OR
            b.customer_name LIKE %s OR
            b.customer_email LIKE %s";

        if (!empty($normalized_rut)) {
            $query .= " OR REPLACE(REPLACE(REPLACE(LOWER(b.customer_rut), '.', ''), '-', ''), ' ', '') LIKE %s";
        }

        $query .= " )";

        $search_like = '%' . $wpdb->esc_like($search_term) . '%';

        $params = array_fill(0, 6, $search_like);

        if (!empty($normalized_rut)) {
            $rut_like = '%' . $wpdb->esc_like(strtolower($normalized_rut)) . '%';
            $params[] = $rut_like;
        }

        $query_params = array_merge($query_params, $params);
    }

    if (!empty($start_date)) {
        $query .= " AND DATE(t.created_at) >= %s";
        $query_params[] = $start_date;
    }

    if (!empty($end_date)) {
        $query .= " AND DATE(t.created_at) <= %s";
        $query_params[] = $end_date;
    }

    if (!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND t.status = %s";
        $query_params[] = $status_filter;
    }

    $query .= " ORDER BY t.created_at DESC";

    if (!empty($query_params)) {
        $transactions = $wpdb->get_results($wpdb->prepare($query, $query_params));
    } else {
        $transactions = $wpdb->get_results($query);
    }

    $filename = 'transbank-transacciones-' . date('Y-m-d-His') . '.csv';
    $filename = sanitize_file_name($filename);

    if (ob_get_length()) {
        ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fwrite($output, "\xEF\xBB\xBF");

    $headers = array(
        'ID',
        'Orden de compra',
        'Token',
        'Session ID',
        'Monto',
        'Estado',
        'C車digo autorizaci車n',
        'Ambiente',
        'Fecha transacci車n',
        'RUT Cliente',
        'Cliente',
        'Email',
        'C車digo reserva',
    );

    fputcsv($output, $headers, ';');

    $status_labels = array(
        'initialized' => __('Inicializada', 'tur-transportes'),
        'approved'    => __('Aprobada', 'tur-transportes'),
        'rejected'    => __('Rechazada', 'tur-transportes'),
    );

    $environment_labels = array(
        'integration' => __('Integraci車n', 'tur-transportes'),
        'production'  => __('Producci車n', 'tur-transportes'),
    );

    if (!empty($transactions)) {
        foreach ($transactions as $t) {
            $row = array(
                $t->id,
                $t->buy_order,
                $t->token,
                $t->session_id,
                number_format((float) $t->amount, 0, ',', '.'),
                $status_labels[$t->status] ?? $t->status,
                $t->authorization_code,
                $environment_labels[$t->environment] ?? $t->environment,
                date('d/m/Y H:i', strtotime($t->created_at)),
                $t->customer_rut ?: 'N/A',
                $t->customer_name,
                $t->customer_email,
                $t->booking_code,
            );

            fputcsv($output, $row, ';');
        }
    }

    fclose($output);
    exit;
}
