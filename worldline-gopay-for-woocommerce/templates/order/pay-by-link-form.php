<?php

namespace WOP;

/**
 * Pay by Link generation form for an unpaid order.
 *
 * @var string $brandName   Brand display name.
 * @var string $defaultDate Pre-filled expiration date (Y-m-d).
 * @var string $minDate     Earliest selectable date (Y-m-d).
 * @var string $maxDate     Latest selectable date (Y-m-d).
 * @var int    $orderId     Order id.
 * @var string $nonce       Nonce for the generate request.
 * @var string $ajaxAction  wp_ajax action name.
 */
\defined('ABSPATH') || exit;
?>
<p class="description"><?php 
/* translators: %s: brand name. */
echo \esc_html(\sprintf(\__('Generate a %s payment link for this order.', 'worldline-gopay-for-woocommerce'), $brandName));
?></p>
<p>
    <label><?php 
\esc_html_e('Expiration date', 'worldline-gopay-for-woocommerce');
?>
        <input type="date" class="wlop-pbl-expires" style="width:100%;"
               value="<?php 
echo \esc_attr($defaultDate);
?>"
               min="<?php 
echo \esc_attr($minDate);
?>"
               max="<?php 
echo \esc_attr($maxDate);
?>" />
    </label>
</p>
<p>
    <button type="button" class="button button-primary wlop-pbl-generate"
            data-order="<?php 
echo \esc_attr((string) $orderId);
?>"
            data-nonce="<?php 
echo \esc_attr($nonce);
?>"><?php 
\esc_html_e('Generate Pay by Link', 'worldline-gopay-for-woocommerce');
?></button>
    <span class="wlop-pbl-msg description"></span>
</p>
<script>
(function () {
    var btn = document.querySelector('.wlop-pbl-generate');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var msg = document.querySelector('.wlop-pbl-msg');
        var input = document.querySelector('.wlop-pbl-expires');
        var data = new FormData();
        data.append('action', '<?php 
echo \esc_js($ajaxAction);
?>');
        data.append('order', btn.getAttribute('data-order'));
        data.append('nonce', btn.getAttribute('data-nonce'));
        data.append('expires', input ? input.value : '');
        btn.disabled = true;
        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    window.location.reload();
                    return;
                }
                if (msg) { msg.textContent = (res && res.data) ? res.data.message : ''; }
                btn.disabled = false;
            })
            .catch(function () {
                if (msg) { msg.textContent = '<?php 
echo \esc_js(\__('Could not generate the payment link.', 'worldline-gopay-for-woocommerce'));
?>'; }
                btn.disabled = false;
            });
    });
})();
</script>
<?php 
