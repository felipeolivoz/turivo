<?php
// export-csv.php → VERSIÓN FINAL SIN DEBUG
require_once dirname(__FILE__) . '/../../../wp-load.php';

// Seguridad
if (!current_user_can('manage_options')) {
    wp_die('Acceso denegado');
}
check_admin_referer('tt_export_bookings_csv');

global $wpdb;

// Variables seguras
$search_term   = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$start_date    = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_date      = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Query base
$query = "
    SELECT
        b.booking_code,
        b.transfer_date,
        b.transfer_time,
        vt.name AS vehicle_name,
        tt.name AS transfer_name,
        b.passengers,
        b.customer_name,
        b.customer_email,
        b.customer_phone,
        b.origin_address,
        b.destination_address,
        b.flight_number,
        b.notes,
        b.total_price,
        b.status,
        b.created_at
    FROM {$wpdb->prefix}tt_bookings b
    LEFT JOIN {$wpdb->prefix}tt_vehicle_types vt ON b.vehicle_type_id = vt.id
    LEFT JOIN {$wpdb->prefix}tt_transfer_types tt ON b.transfer_type_id = tt.id
    WHERE 1=1
";

$params = [];

// Filtros
if (!empty($search_term)) {
    $like = '%' . $wpdb->esc_like($search_term) . '%';
    $query .= " AND (b.booking_code LIKE %s OR b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR b.origin_address LIKE %s OR b.destination_address LIKE %s)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if (!empty($start_date)) {
    $query .= " AND b.transfer_date >= %s";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $query .= " AND b.transfer_date <= %s";
    $params[] = $end_date;
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $query .= " AND b.status = %s";
    $params[] = $status_filter;
}

$query .= " ORDER BY b.created_at DESC";

// Ejecutar consulta
$results = $params ? $wpdb->get_results($wpdb->prepare($query, $params)) : $wpdb->get_results($query);

// Generar CSV
$filename = 'reservas-tur-transportes-' . date('Y-m-d-H-i-s') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM UTF-8

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Código Reserva','Fecha Traslado','Hora','Tipo Vehículo','Tipo Traslado','Pasajeros',
    'Nombre Cliente','Email','Teléfono','Origen','Destino','Nº Vuelo','Notas',
    'Precio Total','Estado','Fecha Creación'
], ';');

$estados = [
    'pending'   => 'Pendiente',
    'confirmed' => 'Confirmada',
    'completed' => 'Completada',
    'cancelled' => 'Cancelada'
];

foreach ($results as $row) {
    fputcsv($output, [
        $row->booking_code,
        $row->transfer_date,
        $row->transfer_time,
        $row->vehicle_name ?? '',
        $row->transfer_name ?? '',
        $row->passengers,
        $row->customer_name,
        $row->customer_email,
        $row->customer_phone,
        $row->origin_address,
        $row->destination_address,
        $row->flight_number,
        $row->notes,
        $row->total_price,
        $estados[$row->status] ?? $row->status,
        $row->created_at
    ], ';');
}

fclose($output);
exit;
