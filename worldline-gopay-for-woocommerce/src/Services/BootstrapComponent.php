<?php

namespace WOP\OnlinePayments\Services;

// The use block below is longer than the 50 lines Plugin Check scans for the guard, so the guard comes first here.
defined('ABSPATH') || exit;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Order\Proxies\CaptureProxy as CoreCaptureProxy;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\HostedTokenizationProxy as CoreHostedTokenizationProxy;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\PaymentLinksProxy as CorePaymentLinksProxy;
use WOP\OnlinePayments\Core\Bootstrap\BootstrapComponent as CoreBootstrapComponent;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Connection\ConnectionConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Disconnect\DisconnectTime;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\LogSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\PayByLinkSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\WebhookSettingsEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\GeneralSettings\PaymentSettingsConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\MonitoringLog;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\WebhookLog;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentLink\PaymentLinkEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentMethod\PaymentMethodConfigEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\AuthorizedTransactionsRepository;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PendingTransactionsRepository;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionLockEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\ProductTypes\ProductTypeEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Tokens\TokenEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Tokens\TokensRepository as CoreTokensRepository;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\Bootstrap\SingleInstance;
use WOP\OnlinePayments\Core\Branding\Brand\ActiveBrandProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\Repositories\LogSettingsRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Encryption\Encryptor;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Language\LanguageService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Logo\LogoUrlService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\MetadataProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Payment\ShopPaymentService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\ShopOrderService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Version\VersionService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\MonitoringLogRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\WebhookLogRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Time\TimeProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Webhook\WebhookMode;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedCheckoutProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedTokenizationProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Order\Proxies\CaptureProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentLinksProxyInterface;
use WOP\OnlinePayments\Core\Infrastructure\Configuration\ConfigEntity;
use WOP\OnlinePayments\Core\Infrastructure\Configuration\Configuration;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\RepositoryRegistry;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Concrete\JsonSerializer;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Serializer;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Process;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\TaskEvents\TickEvent;
use WOP\OnlinePayments\Core\Infrastructure\Utility\Events\EventBus;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Proxies\GithubProxy;
use WOP\OnlinePayments\Proxies\WcCaptureProxy;
use WOP\OnlinePayments\Proxies\WcHostedCheckoutProxy;
use WOP\OnlinePayments\Proxies\WcHostedTokenizationProxy;
use WOP\OnlinePayments\Proxies\WcPaymentLinksProxy;
use WOP\OnlinePayments\Repositories\AuthorizedTransactionsRepositoryWc;
use WOP\OnlinePayments\Repositories\BaseRepository;
use WOP\OnlinePayments\Repositories\BaseRepositoryWithConditionalDelete;
use WOP\OnlinePayments\Repositories\MonitoringLogRepositoryWc;
use WOP\OnlinePayments\Repositories\MonitoringLogsRepository;
use WOP\OnlinePayments\Repositories\PendingTransactionsRepositoryWc;
use WOP\OnlinePayments\Repositories\PaymentTransactionLocksRepository;
use WOP\OnlinePayments\Repositories\PaymentTransactionsRepository;
use WOP\OnlinePayments\Repositories\ProductTypesRepository;
use WOP\OnlinePayments\Repositories\QueueRepository;
use WOP\OnlinePayments\Repositories\TokensRepository;
use WOP\OnlinePayments\Repositories\WebhookLogRepositoryWc;
use WOP\OnlinePayments\Repositories\WebhookLogsRepository;
use WOP\OnlinePayments\Services\Integration\ConfigService;
use WOP\OnlinePayments\Services\Integration\Encryptor as EncryptorService;
use WOP\OnlinePayments\Services\Integration\LanguageService as LanguageServiceImpl;
use WOP\OnlinePayments\Services\Integration\LoggerService;
use WOP\OnlinePayments\Services\Integration\LogoUrlService as LogoUrlServiceImpl;
use WOP\OnlinePayments\Services\Integration\MetadataProvider;
use WOP\OnlinePayments\Services\Integration\PaymentProductService as PaymentProductServiceImpl;
use WOP\OnlinePayments\Services\Integration\ShopOrderService as ShopOrderServiceImpl;
use WOP\OnlinePayments\Services\Integration\ShopPaymentService as ShopPaymentServiceImpl;
use WOP\OnlinePayments\Services\Integration\StoreService as StoreServiceImpl;
use WOP\OnlinePayments\Services\Integration\VersionService as VersionServiceImpl;
use WOP\OnlinePayments\Services\Integration\WcTokensRepository;
use WOP\OnlinePayments\Tasks\SessionTimeoutListener;
/**
 * Wires the WooCommerce platform adapter (integration services + entity
 * repositories) into the integration-core.
 */
