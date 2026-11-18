<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderPayment;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Refund\RefundReason;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Order-edit screen meta box showing payment/fraud information and inline Capture/Cancel controls.
 */
class OrderActionsMetaBox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_' . self::ajaxAction('capture'), [$this, 'handleCapture']);
        add_action('wp_ajax_' . self::ajaxAction('cancel'), [$this, 'handleCancel']);
        add_action('woocommerce_order_item_add_action_buttons', [$this, 'renderActionButtons']);
        add_action('admin_footer', [$this, 'renderActionPanels']);
    }
    /**
     * Full wp_ajax action name for a merchant action ("capture"/"cancel"), brand-prefixed at runtime.
     *
     * @param string $action
     *
     * @return string
     */
    private static function ajaxAction(string $action): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_' . $action;
    }
    /**
     * Nonce action for the capture/cancel AJAX calls; brand-prefixed at runtime.
     *
     * @return string
     */
    private static function nonceAction(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_order_action';
    }
    /**
     * @param string $postType
     * @param mixed $post The WC_Order (HPOS) or WP_Post (legacy) for the current order screen.
     */
    public function addMetaBox($postType = '', $post = null): void
    {
        $order = $this->orderFrom($post);
        if (!$order instanceof \WC_Order || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box(ModuleHelper::getModuleConfig()->getModuleName() . '-actions', sprintf(
            /* translators: %s: brand name. */
            esc_html__('%s payment', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        ), [$this, 'render'], $screen, 'normal', 'high');
    }
    /**
     * @param mixed $object The WC_Order (HPOS) or WP_Post (legacy) for the current order screen.
     *
     * @return \WC_Order|null
     */
    private function orderFrom($object): ?\WC_Order
    {
        if ($object instanceof \WC_Order) {
            return $object;
        }
        if ($object instanceof \WP_Post && $object->post_type === 'shop_order') {
            return OrderService::get((int) $object->ID);
        }
        return null;
    }
    /**
     * @param mixed $object The WC_Order (HPOS) or WP_Post (legacy) for the current order screen.
     */
    public function render($object): void
    {
        $order = $this->orderFrom($object);
        if (!$order instanceof \WC_Order || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            echo '<p class="description">' . sprintf(
                /* translators: %s: brand name. */
                esc_html__('Not a %s payment.', 'worldline-gopay-for-woocommerce'),
                esc_html(ModuleHelper::getModuleConfig()->getName())
            ) . '</p>';
            return;
        }
        $details = self::details($order);
        $url = MerchantActions::transactionUrl($order);
        if ($details === null) {
            echo '<p class="description">' . sprintf(
                /* translators: %s: brand name. */
                esc_html__('%s payment details are currently unavailable.', 'worldline-gopay-for-woocommerce'),
                esc_html(ModuleHelper::getModuleConfig()->getName())
            ) . '</p>';
            $this->renderOpenLink($url);
            return;
        }
        $payments = $details->getPayments();
        $showHeaders = count($payments) > 1;
        echo '<div class="wlop-meta">';
        foreach ($payments as $payment) {
            $this->renderPaymentSection($payment, $showHeaders);
        }
        echo '</div>';
        $this->renderOpenLink($url);
        $this->renderSummary($details);
    }
    /**
     * The "Payment information" / "Fraud information" block for a single payment.
     */
    private function renderPaymentSection(OrderPayment $payment, bool $showHeader): void
    {
        $currency = $payment->getAmount()->getCurrency()->getIsoCode();
        $method = trim((string) $payment->getPaymentMethodName());
        $amount = wc_price($payment->getAmount()->getPriceInCurrencyUnits(), ['currency' => $currency]);
        $card = self::cardInfoFor($payment);
        $methodLabel = $method;
        if ($card['walletBrand'] !== '' && $methodLabel !== '' && stripos($methodLabel, $card['walletBrand']) === \false) {
            $methodLabel .= ' (' . $card['walletBrand'] . ')';
        }
        $maskedCard = self::isCardBearing($payment) ? self::maskedCardDisplay($card) : '';
        echo '<section class="wlop-payment">';
        if ($showHeader) {
            echo '<div class="wlop-payment-head"><span class="wlop-payment-title">' . esc_html($methodLabel !== '' ? $methodLabel : __('Payment', 'worldline-gopay-for-woocommerce')) . '</span><span>' . wp_kses_post($amount) . '</span></div>';
        }
        echo '<div class="wlop-wrapper">';
        echo '<div class="wlop-col"><h4>' . esc_html__('Payment information', 'worldline-gopay-for-woocommerce') . '</h4>';
        $this->infoRow(esc_html__('Payment method', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName() . ($methodLabel !== '' ? ' [' . $methodLabel . ']' : ''));
        $this->infoRow(esc_html__('Payment ID', 'worldline-gopay-for-woocommerce'), (string) $payment->getId());
        if ($maskedCard !== '') {
            $this->infoRow(esc_html__('Card', 'worldline-gopay-for-woocommerce'), $maskedCard);
        }
        $this->infoRow(esc_html__('Amount', 'worldline-gopay-for-woocommerce'), $amount, \true);
        echo '</div>';
        echo '<div class="wlop-col"><h4>' . esc_html__('Fraud information', 'worldline-gopay-for-woocommerce') . '</h4>';
        $this->infoRow(esc_html__('Fraud result', 'worldline-gopay-for-woocommerce'), ucfirst((string) $payment->getFraudResult()));
        $this->infoRow(esc_html__('3DS Liability', 'worldline-gopay-for-woocommerce'), ucfirst((string) $payment->getLiability()));
        $this->infoRow(esc_html__('Exemption', 'worldline-gopay-for-woocommerce'), ucfirst((string) $payment->getExemptionType()));
        echo '</div>';
        echo '</div></section>';
    }
    private function infoRow(string $label, string $value, bool $valueIsHtml = \false, string $rowClass = ''): void
    {
        $class = 'wlop-row' . ($rowClass !== '' ? ' ' . $rowClass : '');
        echo '<div class="' . esc_attr($class) . '"><span class="wlop-label">' . esc_html($label) . '</span><span class="wlop-val">' . ($valueIsHtml ? wp_kses_post($value) : esc_html($value)) . '</span></div>';
    }
    /**
     * Whether a sub-payment's product actually carries a card (a card scheme or a wallet with an
     * underlying card). Mealvouchers and other non-card products return false, so the residual card
     * the core copies onto every split sub-payment is not shown against them.
     *
     * @param OrderPayment $payment
     *
     * @return bool
     */
    private static function isCardBearing(OrderPayment $payment): bool
    {
        $productId = method_exists($payment, 'getPaymentMethodId') ? (string) $payment->getPaymentMethodId() : '';
        if ($productId === '') {
            return \true;
        }
        try {
            $product = PaymentProductId::parse($productId);
        } catch (\Throwable $e) {
            return \true;
        }
        return $product->isCardType() || $product->isMobileType();
    }
    /**
     * Masked card details for a payment, preferring the core OrderPayment model (it now carries the
     * BIN and masked card number) and falling back to the raw SDK only when the core does not supply
     * them - older core builds, or wallet/mobile payments whose underlying card the core does not map.
     *
     * @param OrderPayment $payment
     *
     * @return array{bin: string, last4: string, masked: string, walletBrand: string}
     */
    private static function cardInfoFor(OrderPayment $payment): array
    {
        $bin = method_exists($payment, 'getBin') ? (string) $payment->getBin() : '';
        $masked = method_exists($payment, 'getCardNumber') ? (string) $payment->getCardNumber() : '';
        if ($masked !== '') {
            $digits = preg_replace('/\D/', '', $masked) ?? '';
            $last4 = strlen($digits) >= 4 ? substr($digits, -4) : '';
            return ['bin' => $bin, 'last4' => $last4, 'masked' => $masked, 'walletBrand' => ''];
        }
        // Core carried no card number (older core, or a wallet payment): use the raw SDK, which also
        // resolves the wallet brand and the underlying mobile card.
        return self::cardInfo((string) $payment->getId());
    }
    /** Per-request cache of resolved card info, keyed by payment id. */
    private static array $cardInfoCache = [];
    /**
     * Masked card details and, for wallet payments, the underlying card brand for one Worldline
     * payment. The core order-details model does not carry these, so they are read from the raw SDK
     * payment output. Cached per request and resilient: returns empty values when unavailable.
     *
     * @param string $paymentId
     *
     * @return array{bin: string, last4: string, masked: string, walletBrand: string}
     */
    private static function cardInfo(string $paymentId): array
    {
        $empty = ['bin' => '', 'last4' => '', 'masked' => '', 'walletBrand' => ''];
        if ($paymentId === '') {
            return $empty;
        }
        if (array_key_exists($paymentId, self::$cardInfoCache)) {
            return self::$cardInfoCache[$paymentId];
        }
        try {
            $result = StoreContext::doWithStore('1', static function () use ($paymentId, $empty): array {
                $output = ServiceRegister::getService(MerchantClientFactory::class)->get()->payments()->getPaymentDetails($paymentId)->getPaymentOutput();
                if ($output === null) {
                    return $empty;
                }
                $bin = '';
                $masked = '';
                $cardOutput = $output->getCardPaymentMethodSpecificOutput();
                if ($cardOutput !== null && $cardOutput->getCard() !== null) {
                    $bin = (string) $cardOutput->getCard()->getBin();
                    $masked = (string) $cardOutput->getCard()->getCardNumber();
                }
                $walletBrand = '';
                $mobileOutput = $output->getMobilePaymentMethodSpecificOutput();
                if ($mobileOutput !== null) {
                    $walletBrand = OrderService::brandLabel((string) $mobileOutput->getNetwork());
                    if (method_exists($mobileOutput, 'getCard') && $mobileOutput->getCard() !== null) {
                        $masked = (string) $mobileOutput->getCard()->getCardNumber();
                    }
                }
                $digits = preg_replace('/\D/', '', $masked) ?? '';
                $last4 = strlen($digits) >= 4 ? substr($digits, -4) : '';
                return ['bin' => $bin, 'last4' => $last4, 'masked' => $masked, 'walletBrand' => $walletBrand];
            });
        } catch (\Throwable $e) {
            $result = $empty;
        }
        return self::$cardInfoCache[$paymentId] = $result;
    }
    /**
     * Masked-number display ("411111 •••• 1111", or "•••• 1111" / the raw masked value as fallback),
     * or '' when no card number is available.
     *
     * @param array{bin: string, last4: string, masked: string, walletBrand: string} $card
     *
     * @return string
     */
    private static function maskedCardDisplay(array $card): string
    {
        if ($card['bin'] !== '' && $card['last4'] !== '') {
            return $card['bin'] . ' •••• ' . $card['last4'];
        }
        if ($card['last4'] !== '') {
            return '•••• ' . $card['last4'];
        }
        return (string) $card['masked'];
    }
    /**
     * Overall payment status: REFUNDED once refunded reaches captured, PARTIALLY REFUNDED below it,
     * otherwise the raw Worldline status.
     */
    private function overallStatus(OrderDetails $details): string
    {
        $payments = $details->getPayments();
        $first = reset($payments);
        $raw = $first instanceof OrderPayment ? (string) $first->getStatus() : '';
        $refunded = $details->getRefund()->getDone()->getValue();
        if ($refunded <= 0) {
            return $raw;
        }
        $captured = $details->getCapture()->getDone()->getValue();
        return $captured > 0 && $refunded >= $captured ? esc_html__('REFUNDED', 'worldline-gopay-for-woocommerce') : esc_html__('PARTIALLY REFUNDED', 'worldline-gopay-for-woocommerce');
    }
    /**
     * Overall status and the captured/refunded/cancelled summary shown in the meta box.
     */
    private function renderSummary(OrderDetails $details): void
    {
        $currency = $details->getAmount()->getCurrency()->getIsoCode();
        $money = static function (float $value) use ($currency): string {
            return wc_price($value, ['currency' => $currency]);
        };
        echo '<div class="wlop-actions">';
        $this->infoRow(esc_html__('Status', 'worldline-gopay-for-woocommerce'), $this->overallStatus($details));
        $authorizedTotal = $details->getAmount()->getPriceInCurrencyUnits();
        $captured = $details->getCapture()->getDone()->getPriceInCurrencyUnits();
        $refunded = $details->getRefund()->getDone()->getPriceInCurrencyUnits();
        $cancelled = $details->getCancel()->getDone()->getPriceInCurrencyUnits();
        $authorizedRemaining = max(0.0, $authorizedTotal - $captured - $cancelled);
        $capturedRemaining = max(0.0, $captured - $refunded);
        $total = $authorizedRemaining + $capturedRemaining + $refunded + $cancelled;
        $this->infoRow(esc_html__('Authorized', 'worldline-gopay-for-woocommerce'), $money($authorizedRemaining), \true);
        $this->infoRow(esc_html__('Captured', 'worldline-gopay-for-woocommerce'), $money($capturedRemaining), \true);
        $this->infoRow(esc_html__('Refunded', 'worldline-gopay-for-woocommerce'), $money($refunded), \true);
        if ($details->getCancel()->getDone()->getValue() > 0) {
            $this->infoRow(esc_html__('Cancelled', 'worldline-gopay-for-woocommerce'), $money($cancelled), \true);
        }
        $this->infoRow(esc_html__('Total', 'worldline-gopay-for-woocommerce'), $money($total), \true, 'wlop-row-total');
        echo '</div>';
    }
    /**
     * Per-request cache of the live order details, keyed by order id.
     *
     * @var array<int, OrderDetails|null>
     */
    private static $detailsCache = [];
    private static function details(\WC_Order $order): ?OrderDetails
    {
        $id = (int) $order->get_id();
        if (!array_key_exists($id, self::$detailsCache)) {
            self::$detailsCache[$id] = MerchantActions::details($order);
        }
        return self::$detailsCache[$id];
    }
    /**
     * @param OrderDetails $details
     *
     * @return array{capture: bool, cancel: bool}
     */
    private static function availability(OrderDetails $details): array
    {
        return ['capture' => $details->getCapture()->isPossible() && $details->getCapture()->getAvailable()->getValue() > 0, 'cancel' => $details->getCancel()->isPossible() && $details->getCancel()->getAvailable()->getValue() > 0];
    }
    /**
     * Renders the inline Capture/Cancel trigger buttons into the order-items action row.
     *
     * @param mixed $order
     */
    public function renderActionButtons($order): void
    {
        if (!$order instanceof \WC_Order || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        $details = self::details($order);
        if ($details === null) {
            return;
        }
        $can = self::availability($details);
        if ($can['capture']) {
            echo '<button type="button" class="button button-secondary wlop-do-capture">' . esc_html__('Capture', 'worldline-gopay-for-woocommerce') . '</button> ';
        }
        if ($can['cancel']) {
            echo '<button type="button" class="button button-secondary wlop-do-cancel">' . esc_html__('Cancel', 'worldline-gopay-for-woocommerce') . '</button> ';
        }
    }
    /**
     * Outputs the hidden Capture/Cancel panels into the admin footer; the script relocates them
     * into the order-data area next to the native refund panel.
     */
    public function renderActionPanels(): void
    {
        $order = $this->currentScreenOrder();
        if ($order === null || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        $details = self::details($order);
        if ($details === null) {
            return;
        }
        $can = self::availability($details);
        $currency = $details->getAmount()->getCurrency()->getIsoCode();
        $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : $currency;
        $orderId = (int) $order->get_id();
        $nonce = wp_create_nonce(self::nonceAction());
        if ($can['capture']) {
            $this->renderPanel('capture', $orderId, $nonce, $currency, $symbol, esc_html__('Capture', 'worldline-gopay-for-woocommerce'), [esc_html__('Amount already captured:', 'worldline-gopay-for-woocommerce') => $details->getCapture()->getDone(), esc_html__('Total available to capture:', 'worldline-gopay-for-woocommerce') => $details->getCapture()->getAvailable()], $details->getCapture()->getAvailable()->getPriceInCurrencyUnits());
        }
        if ($can['cancel']) {
            $this->renderPanel('cancel', $orderId, $nonce, $currency, $symbol, esc_html__('Cancel', 'worldline-gopay-for-woocommerce'), [esc_html__('Amount already cancelled:', 'worldline-gopay-for-woocommerce') => $details->getCancel()->getDone(), esc_html__('Total available to cancel:', 'worldline-gopay-for-woocommerce') => $details->getCancel()->getAvailable()], $details->getCancel()->getAvailable()->getPriceInCurrencyUnits());
        }
    }
    /**
     * @param string $action "capture" or "cancel"
     * @param int $orderId
     * @param string $nonce
     * @param string $currency
     * @param string $symbol
     * @param string $label
     * @param array<string, mixed> $rows label => core Amount value object
     * @param float $available
     */
    private function renderPanel(string $action, int $orderId, string $nonce, string $currency, string $symbol, string $label, array $rows, float $available): void
    {
        $money = static function ($amount) use ($currency): string {
            return wc_price($amount->getPriceInCurrencyUnits(), ['currency' => $currency]);
        };
        ?>
        <div id="wlop-<?php 
        echo esc_attr($action);
        ?>-panel" class="wc-order-data-row wc-order-data-row-toggle wlop-action-panel" style="display:none;">
            <table class="wc-order-totals">
                <?php 
        foreach ($rows as $rowLabel => $amount) {
            ?>
                    <tr>
                        <td class="label"><?php 
            echo esc_html($rowLabel);
            ?></td>
                        <td class="total"><?php 
            echo wp_kses_post($money($amount));
            ?></td>
                    </tr>
                <?php 
        }
        ?>
                <tr>
                    <td class="label">
                        <label for="wlop-<?php 
        echo esc_attr($action);
        ?>-amount"><?php 
        /* translators: %s: action label (Capture/Cancel) */
        echo esc_html(sprintf(__('%s amount:', 'worldline-gopay-for-woocommerce'), $label));
        ?></label>
                    </td>
                    <td class="total">
                        <input type="text" id="wlop-<?php 
        echo esc_attr($action);
        ?>-amount" class="wlop-panel-amount wc_input_price"
                               inputmode="decimal"
                               data-max="<?php 
        echo esc_attr((string) $available);
        ?>"
                               data-err-positive="<?php 
        echo esc_attr__('Enter an amount greater than 0.', 'worldline-gopay-for-woocommerce');
        ?>"
                               data-err-max="<?php 
        /* translators: %s: maximum amount with currency symbol */
        echo esc_attr(sprintf(__('The amount cannot exceed %s.', 'worldline-gopay-for-woocommerce'), number_format($available, 2) . ' ' . $symbol));
        ?>"
                               value="0" style="width:120px;" />
                    </td>
                </tr>
            </table>
            <div class="wlop-panel-footer">
                <button type="button" class="button wlop-panel-close"><?php 
        esc_html_e('Cancel', 'worldline-gopay-for-woocommerce');
        ?></button>
                <button type="button" class="button button-primary wlop-panel-submit"
                        data-ajax-action="<?php 
        echo esc_attr(self::ajaxAction($action));
        ?>"
                        data-order="<?php 
        echo esc_attr((string) $orderId);
        ?>"
                        data-nonce="<?php 
        echo esc_attr($nonce);
        ?>">
                    <?php 
        echo esc_html($label);
        ?> <span class="wlop-panel-total"><?php 
        echo esc_html(number_format(0, 2));
        ?></span> <?php 
        echo esc_html($symbol);
        ?>
                </button>
            </div>
            <div class="wlop-panel-error" style="display:none;"></div>
        </div>
        <?php 
    }
    /**
     * Resolves the order for the current admin order-edit screen (HPOS `?id=` or legacy `?post=`),
     * or null when not on that screen.
     */
    private function currentScreenOrder(): ?\WC_Order
    {
        if (!function_exists('get_current_screen')) {
            return null;
        }
        $screen = get_current_screen();
        $orderScreens = [function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : '', 'shop_order'];
        if ($screen === null || !in_array($screen->id, $orderScreens, \true)) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen/order resolution; the actions themselves are nonce-gated.
        $id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        return $id > 0 ? OrderService::get($id) : null;
    }
    private function renderOpenLink(?string $url): void
    {
        if ($url === null) {
            return;
        }
        echo '<p style="margin:.6em 0;"><a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">' . sprintf(
            /* translators: %s: brand name. */
            esc_html__('Open in %s', 'worldline-gopay-for-woocommerce'),
            esc_html(ModuleHelper::getModuleConfig()->getName())
        ) . '</a></p>';
    }
    public function enqueueAssets($hook): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        $orderScreens = [function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : '', 'shop_order'];
        if ($screen === null || !in_array($screen->id, $orderScreens, \true)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        $orderId = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        $order = $orderId > 0 ? OrderService::get($orderId) : null;
        if (!$order instanceof \WC_Order || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        $version = ModuleHelper::getModuleConfig()->getVersion();
        wp_enqueue_style(ModuleHelper::moduleKey('-order-actions'), ModuleHelper::getAssetUrl('admin/order-actions.css'), [], $version);
        wp_enqueue_script(ModuleHelper::moduleKey('-order-actions'), ModuleHelper::getAssetUrl('admin/order-actions.js'), ['jquery'], $version, \true);
        $details = self::details($order);
        wp_localize_script(ModuleHelper::moduleKey('-order-actions'), 'wlopRefund', ['split' => $details !== null && count($details->getPayments()) > 1, 'amount' => $details !== null ? $details->getRefund()->getAvailable()->getPriceInCurrencyUnits() : 0, 'label' => esc_html__('Refund full amount', 'worldline-gopay-for-woocommerce'), 'wero' => $details !== null && MerchantActions::isWero($details), 'reasonField' => MerchantActions::refundReasonField(), 'reasons' => RefundReason::values(), 'reasonLabel' => esc_html__('Reason for refund (Wero)', 'worldline-gopay-for-woocommerce'), 'reasonTooltip' => esc_html__('Required refund reason sent to Wero', 'worldline-gopay-for-woocommerce'), 'reasonNote' => esc_html__('Note: this order was paid with Wero. The option below applies only to Wero payments.', 'worldline-gopay-for-woocommerce')]);
        // Authoritative per-order-item amounts (post-discount line total, tax, quantity) keyed by
        // order_item_id. The Capture/Cancel panel reads these instead of scraping the rendered
        // order-items table: a discounted line renders both the struck-through subtotal and the
        // discounted total in the same cell, so the cell text parses to a wrong, concatenated
        // amount. get_total() is the amount actually charged for the line (after discounts).
        $lineItems = [];
        foreach ($order->get_items(['line_item', 'fee', 'shipping']) as $itemId => $item) {
            $qty = method_exists($item, 'get_quantity') && (int) $item->get_quantity() > 0 ? (int) $item->get_quantity() : 1;
            $lineItems[(string) $itemId] = ['qty' => $qty, 'total' => (float) $item->get_total(), 'tax' => (float) $item->get_total_tax()];
        }
        wp_localize_script(ModuleHelper::moduleKey('-order-actions'), 'wlopItems', $lineItems);
        // A Worldline order with a created payment is locked (see OrderEditGuard): block the
        // address-edit UI too and replace WooCommerce's misleading "set status to Pending payment"
        // notice. Pay by Link orders stay editable until paid, so they are not locked.
        wp_localize_script(ModuleHelper::moduleKey('-order-actions'), 'wlopEditLock', ['locked' => $order->get_payment_method() !== PayByLinkGateway::id(), 'message' => sprintf(
            /* translators: %s: plugin/brand name */
            esc_html__('This order can no longer be edited because it has a %s payment.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        )]);
    }
    public function handleCapture(): void
    {
        $this->handle('capture');
    }
    public function handleCancel(): void
    {
        $this->handle('cancel');
    }
    private function handle(string $action): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer(self::nonceAction(), 'nonce', \false)) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to perform this action.', 'worldline-gopay-for-woocommerce')], 403);
        }
        $order = OrderService::get(absint($_POST['order'] ?? 0));
        if (!$order instanceof \WC_Order) {
            wp_send_json_error(['message' => esc_html__('Order not found.', 'worldline-gopay-for-woocommerce')]);
        }
        $raw = isset($_POST['amount']) ? sanitize_text_field(wp_unslash($_POST['amount'])) : '';
        $amount = $raw !== '' ? (float) str_replace(',', '.', $raw) : null;
        $result = $action === 'capture' ? MerchantActions::capture($order, $amount) : MerchantActions::cancel($order, $amount);
        if (!empty($result['success'])) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message'] ?? esc_html__('Action failed.', 'worldline-gopay-for-woocommerce')]);
    }
}
