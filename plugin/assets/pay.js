/**
 * Intents Checkout pay page.
 *
 * The browser only chooses a token and a refund address, shows the deposit details, and can send
 * the deposit from an EVM wallet. Whether the order is paid is decided on the server (OrderSync),
 * which polls NEAR Intents; this page just displays that state.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'intents-checkout-pay' );
	if ( ! root ) {
		return;
	}
	var cfg = JSON.parse( root.getAttribute( 'data-config' ) );
	var state = cfg.state;
	var assets = [];
	var view = null;
	var pollTimer = null;
	var tickTimer = null;
	var busy = false;
	var forcePicker = false;

	var QUICK = [
		[ 'USDC', 'base' ], [ 'ETH', 'base' ], [ 'USDC', 'arb' ], [ 'USDC', 'eth' ], [ 'USDT', 'tron' ],
		[ 'SOL', 'sol' ], [ 'USDC', 'sol' ], [ 'BTC', 'btc' ], [ 'wNEAR', 'near' ], [ 'USDC', 'near' ],
	];

	// ---------- helpers ----------
	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) { return; }
			if ( k === 'class' ) { node.className = v; } else if ( k.indexOf( 'on' ) === 0 ) { node.addEventListener( k.slice( 2 ), v ); } else { node.setAttribute( k, v === true ? '' : v ); }
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) { return; }
			node.appendChild( typeof c === 'string' || typeof c === 'number' ? document.createTextNode( String( c ) ) : c );
		} );
		return node;
	}

	function url( path, query ) {
		var base = cfg.restUrl;
		var q = Object.keys( query || {} ).map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( query[ k ] ); } ).join( '&' );
		if ( base.indexOf( '?rest_route=' ) !== -1 ) { // Plain permalinks.
			return base + path + ( q ? '&' + q : '' );
		}
		return base.replace( /\/$/, '' ) + path + ( q ? '?' + q : '' );
	}

	function api( method, path, body ) {
		var opts = { method: method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
		var query = method === 'GET' ? { key: cfg.key } : null;
		if ( body ) { opts.body = JSON.stringify( Object.assign( { key: cfg.key }, body ) ); }
		return fetch( url( path, query ), opts ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) { throw new Error( j.message || 'Request failed (' + r.status + ')' ); }
				return j;
			} );
		} );
	}

	function copyButton( text, label ) {
		return h( 'button', { type: 'button', class: 'ic-copy', onclick: function ( e ) {
			var btn = e.currentTarget;
			( navigator.clipboard ? navigator.clipboard.writeText( text ) : Promise.reject() ).then( function () {
				btn.textContent = 'Copied'; setTimeout( function () { btn.textContent = label || 'Copy'; }, 1500 );
			} ).catch( function () { window.prompt( 'Copy:', text ); } );
		} }, label || 'Copy' );
	}

	function qr( text ) {
		try {
			var code = window.qrcode( 0, 'M' );
			code.addData( text );
			code.make();
			var box = h( 'div', { class: 'ic-qr', 'aria-hidden': 'true' } );
			box.innerHTML = code.createSvgTag( { cellSize: 4, margin: 2, scalable: true } ); // Generated SVG markup, no user input.
			return box;
		} catch ( e ) {
			return null;
		}
	}

	function fmtTime( s ) {
		s = Math.max( 0, Math.floor( s ) );
		return Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
	}

	function setError( msg ) {
		var el = root.querySelector( '.ic-error' );
		if ( el ) { el.textContent = msg || ''; el.hidden = ! msg; }
	}

	function hasEvmWallet() {
		return typeof window.ethereum !== 'undefined' && window.ethereum && typeof window.ethereum.request === 'function';
	}

	// ---------- views ----------
	function render() {
		var q = state.quote;
		var next;
		if ( state.order.paid ) { next = 'paid'; }
		else if ( ! q || forcePicker ) { next = 'picker'; }
		else { next = 'pay'; }

		if ( next === 'picker' && view === 'picker' ) { return; } // Keep the form as the user left it.
		view = next;
		root.innerHTML = '';
		if ( next === 'paid' ) { root.appendChild( paidView() ); stopPolling(); setTimeout( function () { window.location.href = state.order.thankYouUrl; }, 3000 ); return; }
		if ( next === 'picker' ) { root.appendChild( pickerView() ); loadAssets(); return; }
		root.appendChild( payView( q ) );
		startTicking();
	}

	// The badge follows the quote being shown; in the picker it follows the store's current mode.
	function header( sandbox ) {
		return h( 'div', { class: 'ic-head' }, [
			h( 'h3', null, 'Pay ' + cfg.total + ' with crypto' ),
			sandbox ? h( 'span', { class: 'ic-badge ic-badge--sandbox' }, 'SANDBOX: simulated payments' ) : null,
		] );
	}

	function pickerView() {
		var wrap = h( 'div', { class: 'ic-card' } );
		wrap.appendChild( header( state.sandbox ) );
		wrap.appendChild( h( 'p', { class: 'ic-muted' }, 'Pick the token you hold. The store receives stablecoins on NEAR; NEAR Intents does the conversion.' ) );
		wrap.appendChild( h( 'div', { class: 'ic-quick', id: 'ic-quick' } ) );
		var chainSel = h( 'select', { id: 'ic-chain', onchange: function () { fillTokens(); } } );
		var tokenSel = h( 'select', { id: 'ic-token', onchange: updateRefundLabel } );
		wrap.appendChild( h( 'div', { class: 'ic-row' }, [
			h( 'label', null, [ h( 'span', null, 'Network' ), chainSel ] ),
			h( 'label', null, [ h( 'span', null, 'Token' ), tokenSel ] ),
		] ) );
		var refund = h( 'input', { id: 'ic-refund', type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'Your address on this network' } );
		var connect = h( 'button', { type: 'button', class: 'ic-secondary', id: 'ic-connect', hidden: true, onclick: connectWallet }, 'Use my wallet address' );
		wrap.appendChild( h( 'label', { class: 'ic-block' }, [ h( 'span', { id: 'ic-refund-label' }, 'Refund address' ), h( 'div', { class: 'ic-inline' }, [ refund, connect ] ) ] ) );
		wrap.appendChild( h( 'p', { class: 'ic-muted ic-small' }, 'If the payment cannot be completed, NEAR Intents sends your funds back to this address automatically. Leave it empty and the store refunds you by hand.' ) );
		wrap.appendChild( h( 'p', { class: 'ic-error', hidden: true, role: 'alert' } ) );
		wrap.appendChild( h( 'button', { type: 'button', class: 'ic-primary', id: 'ic-get', onclick: getQuote }, 'Get payment details' ) );
		if ( state.quote && state.quote.state === 'refunded' ) {
			wrap.insertBefore( h( 'p', { class: 'ic-note' }, 'Your previous payment was refunded to ' + state.quote.refundTo + '. You can try again.' ), wrap.children[ 1 ] );
		}
		return wrap;
	}

	function loadAssets() {
		if ( assets.length ) { fillChains(); return; }
		api( 'GET', '/assets' ).then( function ( r ) { assets = r.assets || []; fillChains(); } ).catch( function ( e ) { setError( 'Could not load tokens: ' + e.message ); } );
	}

	function fillChains() {
		var chainSel = document.getElementById( 'ic-chain' );
		if ( ! chainSel ) { return; }
		var seen = {};
		chainSel.innerHTML = '';
		assets.forEach( function ( a ) {
			if ( ! seen[ a.chain ] ) { seen[ a.chain ] = 1; chainSel.appendChild( h( 'option', { value: a.chain }, a.chainName ) ); }
		} );
		var quick = document.getElementById( 'ic-quick' );
		quick.innerHTML = '';
		QUICK.forEach( function ( pair ) {
			var a = assets.filter( function ( x ) { return x.symbol === pair[ 0 ] && x.chain === pair[ 1 ]; } )[ 0 ];
			if ( a ) {
				quick.appendChild( h( 'button', { type: 'button', class: 'ic-chip', onclick: function () { select( a.assetId ); } }, a.symbol + ' · ' + a.chainName ) );
			}
		} );
		fillTokens();
	}

	function fillTokens( preselect ) {
		var chain = document.getElementById( 'ic-chain' ).value;
		var tokenSel = document.getElementById( 'ic-token' );
		tokenSel.innerHTML = '';
		assets.filter( function ( a ) { return a.chain === chain; } ).forEach( function ( a ) {
			tokenSel.appendChild( h( 'option', { value: a.assetId, selected: a.assetId === preselect }, a.symbol ) );
		} );
		updateRefundLabel();
	}

	function select( assetId ) {
		var a = assets.filter( function ( x ) { return x.assetId === assetId; } )[ 0 ];
		if ( ! a ) { return; }
		document.getElementById( 'ic-chain' ).value = a.chain;
		fillTokens( assetId );
		Array.prototype.forEach.call( root.querySelectorAll( '.ic-chip' ), function ( c ) { c.classList.toggle( 'is-active', c.textContent === a.symbol + ' · ' + a.chainName ); } );
	}

	function currentAsset() {
		var id = ( document.getElementById( 'ic-token' ) || {} ).value;
		return assets.filter( function ( x ) { return x.assetId === id; } )[ 0 ];
	}

	function updateRefundLabel() {
		var a = currentAsset();
		var label = document.getElementById( 'ic-refund-label' );
		var connect = document.getElementById( 'ic-connect' );
		if ( ! a || ! label ) { return; }
		label.textContent = 'Your ' + a.chainName + ' address for refunds';
		connect.hidden = ! ( a.evm && hasEvmWallet() );
	}

	function connectWallet() {
		window.ethereum.request( { method: 'eth_requestAccounts' } ).then( function ( accounts ) {
			if ( accounts && accounts[ 0 ] ) { document.getElementById( 'ic-refund' ).value = accounts[ 0 ]; }
		} ).catch( function ( e ) { setError( e.message || 'Wallet connection refused' ); } );
	}

	function getQuote() {
		var a = currentAsset();
		if ( ! a || busy ) { return; }
		busy = true;
		setError( '' );
		var btn = document.getElementById( 'ic-get' );
		btn.disabled = true;
		btn.textContent = 'Asking NEAR Intents for a price…';
		api( 'POST', '/orders/' + cfg.orderId + '/quote', { asset: a.assetId, refund_to: document.getElementById( 'ic-refund' ).value } )
			.then( function ( s ) { state = s; forcePicker = false; view = null; render(); startPolling(); } )
			.catch( function ( e ) { setError( e.message ); btn.disabled = false; btn.textContent = 'Get payment details'; } )
			.then( function () { busy = false; } );
	}

	var STEPS = [
		[ 'waiting', 'Waiting for payment' ],
		[ 'detected', 'Payment detected' ],
		[ 'paid', 'Settled to the store' ],
	];

	function statusText( q ) {
		switch ( q.state ) {
			case 'waiting':
				return q.expired ? 'This price has expired. If you already sent the funds, keep this page open: they will still be detected. Otherwise get a new price.' : 'Waiting for your payment…';
			case 'detected': return 'Payment detected. Settling through NEAR Intents (usually under a minute)…';
			case 'incomplete': return 'We received ' + ( q.depositedFormatted || 'less than required' ) + ' ' + q.symbol + ', which is not enough. Send the rest to the same address before the deadline, otherwise it is refunded to ' + q.refundTo + '.';
			case 'refunded': return 'This payment was refunded to ' + q.refundTo + ( q.refundReason ? ' (' + q.refundReason + ')' : '' ) + '.';
			case 'failed': return 'The payment network reported a problem. The store has been notified. Please do not pay again.';
			case 'review': return 'Your payment is being checked by the store. Please do not pay again.';
			case 'paid': return 'Paid.';
		}
		return q.status;
	}

	function payView( q ) {
		var wrap = h( 'div', { class: 'ic-card' } );
		wrap.appendChild( header( q.mode === 'sandbox' ) );

		var amount = h( 'div', { class: 'ic-amount' }, [
			h( 'div', null, [ 'Send exactly ', h( 'strong', null, q.amountInFormatted + ' ' + q.symbol ), ' on ', h( 'strong', null, q.chainName ) ] ),
			copyButton( q.amountInFormatted, 'Copy amount' ),
		] );
		wrap.appendChild( amount );
		wrap.appendChild( h( 'p', { class: 'ic-muted ic-small' }, ( q.amountInUsd ? '≈ $' + Number( q.amountInUsd ).toFixed( 2 ) + '. ' : '' ) + 'Includes a small buffer for price movement; anything unused is refunded. Minimum accepted: ' + q.minAmountInFormatted + ' ' + q.symbol + '.' ) );

		var addrBox = h( 'div', { class: 'ic-address' }, [
			qr( q.depositAddress ),
			h( 'div', { class: 'ic-address-text' }, [
				h( 'span', { class: 'ic-muted ic-small' }, 'To this one-time ' + q.chainName + ' address' ),
				h( 'code', null, q.depositAddress ),
				copyButton( q.depositAddress, 'Copy address' ),
				q.memo ? h( 'p', { class: 'ic-warn' }, [ 'Memo (required): ', h( 'code', null, q.memo ), ' ', copyButton( q.memo ) ] ) : null,
			] ),
		] );
		wrap.appendChild( addrBox );

		if ( q.state === 'waiting' && ! q.expired && q.evm && q.evm.chainId && hasEvmWallet() && /^0x[0-9a-fA-F]{40}$/.test( q.depositAddress ) ) {
			wrap.appendChild( h( 'button', { type: 'button', class: 'ic-primary', id: 'ic-wallet', onclick: function () { payWithWallet( q ); } }, 'Pay with browser wallet' ) );
		}

		wrap.appendChild( h( 'p', { class: 'ic-error', hidden: true, role: 'alert' } ) );

		var reached = { waiting: 0, expired: 0, incomplete: 1, detected: 1, failed: 1, review: 1, refunded: 1, paid: 2 }[ q.state ] || 0;
		wrap.appendChild( h( 'ol', { class: 'ic-steps' }, STEPS.map( function ( s, i ) {
			return h( 'li', { class: i < reached ? 'is-done' : ( i === reached ? 'is-current' : '' ) }, s[ 1 ] );
		} ) ) );
		wrap.appendChild( h( 'p', { class: 'ic-status ic-status--' + q.state, role: 'status' }, statusText( q ) ) );
		if ( q.state === 'waiting' && ! q.expired ) {
			wrap.appendChild( h( 'p', { class: 'ic-muted ic-small' }, [ 'Price valid for ', h( 'strong', { id: 'ic-countdown' }, fmtTime( q.secondsLeft ) ), '.' ] ) );
		}
		if ( q.originTx && q.originTx.length ) {
			wrap.appendChild( h( 'p', { class: 'ic-small' }, [ 'Your transaction: ' ].concat( q.originTx.map( function ( t ) {
				return t.url ? h( 'a', { href: t.url, target: '_blank', rel: 'noopener' }, t.hash.slice( 0, 12 ) + '…' ) : h( 'code', null, t.hash.slice( 0, 18 ) + '…' );
			} ) ) ) );
		}

		var footer = [
			'The store receives exactly ' + q.amountOutFormatted + ' ' + q.destSymbol + ' on NEAR (' + q.merchant + ').',
		];
		if ( q.fxSource && q.fxRate && q.fxRate !== '1' ) { footer.push( ' Rate: ' + q.fxSource + '.' ); }
		var foot = h( 'p', { class: 'ic-muted ic-small' }, footer.join( '' ) );
		if ( q.explorerUrl ) {
			foot.appendChild( document.createTextNode( ' ' ) );
			foot.appendChild( h( 'a', { href: q.explorerUrl, target: '_blank', rel: 'noopener' }, 'Track on NEAR Intents Explorer' ) );
		}
		wrap.appendChild( foot );

		if ( state.canRequote && ( q.state === 'waiting' || q.state === 'refunded' ) ) {
			wrap.appendChild( h( 'button', { type: 'button', class: 'ic-link', onclick: function () { forcePicker = true; view = null; render(); } }, q.expired || q.state === 'refunded' ? 'Get a new price' : 'Pay with a different token' ) );
		}

		if ( q.mode === 'sandbox' ) {
			var sim = h( 'div', { class: 'ic-sandbox' }, [ h( 'strong', null, 'Sandbox: simulate what NEAR Intents reports' ), h( 'br' ) ] );
			[ [ 'KNOWN_DEPOSIT_TX', 'Deposit seen' ], [ 'SUCCESS', 'Success' ], [ 'INCOMPLETE_DEPOSIT', 'Underpaid' ], [ 'REFUNDED', 'Refunded' ], [ 'FAILED', 'Failed' ] ].forEach( function ( s ) {
				sim.appendChild( h( 'button', { type: 'button', class: 'ic-chip', onclick: function () { simulate( s[ 0 ] ); } }, s[ 1 ] ) );
			} );
			wrap.appendChild( sim );
		}
		return wrap;
	}

	function paidView() {
		var q = state.quote || {};
		return h( 'div', { class: 'ic-card ic-paid' }, [
			h( 'h3', null, 'Payment received ✓' ),
			h( 'p', null, q.amountOutFormatted ? 'The store received ' + q.amountOutFormatted + ' ' + q.destSymbol + ' through NEAR Intents.' : 'Your order is paid.' ),
			h( 'p', { class: 'ic-muted' }, 'Redirecting to your order…' ),
		] );
	}

	// ---------- wallet ----------
	function payWithWallet( q ) {
		var eth = window.ethereum;
		var btn = document.getElementById( 'ic-wallet' );
		setError( '' );
		btn.disabled = true;
		btn.textContent = 'Confirm in your wallet…';
		var chainHex = '0x' + Number( q.evm.chainId ).toString( 16 );
		var from;
		eth.request( { method: 'eth_requestAccounts' } )
			.then( function ( accounts ) { from = accounts[ 0 ]; return eth.request( { method: 'eth_chainId' } ); } )
			.then( function ( current ) {
				if ( String( current ).toLowerCase() === chainHex ) { return null; }
				return eth.request( { method: 'wallet_switchEthereumChain', params: [ { chainId: chainHex } ] } ).catch( function ( e ) {
					if ( e && e.code === 4902 ) { throw new Error( 'Add the ' + q.chainName + ' network to your wallet first.' ); }
					throw e;
				} );
			} )
			.then( function () {
				var amount = BigInt( q.amountIn );
				var tx;
				if ( ! q.evm.contract ) {
					tx = { from: from, to: q.depositAddress, value: '0x' + amount.toString( 16 ) };
				} else {
					var to = q.depositAddress.toLowerCase().replace( /^0x/, '' );
					var data = '0xa9059cbb' + ( '0'.repeat( 64 ) + to ).slice( -64 ) + ( '0'.repeat( 64 ) + amount.toString( 16 ) ).slice( -64 ); // ERC-20 transfer(to, amount)
					tx = { from: from, to: q.evm.contract, data: data, value: '0x0' };
				}
				return eth.request( { method: 'eth_sendTransaction', params: [ tx ] } );
			} )
			.then( function ( hash ) {
				btn.textContent = 'Sent. Waiting for NEAR Intents…';
				return api( 'POST', '/orders/' + cfg.orderId + '/deposit', { tx_hash: hash } );
			} )
			.then( poll )
			.catch( function ( e ) {
				setError( ( e && e.message ) || 'Wallet error' );
				btn.disabled = false;
				btn.textContent = 'Pay with browser wallet';
			} );
	}

	// ---------- sandbox ----------
	function simulate( status ) {
		api( 'POST', '/orders/' + cfg.orderId + '/simulate', { status: status } ).then( function ( s ) { state = s; view = null; render(); } ).catch( function ( e ) { setError( e.message ); } );
	}

	// ---------- polling ----------
	function poll() {
		return api( 'GET', '/orders/' + cfg.orderId ).then( function ( s ) {
			var changed = JSON.stringify( [ s.order, s.quote && s.quote.state, s.quote && s.quote.status, s.quote && s.quote.depositAddress, s.quote && s.quote.expired ] ) !==
				JSON.stringify( [ state.order, state.quote && state.quote.state, state.quote && state.quote.status, state.quote && state.quote.depositAddress, state.quote && state.quote.expired ] );
			state = s;
			if ( changed && ! forcePicker ) { view = null; render(); }
		} ).catch( function () { /* transient network error: keep polling */ } );
	}

	function startPolling() {
		if ( ! pollTimer ) { pollTimer = setInterval( poll, 5000 ); }
	}

	function stopPolling() {
		clearInterval( pollTimer ); pollTimer = null;
		clearInterval( tickTimer ); tickTimer = null;
	}

	function startTicking() {
		if ( tickTimer ) { return; }
		var started = Date.now();
		var left = state.quote ? state.quote.secondsLeft : 0;
		tickTimer = setInterval( function () {
			var el = document.getElementById( 'ic-countdown' );
			if ( ! state.quote ) { return; }
			if ( state.quote.secondsLeft !== left ) { left = state.quote.secondsLeft; started = Date.now(); }
			var remaining = left - ( Date.now() - started ) / 1000;
			if ( el ) { el.textContent = fmtTime( remaining ); }
			if ( remaining <= 0 && ! state.quote.expired ) { poll(); }
		}, 1000 );
	}

	render();
	if ( state.quote && ! state.order.paid ) { startPolling(); }
} )();
