# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [Unreleased](https://github.com/Worldline-Plugins/integration-core/compare/main...dev)
### Added
- Multi-tenancy support: `TenantContext` singleton, `TenantRegistryInterface` with batch iteration and `isMultiTenant()`, `TenantAwareTask` base class, `TenantContextAspect`, and `DefaultTenantRegistry`.
- Fluent `forTenant()` method on all API facades (AdminAPI, OrderAPI, CheckoutAPI, WebhookAPI).
- Orchestrator tasks (`AutoCaptureCheckOrchestratorTask`, `TransactionStatusCheckOrchestratorTask`) for multi-tenant background processing with per-tenant error isolation and tenant-scoped queue names.
- Tenant context propagation in background tasks (`AutoCaptureCheckTask`, `TransactionStatusCheckTask`, `DisconnectTask`), runnables (`WaitPaymentOutcomeProcessRunner`), and enqueuers (`DisconnectTaskEnqueuer`).
- Tenant-aware log cleanup in `LogCleanupTask`.
- Webhook mode: `BootstrapComponent::bootstrap()` accepts an optional third argument (`WebhookMode::MANUAL` by
  default, `WebhookMode::AUTOMATIC` for integrations where Worldline discovers the webhook URL from the payment
  request). With manual webhooks no webhook URLs are sent with payment requests and no webhook settings storage
  is required from the integration. The active mode is reported as `webhookMode` in the general settings response.
- SEPA Direct Debit: `AIS` is accepted as a mandate signature type (`SignatureType::AIS`, `SignatureType::ais()`), next to `SMS`, `UNSIGNED` and `TICK_BOX` (DEV-381).
- Hosted Checkout: own SEPA Direct Debit mandate signature type (`HostedCheckout::getSignatureType()`, stored as
  `additionalData.signatureType`, default `SMS`). It is sent with the generic hosted checkout request and, newly, with
  payment link requests; the SEPA Direct Debit configuration keeps applying when SEPA Direct Debit is selected at
  checkout (DEV-381).
### Fixed
- Hosted checkout: the SEPA Direct Debit product id is sent only when SEPA Direct Debit is the selected product.
  With SEPA Direct Debit enabled, the generic hosted checkout no longer opens SEPA Direct Debit directly, and other
  single payment buttons (e.g. PayPal, cards in redirect mode) no longer fail with `UNKNOWN_PRODUCT_ID`.

## [1.0.7 - 1.0.8](https://github.com/Worldline-Plugins/integration-core/compare/1.0.7...1.0.8) - 2026-11-18
- WooCommerce integration stability improvements

## [1.0.6 - 1.0.7](https://github.com/Worldline-Plugins/integration-core/compare/1.0.6...1.0.7) - 2026-09-17
- Complete the interface with the already implemented methods

## [1.0.5 - 1.0.6](https://github.com/Worldline-Plugins/integration-core/compare/1.0.5...1.0.6) - 2026-09-11
- Remove constraint to be logged in for Mealvouchers

## [1.0.4 - 1.0.5](https://github.com/Worldline-Plugins/integration-core/compare/1.0.4...1.0.5) - 2026-08-14
- Add support for automatic webhooks configuration (unite automatic and manual webhooks)

## [1.0.3 - 1.0.4](https://github.com/Worldline-Plugins/integration-core/compare/1.0.3...1.0.4) - 2026-07-14
- Fixed webhook validation and payment link webhook transformation

## [1.0.2 - 1.0.3](https://github.com/Worldline-Plugins/integration-core/compare/1.0.2...1.0.3) - 2026-06-15
- Added support for new PAYID format.

## [1.0.1 - 1.0.2](https://github.com/Worldline-Plugins/integration-core/compare/1.0.1...1.0.2) - 2026-03-19
- Fixed the formatting of the locale parameter.
- Updated incomplete partial payments handling.
- Fixed transaction-risk-analysis and low-value exemption handling.

## [1.0.0 - 1.0.1](https://github.com/Worldline-Plugins/integration-core/compare/1.0.0...1.0.1) - 2026-02-03
- Changed branded package name build script. Now, the package name follows the git repository name.

## [1.0.0](https://github.com/Worldline-Plugins/integration-core/releases/tag/1.0.0) - 2026-01-29
- First stable release
