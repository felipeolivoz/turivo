<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$status_updated = false;

// Procesar cambio de estado
if (isset($_POST['update_booking_status'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'tt_update_booking')) {
        wp_die('Error de seguridad');
    }

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $new_status = isset($_POST['booking_status']) ? sanitize_text_field($_POST['booking_status']) : '';

    if ($booking_id > 0 && !empty($new_status)) {
        $updated = $wpdb->update(
            $wpdb->prefix . 'tt_bookings',
            array('status' => $new_status),
            array('id' => $booking_id),
            array('%s'),
            array('%d')
        );

        if ($updated !== false) {
            $status_updated = true;
        }
    }
}

// Filtros
$search_term   = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$start_date    = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_date      = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

$normalized_rut = preg_replace('/[^0-9kK]/', '', $search_term);

// PAGINACIÓN
$items_per_page = 10;
$page           = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset         = ($page - 1) * $items_per_page;

// =====================
//     QUERY BASE
// =====================
$query = "
    SELECT b.*, vt.name AS vehicle_name, tt.name AS transfer_name,
           t.amount AS tbk_amount,
           t.installments_number,
           t.authorization_code,
           t.payment_type_code
    FROM {$wpdb->prefix}tt_bookings b
    LEFT JOIN {$wpdb->prefix}tt_vehicle_types vt ON b.vehicle_type_id = vt.id
    LEFT JOIN {$wpdb->prefix}tt_transfer_types tt ON b.transfer_type_id = tt.id
    LEFT JOIN {$wpdb->prefix}tt_tbk_trans t ON t.booking_id = b.id
    WHERE 1=1
";

$params = [];

// =====================
//  FILTRO BUSQUEDA
// =====================
if (!empty($search_term)) {
    $query .= " AND (
        b.booking_code LIKE %s OR
        b.customer_name LIKE %s OR
        b.customer_email LIKE %s OR
        b.customer_phone LIKE %s
    ";

    if (!empty($normalized_rut)) {
        $query .= " OR REPLACE(REPLACE(REPLACE(LOWER(b.customer_rut), '.', ''), '-', ''), ' ', '') LIKE %s";
    }

    $query .= " )";

    $search_like = '%' . $wpdb->esc_like($search_term) . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;

    if (!empty($normalized_rut)) {
        $params[] = '%' . strtolower($normalized_rut) . '%';
    }
}

// =====================
//  FILTRO FECHAS
// =====================
if (!empty($start_date)) {
    $query .= " AND b.transfer_date >= %s";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $query .= " AND b.transfer_date <= %s";
    $params[] = $end_date;
}

// =====================
//  FILTRO ESTADO
// =====================
if (!empty($status_filter) && $status_filter !== 'all') {
    $query .= " AND b.status = %s";
    $params[] = $status_filter;
}

// ORDER + LIMIT
$query .= " ORDER BY b.created_at DESC";
$query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $items_per_page, $offset);

// EJECUTAR
$bookings = !empty($params)
    ? $wpdb->get_results($wpdb->prepare($query, $params))
    : $wpdb->get_results($query);

// =====================
//   TOTAL FILTRADO
// =====================
$count_query  = "SELECT COUNT(*) FROM {$wpdb->prefix}tt_bookings b WHERE 1=1";
$count_params = [];

if (!empty($search_term)) {
    $count_query .= " AND (
        b.booking_code LIKE %s OR
        b.customer_name LIKE %s OR
        b.customer_email LIKE %s OR
        b.customer_phone LIKE %s
    ";
    if (!empty($normalized_rut)) {
        $count_query .= " OR REPLACE(REPLACE(REPLACE(LOWER(b.customer_rut), '.', ''), '-', ''), ' ', '') LIKE %s";
    }
    $count_query .= " )";

    $count_params[] = $search_like;
    $count_params[] = $search_like;
    $count_params[] = $search_like;
    $count_params[] = $search_like;

    if (!empty($normalized_rut)) {
        $count_params[] = '%' . strtolower($normalized_rut) . '%';
    }
}

if (!empty($start_date)) {
    $count_query .= " AND b.transfer_date >= %s";
    $count_params[] = $start_date;
}
if (!empty($end_date)) {
    $count_query .= " AND b.transfer_date <= %s";
    $count_params[] = $end_date;
}
if (!empty($status_filter) && $status_filter !== 'all') {
    $count_query .= " AND b.status = %s";
    $count_params[] = $status_filter;
}

$total_filtered = !empty($count_params)
    ? (int) $wpdb->get_var($wpdb->prepare($count_query, $count_params))
    : (int) $wpdb->get_var($count_query);

$total_pages = max(1, ceil($total_filtered / $items_per_page));

// ===============================
//     FUNCIÓN PAGINACIÓN WP
// ===============================
function tt_render_pagination($page, $total_pages)
{
    if ($total_pages <= 1) return;

    echo '<div class="tablenav top" style="margin:0; padding:0;">';
    echo '<div class="tablenav-pages" style="float:left;text-align:left;margin:12px 0;">';

    // PRIMERA
    if ($page > 1) {
        echo '<a class="first-page" href="' . esc_url(add_query_arg('paged', 1)) . '">« Primera</a> | ';
    }

    // PREV
    if ($page > 1) {
        echo '<a class="prev-page" href="' . esc_url(add_query_arg('paged', $page - 1)) . '">‹ Anterior</a> | ';
    }

    // PÁGINA N / TOTAL
    echo " Página $page de $total_pages ";

    // NEXT
    if ($page < $total_pages) {
        echo ' | <a class="next-page" href="' . esc_url(add_query_arg('paged', $page + 1)) . '">Siguiente ›</a>';
    }

    // ÚLTIMA
    if ($page < $total_pages) {
        echo ' | <a class="last-page" href="' . esc_url(add_query_arg('paged', $total_pages)) . '">Última »</a>';
    }

    echo '</div>';
    echo '</div>';
}

?>

<div class="wrap">
    <h1><?php _e('Reservas - Tur Transportes', 'tur-transportes'); ?></h1>

    <?php if ($status_updated): ?>
        <div class="notice notice-success is-dismissible"><p>Estado actualizado correctamente.</p></div>
    <?php endif; ?>

    <?php include plugin_dir_path(__FILE__) . '../includes/booking-filters-interface.php'; ?>

    <!-- PAGINACIÓN ARRIBA -->
    <?php tt_render_pagination($page, $total_pages); ?>

    <?php include plugin_dir_path(__FILE__) . '../includes/booking-table.php'; ?>

    <!-- PAGINACIÓN ABAJO -->
    <?php tt_render_pagination($page, $total_pages); ?>

</div>

<?php include plugin_dir_path(__FILE__) . '../includes/booking-modal.php';