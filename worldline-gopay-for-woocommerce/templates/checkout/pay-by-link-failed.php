<?php

namespace WOP;

/**
 * "Payment not completed" page for a cancelled or failed Pay by Link payment.
 *
 * @var string $shopUrl URL of the shop page for the "Continue shopping" button.
 */
\defined('ABSPATH') || exit;
?>
<style>body{padding-left:40px;padding-right:40px;overflow-x:hidden;}</style>
<div class="woocommerce" style="max-width:640px;margin:40px auto;padding:0 20px;text-align:center;">
    <h1><?php 
echo \esc_html__('Payment not completed', 'worldline-gopay-for-woocommerce');
?></h1>
    <p><?php 
echo \esc_html__('Your payment was not completed. Please contact the store if you would like to try again.', 'worldline-gopay-for-woocommerce');
?></p>
    <p><a class="button" href="<?php 
echo \esc_url($shopUrl);
?>"><?php 
echo \esc_html__('Continue shopping', 'worldline-gopay-for-woocommerce');
?></a></p>
</div>
<?php 
