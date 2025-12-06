<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// ====== Filtros de fecha ======
// Por defecto: histórico completo
$filter_type  = isset($_GET['tt_filter_type']) ? sanitize_text_field($_GET['tt_filter_type']) : 'all';
$current_year = (int) date('Y');
$current_month = (int) date('n');

$filter_month = isset($_GET['tt_month']) ? (int) $_GET['tt_month'] : $current_month;
$filter_year  = isset($_GET['tt_year']) ? (int) $_GET['tt_year'] : $current_year;

$start_date   = isset($_GET['tt_start_date']) ? sanitize_text_field($_GET['tt_start_date']) : '';
$end_date     = isset($_GET['tt_end_date']) ? sanitize_text_field($_GET['tt_end_date']) : '';

// Normalizar fechas de rango
if ($filter_type === 'range') {
    if (!empty($start_date) && empty($end_date)) {
        $end_date = $start_date;
    }
    if (!empty($end_date) && empty($start_date)) {
        $start_date = $end_date;
    }
}

// Calcular rango de fechas efectivo
$range_start = null;
$range_end   = null;

if ($filter_type === 'month') {
    // Primer y último día del mes seleccionado
    $range_start = sprintf('%04d-%02d-01', $filter_year, $filter_month);
    $range_end   = date('Y-m-t', strtotime($range_start));
} elseif ($filter_type === 'range' && !empty($start_date) && !empty($end_date)) {
    $range_start = $start_date;
    $range_end   = $end_date;
} elseif ($filter_type === 'all') {
    // Histórico completo: sin rango
    $range_start = null;
    $range_end   = null;
}

// Helper para construir condición de fecha para reservas (tt_bookings.transfer_date)
$bookings_date_condition = '';
$bookings_date_params = array();

if ($range_start && $range_end) {
    $bookings_date_condition = ' AND transfer_date BETWEEN %s AND %s';
    $bookings_date_params[] = $range_start;
    $bookings_date_params[] = $range_end;
}

// Helper para condición de fecha en transacciones (DATE(COALESCE(transaction_date, created_at)))
$trans_date_condition = '';
$trans_date_params = array();

if ($range_start && $range_end) {
    $trans_date_condition = ' AND DATE(COALESCE(transaction_date, created_at)) BETWEEN %s AND %s';
    $trans_date_params[] = $range_start;
    $trans_date_params[] = $range_end;
}

// ====== KPIs de Reservas (wp_tt_bookings) ======

// Total reservas en el período o histórico
if ($bookings_date_condition) {
    $sql_total_bookings = "SELECT COUNT(*) FROM {$wpdb->prefix}tt_bookings WHERE 1=1 {$bookings_date_condition}";
    $total_bookings = (int) $wpdb->get_var($wpdb->prepare($sql_total_bookings, $bookings_date_params));
} else {
    $total_bookings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tt_bookings");
}

// Reservas por estado (para tabla de distribución)
if ($bookings_date_condition) {
    $sql_status_dist = "SELECT status, COUNT(*) AS total 
                        FROM {$wpdb->prefix}tt_bookings 
                        WHERE 1=1 {$bookings_date_condition}
                        GROUP BY status";
    $bookings_by_status = $wpdb->get_results($wpdb->prepare($sql_status_dist, $bookings_date_params));
} else {
    $sql_status_dist = "SELECT status, COUNT(*) AS total 
                        FROM {$wpdb->prefix}tt_bookings 
                        GROUP BY status";
    $bookings_by_status = $wpdb->get_results($sql_status_dist);
}

// Estados clave usando los nuevos valores principales
$statuses_confirmed = array('confirmada', 'asignada', 'finalizada', 'confirmed', 'completed');
$statuses_finished  = array('finalizada', 'completed');
$statuses_cancelled = array('cancelada', 'cancelled');
$statuses_pending   = array('pending', 'pendiente');

$confirmed_count = 0;
$finished_count  = 0;
$cancelled_count = 0;
$pending_count   = 0;

