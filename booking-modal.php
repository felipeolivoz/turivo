<?php
if (!defined('ABSPATH')) exit;
?>

<!-- Overlay -->
<div id="tt-booking-modal" class="tt-modal-overlay" style="display:none;">
    <div class="tt-modal-dialog">

        <!-- Botón cerrar -->
        <button type="button" class="tt-close-modal">&times;</button>

        <!-- HEADER -->
        <div class="tt-modal-header">
            <div class="tt-modal-title-block">
                <h3 id="tt-booking-title"><?php _e('Detalle de la Reserva', 'tur-transportes'); ?></h3>
                <p class="tt-modal-subtitle"><?php _e('Información completa del viaje, cliente y pago.', 'tur-transportes'); ?></p>
            </div>

            <div class="tt-modal-header-meta">
                <div class="tt-badge-pill tt-badge-code">
                    <span class="tt-badge-label"><?php _e('Código', 'tur-transportes'); ?></span>
                    <span class="tt-badge-value" id="tt-badge-booking-code">—</span>
                </div>

                <div class="tt-badge-pill" id="tt-status-pill">
                    <span class="tt-badge-label"><?php _e('Estado', 'tur-transportes'); ?></span>
                    <span class="tt-badge-value" id="tt-badge-status">—</span>
                </div>
            </div>
        </div>

        <!-- BODY -->
        <div class="tt-modal-body">
            <div id="tt-booking-details"></div>
        </div>

        <!-- FOOTER -->
        <div class="tt-modal-footer">
            <button type="button" class="button button-secondary tt-close-modal-footer">
                <?php _e('Cerrar', 'tur-transportes'); ?>
            </button>
        </div>

    </div>
</div>

<!-- =============================== -->
<!-- =========   ESTILOS   ========= -->
<!-- =============================== -->

<style>
/* ---------- Overlay ---------- */
.tt-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(10, 20, 40, 0.65); /* azul oscuro translúcido */
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    backdrop-filter: blur(6px);
}

/* ---------- Dialog ---------- */
.tt-modal-dialog {
    background: #ffffff;
    width: 100%;
    max-width: 880px;
    max-height: 92vh;
    border-radius: 18px;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
    box-shadow:
        0 20px 45px rgba(10, 20, 40, 0.30),
        0 0 0 1px rgba(10, 20, 40, 0.10);
}

/* ---------- Close button ---------- */
.tt-close-modal {
    position: absolute;
    top: 12px;
    right: 16px;
    width: 34px;
    height: 34px;
    background: #0A2540;
    color: #fff;
    font-size: 20px;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    transition: 0.2s;
    box-shadow: 0 2px 8px rgba(10, 20, 40, 0.25);
}
.tt-close-modal:hover {
    transform: scale(1.08);
    background: #133a63;
}

/* ---------- Header ---------- */
.tt-modal-header {
    background: linear-gradient(145deg, #0A2540 0%, #133a63 40%, #1e58a6 100%);
    padding: 22px 26px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.tt-modal-header h3 {
    color: #ffffff !important;
    margin: 0;
    font-size: 22px;
    font-weight: 600;
}

.tt-modal-subtitle {
    color: #ccd6e3 !important;
    margin: 6px 0 0;
    font-size: 13px;
}
/* ---------- Badges en fila ---------- */
.tt-modal-header-meta {
    display: flex;
    flex-direction: row;      
    align-items: center;
    gap: 10px;
    flex-wrap: nowrap;        
    margin-left: auto;        
    padding-top: 4px;
}
/* ---------- Badges ---------- */
.tt-badge-pill {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.18);
    padding: 8px 14px;
    border-radius: 14px;
    min-width: 120px;         
    white-space: nowrap;      
    display: flex;
    flex-direction: column;
    justify-content: center;
    backdrop-filter: blur(4px);
	 flex-direction: column; 
	 left: -25px;
	 position: relative;
}
.tt-badge-code {
    background: rgba(255,255,255,0.18);
}
.tt-badge-label {
    color: #e5ecf5;
    font-size: 10px;
    text-transform: uppercase;
}
.tt-badge-value {
    color: #ffffff;
    font-weight: 600;
    font-size: 14px;
}

/* ---------- Body ---------- */
.tt-modal-body {
    padding: 22px 26px;
    overflow-y: auto;
    background: #f8fafc;
}
.tt-modal-body::-webkit-scrollbar {
    width: 8px;
}
.tt-modal-body::-webkit-scrollbar-thumb {
    background: #c7d0dd;
    border-radius: 10px;
}

/* ---------- Footer ---------- */
.tt-modal-footer {
    padding: 14px 22px;
    background: #f0f4f8;
    border-top: 1px solid #d9e2ec;
}

/* ---------- Cards ---------- */
.tt-section-card {
    background: #ffffff;
    border: 1px solid #d9e2ec;
    border-radius: 14px;
    padding: 16px 18px;
    box-shadow: 0 1px 2px rgba(10,20,40,0.05);
}

.tt-sec-title {
    font-size: 15px;
    font-weight: 600;
    color: #0A2540;
    border-left: 4px solid #1e58a6;
    padding-left: 8px;
    margin-bottom: 12px;
}

/* ---------- Info rows ---------- */
.tt-info-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 14px;
}
.tt-info-label {
    color: #5e6c80;
}
.tt-info-value {
    color: #0A2540;
    font-weight: 600;
}

