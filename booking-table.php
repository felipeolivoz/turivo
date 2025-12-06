<?php 
if (!defined('ABSPATH')) {
    exit;
}
?>

<style>
    .tt-bookings-container {
        overflow-x: auto;
    }

    .tt-bookings-container table.wp-list-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: auto;
    }

    .tt-bookings-container th.column-status,
    .tt-bookings-container td.column-status,
    .tt-bookings-container th.column-actions,
    .tt-bookings-container td.column-actions {
        white-space: nowrap;
    }

    .tt-booking-status-form {
        display: inline-block;
        margin: 0;
    }

    .tt-booking-status-form select {
        max-width: 140px;
    }

    .tt-view-details {
        white-space: nowrap;
    }
</style>

<div class="tt-bookings-container">
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Código', 'tur-transportes'); ?></th>
                <th><?php _e('RUT', 'tur-transportes'); ?></th>
                <th><?php _e('Cliente', 'tur-transportes'); ?></th>
                <th><?php _e('Fecha del traslado', 'tur-transportes'); ?></th>
                <th><?php _e('Total', 'tur-transportes'); ?></th>
                <th class="column-status"><?php _e('Estado', 'tur-transportes'); ?></th>
                <th class="column-actions"><?php _e('Acciones', 'tur-transportes'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($bookings)) : ?>
                <?php foreach ($bookings as $booking) : ?>
                    <tr>
                        <!-- Código -->
                        <td><?php echo esc_html($booking->booking_code); ?></td>

                        <!-- RUT -->
                        <td><?php echo esc_html($booking->customer_rut); ?></td>

                        <!-- Cliente -->
                        <td><?php echo esc_html($booking->customer_name); ?></td>

                        <!-- Fecha del traslado -->
                        <td>
                            <?php
                            $fecha = !empty($booking->transfer_date) ? $booking->transfer_date : '—';
                            $hora  = !empty($booking->transfer_time) ? $booking->transfer_time : '';
                            echo esc_html($fecha . ($hora ? ' ' . $hora : ''));
                            ?>
                        </td>

                        <!-- Total -->
                        <td>
                            <?php echo esc_html('$' . number_format((float) $booking->total_price, 0, ',', '.')); ?>
                        </td>

                        <!-- Estado (select) -->
                        <td class="column-status">
                            <form method="post" class="tt-booking-status-form">
                                <?php wp_nonce_field('tt_update_booking'); ?>
                                <input type="hidden" name="booking_id" value="<?php echo intval($booking->id); ?>">
                                <select name="booking_status" onchange="this.form.submit()">
                                    <option value="confirmada" <?php selected($booking->status, 'confirmada'); ?>>
                                        <?php _e('Confirmada', 'tur-transportes'); ?>
                                    </option>
                                    <option value="asignada" <?php selected($booking->status, 'asignada'); ?>>
                                        <?php _e('Asignada', 'tur-transportes'); ?>
                                    </option>
                                    <option value="finalizada" <?php selected($booking->status, 'finalizada'); ?>>
                                        <?php _e('Finalizada', 'tur-transportes'); ?>
                                    </option>
                                    <option value="cancelada" <?php selected($booking->status, 'cancelada'); ?>>
                                        <?php _e('Cancelada', 'tur-transportes'); ?>
                                    </option>
                                </select>
                                <input type="hidden" name="update_booking_status" value="1">
                            </form>
                        </td>

                        <!-- Acciones -->
                        <td class="column-actions">
                            <button
                                type="button"
                                class="button button-secondary tt-view-details"
                                data-booking='<?php echo esc_attr(wp_json_encode($booking)); ?>'
                            >
                                <?php _e('Ver Detalles', 'tur-transportes'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="7">
                        <?php _e('No hay reservas que coincidan con los filtros aplicados', 'tur-transportes'); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
