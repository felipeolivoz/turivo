<?php
if (!defined('ABSPATH')) exit;

/**
 * Variables disponibles:
 * $response
 * $token
 */

global $wpdb;
$table_tbk  = $wpdb->prefix . 'tt_tbk_trans';
$table_book = $wpdb->prefix . 'tt_bookings';

// Transacción desde la BD
$trans = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $table_tbk WHERE token = %s", $token)
);

// Si existe booking_id, obtener la reserva
$booking = null;
if ($trans && !empty($trans->booking_id)) {
    $booking = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_book WHERE id = %d", $trans->booking_id)
    );
}

// Datos empresa
$site_name  = get_bloginfo('name');
$admin_email = get_bloginfo('admin_email');
$site_url   = home_url();

?>

<style>
    .tt-container {
        max-width: 820px;
        margin: 40px auto;
        padding: 32px;
        background: #ffffff;
        border-radius: 14px;
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        box-shadow: 0 4px 18px rgba(0,0,0,0.08);
    }

    .tt-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* LOGO IZQUIERDA */
    .tt-header img {
        max-width: 140px;
        height: auto;
    }

    .tt-title {
        display: flex;
        align-items: center;
        font-size: 28px;
        font-weight: 700;
        color: #991b1b;
        text-align: right;
    }

    .tt-title .cross {
        color: #dc2626;
        font-size: 36px;
        margin-left: 10px;
    }

    .tt-box {
        background: #fff7f7;
        border-left: 5px solid #dc2626;
        padding: 22px;
        margin-top: 28px;
        border-radius: 10px;
    }

    .tt-box h3 {
        margin-top: 0;
        font-size: 20px;
        color: #991b1b;
    }

    .tt-row p {
        margin: 6px 0;
        color: #1e293b;
        font-size: 15px;
    }

    .tt-buttons {
        margin-top: 30px;
        display: flex;
        gap: 12px;
    }

    .tt-buttons a, .tt-buttons button {
        padding: 12px 24px;
        border-radius: 8px;
        background: #991b1b;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: 0.25s ease;
    }

    .tt-buttons a:hover,
    .tt-buttons button:hover {
        background: #7f1d1d;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(153,27,27,0.25);
    }

    /* Caja empresa */
    .tt-company-box {
        background: #f1f5f9;
        border-left: 5px solid #475569;
        padding: 18px;
        margin-top: 30px;
        border-radius: 10px;
    }

    .tt-company-box p {
        margin: 4px 0;
        font-size: 14px;
        color: #334155;
    }

</style>


<div class="tt-container">

    <!-- Header -->
    <div class="tt-header">
        <img src="<?php echo esc_url(get_site_icon_url()); ?>" alt="Logo">

        <div class="tt-title">
            <?php _e('Pago rechazado', 'tur-transportes'); ?>
            <span class="cross">✘</span>
        </div>
    </div>


    <!-- Resumen -->
    <div class="tt-box">
        <h3>Resumen de la operación</h3>

        <p>✘ La transacción fue rechazada por Transbank.</p>

        <?php if ($booking): ?>
            <p>✔ La reserva se registró en el sistema. (ID: <?php echo esc_html($booking->id); ?>)</p>
        <?php else: ?>
            <p>✘ No se registró ninguna reserva en el sistema.</p>
        <?php endif; ?>
    </div>


    <!-- Motivos del error -->
    <div class="tt-box">
        <h3>Motivos del error</h3>
        <div class="tt-row">
            <p><strong>Código de respuesta:</strong> <?php echo esc_html($response->getResponseCode()); ?></p>
            <p><strong>Orden de compra:</strong> <?php echo esc_html($response->getBuyOrder()); ?></p>
            <p><strong>Monto:</strong> $<?php echo number_format($response->getAmount(), 0, ',', '.'); ?></p>
            <p><strong>Descripción técnica:</strong> Transbank rechazó la operación.</p>
        </div>
    </div>


    <?php if ($booking): ?>
        <!-- Datos del cliente -->
        <div class="tt-box">
            <h3>Datos del cliente</h3>
            <p><strong>Nombre:</strong> <?php echo esc_html($booking->customer_name); ?></p>
            <p><strong>Email:</strong> <?php echo esc_html($booking->customer_email); ?></p>
            <p><strong>Teléfono:</strong> <?php echo esc_html($booking->customer_phone); ?></p>
        </div>

        <!-- Datos de la reserva -->
        <div class="tt-box">
            <h3>Datos de la reserva</h3>
            <p><strong>Origen:</strong> <?php echo esc_html($booking->origin_address); ?></p>
            <p><strong>Destino:</strong> <?php echo esc_html($booking->destination_address); ?></p>
            <p><strong>Fecha:</strong> <?php echo esc_html($booking->transfer_date); ?></p>
            <p><strong>Hora:</strong> <?php echo esc_html($booking->transfer_time); ?></p>
        </div>
    <?php endif; ?>


    <!-- Botones -->
    <div class="tt-buttons">
        <a href="<?php echo esc_url(home_url()); ?>">Ir al inicio</a>
        <button onclick="window.print();">Imprimir</button>
    </div>


    <!-- Datos empresa -->
    <div class="tt-company-box">
        <p><strong><?php echo esc_html($site_name); ?></strong></p>
        <p>Sitio web: <a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_url); ?></a></p>
        <p>Correo de contacto: <?php echo esc_html($admin_email); ?></p>
        <p>Si necesitas asistencia, no dudes en contactarnos.</p>
    </div>

</div>
