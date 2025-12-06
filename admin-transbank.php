<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Asegurarnos de tener WP_List_Table disponible
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabla de Transacciones Transbank (WP_List_Table)
 */
class TT_Transbank_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => 'tt_transbank_tx',
            'plural'   => 'tt_transbank_txs',
            'ajax'     => false,
        ));
    }

    public function get_columns() {
        return array(
            'buy_order'          => __('Orden de Compra', 'tur-transportes'),
            'token'              => __('Token', 'tur-transportes'),
            'amount'             => __('Monto', 'tur-transportes'),
            'status'             => __('Estado', 'tur-transportes'),
            'authorization_code' => __('Código Autorización', 'tur-transportes'),
            'customer'           => __('Cliente', 'tur-transportes'),
            'booking_code'       => __('Reserva', 'tur-transportes'),
            'environment'        => __('Ambiente', 'tur-transportes'),
            'created_at'         => __('Fecha', 'tur-transportes'),
            'actions'            => __('Acciones', 'tur-transportes'),
        );
    }

    public function get_sortable_columns() {
        return array(
            'buy_order'  => array('buy_order', false),
            'amount'     => array('amount', false),
            'status'     => array('status', false),
            'created_at' => array('created_at', true),
        );
    }

    protected function get_default_primary_column_name() {
        return 'buy_order';
    }

    /**
     * Columnas personalizadas
     */
    protected function column_buy_order($item) {
        return '<strong>' . esc_html($item['buy_order']) . '</strong>';
    }

    protected function column_token($item) {
        $short = substr($item['token'], 0, 20) . '...';
        return '<code style="font-size:11px;">' . esc_html($short) . '</code>';
    }

    protected function column_amount($item) {
        return '<strong>$' . number_format((float)$item['amount'], 0, ',', '.') . '</strong>';
    }

    protected function column_status($item) {
        $labels = array(
            'initialized' => __('Inicializada', 'tur-transportes'),
            'approved'    => __('Aprobada', 'tur-transportes'),
            'rejected'    => __('Rechazada', 'tur-transportes'),
        );
        $status = $item['status'];
        $label  = isset($labels[$status]) ? $labels[$status] : $status;

        return '<span class="tt-status-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
    }

    protected function column_authorization_code($item) {
        return !empty($item['authorization_code'])
            ? '<code>' . esc_html($item['authorization_code']) . '</code>'
            : '<span style="color:#999;">—</span>';
    }

    protected function column_customer($item) {
        if (!empty($item['customer_name'])) {
            return '<strong>' . esc_html($item['customer_name']) . '</strong><br>'
                 . '<small>' . esc_html($item['customer_email']) . '</small>';
        }
        return '<span style="color:#999;">—</span>';
    }

    protected function column_booking_code($item) {
        return !empty($item['booking_code'])
            ? '<code>' . esc_html($item['booking_code']) . '</code>'
            : '<span style="color:#999;">—</span>';
    }

    protected function column_environment($item) {
        $labels = array(
            'integration' => __('Integración', 'tur-transportes'),
            'production'  => __('Producción', 'tur-transportes'),
        );
        $env   = $item['environment'];
        $label = isset($labels[$env]) ? $labels[$env] : $env;

        return '<span class="tt-environment-' . esc_attr($env) . '">' . esc_html($label) . '</span>';
    }

    protected function column_created_at($item) {
        if (empty($item['created_at'])) {
            return '—';
        }
        return esc_html(date('d/m/Y H:i', strtotime($item['created_at'])));
    }

    protected function column_actions($item) {
        // Pasamos todo el item (array) al botón como JSON
        $json      = wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json_attr = esc_attr($json);

        return '<button type="button" class="button button-small tt-view-details" data-transaction="' . $json_attr . '">'
             . esc_html__('Ver Detalles', 'tur-transportes')
             . '</button>';
    }

    /**
     * Para columnas no definidas explícitamente, por si acaso.
     */
    protected function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }

    /**
     * Preparar items con paginación + filtros
     */
    public function prepare_items() {
        global $wpdb;

        // Configuración de columnas
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        // Paginación: 10 por página (puedes cambiar a 50 si quieres)
        $per_page     = 10;
        $current_page = $this->get_pagenum();

        // Filtros desde GET, sanitizados
        $search_term   = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $start_date    = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : '';
        $end_date      = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';

        // Normalizar posible RUT
        $normalized_rut = preg_replace('/[^0-9kK]/', '', $search_term);

        // FROM base
        $base_from = "
            FROM {$wpdb->prefix}tt_tbk_trans t
            LEFT JOIN {$wpdb->prefix}tt_bookings b ON t.booking_id = b.id
            WHERE 1=1
        ";

        $where  = '';
        $params = array();

        // Filtro búsqueda (mismo criterio que tu archivo original)
        if (!empty($search_term)) {
            $where .= " AND (
                t.buy_order LIKE %s OR
                t.token LIKE %s OR
                t.session_id LIKE %s OR
                b.booking_code LIKE %s OR
                b.customer_name LIKE %s OR
                b.customer_email LIKE %s
            ";

            $search_like = '%' . $wpdb->esc_like($search_term) . '%';

            $params[] = $search_like; // buy_order
            $params[] = $search_like; // token
            $params[] = $search_like; // session_id
            $params[] = $search_like; // booking_code
            $params[] = $search_like; // customer_name
            $params[] = $search_like; // customer_email

            // RUT
            if (!empty($normalized_rut)) {
                $where .= " OR REPLACE(REPLACE(REPLACE(LOWER(b.customer_rut), '.', ''), '-', ''), ' ', '') LIKE %s";
                $params[] = '%' . strtolower($normalized_rut) . '%';
            }

            $where .= ")"; // cierra AND (
        }

        // Fecha desde
        if (!empty($start_date)) {
            $where .= " AND DATE(t.created_at) >= %s";
            $params[] = $start_date;
        }

        // Fecha hasta
        if (!empty($end_date)) {
            $where .= " AND DATE(t.created_at) <= %s";
            $params[] = $end_date;
        }

        // Estado
        if (!empty($status_filter) && $status_filter !== 'all') {
            $where .= " AND t.status = %s";
            $params[] = $status_filter;
        }

        // Orden / sort
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'created_at';
        $order   = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

        switch ($orderby) {
            case 'buy_order':
                $orderby_sql = 't.buy_order';
                break;
            case 'amount':
                $orderby_sql = 't.amount';
                break;
            case 'status':
                $orderby_sql = 't.status';
                break;
            default:
                $orderby_sql = 't.created_at';
                break;
        }

        // Total filtrado (sin LIMIT/OFFSET)
        if (!empty($params)) {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) {$base_from} {$where}", $params)
            );
        } else {
            $total_items = (int) $wpdb->get_var("SELECT COUNT(*) {$base_from} {$where}");
        }

        // Offset
        $offset = ($current_page - 1) * $per_page;

        // SELECT final para obtener filas
        $select = "SELECT 
            t.*, 
            b.booking_code, 
            b.customer_name, 
            b.customer_email, 
            b.customer_rut
        ";

        $sql = "{$select} {$base_from} {$where} 
                ORDER BY {$orderby_sql} {$order} 
                LIMIT %d OFFSET %d";

        $params_for_query   = $params;
        $params_for_query[] = $per_page;
        $params_for_query[] = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $params_for_query),
            ARRAY_A
        );

        $this->items = $rows ? $rows : array();

        // Paginación
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ($per_page > 0) ? ceil($total_items / $per_page) : 1,
        ));
    }

    public function no_items() {
        _e('No hay transacciones que coincidan con los filtros aplicados', 'tur-transportes');
    }
}

