<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\ConnectionAPI\Request\ConnectionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\LogSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\PaymentSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\WebhookSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\PaymentAPI\Request\PaymentMethodRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection\Proxies\ConnectionProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ConnectionDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Repositories\ConnectionConfigRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\WebhookSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Services\Integration\ShopPaymentService;
use WOP\OnlinePayments\Services\Integration\StoreService;
use WOP\OnlinePayments\Services\PaymentButtonTitleSetting;
use WOP\OnlinePayments\Services\SavedCardsSetting;
use WOP\OnlinePayments\Services\SoftDescriptorSetting;
defined('ABSPATH') || exit;
/**
 * Copies the previous (2.x) plugin's connection, general settings and payment-method configuration
 * into this plugin's configuration via the AdminAPI. 2.x data is only read.
 */
class ConfigMigration
{
    /** Single store. */
    private const STORE_ID = '1';
    /** The previous plugin's default "Hosted checkout page template" (used when it was never saved). */
    private const LEGACY_DEFAULT_HOSTED_CHECKOUT_TEMPLATE = 'SimplifiedCustomPaymentPage';
    /** Highest Low-Value exemption limit (EUR); the previous plugin accepted up to 100 for every type. */
    private const LOW_VALUE_MAX_LIMIT = 30;
    private const PRODUCT_HOSTED_CHECKOUT = 'hosted_checkout';
    private const PRODUCT_CARDS = 'cards';
    private const PRODUCT_BANK_TRANSFER = '5408';
    private const PRODUCT_SEPA = '771';
    private const PRODUCT_GOOGLE_PAY = '320';
    private const PRODUCT_SOFINCO = '5131';
    private const PRODUCT_LINXO = '5003';
    /** Old per-method gateway suffix to core PaymentProductId, for the redirect methods. */
    private const REDIRECT_PRODUCTS = ['google-pay' => self::PRODUCT_GOOGLE_PAY, 'apple-pay' => '302', 'bank-transfer' => self::PRODUCT_BANK_TRANSFER, 'ideal' => '809', 'paypal' => '840', 'sepa-direct-debit' => self::PRODUCT_SEPA, 'postfinance' => '3203', 'twint' => '5407', 'mealvouchers' => '5402', 'cvco' => '5403', 'illicado' => '3112', 'eps' => '5406', 'przelewy24' => '3124', 'klarna-pay-now' => '3301', 'klarna-pay-later' => '3302', 'klarna-financing' => '3303', 'klarna-bank-transfer' => '3304', 'klarna-direct-debit' => '3305', 'klarna-pay-with-klarna' => '3306'];
    private LegacyConfigReader $reader;
    /**
     * @param LegacyConfigReader|null $reader
     */
    public function __construct(?LegacyConfigReader $reader = null)
    {
        $this->reader = $reader ?? new LegacyConfigReader();
    }
    /**
     * Runs the migration. No-op when there is no previous configuration on the site.
     */
    public function run(): void
    {
        if (!$this->reader->hasLegacyConfig()) {
            return;
        }
        $this->migrate(MigrationNotice::AREA_CONNECTION, [$this, 'migrateConnection']);
        $this->migrate(MigrationNotice::AREA_GENERAL_SETTINGS, [$this, 'migrateGeneralSettings']);
        $this->migrate(MigrationNotice::AREA_WEBHOOK_SETTINGS, [$this, 'migrateWebhookSettings']);
        $this->migrate(MigrationNotice::AREA_HOSTED_CHECKOUT, [$this, 'migrateHostedCheckout']);
        $this->migrate(MigrationNotice::AREA_CARDS, [$this, 'migrateCards']);
        $this->migrate(MigrationNotice::AREA_REDIRECT_METHODS, [$this, 'migrateRedirectMethods']);
        $this->migrate(MigrationNotice::AREA_SOFT_DESCRIPTOR, [$this, 'migrateSoftDescriptor']);
        $this->migrate(MigrationNotice::AREA_SAVED_CARDS_SETTING, [$this, 'migrateSavedCards']);
    }
    /**
     * Runs one migration area, logging and swallowing any failure so the rest proceed, and records
     * the outcome for the migration notice. An area that returns false had nothing to copy.
     *
     * @param string $area MigrationNotice area key.
     * @param callable $fn
     */
    private function migrate(string $area, callable $fn): void
    {
        try {
            if ($fn() !== \false) {
                MigrationNotice::recordArea($area, \true);
            }
        } catch (\Throwable $e) {
            Logger::logError('Config migration failed for ' . $area . ': ' . $e->getMessage(), 'Migration');
            MigrationNotice::recordArea($area, \false);
        }
    }
    /**
     * Migrates the connection credentials and mode, then syncs gateway availability.
     *
     * Does not go through AdminAPI connect(), which also rejects invalid webhook credentials: the
     * connection is migrated whenever the API credentials are valid. Invalid webhook credentials are
     * reported by the integration state check on the next admin page load.
     */
    private function migrateConnection(): void
    {
        // 2.5.26+ stores the PSPID per environment; older 2.x only has the shared pspid.
        $legacyPspid = (string) $this->reader->main('pspid');
        $testPspid = (string) $this->reader->main('test_pspid', $legacyPspid);
        $livePspid = (string) $this->reader->main('live_pspid', $legacyPspid);
        $mode = $this->reader->mainBool('live_mode') ? 'live' : 'test';
        $request = new ConnectionRequest($mode, $testPspid, (string) $this->reader->main('test_api_key'), (string) $this->reader->main('test_api_secret'), (string) $this->reader->main('test_webhook_id'), (string) $this->reader->main('test_webhook_secret_key'), $livePspid, (string) $this->reader->main('live_api_key'), (string) $this->reader->main('live_api_secret'), (string) $this->reader->main('live_webhook_id'), (string) $this->reader->main('live_webhook_secret_key'));
        StoreContext::doWithStore(self::STORE_ID, static function () use ($request): void {
            /** @var ConnectionDetails $details */
            $details = $request->transformToDomainModel();
            /** @var ConnectionProxyInterface $proxy */
            $proxy = ServiceRegister::getService(ConnectionProxyInterface::class);
            if (!$proxy->isConnectionValid($details)) {
                throw new \RuntimeException('the API credentials are not valid, the connection was not migrated.');
            }
            ServiceRegister::getService(ConnectionConfigRepositoryInterface::class)->saveConnection($details);
            (new ShopPaymentService())->syncGatewayAvailability();
        });
    }
    /**
     * Migrates the general settings, preserving every field that has no old source.
     */
    private function migrateGeneralSettings(): void
    {
        $current = $this->currentGeneralSettings();
        $payment = $current['paymentSettings'] ?? [];
        $statuses = $this->statusMapping($payment);
        $log = $current['logSettings'] ?? [];
        $paymentRequest = new PaymentSettingsRequest($this->paymentAction((string) $this->reader->main('authorization_mode', 'authorization')), $this->captureMinutes((string) $this->reader->main('capture_mode', 'manual')), (int) ($payment['numberOfPaymentAttempts'] ?? 1) ?: 1, $this->reader->mainBool('surcharge_enable'), $statuses['captured'], $statuses['error'], $statuses['pending'], $statuses['authorized'], $statuses['cancelled'], $statuses['refunded'], $this->hostedCheckoutTemplate(), $statuses['partiallyRefunded'], $this->reader->mainBool('send_shopping_cart', \true), (bool) ($payment['displayConfirmationPage'] ?? \true));
        AdminAPI::get()->generalSettings(self::STORE_ID)->savePaymentSettings($paymentRequest);
        $logDays = (int) ($log['logDays'] ?? 0);
        if ($logDays < 1 || $logDays > 14) {
            $logDays = 14;
        }
        $logRequest = new LogSettingsRequest($this->reader->mainBool('debug_logging'), $logDays);
        AdminAPI::get()->generalSettings(self::STORE_ID)->saveLogSettings($logRequest);
    }
    /**
     * Migrates the up-to-4 additional webhook URLs from the previous plugin. They are migrated
     * regardless of the old plugin's webhook mode (automatic/manual) - this plugin always sends them in
     * the payment feedbacks. Legacy values that are not valid https URLs are skipped so a single bad
     * entry can't drop the rest.
     *
     * @return bool Whether there was a URL to migrate.
     */
    private function migrateWebhookSettings(): bool
    {
        $urls = [];
        for ($i = 1; $i <= 4; $i++) {
            $url = trim((string) $this->reader->main('additional_webhook_url_' . $i));
            if ($url !== '' && WebhookSettings::isValidWebhookUrl($url)) {
                $urls[] = $url;
            }
        }
        if (empty($urls)) {
            return \false;
        }
        AdminAPI::get()->generalSettings(self::STORE_ID)->saveWebhookSettings(new WebhookSettingsRequest($urls));
        return \true;
    }
    /**
     * Migrates the hosted checkout (redirect to Worldline) payment method.
     */
    private function migrateHostedCheckout(): void
    {
        $current = $this->currentMethod(self::PRODUCT_HOSTED_CHECKOUT);
        $threeDs = $this->threeDsFromMain();
        $request = new PaymentMethodRequest(self::PRODUCT_HOSTED_CHECKOUT, $this->nameMap($current['name'] ?? [], (string) $this->reader->main('primary_gateway_title')), $this->reader->mainBool('enabled'), $this->hostedCheckoutTemplate(), $this->paymentAction((string) $this->reader->main('authorization_mode', 'authorization')), [], $this->migratedHostedCheckoutLogo(), $this->reader->mainBool('card_brands_display'), null, null, null, null, null, null, $threeDs['enable3ds'], $threeDs['enforce'], $threeDs['exemptionEnabled'], $threeDs['exemptionType'], $threeDs['exemptionLimit'], null, null);
        $this->saveMethod($request, self::PRODUCT_HOSTED_CHECKOUT, $this->reader->mainBool('enabled'));
        // The previous plugin's single "Payment button title" applied to hosted checkout only; the
        // new plugin stores button titles per method, so migrate it onto the hosted-checkout method.
        $buttonTitle = (string) $this->reader->main('payment_button_title');
        if ($buttonTitle !== '') {
            PaymentButtonTitleSetting::set(self::PRODUCT_HOSTED_CHECKOUT, $buttonTitle);
        }
    }
    /**
     * The custom Hosted Checkout logo to migrate, or '' to keep this plugin's bundled default.
     *
     * The previous plugin stored its OWN bundled default logo (a URL inside its `/modules/` assets) in
     * `logo_url` even when the merchant never uploaded one. Carrying that over would point this plugin
     * at a path that no longer exists and hide our own bundled logo, so only a genuinely uploaded custom
     * logo (which lives under wp-content/uploads, never inside the plugin's bundled assets) is migrated.
     *
     * @return string
     */
    private function migratedHostedCheckoutLogo(): string
    {
        $logoUrl = trim((string) $this->reader->main('logo_url'));
        if ($logoUrl === '' || strpos($logoUrl, '/modules/') !== \false) {
            return '';
        }
        return $logoUrl;
    }
    /**
     * Migrates the credit cards (hosted tokenization) payment method.
     */
    private function migrateCards(): void
    {
        $cardsSuffix = LegacyConfigReader::CARDS_SUFFIX;
        $current = $this->currentMethod(self::PRODUCT_CARDS);
        $threeDs = $this->threeDsFromMain();
        $enabled = $this->reader->methodBool($cardsSuffix, 'enabled');
        $name = $this->nameMap($current['name'] ?? [], (string) $this->reader->method($cardsSuffix, 'title'));
        $vaultSource = $current['additionalData']['vaultTitleCollection'] ?? [];
        $vaultTitles = $vaultSource !== [] ? $this->nameMap($vaultSource, '') : $name;
        $request = new PaymentMethodRequest(self::PRODUCT_CARDS, $name, $enabled, (string) $this->reader->main('hosted_tokenization_page_template'), $this->paymentAction((string) $this->reader->main('authorization_mode', 'authorization')), $vaultTitles, null, \true, null, null, null, null, null, null, $threeDs['enable3ds'], $threeDs['enforce'], $threeDs['exemptionEnabled'], $threeDs['exemptionType'], $threeDs['exemptionLimit'], 'iframe', $this->cardsAuthorizationMode((string) $this->reader->main('credit_card_authorization_mode', 'final_authorization')));
        $this->saveMethod($request, self::PRODUCT_CARDS, $enabled);
    }
    /**
     * Migrates the previous plugin's "Show tokenized cards" (`stored_card_buttons`) onto the
     * "Enable Saved Cards" setting. Only an explicit "no" disables it, the way the previous plugin
     * read the option (it defaulted to enabled).
     */
    private function migrateSavedCards(): void
    {
        $storedCardButtons = strtolower(trim((string) $this->reader->main('stored_card_buttons', 'yes')));
        SavedCardsSetting::set($storedCardButtons !== 'no');
    }
    /**
     * Migrates the previous plugin's single general "Fixed soft descriptor", which was used for
     * Sofinco and Linxo, onto both methods' per-method descriptor in this plugin.
     *
     * @return bool Whether a descriptor was set.
     */
    private function migrateSoftDescriptor(): bool
    {
        $descriptor = (string) $this->reader->main('fixed_soft_descriptor');
        if ($descriptor === '') {
            return \false;
        }
        SoftDescriptorSetting::set(self::PRODUCT_SOFINCO, $descriptor);
        SoftDescriptorSetting::set(self::PRODUCT_LINXO, $descriptor);
        return \true;
    }
    /**
     * Migrates the redirect payment methods the merchant had configured.
     *
     * @return bool Whether 2.x had any of them configured.
     */
    private function migrateRedirectMethods(): bool
    {
        $template = $this->hostedCheckoutTemplate();
        $paymentAction = $this->paymentAction((string) $this->reader->main('authorization_mode', 'authorization'));
        $found = \false;
        $failed = [];
        foreach (self::REDIRECT_PRODUCTS as $suffix => $productId) {
            if (!$this->reader->hasGateway($suffix)) {
                continue;
            }
            $found = \true;
            try {
                $current = $this->currentMethod($productId);
                $enabled = $this->reader->methodBool($suffix, 'enabled');
                $name = $this->nameMap($current['name'] ?? [], (string) $this->reader->method($suffix, 'title'));
                [$instantPayment, $recurrenceType, $signatureType, $threeDs] = $this->redirectExtras($productId);
                $request = new PaymentMethodRequest($productId, $name, $enabled, $template, $paymentAction, [], null, null, null, null, null, $recurrenceType, $signatureType, $instantPayment, $threeDs['enable3ds'], $threeDs['enforce'], $threeDs['exemptionEnabled'], $threeDs['exemptionType'], $threeDs['exemptionLimit'], null, null);
                $this->saveMethod($request, $productId, $enabled);
            } catch (\Throwable $e) {
                Logger::logError('Config migration skipped method ' . $productId . ': ' . $e->getMessage(), 'Migration');
                $failed[] = $productId;
            }
        }
        if ($failed !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message (product ids), logged, never output as HTML.
            throw new \RuntimeException('methods ' . implode(', ', $failed) . ' were not migrated.');
        }
        return $found;
    }
    /**
     * Method-specific extras [instantPayment, recurrenceType, signatureType, threeDs] for a product.
     *
     * @param string $productId
     *
     * @return array{0: bool|null, 1: string|null, 2: string|null, 3: array<string, mixed>}
     */
    private function redirectExtras(string $productId): array
    {
        $instantPayment = null;
        $recurrenceType = null;
        $signatureType = null;
        $threeDs = $this->emptyThreeDs();
        if ($productId === self::PRODUCT_BANK_TRANSFER) {
            $instantPayment = $this->reader->methodBool(LegacyConfigReader::BANK_TRANSFER_SUFFIX, 'instant_payment', $this->reader->mainBool('instant_payment', \true));
        }
        if ($productId === self::PRODUCT_SEPA) {
            $recurrenceType = 'unique';
            $signatureType = (string) $this->reader->main('sdd_signature_type', 'SMS');
        }
        if ($productId === self::PRODUCT_GOOGLE_PAY) {
            $threeDs = $this->threeDsFromMain();
        }
        return [$instantPayment, $recurrenceType, $signatureType, $threeDs];
    }
    /**
     * The previous plugin's "Hosted checkout page template". A value the merchant saved is kept as is,
     * empty included; a template never saved resolves to the previous plugin's default, which is what
     * it actually used.
     *
     * @return string
     */
    private function hostedCheckoutTemplate(): string
    {
        return (string) $this->reader->main('hosted_checkout_page_template', self::LEGACY_DEFAULT_HOSTED_CHECKOUT_TEMPLATE);
    }
    /**
     * Saves a payment method config and aligns its WooCommerce gateway registration.
     *
     * @param PaymentMethodRequest $request
     * @param string $productId
     * @param bool $enabled
     */
    private function saveMethod(PaymentMethodRequest $request, string $productId, bool $enabled): void
    {
        AdminAPI::get()->payment(self::STORE_ID)->save($request);
        AdminAPI::get()->payment(self::STORE_ID)->enable($productId, $enabled);
    }
    /**
     * Reads the current general settings as an array, or [] when unavailable.
     *
     * @return array<string, mixed>
     */
    private function currentGeneralSettings(): array
    {
        try {
            return AdminAPI::get()->generalSettings(self::STORE_ID)->getGeneralSettings()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * Reads the current config of a payment method as an array, or [] when unavailable.
     *
     * @param string $productId
     *
     * @return array<string, mixed>
     */
    private function currentMethod(string $productId): array
    {
        try {
            return AdminAPI::get()->payment(self::STORE_ID)->getPaymentMethod($productId)->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * Resolves the order-status mapping to preserve, falling back to the plugin default map
     * when the current settings do not carry one yet.
     *
     * @param array<string, mixed> $payment
     *
     * @return array<string, string>
     */
    private function statusMapping(array $payment): array
    {
        $default = (new StoreService())->getDefaultOrderStatusMapping();
        return ['captured' => (string) ($payment['paymentCapturedStatus'] ?? $default->getPaymentCapturedStatus()), 'error' => (string) ($payment['paymentErrorStatus'] ?? $default->getPaymentErrorStatus()), 'pending' => (string) ($payment['paymentPendingStatus'] ?? $default->getPaymentPendingStatus()), 'authorized' => (string) ($payment['paymentAuthorizedStatus'] ?? $default->getPaymentAuthorizedStatus()), 'cancelled' => (string) ($payment['paymentCancelledStatus'] ?? $default->getPaymentCancelledStatus()), 'refunded' => (string) ($payment['paymentRefundedStatus'] ?? $default->getPaymentRefundedStatus()), 'partiallyRefunded' => (string) ($payment['paymentPartiallyRefundedStatus'] ?? $default->getPaymentPartiallyRefundedStatus())];
    }
    /**
     * Builds the shared 3DS settings from the main gateway option.
     *
     * @return array<string, mixed>
     */
    private function threeDsFromMain(): array
    {
        $exemptionType = $this->exemptionType((string) $this->reader->main('3ds_exemption_type', 'none'));
        $exemptionLimit = (float) $this->reader->main('3ds_exemption_limit', 100);
        if ($exemptionType === 'low-value') {
            $exemptionLimit = (float) min($exemptionLimit, self::LOW_VALUE_MAX_LIMIT);
        }
        return ['enable3ds' => $this->reader->mainBool('enable_3ds', \true), 'enforce' => $this->reader->mainBool('enforce_3dsv2'), 'exemptionEnabled' => $this->reader->mainBool('request_3ds_exemption') && $exemptionType !== null, 'exemptionType' => $exemptionType, 'exemptionLimit' => $exemptionLimit];
    }
    /**
     * 3DS settings left at the request defaults (used by methods without 3DS).
     *
     * @return array<string, mixed>
     */
    private function emptyThreeDs(): array
    {
        return ['enable3ds' => null, 'enforce' => null, 'exemptionEnabled' => null, 'exemptionType' => null, 'exemptionLimit' => null];
    }
    /**
     * Converts a current name (list of {locale, value}) to the request's locale=>value map,
     * applying $title when given and falling back to a non-empty default.
     *
     * @param array<int, array<string, string>> $currentName
     * @param string $title
     *
     * @return array<string, string>
     */
    private function nameMap(array $currentName, string $title): array
    {
        $map = [];
        foreach ($currentName as $entry) {
            if (isset($entry['locale'], $entry['value'])) {
                $map[(string) $entry['locale']] = (string) $entry['value'];
            }
        }
        if ($title !== '') {
            $locale = $map !== [] ? (string) array_key_first($map) : $this->locale();
            return [$locale => $title];
        }
        if ($map !== []) {
            return $map;
        }
        return [$this->locale() => ModuleHelper::getModuleConfig()->getName()];
    }
    /**
     * @return string
     */
    private function locale(): string
    {
        $locale = function_exists('get_locale') ? (string) get_locale() : '';
        return $locale !== '' ? $locale : 'en_US';
    }
    /**
     * Maps the old authorization mode to the core payment action.
     *
     * @param string $old
     *
     * @return string
     */
    private function paymentAction(string $old): string
    {
        return strtolower($old) === 'sale' ? 'SALE' : 'FINAL_AUTHORIZATION';
    }
    /**
     * Maps the old capture mode to the core automatic-capture minutes (the core accepts only
     * a fixed set; values beyond five days are capped at the maximum).
     *
     * @param string $old
     *
     * @return int
     */
    private function captureMinutes(string $old): int
    {
        switch (strtolower($old)) {
            case 'end_of_day':
            case '1d':
                return 1440;
            case '2d':
                return 2880;
            case '3d':
            case '4d':
            case '5d':
            case '6d':
            case '7d':
                return 7200;
            case 'manual':
            default:
                return -1;
        }
    }
    /**
     * Maps the old exemption type to the core value, or null when there is no equivalent.
     *
     * @param string $old
     *
     * @return string|null
     */
    private function exemptionType(string $old): ?string
    {
        switch (strtolower($old)) {
            case 'none':
                // The previous plugin's default, "Preference: No challenge request".
                return 'no-challenge-requested';
            case 'low-value':
                return 'low-value';
            case 'transaction-risk-analysis':
                return 'transaction-risk-analysis';
            default:
                return null;
        }
    }
    /**
     * Maps the old credit-card authorization mode to the core cards authorization mode.
     *
     * @param string $old
     *
     * @return string
     */
    private function cardsAuthorizationMode(string $old): string
    {
        return strtolower($old) === 'pre_authorization' ? 'PRE_AUTHORIZATION' : 'FINAL_AUTHORIZATION';
    }
}
