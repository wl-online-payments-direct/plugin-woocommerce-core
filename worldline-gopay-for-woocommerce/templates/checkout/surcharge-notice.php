<?php

namespace WOP;

/**
 * Surcharge notice shown above the classic checkout / order-pay payment area.
 *
 * @var string $notice Translated surcharge message provided by the caller.
 */
\defined('ABSPATH') || exit;
?>
<div class="woocommerce-info worldlineop-surcharge-notice"><?php 
echo \esc_html($notice);
?></div>
<?php 
