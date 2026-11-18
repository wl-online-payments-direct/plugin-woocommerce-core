<?php

namespace WOP\OnlinePayments\Product;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\ProductTypes\ProductType;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Product-edit meta box with a product-type selector for Mealvouchers eligibility.
 */
class ProductTypeMetaBox
{
    private static function nonceAction(): string
    {
        return ModuleHelper::moduleKey('_product_type_nonce');
    }
    private static function fieldName(): string
    {
        return ModuleHelper::moduleKey('_product_type');
    }
    private static function ajaxAction(): string
    {
        return ModuleHelper::moduleKey('_save_product_type');
    }
    private static function metaboxId(): string
    {
        return ModuleHelper::moduleKey('-product-type');
    }
    private static function buttonId(): string
    {
        return ModuleHelper::moduleKey('-save-product-type');
    }
    /** Human label per product type, falling back to the raw value if unknown. */
    private static function label(string $type): string
    {
        switch ($type) {
            case 'FoodAndDrink':
                return esc_html__('Food and drink', 'worldline-gopay-for-woocommerce');
            case 'HomeAndGarden':
                return esc_html__('Home and garden', 'worldline-gopay-for-woocommerce');
            case 'GiftAndFlowers':
                return esc_html__('Gift and flowers', 'worldline-gopay-for-woocommerce');
            default:
                return $type;
        }
    }
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
        add_action('wp_ajax_' . self::ajaxAction(), [$this, 'ajaxSave']);
    }
    public function addMetaBox(): void
    {
        add_meta_box(self::metaboxId(), ModuleHelper::getModuleConfig()->getName(), [$this, 'render'], 'product', 'side', 'default');
    }
    /**
     * @param mixed $post The WP_Post for the product being edited.
     */
    public function render($post): void
    {
        $productId = (string) (is_object($post) ? $post->ID ?? 0 : 0);
        try {
            $response = AdminAPI::get()->productTypes()->list($productId);
        } catch (\Throwable $e) {
            echo '<p class="description">' . sprintf(
                /* translators: %s: brand name. */
                esc_html__('%s product types are currently unavailable.', 'worldline-gopay-for-woocommerce'),
                esc_html(ModuleHelper::getModuleConfig()->getName())
            ) . '</p>';
            return;
        }
        $selected = $response->getSelectedProductType();
        $selectedValue = $selected !== null ? (string) $selected : '';
        $options = [];
        foreach ($response->getAvailableProductTypes() as $type) {
            $value = (string) $type;
            $options[] = ['value' => $value, 'label' => self::label($value)];
        }
        ModuleHelper::renderTemplate('product/product-type-metabox.php', ['fieldName' => self::fieldName(), 'buttonId' => self::buttonId(), 'ajaxAction' => self::ajaxAction(), 'nonceAction' => self::nonceAction(), 'selectedValue' => $selectedValue, 'options' => $options, 'productId' => $productId]);
    }
    /**
     * AJAX save behind the meta box "Save" button, without saving the whole product.
     */
    public function ajaxSave(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::nonceAction())) {
            wp_send_json_error(['message' => esc_html__('Invalid request.', 'worldline-gopay-for-woocommerce')]);
        }
        $productId = absint($_POST['product_id'] ?? 0);
        if ($productId <= 0 || !current_user_can('edit_product', $productId)) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to perform this action.', 'worldline-gopay-for-woocommerce')]);
        }
        $value = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        try {
            if ($value === '') {
                AdminAPI::get()->productTypes()->delete((string) $productId);
                wp_send_json_success(['message' => esc_html__('Product type removed.', 'worldline-gopay-for-woocommerce')]);
            }
            AdminAPI::get()->productTypes()->save((string) $productId, ProductType::parse($value));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => esc_html__('Could not save the product type.', 'worldline-gopay-for-woocommerce')]);
        }
        wp_send_json_success(['message' => esc_html__('Product type saved.', 'worldline-gopay-for-woocommerce')]);
    }
    /**
     * @param int $postId The product being saved.
     */
    public function save($postId): void
    {
        if (!isset($_POST[self::nonceAction()]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::nonceAction()])), self::nonceAction()) || !current_user_can('edit_product', $postId)) {
            return;
        }
        $value = isset($_POST[self::fieldName()]) ? sanitize_text_field(wp_unslash($_POST[self::fieldName()])) : '';
        $productId = (string) $postId;
        try {
            if ($value === '') {
                AdminAPI::get()->productTypes()->delete($productId);
                return;
            }
            AdminAPI::get()->productTypes()->save($productId, ProductType::parse($value));
        } catch (\Throwable $e) {
        }
    }
}
