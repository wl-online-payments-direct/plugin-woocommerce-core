<?php

namespace WOP;

/**
 * Classic checkout card form (hosted-tokenization iframe flow).
 *
 * @var string   $gatewayId         Gateway id, used as a data attribute and to build the iframe id.
 * @var string   $sessionUrl        REST URL that returns a tokenization session.
 * @var string   $product           Payment product id, or '' for the grouped Cards gateway.
 * @var string   $tokenizationField Hidden input name carrying the tokenization id.
 * @var string[] $deviceFields      Hidden input names carrying the browser 3DS device metrics.
 */
\defined('ABSPATH') || exit;
?>
<div class="worldlineop-classic-card"
     data-gateway="<?php 
echo \esc_attr($gatewayId);
?>"
     data-session-url="<?php 
echo \esc_url($sessionUrl);
?>"
     data-product="<?php 
echo \esc_attr($product);
?>">
    <div class="worldlineop-classic-card-iframe" id="worldlineop-classic-<?php 
echo \esc_attr(\sanitize_html_class($gatewayId));
?>"></div>
    <input type="hidden" name="<?php 
echo \esc_attr($tokenizationField);
?>" value="" autocomplete="off" />
    <?php 
foreach ($deviceFields as $field) {
    ?>
        <input type="hidden" name="<?php 
    echo \esc_attr($field);
    ?>" value="" autocomplete="off" />
    <?php 
}
?>
</div>
<?php 
