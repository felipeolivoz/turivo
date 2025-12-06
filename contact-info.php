<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="tt-contact-info">
    <h3><?php echo esc_html(get_option('tt_company_name', 'Tur Transportes')); ?></h3>
    
    <?php if ($atts['show_phone'] === 'yes' && get_option('tt_contact_phone')): ?>
    <p class="tt-contact-item">
        <span class="dashicons dashicons-phone"></span>
        <strong><?php _e('Teléfono:', 'tur-transportes'); ?></strong> 
        <?php echo esc_html(get_option('tt_contact_phone')); ?>
    </p>
    <?php endif; ?>
    
    <?php if ($atts['show_email'] === 'yes' && get_option('tt_contact_email')): ?>
    <p class="tt-contact-item">
        <span class="dashicons dashicons-email"></span>
        <strong><?php _e('Email:', 'tur-transportes'); ?></strong> 
        <a href="mailto:<?php echo esc_attr(get_option('tt_contact_email')); ?>">
            <?php echo esc_html(get_option('tt_contact_email')); ?>
        </a>
    </p>
    <?php endif; ?>
    
    <div class="tt-booking-link">
        <a href="#reservar" class="tt-book-btn">
            <?php _e('Hacer una Reserva', 'tur-transportes'); ?>
        </a>
    </div>
</div>