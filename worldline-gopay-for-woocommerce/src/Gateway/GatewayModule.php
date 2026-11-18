<?php

namespace WOP\OnlinePayments\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WOP\OnlinePayments\Admin\AdminAssets;
use WOP\OnlinePayments\Checkout\PayByLinkReturnHandler;
use WOP\OnlinePayments\Checkout\PaymentReturnHandler;
use WOP\OnlinePayments\Checkout\WooCommerceCartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\GeneralSettingsService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\CreditCard;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Repositories\PaymentMethodConfigRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\MigrationNotice;
use WOP\OnlinePayments\Order\OrderActionsMetaBox;
use WOP\OnlinePayments\Order\OrderListColumns;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderEditGuard;
use WOP\OnlinePayments\Order\OrderStatuses;
use WOP\OnlinePayments\Order\PayByLinkBox;
use WOP\OnlinePayments\Product\ProductTypeMetaBox;
use WOP\OnlinePayments\Services\Integration\LogoUrlService;
use WOP\OnlinePayments\Services\Integration\SavedCardSync;
use WOP\OnlinePayments\Services\Integration\WcTokensRepository;
use WOP\OnlinePayments\Services\PaymentButtonTitleSetting;
use WOP\OnlinePayments\Services\SavedCardsSetting;
use WOP\OnlinePayments\Services\TaskRunnerKeepAlive;
defined('ABSPATH') || exit;
/**
 * Registers the Worldline gateways (the main Hosted Checkout gateway plus one
 * gateway per admin-enabled method), wires block checkout, the Configure link, the
 * return handler, the background-task keep-alive and the migration notice.
 */
