( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings ? window.wc.wcSettings.getSetting : null;
	var createElement = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var useState = window.wp.element.useState;
	var ids = ( window.worldlineopBlocks && window.worldlineopBlocks.methods ) || [];

	var CONTAINER_ID = 'worldlineop-card-tokenizer';
	var REAUTH_CONTAINER_ID = 'worldlineop-card-reauth';

	/**
	 * Browser fingerprint values for 3-D Secure, keyed by the POST field names the
	 * server expects (data.deviceFields). Returns {} when no field map was provided.
	 */
	function collectDeviceData( data ) {
		var fields = data.deviceFields;
		if ( ! fields ) {
			return {};
		}

		var values = {};
		values[ fields.colorDepth ] = String( window.screen ? window.screen.colorDepth : '' );
		values[ fields.screenHeight ] = String( window.screen ? window.screen.height : '' );
		values[ fields.screenWidth ] = String( window.screen ? window.screen.width : '' );
		values[ fields.timezoneOffset ] = String( new Date().getTimezoneOffset() );
		values[ fields.javaEnabled ] = String(
			typeof navigator.javaEnabled === 'function' ? navigator.javaEnabled() : false
		);

		return values;
	}

	/**
	 * The surcharge notice element, or null when the merchant hasn't enabled surcharging.
	 */
	function surchargeNotice( data ) {
		return data.surchargeNotice
			? createElement( 'p', { className: 'worldlineop-surcharge-notice' }, data.surchargeNotice )
			: null;
	}

	/**
	 * Whether the checkout is anywhere in its submit lifecycle. The checkoutStatus
	 * props miss the before/after-processing phases, during which mounting a fresh
	 * session must also be avoided (a session request racing the order-received
	 * redirect writes the stale cart back over the cleared one).
	 */
	function checkoutBusy() {
		if ( ! window.wp.data || typeof window.wp.data.select !== 'function' ) {
			return false;
		}

		var checkout = window.wp.data.select( 'wc/store/checkout' );

		return !! ( checkout && typeof checkout.isIdle === 'function' && ! checkout.isIdle() );
	}

	/**
	 * Resolves once the Worldline tokenizer library is available. The SDK script is
	 * normally enqueued with the payment method; this injects it on demand as a
	 * fallback so the card form never depends on script ordering.
	 */
	function ensureTokenizer( sdkUrl ) {
		if ( typeof window.Tokenizer !== 'undefined' ) {
			return Promise.resolve();
		}
		if ( window.__opHostedTokenizerSdk ) {
			return window.__opHostedTokenizerSdk;
		}
		if ( ! sdkUrl ) {
			return Promise.reject( new Error( 'Tokenizer SDK is unavailable.' ) );
		}

		window.__opHostedTokenizerSdk = new Promise( function ( resolve, reject ) {
			var script = document.createElement( 'script' );
			script.src = sdkUrl;
			script.addEventListener( 'load', function () {
				if ( typeof window.Tokenizer !== 'undefined' ) {
					resolve();
				} else {
					window.__opHostedTokenizerSdk = null;
					reject( new Error( 'Tokenizer SDK did not initialize.' ) );
				}
			} );
			script.addEventListener( 'error', function ( event ) {
				window.__opHostedTokenizerSdk = null;
				reject( event );
			} );
			document.head.appendChild( script );
		} );

		return window.__opHostedTokenizerSdk;
	}

	/**
	 * Tokenization sessions by session URL. Switching payment methods or saved cards reuses the
	 * unused session (the tokenizer is only mounted again); a checkout attempt spends it, so it is
	 * dropped as soon as checkout starts processing.
	 */
	var sessions = {};

	function sessionFor( url ) {
		if ( ! sessions[ url ] ) {
			var request = window.wp.apiFetch
				? window.wp.apiFetch( { url: url, method: 'POST' } )
				: window.fetch( url, { method: 'POST', credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} );

			sessions[ url ] = Promise.resolve( request )
				.then( function ( session ) {
					if ( ! session || ! session.success || ! session.url ) {
						delete sessions[ url ];
					}
					return session;
				} )
				.catch( function ( err ) {
					delete sessions[ url ];
					throw err;
				} );
		}

		return sessions[ url ];
	}

	function savedCardsSessionUrl( data ) {
		return data.sessionUrl + ( data.sessionUrl.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'savedCards=1';
	}

	/**
	 * Card form for the iframe flow: creates a hosted-tokenization session, renders the
	 * Worldline tokenizer iframe in it, and on place-order submits the tokenization and
	 * passes the resulting id to the gateway through the block payment data.
	 */
	function CardFields( props ) {
		var data = props.data;
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var status = props.checkoutStatus || {};
		var busy = !! ( status.isProcessing || status.isComplete );
		var clientRef = useRef( null );
		var wasBusyRef = useRef( false );
		var loadFailed = useState( false );
		var failed = loadFailed[ 0 ];
		var setFailed = loadFailed[ 1 ];

		useEffect( function () {
			if ( busy || checkoutBusy() ) {
				wasBusyRef.current = true;
				delete sessions[ data.sessionUrl ];
				return undefined;
			}

			if ( clientRef.current ) {
				if ( ! wasBusyRef.current ) {
					return undefined;
				}

				if ( typeof clientRef.current.destroy === 'function' ) {
					clientRef.current.destroy();
				}
				clientRef.current = null;
			} else if ( failed && ! wasBusyRef.current ) {
				return undefined;
			}
			wasBusyRef.current = false;
			setFailed( false );

			var cancelled = false;

			// wp.apiFetch carries the X-WP-Nonce, which logged-in sessions require.
			sessionFor( data.sessionUrl )
				.then( function ( session ) {
					if ( cancelled ) {
						return;
					}

					if ( ! session || ! session.success || ! session.url ) {
						setFailed( true );
						return;
					}

					return ensureTokenizer( data.sdkUrl ).then( function () {
						if ( cancelled ) {
							return;
						}

						clientRef.current = new window.Tokenizer( session.url, CONTAINER_ID, {
							hideCardholderName: false,
							storePermanently: false,
						} );
						var initResult = clientRef.current.initialize();
						if ( initResult && typeof initResult.catch === 'function' ) {
							initResult.catch( function () {
								if ( ! cancelled ) {
									setFailed( true );
								}
							} );
						}
					} );
				} )
				.catch( function () {
					if ( ! cancelled ) {
						setFailed( true );
					}
				} );

			return function () {
				cancelled = true;
			};
		}, [ busy ] );

		useEffect( function () {
			return function () {
				if ( clientRef.current && typeof clientRef.current.destroy === 'function' ) {
					clientRef.current.destroy();
				}
				clientRef.current = null;
			};
		}, [] );

		useEffect( function () {
			var unsubscribe = eventRegistration.onPaymentSetup( function () {
				if ( ! clientRef.current ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: data.loadErrorMessage,
					};
				}

				return clientRef.current
					.submitTokenization()
					.then( function ( result ) {
						if ( ! result || ! result.success || ! result.hostedTokenizationId ) {
							return {
								type: emitResponse.responseTypes.ERROR,
								message: data.cardErrorMessage,
							};
						}

						var paymentMethodData = collectDeviceData( data );
						paymentMethodData[ data.tokenizationIdField ] = result.hostedTokenizationId;

						return {
							type: emitResponse.responseTypes.SUCCESS,
							meta: { paymentMethodData: paymentMethodData },
						};
					} )
					.catch( function () {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: data.cardErrorMessage,
						};
					} );
			} );

			return unsubscribe;
		}, [ eventRegistration, emitResponse ] );

		if ( failed ) {
			return createElement( 'div', null, data.loadErrorMessage );
		}

		// The style tag lives outside the container so the tokenizer cannot wipe it.
		return createElement(
			'div',
			{ style: { width: '100%' } },
			surchargeNotice( data ),
			// The minimum height keeps the save-card text visible on narrow screens, where the
			// iframe resizer leaves the new-card form shorter than its content.
			createElement( 'style', null, '#' + CONTAINER_ID + ' iframe { border: none; width: 100%; min-height: 525px; }' ),
			createElement( 'div', { id: CONTAINER_ID } )
		);
	}

	// WooCommerce renders savedTokenComponent below the whole saved-cards list, so the shared CVC
	// re-auth iframe would sit under all cards. positionReauthContainer creates that container (once)
	// and moves it directly under the currently selected saved-card radio row.
	function selectedTokenOption( wcTokenId ) {
		var input = wcTokenId
			? document.querySelector( '.wc-block-components-radio-control__input[value="' + wcTokenId + '"]' )
			: null;
		if ( ! input ) {
			return null;
		}

		return input.closest( '.wc-block-components-radio-control-accordion-option' )
			|| input.closest( '.wc-block-components-radio-control__option' );
	}

	function positionReauthContainer( wcTokenId ) {
		var container = document.getElementById( REAUTH_CONTAINER_ID );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.id = REAUTH_CONTAINER_ID;
			container.style.width = '100%';
		}

		var option = selectedTokenOption( wcTokenId );
		if ( option && container.parentNode !== option ) {
			option.appendChild( container );
		}

		return container;
	}

	function removeReauthContainer() {
		var container = document.getElementById( REAUTH_CONTAINER_ID );
		if ( container && container.parentNode ) {
			container.parentNode.removeChild( container );
		}
	}

	/**
	 * CVC re-authentication for a saved card: mounts the tokenizer iframe on a session
	 * created with the customer's stored tokens, preselects the chosen card
	 * (Tokenizer useToken), and submits the tokenization on place-order. When no
	 * session or token mapping is available it emits no tokenization id, which makes
	 * the gateway fall back to the hosted checkout page.
	 */
	function SavedCardReauth( props ) {
		var data = props.data;
		var wcTokenId = props.token !== undefined && props.token !== null ? String( props.token ) : '';
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var status = props.checkoutStatus || {};
		var busy = !! ( status.isProcessing || status.isComplete );
		var clientRef = useRef( null );
		var wasBusyRef = useRef( false );
		var mountedTokenRef = useRef( '' );
		var observerRef = useRef( null );
		var fallbackState = useState( false );
		var fallback = fallbackState[ 0 ];
		var setFallback = fallbackState[ 1 ];

		useEffect( function () {
			if ( busy || checkoutBusy() ) {
				wasBusyRef.current = true;
				delete sessions[ savedCardsSessionUrl( data ) ];
				return undefined;
			}

			if ( clientRef.current ) {
				if ( ! wasBusyRef.current && mountedTokenRef.current === wcTokenId ) {
					return undefined;
				}

				if ( typeof clientRef.current.destroy === 'function' ) {
					clientRef.current.destroy();
				}
				clientRef.current = null;
			} else if ( fallback && ! wasBusyRef.current && mountedTokenRef.current === wcTokenId ) {
				return undefined;
			}
			wasBusyRef.current = false;
			setFallback( false );

			if ( observerRef.current ) {
				observerRef.current.disconnect();
				observerRef.current = null;
			}
			removeReauthContainer();

			mountedTokenRef.current = wcTokenId;

			var cancelled = false;
			sessionFor( savedCardsSessionUrl( data ) )
				.then( function ( session ) {
					if ( cancelled ) {
						return;
					}

					var worldlineToken = session && session.wcTokens ? session.wcTokens[ wcTokenId ] : null;
					if ( ! session || ! session.success || ! session.url || ! worldlineToken ) {
						setFallback( true );
						return;
					}

					return ensureTokenizer( data.sdkUrl ).then( function () {
						if ( cancelled ) {
							return;
						}

						positionReauthContainer( wcTokenId );
						var option = selectedTokenOption( wcTokenId );
						if ( window.MutationObserver && option && option.parentNode ) {
							observerRef.current = new window.MutationObserver( function () {
								positionReauthContainer( wcTokenId );
							} );
							observerRef.current.observe( option.parentNode, { childList: true, subtree: true } );
						}

						clientRef.current = new window.Tokenizer( session.url, REAUTH_CONTAINER_ID, {
							hideCardholderName: false,
							storePermanently: false,
						} );
						var initResult = clientRef.current.initialize();
						if ( initResult && typeof initResult.catch === 'function' ) {
							initResult.catch( function () {
								if ( ! cancelled ) {
									setFallback( true );
								}
							} );
						}
						clientRef.current.useToken( worldlineToken );
					} );
				} )
				.catch( function () {
					if ( ! cancelled ) {
						setFallback( true );
					}
				} );

			return function () {
				cancelled = true;
			};
		}, [ wcTokenId, busy ] );

		useEffect( function () {
			return function () {
				if ( clientRef.current && typeof clientRef.current.destroy === 'function' ) {
					clientRef.current.destroy();
				}
				clientRef.current = null;
				if ( observerRef.current ) {
					observerRef.current.disconnect();
					observerRef.current = null;
				}
				removeReauthContainer();
			};
		}, [] );

		useEffect( function () {
			var unsubscribe = eventRegistration.onPaymentSetup( function () {
				if ( fallback || ! clientRef.current ) {
					// No CVC session - send the token so the hosted-checkout fallback knows the card.
					var fallbackData = { token: wcTokenId, isSavedToken: true };
					fallbackData[ 'wc-' + data.name + '-payment-token' ] = wcTokenId;

					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: { paymentMethodData: fallbackData },
					};
				}

				return clientRef.current
					.submitTokenization()
					.then( function ( result ) {
						if ( ! result || ! result.success || ! result.hostedTokenizationId ) {
							return {
								type: emitResponse.responseTypes.ERROR,
								message: data.cardErrorMessage,
							};
						}

						var paymentMethodData = collectDeviceData( data );
						paymentMethodData[ data.tokenizationIdField ] = result.hostedTokenizationId;

						return {
							type: emitResponse.responseTypes.SUCCESS,
							meta: { paymentMethodData: paymentMethodData },
						};
					} )
					.catch( function () {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: data.cardErrorMessage,
						};
					} );
			} );

			return unsubscribe;
		}, [ eventRegistration, emitResponse, fallback, wcTokenId ] );

		if ( fallback ) {
			return surchargeNotice( data );
		}

		var styles =
			'#' + REAUTH_CONTAINER_ID + ' iframe { border: none; width: 100%; }' +
			'.wc-block-components-radio-control__option:has(#' + REAUTH_CONTAINER_ID + ') .wc-block-components-radio-control__input,' +
			'.wc-block-components-radio-control-accordion-option:has(#' + REAUTH_CONTAINER_ID + ') .wc-block-components-radio-control__input' +
			' { top: 27px; transform: translateY(-50%); }';

		return createElement(
			'div',
			{ style: { width: '100%' } },
			surchargeNotice( data ),
			createElement( 'style', null, styles )
		);
	}

	/**
	 * Blocks re-initializes the payment store while the order is being placed and may
	 * move the selection off the shopper's saved card. Remember the saved-card choice
	 * made while the checkout is idle and restore it when processing ends without a
	 * redirect (the failed-payment path).
	 */
	function keepSavedCardSelection() {
		if ( ! window.wp.data || ! window.wp.data.subscribe ) {
			return;
		}

		var select = window.wp.data.select;
		var dispatch = window.wp.data.dispatch;
		var remembered = null;
		var wasProcessing = false;
		var restoresLeft = 0;

		function restore() {
			var actions = dispatch( 'wc/store/payment' );
			var payment = select( 'wc/store/payment' );
			if ( ! actions || typeof actions.__internalSetActivePaymentMethod !== 'function' ) {
				return;
			}

			var paymentData = {};
			var current = payment && typeof payment.getPaymentMethodData === 'function'
				? payment.getPaymentMethodData()
				: null;
			if ( current ) {
				Object.keys( current ).forEach( function ( key ) {
					paymentData[ key ] = current[ key ];
				} );
			}

			paymentData.token = remembered.token;
			paymentData.payment_method = remembered.method;
			paymentData.isSavedToken = true;
			paymentData[ 'wc-' + remembered.method + '-payment-token' ] = remembered.token;
			actions.__internalSetActivePaymentMethod( remembered.method, paymentData );
		}

		window.wp.data.subscribe( function () {
			var payment = select( 'wc/store/payment' );
			var checkout = select( 'wc/store/checkout' );
			if ( ! payment || ! checkout || typeof payment.getActivePaymentMethod !== 'function' ) {
				return;
			}

			var processing = ( typeof checkout.isProcessing === 'function' && checkout.isProcessing() )
				|| ( typeof checkout.isBeforeProcessing === 'function' && checkout.isBeforeProcessing() )
				|| ( typeof checkout.isAfterProcessing === 'function' && checkout.isAfterProcessing() );

			var method = payment.getActivePaymentMethod();
			var token = typeof payment.getActiveSavedToken === 'function' ? String( payment.getActiveSavedToken() || '' ) : '';
			var deviates = remembered && remembered.token
				&& ( method !== remembered.method || token !== remembered.token );

			if ( processing ) {
				if ( ! wasProcessing ) {
					wasProcessing = true;
					// Capped so a disagreement with Blocks can never loop.
					restoresLeft = 5;
				}

				if ( deviates && restoresLeft > 0 ) {
					restoresLeft--;
					restore();
				}

				return;
			}

			if ( wasProcessing ) {
				wasProcessing = false;

				if ( deviates ) {
					restore();
				}

				return;
			}

			if ( token && ids.indexOf( method ) >= 0 ) {
				remembered = { method: method, token: token };
			} else if ( method ) {
				remembered = null;
			}
		} );
	}

	keepSavedCardSelection();

	ids.forEach( function ( id ) {
		var data = getSetting ? getSetting( id + '_data', null ) : null;
		if ( ! data ) {
			return;
		}

		var label = data.title || id;
		var description = data.description || '';
		var icon = data.icon || '';

		var labelNode = icon
			? createElement(
				'span',
				{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
				createElement( 'img', { src: icon, alt: label, style: { height: '24px', width: 'auto' } } ),
				createElement( 'span', null, label )
			)
			: label;

		// Card methods render the notice inside the component; others get it prepended here.
		var content = data.cardsIframe
			? createElement( CardFields, { data: data } )
			: createElement( 'div', null, surchargeNotice( data ), description );

		registerPaymentMethod( {
			name: id,
			label: labelNode,
			ariaLabel: label,

			// Custom checkout button text (Payment button title). Omitted when empty so the
			// WooCommerce default "Place order" label is used.
			placeOrderButtonLabel: data.orderButtonText ? String( data.orderButtonText ) : undefined,

			// Show this method only while Worldline offers it for the current cart. WooCommerce
			// passes the cart's available methods (the Store API `payment_methods` list, built from
			// get_available_payment_gateways() -> our is_available(), which queries Worldline by
			// billing country / currency / amount) and re-runs canMakePayment when the billing
			// address or totals change, so the shown methods follow the billing address. Falls back
			// to visible when the list is empty/absent, so a valid method is never hidden mid-load.
			canMakePayment: function ( args ) {
				var available = args && Array.isArray( args.paymentMethods ) ? args.paymentMethods : null;

				if ( ! available || available.length === 0 ) {
					return true;
				}

				return available.indexOf( id ) !== -1;
			},
			content: content,
			edit: createElement( 'div', null, description ),
			savedTokenComponent: data.cardsIframe ? createElement( SavedCardReauth, { data: data } ) : null,
			supports: {
				features: data.supports || [ 'products' ],
				// The save-card consent checkbox is rendered inside the Worldline iframe.
				showSavedCards: !! data.showSavedCards,
				showSaveOption: false,
			},
		} );
	} );
} )();
