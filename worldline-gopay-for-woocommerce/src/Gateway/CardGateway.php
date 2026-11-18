<?php

namespace WOP\OnlinePayments\Gateway;

use WOP\OnlinePayments\Checkout\CardPaymentProcessor;
use WOP\OnlinePayments\Checkout\HostedCheckoutProcessor;
use WOP\OnlinePayments\Checkout\WooCommerceCartProvider;
use WOP\OnlinePayments\Core\Branding\Brand\ActiveBrandProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ConnectionMode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Repositories\PaymentMethodConfigRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Services\SavedCardsSetting;
defined('ABSPATH') || exit;
/**
 * The grouped Cards payment method; the admin-configured flow type decides how it pays.
 */
class CardGateway extends MethodGateway
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Brand-prefixed POST field carrying the tokenizer result from the checkout form.
     *
     * @return string
     */
    public static function tokenizationIdField(): string
    {
        return ModuleHelper::getModuleConfig()->getGatewayPrefix() . '_hosted_tokenization_id';
    }
    /**
     * Shows checkout fields (the card iframe) only in the iframe flow.
     *
     * @param PaymentProductId $productId
     * @param string $label
     */
    public function __construct(PaymentProductId $productId, string $label)
    {
        parent::__construct($productId, $label);
        $this->supports = ['products', 'refunds', 'tokenization'];
        $this->has_fields = self::isIframeCheckout() || $this->showsSavedCards();
    }
    /**
     * All card gateways (grouped and per-brand) use the grouped Cards button title.
     *
     * @return string
     */
    protected function buttonTitleProductId(): string
    {
        return PaymentProductId::CARDS;
    }
    /**
     * Renders the classic checkout card form. Saved cards show in both flows (a stored card pays
     * through the hosted checkout in the redirect flow); only the iframe flow adds the tokenizer form.
     */
    public function payment_fields(): void
    {
        if ($this->showsSavedCards()) {
            $this->saved_payment_methods();
        }
        if (!self::isIframeCheckout()) {
            parent::payment_fields();
            return;
        }
        $sessionUrl = rest_url(ModuleHelper::getRestNamespace() . '/card/tokenization-session');
        global $wp;
        $payOrderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if ($payOrderId > 0) {
            $sessionUrl = add_query_arg(['orderId' => $payOrderId, 'key' => isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''], $sessionUrl);
        }
        $product = $this->productId->equals(PaymentProductId::CARDS) ? '' : (string) $this->productId;
        ModuleHelper::renderTemplate('checkout/card-fields.php', ['gatewayId' => $this->id, 'sessionUrl' => $sessionUrl, 'product' => $product, 'tokenizationField' => self::tokenizationIdField(), 'deviceFields' => WooCommerceCartProvider::deviceFields()]);
    }
    /**
     * Not offered at checkout when grouping is disabled (the per-brand gateways are shown instead).
     *
     * @return bool
     */
    public function is_available(): bool
    {
        if ($this->productId->equals(PaymentProductId::CARDS) && !self::isGroupingEnabled()) {
            return \false;
        }
        return parent::is_available();
    }
    /**
     * Saved card mirrors are stored under the grouped cards gateway id; a per-brand gateway
     * pulls the grouped mirrors and keeps the ones for its own brand.
     *
     * @return \WC_Payment_Token[]
     */
    public function get_tokens(): array
    {
        if (count($this->tokens) > 0) {
            return $this->tokens;
        }
        if ($this->productId->equals(PaymentProductId::CARDS) || !is_user_logged_in() || !$this->supports('tokenization')) {
            return parent::get_tokens();
        }
        $cardsGatewayId = MethodCatalog::gatewayId(PaymentProductId::cards());
        foreach (\WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $cardsGatewayId) as $token) {
            if ((string) $token->get_meta('paymentProductId') === (string) $this->productId) {
                $token->set_gateway_id($this->id);
                $this->tokens[$token->get_id()] = $token;
            }
        }
        return $this->tokens;
    }
    /**
     * Pays via a saved card token, the submitted tokenization id (iframe flow) or the
     * hosted checkout page.
     *
     * @param int $order_id
     *
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $tokenizationId = isset($_POST[self::tokenizationIdField()]) ? sanitize_text_field(wp_unslash($_POST[self::tokenizationIdField()])) : '';
        $savedToken = $this->postedSavedToken();
        if ($savedToken !== null && !SavedCardsSetting::isEnabled()) {
            return $this->savedCardsDisabledFailure();
        }
        if ($savedToken !== null) {
            $order = wc_get_order($order_id);
            if (self::isIframeCheckout() && $tokenizationId !== '') {
                return (new CardPaymentProcessor())->process($order, $tokenizationId);
            }
            return (new HostedCheckoutProcessor())->process($order, $this->productId, $savedToken->get_token());
        }
        if (!self::isIframeCheckout()) {
            return parent::process_payment($order_id);
        }
        if ($tokenizationId === '') {
            // Classic contexts render no iframe - pay via hosted checkout; block fails loudly.
            if (!(defined('REST_REQUEST') && \REST_REQUEST)) {
                return (new HostedCheckoutProcessor())->process(wc_get_order($order_id), $this->productId);
            }
            if (function_exists('wc_add_notice')) {
                wc_add_notice(esc_html__('Please enter your card details.', 'worldline-gopay-for-woocommerce'), 'error');
            }
            return ['result' => 'failure'];
        }
        return (new CardPaymentProcessor())->process(wc_get_order($order_id), $tokenizationId);
    }
    /**
     * Whether checkout lists the customer's saved cards: logged in and saved cards enabled.
     *
     * @return bool
     */
    private function showsSavedCards(): bool
    {
        return $this->supports('tokenization') && is_user_logged_in() && SavedCardsSetting::isEnabled();
    }
    /**
     * Rejects paying with a saved card while saved cards are disabled.
     *
     * @return array{result: string}
     */
    private function savedCardsDisabledFailure(): array
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice(esc_html__('Saved cards are not available. Please pay with a new card.', 'worldline-gopay-for-woocommerce'), 'error');
        }
        return ['result' => 'failure'];
    }
    /**
     * Saved card chosen at checkout, validated against the current customer and this
     * plugin's gateways. Null when paying with a new card.
     *
     * @return \WC_Payment_Token|null
     */
    private function postedSavedToken(): ?\WC_Payment_Token
    {
        $field = 'wc-' . $this->id . '-payment-token';
        $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if ($value === '' || $value === 'new' || !class_exists(\WC_Payment_Tokens::class)) {
            return null;
        }
        $token = \WC_Payment_Tokens::get((int) $value);
        if (!$token instanceof \WC_Payment_Token || get_current_user_id() <= 0 || (int) $token->get_user_id() !== get_current_user_id() || !ModuleHelper::ownsGateway((string) $token->get_gateway_id())) {
            return null;
        }
        return $token;
    }
    /**
     * Whether checkout collects the card in the in-page tokenizer iframe: the iframe flow
     * is configured AND the tokenizer library is resolvable.
     *
     * @return bool
     */
    public static function isIframeCheckout(): bool
    {
        return self::isIframeFlow() && self::tokenizerSdkUrl() !== '';
    }
    /**
     * URL of the Worldline hosted-tokenization client library; empty when no brand endpoint is available.
     *
     * @return string
     */
    public static function tokenizerSdkUrl(): string
    {
        try {
            /** @var ActiveBrandProviderInterface $brandProvider */
            $brandProvider = ServiceRegister::getService(ActiveBrandProviderInterface::class);
            $brand = $brandProvider->getActiveBrand();
            $endpoint = StoreContext::doWithStore(self::STORE_ID, static function () use ($brand): string {
                $connection = ServiceRegister::getService(ActiveConnectionProvider::class)->get();
                $isLive = $connection !== null && $connection->getMode()->equals(ConnectionMode::live());
                return rtrim($isLive ? $brand->getLiveApiEndpoint() : $brand->getTestApiEndpoint(), '/');
            });
        } catch (\Throwable $e) {
            return '';
        }
        return $endpoint === '' ? '' : $endpoint . '/hostedtokenization/js/client/tokenizer.min.js';
    }
    /**
     * Whether cards show as one grouped checkout entry; when disabled, every card brand
     * is its own checkout method.
     *
     * @return bool
     */
    public static function isGroupingEnabled(): bool
    {
        try {
            return (bool) StoreContext::doWithStore(self::STORE_ID, static function () {
                return ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled()->isCardsGroupingEnabled();
            });
        } catch (\Throwable $e) {
            return \true;
        }
    }
    /**
     * Whether the Cards method is configured with the iframe (in-page tokenizer) flow.
     *
     * @return bool
     */
    public static function isIframeFlow(): bool
    {
        try {
            return (bool) StoreContext::doWithStore(self::STORE_ID, static function () {
                return ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getEnabled()->isCardsTokenizationEnabled();
            });
        } catch (\Throwable $e) {
            return \false;
        }
    }
}