/**
 * ===========================
 *  LÓGICA DE LA PANTALLA ADMIN
 * ===========================
 */

// Filtros actuales para mantener en formulario
$search_term   = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
$start_date    = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : '';
$end_date      = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';

// URL exportación CSV (misma lógica que tu original)
$export_nonce = wp_create_nonce('tt_export_csv');
$export_args  = array(
    'action'   => 'tt_export_transbank_csv',
    '_wpnonce' => $export_nonce,
);

if (!empty($search_term)) {
    $export_args['search'] = $search_term;
}
if (!empty($start_date)) {
    $export_args['start_date'] = $start_date;
}
if (!empty($end_date)) {
    $export_args['end_date'] = $end_date;
}
if (!empty($status_filter) && $status_filter !== 'all') {
    $export_args['status'] = $status_filter;
}

$export_url = add_query_arg($export_args, admin_url('admin-post.php'));

// Estadísticas (igual que tu archivo original)
$total_transactions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tt_tbk_trans");
$approved_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tt_tbk_trans WHERE status = 'approved'");
$rejected_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tt_tbk_trans WHERE status = 'rejected'");
$initialized_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tt_tbk_trans WHERE status = 'initialized'");
$total_amount       = (float) $wpdb->get_var("SELECT SUM(amount) FROM {$wpdb->prefix}tt_tbk_trans WHERE status = 'approved'");

