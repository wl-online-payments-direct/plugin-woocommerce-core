if (!window.OnlinePaymentsFE) {
    window.OnlinePaymentsFE = {};
}

(function () {
    /**
     * Webhook delivery modes reported by the core. With manual webhooks the merchant registers the
     * store's webhook URL in the Worldline back office. With automatic webhooks the core sends the URL
     * with every payment request, so nothing has to be registered and additional webhook URLs may be
     * configured.
     */
    const WEBHOOK_MODE_MANUAL = 'manual';
    const WEBHOOK_MODE_AUTOMATIC = 'automatic';
    /** Error code returned when Worldline rejects the webhook key and secret on save. */
    const WEBHOOK_CREDENTIALS_ERROR_CODE = 'connection.webhookCredentialsValidationFailed';

    /**
     * @typedef PaymentStatus
     *
     * @property {string} label
     * @property {string} value
     */

    /**
     * @typedef ConnectionInfo
     * @property {string} pspid
     * @property {string} apiKey
     * @property {string} apiSecret
     * @property {string} webhooksKey
     * @property {string} webhooksSecret
     */

    /**
     * @typedef AccountSettings
     *
     * @property {'test' | 'live'} mode
     * @property {ConnectionInfo?} sandboxData
     * @property {ConnectionInfo?} liveData
     */

    /**
     * @typedef PaymentSettings
     *
     * @property {'authorize' | 'authorize-capture'} paymentAction
     * @property {-1 | 60 | 120 | 240 | 480 | 1440 | 2880 | 7200} automaticCapture
     * @property {int} numberOfPaymentAttempts
     * @property {'1' | '3' | '6' | '12' | '18' | '24' | 'custom'} sessionTimeout
     * @property {int} sessionTimeoutCustom Minutes, used when sessionTimeout is 'custom'.
     * @property {boolean} applySurcharge
     * @property {boolean} enableSavedCards
     * @property {string} paymentCapturedStatus
     * @property {string} paymentErrorStatus
     * @property {string} paymentPendingStatus
     * @property {string} paymentAuthorizedStatus
     * @property {string} paymentCancelledStatus
     * @property {string} paymentRefundedStatus
     * @property {string} template
     * @property {string} paymentPartiallyRefundedStatus
     */

    /**
     * @typedef LogSettings
     *
     * @property {boolean} debugMode
     * @property {int} logDays
     */

    /**
     * @typedef PayByLinkSettings
     *
     * @property {boolean} enabled
     * @property {string} title
     * @property {int} expirationTime
     */

    /**
     * @typedef GeneralSettings
     *
     * @property {AccountSettings} accountSettings
     * @property {PaymentSettings} paymentSettings
     * @property {LogSettings} logSettings
     * @property {PayByLinkSettings} payByLinkSettings
     * @property {('manual' | 'automatic')?} webhookMode
     * @property {{ additionalWebhookUrls: string[] }?} webhookSettings
     */

    /**
     * Handles settings page logic.
     *
     * @param {{
     *  getGeneralSettingsUrl: string,
     *  getPaymentStatusesUrl: string,
     *  saveConnectionUrl: string,
     *  savePaymentSettingsUrl: string,
     *  saveLogSettingsUrl: string,
     *  savePayByLinkSettingsUrl: string,
     *  saveWebhookSettingsUrl: string?,
     *  webhooksUrl: string
     *  disconnectUrl: string}}  configuration
     * @constructor
     */
    function SettingsController(configuration) {
        /** @type AjaxServiceType */
        const api = OnlinePaymentsFE.ajaxService;

        const translationService = OnlinePaymentsFE.translationService;

        const {
            templateService,
            elementGenerator: generator,
            validationService: validator,
            components,
            utilities
        } = OnlinePaymentsFE;
        /** @type string */
        let currentStoreId = '';
        /** @type HTMLElement | null */
        let accountForm = null;
        /** Set when the webhook credentials migrated from the previous plugin version were not valid. */
        /** @type HTMLElement | null */
        let paymentForm = null;
        /** @type HTMLElement | null */
        let logForm = null;
        /** @type HTMLElement | null */
        let payByLinkForm = null;
        /** @type HTMLElement | null */
        let disconnectForm = null;

        /** @type AccountSettings */
        let activeAccountSettings;
        /** @type AccountSettings */
        let changedAccountSettings;
        /** @type PaymentSettings */
        let activePaymentSettings;
        /** @type PaymentSettings */
        let changedPaymentSettings;
        /** @type LogSettings */
        let activeLogSettings;
        /** @type LogSettings */
        let changedLogSettings;
        /** @type PayByLinkSettings */
        let activePayByLinkSettings;
        /** @type PayByLinkSettings */
        let changedPayByLinkSettings;
        /** @type {{ additionalWebhookUrls: string[] }} */
        let changedWebhookSettings;

        /**
         * Webhook delivery mode of the integration, as reported by the core. Defaults to manual, which
         * is also what a core that does not report the mode at all behaves like.
         *
         * @type {string}
         */
        let webhookMode = WEBHOOK_MODE_MANUAL;

        /**
         * Whether Worldline discovers the webhook URL from the payment request itself, in which case the
         * merchant registers nothing in the back office and may configure additional webhook URLs.
         *
         * @return {boolean}
         */
        const isAutomaticWebhooks = () => webhookMode === WEBHOOK_MODE_AUTOMATIC;

        /**
         * Whether the additional webhook URL fields should be offered. They are only usable when the mode
         * is automatic AND the integration exposes an endpoint to save them, so rendering and saving are
         * driven by one predicate. Rendering fields that cannot be saved would silently discard input.
         *
         * @return {boolean}
         */
        const areAdditionalWebhookUrlsEnabled = () => isAutomaticWebhooks() && !!configuration.saveWebhookSettingsUrl;

        /** @type PaymentStatus[] */
        let paymentStatuses;

        /**
         * Displays page content.
         *
         * @param {{ state?: string, storeId: string }} config
         */
        this.display = ({storeId}) => {
            currentStoreId = storeId;
            templateService.clearMainPage();
            [
                'getGeneralSettingsUrl',
                'getPaymentStatusesUrl',
                'saveConnectionUrl',
                'savePaymentSettingsUrl',
                'saveLogSettingsUrl',
                'savePayByLinkSettingsUrl',
                'saveWebhookSettingsUrl',
                'webhooksUrl',
                'disconnectUrl'
            ].forEach((prop) => {
                // Not every integration supplies every URL, so skip the ones that are absent rather than
                // calling replace() on undefined.
                if (typeof configuration[prop] !== 'string') {
                    return;
                }

                configuration[prop] = configuration[prop].replace('{storeId}', storeId);
            });

            return renderPage();
        };

        /**
         * Sets the unsaved changes.
         *
         * @return {boolean}
         */
        this.hasUnsavedChanges = () => false;

        const scrollToTop = () => {
            document.querySelector('#op-page > main')?.scrollTo({top: 0, left: 0, behavior: 'smooth'});
        };

        const renderPage = () => {
            utilities.showLoader();

            scrollToTop();
            let url = configuration.getGeneralSettingsUrl;
            let renderer = renderGeneralSettingsForm;

            return api
                .get(url, () => null)
                .then(renderer)
                .catch(renderer);
        };

        /**
         * Renders the general settings form.
         *
         * @param {GeneralSettings} settings
         */
        const renderGeneralSettingsForm = (settings) => {
            let url = configuration.getPaymentStatusesUrl;

            api.get(url, () => null)
                .then((response) => {
                    paymentStatuses = response;

                    activeAccountSettings = utilities.cloneObject(settings.accountSettings);
                    activePaymentSettings = utilities.cloneObject(settings.paymentSettings);
                    activeLogSettings = utilities.cloneObject(settings.logSettings);
                    activePayByLinkSettings = utilities.cloneObject(settings.payByLinkSettings);

                    changedAccountSettings = utilities.cloneObject(settings.accountSettings);
                    changedPaymentSettings = utilities.cloneObject(settings.paymentSettings);
                    changedLogSettings = utilities.cloneObject(settings.logSettings);
                    changedPayByLinkSettings = utilities.cloneObject(settings.payByLinkSettings);

                    webhookMode = settings.webhookMode || WEBHOOK_MODE_MANUAL;

                    changedWebhookSettings = utilities.cloneObject(settings.webhookSettings || {additionalWebhookUrls: []});
                    if (!Array.isArray(changedWebhookSettings.additionalWebhookUrls)) {
                        changedWebhookSettings.additionalWebhookUrls = [];
                    }

                    let content = generator.createElement('div', 'op-settings-page');

                    if (!OnlinePaymentsFE.state.areWebhookCredentialsValid()) {
                        content.appendChild(generator.createFlashMessage(
                            OnlinePaymentsFE.brand.code + '.webhookCredentialsInvalid',
                            'warning'
                        ));
                    }

                    content.appendChild(renderAccountForm());
                    content.appendChild(generator.createElement('div', 'op-divider'));
                    content.appendChild(renderPaymentSettingsForm());
                    content.appendChild(generator.createElement('div', 'op-divider'));
                    content.appendChild(renderLogForm());
                    content.appendChild(generator.createElement('div', 'op-divider'));
                    content.appendChild(renderPayByLinkForm());
                    content.appendChild(generator.createElement('div', 'op-divider'));
                    content.appendChild(renderDisconnectForm());

                    templateService.getMainPage().appendChild(content);

                    handlePayByLinkDependencies('enabled', activePayByLinkSettings.enabled);
                    handleSessionTimeoutDependencies(activePaymentSettings.sessionTimeout || '3');
                })
                .finally(() => {
                        let header = templateService.getHeaderSection();
                        let title = header.querySelector('.op-main-title');
                        title.innerText = OnlinePaymentsFE.sanitize(translationService.translate('generalSettings.title'));
                        utilities.hideLoader();
                        OnlinePaymentsFE.state.setHeader();
                        OnlinePaymentsFE.state.initializeFooter();
                    }
                );
        };

        const renderAccountForm = () => {
            if (accountForm) {
                templateService.clearComponent(accountForm);
            }

            let activeConnection = activeAccountSettings.liveData;
            if (activeAccountSettings.mode === 'test') {
                activeConnection = activeAccountSettings.sandboxData;
            }

            const webhookUrlDiv = generator.createElement(
                'div',
                'op-webhooks-url-wrapper'
            );
            const webhooksUrl = generator.createElement(
                'span',
                'op-webhooks-url',
                OnlinePaymentsFE.state.formatUrl(configuration.webhooksUrl)
            );
            webhookUrlDiv.appendChild(webhooksUrl);

            // With automatic webhooks the URL is sent with every transaction, so the merchant never
            // copies it anywhere and no copy button or description is shown. With manual webhooks the
            // URL has to be registered in the Worldline back office, so both are needed.
            if (!isAutomaticWebhooks()) {
                const webhookCopy = generator.createElement(
                    'span',
                    'op-webhooks-url-copy'
                );
                webhookCopy.addEventListener('click', function () {
                    navigator.clipboard.writeText(OnlinePaymentsFE.state.formatUrl(configuration.webhooksUrl));
                    utilities.createToasterMessage('general.copied');
                });
                webhookUrlDiv.appendChild(webhookCopy);
            }

            const webhooksUrlWrapper = generator.createFieldWrapper(
                webhookUrlDiv,
                'connection.webhooksUrl.title',
                isAutomaticWebhooks() ? undefined : 'connection.webhooksUrl.description'
            );

            const saveButton = generator.createButton({
                type: 'primary',
                name: 'saveAccountBtn',
                disabled: true,
                label: 'general.saveChanges',
                onClick: () => {
                    let mode = document.querySelector('[name="mode"]'),
                        apiKey = document.querySelector('[name="apiKey"]'),
                        pspid = document.querySelector('[name="pspid"]'),
                        apiSecret = document.querySelector('[name="apiSecret"]'),
                        webhooksKey = document.querySelector('[name="webhooksKey"]'),
                        webhooksSecret = document.querySelector('[name="webhooksSecret"]');
                    const isValid =
                        validator.validateRequiredField(mode) &&
                        validator.validateRequiredField(pspid, 'connection.pspid.error') &&
                        validator.validateRequiredField(apiKey, 'connection.apiKey.error') &&
                        validator.validateRequiredField(apiSecret, 'connection.apiSecret.error') &&
                        validator.validateRequiredField(webhooksKey, 'connection.webhooksKey.error') &&
                        validator.validateRequiredField(webhooksSecret, 'connection.webhooksSecret.error');

                    // Additional webhook URLs are optional, but any non-empty one must be a valid
                    // https:// URL (matches the server-side check in the core). The fields only exist
                    // with automatic webhooks.
                    let webhookUrlsValid = true;
                    (areAdditionalWebhookUrlsEnabled() ? [0, 1, 2, 3] : []).forEach((index) => {
                        const field = document.querySelector('[name="additionalWebhookUrl' + index + '"]');
                        if (!field) {
                            return;
                        }
                        const value = (field.value || '').trim();
                        const invalid = value !== '' && !isValidHttpsWebhookUrl(value);
                        if (!validator.validateField(field, invalid, 'connection.additionalWebhookUrls.error')) {
                            webhookUrlsValid = false;
                        }
                    });

                    if (isValid && webhookUrlsValid) {
                        utilities.showLoader();

                        const requests = [
                            api.post(
                                configuration.saveConnectionUrl,
                                {
                                    mode: changedAccountSettings.mode,
                                    testData: {
                                        pspid: changedAccountSettings.sandboxData.pspid,
                                        apiKey: changedAccountSettings.sandboxData.apiKey,
                                        apiSecret: changedAccountSettings.sandboxData.apiSecret,
                                        webhooksKey: changedAccountSettings.sandboxData.webhooksKey,
                                        webhooksSecret: changedAccountSettings.sandboxData.webhooksSecret
                                    },
                                    liveData: {
                                        pspid: changedAccountSettings.liveData.pspid,
                                        apiKey: changedAccountSettings.liveData.apiKey,
                                        apiSecret: changedAccountSettings.liveData.apiSecret,
                                        webhooksKey: changedAccountSettings.liveData.webhooksKey,
                                        webhooksSecret: changedAccountSettings.liveData.webhooksSecret
                                    }
                                }
                            )
                        ];

                        if (areAdditionalWebhookUrlsEnabled()) {
                            requests.push(api.post(configuration.saveWebhookSettingsUrl, {
                                additionalWebhookUrls: (changedWebhookSettings.additionalWebhookUrls || [])
                                    .map((webhookUrl) => (webhookUrl || '').trim())
                                    .filter((webhookUrl) => webhookUrl !== '')
                            }));
                        }

                        Promise.all(requests)
                            .then((responses) => {
                                if (responses.every((response) => response.errorMessage === undefined)) {
                                    if (responses[0].webhookCredentialsValidated) {
                                        OnlinePaymentsFE.state.setWebhookCredentialsValid(true);
                                    }
                                    handleSaveSuccess('accountSettings');
                                    if (responses[0].webhookCredentialsValidated) {
                                        showFlashMessage('connection.messages.webhookCredentialsValidated', 'success');
                                    }

                                    return;
                                }

                                if (responses[0].errorCode === WEBHOOK_CREDENTIALS_ERROR_CODE) {
                                    showFlashMessage('connection.messages.webhookCredentialsValidationFailed', 'error');

                                    return;
                                }

                                handleSaveFailure('accountSettings');
                            })
                            .catch(() => {
                                handleSaveFailure('accountSettings');
                            })
                            .finally(() => {
                                utilities.hideLoader();
                            });
                    }
                }
            });
            const buttonWrapper = generator.createElement('div', 'op-button-wrapper');

            buttonWrapper.append(saveButton);

            // Optional global webhook URLs (up to 4) sent in the payment feedbacks object alongside the
            // store's own webhook URL. Only the first field carries the label/description; the rest are
            // bare inputs stacked beneath it. They are only offered with automatic webhooks, since with
            // manual webhooks nothing is sent with the payment request for Worldline to call back.
            const additionalWebhookUrls = changedWebhookSettings.additionalWebhookUrls || [];
            const webhookUrlFields = !areAdditionalWebhookUrlsEnabled() ? [] : [0, 1, 2, 3].map((index) => generator.createTextField({
                name: 'additionalWebhookUrl' + index,
                value: additionalWebhookUrls[index] || '',
                label: index === 0 ? 'connection.additionalWebhookUrls.title' : '',
                description: index === 0 ? 'connection.additionalWebhookUrls.description' : '',
                placeholder: translationService.translate('connection.additionalWebhookUrls.placeholder'),
                onChange: (value) => handleWebhookUrlChange(index, value)
            }));

            accountForm = generator.createElement('div', 'op-card', '', null, [
                generator.createElement('div', 'op-card-title', '', null, [
                    generator.createElement(
                        'h1',
                        '',
                        translationService.translate('generalSettings.accountSettings.title')
                    ),
                    generator.createElement(
                        'p',
                        '',
                        translationService.translate(OnlinePaymentsFE.brand.code + '.generalSettings.accountSettings.description')
                    )
                ]),
                generator.createElement('div', 'op-card-content', '', null, [
                    generator.createDropdownField({
                        name: 'mode',
                        value: activeAccountSettings.mode || 'test',
                        label: 'connection.mode.title',
                        description: 'connection.mode.description',
                        options: [
                            {label: 'connection.mode.options.sandbox', value: 'test'},
                            {label: 'connection.mode.options.live', value: 'live'}
                        ],
                        onChange: (value) => handleAccountSettingsChange('mode', value)
                    }),
                    generator.createTextField({
                        name: 'pspid',
                        value: activeConnection.pspid,
                        label: 'connection.pspid.title',
                        description: 'connection.pspid.description',
                        error: 'connection.pspid.error',
                        onChange: (value) => handleAccountSettingsChange('pspid', value)
                    }),
                    generator.createTextField({
                        name: 'apiKey',
                        value: activeConnection.apiKey,
                        label: 'connection.apiKey.title',
                        description: 'connection.apiKey.description',
                        error: 'connection.apiKey.error',
                        onChange: (value) => handleAccountSettingsChange('apiKey', value)
                    }),
                    generator.createPasswordField({
                        name: 'apiSecret',
                        value: activeConnection.apiSecret,
                        label: 'connection.apiSecret.title',
                        placeholder: translationService.translate(
                            'connection.apiSecret.placeholder',
                            [activeAccountSettings.mode || 'sandbox']
                        ),
                        description: 'connection.apiSecret.description',
                        error: 'connection.apiSecret.error',
                        onChange: (value) => handleAccountSettingsChange('apiSecret', value)
                    }),
                    generator.createTextField({
                        name: 'webhooksKey',
                        value: activeConnection.webhooksKey,
                        label: 'connection.webhooksKey.title',
                        placeholder: translationService.translate('connection.webhooksKey.placeholder'),
                        description: 'connection.webhooksKey.description',
                        error: 'connection.webhooksKey.error',
                        onChange: (value) => handleAccountSettingsChange('webhooksKey', value)
                    }),
                    generator.createPasswordField({
                        name: 'webhooksSecret',
                        value: activeConnection.webhooksSecret,
                        label: 'connection.webhooksSecret.title',
                        placeholder: translationService.translate('connection.webhooksSecret.placeholder'),
                        description: 'connection.webhooksSecret.description',
                        error: 'connection.webhooksSecret.error',
                        onChange: (value) => handleAccountSettingsChange('webhooksSecret', value)
                    }),
                    webhooksUrlWrapper,
                    ...webhookUrlFields,
                    buttonWrapper
                ])
            ]);

            return accountForm;
        };

        const handleAccountSettingsChange = (prop, value) => {
            if (changedAccountSettings.mode === 'test') {
                changedAccountSettings.sandboxData[prop] = OnlinePaymentsFE.sanitize(value);
            } else {
                changedAccountSettings.liveData[prop] = OnlinePaymentsFE.sanitize(value);
            }

            let apiKey = document.querySelector('[name="apiKey"]'),
                pspid = document.querySelector('[name="pspid"]'),
                apiSecret = document.querySelector('[name="apiSecret"]'),
                webhooksKey = document.querySelector('[name="webhooksKey"]'),
                webhooksSecret = document.querySelector('[name="webhooksSecret"]'),
                saveButton = document.querySelector('[name="saveAccountBtn"]');

            if (prop === 'mode') {
                let key = value === 'test' ? 'sandboxData' : 'liveData';

                apiKey.value = activeAccountSettings[key].apiKey;
                pspid.value = activeAccountSettings[key].pspid;
                apiSecret.value = activeAccountSettings[key].apiSecret;
                webhooksKey.value = activeAccountSettings[key].webhooksKey;
                webhooksSecret.value = activeAccountSettings[key].webhooksSecret;
                changedAccountSettings.mode = value;
            } else {
                let field = document.querySelector('[name="' + prop + '"]');

                validator.validateRequiredField(field, 'connection.' + prop + '.error');
            }

            saveButton.disabled = (!pspid.value || !apiKey.value || !apiSecret.value || !webhooksKey.value
                || !webhooksSecret.value) && changedAccountSettings !== activeAccountSettings;
        }

        const handleWebhookUrlChange = (index, value) => {
            changedWebhookSettings.additionalWebhookUrls[index] = OnlinePaymentsFE.sanitize(value);

            let pspid = document.querySelector('[name="pspid"]'),
                apiKey = document.querySelector('[name="apiKey"]'),
                apiSecret = document.querySelector('[name="apiSecret"]'),
                webhooksKey = document.querySelector('[name="webhooksKey"]'),
                webhooksSecret = document.querySelector('[name="webhooksSecret"]'),
                saveButton = document.querySelector('[name="saveAccountBtn"]');

            // Webhook URLs are optional; enable saving as long as the required account fields are present.
            saveButton.disabled = !pspid.value || !apiKey.value || !apiSecret.value
                || !webhooksKey.value || !webhooksSecret.value;
        }

        /**
         * Whether an additional webhook URL is acceptable: a valid https:// URL with a real hostname
         * (contains a dot and a non-empty top-level segment). Mirrors the server-side check in the core.
         *
         * @param {string} url
         *
         * @return {boolean}
         */
        const isValidHttpsWebhookUrl = (url) => {
            try {
                if (!url.startsWith('https://')) {
                    return false;
                }

                const hostname = new URL(url).hostname;
                if (!hostname.includes('.')) {
                    return false;
                }

                const parts = hostname.split('.');

                return parts[parts.length - 1].length >= 1;
            } catch (e) {
                return false;
            }
        };

        const renderPaymentSettingsForm = () => {
            if (paymentForm) {
                templateService.clearComponent(paymentForm);
            }

            let statusOptions = [];

            paymentStatuses.forEach(status => {
                statusOptions.push({label: status.label, value: status.value});
            })

            let saveButton = generator.createButton({
                    type: 'primary',
                    name: 'paymentSettingsBtn',
                    disabled: true,
                    label: 'general.saveChanges',
                    onClick: () => {
                        let paymentAction = paymentForm.querySelector('[name="paymentAction"]'),
                            automaticCapture = paymentForm.querySelector('[name="automaticCapture"]'),
                            paymentCapturedStatus = paymentForm.querySelector('[name="paymentCapturedStatus"]'),
                            paymentErrorStatus = paymentForm.querySelector('[name="paymentErrorStatus"]'),
                            paymentPendingStatus = paymentForm.querySelector('[name="paymentPendingStatus"]'),
                            paymentAuthorizedStatus = paymentForm.querySelector('[name="paymentAuthorizedStatus"]'),
                            paymentCancelledStatus = paymentForm.querySelector('[name="paymentCancelledStatus"]'),
                            paymentRefundedStatus = paymentForm.querySelector('[name="paymentRefundedStatus"]'),
                            paymentPartiallyRefundedStatus = paymentForm.querySelector('[name="paymentPartiallyRefundedStatus"]');

                        let isValid =
                            validator.validateRequiredField(paymentAction) &&
                            validator.validateRequiredField(automaticCapture) &&
                            validator.validateRequiredField(paymentCapturedStatus) &&
                            validator.validateRequiredField(paymentErrorStatus) &&
                            validator.validateRequiredField(paymentPendingStatus) &&
                            validator.validateRequiredField(paymentAuthorizedStatus) &&
                            validator.validateRequiredField(paymentCancelledStatus) &&
                            validator.validateRequiredField(paymentRefundedStatus) &&
                            validator.validateRequiredField(paymentPartiallyRefundedStatus);

                        if (isValid) {
                            utilities.showLoader();
                            api.post(configuration.savePaymentSettingsUrl, changedPaymentSettings)
                                .then(() => handleSaveSuccess('paymentSettings'))
                                .catch(() => handleSaveFailure('paymentSettings'))
                                .finally(() => {
                                    utilities.hideLoader();
                                });
                        }
                    }
                }
            );

            const buttonWrapper = generator.createElement('div', 'op-button-wrapper');
            buttonWrapper.append(saveButton);

            paymentForm = generator.createElement('div', 'op-card', '', null, [
                    generator.createElement('div', 'op-card-title', '', null, [
                        generator.createElement(
                            'h1',
                            '',
                            translationService.translate('generalSettings.paymentSettings.title')
                        ),
                        generator.createElement(
                            'p',
                            '',
                            translationService.translate('generalSettings.paymentSettings.description')
                        )
                    ]),
                    generator.createElement('div', 'op-card-content', '', null, [
                            generator.createDropdownField({
                                name: 'paymentAction',
                                value: activePaymentSettings.paymentAction || 'authorize-capture',
                                label: 'generalSettings.paymentSettings.paymentAction.title',
                                description: 'generalSettings.paymentSettings.paymentAction.description',
                                options: [
                                    {
                                        label: 'generalSettings.paymentSettings.paymentAction.values.authorize',
                                        value: 'FINAL_AUTHORIZATION'
                                    },
                                    {
                                        label: 'generalSettings.paymentSettings.paymentAction.values.authorizeCapture',
                                        value: 'SALE'
                                    }
                                ],
                                onChange: (value) => handlePaymentsSettingsChange('paymentAction', value)
                            }),
                            generator.createDropdownField({
                                name: 'automaticCapture',
                                value: activePaymentSettings.automaticCapture || -1,
                                label: 'generalSettings.paymentSettings.automaticCapture.title',
                                description: 'generalSettings.paymentSettings.automaticCapture.description',
                                options: [
                                    {label: 'generalSettings.paymentSettings.automaticCapture.values.never', value: -1},
                                    {label: 'generalSettings.paymentSettings.automaticCapture.values.oneHour', value: 60},
                                    {label: 'generalSettings.paymentSettings.automaticCapture.values.twoHours', value: 120},
                                    {
                                        label: 'generalSettings.paymentSettings.automaticCapture.values.fourHours',
                                        value: 240
                                    },
                                    {
                                        label: 'generalSettings.paymentSettings.automaticCapture.values.eightHours',
                                        value: 480
                                    },
                                    {label: 'generalSettings.paymentSettings.automaticCapture.values.oneDay', value: 1440},
                                    {label: 'generalSettings.paymentSettings.automaticCapture.values.twoDays', value: 2880},
                                    {
                                        label: 'generalSettings.paymentSettings.automaticCapture.values.fiveDays',
                                        value: 7200
                                    },
                                ],
                                onChange: (value) => handlePaymentsSettingsChange('automaticCapture', value)
                            }),
                            generator.createDropdownField({
                                name: 'sessionTimeout',
                                value: String(activePaymentSettings.sessionTimeout || '3'),
                                label: 'generalSettings.paymentSettings.sessionTimeout.title',
                                description: 'generalSettings.paymentSettings.sessionTimeout.description',
                                options: [
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.oneHour', value: '1'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.threeHours', value: '3'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.sixHours', value: '6'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.twelveHours', value: '12'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.eighteenHours', value: '18'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.twentyFourHours', value: '24'},
                                    {label: 'generalSettings.paymentSettings.sessionTimeout.values.custom', value: 'custom'},
                                ],
                                onChange: (value) => handlePaymentsSettingsChange('sessionTimeout', String(value))
                            }),
                            generator.createNumberField({
                                name: 'sessionTimeoutCustom',
                                value: activePaymentSettings.sessionTimeoutCustom || 180,
                                label: 'generalSettings.paymentSettings.sessionTimeoutCustom.title',
                                description: 'generalSettings.paymentSettings.sessionTimeoutCustom.description',
                                onChange: (value) => handlePaymentsSettingsChange('sessionTimeoutCustom', value)
                            }),
                            generator.createTextField(
                                {
                                    name: 'template',
                                    value: activePaymentSettings.template,
                                    type: 'text',
                                    label: 'payments.configure.fields.templateName.label',
                                    description: 'payments.configure.fields.templateName.description',
                                    onChange: (value) => handlePaymentsSettingsChange('template', value, false)
                                }
                            ),
                            generator.createNumberField({
                                name: 'numberOfPaymentAttempts',
                                value: activePaymentSettings.numberOfPaymentAttempts || 10,
                                label: 'generalSettings.paymentSettings.attemptNumber.title',
                                description: 'generalSettings.paymentSettings.attemptNumber.description',
                                onChange: (value) => handlePaymentsSettingsChange('numberOfPaymentAttempts', value)
                            }),
                            generator.createFormFields([
                                {
                                    name: 'applySurcharge',
                                    value: activePaymentSettings.applySurcharge,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.paymentSettings.applySurcharge.title`,
                                    description: `generalSettings.paymentSettings.applySurcharge.description`,
                                    onChange: (value) => handlePaymentsSettingsChange('applySurcharge', value)
                                }
                            ])[0],
                            generator.createFormFields([
                                {
                                    name: 'sendShoppingCart',
                                    value: activePaymentSettings.sendShoppingCart,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.paymentSettings.sendShoppingCart.title`,
                                    description: `generalSettings.paymentSettings.sendShoppingCart.description`,
                                    onChange: (value) => handlePaymentsSettingsChange('sendShoppingCart', value)
                                }
                            ])[0],
                            generator.createFormFields([
                                {
                                    name: 'displayConfirmationPage',
                                    value: activePaymentSettings.displayConfirmationPage,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.paymentSettings.displayConfirmationPage.title`,
                                    description: `generalSettings.paymentSettings.displayConfirmationPage.description`,
                                    onChange: (value) => handlePaymentsSettingsChange('displayConfirmationPage', value)
                                }
                            ])[0],
                            generator.createFormFields([
                                {
                                    name: 'enableSavedCards',
                                    value: activePaymentSettings.enableSavedCards,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.paymentSettings.enableSavedCards.title`,
                                    description: `generalSettings.paymentSettings.enableSavedCards.description`,
                                    onChange: (value) => handlePaymentsSettingsChange('enableSavedCards', value)
                                }
                            ])[0],
                            generator.createDropdownField({
                                name: 'paymentCapturedStatus',
                                value: activePaymentSettings.paymentCapturedStatus,
                                label: 'generalSettings.paymentSettings.paymentCapturedStatus.title',
                                description: 'generalSettings.paymentSettings.paymentCapturedStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentCapturedStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentErrorStatus',
                                value: activePaymentSettings.paymentErrorStatus,
                                label: 'generalSettings.paymentSettings.paymentErrorStatus.title',
                                description: 'generalSettings.paymentSettings.paymentErrorStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentErrorStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentPendingStatus',
                                value: activePaymentSettings.paymentPendingStatus,
                                label: 'generalSettings.paymentSettings.paymentPendingStatus.title',
                                description: 'generalSettings.paymentSettings.paymentPendingStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentPendingStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentAuthorizedStatus',
                                value: activePaymentSettings.paymentAuthorizedStatus,
                                label: 'generalSettings.paymentSettings.paymentAuthorizedStatus.title',
                                description: 'generalSettings.paymentSettings.paymentAuthorizedStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentAuthorizedStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentCancelledStatus',
                                value: activePaymentSettings.paymentCancelledStatus,
                                label: 'generalSettings.paymentSettings.paymentCancelledStatus.title',
                                description: 'generalSettings.paymentSettings.paymentCancelledStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentCancelledStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentRefundedStatus',
                                value: activePaymentSettings.paymentRefundedStatus,
                                label: 'generalSettings.paymentSettings.paymentRefundedStatus.title',
                                description: 'generalSettings.paymentSettings.paymentRefundedStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentRefundedStatus', value)
                            }),
                            generator.createDropdownField({
                                name: 'paymentPartiallyRefundedStatus',
                                value: activePaymentSettings.paymentPartiallyRefundedStatus,
                                label: 'generalSettings.paymentSettings.paymentPartiallyRefundedStatus.title',
                                description: 'generalSettings.paymentSettings.paymentPartiallyRefundedStatus.description',
                                options: statusOptions,
                                onChange: (value) => handlePaymentsSettingsChange('paymentPartiallyRefundedStatus', value)
                            }),
                            buttonWrapper
                        ]
                    )
                ]
            );

            return paymentForm;
        }

        const handlePaymentsSettingsChange = (prop, value) => {
            let paymentSettingsBtn = paymentForm.querySelector('[name="paymentSettingsBtn"]');

            if (prop === 'sessionTimeout') {
                handleSessionTimeoutDependencies(value);
            }

            if (prop === 'sessionTimeoutCustom') {
                let customTimeout = paymentForm.querySelector('[name="sessionTimeoutCustom"]');
                if (!isValidSessionTimeoutMinutes(value)) {
                    validator.setError(customTimeout, 'generalSettings.paymentSettings.sessionTimeoutCustom.error');
                    paymentSettingsBtn.disabled = true;

                    return;
                }

                validator.removeError(customTimeout);
            }

            if (prop === 'numberOfPaymentAttempts') {
                let numberOfAttempts = paymentForm.querySelector('[name="numberOfPaymentAttempts"]');
                if (value < 1 || value > 10) {
                    validator.setError(numberOfAttempts, 'generalSettings.paymentSettings.attemptNumber.error');
                    paymentSettingsBtn.disabled = true;

                    return;
                } else {
                    validator.removeError(numberOfAttempts);
                }
            }

            changedPaymentSettings[prop] = value;

            paymentSettingsBtn.disabled = changedPaymentSettings.paymentAction === activePaymentSettings.paymentAction &&
                changedPaymentSettings.numberOfPaymentAttempts === activePaymentSettings.numberOfPaymentAttempts &&
                changedPaymentSettings.applySurcharge === activePaymentSettings.applySurcharge &&
                changedPaymentSettings.automaticCapture === activePaymentSettings.automaticCapture &&
                String(changedPaymentSettings.sessionTimeout) === String(activePaymentSettings.sessionTimeout) &&
                String(changedPaymentSettings.sessionTimeoutCustom) === String(activePaymentSettings.sessionTimeoutCustom) &&
                changedPaymentSettings.template === activePaymentSettings.template &&
                changedPaymentSettings.sendShoppingCart === activePaymentSettings.sendShoppingCart &&
                changedPaymentSettings.displayConfirmationPage === activePaymentSettings.displayConfirmationPage &&
                changedPaymentSettings.enableSavedCards === activePaymentSettings.enableSavedCards &&
                changedPaymentSettings.paymentCapturedStatus === activePaymentSettings.paymentCapturedStatus &&
                changedPaymentSettings.paymentErrorStatus === activePaymentSettings.paymentErrorStatus &&
                changedPaymentSettings.paymentPendingStatus === activePaymentSettings.paymentPendingStatus &&
                changedPaymentSettings.paymentAuthorizedStatus === activePaymentSettings.paymentAuthorizedStatus &&
                changedPaymentSettings.paymentCancelledStatus === activePaymentSettings.paymentCancelledStatus &&
                changedPaymentSettings.paymentRefundedStatus === activePaymentSettings.paymentRefundedStatus &&
                changedPaymentSettings.paymentPartiallyRefundedStatus === activePaymentSettings.paymentPartiallyRefundedStatus;
        }

        /**
         * Shows the custom minutes field only while "Custom" is selected.
         *
         * @param {string} selected
         */
        const handleSessionTimeoutDependencies = (selected) => {
            let customTimeout = paymentForm.querySelector('[name="sessionTimeoutCustom"]');
            let wrapper = utilities.getAncestor(customTimeout, 'op-field-wrapper');

            if (String(selected) === 'custom') {
                utilities.showElement(wrapper);

                return;
            }

            validator.removeError(customTimeout);
            utilities.hideElement(wrapper);
        }

        /**
         * Whether the custom session timeout is a whole number of minutes within 1-1440.
         *
         * @param {string|number} value
         * @returns {boolean}
         */
        const isValidSessionTimeoutMinutes = (value) => {
            let minutes = Number(value);

            return Number.isInteger(minutes) && minutes >= 1 && minutes <= 1440;
        }

        const renderLogForm = () => {
            if (logForm) {
                templateService.clearComponent(logForm);
            }

            let saveButton = generator.createButton({
                    type: 'primary',
                    name: 'logSettingsBtn',
                    disabled: true,
                    label: 'general.saveChanges',
                    onClick: () => {
                        let logDays = logForm.querySelector('[name="logDays"]');

                        let isValid = validator.validateRequiredField(logDays);

                        if (isValid) {
                            utilities.showLoader();
                            api.post(configuration.saveLogSettingsUrl, changedLogSettings)
                                .then(() => handleSaveSuccess('logSettings'))
                                .catch(() => handleSaveFailure('logSettings'))
                                .finally(() => {
                                    utilities.hideLoader();
                                });
                        }
                    }
                }
            );
            const buttonWrapper = generator.createElement('div', 'op-button-wrapper');
            buttonWrapper.append(saveButton);

            logForm = generator.createElement('div', 'op-card', '', null, [
                    generator.createElement('div', 'op-card-title', '', null, [
                        generator.createElement(
                            'h1',
                            '',
                            translationService.translate('generalSettings.logSettings.title')
                        ),
                        generator.createElement(
                            'p',
                            '',
                            translationService.translate('generalSettings.logSettings.description')
                        )
                    ]),
                    generator.createElement('div', 'op-card-content', '', null, [
                        ...generator.createFormFields([
                                {
                                    name: 'debugMode',
                                    value: activeLogSettings.debugMode,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.logSettings.debugMode.title`,
                                    onChange: (value) => handleLogSettingsChange('debugMode', value)
                                },
                                {
                                    name: 'logDays',
                                    value: activeLogSettings.logDays,
                                    type: 'number',
                                    className: '',
                                    label: `generalSettings.logSettings.logDays.title`,
                                    description: `generalSettings.logSettings.logDays.description`,
                                    onChange: (value) => handleLogSettingsChange('logDays', value)
                                }
                            ]
                        ),
                        buttonWrapper
                    ])
                ]
            );

            return logForm;
        }

        const renderPayByLinkForm = () => {
            if (payByLinkForm) {
                templateService.clearComponent(payByLinkForm);
            }

            let payByLinkBtn = generator.createButton({
                type: 'primary',
                name: 'payByLinkBtn',
                disabled: true,
                label: 'general.saveChanges',
                onClick: () => {
                    utilities.showLoader();
                    api.post(configuration.savePayByLinkSettingsUrl, changedPayByLinkSettings)
                        .then(() => handleSaveSuccess('payByLinkSettings'))
                        .catch(() => handleSaveFailure('payByLinkSettings'))
                        .finally(() => {
                            utilities.hideLoader();
                        });
                }
            });
            const buttonWrapper = generator.createElement('div', 'op-button-wrapper');
            buttonWrapper.append(payByLinkBtn);

            payByLinkForm = generator.createElement('div', 'op-card', '', null, [
                    generator.createElement('div', 'op-card-title', '', null, [
                        generator.createElement(
                            'h1',
                            '',
                            translationService.translate('generalSettings.payByLinkSettings.title')
                        ),
                        generator.createElement(
                            'p',
                            '',
                            translationService.translate('generalSettings.payByLinkSettings.description')
                        )
                    ]),
                    generator.createElement('div', 'op-card-content', '', null, [
                        ...generator.createFormFields([
                                {
                                    name: 'enabled',
                                    value: activePayByLinkSettings.enabled,
                                    type: 'checkbox',
                                    className: '',
                                    label: `generalSettings.payByLinkSettings.enable.title`,
                                    description: OnlinePaymentsFE.brand.code + `.generalSettings.payByLinkSettings.enable.description`,
                                    onChange: (value) => handlePayByLinkSettingsChange('enabled', value)
                                },
                                {
                                    name: 'title',
                                    value: activePayByLinkSettings.title !== '' ? activePayByLinkSettings.title
                                        : translationService.translate(OnlinePaymentsFE.brand.code + '.generalSettings.payByLinkSettings.default'),
                                    type: 'text',
                                    className: '',
                                    label: `generalSettings.payByLinkSettings.payByLinkTitle.title`,
                                    description: `generalSettings.payByLinkSettings.payByLinkTitle.description`,
                                    onChange: (value) => handlePayByLinkSettingsChange('title', value)
                                },
                                {
                                    name: 'expirationTime',
                                    value: activePayByLinkSettings.expirationTime,
                                    type: 'number',
                                    className: '',
                                    label: `generalSettings.payByLinkSettings.expirationTime.title`,
                                    description: `generalSettings.payByLinkSettings.expirationTime.description`,
                                    onChange: (value) => handlePayByLinkSettingsChange('expirationTime', value)
                                }
                            ]
                        ),
                        buttonWrapper
                    ])
                ]
            );

            return payByLinkForm;
        }

        const handlePayByLinkSettingsChange = (prop, value) => {
            let payByLinkBtn = payByLinkForm.querySelector('[name="payByLinkBtn"]');
            if (prop === 'expirationTime') {
                let expirationTime = payByLinkForm.querySelector('[name="expirationTime"]');
                if (value < 0 || value > 180) {
                    validator.setError(expirationTime, 'generalSettings.payByLinkSettings.expirationTime.error');
                    payByLinkBtn.disabled = true;

                    return;
                } else {
                    validator.removeError(expirationTime);
                }
            }

            changedPayByLinkSettings[prop] = value;

            handlePayByLinkDependencies(prop, value);

            payByLinkBtn.disabled = changedPayByLinkSettings.enabled === activePayByLinkSettings.enabled &&
                changedPayByLinkSettings.title === activePayByLinkSettings.title &&
                changedPayByLinkSettings.expirationTime === activePayByLinkSettings.expirationTime;
        }

        const handlePayByLinkDependencies = (prop, value) => {
            if (prop === 'enabled') {
                let title = utilities.getAncestor(payByLinkForm.querySelector(
                        '[name="title"]'), 'op-field-wrapper'),
                    expirationTime = utilities.getAncestor(
                        payByLinkForm.querySelector('[name="expirationTime"]'),
                        'op-field-wrapper'
                    );
                if (value === true) {
                    utilities.showElement(title);
                    utilities.showElement(expirationTime);
                } else {
                    utilities.hideElement(title);
                    utilities.hideElement(expirationTime);
                }
            }
        }

        const showDisconnectModal = () => {
            showConfirmModal().then((confirmed) => confirmed && handleDisconnect());
        }

        const showConfirmModal = () => {
            return new Promise((resolve) => {
                const modal = components.Modal.create({
                    title: `generalSettings.disconnect.disconnectModal.title`,
                    className: `op-disconnect-modal`,
                    content: [generator.createElement('p', '', `generalSettings.disconnect.disconnectModal.message`)],
                    footer: true,
                    buttons: [
                        {
                            type: 'secondary',
                            label: 'general.cancel',
                            onClick: () => {
                                modal.close();
                                resolve(false);
                            }
                        },
                        {
                            type: 'primary',
                            className: 'opm--destructive',
                            label: 'general.confirm',
                            onClick: () => {
                                modal.close();
                                resolve(true);
                            }
                        }
                    ]
                });

                modal.open();
            });
        }

        function handleDisconnect() {
            utilities.showLoader();
            api.get(configuration.disconnectUrl).then((response) => {
                    OnlinePaymentsFE.state.display();
                }
            )
                .finally(() => {
                    utilities.hideLoader();
                });
        }

        const renderDisconnectForm = () => {
            if (disconnectForm) {
                templateService.clearComponent(disconnectForm);
            }

            let disconnectBtn = generator.createButton({
                    type: 'primary',
                    name: 'saveButton',
                    className: 'opm--destructive',
                    disabled: false,
                    label: 'generalSettings.disconnect.disconnect',
                    onClick: () => {
                        showDisconnectModal();
                    }
                }
            );
            const buttonWrapper = generator.createElement('div', 'op-button-wrapper');
            buttonWrapper.append(disconnectBtn);

            disconnectForm = generator.createElement('div', 'op-card', '', null, [
                generator.createElement('div', 'op-card-title', '', null, [
                    generator.createElement(
                        'h1',
                        '',
                        translationService.translate('generalSettings.disconnect.title')
                    ),
                    generator.createElement(
                        'p',
                        '',
                        translationService.translate(OnlinePaymentsFE.brand.code + '.generalSettings.disconnect.description')
                    )
                ]),
                generator.createElement('div', 'op-card-content', '', null, [
                    generator.createElement(
                        'p',
                        '',
                        translationService.translate('generalSettings.disconnect.warning')
                    ),
                    buttonWrapper
                ])
            ]);

            return disconnectForm;
        }

        const handleLogSettingsChange = (prop, value) => {
            let logBtn = logForm.querySelector('[name="logSettingsBtn"]');
            if (prop === 'logDays') {
                let days = logForm.querySelector('[name="logDays"]');
                if (value < 1 || value > 14) {
                    validator.setError(days, 'generalSettings.logSettings.logDays.error');
                    logBtn.disabled = true;

                    return;
                } else {
                    validator.removeError(days);
                }
            }

            changedLogSettings[prop] = value;

            logBtn.disabled = changedLogSettings.logDays === activeLogSettings.logDays &&
                changedLogSettings.debugMode === activeLogSettings.debugMode;
        }

        const handleSaveSuccess = (section) => {
            showFlashMessage('generalSettings.' + section + '.message', 'success');
            templateService.clearMainPage();
            renderPage().then(r => {
            });
        }

        const handleSaveFailure = (section) => {
            showFlashMessage('generalSettings.' + section + '.error', 'error');
        }

        /**
         * Displays the flash message.
         *
         * @param {string} message Translation key or message
         * @param {'success' | 'error'} status
         */
        const showFlashMessage = (message, status = 'success') => {
            utilities.createToasterMessage(message, status);
        };
    }

    OnlinePaymentsFE.SettingsController = SettingsController;
})();
