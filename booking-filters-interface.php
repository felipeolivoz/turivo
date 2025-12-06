<?php
/**
 * Interfaz de filtros para las reservas
 */
if (!defined('ABSPATH')) {
    exit;
}

/*
 * Variables esperadas desde admin-bookings.php:
 * $search_term, $start_date, $end_date, $status_filter,
 * $total_bookings, $confirmada_count, $asignada_count, $finalizada_count,
 * $cancelada_count, $bookings
 */

/**
 * Normalizamos valores esperados para evitar notices si algún include
 * cambia en el futuro.
 */
$total_bookings    = isset($total_bookings)    ? (int) $total_bookings    : 0;
$confirmada_count  = isset($confirmada_count)  ? (int) $confirmada_count  : 0;
$asignada_count    = isset($asignada_count)    ? (int) $asignada_count    : 0;
$finalizada_count  = isset($finalizada_count)  ? (int) $finalizada_count  : 0;
$cancelada_count   = isset($cancelada_count)   ? (int) $cancelada_count   : 0;
$bookings          = isset($bookings) && is_array($bookings) ? $bookings : array();

$search_term   = isset($search_term)   ? (string) $search_term   : '';
$start_date    = isset($start_date)    ? (string) $start_date    : '';
$end_date      = isset($end_date)      ? (string) $end_date      : '';
$status_filter = isset($status_filter) ? (string) $status_filter : '';

/**
 * Parámetro page desde la URL, sanitizado.
 * Lo necesitamos para mantener la pestaña actual del menú admin.
 */
$page_param = '';
if (isset($_GET['page'])) {
    $page_param = sanitize_text_field(wp_unslash($_GET['page']));
}

// Generar nonce para exportación
$export_nonce = wp_create_nonce('tt_export_csv');

// Construir la URL de exportación usando admin-post.php
$export_args = array(
    'action'   => 'tt_export_bookings_csv',
    '_wpnonce' => $export_nonce,
);

// Pasar filtros actuales a la URL de exportación
if ($search_term !== '') {
    $export_args['search'] = $search_term;
}
if ($start_date !== '') {
    $export_args['start_date'] = $start_date;
}
if ($end_date !== '') {
    $export_args['end_date'] = $end_date;
}
if ($status_filter !== '' && $status_filter !== 'all') {
    $export_args['status'] = $status_filter;
}

$export_url = add_query_arg($export_args, admin_url('admin-post.php'));

// Cálculo de resumen de resultados
$filtered_count = count($bookings);
if ($filtered_count === $total_bookings) {
    $summary_label = sprintf(
        __('Mostrando todas las %d reservas', 'tur-transportes'),
        $total_bookings
    );
} else {
    $summary_label = sprintf(
        __('Mostrando %d de %d reservas', 'tur-transportes'),
        $filtered_count,
        $total_bookings
    );
}

// URL para limpiar filtros (remover parámetros específicos)
$clear_url = remove_query_arg(array('search', 'start_date', 'end_date', 'status'));
?>
<div class="tt-bookings-filters">
    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_param); ?>">
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <!-- Búsqueda -->
            <div>
                <label for="search" style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Buscar', 'tur-transportes'); ?>
                </label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo esc_attr($search_term); ?>"
                    placeholder="<?php _e('Código, nombre, email, teléfono o RUT...', 'tur-transportes'); ?>"
                    style="width: 100%;"
                >
            </div>

            <!-- Fecha desde -->
            <div>
                <label for="start_date" style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Fecha desde', 'tur-transportes'); ?>
                </label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?php echo esc_attr($start_date); ?>"
                    style="width: 100%;"
                >
            </div>

            <!-- Fecha hasta -->
            <div>
                <label for="end_date" style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Fecha hasta', 'tur-transportes'); ?>
                </label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?php echo esc_attr($end_date); ?>"
                    style="width: 100%;"
                >
            </div>

            <!-- Estado -->
            <div>
                <label for="status" style="display: block; margin-bottom: 5px; font-weight: bold;">
                    <?php _e('Estado', 'tur-transportes'); ?>
                </label>
                <select id="status" name="status" style="width: 100%;">
                    <option value="all"><?php _e('Todos los estados', 'tur-transportes'); ?></option>
                    <option value="confirmada" <?php selected($status_filter, 'confirmada'); ?>>
                        <?php _e('Confirmada', 'tur-transportes'); ?> (<?php echo esc_html($confirmada_count); ?>)
                    </option>
                    <option value="asignada" <?php selected($status_filter, 'asignada'); ?>>
                        <?php _e('Asignada', 'tur-transportes'); ?> (<?php echo esc_html($asignada_count); ?>)
                    </option>
                    <option value="finalizada" <?php selected($status_filter, 'finalizada'); ?>>
                        <?php _e('Finalizada', 'tur-transportes'); ?> (<?php echo esc_html($finalizada_count); ?>)
                    </option>
                    <option value="cancelada" <?php selected($status_filter, 'cancelada'); ?>>
                        <?php _e('Cancelada', 'tur-transportes'); ?> (<?php echo esc_html($cancelada_count); ?>)
                    </option>
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="submit" class="button button-primary">
                <?php _e('Aplicar Filtros', 'tur-transportes'); ?>
            </button>

            <?php if ($search_term || $start_date || $end_date || ($status_filter && $status_filter !== 'all')) : ?>
                <a href="<?php echo esc_url($clear_url); ?>" class="button">
                    <?php _e('Limpiar Filtros', 'tur-transportes'); ?>
                </a>
            <?php endif; ?>

            <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 5px;"></span>
                <?php _e('Exportar a CSV', 'tur-transportes'); ?>
            </a>

            <span style="margin-left: auto; color: #666; font-style: italic;">
                <?php echo esc_html($summary_label); ?>
            </span>
        </div>
    </form>
</div>
