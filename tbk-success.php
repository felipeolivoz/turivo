<?php
if (!defined('ABSPATH')) exit;

/**
 * Variables disponibles:
 * $booking_id
 * $amount_formatted
 * $buy_order
 * $auth_code
 */

global $wpdb;
$table = $wpdb->prefix . 'tt_bookings';
$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $booking_id));
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

    /* LOGO A LA IZQUIERDA */
    .tt-header img {
        max-width: 140px;
        height: auto;
    }

    .tt-title {
        display: flex;
        align-items: center;
        font-size: 28px;
        font-weight: 700;
        color: #0a2540;
        text-align: right;
    }

    .tt-title .check {
        color: #16a34a;
        font-size: 36px;
        margin-left: 10px;
    }

    .tt-box {
        background: #f9fbff;
        border-left: 5px solid #1e3a8a;
        padding: 22px;
        margin-top: 28px;
        border-radius: 10px;
    }

    .tt-box h3 {
        margin-top: 0;
        font-size: 20px;
        color: #0f172a;
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
        background: #0a2540;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: 0.25s ease;
    }

    .tt-buttons a:hover,
    .tt-buttons button:hover {
        background: #11263f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(10,37,64,0.25);
    }

</style>


<div class="tt-container">

    <div class="tt-header">

        <!-- LOGO A LA IZQUIERDA -->
        <img src="<?php echo esc_url(get_site_icon_url()); ?>" alt="Logo">

        <!-- TÍTULO A LA DERECHA -->
        <div class="tt-title">
            <?php _e('Pago exitoso', 'tur-transportes'); ?>
            <span class="check">✔</span>
        </div>
    </div>


    <!-- RESUMEN -->
    <div class="tt-box">
        <h3>Resumen de la operación</h3>
        <p>✔ Pago aprobado por Transbank.</p>
        <p>✔ Reserva registrada en el sistema.</p>
    </div>

    <!-- DATOS DEL CLIENTE -->
    <div class="tt-box">
        <h3>Datos del cliente</h3>
        <div class="tt-row">
            <p><strong>Nombre:</strong> <?php echo esc_html($booking->customer_name); ?></p>
            <p><strong>Email:</strong> <?php echo esc_html($booking->customer_email); ?></p>
            <p><strong>Teléfono:</strong> <?php echo esc_html($booking->customer_phone); ?></p>
            <p><strong>RUT:</strong> <?php echo esc_html($booking->customer_rut); ?></p>
        </div>
    </div>

    <!-- DATOS DE LA RESERVA -->
    <div class="tt-box">
        <h3>Datos de la reserva</h3>
        <div class="tt-row">
            <p><strong>Dirección origen:</strong> <?php echo esc_html($booking->origin_address); ?></p>
            <p><strong>Dirección destino:</strong> <?php echo esc_html($booking->destination_address); ?></p>
            <p><strong>Fecha:</strong> <?php echo esc_html($booking->transfer_date); ?></p>
            <p><strong>Hora:</strong> <?php echo esc_html($booking->transfer_time); ?></p>
            <p><strong>Pasajeros:</strong> <?php echo esc_html($booking->passengers); ?></p>
        </div>
    </div>

    <!-- DETALLES DEL PAGO -->
    <div class="tt-box">
        <h3>Detalles del pago</h3>
        <div class="tt-row">
            <p><strong>Monto pagado:</strong> $<?php echo esc_html($amount_formatted); ?></p>
            <p><strong>Orden de compra:</strong> <?php echo esc_html($buy_order); ?></p>
            <p><strong>Código de autorización:</strong> <?php echo esc_html($auth_code); ?></p>
        </div>
    </div>

    <div class="tt-buttons">
        <a href="<?php echo esc_url(home_url()); ?>">Ir al inicio</a>
        <button onclick="window.print();">Imprimir</button>
    </div>

</div>