foreach ((array) $bookings_by_status as $row) {
    $st = strtolower($row->status);
    $count = (int) $row->total;

    if (in_array($st, $statuses_confirmed, true)) {
        $confirmed_count += $count;
    }
    if (in_array($st, $statuses_finished, true)) {
        $finished_count += $count;
    }
    if (in_array($st, $statuses_cancelled, true)) {
        $cancelled_count += $count;
    }
    if (in_array($st, $statuses_pending, true)) {
        $pending_count += $count;
    }
}

// ====== KPIs de Transacciones (wp_tt_tbk_trans) ======

$trans_table = "{$wpdb->prefix}tt_tbk_trans";

// Total transacciones en el período o histórico
if ($trans_date_condition) {
    $sql_total_trans = "SELECT COUNT(*) FROM {$trans_table} WHERE 1=1 {$trans_date_condition}";
    $total_transactions = (int) $wpdb->get_var($wpdb->prepare($sql_total_trans, $trans_date_params));
} else {
    $total_transactions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trans_table}");
}

// Transacciones aprobadas
if ($trans_date_condition) {
    $sql_approved_trans = "SELECT COUNT(*) FROM {$trans_table} WHERE status = 'approved' {$trans_date_condition}";
    $approved_transactions = (int) $wpdb->get_var($wpdb->prepare($sql_approved_trans, $trans_date_params));
} else {
    $approved_transactions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trans_table} WHERE status = 'approved'");
}

// Monto total aprobado SOLO de servicios finalizados (reservas completadas)
$bookings_table = "{$wpdb->prefix}tt_bookings";

// Construimos la parte de fecha específica para este JOIN (usando alias t)
$income_date_condition = '';
$income_date_params    = array();

if ($range_start && $range_end) {
    $income_date_condition = ' AND DATE(COALESCE(t.transaction_date, t.created_at)) BETWEEN %s AND %s';
    $income_date_params    = $trans_date_params; // mismas fechas que ya calculaste
}

$sql_income = "
    SELECT SUM(t.amount)
    FROM {$trans_table} t
    LEFT JOIN {$bookings_table} b ON t.booking_id = b.id
    WHERE t.status = 'approved'
      AND b.status IN ('finalizada', 'completed')
      {$income_date_condition}
";

if (!empty($income_date_params)) {
    $total_income = (float) $wpdb->get_var($wpdb->prepare($sql_income, $income_date_params));
} else {
    $total_income = (float) $wpdb->get_var($sql_income);
}


// Ticket promedio
$avg_ticket = ($approved_transactions > 0) ? ($total_income / $approved_transactions) : 0;

// Tasa de aprobación
$approval_rate = ($total_transactions > 0) ? ($approved_transactions / $total_transactions * 100) : 0;

// Distribución por método de pago (payment_type_code)
if ($trans_date_condition) {
    $sql_payment_type = "SELECT payment_type_code, COUNT(*) AS total, SUM(amount) AS total_amount
                         FROM {$trans_table}
                         WHERE status = 'approved' {$trans_date_condition}
                         GROUP BY payment_type_code";
    $payment_type_stats = $wpdb->get_results($wpdb->prepare($sql_payment_type, $trans_date_params));
} else {
    $sql_payment_type = "SELECT payment_type_code, COUNT(*) AS total, SUM(amount) AS total_amount
                         FROM {$trans_table}
                         WHERE status = 'approved'
                         GROUP BY payment_type_code";
    $payment_type_stats = $wpdb->get_results($sql_payment_type);
}

// Ingresos por día (últimos 15 días dentro del rango o histórico)
$income_by_day = array();
if ($trans_date_condition) {
    $sql_income_day = "SELECT DATE(COALESCE(transaction_date, created_at)) AS day,
                              SUM(amount) AS total_amount,
                              COUNT(*) AS total_trans
                       FROM {$trans_table}
                       WHERE status = 'approved' {$trans_date_condition}
                       GROUP BY DATE(COALESCE(transaction_date, created_at))
                       ORDER BY day DESC
                       LIMIT 15";
    $income_by_day = $wpdb->get_results($wpdb->prepare($sql_income_day, $trans_date_params));
} else {
    $sql_income_day = "SELECT DATE(COALESCE(transaction_date, created_at)) AS day,
                              SUM(amount) AS total_amount,
                              COUNT(*) AS total_trans
                       FROM {$trans_table}
                       WHERE status = 'approved'
                       GROUP BY DATE(COALESCE(transaction_date, created_at))
                       ORDER BY day DESC
                       LIMIT 15";
    $income_by_day = $wpdb->get_results($sql_income_day);
}

