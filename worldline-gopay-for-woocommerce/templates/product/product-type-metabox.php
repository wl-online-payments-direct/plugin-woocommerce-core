<?php

namespace WOP;

/**
 * Product-type selector for Mealvouchers eligibility, with an AJAX "Save" button.
 *
 * @var string                                    $fieldName     Select field name.
 * @var string                                    $buttonId      Save button DOM id.
 * @var string                                    $ajaxAction    wp_ajax action name.
 * @var string                                    $nonceAction   Nonce action name.
 * @var string                                    $selectedValue Currently selected product type.
 * @var array<int, array{value: string, label: string}> $options  Available product types.
 * @var string                                    $productId     Product id being edited.
 */
\defined('ABSPATH') || exit;
\wp_nonce_field($nonceAction, $nonceAction);
?>
<p class="description"><?php 
\esc_html_e('Flag this product for Mealvouchers eligibility.', 'worldline-gopay-for-woocommerce');
?></p>
<select name="<?php 
echo \esc_attr($fieldName);
?>" style="width:100%;">
    <option value="" <?php 
\selected($selectedValue, '');
?>><?php 
\esc_html_e('None', 'worldline-gopay-for-woocommerce');
?></option>
    <?php 
foreach ($options as $option) {
    ?>
        <option value="<?php 
    echo \esc_attr($option['value']);
    ?>" <?php 
    \selected($selectedValue, $option['value']);
    ?>>
            <?php 
    echo \esc_html($option['label']);
    ?>
        </option>
    <?php 
}
?>
</select>
<p style="text-align:center;margin-top:10px;">
    <button type="button" class="button button-primary" id="<?php 
echo \esc_attr($buttonId);
?>"><?php 
\esc_html_e('Save', 'worldline-gopay-for-woocommerce');
?></button>
</p>
<p id="<?php 
echo \esc_attr($buttonId);
?>-msg" class="description" style="text-align:center;margin:0;"></p>
<script>
(function () {
    var btn = document.getElementById('<?php 
echo \esc_js($buttonId);
?>');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var select = document.querySelector('[name="<?php 
echo \esc_js($fieldName);
?>"]');
        var msg = document.getElementById('<?php 
echo \esc_js($buttonId);
?>-msg');
        var data = new FormData();
        data.append('action', '<?php 
echo \esc_js($ajaxAction);
?>');
        data.append('product_id', '<?php 
echo (int) $productId;
?>');
        data.append('type', select ? select.value : '');
        data.append('nonce', '<?php 
echo \esc_js(\wp_create_nonce($nonceAction));
?>');
        btn.disabled = true;
        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) { msg.textContent = (res && res.data) ? res.data.message : ''; })
            .catch(function () { msg.textContent = '<?php 
echo \esc_js(\__('Could not save the product type.', 'worldline-gopay-for-woocommerce'));
?>'; })
            .then(function () { btn.disabled = false; });
    });
})();
</script>
<?php 