class GatewayModule
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Per-request cache of the admin-enabled methods, shared by addGateway() and registerBlocks(). */
    private static ?array $enabledMethodsCache = null;
    /** Per-request cache for hasEnabledPaymentMethod(). */
    private static ?bool $hasEnabledMethodCache = null;
    /** Per-request cache of the pay-by-link settings (enabled, title, expirationTime). */
    private static ?array $payByLinkSettingsCache = null;
    /** Hooks everything into WordPress. */
    public function register(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'addGateway']);
        (new OrderStatuses())->register();
        add_action('admin_enqueue_scripts', static function (): void {
            AdminAssets::enqueue();
        });
        if (defined('WOP_ONLINE_PAYMENTS_PLUGIN_FILE')) {
            add_filter('plugin_action_links_' . plugin_basename(\WOP_ONLINE_PAYMENTS_PLUGIN_FILE), [$this, 'addActionLink']);
        }
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('woocommerce_admin_field_payment_gateways', [$this, 'hideMethodGatewaysFromAdminList']);
        add_action('current_screen', [$this, 'hidePayByLinkInBlockEditor']);
        add_action('admin_init', [$this, 'redirectMethodManage']);
        add_action('woocommerce_payment_token_deleted', [WcTokensRepository::class, 'onWcTokenDeleted'], 10, 2);
        add_action('woocommerce_payment_token_deleted', [SavedCardSync::class, 'onTokenDeleted'], 20, 2);
        add_action('woocommerce_new_payment_token', [SavedCardSync::class, 'onTokenCreated'], 10, 2);
        add_filter('woocommerce_get_customer_payment_tokens', [$this, 'filterCardTokens'], 10, 3);
        add_filter('woocommerce_saved_payment_methods_list', [$this, 'hideSavedCardsAtCheckout'], 20, 1);
        add_filter('woocommerce_payment_gateway_get_saved_payment_method_option_html', [$this, 'prefixVaultTitleClassic'], 10, 3);
        add_filter('woocommerce_payment_methods_list_item', [$this, 'prefixVaultTitleBlock'], 20, 2);
        add_filter('woocommerce_pay_order_button_text', [$this, 'payOrderButtonText'], 10, 1);
        add_filter('woocommerce_order_fully_refunded_status', [$this, 'deferRefundedStatus'], 10, 3);
        add_action('wp_head', [$this, 'hideAddPaymentMethodButton']);
        add_action('wp_head', [$this, 'hideCardOnAddPaymentMethodPage']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueClassicCardAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueClassicEmailRefresh'], 20);
        add_action('woocommerce_checkout_update_order_review', [$this, 'syncClassicCheckoutEmail']);
        add_action('woocommerce_review_order_before_payment', [$this, 'renderClassicSurchargeNotice']);
        add_action('woocommerce_pay_order_before_submit', [$this, 'renderClassicSurchargeNotice']);
        (new PaymentReturnHandler())->register();
        (new TaskRunnerKeepAlive())->register();
        (new MigrationNotice())->register();
        (new OrderActionsMetaBox())->register();
        (new OrderListColumns())->register();
        (new ProductTypeMetaBox())->register();
        (new OrderEditGuard())->register();
        (new PayByLinkBox())->register();
        (new PayByLinkReturnHandler())->register();
    }
    /**
     * Whether a non-Worldline gateway can add a saved method (so the native add flow stays usable).
     *
     * @return bool
     */
    private function hasForeignAddCapableGateway(): bool
    {
        $paymentGateways = function_exists('WC') ? WC()->payment_gateways() : null;
        if ($paymentGateways === null) {
            return \false;
        }
        foreach ($paymentGateways->get_available_payment_gateways() as $gateway) {
            if (!ModuleHelper::ownsGateway($gateway->id) && ($gateway->supports('add_payment_methods') || $gateway->supports('tokenization'))) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Removes Worldline saved cards from the list the block checkout renders while saved cards are
     * disabled. The block checkout lists saved methods for every available gateway and does not
     * read the method's `showSavedCards` flag, so they are removed here. The My Account pages keep
     * the full list so customers can still delete their cards.
     *
     * @param array $list Saved methods grouped by type, each with `method.gateway`.
     *
     * @return array
     */
    public function hideSavedCardsAtCheckout($list)
    {
        if (!is_array($list) || SavedCardsSetting::isEnabled()) {
            return $list;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return $list;
        }
        foreach ($list as $type => $methods) {
            $list[$type] = array_values(array_filter((array) $methods, static function ($method): bool {
                return !ModuleHelper::ownsGateway((string) ($method['method']['gateway'] ?? ''));
            }));
        }
        return array_filter($list);
    }
    /**
     * Hides the native "Add payment method" button on the Payment methods tab when Worldline is the
     * only add-capable gateway (we save cards at checkout, not via the standalone flow). CSS-only, so
     * the available-gateways list that decides whether the tab exists is left untouched.
     */
    public function hideAddPaymentMethodButton(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('payment-methods')) {
            return;
        }
        if ($this->hasForeignAddCapableGateway()) {
            return;
        }
        echo '<style>.woocommerce-MyAccount-content a[href*="add-payment-method"]{display:none!important;}</style>';
    }
    /**
     * Hides our (non-functional) card option on the add-payment-method page when another gateway keeps
     * that page in use, leaving the working gateway selectable.
     */
    public function hideCardOnAddPaymentMethodPage(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('add-payment-method')) {
            return;
        }
        if (!$this->hasForeignAddCapableGateway()) {
            return;
        }
        $paymentGateways = function_exists('WC') ? WC()->payment_gateways() : null;
        if ($paymentGateways === null) {
            return;
        }
        $selectors = [];
        foreach ($paymentGateways->get_available_payment_gateways() as $gateway) {
            if (ModuleHelper::ownsGateway($gateway->id)) {
                $selectors[] = 'li.payment_method_' . sanitize_html_class($gateway->id);
            }
        }
        if ($selectors !== []) {
            echo '<style>' . esc_html(implode(',', $selectors)) . '{display:none!important;}</style>';
        }
    }
    /**
     * Keeps a fully-refunded Worldline order in its current status instead of letting WooCommerce
     * switch it to "refunded" as soon as the refund request is submitted. The refund is only final
     * once Worldline confirms it via the webhook, which then applies the refunded status - matching
     * how capture and cancel wait for the webhook. Non-Worldline orders are left untouched.
     *
     * @param string $status
     * @param int $orderId
     * @param int $refundId
     *
     * @return string
     */
    public function deferRefundedStatus(string $status, int $orderId = 0, int $refundId = 0): string
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && ModuleHelper::ownsGateway($order->get_payment_method())) {
            return $order->get_status();
        }
        return $status;
    }
    /**
     * Reconciles saved card mirrors before WooCommerce shows them: drops mirrors the core
     * no longer holds and, when card grouping is disabled, re-homes survivors onto their
     * per-brand gateways. In-memory only.
     *
     * @param \WC_Payment_Token[] $tokens
     * @param int $customerId
     * @param string $gatewayId
     *
     * @return \WC_Payment_Token[]
     */
    public function filterCardTokens(array $tokens, int $customerId, string $gatewayId): array
    {
        if ($tokens === []) {
            return [];
        }
        $coreTokenIds = $this->coreTokenIds($customerId);
        $grouped = CardGateway::isGroupingEnabled();
        $cardsGatewayId = MethodCatalog::gatewayId(PaymentProductId::cards());
        $result = [];
        foreach ($tokens as $token) {
            if (!$token instanceof \WC_Payment_Token || !ModuleHelper::ownsGateway((string) $token->get_gateway_id())) {
                $result[] = $token;
                continue;
            }
            // Hide mirrors the core can't charge; null means lookup failed, so keep them.
            if ($coreTokenIds !== null && !in_array($token->get_token(), $coreTokenIds, \true)) {
                continue;
            }
            if (!$grouped && $token->get_gateway_id() === $cardsGatewayId) {
                $productId = (string) $token->get_meta('paymentProductId');
                if ($productId !== '') {
                    try {
                        $token->set_gateway_id(MethodCatalog::gatewayId(PaymentProductId::parse($productId)));
                    } catch (\Throwable $e) {
                        // Unknown product id - leave the token on the grouped gateway.
                    }
                }
            }
            $result[] = $token;
        }
        return $result;
    }
    /**
     * Worldline token ids the core currently holds for the customer, or null when the
     * lookup is unavailable.
     *
     * @param int $customerId
     *
     * @return string[]|null
     */
    private function coreTokenIds(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        try {
            return StoreContext::doWithStore(self::STORE_ID, static function () use ($customerId) {
                $tokens = ServiceRegister::getService(TokensRepositoryInterface::class)->getForCustomer((string) $customerId);
                return array_map(static function ($token) {
                    return $token->getTokenId();
                }, $tokens);
            });
        } catch (\Throwable $e) {
            return null;
        }
    }
    /**
     * Redirects the native "Manage" screen of a per-method gateway to the main settings SPA.
     */
    public function redirectMethodManage(): void
    {
        if (!is_admin() || (isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '') !== 'wc-settings' || (isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '') !== 'checkout') {
            return;
        }
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        $main = ModuleHelper::getMainGatewayId();
        if ($section === '' || $section === $main || !ModuleHelper::ownsGateway($section)) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $main));
        exit;
    }
    /**
     * Registers Hosted Checkout (main) + one gateway per admin-enabled method.
     *
     * @param array<int, string|\WC_Payment_Gateway> $gateways
     *
     * @return array<int, string|\WC_Payment_Gateway>
     */
    public function addGateway(array $gateways): array
    {
        $gateways[] = PaymentGateway::class;
        foreach ($this->enabledMethods() as $method) {
            if (!$method['productId']->equals(PaymentProductId::CARDS)) {
                $gateways[] = new MethodGateway($method['productId'], $method['label']);
                continue;
            }
            // Always register the grouped cards gateway so its saved-card tokens are not dropped.
            $gateways[] = new CardGateway(PaymentProductId::cards(), $method['label']);
            if (!CardGateway::isGroupingEnabled()) {
                foreach (PaymentProductId::getAllCardBrands() as $brand) {
                    $gateways[] = new CardGateway($brand, MethodCatalog::label($brand));
                }
            }
        }
        $payByLink = $this->payByLinkSettings();
        if (!empty($payByLink['enabled'])) {
            $title = !empty($payByLink['title']) ? (string) $payByLink['title'] : sprintf(
                /* translators: %s: payment gateway name. */
                esc_html__('%s Pay by Link', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            );
            $gateways[] = new PayByLinkGateway($title);
        }
        return $gateways;
    }
    /**
     * Removes the per-method gateways from the admin Payments list while they remain registered.
     */
    public function hideMethodGatewaysFromAdminList(): void
    {
        $store = function_exists('WC') ? WC()->payment_gateways() : null;
        if ($store === null) {
            return;
        }
        foreach ($store->payment_gateways as $key => $gateway) {
            if ($gateway instanceof MethodGateway || $gateway instanceof PayByLinkGateway) {
                unset($store->payment_gateways[$key]);
            }
        }
    }
    /**
     * Keeps Pay by Link out of the checkout block editor's payment-method list. WooCommerce builds
     * that list from the enabled gateways, and Pay by Link must stay enabled so it can be chosen on
     * the admin order screen - but it is not a block-checkout method, so without this it is flagged
     * there as "incompatible". Only the block editor context is touched (its enabled flag is dropped
     * for this request); the storefront is unaffected since is_available() is already false.
     *
     * @param mixed $screen
     */
    public function hidePayByLinkInBlockEditor($screen = null): void
    {
        if (!$screen instanceof \WP_Screen || !$screen->is_block_editor()) {
            return;
        }
        $store = function_exists('WC') ? WC()->payment_gateways() : null;
        if ($store === null) {
            return;
        }
        $gateways = $store->payment_gateways();
        $id = PayByLinkGateway::id();
        if (isset($gateways[$id])) {
            $gateways[$id]->enabled = 'no';
        }
    }
    /**
     * Registers a block-checkout type per gateway id plus the shared client-side script.
     *
     * @param mixed $registry PaymentMethodRegistry
     */
    public function registerBlocks($registry): void
    {
        if (!class_exists(AbstractPaymentMethodType::class)) {
            return;
        }
        wp_register_script(ModuleHelper::moduleKey('-blocks'), ModuleHelper::getAssetUrl('blocks/blocks.js'), ['wc-blocks-registry', 'wp-element', 'wc-settings', 'wp-api-fetch'], ModuleHelper::getModuleConfig()->getVersion(), \true);
        $logoService = new LogoUrlService();
        $surchargeNotice = $this->surchargeNotice();
        $mainId = ModuleHelper::getMainGatewayId();
        $mainExtra = $surchargeNotice !== '' ? ['surchargeNotice' => $surchargeNotice] : [];
        $mainButtonTitle = PaymentButtonTitleSetting::get((string) PaymentProductId::hostedCheckout());
        if ($mainButtonTitle !== '') {
            $mainExtra['orderButtonText'] = $mainButtonTitle;
        }
        $registry->register(new BlocksSupport($mainId, self::configuredTitle(PaymentProductId::hostedCheckout(), ModuleHelper::getModuleConfig()->getName()), PaymentGateway::resolveLogoUrl(), $mainExtra));
        $ids = [$mainId];
        foreach ($this->enabledMethods() as $method) {
            $isCards = $method['productId']->equals(PaymentProductId::CARDS);
            $entries = $isCards ? $this->cardCheckoutEntries($method) : [$method];
            $isIframeCards = $isCards && CardGateway::isIframeCheckout() && $this->registerTokenizerSdk();
            foreach ($entries as $entry) {
                $id = MethodCatalog::gatewayId($entry['productId']);
                $extraData = [];
                $handles = [ModuleHelper::moduleKey('-blocks')];
                if ($isIframeCards) {
                    $sessionUrl = rest_url(ModuleHelper::getRestNamespace() . '/card/tokenization-session');
                    if (!$entry['productId']->equals(PaymentProductId::CARDS)) {
                        $sessionUrl = add_query_arg('productId', (string) $entry['productId'], $sessionUrl);
                    }
                    $extraData = ['cardsIframe' => \true, 'sessionUrl' => $sessionUrl, 'tokenizationIdField' => CardGateway::tokenizationIdField(), 'deviceFields' => WooCommerceCartProvider::deviceFields(), 'sdkUrl' => CardGateway::tokenizerSdkUrl(), 'loadErrorMessage' => esc_html__('The card form could not be loaded. Please refresh the page and try again.', 'worldline-gopay-for-woocommerce'), 'cardErrorMessage' => esc_html__('Please check your card details and try again.', 'worldline-gopay-for-woocommerce')];
                    $handles = [ModuleHelper::moduleKey('-tokenizer-sdk'), ModuleHelper::moduleKey('-blocks')];
                }
                if ($isCards) {
                    $extraData['showSavedCards'] = SavedCardsSetting::isEnabled();
                }
                if ($surchargeNotice !== '') {
                    $extraData['surchargeNotice'] = $surchargeNotice;
                }
                $entryButtonTitle = PaymentButtonTitleSetting::get($this->buttonTitleProductIdFor($entry['productId']));
                if ($entryButtonTitle !== '') {
                    $extraData['orderButtonText'] = $entryButtonTitle;
                }
                $registry->register(new BlocksSupport($id, $entry['label'], $logoService->getLogoUrlIfExists((string) $entry['productId']), $extraData, $handles));
                $ids[] = $id;
            }
        }
        wp_localize_script(ModuleHelper::moduleKey('-blocks'), ModuleHelper::moduleKey('Blocks'), ['methods' => $ids]);
    }
    /**
     * Checkout entries for the configured Cards method: the grouped method itself, or
     * one entry per card brand when grouping is disabled.
     *
     * @param array{productId: PaymentProductId, label: string} $cardsMethod
     *
     * @return array<int, array{productId: PaymentProductId, label: string}>
     */
    private function cardCheckoutEntries(array $cardsMethod): array
    {
        if (CardGateway::isGroupingEnabled()) {
            return [$cardsMethod];
        }
        $entries = [];
        foreach (PaymentProductId::getAllCardBrands() as $brand) {
            $entries[] = ['productId' => $brand, 'label' => MethodCatalog::label($brand)];
        }
        return $entries;
    }
    /**
     * The checkout surcharge notice, shown when surcharging is enabled; empty otherwise.
     *
     * @return string
     */
    private function surchargeNotice(): string
    {
        // Read only the payment settings: the full general settings need a connection, and the
        // facade logs an error on every page load while the plugin is not connected yet.
        try {
            $applies = (bool) StoreContext::doWithStore(self::STORE_ID, static function (): bool {
                return ServiceRegister::getService(GeneralSettingsService::class)->getPaymentSettings()->isApplySurcharge();
            });
        } catch (\Throwable $e) {
            return '';
        }
        return $applies ? esc_html__('A surcharge may be applied to your total depending on the payment method you use.', 'worldline-gopay-for-woocommerce') : '';
    }
    /**
     * Enqueues the classic-checkout card script when the iframe card flow is active.
     */
    public function enqueueClassicCardAssets(): void
    {
        $onCheckout = function_exists('is_checkout') && is_checkout() || function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
        if (!$onCheckout || !CardGateway::isIframeCheckout()) {
            return;
        }
        wp_register_script(ModuleHelper::moduleKey('-classic-cards'), ModuleHelper::getAssetUrl('checkout/classic-cards.js'), ['jquery'], ModuleHelper::getModuleConfig()->getVersion(), \true);
        wp_localize_script(ModuleHelper::moduleKey('-classic-cards'), ModuleHelper::moduleKey('Classic'), ['sdkUrl' => CardGateway::tokenizerSdkUrl(), 'nonce' => wp_create_nonce('wp_rest'), 'tokenField' => CardGateway::tokenizationIdField(), 'deviceFields' => WooCommerceCartProvider::deviceFields(), 'cardErrorMessage' => esc_html__('Please check your card details and try again.', 'worldline-gopay-for-woocommerce')]);
        wp_enqueue_script(ModuleHelper::moduleKey('-classic-cards'));
        wp_register_style(ModuleHelper::moduleKey('-classic-cards'), \false, [], ModuleHelper::getModuleConfig()->getVersion());
        wp_enqueue_style(ModuleHelper::moduleKey('-classic-cards'));
        wp_add_inline_style(ModuleHelper::moduleKey('-classic-cards'), sprintf(
            // The new-card form keeps a minimum height: on narrow screens the iframe resizer
            // leaves it shorter than its content and the save-card text is cut off.
            '.%1$s{width:100%%;}.%1$s-iframe iframe{border:none;width:100%%;}' . '.%1$s-iframe.%1$s-new iframe{min-height:525px;}',
            ModuleHelper::moduleKey('-classic-card')
        ));
    }
    /**
     * Stores the billing email typed on the classic checkout in the customer session. The classic
     * order review refresh stores only the address, and Mealvouchers is offered to guests only once
     * the core sees an email, so without this a guest never gets it on the classic checkout.
     *
     * @param string $postData Serialized checkout form.
     */
    public function syncClassicCheckoutEmail($postData): void
    {
        if (!function_exists('WC') || WC()->customer === null || !$this->isMealvouchersEnabled()) {
            return;
        }
        parse_str((string) $postData, $fields);
        $email = sanitize_email((string) ($fields['billing_email'] ?? ''));
        if ($email === '' || !is_email($email)) {
            return;
        }
        WC()->customer->set_billing_email($email);
    }
    /**
     * Refreshes the classic checkout when the billing email changes, so payment methods that depend
     * on it (Mealvouchers for guests) appear without waiting for an address change. Only on the
     * classic checkout: WooCommerce enqueues its checkout script on the block checkout too, so pages
     * with the checkout block are skipped explicitly.
     */
    public function enqueueClassicEmailRefresh(): void
    {
        if (!wp_script_is('wc-checkout', 'enqueued') || !$this->isMealvouchersEnabled()) {
            return;
        }
        if (function_exists('WOP\has_block') && has_block('woocommerce/checkout')) {
            return;
        }
        wp_register_script(ModuleHelper::moduleKey('-classic-email'), \false, ['jquery', 'wc-checkout'], ModuleHelper::getModuleConfig()->getVersion(), \true);
        wp_enqueue_script(ModuleHelper::moduleKey('-classic-email'));
        wp_add_inline_script(ModuleHelper::moduleKey('-classic-email'), <<<'JS'
jQuery(function ($) {
    $('form.checkout').on('change', '#billing_email', function () {
        $(document.body).trigger('update_checkout');
    });
});
JS
);
    }
    /**
     * Whether Mealvouchers is enabled in the Worldline configuration.
     *
     * @return bool
     */
    private function isMealvouchersEnabled(): bool
    {
        foreach ($this->enabledMethods() as $method) {
            if ($method['productId']->equals(PaymentProductId::MEALVOUCHERS)) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Renders the surcharge notice above the classic checkout / order-pay payment area.
     */
    public function renderClassicSurchargeNotice(): void
    {
        $notice = $this->surchargeNotice();
        if ($notice === '') {
            return;
        }
        ModuleHelper::renderTemplate('checkout/surcharge-notice.php', ['notice' => $notice]);
    }
    /**
     * Registers the Worldline hosted-tokenization client library; false when no brand endpoint is available.
     *
     * @return bool
     */
    private function registerTokenizerSdk(): bool
    {
        $url = CardGateway::tokenizerSdkUrl();
        if ($url === '') {
            return \false;
        }
        wp_register_script(ModuleHelper::moduleKey('-tokenizer-sdk'), $url, [], null, \true);
        return \true;
    }
    /**
     * Prepends a Configure link (to the Worldline settings section) to the plugin's action links.
     *
     * @param array<int|string, string> $links
     *
     * @return array<int|string, string>
     */
    public function addActionLink(array $links): array
    {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . ModuleHelper::getMainGatewayId());
        $configureLink = sprintf('<a href="%s" aria-label="%s">%s</a>', esc_url($url), esc_attr(sprintf(
            /* translators: %s: plugin name */
            esc_html__('Configure %s', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        )), esc_html__('Configure', 'worldline-gopay-for-woocommerce'));
        return array_merge(['configure' => $configureLink], $links);
    }
    /**
     * Whether the merchant has at least one Worldline payment method enabled in the plugin (Hosted
     * Checkout included). Used to gate the WooCommerce Payments-list "Enable" action: until a method
     * is enabled the main gateway reports needs_setup, so enabling it from the list opens the plugin
     * settings instead of flipping it on without a usable payment method. Returns false on any error
     * (e.g. store not connected), which also routes the merchant to setup.
     *
     * @return bool
     */
    public static function hasEnabledPaymentMethod(): bool
    {
        if (self::$hasEnabledMethodCache !== null) {
            return self::$hasEnabledMethodCache;
        }
        try {
            return self::$hasEnabledMethodCache = StoreContext::doWithStore(self::STORE_ID, static function (): bool {
                return ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled()->toArray() !== [];
            });
        } catch (\Throwable $e) {
            return self::$hasEnabledMethodCache = \false;
        }
    }
    /**
     * Whether the integration is switched on, i.e. the main gateway's WooCommerce "enabled" toggle
     * is on. Disabling the "Worldline" entry in the Payments list turns off every Worldline payment
     * option at checkout (Hosted Checkout and the per-method gateways) while the entry and its Manage
     * link stay in place.
     *
     * @return bool
     */
    public static function isMainGatewayEnabled(): bool
    {
        $settings = get_option('woocommerce_' . ModuleHelper::getMainGatewayId() . '_settings', []);
        return is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes';
    }
    /**
     * Admin-enabled methods (excluding the generic Hosted Checkout, which is the main
     * gateway), read from the core config so no cart is required.
     *
     * @return array<int, array{productId: PaymentProductId, label: string}>
     */
    private function enabledMethods(): array
    {
        if (self::$enabledMethodsCache !== null) {
            return self::$enabledMethodsCache;
        }
        try {
            $collection = StoreContext::doWithStore(self::STORE_ID, static function () {
                return ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled();
            });
        } catch (\Throwable $e) {
            return self::$enabledMethodsCache = [];
        }
        $methods = [];
        foreach ($collection->toArray() as $paymentMethod) {
            $productId = $paymentMethod->getProductId();
            if ($productId->equals(PaymentProductId::HOSTED_CHECKOUT)) {
                continue;
            }
            $name = $paymentMethod->getName()->getTranslationMessage(self::localeCode());
            $methods[] = ['productId' => $productId, 'label' => $name !== '' ? $name : MethodCatalog::label($productId)];
        }
        return self::$enabledMethodsCache = $methods;
    }
    /**
     * Pay-by-link settings (enabled, title, expirationTime) from the core, cached per request.
     *
     * @return array<string, mixed>
     */
    private function payByLinkSettings(): array
    {
        if (self::$payByLinkSettingsCache !== null) {
            return self::$payByLinkSettingsCache;
        }
        // Read only the Pay by Link settings: the full general settings need a connection (see
        // surchargeNotice()), and this runs whenever the gateways are listed.
        try {
            $settings = (array) StoreContext::doWithStore(self::STORE_ID, static function (): array {
                $payByLink = ServiceRegister::getService(GeneralSettingsService::class)->getPayByLinkSettings();
                return ['enabled' => $payByLink->isEnable(), 'title' => $payByLink->getTitle(), 'expirationTime' => $payByLink->getExpirationTime()->getDays()];
            });
        } catch (\Throwable $e) {
            $settings = [];
        }
        return self::$payByLinkSettingsCache = $settings;
    }
    /**
     * Customer-facing title configured for a payment method (the per-language "name"
     * field), resolved for the current locale; $fallback when none is found.
     *
     * @param PaymentProductId $productId
     * @param string $fallback
     *
     * @return string
     */
    public static function configuredTitle(PaymentProductId $productId, string $fallback): string
    {
        try {
            $title = StoreContext::doWithStore(self::STORE_ID, static function () use ($productId) {
                $collection = ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled();
                foreach ($collection->toArray() as $paymentMethod) {
                    if ($paymentMethod->getProductId()->equals((string) $productId)) {
                        return $paymentMethod->getName()->getTranslationMessage(self::localeCode());
                    }
                }
                return '';
            });
        } catch (\Throwable $e) {
            $title = '';
        }
        return is_string($title) && $title !== '' ? $title : $fallback;
    }
    /**
     * Localized "Vault title" configured for the grouped Credit Cards method, or an empty string
     * when none is set. Used to prefix the saved-card option label at checkout.
     *
     * @return string
     */
    public static function vaultTitle(): string
    {
        try {
            $title = StoreContext::doWithStore(self::STORE_ID, static function () {
                $collection = ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled();
                foreach ($collection->toArray() as $paymentMethod) {
                    if (!$paymentMethod->getProductId()->equals((string) PaymentProductId::cards())) {
                        continue;
                    }
                    $additional = $paymentMethod->getAdditionalData();
                    if ($additional instanceof CreditCard && $additional->getVaultTitles() !== null) {
                        return $additional->getVaultTitles()->getTranslationMessage(self::localeCode());
                    }
                    return '';
                }
                return '';
            });
        } catch (\Throwable $e) {
            $title = '';
        }
        return is_string($title) ? $title : '';
    }
    /**
     * Whether a token is one of this plugin's saved credit cards.
     *
     * @param \WC_Payment_Token|mixed $token
     *
     * @return bool
     */
    private static function ownsCardToken($token): bool
    {
        return $token instanceof \WC_Payment_Token_CC && ModuleHelper::ownsGateway((string) $token->get_gateway_id());
    }
    /**
     * Classic checkout: prefixes the saved-card option label with the configured Vault title,
     * e.g. "Saved credit card (WL): Mastercard ending in 0624 (expires 07/26)".
     *
     * @param string $html
     * @param \WC_Payment_Token $token
     * @param \WC_Payment_Gateway $gateway
     *
     * @return string
     */
    public function prefixVaultTitleClassic($html, $token, $gateway): string
    {
        if (!self::ownsCardToken($token)) {
            return (string) $html;
        }
        $vault = self::vaultTitle();
        if ($vault === '') {
            return (string) $html;
        }
        $display = $token->get_display_name();
        return str_replace('>' . esc_html($display) . '</label>', '>' . esc_html($vault . ': ' . $display) . '</label>', (string) $html);
    }
    /**
     * Block checkout: prefixes the saved-card brand (which the block renders as
     * "{brand} ending in {last4} (expires ..)") with the configured Vault title. The account
     * "Payment methods" table (same filter) is skipped so only checkout is affected.
     *
     * @param array<string, mixed> $item
     * @param \WC_Payment_Token $token
     *
     * @return array<string, mixed>
     */
    public function prefixVaultTitleBlock($item, $token): array
    {
        // The account "Payment methods" table uses this same filter; leave it unchanged.
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('payment-methods')) {
            return (array) $item;
        }
        if (!self::ownsCardToken($token) || !isset($item['method']['brand'])) {
            return (array) $item;
        }
        $vault = self::vaultTitle();
        if ($vault !== '') {
            $item['method']['brand'] = $vault . ': ' . $item['method']['brand'];
        }
        return $item;
    }
    /**
     * Product id whose configured button title applies to a checkout entry; card brands share the
     * grouped Cards config.
     *
     * @param PaymentProductId $productId
     *
     * @return string
     */
    private function buttonTitleProductIdFor(PaymentProductId $productId): string
    {
        return $productId->isCardBrand() ? PaymentProductId::CARDS : (string) $productId;
    }
    /**
     * Pay-for-order page: that page builds its button from the global woocommerce_pay_order_button_text
     * filter (default "Pay for order"), ignoring the per-gateway order_button_text used at checkout.
     * Apply the configured Payment button title when the order being paid uses one of our gateways.
     *
     * @param string $text
     *
     * @return string
     */
    public function payOrderButtonText($text): string
    {
        $order = OrderService::currentPayOrder();
        if ($order === null || !ModuleHelper::ownsGateway((string) $order->get_payment_method())) {
            return (string) $text;
        }
        $gateways = function_exists('WC') ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway = $gateways[(string) $order->get_payment_method()] ?? null;
        if ($gateway instanceof \WC_Payment_Gateway && !empty($gateway->order_button_text)) {
            return (string) $gateway->order_button_text;
        }
        return (string) $text;
    }
    /**
     * Two-letter code of the current WordPress locale, used to pick the configured
     * per-language method title.
     *
     * @return string
     */
    private static function localeCode(): string
    {
        $locale = function_exists('get_user_locale') ? (string) get_user_locale() : '';
        if ($locale === '' && function_exists('get_locale')) {
            $locale = (string) get_locale();
        }
        return $locale !== '' ? strtoupper(substr($locale, 0, 2)) : 'EN';
    }
}