// Formatear texto del rango actual para mostrar en el encabezado
$range_label = '';
if ($range_start && $range_end) {
    if ($filter_type === 'month') {
        $range_label = sprintf(
            __('Mes %1$s de %2$s', 'tur-transportes'),
            str_pad($filter_month, 2, '0', STR_PAD_LEFT),
            $filter_year
        );
    } else {
        $range_label = sprintf(
            __('Del %1$s al %2$s', 'tur-transportes'),
            date_i18n('d/m/Y', strtotime($range_start)),
            date_i18n('d/m/Y', strtotime($range_end))
        );
    }
} elseif ($filter_type === 'all') {
    $range_label = __('Histórico completo de registros', 'tur-transportes');
} else {
    $range_label = __('Sin filtro de fecha (todos los registros)', 'tur-transportes');
}

// Años para selector (últimos 5, próximos 1)
$years_options = range($current_year - 4, $current_year + 1);
?>

<div class="wrap tt-admin-dashboard">
    <h1><?php _e('Tur Transportes - Dashboard', 'tur-transportes'); ?></h1>
    <p class="tt-dashboard-subtitle">
        <?php echo esc_html($range_label); ?>
    </p>

    <!-- Filtros de fecha -->
    <form method="get" class="tt-dashboard-filters">
        <input type="hidden" name="page" value="<?php echo isset($_GET['page']) ? esc_attr($_GET['page']) : ''; ?>" />
        
        <div class="tt-filters-row">
            <div class="tt-filter-group">
                <label for="tt_filter_type"><strong><?php _e('Tipo de filtro', 'tur-transportes'); ?></strong></label>
                <select id="tt_filter_type" name="tt_filter_type">
                    <option value="all" <?php selected($filter_type, 'all'); ?>>
                        <?php _e('Histórico completo', 'tur-transportes'); ?>
                    </option>
                    <option value="month" <?php selected($filter_type, 'month'); ?>>
                        <?php _e('Mensual', 'tur-transportes'); ?>
                    </option>
                    <option value="range" <?php selected($filter_type, 'range'); ?>>
                        <?php _e('Rango de fechas', 'tur-transportes'); ?>
                    </option>
                </select>
            </div>

            <div class="tt-filter-group tt-filter-month">
                <label><strong><?php _e('Mes / Año', 'tur-transportes'); ?></strong></label>
                <div class="tt-filter-inline">
                    <select name="tt_month">
                        <?php
                        $months_labels = array(
                            1 => __('Enero', 'tur-transportes'),
                            2 => __('Febrero', 'tur-transportes'),
                            3 => __('Marzo', 'tur-transportes'),
                            4 => __('Abril', 'tur-transportes'),
                            5 => __('Mayo', 'tur-transportes'),
                            6 => __('Junio', 'tur-transportes'),
                            7 => __('Julio', 'tur-transportes'),
                            8 => __('Agosto', 'tur-transportes'),
                            9 => __('Septiembre', 'tur-transportes'),
                            10 => __('Octubre', 'tur-transportes'),
                            11 => __('Noviembre', 'tur-transportes'),
                            12 => __('Diciembre', 'tur-transportes'),
                        );
                        foreach ($months_labels as $m => $label) : ?>
                            <option value="<?php echo esc_attr($m); ?>" <?php selected($filter_month, $m); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="tt_year">
                        <?php foreach ($years_options as $year) : ?>
                            <option value="<?php echo esc_attr($year); ?>" <?php selected($filter_year, $year); ?>>
                                <?php echo esc_html($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="tt-filter-group tt-filter-range">
                <label><strong><?php _e('Rango de fechas', 'tur-transportes'); ?></strong></label>
                <div class="tt-filter-inline">
                    <input type="date" name="tt_start_date" value="<?php echo esc_attr($start_date); ?>" />
                    <span>–</span>
                    <input type="date" name="tt_end_date" value="<?php echo esc_attr($end_date); ?>" />
                </div>
            </div>

            <div class="tt-filter-group tt-filter-actions">
                <button type="submit" class="button button-primary">
                    <?php _e('Aplicar filtros', 'tur-transportes'); ?>
                </button>
                <a href="<?php echo esc_url( remove_query_arg( array('tt_filter_type','tt_month','tt_year','tt_start_date','tt_end_date') ) ); ?>" class="button">
                    <?php _e('Limpiar', 'tur-transportes'); ?>
                </a>
            </div>
        </div>
    </form>

    <!-- KPIs principales -->
    <div class="tt-dashboard-stats">
        <div class="tt-stat-card">
            <h3><?php _e('Reservas totales', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo number_format_i18n($total_bookings); ?></span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Reservas confirmadas / activas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo number_format_i18n($confirmed_count); ?></span>
            <span class="tt-stat-helper">
                <?php
                $pct_conf = ($total_bookings > 0) ? round($confirmed_count / max($total_bookings, 1) * 100) : 0;
                printf(__('Equivale al %s%% de las reservas', 'tur-transportes'), $pct_conf);
                ?>
            </span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Reservas finalizadas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo number_format_i18n($finished_count); ?></span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Reservas canceladas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo number_format_i18n($cancelled_count); ?></span>
        </div>
    </div>

    <!-- KPIs financieros -->
    <div class="tt-dashboard-stats tt-dashboard-stats-secondary">
        <div class="tt-stat-card tt-stat-card-accent">
            <h3><?php _e('Ingresos aprobados (Transbank)', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number">$<?php echo number_format_i18n($total_income, 0); ?></span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Transacciones aprobadas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo number_format_i18n($approved_transactions); ?></span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Tasa de aprobación', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number"><?php echo round($approval_rate, 1); ?>%</span>
        </div>

        <div class="tt-stat-card">
            <h3><?php _e('Ticket promedio', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number">$<?php echo number_format_i18n($avg_ticket, 0); ?></span>
        </div>
    </div>

    <div class="tt-dashboard-grids">
        <!-- Distribución de reservas por estado -->
        <div class="tt-dashboard-panel">
            <h2><?php _e('Distribución de reservas por estado', 'tur-transportes'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Estado', 'tur-transportes'); ?></th>
                        <th><?php _e('Cantidad', 'tur-transportes'); ?></th>
                        <th><?php _e('Porcentaje', 'tur-transportes'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($bookings_by_status)) : ?>
                        <?php foreach ($bookings_by_status as $row) :
                            $st_label = $row->status ? $row->status : __('(Sin estado)', 'tur-transportes');
                            $cnt = (int) $row->total;
                            $pct = ($total_bookings > 0) ? round($cnt / max($total_bookings, 1) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><?php echo esc_html($st_label); ?></td>
                                <td><?php echo number_format_i18n($cnt); ?></td>
                                <td><?php echo esc_html($pct . ' %'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3"><?php _e('No hay reservas en el período seleccionado.', 'tur-transportes'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Distribución por tipo de pago -->
        <div class="tt-dashboard-panel">
            <h2><?php _e('Distribución de transacciones por tipo de pago', 'tur-transportes'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Tipo de pago', 'tur-transportes'); ?></th>
                        <th><?php _e('Transacciones', 'tur-transportes'); ?></th>
                        <th><?php _e('Monto total', 'tur-transportes'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payment_type_stats)) : ?>
                        <?php foreach ($payment_type_stats as $row) :
                            $label = $row->payment_type_code ? $row->payment_type_code : __('Sin información', 'tur-transportes');
                        ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td><?php echo number_format_i18n((int) $row->total); ?></td>
                                <td>$<?php echo number_format_i18n((float) $row->total_amount, 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3"><?php _e('No hay transacciones aprobadas en el período seleccionado.', 'tur-transportes'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ingresos por día -->
    <div class="tt-dashboard-panel tt-panel-full">
        <h2><?php _e('Ingresos diarios (Transbank)', 'tur-transportes'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Fecha', 'tur-transportes'); ?></th>
                    <th><?php _e('Transacciones aprobadas', 'tur-transportes'); ?></th>
                    <th><?php _e('Monto total', 'tur-transportes'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($income_by_day)) : ?>
                    <?php foreach ($income_by_day as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($row->day))); ?></td>
                            <td><?php echo number_format_i18n((int) $row->total_trans); ?></td>
                            <td>$<?php echo number_format_i18n((float) $row->total_amount, 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="3"><?php _e('No hay datos de ingresos para el período seleccionado.', 'tur-transportes'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.tt-admin-dashboard h1 {
    margin-bottom: 0;
}
.tt-dashboard-subtitle {
    margin-top: 4px;
    margin-bottom: 20px;
    color: #555d66;
    font-size: 13px;
}

.tt-dashboard-filters {
    margin-bottom: 20px;
    padding: 15px 20px;
    background: #fff;
    border-radius: 8px;
    border: 1px solid #e2e4e7;
}

.tt-filters-row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    align-items: flex-end;
}

.tt-filter-group {
    min-width: 180px;
}

.tt-filter-inline {
    display: flex;
    gap: 8px;
    align-items: center;
}

.tt-filter-inline input[type="date"],
.tt-filter-inline select {
    min-width: 130px;
}

.tt-filter-actions {
    margin-left: auto;
}

.tt-dashboard-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.tt-dashboard-stats-secondary {
    margin-top: 10px;
}

.tt-stat-card {
    background: #fff;
    border-radius: 10px;
    padding: 18px 20px;
    border: 1px solid #e2e4e7;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
	border-left: 4px solid #fc711d;
}

.tt-stat-card-accent {
    border-left: 4px solid #fc711d;
}

.tt-stat-card h3 {
    margin: 0 0 8px;
    font-size: 14px;
    color: #1d2327;
}

.tt-stat-number {
    display: block;
    font-size: 22px;
    font-weight: 600;
    color: #1d2327;
}

.tt-stat-helper {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: #6c7781;
}

.tt-dashboard-grids {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-top: 20px;
    margin-bottom: 20px;
}

.tt-dashboard-panel {
    background: #fff;
    border-radius: 10px;
    padding: 18px 20px;
    border: 1px solid #e2e4e7;
}

.tt-panel-full {
    margin-top: 10px;
    margin-bottom: 30px;
}

.tt-dashboard-panel h2 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 15px;
    color: #1d2327;
}

/* Responsive */
@media (max-width: 1024px) {
    .tt-dashboard-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .tt-dashboard-grids {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 782px) {
    .tt-filters-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .tt-filter-actions {
        margin-left: 0;
    }
    .tt-dashboard-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var filterTypeSelect = document.getElementById('tt_filter_type');
    var monthGroup = document.querySelector('.tt-filter-month');
    var rangeGroup = document.querySelector('.tt-filter-range');

    function updateFilterVisibility() {
        if (!filterTypeSelect) return;

        var type = filterTypeSelect.value;
        if (type === 'month') {
            if (monthGroup) monthGroup.style.display = 'block';
            if (rangeGroup) rangeGroup.style.display = 'none';
        } else if (type === 'range') {
            if (monthGroup) monthGroup.style.display = 'none';
            if (rangeGroup) rangeGroup.style.display = 'block';
        } else { // 'all'
            if (monthGroup) monthGroup.style.display = 'none';
            if (rangeGroup) rangeGroup.style.display = 'none';
        }
    }

    if (filterTypeSelect) {
        filterTypeSelect.addEventListener('change', updateFilterVisibility);
        updateFilterVisibility();
    }
});
</script>