/* Grid 2 columns */
.tt-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 760px) {
    .tt-grid-2 { grid-template-columns: 1fr; }
}


</style>

<!-- =============================== -->
<!-- ========   JAVASCRIPT   ======= -->
<!-- =============================== -->

<script type="text/javascript">
jQuery(document).ready(function ($) {

    function ttSafe(v, fallback = "—") {
        return (v === null || v === undefined || v === "") ? fallback : v;
    }

    function ttFormatCLP(value) {
        let num = parseFloat(value || 0);
        if (isNaN(num)) return "$0";
        return num.toLocaleString("es-CL");
    }

    $(".tt-view-details").on("click", function () {

        let booking = $(this).data("booking") || {};

        // ==========================
        //   BADGES DEL HEADER
        // ==========================
        $("#tt-badge-booking-code").text(ttSafe(booking.booking_code));
        $("#tt-badge-status").text(ttSafe(booking.status));

        // ==========================
        //     N° DE CUOTAS
        // ==========================
        let cuotas = ttSafe(booking.installments_number, 0);

        // ==========================
        //       HTML PRINCIPAL
        // ==========================
        let html = `
            <div class="tt-section-card">
                <div class="tt-sec-title">Información del Cliente</div>

                <div class="tt-info-row"><span class="tt-info-label">Nombre:</span>
                    <span class="tt-info-value">${ttSafe(booking.customer_name)}</span>
                </div>

                <div class="tt-info-row"><span class="tt-info-label">RUT:</span>
                    <span class="tt-info-value">${ttSafe(booking.customer_rut)}</span>
                </div>

                <div class="tt-info-row"><span class="tt-info-label">Email:</span>
                    <span class="tt-info-value">${ttSafe(booking.customer_email)}</span>
                </div>

                <div class="tt-info-row"><span class="tt-info-label">Teléfono:</span>
                    <span class="tt-info-value">${ttSafe(booking.customer_phone)}</span>
                </div>
            </div>

            <div class="tt-grid-2">
                <div class="tt-section-card">
                    <div class="tt-sec-title">Detalles del Viaje</div>

                    <div class="tt-info-row"><span class="tt-info-label">Fecha:</span>
                        <span class="tt-info-value">${ttSafe(booking.transfer_date)}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Hora:</span>
                        <span class="tt-info-value">${ttSafe(booking.transfer_time)}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Tipo Traslado:</span>
                        <span class="tt-info-value">${ttSafe(booking.transfer_name)}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Vehículo:</span>
                        <span class="tt-info-value">${ttSafe(booking.vehicle_name)}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Pasajeros:</span>
                        <span class="tt-info-value">${ttSafe(booking.passengers)}</span>
                    </div>
                </div>

                <div class="tt-section-card">
                    <div class="tt-sec-title">Pago</div>

                    <div class="tt-info-row"><span class="tt-info-label">Total Pagado:</span>
                        <span class="tt-info-value">$${ttFormatCLP(booking.total_price)}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Método:</span>
                        <span class="tt-info-value">${ttSafe(booking.payment_method, "Transbank")}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Estado del Pago:</span>
                        <span class="tt-info-value">${ttSafe(booking.payment_status, "Pagado")}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">N° Cuotas:</span>
                        <span class="tt-info-value">${cuotas}</span>
                    </div>

                    <div class="tt-info-row"><span class="tt-info-label">Referencia Transbank:</span>
                        <span class="tt-info-value">${ttSafe(booking.payment_reference)}</span>
                    </div>
                </div>
            </div>

            <div class="tt-section-card">
                <div class="tt-sec-title">Direcciones</div>

                <div class="tt-info-row"><span class="tt-info-label">Origen:</span>
                    <span class="tt-info-value">${ttSafe(booking.origin_address)}</span>
                </div>

                <div class="tt-info-row"><span class="tt-info-label">Destino:</span>
                    <span class="tt-info-value">${ttSafe(booking.destination_address)}</span>
                </div>
            </div>
        `;

        $("#tt-booking-details").html(html);
        $("#tt-booking-modal").fadeIn(160);
        $("body").css("overflow", "hidden");
    });

    // Cerrar modal
    $(".tt-close-modal, .tt-close-modal-footer").on("click", function () {
        $("#tt-booking-modal").fadeOut(160);
        $("body").css("overflow", "auto");
    });

    $("#tt-booking-modal").on("click", function (e) {
        if (e.target.id === "tt-booking-modal") {
            $(".tt-close-modal").click();
        }
    });

    $(document).on("keydown", function (e) {
        if (e.key === "Escape") $(".tt-close-modal").click();
    });
});
</script>