// Instanciar tabla y preparar items
$list_table = new TT_Transbank_List_Table();
$list_table->prepare_items();

// Datos paginación para leyenda tipo "Mostrando X–Y de Z"
$total_filtered = (int) $list_table->get_pagination_arg('total_items');
$per_page       = (int) $list_table->get_pagination_arg('per_page');
$current_page   = (int) $list_table->get_pagenum();
$from           = ($total_filtered > 0) ? (($current_page - 1) * $per_page + 1) : 0;
$to             = min($total_filtered, $current_page * $per_page);

?>
<div class="wrap">
    <h1><?php _e('Transacciones Transbank - Tur Transportes', 'tur-transportes'); ?></h1>

    <!-- Estadísticas rápidas -->
    <div class="tt-dashboard-stats" style="display:flex;gap:15px;flex-wrap:wrap;margin-top:10px;">
        <div class="tt-stat-card" style="background:#fff;padding:15px 20px;border-radius:6px;border:1px solid #ccd0d4;min-width:180px;">
            <h3 style="margin:0 0 5px;"><?php _e('Total Transacciones', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number" style="font-size:20px;font-weight:bold;"><?php echo esc_html($total_transactions); ?></span>
        </div>

        <div class="tt-stat-card" style="background:#fff;padding:15px 20px;border-radius:6px;border:1px solid #ccd0d4;min-width:180px;">
            <h3 style="margin:0 0 5px;"><?php _e('Aprobadas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number" style="font-size:20px;font-weight:bold;color:#00a32a;"><?php echo esc_html($approved_count); ?></span>
        </div>

        <div class="tt-stat-card" style="background:#fff;padding:15px 20px;border-radius:6px;border:1px solid #ccd0d4;min-width:180px;">
            <h3 style="margin:0 0 5px;"><?php _e('Rechazadas', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number" style="font-size:20px;font-weight:bold;color:#d63638;"><?php echo esc_html($rejected_count); ?></span>
        </div>

        <div class="tt-stat-card" style="background:#fff;padding:15px 20px;border-radius:6px;border:1px solid #ccd0d4;min-width:220px;">
            <h3 style="margin:0 0 5px;"><?php _e('Monto Total Pagado', 'tur-transportes'); ?></h3>
            <span class="tt-stat-number" style="font-size:20px;font-weight:bold;color:#0073aa;">
                $<?php echo number_format($total_amount ?: 0, 0, ',', '.'); ?>
            </span>
        </div>
    </div>

    <!-- Filtros -->
    <div class="tt-filters-container" style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;border-radius:4px;">
        <h2><?php _e('Filtrar Transacciones', 'tur-transportes'); ?></h2>

        <form method="get" action="">
            <input type="hidden" name="page" value="<?php echo isset($_GET['page']) ? esc_attr(wp_unslash($_GET['page'])) : ''; ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:15px;">
                <div>
                    <label for="search" style="font-weight:bold;display:block;margin-bottom:5px;"><?php _e('Buscar', 'tur-transportes'); ?></label>
                    <input type="text" id="search" name="search"
                           value="<?php echo esc_attr($search_term); ?>"
                           placeholder="<?php _e('Orden, token, cliente, email, RUT...', 'tur-transportes'); ?>"
                           style="width:100%;">
                </div>

                <div>
                    <label for="start_date" style="font-weight:bold;display:block;margin-bottom:5px;"><?php _e('Fecha desde', 'tur-transportes'); ?></label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo esc_attr($start_date); ?>"
                           style="width:100%;">
                </div>

                <div>
                    <label for="end_date" style="font-weight:bold;display:block;margin-bottom:5px;"><?php _e('Fecha hasta', 'tur-transportes'); ?></label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo esc_attr($end_date); ?>"
                           style="width:100%;">
                </div>

                <div>
                    <label for="status" style="font-weight:bold;display:block;margin-bottom:5px;"><?php _e('Estado', 'tur-transportes'); ?></label>
                    <select id="status" name="status" style="width:100%;">
                        <option value="all"><?php _e('Todos los estados', 'tur-transportes'); ?></option>
                        <option value="initialized" <?php selected($status_filter, 'initialized'); ?>>
                            <?php _e('Inicializada', 'tur-transportes'); ?> (<?php echo esc_html($initialized_count); ?>)
                        </option>
                        <option value="approved" <?php selected($status_filter, 'approved'); ?>>
                            <?php _e('Aprobada', 'tur-transportes'); ?> (<?php echo esc_html($approved_count); ?>)
                        </option>
                        <option value="rejected" <?php selected($status_filter, 'rejected'); ?>>
                            <?php _e('Rechazada', 'tur-transportes'); ?> (<?php echo esc_html($rejected_count); ?>)
                        </option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit" class="button button-primary">
                    <?php _e('Aplicar filtros', 'tur-transportes'); ?>
                </button>

                <?php if ($search_term || $start_date || $end_date || $status_filter): ?>
                    <a href="?page=<?php echo isset($_GET['page']) ? esc_attr(wp_unslash($_GET['page'])) : ''; ?>" class="button">
                        <?php _e('Limpiar Filtros', 'tur-transportes'); ?>
                    </a>
                <?php endif; ?>

                <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:5px;"></span>
                    <?php _e('Exportar a CSV', 'tur-transportes'); ?>
                </a>

                <span style="margin-left:auto;color:#666;font-style:italic;">
                    <?php
                    if ($total_filtered === $total_transactions) {
                        printf(
                            __('Mostrando %1$d–%2$d de %3$d transacciones', 'tur-transportes'),
                            $from,
                            $to,
                            $total_transactions
                        );
                    } else {
                        printf(
                            __('Mostrando %1$d–%2$d de %3$d transacciones filtradas (total: %4$d)', 'tur-transportes'),
                            $from,
                            $to,
                            $total_filtered,
                            $total_transactions
                        );
                    }
                    ?>
                </span>
            </div>
        </form>
    </div>

    <!-- Tabla con paginación WP_List_Table -->
    <form method="post">
        <?php
        $list_table->display();
        ?>
    </form>
</div>

<!-- Modal para detalles de transacción -->
<div id="tt-transaction-modal" style="display:none;">
    <div class="tt-modal-content" style="max-width:700px;">
        <span class="tt-close-modal">&times;</span>
        <div id="tt-transaction-details"></div>
    </div>
</div>

<style>
.tt-status-initialized { 
    color: #ffb900; 
    font-weight: bold; 
    background: #fff8e5; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px; 
}
.tt-status-approved { 
    color: #00a32a; 
    font-weight: bold; 
    background: #f0f9f0; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px; 
}
.tt-status-rejected { 
    color: #d63638; 
    font-weight: bold; 
    background: #fdf2f2; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px; 
}

.tt-environment-integration { 
    color: #ff6b00; 
    font-weight: bold; 
    background: #fff0e5; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px; 
}
.tt-environment-production { 
    color: #0073aa; 
    font-weight: bold; 
    background: #e5f6ff; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px; 
}

.tt-transaction-info {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin: 15px 0;
}

.tt-transaction-detail {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.tt-transaction-detail:last-child {
    border-bottom: none;
}

.tt-detail-label {
    font-weight: 600;
    color: #495057;
}

.tt-detail-value {
    color: #212529;
    text-align: right;
}

.tt-booking-data {
    background: #e7f3ff;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
    border-left: 4px solid #0073aa;
}

.tt-booking-data h4 {
    margin-top: 0;
    color: #0073aa;
    border-bottom: 1px solid #cfe2ff;
    padding-bottom: 8px;
}

/* Fondo */
#tt-transaction-modal {
    position: fixed;
    z-index: 99999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    padding: 20px;
    background-color: rgba(0,0,0,0.55);
    display: flex;
    justify-content: center;
    align-items: center;
}

/* Caja del modal */
.tt-modal-content {
    background: #fff;
    width: 95%;
    max-width: 750px;
    max-height: 90vh;         
    overflow-y: auto;           
    padding: 25px;
    border-radius: 10px;
    position: relative;
    box-shadow: 0 10px 40px rgba(0,0,0,0.25);
    animation: ttModalFade .25s ease-out;
}

/* Botón cerrar */
.tt-close-modal {
    position: sticky;        
    top: 0;
    float: right;
    font-size: 22px;
    cursor: pointer;
    color: #555;
    padding-bottom: 10px;
    background: #fff;
    z-index: 3;
}

.tt-close-modal:hover {
    color: #000;
}

/* Animación */
@keyframes ttModalFade {
    from {opacity:0; transform: translateY(-25px);}
    to   {opacity:1; transform: translateY(0);}
}

/* Scroll bonito (opcional) */
.tt-modal-content::-webkit-scrollbar {
    width: 8px;
}
.tt-modal-content::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 4px;
}

</style>

<script type="text/javascript">
jQuery(document).ready(function($) {

    $('.tt-view-details').on('click', function() {
        var txJson = $(this).attr('data-transaction');
        var tx = null;

        try {
            tx = JSON.parse(txJson);
        } catch (e) {
            console.error('Error parseando data-transaction:', e, txJson);
            alert('No se pudieron cargar los datos de la transacción.');
            return;
        }

        if (!tx) {
            alert('No se pudieron cargar los datos de la transacción.');
            return;
        }

        // N° Cuotas
        var cuotas = (typeof tx.installments_number !== 'undefined' && tx.installments_number !== null)
            ? tx.installments_number
            : 0;

        // Info de reserva (si existe booking_data)
        var bookingHtml = '';
        if (tx.booking_data) {
            try {
                var b = JSON.parse(tx.booking_data);
                bookingHtml = `
                    <div class="tt-booking-data">
                        <h4>Datos de la Reserva</h4>
                        <div class="tt-transaction-detail">
                            <span class="tt-detail-label">Origen:</span>
                            <span class="tt-detail-value">${b.step1 && b.step1.origin ? b.step1.origin : '—'}</span>
                        </div>
                        <div class="tt-transaction-detail">
                            <span class="tt-detail-label">Destino:</span>
                            <span class="tt-detail-value">${b.step1 && b.step1.destination ? b.step1.destination : '—'}</span>
                        </div>
                        <div class="tt-transaction-detail">
                            <span class="tt-detail-label">Fecha:</span>
                            <span class="tt-detail-value">${b.step1 && b.step1.date ? b.step1.date : '—'}</span>
                        </div>
                        <div class="tt-transaction-detail">
                            <span class="tt-detail-label">Hora:</span>
                            <span class="tt-detail-value">${b.step1 && b.step1.time ? b.step1.time : '—'}</span>
                        </div>
                        <div class="tt-transaction-detail">
                            <span class="tt-detail-label">Pasajeros:</span>
                            <span class="tt-detail-value">${b.step1 && b.step1.passengers ? b.step1.passengers : '—'}</span>
                        </div>
                    </div>
                `;
            } catch (e) {
                console.error('Error parseando booking_data', e);
                bookingHtml = "<p style='color:#999;'>Error al interpretar datos de la reserva.</p>";
            }
        }

        var html = `
            <h3>Detalles de la Transacción</h3>
            <div class="tt-transaction-info">
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">ID:</span>
                    <span class="tt-detail-value">${tx.id}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Orden de Compra:</span>
                    <span class="tt-detail-value">${tx.buy_order}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Token:</span>
                    <span class="tt-detail-value"><code>${tx.token}</code></span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Session ID:</span>
                    <span class="tt-detail-value">${tx.session_id}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Monto:</span>
                    <span class="tt-detail-value"><strong>$${new Intl.NumberFormat('es-CL').format(parseFloat(tx.amount))}</strong></span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">N° Cuotas:</span>
                    <span class="tt-detail-value">${cuotas}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Estado:</span>
                    <span class="tt-detail-value tt-status-${tx.status}">${tx.status}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Código de Autorización:</span>
                    <span class="tt-detail-value">${tx.authorization_code || '—'}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Código de Respuesta:</span>
                    <span class="tt-detail-value">${tx.response_code || '—'}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Fecha Transacción:</span>
                    <span class="tt-detail-value">${tx.transaction_date || '—'}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Ambiente:</span>
                    <span class="tt-detail-value tt-environment-${tx.environment}">${tx.environment}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Creado:</span>
                    <span class="tt-detail-value">${tx.created_at}</span>
                </div>
                <div class="tt-transaction-detail">
                    <span class="tt-detail-label">Actualizado:</span>
                    <span class="tt-detail-value">${tx.updated_at || '—'}</span>
                </div>
            </div>
            ${bookingHtml}
        `;

        $('#tt-transaction-details').html(html);
        $('#tt-transaction-modal').fadeIn(150);
    });

    $('.tt-close-modal').on('click', function() {
        $('#tt-transaction-modal').fadeOut(150);
    });

    $(window).on('click', function(e) {
        if (e.target.id === 'tt-transaction-modal') {
            $('#tt-transaction-modal').fadeOut(150);
        }
    });
});
</script>
