<?php

namespace WOP;

/**
 * Payment-waiting box shown on the thank-you page while the outcome is still pending.
 * Polling is handled by assets/checkout/payment-waiting.js (config in window.worldlineopWait).
 *
 * @var string $message       Escaped, translated waiting message provided by the caller.
 * @var string $transactionId Worldline transaction id to quote when contacting support (may be empty).
 * @var string $contactUrl    Customer-service URL shown in the contact notice (may be empty).
 */
\defined('ABSPATH') || exit;
?>
<style>
.woocommerce-order > :not(#worldlineop-wait),
.woocommerce-thankyou-order-received,
.woocommerce-order-overview,
.woocommerce-order-details,
.woocommerce-customer-details,
.wc-block-order-confirmation-status > :not(#worldlineop-wait),
.wp-block-woocommerce-order-confirmation-summary,
.wp-block-woocommerce-order-confirmation-totals,
.wp-block-woocommerce-order-confirmation-totals-wrapper,
.wp-block-woocommerce-order-confirmation-billing-address,
.wp-block-woocommerce-order-confirmation-billing-wrapper,
.wp-block-woocommerce-order-confirmation-shipping-address,
.wp-block-woocommerce-order-confirmation-shipping-wrapper,
.wp-block-woocommerce-order-confirmation-additional-fields,
.wp-block-woocommerce-order-confirmation-additional-fields-wrapper,
.wp-block-woocommerce-order-confirmation-additional-information,
.wp-block-woocommerce-order-confirmation-downloads,
.wp-block-woocommerce-order-confirmation-downloads-wrapper,
.wp-block-woocommerce-order-confirmation-create-account {
    display: none !important;
}
</style>
<div id="worldlineop-wait" style="margin:1.5em auto;max-width:560px;width:100%;padding:2.5rem 2rem;background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center;box-sizing:border-box;">
    <div id="worldlineop-wait-spinner">
        <p style="font-size:1.15rem;font-weight:600;margin:0 0 1.25rem;"><?php 
echo \esc_html($message);
?></p>
        <svg width="120" height="120" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid" aria-label="Loading" style="display:inline-block;">
            <rect x="17.5" y="30" width="15" height="40" fill="#007b7c">
                <animate attributeName="y" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="18;30;30" keySplines="0 0.5 0.5 1;0 0.5 0.5 1" begin="-0.2s"/>
                <animate attributeName="height" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="64;40;40" keySplines="0 0.5 0.5 1;0 0.5 0.5 1" begin="-0.2s"/>
            </rect>
            <rect x="42.5" y="30" width="15" height="40" fill="#007b7c">
                <animate attributeName="y" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="21;30;30" keySplines="0 0.5 0.5 1;0 0.5 0.5 1" begin="-0.1s"/>
                <animate attributeName="height" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="58;40;40" keySplines="0 0.5 0.5 1;0 0.5 0.5 1" begin="-0.1s"/>
            </rect>
            <rect x="67.5" y="30" width="15" height="40" fill="#007b7c">
                <animate attributeName="y" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="21;30;30" keySplines="0 0.5 0.5 1;0 0.5 0.5 1"/>
                <animate attributeName="height" repeatCount="indefinite" dur="1s" calcMode="spline" keyTimes="0;0.5;1" values="58;40;40" keySplines="0 0.5 0.5 1;0 0.5 0.5 1"/>
            </rect>
        </svg>
    </div>
    <div id="worldlineop-wait-contact" hidden style="text-align:left;margin-top:1.75rem;background:#fff8e1;border-left:4px solid #ffa000;border-radius:4px;padding:1rem 1.25rem;color:#5d4037;font-size:.95rem;">
        <p style="margin:0 0 .75rem;font-weight:600;"><?php 
echo \esc_html__('The transaction has not been confirmed yet.', 'worldline-gopay-for-woocommerce');
?></p>
        <p style="margin:0;"><?php 
echo \esc_html__('We suggest you contact our customer service for assistance.', 'worldline-gopay-for-woocommerce');
?>
            <?php 
if ($contactUrl !== '') {
    ?>
                <a href="<?php 
    echo \esc_url($contactUrl);
    ?>" target="_blank" rel="noopener"><?php 
    echo \esc_html__('Contact support', 'worldline-gopay-for-woocommerce');
    ?></a>
            <?php 
}
?>
        </p>
        <?php 
if ($transactionId !== '') {
    ?>
            <p style="margin:.75rem 0 0;"><?php 
    echo \esc_html__('Please also provide this transaction reference:', 'worldline-gopay-for-woocommerce');
    ?>
                <strong><?php 
    echo \esc_html($transactionId);
    ?></strong></p>
        <?php 
}
?>
    </div>
</div>
<?php 
