if (!window.OnlinePaymentsFE) {
    window.OnlinePaymentsFE = {};
}

if (!window.OnlinePaymentsFE.components) {
    window.OnlinePaymentsFE.components = {};
}

(function () {
    const preventDefaults = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    /**
     * @typedef VaultTitle
     *
     * @property {string} language
     * @property {string} translation
     */

    /**
     * @typedef AdditionalData
     *
     * @property {VaultTitle[]?} vaultTitleCollection
     * @property {boolean?} instantPayment
     * @property {string?} recurrenceType
     * @property {string?} signatureType
     * @property {int?} sessionTimeout
     * @property {string?} paymentProductId
     * @property {string?} paymentOption
     * @property {string?} logo
     * @property {boolean?} enableGroupCards
     * @property {boolean?} enable3ds
     * @property {boolean?} enforceStrongAuthentication
     * @property {boolean?} enable3dsExemption
     * @property {'no-challenge-requested' | 'low-value' | 'transaction-risk-analysis' | null} exemptionType
     * @property {float?} exemptionLimit
     * @property {string?} flowType
     */

    /**
     * @typedef SlidingModalModel
     *
     * @property {string} paymentProductId
     * @property {string} name
     * @property {boolean} enabled
     * @property {string} translations
     * @property {string[]} integrationTypes
     * @property {string} paymentGroup
     * @property {string} template
     * @property {string} logo
     * @property {string} paymentAction
     * @property {AdditionalData} additionalData
     */

    /**
     * @typedef Language
     *
     * @property {string} code
     * @property {string} logo
     */

    /**
     * @param {SlidingModalModel} configuration
     * @param {string} saveMethodConfigurationUrl
     * @param {Language[]} languages
     *
     * @returns {*|HTMLElement}
     * @constructor
     */
    const SlidingModal = (configuration, saveMethodConfigurationUrl, languages) => {
        const {
            elementGenerator: generator,
            translationService,
            utilities,
            templateService,
            validationService: validator
        } = OnlinePaymentsFE;
        const api = OnlinePaymentsFE.ajaxService;
        /** @type {SlidingModalModel|null} */
        let activeMethod = null;
        /** @type {SlidingModalModel|null} */
        let changedMethod = null;
        /** @type {number} */
        let numberOfChanges = 0;
        let name = null;
        let vaultTitle = null;
        const separateCapture = [
            'hosted_checkout', '302', '5001', '5133', '5129', '5100', 'cards', '320', '3301', '5908',
            '5127', '840', '3203', '5131', '5407', '5404'
        ];

        /**
         * Handles form input field change.
         *
         * @param {string} prop
         * @param {any} value
         * @param {boolean?} additional
         */
        const handleConfigMethodChange = (prop, value, additional) => {
            if (prop !== 'logo') {
                value = OnlinePaymentsFE.sanitize(value);
            }

            const areDifferent = (source, target) => {
                if (Array.isArray(source) && Array.isArray(target)) {
                    return !OnlinePaymentsFE.utilities.compareArrays(source, target);
                }

                return source !== target;
            };

            const areArraysEqual = (prop, additional) => {
                let changed = additional ? changedMethod.additionalData[prop] : changedMethod[prop];
                let active = additional ? activeMethod.additionalData[prop] : activeMethod[prop];

                if (changed.length !== active.length) {
                    numberOfChanges++;
                    return;
                }

                const sorted1 = [...changed].sort((a, b) => a.locale.localeCompare(b.locale));
                const sorted2 = [...active].sort((a, b) => a.locale.localeCompare(b.locale));

                let result = sorted1.every((item, index) => {
                    return item.locale === sorted2[index].locale && item.value === sorted2[index].value;
                });

                if (!result) {
                    numberOfChanges++;
                }
            }

            numberOfChanges = 0;
            if (additional) {
                if (!changedMethod.additionalData) {
                    changedMethod.additionalData = {};
                }

                changedMethod.additionalData[prop] = value;
            } else if (prop === 'logo') {
                changedMethod.logoFile = value;
                numberOfChanges = 1;
            } else {
                changedMethod[prop] = value;
            }

            Object.entries(changedMethod).forEach(([prop, value]) => {
                if (prop === 'additionalData') {
                    Object.entries(changedMethod.additionalData).forEach(([prop, value]) => {
                        if (prop === 'vaultTitleCollection') {
                            areArraysEqual(prop, true);
                        } else if (prop !== 'logo') {
                            areDifferent(activeMethod.additionalData[prop], value) && numberOfChanges++;
                        }
                    });
                } else {
                    if (prop === 'name') {
                        areArraysEqual(prop, false);
                    } else {
                        areDifferent(activeMethod[prop], value) && numberOfChanges++;
                    }
                }
            });

            const btn = document.querySelector('.op-save-btn');
            const hasValidationErrors = form.querySelector('.ops--error') !== null;

            if (numberOfChanges > 0 && !hasValidationErrors) {
                btn.classList.remove('ops--disabled');
            } else {
                btn.classList.add('ops--disabled');
            }
        };

        /**
         * The card authorization mode (final vs pre-authorization) only applies when the payment
         * action is "Authorize" (authorize-only); for "Authorize and capture" it is never sent, so
         * the field is hidden. 'FINAL_AUTHORIZATION' is the authorize-only payment action value.
         *
         * @param {string} paymentActionValue
         */
        const handleAuthorizationModeVisibility = (paymentActionValue) => {
            const field = form.querySelector('[name="authorizationMode"]');
            if (!field) {
                return;
            }

            const wrapper = utilities.getAncestor(field, 'op-field-wrapper');
            if (paymentActionValue === 'FINAL_AUTHORIZATION') {
                utilities.showElement(wrapper);
            } else {
                utilities.hideElement(wrapper);
            }
        };

        const isValid = () => {
            let result = [validateRequiredField(['enabled', 'name'])];

            return !result.includes(false);
        };

        /**
         * Validates the additional form fields.
         *
         * @param {(keyof AdditionalDataConfig | 'name' | 'enabled' | 'paymentAction')[]} fieldNames
         * @returns {boolean[]}
         */
        const validateRequiredField = (fieldNames) => {
            return fieldNames.map((fieldName) =>
                validator.validateRequiredField(form.querySelector(`[name=${fieldName}]`))
            );
        };

        const createHeader = () => {
            const modalHeader = generator.createElement('div', 'op-header-name');
            const logo = generator.createElement('img', 'op-payment-logo');
            logo.src = configuration.logo;
            const nameWrapper = generator.createElement('div', 'op-name-wrapper');
            const name = generator.createElement('p', 'op-payment-method-name', configuration.name[0].value);
            nameWrapper.appendChild(name);
            configuration.integrationTypes.forEach((type) => {
                const typeElement = generator.createElement(
                    'span',
                    'op-integration-type ' + 'op-' + type,
                    'payments.integrationType.' + type
                );
                nameWrapper.appendChild(typeElement);
            });
            const closeButton = generator.createElement('div', 'op-close-button');
            closeButton.addEventListener('click', () => {
                let modal = document.querySelector('.op-sliding-modal');
                let mask = document.querySelector('.op-dark-mask');

                templateService.getMainPage().querySelector('.op-payments-page').classList.remove('op-settings-modal-opened');
                mask.remove();
                modal.remove();
            });

            modalHeader.appendChild(logo);
            modalHeader.appendChild(nameWrapper);
            modalHeader.appendChild(closeButton);

            return modalHeader;
        };

        function getCardsFields() {
            vaultTitle = OnlinePaymentsFE.components.TranslatableLabel.create({
                onChange: () => {
                    handleConfigMethodChange('vaultTitleCollection', vaultTitle.getValues(), true);
                },
                name: 'name',
                languages: languages,
                translations: activeMethod.additionalData.vaultTitleCollection
            });

            let vaultTitleWrapper = generator.createFieldWrapper(
                vaultTitle.element,
                translationService.translate('payments.configure.fields.vaultTitle.label')
            );

            let flowType = generator.createDropdownField({
                name: 'flowType',
                value: activeMethod.additionalData.flowType || 'iframe',
                label: 'payments.configure.fields.flowType.label',
                description: OnlinePaymentsFE.brand.code + '.payments.flowType.description',
                options: [
                    {
                        label: 'payments.configure.fields.flowType.iframe',
                        value: 'iframe'
                    },
                    {
                        label: 'payments.configure.fields.flowType.redirect',
                        value: 'redirect'
                    }
                ],
                onChange: (value) => handleCardsSettingsChange('flowType', value)
            });

            return [
                ...get3dsSettings(),
                vaultTitleWrapper,
                flowType,
                ...generator.createFormFields([
                    {
                        name: 'enableGroupCards',
                        value: configuration.additionalData.enableGroupCards,
                        type: 'checkbox',
                        className: '',
                        label: `payments.configure.fields.enableGroupCards.label`,
                        description: `payments.configure.fields.enableGroupCards.description`,
                        onChange: (value) => handleConfigMethodChange('enableGroupCards', value === true, true)
                    }
                ])
            ];
        }

        function get3dsSettings() {
            return [
                ...generator.createFormFields([
                    {
                        name: 'enable3ds',
                        value: configuration.additionalData.enable3ds,
                        type: 'checkbox',
                        className: '',
                        label: `generalSettings.cardsSettings.enable3ds.title`,
                        description: `generalSettings.cardsSettings.enable3ds.description`,
                        onChange: (value) => handleCardsSettingsChange('enable3ds', value)
                    },
                    {
                        name: 'enforceStrongAuthentication',
                        value: configuration.additionalData.enforceStrongAuthentication,
                        type: 'checkbox',
                        className: '',
                        label: `generalSettings.cardsSettings.enforceStrongAuthentication.title`,
                        onChange: (value) => handleCardsSettingsChange('enforceStrongAuthentication', value)
                    },
                    {
                        name: 'enable3dsExemption',
                        value: configuration.additionalData.enable3dsExemption,
                        type: 'checkbox',
                        className: '',
                        label: `generalSettings.cardsSettings.3dsExemption.title`,
                        description: `generalSettings.cardsSettings.3dsExemption.description`,
                        onChange: (value) => handleCardsSettingsChange('enable3dsExemption', value)
                    }
                ]),
                generator.createDropdownField({
                    name: 'exemptionType',
                    value: configuration.additionalData.exemptionType,
                    label: 'generalSettings.cardsSettings.exemptionType.title',
                    description: 'generalSettings.cardsSettings.exemptionType.description',
                    options: [
                        {
                            label: 'generalSettings.cardsSettings.exemptionType.values.noChallenge',
                            value: 'no-challenge-requested'
                        },
                        {
                            label: 'generalSettings.cardsSettings.exemptionType.values.lowValue',
                            value: 'low-value'
                        },
                        {
                            label: 'generalSettings.cardsSettings.exemptionType.values.transactionRisk',
                            value: 'transaction-risk-analysis'
                        }
                    ],
                    onChange: (value) => handleCardsSettingsChange('exemptionType', value)
                }),
                generator.createNumberField({
                    name: 'exemptionLimit',
                    value: configuration.additionalData.exemptionLimit,
                    label: 'generalSettings.cardsSettings.exemptionLimit.title',
                    description: 'generalSettings.cardsSettings.exemptionLimit.description',
                    onChange: (value) => handleCardsSettingsChange('exemptionLimit', value)
                }),
            ];
        }

        function handle3dsSpecificChanges(prop, value) {
            if (prop === 'enable3ds') {
                let enforceStrongAuthentication = utilities.getAncestor(form.querySelector(
                        '[name="enforceStrongAuthentication"]'), 'op-field-wrapper'),
                    enableExemption = utilities.getAncestor(
                        form.querySelector('[name="enable3dsExemption"]'),
                        'op-field-wrapper'
                    ),
                    exemptionType = utilities.getAncestor(
                        form.querySelector('[name="exemptionType"]'),
                        'op-field-wrapper'
                    ),
                    exemptionLimit = utilities.getAncestor(
                        form.querySelector('[name="exemptionLimit"]'),
                        'op-field-wrapper'
                    );
                if (value === true) {
                    utilities.showElement(enforceStrongAuthentication);

                    if (!changedMethod.additionalData.enforceStrongAuthentication) {
                        utilities.showElement(enableExemption);

                        if (changedMethod.additionalData.enable3dsExemption === true) {
                            utilities.showElement(exemptionType);
                            utilities.showElement(exemptionLimit);
                        }
                    }
                } else {
                    utilities.hideElement(enforceStrongAuthentication);
                    utilities.hideElement(enableExemption);
                    utilities.hideElement(exemptionType);
                    utilities.hideElement(exemptionLimit);
                }
            }

            if (prop === 'enforceStrongAuthentication') {
                let enableExemption = utilities.getAncestor(
                    form.querySelector('[name="enable3dsExemption"]'),
                    'op-field-wrapper'
                );
                let exemptionType = utilities.getAncestor(
                    form.querySelector('[name="exemptionType"]'),
                    'op-field-wrapper'
                );
                let exemptionLimit = utilities.getAncestor(
                    form.querySelector('[name="exemptionLimit"]'),
                    'op-field-wrapper'
                );
                if (value === true) {
                    utilities.hideElement(enableExemption);
                    utilities.hideElement(exemptionType);
                    utilities.hideElement(exemptionLimit);
                } else {
                    if (changedMethod.additionalData.enable3ds === true) {
                        utilities.showElement(enableExemption);
                    }

                    if (changedMethod.additionalData.enable3dsExemption === true) {
                        utilities.showElement(exemptionType);
                        utilities.showElement(exemptionLimit);
                    }
                }
            }

            if (prop === 'enable3dsExemption') {
                let exemptionType = utilities.getAncestor(
                        form.querySelector('[name="exemptionType"]'),
                        'op-field-wrapper'
                    ),
                    exemptionLimit = utilities.getAncestor(
                        form.querySelector('[name="exemptionLimit"]'),
                        'op-field-wrapper'
                    ),
                    enableExemption = utilities.getAncestor(
                        form.querySelector('[name="enable3dsExemption"]'),
                        'op-field-wrapper'
                    );
                if (value === true && changedMethod.additionalData.enforceStrongAuthentication === false
                    && changedMethod.additionalData.enable3ds === true) {
                    let warning = generator.createElement(
                        'div',
                        'ops-warning',
                        '',
                        null,
                        [
                            generator.createElement(
                                'span',
                                'ops-warning-message',
                                translationService.translate('generalSettings.cardsSettings.3dsExemption.warning')
                            )
                        ]
                    );
                    enableExemption.append(warning);

                    utilities.showElement(exemptionType);
                    utilities.showElement(exemptionLimit);
                } else {
                    let warning = enableExemption.querySelector('.ops-warning');

                    if (warning) {
                        warning.remove();
                    }

                    utilities.hideElement(exemptionType);
                    utilities.hideElement(exemptionLimit);
                }
            }

            if (prop === 'exemptionType') {
                let exemptionLimit = form.querySelector('[name="exemptionLimit"]');
                validator.removeError(exemptionLimit);

                if (value === 'low-value') {
                    if (exemptionLimit.value > 30) {
                        exemptionLimit.value = activeMethod.additionalData.exemptionType === 'low-value' ?
                            activeMethod.additionalData.exemptionLimit : 30;
                        changedMethod.additionalData.exemptionLimit = activeMethod.additionalData.exemptionType === 'low-value' ?
                            activeMethod.additionalData.exemptionLimit : 30;
                    }
                } else {
                    exemptionLimit.value = activeMethod.additionalData.exemptionType === value ?
                        activeMethod.additionalData.exemptionLimit : 100;
                    changedMethod.additionalData.exemptionLimit = activeMethod.additionalData.exemptionType === value ?
                        activeMethod.additionalData.exemptionLimit : 100;
                }
            }

            if (prop === 'exemptionLimit') {
                let exemptionLimit = form.querySelector('[name="exemptionLimit"]');

                if (changedMethod.additionalData.exemptionType === 'low-value') {
                    if (value < 0 || value > 30) {
                        validator.setError(exemptionLimit, 'generalSettings.cardsSettings.exemptionLimit.errorLowValue');
                    } else {
                        validator.removeError(exemptionLimit);
                    }
                }

                if (changedMethod.additionalData.exemptionType !== 'low-value') {
                    if (value < 0 || value > 100) {
                        validator.setError(exemptionLimit, 'generalSettings.cardsSettings.exemptionLimit.errorTransactionRisk');
                    } else {
                        validator.removeError(exemptionLimit);
                    }
                }
            }
        }

        function handleCardsSettingsChange(prop, value) {
            handle3dsSpecificChanges(prop, value);

            handleConfigMethodChange(prop, value, true);
        }

        function getInstantPaymentField() {
            return generator.createFormFields([
                {
                    name: 'instantPayment',
                    value: configuration.additionalData.instantPayment,
                    type: 'checkbox',
                    className: '',
                    label: `payments.configure.fields.instantPayment.label`,
                    description: `payments.configure.fields.instantPayment.description`,
                    onChange: (value) => handleConfigMethodChange('instantPayment', value, true)
                }
            ]);
        }

        function getSepaFields() {
            // Recurrence type is hardcoded to UNIQUE (one-off payments only), so it is not exposed
            // as a merchant-configurable field - only the signature type is configurable.
            return [
                createSignatureTypeWarning(),
                ...generator.createFormFields([createSignatureTypeField()])
            ];
        }

        // Yellow warning above the Direct Debit Signature Type field: a signature type the merchant's
        // partner does not accept makes Worldline refuse SEPA Direct Debit transactions.
        function createSignatureTypeWarning() {
            return generator.createElement(
                'div',
                'ops-warning',
                '',
                null,
                [
                    generator.createElement(
                        'span',
                        'ops-warning-message',
                        'payments.configure.fields.directDebitSignature.warning'
                    )
                ]
            );
        }

        // Direct Debit Signature Type dropdown (SEPA mandate signature type); the "?" hint lists the
        // signature types each partner accepts. The SEPA Direct Debit panel and the Hosted Checkout
        // panel both use it, each for its own value.
        function createSignatureTypeField() {
            return {
                name: 'signatureType',
                value: configuration.additionalData.signatureType,
                type: 'dropdown',
                label: 'payments.configure.fields.directDebitSignature.label',
                description: 'payments.configure.fields.directDebitSignature.description',
                options: [
                    {label: 'payments.configure.fields.directDebitSignature.SMS', value: 'SMS'},
                    {label: 'payments.configure.fields.directDebitSignature.UNSIGNED', value: 'UNSIGNED'},
                    {label: 'payments.configure.fields.directDebitSignature.TICK_BOX', value: 'TICK_BOX'},
                    {label: 'payments.configure.fields.directDebitSignature.AIS', value: 'AIS'}
                ],
                onChange: (value) => handleConfigMethodChange('signatureType', value, true)
            };
        }

        // No Session Timeout field: the hosted payment page session follows General settings > Session timeout.
        function getIntersolveFields() {
            return generator.createFormFields(
                [
                    {
                        name: 'paymentProductId',
                        value: configuration.additionalData.paymentProductId,
                        type: 'number',
                        label: 'payments.configure.fields.productId.label',
                        description: 'payments.configure.fields.productId.description',
                        onChange: (value) => handleConfigMethodChange('paymentProductId', value, true)
                    }
                ]
            );
        }

        /**
         * Product ids whose payment option title is their own rather than the shared one. Oney 3x and
         * Oney 4x are separate products and the merchant agrees a separate option code with the
         * acquirer for each, so one shared title would not say which contract the field belongs to.
         */
        const ONEY_OPTION_TITLES = {
            5146: 'payments.configure.fields.paymentOption.oney3x',
            5147: 'payments.configure.fields.paymentOption.oney4x'
        };

        function getOneyFields() {
            return generator.createFormFields(
                [
                    {
                        name: 'paymentOption',
                        value: configuration.additionalData.paymentOption,
                        type: 'text',
                        label: ONEY_OPTION_TITLES[configuration.paymentProductId]
                            || 'payments.configure.fields.paymentOption.label',
                        description: 'payments.configure.fields.paymentOption.description',
                        onChange: (value) => handleConfigMethodChange('paymentOption', value, true)
                    }
                ]
            );
        }

        function getDescriptorFields() {
            return generator.createFormFields(
                [
                    {
                        name: 'descriptor',
                        value: configuration.additionalData.descriptor,
                        type: 'text',
                        maxLength: 15,
                        label: 'payments.configure.fields.descriptor.label',
                        description: 'payments.configure.fields.descriptor.description',
                        onChange: (value) => handleConfigMethodChange('descriptor', value, true)
                    }
                ]
            );
        }

        function getHostedCheckoutFields() {
            return [
                ...generator.createFormFields(
                    [
                        {
                            name: 'logo',
                            value: configuration.additionalData.logo,
                            type: 'file',
                            supportedMimeTypes: ['image/jpeg', 'image/jpg', 'image/png'],
                            label: 'payments.configure.fields.logo.label',
                            description: OnlinePaymentsFE.brand.code + '.payments.hostedCheckout.logo.description',
                            onChange: (value) => handleConfigMethodChange('logo', value, false)
                        },
                        {
                            name: 'enableGroupCards',
                            value: configuration.additionalData.enableGroupCards,
                            type: 'checkbox',
                            className: '',
                            label: `payments.configure.fields.enableGroupCards.label`,
                            description: `payments.configure.fields.enableGroupCards.description`,
                            onChange: (value) => handleConfigMethodChange('enableGroupCards', value === true, true)
                        }
                    ]
                ),
                ...get3dsSettings(),
                // Mandate signature type for SEPA Direct Debit picked on the hosted checkout or payment link
                // page: the Hosted Checkout configuration's own value, separate from the SEPA Direct Debit one.
                createSignatureTypeWarning(),
                ...generator.createFormFields([createSignatureTypeField()])
            ];
        }

        function getGooglePayFields() {
            return get3dsSettings();
        }

        function handleSave(event) {
            preventDefaults(event);
            utilities.showLoader();

            const data = {
                ...changedMethod,
                additionalData: utilities.cloneObject(changedMethod.additionalData)
            };

            if (!isValid()) {
                utilities.hideLoader();
                return;
            }

            const postData = new FormData();
            Object.entries(data).forEach(([key, value]) => {
                if (key !== 'logoFile' && key !== 'additionalData' && key !== 'name') {
                    postData.append(key, value);
                }
            });

            postData.append('name', JSON.stringify(data.name));
            postData.append('additionalData', JSON.stringify(data.additionalData || null));

            if (data.logoFile) {
                postData.set('logo', data.logoFile, data.logoFile.name);
            }

            const url = saveMethodConfigurationUrl.replace('{methodId}', activeMethod.paymentProductId);
            api.post(url, postData, {
                'Content-Type': 'multipart/form-data'
            }).then(() => {
                utilities.createToasterMessage('payments.configure.methodSaved');
                utilities.hideLoader();
                OnlinePaymentsFE.state.display();
            }).catch((error) => {
                utilities.createToasterMessage(error, 'error');
                utilities.hideLoader();
            });
        }

        const createDefaultForm = () => {
            const form = generator.createElement('form', 'op-form');

            const enabled = generator.createFormFields([
                {
                    name: 'enabled',
                    value: configuration.enabled,
                    type: 'checkbox',
                    className: '',
                    label: `payments.configure.fields.enableOnCheckout.label`,
                    description: `payments.configure.fields.enableOnCheckout.description`,
                    onChange: (value) => handleConfigMethodChange('enabled', value, false)
                }
            ]);
            form.appendChild(enabled[0]);

            name = OnlinePaymentsFE.components.TranslatableLabel.create({
                onChange: () => {
                    handleConfigMethodChange('name', name.getValues(), false);
                },
                name: 'name',
                languages: languages,
                translations: activeMethod.name
            });
            let nameWrapper = generator.createFieldWrapper(
                name.element,
                translationService.translate('payments.configure.fields.name.label'),
                translationService.translate('payments.configure.fields.name.description')
            );
            form.appendChild(nameWrapper);

            const template = generator.createFormFields(
                [
                    {
                        name: 'template',
                        value: configuration.template,
                        type: 'text',
                        label: 'payments.configure.fields.templateName.label',
                        description: 'payments.configure.fields.templateName.description',
                        onChange: (value) => handleConfigMethodChange('template', value, false)
                    }
                ]
            );

            form.appendChild(template[0]);

            // Per-method checkout button title: changes the pay-button label when this method is selected.
            const buttonTitle = generator.createFormFields([
                {
                    name: 'buttonTitle',
                    value: configuration.additionalData.buttonTitle,
                    type: 'text',
                    label: 'payments.configure.fields.buttonTitle.label',
                    description: 'payments.configure.fields.buttonTitle.description',
                    onChange: (value) => handleConfigMethodChange('buttonTitle', value, true)
                }
            ]);
            form.append(...buttonTitle);

            if (separateCapture.includes(activeMethod.paymentProductId)) {
                let paymentAction = generator.createDropdownField({
                    name: 'paymentAction',
                    value: activeMethod.paymentAction || 'authorize-capture',
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
                    onChange: (value) => {
                        handleConfigMethodChange('paymentAction', value);
                        handleAuthorizationModeVisibility(value);
                    }
                });

                form.appendChild(paymentAction);

                if (configuration.paymentProductId === 'cards') {
                    let authorizationMode = generator.createDropdownField({
                        name: 'authorizationMode',
                        value: activeMethod.additionalData.authorizationMode || 'FINAL_AUTHORIZATION',
                        label: 'payments.configure.fields.authorizationMode.label',
                        description: 'payments.configure.fields.authorizationMode.description',
                        options: [
                            {
                                label: 'payments.configure.fields.authorizationMode.final',
                                value: 'FINAL_AUTHORIZATION'
                            },
                            {
                                label: 'payments.configure.fields.authorizationMode.preAuthorization',
                                value: 'PRE_AUTHORIZATION'
                            }
                        ],
                        onChange: (value) => handleConfigMethodChange('authorizationMode', value, true)
                    });

                    form.appendChild(authorizationMode);
                }
            }

            if (configuration.paymentProductId === 'cards') {
                let cardFields = getCardsFields();
                form.append(...cardFields);
            }

            if (configuration.paymentProductId === '5408') {
                let instantPayment = getInstantPaymentField();
                form.appendChild(instantPayment[0]);
            }

            if (configuration.paymentProductId === '771') {
                let sepaFields = getSepaFields();
                form.append(...sepaFields);
            }

            if (configuration.paymentProductId === '5700') {
                let intersolveFields = getIntersolveFields();
                form.append(...intersolveFields);
            }

            // `5146/5147` are Oney 3x and Oney 4x; `5111/5112/5125/5127` the older products. Oney 3x4x
            // (`5148`) takes no payment option.
            if (['5111', '5112', '5127', '5125', '5146', '5147']
                .includes(configuration.paymentProductId)) {
                let oneyFields = getOneyFields();
                form.append(...oneyFields);
            }

            // Sofinco / Linxo: optional soft descriptor sent on the payment (order.references.descriptor).
            if (['5131', '5003'].includes(configuration.paymentProductId)) {
                let descriptorFields = getDescriptorFields();
                form.append(...descriptorFields);
            }

            if (configuration.paymentProductId === 'hosted_checkout') {
                let hostedCheckoutFields = getHostedCheckoutFields();
                form.append(...hostedCheckoutFields);
            }

            if (configuration.paymentProductId === '320') {
                let googlePay = getGooglePayFields();
                form.append(...googlePay);
            }

            const buttons = generator.createElement('div', 'op-button-bar');
            const saveButton = generator.createElement(
                'button',
                'op-save-btn op-button opt--primary ops--disabled',
                translationService.translate('general.saveChanges')
            );
            const cancelButton = generator.createElement(
                'button',
                'op-button opt--secondary',
                translationService.translate('general.cancel')
            );
            cancelButton.addEventListener('click', function (event) {
                preventDefaults(event);
                let mask = document.querySelector('.op-dark-mask');
                templateService.getMainPage().querySelector('.op-payments-page').classList.remove('op-settings-modal-opened');
                mask.remove();
                document.querySelector('.op-sliding-modal').remove();
            });

            saveButton.addEventListener('click', function (event) {
                handleSave(event);
            });

            buttons.appendChild(saveButton);
            buttons.appendChild(cancelButton);
            form.appendChild(buttons);

            return form;
        };

        activeMethod = utilities.cloneObject(configuration);
        changedMethod = utilities.cloneObject(configuration);
        const modal = generator.createElement('div', 'op-sliding-modal');

        const paymentGroup = generator.createElement('div', 'op-payment-group',
            'payments.paymentGroups.' + configuration.paymentGroup);
        const form = createDefaultForm();

        if (activeMethod.paymentProductId === 'cards' || activeMethod.paymentProductId === 'hosted_checkout'
            || activeMethod.paymentProductId === '320') {
            handle3dsSpecificChanges('enable3ds', activeMethod.additionalData.enable3ds);
            handle3dsSpecificChanges('enforceStrongAuthentication', activeMethod.additionalData.enforceStrongAuthentication);
            handle3dsSpecificChanges('enable3dsExemption', activeMethod.additionalData.enable3dsExemption);

            const paymentActionField = form.querySelector('[name="paymentAction"]');
            if (paymentActionField) {
                handleAuthorizationModeVisibility(paymentActionField.value);
            }
        }

        modal.appendChild(createHeader());
        modal.appendChild(paymentGroup);
        modal.appendChild(form);

        return modal;
    };

    OnlinePaymentsFE.components.SlidingModal = {
        /**
         * @param {SlidingModalModel} config
         * @param {string} saveMethodConfigurationUrl
         * @param {Language[]} languages
         * @returns {HTMLElement}
         */
        create: (config, saveMethodConfigurationUrl, languages) => SlidingModal(config, saveMethodConfigurationUrl, languages)
    };
})();
