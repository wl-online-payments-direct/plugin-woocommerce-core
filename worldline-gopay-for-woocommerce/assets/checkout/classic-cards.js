( function ( $ ) {
	'use strict';

	var cfg = window.worldlineopClassic || {};
	if ( ! $ || ! cfg.tokenField ) {
		return;
	}

	var submitting = false;

	/**
	 * Tokenization sessions by session URL, kept outside the DOM. WooCommerce replaces the payment
	 * fragment on updated_checkout (also right after page load), which drops anything stored on the
	 * iframe element; without this cache every replacement fetched a new session.
	 */
	var sessions = {};

	/**
	 * Resolves once the Worldline tokenizer library is available, injecting it on demand.
	 *
	 * The SDK is loaded once per page and shared across brands. The library bundles an
	 * iframe-resizer that, from a single instance, gives each iframe a distinct id and validates
	 * each against its own iframe origin - so a Worldline and a CAWL iframe coexist. Loading a
	 * second copy spins up a second resizer that numbers ids from the same base, the two collide,
	 * and neither iframe renders. The in-flight load is kept on a window global (whose name the
	 * brand build never rewrites) so the two brands' separate bundles share one load.
	 */
	function ensureTokenizer() {
		if ( typeof window.Tokenizer !== 'undefined' ) {
			return Promise.resolve();
		}
		if ( window.__opHostedTokenizerSdk ) {
			return window.__opHostedTokenizerSdk;
		}
		if ( ! cfg.sdkUrl ) {
			return Promise.reject( new Error( 'no sdk url' ) );
		}

		window.__opHostedTokenizerSdk = new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = cfg.sdkUrl;
			s.onload = resolve;
			s.onerror = function ( event ) {
				window.__opHostedTokenizerSdk = null;
				reject( event );
			};
			document.head.appendChild( s );
		} );

		return window.__opHostedTokenizerSdk;
	}

	/** Card container of the currently selected payment method, or null. */
	function activeContainer() {
		var $selected = $( 'input[name="payment_method"]:checked' );
		if ( ! $selected.length ) {
			return null;
		}
		var $c = $( '.payment_box.payment_method_' + $selected.val() ).find( '.worldlineop-classic-card' ).first();

		return $c.length ? $c : null;
	}

	/** Reads the tokenizer instance stored on a container's iframe element. */
	function clientOf( $c ) {
		return $c.find( '.worldlineop-classic-card-iframe' ).prop( 'wlClient' ) || null;
	}

	/** Selected saved-card row (its <li>), or null when "use a new card"/nothing is selected. */
	function selectedTokenLi( $c ) {
		var $checked = $( 'input[name="wc-' + $c.data( 'gateway' ) + '-payment-token"]:checked' );
		if ( ! $checked.length || $checked.val() === 'new' ) {
			return null;
		}
		var $li = $checked.closest( 'li' );

		return $li.length ? $li : null;
	}

	/** True when the card container is not already sitting under its target position. */
	function needsReposition( $c ) {
		var $li = selectedTokenLi( $c );
		if ( $li ) {
			return ! $.contains( $li[ 0 ], $c[ 0 ] );
		}
		var $list = $( '.payment_box.payment_method_' + $c.data( 'gateway' ) + ' .woocommerce-SavedPaymentMethods' ).first();

		return $list.length > 0 && ! $list.next().is( $c );
	}

	/**
	 * Moves the card container directly under the selected saved card, or back below the saved-cards
	 * list for the new-card option. Call only with a torn-down container: moving a live iframe
	 * reparents and reloads it, so the selection flow empties the iframe first, then remounts here.
	 */
	function positionContainer( $c ) {
		var $li = selectedTokenLi( $c );
		if ( $li ) {
			if ( ! $.contains( $li[ 0 ], $c[ 0 ] ) ) {
				$li.append( $c );
			}
			return;
		}
		var $list = $( '.payment_box.payment_method_' + $c.data( 'gateway' ) + ' .woocommerce-SavedPaymentMethods' ).first();
		if ( $list.length && ! $list.next().is( $c ) ) {
			$c.insertAfter( $list );
		}
	}

	/** The session endpoint URL for a card container. */
	function sessionUrl( $c ) {
		var gateway = $c.data( 'gateway' );
		var hasSavedCards = $( 'input[name="wc-' + gateway + '-payment-token"]' ).filter( function () {
			return this.value !== 'new';
		} ).length > 0;
		var url = String( $c.data( 'session-url' ) );
		var product = $c.data( 'product' );
		if ( product ) {
			url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'productId=' + encodeURIComponent( product );
		}
		if ( hasSavedCards ) {
			url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'savedCards=1';
		}

		return url;
	}

	/**
	 * One tokenization session per session URL and page: the first call fetches it, later calls
	 * (fragment replacements, switching saved cards) reuse it. A failed fetch is not cached.
	 */
	function sessionFor( url ) {
		if ( ! sessions[ url ] ) {
			sessions[ url ] = fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce },
			} )
				.then( function ( r ) {
					return r.json();
				} )
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

	/**
	 * Mounts the tokenizer iframe into a container exactly once and keeps it: switching
	 * between new/saved cards is done through useToken, not by re-creating the iframe.
	 *
	 * The container is resolved by id at construction time, never from the captured
	 * jQuery object: WooCommerce replaces the payment DOM on updated_checkout, so a fetch
	 * started against the old element must mount into whichever element now owns the id.
	 * The iframe-child check guards against two in-flight fetches racing onto that element.
	 */
	function mount( $c ) {
		var $iframe = $c.find( '.worldlineop-classic-card-iframe' );
		if ( ! $iframe.length || $iframe.attr( 'data-wl-mounted' ) ) {
			return;
		}
		$iframe.attr( 'data-wl-mounted', '1' );

		var id = $iframe.attr( 'id' );

		sessionFor( sessionUrl( $c ) )
			.then( function ( session ) {
				return ensureTokenizer().then( function () {
					var el = document.getElementById( id );
					if ( ! session || ! session.success || ! session.url || ! el ) {
						$iframe.removeAttr( 'data-wl-mounted' );
						return;
					}

					var $live = $( el );
					if ( $live.find( 'iframe' ).length > 0 ) {
						// A racing fetch already mounted into this element.
						return;
					}

					var prior = $live.prop( 'wlClient' );
					if ( prior && typeof prior.destroy === 'function' ) {
						try {
							prior.destroy();
						} catch ( err ) {}
					}

					var client = new window.Tokenizer( session.url, id, {
						hideCardholderName: false,
						storePermanently: false,
					} );
					$live.prop( 'wlClient', client );
					$live.prop( 'wlTokens', session.wcTokens || {} );

					// useToken must run after initialize, or the saved-card CVC view
					// doesn't take and the full new-card form shows instead.
					Promise.resolve( client.initialize() )
						.then( function () {
							syncToken( $live.closest( '.worldlineop-classic-card' ) );
						} )
						.catch( function () {} );
				} );
			} )
			.catch( function () {
				$iframe.removeAttr( 'data-wl-mounted' );
			} );
	}

	/**
	 * Shows the card field and points the tokenizer at the selected sub-option. When the
	 * saved-card radios are present but none is picked yet (neither a saved card nor "use a
	 * new payment method"), the field stays hidden until the shopper chooses one. With no
	 * saved cards at all there are no sub-radios, so the new-card form shows immediately.
	 */
	function syncToken( $c ) {
		var $iframe = $c.find( '.worldlineop-classic-card-iframe' );
		var client = $iframe.prop( 'wlClient' );
		if ( ! client || typeof client.useToken !== 'function' ) {
			return;
		}

		var gateway = $c.data( 'gateway' );
		var $radios = $( 'input[name="wc-' + gateway + '-payment-token"]' );
		var $checked = $radios.filter( ':checked' );

		if ( $radios.length && ! $checked.length ) {
			$iframe.hide();
			return;
		}

		$iframe.show();

		var dbId = $checked.length ? String( $checked.val() ) : 'new';
		var tokens = $iframe.prop( 'wlTokens' ) || {};
		var useNewCard = dbId === 'new' || ! tokens[ dbId ];

		// Minimum height for the new-card form only (not the saved-card CVC field).
		$iframe.toggleClass( 'worldlineop-classic-card-new', useNewCard );

		if ( ! useNewCard ) {
			client.useToken( tokens[ dbId ] );
		} else {
			client.useToken();
		}
	}

	/**
	 * Ungrouped checkout renders one card container per brand, each with hidden inputs of the
	 * same name (tokenization id + device fields). The classic form submits them all and PHP
	 * keeps only the last, so an empty field from another brand would override the active
	 * one. Disable the other containers' inputs so only the paying method's values are sent.
	 */
	function isolateActiveContainer( $c ) {
		$( '.worldlineop-classic-card' ).not( $c ).find( 'input' ).prop( 'disabled', true );
	}

	/** Re-enables every card container's inputs (after a failed submit, before a retry). */
	function releaseContainers() {
		$( '.worldlineop-classic-card' ).find( 'input' ).prop( 'disabled', false );
	}

	/** Fills the hidden 3-D Secure device inputs from the browser. */
	function fillDevice( $c ) {
		var f = cfg.deviceFields || {};
		var set = function ( name, value ) {
			if ( name ) {
				$c.find( 'input[name="' + name + '"]' ).val( String( value ) );
			}
		};
		set( f.colorDepth, window.screen ? window.screen.colorDepth : '' );
		set( f.screenHeight, window.screen ? window.screen.height : '' );
		set( f.screenWidth, window.screen ? window.screen.width : '' );
		set( f.timezoneOffset, new Date().getTimezoneOffset() );
		set( f.javaEnabled, typeof navigator.javaEnabled === 'function' ? navigator.javaEnabled() : false );
	}

	/**
	 * Tokenizes before the order is placed. Holds the click, submits the tokenization,
	 * writes the id + device data, then replays the click so WooCommerce proceeds. The
	 * replayed click sees the filled id and falls straight through. No mounted client
	 * (failed session) falls through to the hosted-checkout fallback.
	 */
	function onPlaceOrder( e ) {
		var $c = activeContainer();
		if ( ! $c ) {
			return;
		}

		// Field hidden means no sub-option chosen: let WooCommerce validate / fall back.
		if ( ! $c.find( '.worldlineop-classic-card-iframe' ).is( ':visible' ) ) {
			return;
		}

		var $hidden = $c.find( 'input[name="' + cfg.tokenField + '"]' );
		if ( $hidden.val() ) {
			fillDevice( $c );
			isolateActiveContainer( $c );
			return;
		}

		// A tokenization is already in flight; ignore further clicks until it settles.
		if ( submitting ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}

		var client = clientOf( $c );
		if ( ! client ) {
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();
		submitting = true;

		client.submitTokenization()
			.then( function ( result ) {
				submitting = false;
				if ( result && result.success && result.hostedTokenizationId ) {
					$hidden.val( result.hostedTokenizationId );
					fillDevice( $c );
					isolateActiveContainer( $c );
					$( e.target ).trigger( 'click' );
				} else if ( window.wc_add_notice === undefined && typeof window.alert === 'function' ) {
					window.alert( cfg.cardErrorMessage );
				}
			} )
			.catch( function () {
				submitting = false;
			} );
	}

	/**
	 * Remounts a container. After a failed checkout (`newSession`) the hosted tokenization id is
	 * spent, so the session is dropped and a fresh one fetched before the shopper retries, otherwise
	 * the spent id is replayed and the retry fails. When only the saved-card selection moved the
	 * field, the unused session is mounted again in the new position.
	 */
	function resetContainer( $c, newSession ) {
		if ( newSession ) {
			delete sessions[ sessionUrl( $c ) ];
		}

		var $iframe = $c.find( '.worldlineop-classic-card-iframe' );
		var client = $iframe.prop( 'wlClient' );
		if ( client && typeof client.destroy === 'function' ) {
			try {
				client.destroy();
			} catch ( err ) {}
		}

		$iframe.prop( 'wlClient', null ).prop( 'wlTokens', null ).removeAttr( 'data-wl-mounted' ).empty();
		$c.find( 'input[name="' + cfg.tokenField + '"]' ).val( '' );
		positionContainer( $c );
		mount( $c );
	}

	$( function () {
		// Mount only the selected method's card container. With two Worldline brands on the
		// page (e.g. Worldline + CAWL), mounting every container at once makes both tokenizer
		// iframes initialize in the same tick; the shared SDK cannot do that and the second one
		// silently fails to render. The shopper selects one method at a time, so mounting just
		// the active container keeps initializations sequential. An already-mounted container
		// stays mounted (its iframe just lives in the hidden payment box) - so switching back is
		// still instant.
		var attach = function () {
			var $c = activeContainer();
			if ( ! $c ) {
				return;
			}
			// Position before the first mount; never move a live iframe (moving reloads it).
			if ( ! $c.find( '.worldlineop-classic-card-iframe' ).attr( 'data-wl-mounted' ) ) {
				positionContainer( $c );
			}
			mount( $c );
		};

		$( document.body ).on( 'updated_checkout payment_method_selected', attach );
		$( document.body ).on( 'checkout_error', function () {
			submitting = false;
			releaseContainers();
			var $c = activeContainer();
			if ( $c ) {
				resetContainer( $c, true );
			}
		} );
		$( document ).on( 'change', 'input[name^="wc-"][name$="-payment-token"]', function () {
			var $c = activeContainer();
			if ( ! $c ) {
				return;
			}
			// A different selection moves the CVC field under it; the iframe must be remounted in its
			// new position (moving would reload it), so reset. Same position -> just re-point the token.
			if ( needsReposition( $c ) ) {
				resetContainer( $c, false );
			} else {
				syncToken( $c );
			}
		} );
		$( document ).on( 'click', '#place_order', onPlaceOrder );

		attach();
	} );
} )( window.jQuery );