class BootstrapComponent extends CoreBootstrapComponent
{
    /**
     * Registers the ModuleConfig and bootstraps the core for the configured brand.
     *
     * @param string $moduleConfigFile
     * @param string $brandConfigFile
     */
    public static function boot(string $moduleConfigFile, string $brandConfigFile): void
    {
        ServiceRegister::registerService(ModuleConfig::class, new SingleInstance(static function () use ($moduleConfigFile) {
            return new ModuleConfig($moduleConfigFile);
        }));
        // WooCommerce exposes a public webhook endpoint and sends its URL with every payment request,
        // so Worldline needs no back office configuration. Every other integration keeps the default
        // manual mode, where the merchant registers the URL themselves.
        static::bootstrap(static function () {
            return ModuleHelper::getModuleConfig()->getBrand();
        }, $brandConfigFile, WebhookMode::AUTOMATIC);
    }
    /**
     * Registers the WooCommerce integration service implementations.
     */
    protected static function initServices(): void
    {
        parent::initServices();
        ServiceRegister::registerService(Serializer::class, static function () {
            return new JsonSerializer();
        });
        ServiceRegister::registerService(Installer::class, new SingleInstance(static function () {
            return new Installer();
        }));
        ServiceRegister::registerService(UninstallService::class, new SingleInstance(static function () {
            return new UninstallService();
        }));
        ServiceRegister::registerService(ShopLoggerAdapter::class, new SingleInstance(static function () {
            return new LoggerService();
        }));
        ServiceRegister::registerService(Configuration::class, static function () {
            return ConfigService::getInstance();
        });
        ServiceRegister::registerService(Encryptor::class, new SingleInstance(static function () {
            return new EncryptorService();
        }));
        ServiceRegister::registerService(VersionService::class, static function () {
            return new VersionServiceImpl(new GithubProxy());
        });
        ServiceRegister::registerService(StoreService::class, static function () {
            return new StoreServiceImpl();
        });
        ServiceRegister::registerService(LanguageService::class, static function () {
            return new LanguageServiceImpl();
        });
        ServiceRegister::registerService(LogoUrlService::class, static function () {
            return new LogoUrlServiceImpl();
        });
        ServiceRegister::registerService(MetadataProviderInterface::class, static function () {
            return new MetadataProvider(ServiceRegister::getService(Configuration::class));
        });
        ServiceRegister::registerService(PaymentProductService::class, static function () {
            return new PaymentProductServiceImpl();
        });
        ServiceRegister::registerService(ShopPaymentService::class, static function () {
            return new ShopPaymentServiceImpl();
        });
        ServiceRegister::registerService(ShopOrderService::class, static function () {
            return new ShopOrderServiceImpl();
        });
    }
    /**
     * Registers the entity-to-repository mappings.
     */
    protected static function initRepositories(): void
    {
        parent::initRepositories();
        ServiceRegister::registerService(TokensRepositoryInterface::class, new SingleInstance(static function () {
            return new WcTokensRepository(new CoreTokensRepository(RepositoryRegistry::getRepository(TokenEntity::getClassName()), StoreContext::getInstance()));
        }));
        RepositoryRegistry::registerRepository(Process::getClassName(), BaseRepository::getClassName());
        RepositoryRegistry::registerRepository(ConfigEntity::getClassName(), BaseRepository::getClassName());
        RepositoryRegistry::registerRepository(QueueItem::getClassName(), QueueRepository::getClassName());
        RepositoryRegistry::registerRepository(ConnectionConfigEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(DisconnectTime::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(LogSettingsEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(PaymentMethodConfigEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(PaymentSettingsConfigEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(PayByLinkSettingsEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(WebhookSettingsEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        RepositoryRegistry::registerRepository(MonitoringLog::getClassName(), MonitoringLogsRepository::getClassName());
        RepositoryRegistry::registerRepository(WebhookLog::getClassName(), WebhookLogsRepository::getClassName());
        RepositoryRegistry::registerRepository(ProductTypeEntity::getClassName(), ProductTypesRepository::getClassName());
        RepositoryRegistry::registerRepository(PaymentTransactionEntity::getClassName(), PaymentTransactionsRepository::getClassName());
        RepositoryRegistry::registerRepository(PaymentTransactionLockEntity::getClassName(), PaymentTransactionLocksRepository::getClassName());
        RepositoryRegistry::registerRepository(TokenEntity::getClassName(), TokensRepository::getClassName());
        RepositoryRegistry::registerRepository(PaymentLinkEntity::getClassName(), BaseRepositoryWithConditionalDelete::getClassName());
        // Webhook and monitoring logs show the WooCommerce order id for a "WC-{id}" reference.
        ServiceRegister::registerService(MonitoringLogRepositoryInterface::class, new SingleInstance(static function () {
            return new MonitoringLogRepositoryWc(RepositoryRegistry::getRepository(MonitoringLog::getClassName()), StoreContext::getInstance(), ServiceRegister::getService(ActiveConnectionProvider::class), ServiceRegister::getService(ActiveBrandProviderInterface::class), ServiceRegister::getService(LogSettingsRepositoryInterface::class));
        }));
        ServiceRegister::registerService(WebhookLogRepositoryInterface::class, new SingleInstance(static function () {
            return new WebhookLogRepositoryWc(RepositoryRegistry::getRepository(WebhookLog::getClassName()), StoreContext::getInstance(), ServiceRegister::getService(ActiveConnectionProvider::class), ServiceRegister::getService(LogSettingsRepositoryInterface::class));
        }));
        // The core status-check and auto-capture tasks read their candidates from these
        // repositories; the plugin versions keep its status coverage and check the WooCommerce order.
        ServiceRegister::registerService(PendingTransactionsRepository::class, new SingleInstance(static function () {
            return new PendingTransactionsRepositoryWc(RepositoryRegistry::getRepository(PaymentTransactionEntity::class), ServiceRegister::getService(TimeProviderInterface::class));
        }));
        ServiceRegister::registerService(AuthorizedTransactionsRepository::class, new SingleInstance(static function () {
            return new AuthorizedTransactionsRepositoryWc(RepositoryRegistry::getRepository(PaymentTransactionEntity::class), ServiceRegister::getService(TimeProviderInterface::class));
        }));
    }
    /**
     * Adds the session-timeout task to the core periodic tasks enqueued on every runner tick.
     */
    protected static function initEvents()
    {
        parent::initEvents();
        /** @var EventBus $eventBus */
        $eventBus = ServiceRegister::getService(EventBus::class);
        $eventBus->when(TickEvent::class, static function () {
            (new SessionTimeoutListener())->handle();
        });
    }
    /**
     * Replaces the core proxies that create Hosted Checkout, hosted tokenization and Pay by Link
     * sessions with ones that also apply the saved-cards rules (`SavedCardsSetting`). The Hosted
     * Checkout proxy also sends the plugin's session timeout (`SessionTimeoutSetting`).
     */
    protected static function initProxies(): void
    {
        parent::initProxies();
        ServiceRegister::registerService(HostedCheckoutProxyInterface::class, new SingleInstance(static function () {
            return new WcHostedCheckoutProxy(ServiceRegister::getService(MerchantClientFactory::class));
        }));
        ServiceRegister::registerService(HostedTokenizationProxyInterface::class, new SingleInstance(static function () {
            $clientFactory = ServiceRegister::getService(MerchantClientFactory::class);
            return new WcHostedTokenizationProxy($clientFactory, new CoreHostedTokenizationProxy($clientFactory));
        }));
        ServiceRegister::registerService(PaymentLinksProxyInterface::class, new SingleInstance(static function () {
            $clientFactory = ServiceRegister::getService(MerchantClientFactory::class);
            return new WcPaymentLinksProxy($clientFactory, new CorePaymentLinksProxy($clientFactory));
        }));
        ServiceRegister::registerService(CaptureProxyInterface::class, new SingleInstance(static function () {
            return new WcCaptureProxy(new CoreCaptureProxy(ServiceRegister::getService(MerchantClientFactory::class)));
        }));
    }
}
