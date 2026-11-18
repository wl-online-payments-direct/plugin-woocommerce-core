<?php

namespace WOP;

/**
 * Existing Worldline payment link for an order, with a copy-to-clipboard button.
 *
 * @var string $url     The generated payment link.
 * @var string $expires Expiration date (Y-m-d), or '' when not stored.
 */
\defined('ABSPATH') || exit;
?>
<p class="description"><?php 
\esc_html_e('Send this link to the customer to complete the payment.', 'worldline-gopay-for-woocommerce');
?></p>
<p><input type="text" readonly class="wlop-pbl-link" style="width:100%;" value="<?php 
echo \esc_attr($url);
?>" onclick="this.select();" /></p>
<p>
    <button type="button" class="button wlop-pbl-copy" data-url="<?php 
echo \esc_attr($url);
?>"><?php 
\esc_html_e('Copy link', 'worldline-gopay-for-woocommerce');
?></button>
    <span class="wlop-pbl-msg description"></span>
</p>
<?php 
if ($expires !== '') {
    ?>
    <p class="description"><?php 
    \esc_html_e('Expires on', 'worldline-gopay-for-woocommerce');
    ?> <strong><?php 
    echo \esc_html($expires);
    ?></strong></p>
<?php 
}
?>
<script>
(function () {
    var btn = document.querySelector('.wlop-pbl-copy');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var msg = document.querySelector('.wlop-pbl-msg');
        var done = function () { if (msg) { msg.textContent = '<?php 
echo \esc_js(\__('Copied.', 'worldline-gopay-for-woocommerce'));
?>'; } };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(btn.getAttribute('data-url')).then(done, function () {});
        } else {
            var input = document.querySelector('.wlop-pbl-link');
            if (input) { input.select(); document.execCommand('copy'); done(); }
        }
    });
})();
</script>
<?php 
