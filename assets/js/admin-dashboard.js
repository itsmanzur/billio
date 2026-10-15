( function () {
	'use strict';

	var data = window.billioDashboard;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n;
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var root = document.getElementById( 'billio-dash-root' );
	if ( ! root ) {
		return;
	}

	/* ---------------------------------------------------------------
	 * Formatting helpers
	 * ------------------------------------------------------------- */

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str === undefined || str === null ? '' : str );
		return div.innerHTML;
	}

	// For values placed inside a quoted HTML attribute (href=, data-*=,
	// aria-label=, title=, placeholder=), never escapeHtml(): that's a
	// textContent->innerHTML round-trip, which only escapes the five
	// characters HTML TEXT NODES need (and only turns " into &quot; as a
	// side effect of innerHTML serialization, not reliably across all
	// values). A value that already contains an HTML entity — such as a
	// PHP-built download URL, which must NOT be pre-entity-decoded a
	// second time here, see Dashboard::raw_download_url() on the PHP side —
	// must not be escaped again by that path. This is an explicit
	// character-replace instead, covering exactly the characters that are
	// unsafe inside a double-quoted attribute value: & < > " '.
	function escapeAttr( str ) {
		return String( str === undefined || str === null ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function fmtMoney( n ) {
		var decimals = data.currency.decimals;
		var fixed = Math.abs( n ).toFixed( decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, data.currency.thousandSep );
		var amount = parts.join( data.currency.decimalSep );
		var sign = n < 0 ? '-' : '';
		var symbol = data.currency.symbol;

		switch ( data.currency.position ) {
			case 'right':
				return sign + amount + symbol;
			case 'left_space':
				return sign + symbol + ' ' + amount;
			case 'right_space':
				return sign + amount + ' ' + symbol;
			default:
				return sign + symbol + amount;
		}
	}

	function fmtDate( tsSeconds ) {
		return new Date( tsSeconds * 1000 ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function fmtDateFull( tsSeconds ) {
		return new Date( tsSeconds * 1000 ).toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric' } );
	}

	function t( key ) {
		return ( i18n && i18n[ key ] ) || key;
	}

	function tf( key ) {
		var str = t( key );
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return str.replace( /%(?:(\d+)\$)?([ds])/g, function ( match, p1 ) {
			if ( p1 ) {
				var idx = parseInt( p1, 10 ) - 1;
				return args[ idx ] !== undefined ? args[ idx ] : match;
			}
			return args[ i++ ] !== undefined ? args[ i - 1 ] : match;
		} );
	}

	/* ---------------------------------------------------------------
	 * Toast
	 * ------------------------------------------------------------- */

	// Appended to the wrapper (.billio-dash), not document.body: the CSS
	// custom properties (--billio-ink, --billio-brand, etc.) these rely on
	// are scoped to .billio-dash, and position:fixed still escapes it
	// visually regardless of DOM nesting. Appended to the wrap, not
	// #billio-dash-root itself, since buildSkeleton() replaces root's
	// entire innerHTML and would otherwise delete these nodes.
	var dashWrap = root.parentElement;

	var toastEl = document.createElement( 'div' );
	toastEl.className = 'billio-toast';
	toastEl.setAttribute( 'role', 'status' );
	toastEl.setAttribute( 'aria-live', 'polite' );
	toastEl.innerHTML = '<span class="billio-toast-icon"></span><span class="billio-toast-text"></span>';
	dashWrap.appendChild( toastEl );
	var toastTimer = null;

	function toast( message, isError ) {
		toastEl.querySelector( '.billio-toast-icon' ).innerHTML = isError ? '<span class="billio-err-icon">✕</span>' : '<span class="billio-ok-icon">✓</span>';
		toastEl.querySelector( '.billio-toast-text' ).textContent = message;
		toastEl.classList.add( 'billio-show' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			toastEl.classList.remove( 'billio-show' );
		}, 2400 );
	}

	/* ---------------------------------------------------------------
	 * Modal (focus trap, Esc, overlay click, focus return)
	 * ------------------------------------------------------------- */

	var ov = document.createElement( 'div' );
	ov.className = 'billio-ov';
	ov.innerHTML = '<div class="billio-modal" role="dialog" aria-modal="true"></div>';
	dashWrap.appendChild( ov );
	var modalEl = ov.querySelector( '.billio-modal' );
	var modalReturnFocus = null;
	var modalOnClose = null;

	function openModal( html, onOpen, onClose ) {
		modalReturnFocus = document.activeElement;
		modalOnClose = onClose || null;
		modalEl.innerHTML = html;
		ov.classList.add( 'billio-show' );
		var focusable = modalEl.querySelector( 'button, [href], input, select, textarea' );
		if ( focusable ) {
			focusable.focus();
		}
		if ( onOpen ) {
			onOpen( modalEl );
		}
	}

	function closeModal() {
		if ( modalOnClose ) {
			modalOnClose();
			modalOnClose = null;
		}
		ov.classList.remove( 'billio-show' );
		modalEl.innerHTML = '';
		if ( modalReturnFocus && typeof modalReturnFocus.focus === 'function' ) {
			modalReturnFocus.focus();
			modalReturnFocus = null;
		}
	}

	ov.addEventListener( 'click', function ( e ) {
		if ( e.target === ov ) {
			closeModal();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			if ( drawerOverlay.classList.contains( 'billio-open' ) ) {
				closeDrawer();
				return;
			}
			if ( ov.classList.contains( 'billio-show' ) ) {
				closeModal();
				return;
			}
		}
		if ( 'Tab' === e.key && ov.classList.contains( 'billio-show' ) ) {
			var focusables = modalEl.querySelectorAll( 'button, [href], input, select, textarea' );
			if ( ! focusables.length ) {
				return;
			}
			var first = focusables[ 0 ];
			var last = focusables[ focusables.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} else if ( 'Tab' === e.key && drawerOverlay.classList.contains( 'billio-open' ) ) {
			var drawerFocusables = drawerOverlay.querySelectorAll( 'button:not([disabled]), [href], input, select, textarea' );
			if ( ! drawerFocusables.length ) {
				return;
			}
			var dFirst = drawerFocusables[ 0 ];
			var dLast = drawerFocusables[ drawerFocusables.length - 1 ];
			if ( e.shiftKey && document.activeElement === dFirst ) {
				e.preventDefault();
				dLast.focus();
			} else if ( ! e.shiftKey && document.activeElement === dLast ) {
				e.preventDefault();
				dFirst.focus();
			}
		}
	} );

	/* ---------------------------------------------------------------
	 * Slide-over Preview Drawer
	 * ------------------------------------------------------------- */

	var drawerOverlay = document.createElement( 'div' );
	drawerOverlay.className = 'billio-drawer-overlay';
	drawerOverlay.setAttribute( 'aria-hidden', 'true' );
	drawerOverlay.innerHTML =
		'<div class="billio-drawer" role="dialog" aria-modal="true" aria-labelledby="billio-drawer-title">' +
			'<div class="billio-drawer-header">' +
				'<div class="billio-drawer-title-wrap">' +
					'<span id="billio-drawer-badge" class="billio-type">INV</span>' +
					'<div>' +
						'<h3 id="billio-drawer-title"></h3>' +
						'<div id="billio-drawer-sub" class="billio-drawer-sub"></div>' +
					'</div>' +
				'</div>' +
				'<button type="button" id="billio-drawer-close" class="billio-drawer-close" aria-label="' + escapeAttr( t( 'closePreview' ) ) + '">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
				'</button>' +
			'</div>' +
			'<div class="billio-drawer-actions">' +
				'<button type="button" class="billio-btn" id="billio-drawer-download">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg> ' + escapeHtml( t( 'download' ) ) +
				'</button>' +
				'<button type="button" class="billio-btn" id="billio-drawer-print">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg> ' + escapeHtml( t( 'printInvoice' ) ) +
				'</button>' +
				'<button type="button" class="billio-btn" id="billio-drawer-copy">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> ' + escapeHtml( t( 'copyLink' ) ) +
				'</button>' +
				'<a href="#" target="_blank" rel="noopener" class="billio-btn billio-btn-wa" id="billio-drawer-wa">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg> ' + escapeHtml( t( 'sendWhatsApp' ) ) +
				'</a>' +
				'<button type="button" class="billio-btn" id="billio-drawer-resend">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg> ' + escapeHtml( t( 'resend' ) ) +
				'</button>' +
			'</div>' +
			'<div class="billio-drawer-tabs">' +
				'<button type="button" class="billio-drawer-tab active" data-tab="preview">' + escapeHtml( t( 'previewDocument' ) ) + '</button>' +
				'<button type="button" class="billio-drawer-tab" data-tab="details">' + escapeHtml( t( 'orderDetails' ) ) + '</button>' +
			'</div>' +
			'<div class="billio-drawer-body">' +
				'<div id="billio-panel-preview" class="billio-drawer-panel active">' +
					'<div class="billio-drawer-frame-wrap">' +
						'<div class="billio-drawer-loading" id="billio-drawer-loading">' +
							'<div class="billio-spinner"></div>' +
							'<span>' + escapeHtml( t( 'loadingPreview' ) ) + '</span>' +
						'</div>' +
						'<iframe id="billio-drawer-iframe" title="' + escapeAttr( t( 'previewDocument' ) ) + '"></iframe>' +
					'</div>' +
				'</div>' +
				'<div id="billio-panel-details" class="billio-drawer-panel">' +
					'<div class="billio-drawer-details" id="billio-drawer-details"></div>' +
				'</div>' +
			'</div>' +
		'</div>';
	dashWrap.appendChild( drawerOverlay );

	var activeDoc = null;
	var drawerIframe = document.getElementById( 'billio-drawer-iframe' );
	var drawerLoading = document.getElementById( 'billio-drawer-loading' );
	var drawerCloseBtn = document.getElementById( 'billio-drawer-close' );
	var drawerTabs = drawerOverlay.querySelectorAll( '.billio-drawer-tab' );
	var resendDrawerBtn = document.getElementById( 'billio-drawer-resend' );

	drawerCloseBtn.addEventListener( 'click', closeDrawer );

	drawerOverlay.addEventListener( 'click', function ( e ) {
		if ( e.target === drawerOverlay ) {
			closeDrawer();
		}
	} );

	drawerTabs.forEach( function ( tabBtn ) {
		tabBtn.addEventListener( 'click', function () {
			drawerTabs.forEach( function ( b ) {
				b.classList.remove( 'active' );
			} );
			tabBtn.classList.add( 'active' );
			var tabName = tabBtn.dataset.tab;
			document.getElementById( 'billio-panel-preview' ).classList.toggle( 'active', 'preview' === tabName );
			document.getElementById( 'billio-panel-details' ).classList.toggle( 'active', 'details' === tabName );
		} );
	} );

	document.getElementById( 'billio-drawer-download' ).addEventListener( 'click', function () {
		if ( activeDoc ) {
			toast( t( 'downloadingPdf' ) );
			window.open( activeDoc.downloadUrl, '_blank', 'noopener' );
		}
	} );

	document.getElementById( 'billio-drawer-print' ).addEventListener( 'click', function () {
		if ( drawerIframe && drawerIframe.contentWindow ) {
			try {
				drawerIframe.contentWindow.focus();
				drawerIframe.contentWindow.print();
			} catch ( err ) {
				window.open( activeDoc.downloadUrl, '_blank', 'noopener' );
			}
		}
	} );

	document.getElementById( 'billio-drawer-copy' ).addEventListener( 'click', function () {
		if ( activeDoc && activeDoc.downloadUrl ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( activeDoc.downloadUrl ).then( function () {
					toast( t( 'linkCopied' ) );
				} );
			} else {
				var tempInput = document.createElement( 'input' );
				tempInput.value = activeDoc.downloadUrl;
				document.body.appendChild( tempInput );
				tempInput.select();
				document.execCommand( 'copy' );
				document.body.removeChild( tempInput );
				toast( t( 'linkCopied' ) );
			}
		}
	} );

	resendDrawerBtn.addEventListener( 'click', function () {
		if ( ! activeDoc || 'inv' !== activeDoc.type ) {
			return;
		}
		resendDrawerBtn.disabled = true;
		postOrderAction( 'billio_resend_invoice', activeDoc.orderId ).then( function ( res ) {
			resendDrawerBtn.disabled = false;
			var message = res && res.data && res.data.message;
			toast( message || ( res && res.success ? t( 'invoiceEmailed' ) : t( 'error' ) ), ! ( res && res.success ) );
		} );
	} );

	var drawerReturnFocus = null;

	function openDrawer( doc ) {
		drawerReturnFocus = document.activeElement;
		activeDoc = doc;
		document.getElementById( 'billio-drawer-title' ).textContent = doc.number;
		var badge = document.getElementById( 'billio-drawer-badge' );
		badge.textContent = 'cn' === doc.type ? 'CN' : 'INV';
		badge.className = 'billio-type' + ( 'cn' === doc.type ? ' billio-cn' : '' );

		document.getElementById( 'billio-drawer-sub' ).innerHTML =
			'<a href="' + escapeAttr( doc.orderEditUrl ) + '" style="color:var(--billio-brand);font-weight:600">#' + escapeHtml( doc.orderNumber ) + '</a> · ' +
			'<span class="billio-pill ' + statusPillClass( doc.orderStatus ) + '">' + escapeHtml( doc.orderStatusLabel ) + '</span> · ' +
			'<span>' + escapeHtml( doc.dateDisplay ) + '</span>';

		document.getElementById( 'billio-drawer-wa' ).href = doc.whatsappUrl || '#';
		resendDrawerBtn.style.display = 'inv' === doc.type ? 'inline-flex' : 'none';

		drawerTabs.forEach( function ( b, i ) {
			b.classList.toggle( 'active', 0 === i );
		} );
		document.getElementById( 'billio-panel-preview' ).classList.add( 'active' );
		document.getElementById( 'billio-panel-details' ).classList.remove( 'active' );

		drawerLoading.style.display = 'flex';
		drawerIframe.onload = function () {
			drawerLoading.style.display = 'none';
		};
		drawerIframe.src = doc.previewUrl;

		var detailsHtml =
			'<div class="billio-detail-grid">' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'customer' ) ) + '</div><div class="billio-detail-val">' + escapeHtml( doc.customerName || '—' ) + '</div></div>' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'email' ) ) + '</div><div class="billio-detail-val">' + ( doc.customerEmail ? '<a href="mailto:' + escapeAttr( doc.customerEmail ) + '">' + escapeHtml( doc.customerEmail ) + '</a>' : '—' ) + '</div></div>' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'phone' ) ) + '</div><div class="billio-detail-val">' + ( doc.customerPhone ? '<a href="tel:' + escapeAttr( doc.customerPhone ) + '">' + escapeHtml( doc.customerPhone ) + '</a>' : '—' ) + '</div></div>' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'paymentMethod' ) ) + '</div><div class="billio-detail-val">' + escapeHtml( doc.paymentMethod || '—' ) + '</div></div>' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'items' ) ) + '</div><div class="billio-detail-val">' + escapeHtml( doc.itemCount || 1 ) + '</div></div>' +
				'<div class="billio-detail-card"><div class="billio-detail-label">' + escapeHtml( t( 'total' ) ) + '</div><div class="billio-detail-val" style="font-weight:700;font-size:15px;color:var(--billio-brand)">' + fmtMoney( doc.total ) + '</div></div>' +
			'</div>' +
			'<div class="billio-detail-card" style="margin-bottom:20px"><div class="billio-detail-label">' + escapeHtml( t( 'billingAddress' ) ) + '</div><div class="billio-detail-val" style="white-space:pre-line;line-height:1.5">' + escapeHtml( doc.billingAddress || '—' ) + '</div></div>' +
			'<a href="' + escapeAttr( doc.orderEditUrl ) + '" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px">' + escapeHtml( t( 'openOrder' ) ) + '</a>';

		document.getElementById( 'billio-drawer-details' ).innerHTML = detailsHtml;

		drawerOverlay.classList.add( 'billio-open' );
		drawerOverlay.setAttribute( 'aria-hidden', 'false' );
		if ( drawerCloseBtn ) {
			drawerCloseBtn.focus();
		}
	}

	function closeDrawer() {
		drawerOverlay.classList.remove( 'billio-open' );
		drawerOverlay.setAttribute( 'aria-hidden', 'true' );
		drawerIframe.src = 'about:blank';
		activeDoc = null;
		if ( drawerReturnFocus && typeof drawerReturnFocus.focus === 'function' ) {
			drawerReturnFocus.focus();
			drawerReturnFocus = null;
		}
	}

	function openDrawerForSample() {
		var sampleDoc = {
			number: data.nextInvoiceNumber || 'INV-2026-0001',
			type: 'inv',
			orderId: 0,
			orderNumber: 'SAMPLE',
			orderEditUrl: data.urls.settings,
			orderStatus: 'completed',
			orderStatusLabel: 'Completed',
			dateDisplay: t( 'today' ),
			previewUrl: data.urls.sampleInvoicePreview,
			downloadUrl: data.urls.sampleInvoicePreview,
			whatsappUrl: 'https://api.whatsapp.com/send?text=' + encodeURIComponent( 'Invoice Preview: ' + data.urls.sampleInvoicePreview ),
			customerName: 'Rahim Uddin',
			customerEmail: 'rahim@example.com',
			customerPhone: '+880 1711 000000',
			paymentMethod: 'bKash / Bank Transfer',
			itemCount: 2,
			total: 70,
			billingAddress: '42 Gulshan Avenue\nDhaka 1212\nBangladesh'
		};
		openDrawer( sampleDoc );
	}

	/* ---------------------------------------------------------------
	 * AJAX helper
	 * ------------------------------------------------------------- */

	function post( action, params ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', data.nonce );
		Object.keys( params || {} ).forEach( function ( key ) {
			var value = params[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) {
					body.append( key + '[]', v );
				} );
			} else {
				body.set( key, value );
			}
		} );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false };
			} );
	}

	function postOrderAction( action, orderId ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'order_id', orderId );
		body.set( 'nonce', data.orderActionNonce );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false };
			} );
	}

	/* ---------------------------------------------------------------
	 * Page skeleton
	 * ------------------------------------------------------------- */

	var avatarColors = [ '#4f46e5', '#059669', '#d97706', '#db2777', '#0891b2', '#7c3aed' ];

	function avatarColor( name ) {
		var sum = 0;
		for ( var i = 0; i < name.length; i++ ) {
			sum += name.charCodeAt( i );
		}
		return avatarColors[ sum % avatarColors.length ];
	}

	function initials( name ) {
		return name.split( ' ' ).filter( Boolean ).map( function ( w ) {
			return w[ 0 ];
		} ).slice( 0, 2 ).join( '' ).toUpperCase();
	}

	function iconGeneric() {
		return '<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h7M9 17h5"/></svg>';
	}

	function buildSkeleton() {
		var missingCount = data.kpis.ordersWithoutInvoice;
		var statusBadge = missingCount === 0
			? '<span class="billio-success-badge" id="billio-header-status"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>' + escapeHtml( t( 'allInvoiced' ) ) + '</span>'
			: '';

		var quickActionsHtml =
			'<div class="billio-quick-actions" id="billio-quick-actions">' +
				'<button type="button" class="billio-qa-btn" id="billio-qa-preview">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' +
					escapeHtml( t( 'instantInvoicePreview' ) ) +
				'</button>' +
				'<button type="button" class="billio-qa-btn" id="billio-qa-generate">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>' +
					escapeHtml( t( 'generateMissingBtn' ) ) +
					( missingCount > 0 ? ' <span class="billio-qa-badge" id="billio-qa-missing-badge">' + missingCount + '</span>' : '' ) +
				'</button>' +
				'<a class="billio-qa-btn" href="' + escapeAttr( data.urls.settings ) + '">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' +
					escapeHtml( t( 'settings' ) ) +
				'</a>' +
				'<a class="billio-qa-btn" href="' + escapeAttr( data.urls.docs ) + '">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>' +
					escapeHtml( t( 'docsAndGuide' ) ) +
				'</a>' +
				'<span id="billio-header-status-slot">' + statusBadge + '</span>' +
			'</div>';

		root.innerHTML =
			'<div class="billio-hdr">' +
				'<div class="billio-logo">' + iconGeneric() + '</div>' +
				'<div><h2>Billio <span class="billio-ver">v' + escapeHtml( root.dataset.version || '' ) + '</span></h2>' +
				'<div class="billio-sub">' + escapeHtml( t( 'tagline' ) ) + '</div></div>' +
				'<div class="billio-sp"></div>' +
				quickActionsHtml +
			'</div>' +
			'<div id="billio-setup-slot"></div>' +
			'<div class="billio-kpis" id="billio-kpis"></div>' +
			'<div id="billio-review-slot"></div>' +
			'<div class="billio-grid">' +
				'<div class="billio-card billio-chart-card">' +
					'<div class="billio-ch"><h3>' + escapeHtml( t( 'invoiceActivity' ) ) + '</h3><div class="billio-sp"></div>' +
						'<div class="billio-seg" id="billio-metric-seg">' +
							'<button type="button" aria-pressed="true" data-m="invoices">' + escapeHtml( t( 'metricInvoices' ) ) + '</button>' +
							'<button type="button" aria-pressed="false" data-m="revenue">' + escapeHtml( t( 'metricRevenue' ) ) + '</button>' +
						'</div>' +
						'<div class="billio-seg" id="billio-range-seg">' +
							'<button type="button" aria-pressed="false" data-r="7">7D</button>' +
							'<button type="button" aria-pressed="true" data-r="30">30D</button>' +
							'<button type="button" aria-pressed="false" data-r="90">90D</button>' +
						'</div>' +
					'</div>' +
					'<div class="billio-chart-summary">' +
						'<div><div class="billio-label">' + escapeHtml( t( 'chartTotalInRange' ) ) + '</div><div class="billio-value" id="billio-chart-tot">—</div></div>' +
						'<div><div class="billio-label">' + escapeHtml( t( 'chartDailyAverage' ) ) + '</div><div class="billio-value" id="billio-chart-avg">—</div></div>' +
						'<div><div class="billio-label">' + escapeHtml( t( 'chartBestDay' ) ) + '</div><div class="billio-value" id="billio-chart-best">—</div></div>' +
					'</div>' +
					'<div class="billio-chartbox" id="billio-chart" aria-label="' + escapeAttr( t( 'invoiceActivity' ) ) + '">' +
						'<div class="billio-tip" id="billio-tip"></div>' +
					'</div>' +
					'<table class="billio-sr-table" id="billio-chart-table"><caption>' + escapeHtml( t( 'chartActivityByDay' ) ) + '</caption></table>' +
					'<div class="billio-legend">' +
						'<span><i style="background:#4f46e5"></i>' + escapeHtml( t( 'filterInvoices' ) ) + '</span>' +
						'<span><i style="background:#f87171"></i>' + escapeHtml( t( 'filterCreditNotes' ) ) + '</span>' +
					'</div>' +
				'</div>' +
				'<div class="billio-card">' +
					'<div class="billio-ch"><h3>' + escapeHtml( t( 'invoiceSetupAtGlance' ) ) + '</h3></div>' +
					'<div class="billio-cfg" id="billio-cfg"></div>' +
				'</div>' +
			'</div>' +
			'<div class="billio-card">' +
				'<div class="billio-ch"><h3>' + escapeHtml( t( 'recentDocuments' ) ) + '</h3><div class="billio-sp"></div>' +
					'<div class="billio-seg" id="billio-ftype">' +
						'<button type="button" aria-pressed="true" data-t="all">' + escapeHtml( t( 'filterAll' ) ) + '</button>' +
						'<button type="button" aria-pressed="false" data-t="inv">' + escapeHtml( t( 'filterInvoices' ) ) + '</button>' +
						'<button type="button" aria-pressed="false" data-t="cn">' + escapeHtml( t( 'filterCreditNotes' ) ) + '</button>' +
					'</div>' +
				'</div>' +
				'<div class="billio-tools"><div class="billio-search">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
					'<input type="search" id="billio-q" placeholder="' + escapeAttr( t( 'searchPlaceholder' ) ) + '">' +
				'</div></div>' +
				'<div class="billio-table-wrap"><table class="billio-tbl">' +
					'<thead><tr>' +
						'<th>' + escapeHtml( t( 'colDocument' ) ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( t( 'colOrder' ) ) + '</th>' +
						'<th>' + escapeHtml( t( 'colCustomer' ) ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( t( 'colOrderStatus' ) ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( t( 'colDate' ) ) + '</th>' +
						'<th class="billio-r">' + escapeHtml( t( 'colTotal' ) ) + '</th>' +
						'<th class="billio-r">' + escapeHtml( t( 'colActions' ) ) + '</th>' +
					'</tr></thead>' +
					'<tbody id="billio-rows"></tbody>' +
				'</table></div>' +
				'<div class="billio-tfoot"><span id="billio-cnt"></span><div class="billio-sp"></div><div class="billio-pager" id="billio-pager"></div><a class="billio-lnk" href="' + escapeAttr( data.urls.ordersList ) + '">' + escapeHtml( t( 'viewAllInOrders' ) ) + '</a></div>' +
				'<div class="billio-truncated-notice" id="billio-truncated-notice"></div>' +
			'</div>';

		var qaPreviewBtn = document.getElementById( 'billio-qa-preview' );
		if ( qaPreviewBtn ) {
			qaPreviewBtn.addEventListener( 'click', openDrawerForSample );
		}

		var qaGenBtn = document.getElementById( 'billio-qa-generate' );
		if ( qaGenBtn ) {
			qaGenBtn.addEventListener( 'click', openGenerateModal );
		}
	}

	/* ---------------------------------------------------------------
	 * Count-up
	 * ------------------------------------------------------------- */

	function countUp( el, to, isMoney ) {
		if ( reduceMotion ) {
			el.textContent = isMoney ? fmtMoney( to ) : to.toLocaleString();
			return;
		}
		var start = null;
		function step( ts ) {
			if ( ! start ) {
				start = ts;
			}
			var p = Math.min( ( ts - start ) / 900, 1 );
			var v = to * ( 1 - Math.pow( 1 - p, 3 ) );
			el.textContent = isMoney ? fmtMoney( v ) : Math.round( v ).toLocaleString();
			if ( p < 1 ) {
				requestAnimationFrame( step );
			}
		}
		requestAnimationFrame( step );
	}

	function trendHtml( thisVal, lastVal ) {
		if ( ! lastVal ) {
			return '';
		}
		var pct = Math.round( Math.abs( ( thisVal - lastVal ) / lastVal ) * 100 );
		var up = thisVal >= lastVal;
		return '<span class="' + ( up ? 'billio-up' : 'billio-dn' ) + '">' + ( up ? '▲' : '▼' ) + ' ' + pct + '%</span> vs last month';
	}

	function sparkline( color ) {
		return '<svg class="billio-spark" viewBox="0 0 90 34"><polyline fill="none" stroke="' + color + '" stroke-width="2" points="0,26 15,22 30,24 45,14 60,16 75,12 90,8"/></svg>';
	}

	function renderKpis() {
		var k = data.kpis;
		var wrap = document.getElementById( 'billio-kpis' );
		wrap.innerHTML =
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#eef2ff;color:#4f46e5">' + iconGeneric() + '</span>' + escapeHtml( t( 'invoicesThisMonth' ) ) + '</div>' +
				'<div class="billio-v" id="billio-kpi-inv">0</div>' +
				'<div class="billio-d">' + trendHtml( k.invoicesThisMonth, k.invoicesLastMonth ) + '</div>' + sparkline( '#4f46e5' ) +
			'</div>' +
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#ecfdf5;color:#059669">' + iconGeneric() + '</span>' + escapeHtml( t( 'revenueInvoicedThisMonth' ) ) + '</div>' +
				'<div class="billio-v" id="billio-kpi-rev">' + fmtMoney( 0 ) + '</div>' +
				'<div class="billio-d">' + trendHtml( k.revenueThisMonth, k.revenueLastMonth ) + '</div>' + sparkline( '#059669' ) +
			'</div>' +
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#fef2f2;color:#dc2626">' + iconGeneric() + '</span>' + escapeHtml( t( 'creditNotesThisMonth' ) ) + '</div>' +
				'<div class="billio-v">' + k.creditNotesThisMonth + '</div>' +
				'<div class="billio-d">' + ( k.creditNotesRefundedTotal > 0
					? '<span class="billio-dn">−' + fmtMoney( k.creditNotesRefundedTotal ) + '</span> ' + escapeHtml( tf( 'refundedThisMonth', '' ).trim() )
					: escapeHtml( t( 'noRefundsThisMonth' ) ) ) +
				'</div>' +
			'</div>' +
			'<div class="billio-kpi' + ( k.ordersWithoutInvoice > 0 ? ' billio-alert' : '' ) + '"><div class="billio-top"><span class="billio-ic" style="background:#fef3c7;color:#d97706">' + iconGeneric() + '</span>' + escapeHtml( t( 'ordersWithoutInvoice' ) ) + '</div>' +
				'<div class="billio-v">' + k.ordersWithoutInvoice + '</div>' +
				'<div class="billio-d">' + ( k.ordersWithoutInvoice > 0
					? '<button type="button" class="billio-lnk billio-kpi-generate">' + escapeHtml( t( 'generateThemNow' ) ) + '</button>'
					: escapeHtml( t( 'allCaughtUp' ) ) ) +
				'</div>' +
			'</div>';

		countUp( document.getElementById( 'billio-kpi-inv' ), k.invoicesThisMonth, false );
		countUp( document.getElementById( 'billio-kpi-rev' ), k.revenueThisMonth, true );

		var genLink = wrap.querySelector( '.billio-kpi-generate' );
		if ( genLink ) {
			genLink.addEventListener( 'click', openGenerateModal );
		}
	}

	/* ---------------------------------------------------------------
	 * Setup banner
	 * ------------------------------------------------------------- */

	function renderSetup() {
		var slot = document.getElementById( 'billio-setup-slot' );
		var s = data.setup;

		if ( ! s || ! s.steps ) {
			slot.innerHTML = '';
			return;
		}

		if ( s.allDone ) {
			slot.innerHTML =
				'<div class="billio-setup-complete-card" id="billio-setup-complete">' +
					'<div class="billio-setup-complete-info">' +
						'<div class="billio-setup-complete-icon">✓</div>' +
						'<div>' +
							'<div>' + escapeHtml( t( 'allStepsCompleted' ) ) + '</div>' +
							'<small style="color:#059669;font-weight:400">' + escapeHtml( tf( 'setupProgress', s.done, s.total ) ) + '</small>' +
						'</div>' +
					'</div>' +
					'<button type="button" class="billio-checklist-toggle" id="billio-toggle-checklist">' + escapeHtml( t( 'showChecklist' ) ) + ' ▾</button>' +
				'</div>' +
				'<div id="billio-setup-expanded" style="display:none;margin-bottom:20px;"></div>';

			var toggleBtn = document.getElementById( 'billio-toggle-checklist' );
			if ( toggleBtn ) {
				toggleBtn.addEventListener( 'click', function () {
					var exp = document.getElementById( 'billio-setup-expanded' );
					var isVisible = exp.style.display !== 'none';
					exp.style.display = isVisible ? 'none' : 'block';
					this.textContent = isVisible ? ( escapeHtml( t( 'showChecklist' ) ) + ' ▾' ) : ( escapeHtml( t( 'hideChecklist' ) ) + ' ▴' );
					if ( ! isVisible ) {
						renderSetupBannerContent( exp, s, true );
					}
				} );
			}
			return;
		}

		if ( s.dismissed ) {
			slot.innerHTML =
				'<div style="margin-bottom:16px;display:flex;justify-content:flex-end;">' +
					'<button type="button" class="billio-checklist-toggle" id="billio-reopen-setup">' +
						escapeHtml( t( 'smartChecklist' ) ) + ' (' + s.done + '/' + s.total + ' complete) ▾' +
					'</button>' +
				'</div>' +
				'<div id="billio-setup-reopened" style="display:none;margin-bottom:20px;"></div>';

			var reopenBtn = document.getElementById( 'billio-reopen-setup' );
			if ( reopenBtn ) {
				reopenBtn.addEventListener( 'click', function () {
					var re = document.getElementById( 'billio-setup-reopened' );
					var isVis = re.style.display !== 'none';
					re.style.display = isVis ? 'none' : 'block';
					if ( ! isVis ) {
						renderSetupBannerContent( re, s, false );
					}
				} );
			}
			return;
		}

		renderSetupBannerContent( slot, s, false );
	}

	function renderSetupBannerContent( container, s, isComplete ) {
		var firstTodoIndex = -1;
		s.steps.forEach( function ( step, i ) {
			if ( ! step.done && firstTodoIndex === -1 ) {
				firstTodoIndex = i;
			}
		} );

		var stepsHtml = s.steps.map( function ( step, i ) {
			if ( step.done ) {
				return '<span class="billio-step billio-done">✓ ' + escapeHtml( step.label ) + '</span>';
			}
			if ( i === firstTodoIndex && step.anchor ) {
				return '<a class="billio-step billio-todo" href="' + escapeAttr( data.urls.settings + step.anchor ) + '">＋ ' + escapeHtml( step.label ) + ' →</a>';
			}
			if ( step.anchor ) {
				return '<a class="billio-step" href="' + escapeAttr( data.urls.settings + step.anchor ) + '">○ ' + escapeHtml( step.label ) + '</a>';
			}
			return '<span class="billio-step">○ ' + escapeHtml( step.label ) + '</span>';
		} ).join( '' );

		var pct = Math.round( ( s.done / s.total ) * 100 );

		container.innerHTML =
			'<div class="billio-setup" id="billio-setup">' +
				( isComplete ? '' : '<button type="button" class="billio-x" id="billio-setup-dismiss" aria-label="' + escapeAttr( t( 'hide' ) ) + '">×</button>' ) +
				'<div class="billio-ring"><svg viewBox="0 0 36 36" width="72" height="72">' +
					'<circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="3"/>' +
					'<circle cx="18" cy="18" r="15.9" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-dasharray="' + pct + ' 100" transform="rotate(-90 18 18)"/>' +
				'</svg><b>' + s.done + '/' + s.total + '</b></div>' +
				'<div class="billio-setup-body">' +
					'<h3>' + escapeHtml( t( 'smartChecklist' ) ) + ' <span>' + escapeHtml( tf( 'percentComplete', pct ) ) + '</span></h3>' +
					'<p>' + escapeHtml( t( 'setupBannerDesc' ) ) + '</p>' +
					'<div class="billio-steps">' + stepsHtml + '</div>' +
				'</div>' +
			'</div>';

		var dismissBtn = container.querySelector( '#billio-setup-dismiss' );
		if ( dismissBtn ) {
			dismissBtn.addEventListener( 'click', function () {
				container.innerHTML = '';
				post( 'billio_dashboard_dismiss_setup', {} );
			} );
		}
	}

	/* ---------------------------------------------------------------
	 * Review bar
	 * ------------------------------------------------------------- */

	function renderReview() {
		var slot = document.getElementById( 'billio-review-slot' );
		var r = data.reviewNotice;

		if ( ! r.show ) {
			slot.innerHTML = '';
			return;
		}

		slot.innerHTML =
			'<div class="billio-review" id="billio-review">' +
				'<span class="billio-stars">★★★★★</span>' +
				escapeHtml( tf( 'reviewEnjoying', r.total ) ) +
				'<span class="billio-sp"></span>' +
				'<a class="billio-lnk" href="' + escapeAttr( r.url ) + '" target="_blank" rel="noopener">' + escapeHtml( t( 'leaveReview' ) ) + '</a>' +
				'<button type="button" class="billio-lnk" id="billio-review-dismiss" style="color:var(--billio-muted)">' + escapeHtml( t( 'notNow' ) ) + '</button>' +
			'</div>';

		document.getElementById( 'billio-review-dismiss' ).addEventListener( 'click', function () {
			document.getElementById( 'billio-review' ).remove();
			var body = new URLSearchParams();
			body.set( 'action', 'billio_dismiss_review_notice' );
			body.set( 'nonce', r.nonce );
			fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } );
		} );
	}

	/* ---------------------------------------------------------------
	 * Chart
	 * ------------------------------------------------------------- */

	var chartState = { metric: 'invoices', range: 30, days: data.chart.days };
	var chartCache = {};
	chartCache[ 30 ] = data.chart.days;

	// Standard "nice ticks" step: picks 1/2/2.5/5 × 10^n so axis labels read
	// as round numbers instead of arbitrary fractions, and the resulting
	// max is the first such tick at or above the real data max — a fixed
	// "4 / 4000" step (the old logic) made a 206-unit bar on a 4000-max
	// axis nearly invisible.
	function niceTickStep( rawMax, targetTicks, minStep ) {
		if ( rawMax <= 0 ) {
			return minStep || 1;
		}
		var roughStep = rawMax / ( targetTicks || 4 );
		var magnitude = Math.pow( 10, Math.floor( Math.log( roughStep ) / Math.LN10 ) );
		var normalized = roughStep / magnitude;
		var niceNormalized;
		if ( normalized <= 1 ) {
			niceNormalized = 1;
		} else if ( normalized <= 2 ) {
			niceNormalized = 2;
		} else if ( normalized <= 2.5 ) {
			niceNormalized = 2.5;
		} else if ( normalized <= 5 ) {
			niceNormalized = 5;
		} else {
			niceNormalized = 10;
		}
		var step = niceNormalized * magnitude;
		// Invoice counts are whole numbers — a sub-1 step (e.g. 0.25, when
		// the whole range only has 1 invoice) would round to meaningless
		// duplicate tick labels, so the count metric enforces a floor of 1.
		return minStep ? Math.max( step, minStep ) : step;
	}

	function fmtAxisRevenue( value ) {
		var amount;
		if ( value >= 1000 ) {
			var inK = value / 1000;
			amount = ( inK % 1 === 0 ? inK.toFixed( 0 ) : inK.toFixed( 1 ) ) + 'k';
		} else {
			amount = String( Math.round( value ) );
		}
		var symbol = data.currency.symbol;
		switch ( data.currency.position ) {
			case 'right':
				return amount + symbol;
			case 'left_space':
				return symbol + ' ' + amount;
			case 'right_space':
				return amount + ' ' + symbol;
			default:
				return symbol + amount;
		}
	}

	function drawChart() {
		var box = document.getElementById( 'billio-chart' );
		var tip = document.getElementById( 'billio-tip' );
		var days = chartState.days;
		var W = box.clientWidth || 600;
		var H = box.clientHeight || 240;
		var pb = 24;
		var pl = 40;

		function val( d ) {
			return chartState.metric === 'invoices' ? d.invoices : d.revenue;
		}

		var maxRaw = 0;
		days.forEach( function ( d ) {
			var v = val( d ) + ( chartState.metric === 'invoices' ? d.creditNotes : 0 );
			if ( v > maxRaw ) {
				maxRaw = v;
			}
		} );
		var step     = niceTickStep( maxRaw || 1, 4, chartState.metric === 'invoices' ? 1 : 0 );
		var max      = Math.ceil( ( maxRaw || 1 ) / step ) * step;
		var numTicks = Math.round( max / step );

		var bw = ( W - pl ) / days.length;
		var gap = Math.max( bw * 0.28, 1 );

		var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="height:' + H + 'px" role="img" aria-hidden="true">';
		for ( var g = 0; g <= numTicks; g++ ) {
			var y = ( H - pb ) - ( H - pb ) * g / numTicks;
			var tickVal = chartState.metric === 'invoices' ? Math.round( max * g / numTicks ) : fmtAxisRevenue( max * g / numTicks );
			svg += '<line x1="' + pl + '" x2="' + W + '" y1="' + y + '" y2="' + y + '" stroke="#eef0f5"/>';
			svg += '<text x="' + ( pl - 8 ) + '" y="' + ( y + 4 ) + '" text-anchor="end" font-size="10.5" fill="#94a3b8">' + escapeHtml( tickVal ) + '</text>';
		}

		// "Today" (the last bar) always gets its label; any other candidate
		// label within one label-width's worth of pixels of it is skipped
		// instead of overlapping it (e.g. "Oct 7" running into "Today").
		var todayLabelX = pl + ( days.length - 1 ) * bw + bw / 2;
		var minLabelGap = 40;

		days.forEach( function ( d, i ) {
			var x = pl + i * bw + gap / 2;
			var w = bw - gap;
			var h = ( H - pb ) * val( d ) / max;
			var y = H - pb - h;
			svg += '<rect class="billio-bar" data-i="' + i + '" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + Math.min( 4, w / 2 ) + '" fill="url(#billio-grad)"/>';
			if ( chartState.metric === 'invoices' && d.creditNotes ) {
				var h2 = ( H - pb ) * d.creditNotes / max;
				svg += '<rect x="' + x + '" y="' + ( y - h2 - 2 ) + '" width="' + w + '" height="' + h2 + '" rx="' + Math.min( 4, w / 2 ) + '" fill="#f87171"/>';
			}
			var isToday     = i === days.length - 1;
			var wantsLabel  = chartState.range === 7 || isToday || ( i % ( chartState.range === 30 ? 7 : 15 ) === 0 );
			var labelX      = x + w / 2;
			var tooCloseToToday = ! isToday && Math.abs( labelX - todayLabelX ) < minLabelGap;
			if ( wantsLabel && ! tooCloseToToday ) {
				var label = isToday ? t( 'today' ) : fmtDate( Math.floor( new Date( d.date ).getTime() / 1000 ) );
				svg += '<text x="' + labelX + '" y="' + ( H - 6 ) + '" text-anchor="middle" font-size="10.5" fill="#94a3b8">' + escapeHtml( label ) + '</text>';
			}
			svg += '<rect data-i="' + i + '" class="billio-hit" x="' + ( pl + i * bw ) + '" y="0" width="' + bw + '" height="' + ( H - pb ) + '" fill="transparent"/>';
		} );

		svg += '<defs><linearGradient id="billio-grad" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#7c3aed"/><stop offset="1" stop-color="#4f46e5"/></linearGradient></defs>';
		svg += '</svg>';

		box.innerHTML = svg;
		box.appendChild( tip );

		var hits = box.querySelectorAll( '.billio-hit' );
		hits.forEach( function ( hitEl ) {
			hitEl.addEventListener( 'mouseenter', function () {
				var d = days[ hitEl.dataset.i ];
				box.querySelectorAll( '.billio-bar' ).forEach( function ( b ) {
					b.style.opacity = b.dataset.i === hitEl.dataset.i ? 1 : 0.35;
				} );
				var ts = Math.floor( new Date( d.date ).getTime() / 1000 );
				tip.innerHTML = '<b>' + ( chartState.metric === 'invoices' ? d.invoices + ' invoices' : fmtMoney( d.revenue ) ) + '</b>' +
					escapeHtml( fmtDateFull( ts ) ) + ( d.creditNotes ? ' · ' + d.creditNotes + ' credit note' + ( d.creditNotes > 1 ? 's' : '' ) : '' );
				tip.style.left = ( +hitEl.getAttribute( 'x' ) + bw / 2 ) + 'px';
				tip.style.top = Math.max( ( H - pb ) - ( H - pb ) * val( d ) / max, 30 ) + 'px';
				tip.style.opacity = 1;
			} );
			hitEl.addEventListener( 'mouseleave', function () {
				tip.style.opacity = 0;
				box.querySelectorAll( '.billio-bar' ).forEach( function ( b ) {
					b.style.opacity = 1;
				} );
			} );
		} );

		var tot = 0;
		var best = days[ 0 ];
		days.forEach( function ( d ) {
			tot += val( d );
			if ( val( d ) > val( best ) ) {
				best = d;
			}
		} );
		document.getElementById( 'billio-chart-tot' ).textContent = chartState.metric === 'invoices' ? tot : fmtMoney( tot );
		document.getElementById( 'billio-chart-avg' ).textContent = chartState.metric === 'invoices' ? ( tot / days.length ).toFixed( 1 ) : fmtMoney( Math.round( tot / days.length ) );
		document.getElementById( 'billio-chart-best' ).textContent = fmtDate( Math.floor( new Date( best.date ).getTime() / 1000 ) );

		var srTable = document.getElementById( 'billio-chart-table' );
		var rows = '<tr><th>' + escapeHtml( t( 'colDate' ) ) + '</th><th>' + escapeHtml( t( 'filterInvoices' ) ) + '</th><th>' + escapeHtml( t( 'metricRevenue' ) ) + '</th><th>' + escapeHtml( t( 'filterCreditNotes' ) ) + '</th></tr>';
		days.forEach( function ( d ) {
			rows += '<tr><td>' + escapeHtml( d.date ) + '</td><td>' + d.invoices + '</td><td>' + fmtMoney( d.revenue ) + '</td><td>' + d.creditNotes + '</td></tr>';
		} );
		srTable.innerHTML = '<caption>' + escapeHtml( t( 'chartActivityByDay' ) ) + '</caption>' + rows;
	}

	function loadRange( days ) {
		if ( chartCache[ days ] ) {
			chartState.days = chartCache[ days ];
			chartState.range = days;
			drawChart();
			return;
		}
		post( 'billio_dashboard_chart_data', { days: days } ).then( function ( res ) {
			if ( res && res.success ) {
				chartCache[ days ] = res.data.days;
				chartState.days = res.data.days;
				chartState.range = days;
				drawChart();
			}
		} );
	}

	function wireChartControls() {
		document.querySelectorAll( '#billio-metric-seg button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '#billio-metric-seg button' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.setAttribute( 'aria-pressed', 'true' );
				chartState.metric = btn.dataset.m;
				drawChart();
			} );
		} );
		document.querySelectorAll( '#billio-range-seg button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '#billio-range-seg button' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.setAttribute( 'aria-pressed', 'true' );
				loadRange( +btn.dataset.r );
			} );
		} );
		// The chartbox flexes to fill the right column's height (see
		// .billio-chartbox CSS), so its size can change from something
		// other than a window resize too (e.g. the right column wrapping
		// differently). Observing the GRID (a stable ancestor), not the
		// chartbox itself: observing an element whose own content we
		// rewrite on every redraw is a feedback loop waiting to happen —
		// the previous version did exactly that and caused the chart card
		// to grow without bound.
		var gridEl = root.querySelector( '.billio-grid' );
		if ( gridEl && window.ResizeObserver ) {
			var lastW = 0;
			var lastH = 0;
			new ResizeObserver( function ( entries ) {
				var rect = entries[ 0 ].contentRect;
				if ( rect.width !== lastW || rect.height !== lastH ) {
					lastW = rect.width;
					lastH = rect.height;
					drawChart();
				}
			} ).observe( gridEl );
			return;
		}
		var lastW = window.innerWidth;
		window.addEventListener( 'resize', function () {
			if ( window.innerWidth !== lastW ) {
				lastW = window.innerWidth;
				drawChart();
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Quick settings card
	 * ------------------------------------------------------------- */

	function saveQuickSetting( option, value, revert ) {
		post( 'billio_dashboard_quick_setting', { option: option, value: value } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				toast( ( res && res.data && res.data.message ) || t( 'settingsSaveFailed' ), true );
				if ( revert ) {
					revert();
				}
			}
		} );
	}

	function renderConfigCard() {
		var qs = data.quickSettings;
		var processingOn = qs.emailTriggers.indexOf( 'customer_processing_order' ) !== -1;
		var completedOn = qs.emailTriggers.indexOf( 'customer_completed_order' ) !== -1;

		var cfg = document.getElementById( 'billio-cfg' );
		cfg.innerHTML =
			'<div class="billio-row"><span class="billio-ic">#</span><div class="billio-t"><small>' + escapeHtml( t( 'nextInvoiceNumber' ) ) + '</small><span class="billio-mono">' + escapeHtml( data.nextInvoiceNumber ) + '</span></div><a class="billio-lnk" href="' + escapeAttr( data.urls.editFormatField ) + '">' + escapeHtml( t( 'editFormat' ) ) + '</a></div>' +
			'<div class="billio-row"><span class="billio-ic">✉</span><div class="billio-t"><small>' + escapeHtml( t( 'attachToEmail' ) ) + '</small><span>' + escapeHtml( t( 'processingOrder' ) ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-processing" role="switch" aria-checked="' + ( processingOn ? 'true' : 'false' ) + '" aria-label="' + escapeAttr( t( 'processingOrder' ) ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic">✉</span><div class="billio-t"><small>' + escapeHtml( t( 'attachToEmail' ) ) + '</small><span>' + escapeHtml( t( 'completedOrder' ) ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-completed" role="switch" aria-checked="' + ( completedOn ? 'true' : 'false' ) + '" aria-label="' + escapeAttr( t( 'completedOrder' ) ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span><div class="billio-t"><small>' + escapeHtml( t( 'b2bTax' ) ) + '</small><span>' + escapeHtml( t( 'b2bTaxIdToggle' ) ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-tax-id" role="switch" aria-checked="' + ( qs.enableTaxId ? 'true' : 'false' ) + '" aria-label="' + escapeAttr( t( 'b2bTaxIdToggle' ) ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg></span><div class="billio-t"><small>' + escapeHtml( t( 'watermark' ) ) + '</small><span>' + escapeHtml( t( 'watermarkPaidToggle' ) ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-watermark" role="switch" aria-checked="' + ( qs.watermarkPaid ? 'true' : 'false' ) + '" aria-label="' + escapeAttr( t( 'watermarkPaidToggle' ) ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/></svg></span><div class="billio-t"><small>' + escapeHtml( t( 'paperSize' ) ) + '</small><span id="billio-paper-value">' + escapeHtml( qs.paperSize ) + '</span></div><div class="billio-seg" id="billio-paper-seg">' +
				'<button type="button" data-v="A4" aria-pressed="' + ( qs.paperSize === 'A4' ? 'true' : 'false' ) + '">A4</button>' +
				'<button type="button" data-v="Letter" aria-pressed="' + ( qs.paperSize === 'Letter' ? 'true' : 'false' ) + '">Letter</button>' +
			'</div></div>' +
			'<div class="billio-row" style="flex-direction:column;align-items:stretch">' +
				'<small style="color:var(--billio-muted);font-size:12px">' + escapeHtml( t( 'invoiceTemplate' ) ) + '</small>' +
				'<div class="billio-tpl" id="billio-tpl-seg">' +
					'<button type="button" data-v="default" aria-pressed="' + ( qs.template === 'default' ? 'true' : 'false' ) + '"><div class="billio-mini"><i style="width:40%"></i><i></i><i style="width:70%"></i><i></i></div>' + escapeHtml( t( 'templateDefault' ) ) + '</button>' +
					'<button type="button" data-v="modern" aria-pressed="' + ( qs.template === 'modern' ? 'true' : 'false' ) + '"><div class="billio-mini billio-modern"><i></i><i style="width:60%"></i><i></i><i style="width:80%"></i></div>' + escapeHtml( t( 'templateModern' ) ) + '</button>' +
				'</div>' +
			'</div>';

		cfg.querySelectorAll( '.billio-tg' ).forEach( function ( btn ) {
			btn.addEventListener( 'keydown', function ( e ) {
				if ( ' ' === e.key || 'Enter' === e.key ) {
					e.preventDefault();
					btn.click();
				}
			} );
		} );

		function wireToggle( id, statusKey, label ) {
			var btn = document.getElementById( id );
			btn.addEventListener( 'click', function () {
				var next = btn.getAttribute( 'aria-checked' ) !== 'true';
				btn.setAttribute( 'aria-checked', next ? 'true' : 'false' );
				var triggers = qs.emailTriggers.slice();
				if ( next ) {
					if ( triggers.indexOf( statusKey ) === -1 ) {
						triggers.push( statusKey );
					}
				} else {
					triggers = triggers.filter( function ( s ) {
						return s !== statusKey;
					} );
				}
				qs.emailTriggers = triggers;
				toast( next ? t( 'emailTriggerOn' ) : t( 'emailTriggerOff' ) );
				saveQuickSetting( 'billio_email_triggers', triggers, function () {
					btn.setAttribute( 'aria-checked', next ? 'false' : 'true' );
				} );
			} );
		}
		wireToggle( 'billio-tg-processing', 'customer_processing_order' );
		wireToggle( 'billio-tg-completed', 'customer_completed_order' );

		function wireSimpleToggle( id, optionName, qsProp, onMsg, offMsg ) {
			var btn = document.getElementById( id );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', function () {
				var next = btn.getAttribute( 'aria-checked' ) !== 'true';
				btn.setAttribute( 'aria-checked', next ? 'true' : 'false' );
				qs[ qsProp ] = next;
				toast( next ? onMsg : offMsg );
				saveQuickSetting( optionName, next ? '1' : '', function () {
					btn.setAttribute( 'aria-checked', next ? 'false' : 'true' );
					qs[ qsProp ] = ! next;
				} );
			} );
		}
		wireSimpleToggle( 'billio-tg-tax-id', 'billio_enable_tax_id', 'enableTaxId', t( 'b2bTaxIdEnabled' ), t( 'b2bTaxIdDisabled' ) );
		wireSimpleToggle( 'billio-tg-watermark', 'billio_watermark_paid', 'watermarkPaid', t( 'watermarkPaidOn' ), t( 'watermarkPaidOff' ) );

		document.querySelectorAll( '#billio-paper-seg button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '#billio-paper-seg button' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.setAttribute( 'aria-pressed', 'true' );
				qs.paperSize = btn.dataset.v;
				document.getElementById( 'billio-paper-value' ).textContent = btn.dataset.v;
				toast( tf( 'paperSizeSet' ) );
				saveQuickSetting( 'billio_paper_size', btn.dataset.v );
			} );
		} );

		document.querySelectorAll( '#billio-tpl-seg button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '#billio-tpl-seg button' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.setAttribute( 'aria-pressed', 'true' );
				qs.template = btn.dataset.v;
				toast( t( 'templateSet' ) );
				saveQuickSetting( 'billio_template', btn.dataset.v );
			} );
		} );
	}

	/* ---------------------------------------------------------------
	 * Recent documents table
	 * ------------------------------------------------------------- */

	var tableState = { type: 'all', q: '', page: 1 };
	var SEARCH_PER_PAGE = 10;
	var searchDebounceTimer = null;
	// Incremented on every search request; a response is only rendered if
	// its sequence number is still current — guards against an older,
	// slower request resolving after a newer one and overwriting its
	// (more current) results.
	var searchRequestSeq = 0;

	function statusPillClass( status ) {
		if ( 'completed' === status ) {
			return 'billio-p-ok';
		}
		if ( 'refunded' === status ) {
			return 'billio-p-cn';
		}
		if ( 'processing' === status ) {
			return 'billio-p-pr';
		}
		return 'billio-p-default';
	}

	// Builds the "no results" row via textContent (never innerHTML) — a
	// search term or i18n string containing literal quotes/ampersands must
	// never be HTML-parsed, only displayed as plain text.
	function renderEmptyRow( message ) {
		var rowsEl = document.getElementById( 'billio-rows' );
		rowsEl.innerHTML = '';
		var tr = document.createElement( 'tr' );
		var td = document.createElement( 'td' );
		td.colSpan = 7;
		td.className = 'billio-empty';
		td.textContent = message;
		tr.appendChild( td );
		rowsEl.appendChild( tr );
	}

	function emptyStateMessage() {
		if ( tableState.q ) {
			return tf( 'noSearchMatch', tableState.q );
		}
		if ( 'cn' === tableState.type ) {
			return t( 'noCreditNotesYet' );
		}
		if ( 'inv' === tableState.type ) {
			return t( 'noInvoicesYet' );
		}
		return t( 'noDocumentsYet' );
	}

	var renderedDocs = [];

	function buildRowHtml( doc, idx ) {
		var actions = '<button type="button" class="billio-ib billio-act-preview" data-index="' + idx + '" aria-label="' + escapeAttr( t( 'previewDocument' ) ) + '" title="' + escapeAttr( t( 'previewDocument' ) ) + '">' +
			'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>' +
			'<button type="button" class="billio-ib billio-act-download" data-url="' + escapeAttr( doc.downloadUrl ) + '" data-number="' + escapeAttr( doc.number ) + '" aria-label="' + escapeAttr( t( 'downloadPdf' ) ) + '" title="' + escapeAttr( t( 'downloadPdf' ) ) + '">' +
			'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg></button>';
		if ( 'inv' === doc.type ) {
			actions += '<button type="button" class="billio-ib billio-act-resend" data-order-id="' + doc.orderId + '" aria-label="' + escapeAttr( t( 'resendEmail' ) ) + '" title="' + escapeAttr( t( 'resendEmail' ) ) + '">' +
				'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg></button>' +
				'<button type="button" class="billio-ib billio-act-regen" data-order-id="' + doc.orderId + '" data-number="' + escapeAttr( doc.number ) + '" aria-label="' + escapeAttr( t( 'regenerate' ) ) + '" title="' + escapeAttr( t( 'regenerate' ) ) + '">' +
				'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg></button>';
		}

		return '<tr class="billio-doc-row" data-index="' + idx + '">' +
			'<td><span class="billio-type ' + ( doc.type === 'cn' ? 'billio-cn' : '' ) + '">' + ( doc.type === 'cn' ? 'CN' : 'INV' ) + '</span> <span class="billio-num">' + escapeHtml( doc.number ) + '</span></td>' +
			'<td class="billio-hm"><a href="' + escapeAttr( doc.orderEditUrl ) + '" style="color:var(--billio-brand)" onclick="event.stopPropagation()">#' + escapeHtml( doc.orderNumber ) + '</a></td>' +
			'<td><div class="billio-cust"><span class="billio-av" style="background:' + avatarColor( doc.customerName || '?' ) + '">' + escapeHtml( initials( doc.customerName || '?' ) ) + '</span>' + escapeHtml( doc.customerName ) + '</div></td>' +
			'<td class="billio-hm"><span class="billio-pill ' + statusPillClass( doc.orderStatus ) + '">' + escapeHtml( doc.orderStatusLabel ) + '</span></td>' +
			'<td class="billio-hm" style="color:var(--billio-ink2)">' + escapeHtml( doc.dateDisplay ) + '</td>' +
			'<td class="billio-r billio-num" style="' + ( doc.total < 0 ? 'color:var(--billio-bad)' : '' ) + '">' + fmtMoney( doc.total ) + '</td>' +
			'<td><div class="billio-acts">' + actions + '</div></td>' +
		'</tr>';
	}

	function renderRows( docs ) {
		renderedDocs = docs;
		document.getElementById( 'billio-rows' ).innerHTML = docs.map( function ( doc, idx ) {
			return buildRowHtml( doc, idx );
		} ).join( '' );
		wireRowActions();
	}

	function renderPager( total, page, perPage ) {
		var pagerEl = document.getElementById( 'billio-pager' );
		var totalPages = Math.max( 1, Math.ceil( total / perPage ) );

		if ( totalPages <= 1 ) {
			pagerEl.innerHTML = '';
			return;
		}

		pagerEl.innerHTML =
			'<button type="button" class="billio-ib" id="billio-page-prev" aria-label="' + escapeAttr( t( 'previousPage' ) ) + '"' + ( page <= 1 ? ' disabled' : '' ) + '>‹</button>' +
			'<span class="billio-page-info">' + page + ' / ' + totalPages + '</span>' +
			'<button type="button" class="billio-ib" id="billio-page-next" aria-label="' + escapeAttr( t( 'nextPage' ) ) + '"' + ( page >= totalPages ? ' disabled' : '' ) + '>›</button>';

		var prevBtn = document.getElementById( 'billio-page-prev' );
		var nextBtn = document.getElementById( 'billio-page-next' );
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				if ( tableState.page > 1 ) {
					tableState.page--;
					renderTable();
				}
			} );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				if ( tableState.page < totalPages ) {
					tableState.page++;
					renderTable();
				}
			} );
		}
	}

	// With no active search term, the table renders from the ≤10 documents
	// already localized on page load — identical to pre-Bug-C behavior,
	// no server round trip, no pagination. A non-empty search term instead
	// queries billio_dashboard_search_documents, which searches every
	// invoice/credit note (not just these 10) and returns one paginated
	// page of results.
	function renderTable() {
		var pagerEl = document.getElementById( 'billio-pager' );
		var truncatedEl = document.getElementById( 'billio-truncated-notice' );

		if ( ! tableState.q ) {
			pagerEl.innerHTML = '';
			truncatedEl.textContent = '';

			var docs = data.recentDocuments;
			var filtered = docs.filter( function ( doc ) {
				return tableState.type === 'all' || doc.type === tableState.type;
			} );

			if ( ! docs.length ) {
				renderEmptyRow( t( 'noDocumentsYet' ) );
				document.getElementById( 'billio-cnt' ).textContent = '';
				return;
			}

			if ( ! filtered.length ) {
				renderEmptyRow( emptyStateMessage() );
			} else {
				renderRows( filtered );
			}
			document.getElementById( 'billio-cnt' ).textContent = tf( 'showingOf', filtered.length, docs.length );
			return;
		}

		var requestSeq = ++searchRequestSeq;
		post( 'billio_dashboard_search_documents', {
			q: tableState.q,
			type: tableState.type,
			page: tableState.page,
			per_page: SEARCH_PER_PAGE,
		} ).then( function ( res ) {
			if ( requestSeq !== searchRequestSeq ) {
				return;
			}
			if ( ! res || ! res.success ) {
				renderEmptyRow( t( 'error' ) );
				document.getElementById( 'billio-cnt' ).textContent = '';
				pagerEl.innerHTML = '';
				truncatedEl.textContent = '';
				return;
			}

			var items = res.data.items;
			if ( ! items.length ) {
				renderEmptyRow( emptyStateMessage() );
			} else {
				renderRows( items );
			}
			document.getElementById( 'billio-cnt' ).textContent = tf( 'showingOf', items.length, res.data.total );
			renderPager( res.data.total, res.data.page, res.data.perPage );
			// textContent, never innerHTML: this is a plain status line, and
			// the i18n string shouldn't be trusted as pre-escaped HTML.
			truncatedEl.textContent = res.data.truncated ? t( 'searchResultsLimited' ) : '';
		} );
	}

	function wireRowActions() {
		document.querySelectorAll( '.billio-doc-row' ).forEach( function ( row ) {
			row.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'button, a' ) ) {
					return;
				}
				var idx = parseInt( row.dataset.index, 10 );
				if ( renderedDocs[ idx ] ) {
					openDrawer( renderedDocs[ idx ] );
				}
			} );
		} );

		document.querySelectorAll( '.billio-act-preview' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var idx = parseInt( btn.dataset.index, 10 );
				if ( renderedDocs[ idx ] ) {
					openDrawer( renderedDocs[ idx ] );
				}
			} );
		} );

		document.querySelectorAll( '.billio-act-download' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				toast( t( 'downloadingPdf' ) );
				window.open( btn.dataset.url, '_blank', 'noopener' );
			} );
		} );

		document.querySelectorAll( '.billio-act-resend' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				btn.disabled = true;
				postOrderAction( 'billio_resend_invoice', btn.dataset.orderId ).then( function ( res ) {
					btn.disabled = false;
					var message = res && res.data && res.data.message;
					toast( message || ( res && res.success ? t( 'invoiceEmailed' ) : t( 'error' ) ), ! ( res && res.success ) );
				} );
			} );
		} );

		document.querySelectorAll( '.billio-act-regen' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var orderId = btn.dataset.orderId;
				var number = btn.dataset.number;
				openModal(
					'<h3>' + escapeHtml( t( 'confirmRegenerateTitle' ) ) + '</h3>' +
					'<p>' + escapeHtml( t( 'confirmRegenerateBody' ) ) + '</p>' +
					'<div class="billio-f"><button type="button" class="billio-btn" id="billio-regen-cancel">' + escapeHtml( t( 'cancel' ) ) + '</button>' +
					'<button type="button" class="billio-btn billio-pri" id="billio-regen-go">' + escapeHtml( t( 'regenerate' ) ) + '</button></div>'
				);
				document.getElementById( 'billio-regen-cancel' ).addEventListener( 'click', closeModal );
				document.getElementById( 'billio-regen-go' ).addEventListener( 'click', function () {
					closeModal();
					postOrderAction( 'billio_regenerate_invoice', orderId ).then( function ( res ) {
						if ( res && res.success ) {
							toast( tf( 'regenerated', number ) );
						} else {
							toast( ( res && res.data && res.data.message ) || t( 'error' ), true );
						}
					} );
				} );
			} );
		} );
	}

	function wireTableControls() {
		document.querySelectorAll( '#billio-ftype button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.querySelectorAll( '#billio-ftype button' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.setAttribute( 'aria-pressed', 'true' );
				tableState.type = btn.dataset.t;
				tableState.page = 1;
				renderTable();
			} );
		} );
		document.getElementById( 'billio-q' ).addEventListener( 'input', function ( e ) {
			tableState.q = e.target.value;
			tableState.page = 1;
			clearTimeout( searchDebounceTimer );
			if ( ! tableState.q ) {
				// Clearing the box drops straight back to the fast local
				// path — no need to wait out the debounce for that.
				renderTable();
				return;
			}
			searchDebounceTimer = setTimeout( renderTable, 300 );
		} );
	}

	/* ---------------------------------------------------------------
	 * Generate-missing modal
	 * ------------------------------------------------------------- */

	function openGenerateModal() {
		var cancelled = false;
		openModal(
			'<h3>' + escapeHtml( t( 'generateModalTitle' ) ) + '</h3>' +
			'<p>' + escapeHtml( t( 'generateModalNote' ) ) + '</p>' +
			'<div class="billio-prog"><i id="billio-prog-bar"></i></div>' +
			'<div id="billio-prog-text" style="font-size:12.5px;color:var(--billio-muted);margin:-8px 0 16px">' + escapeHtml( t( 'ready' ) ) + '</div>' +
			'<div class="billio-f"><button type="button" class="billio-btn" id="billio-gen-cancel">' + escapeHtml( t( 'cancel' ) ) + '</button>' +
			'<button type="button" class="billio-btn billio-pri" id="billio-gen-start">' + escapeHtml( t( 'start' ) ) + '</button></div>',
			null,
			function () {
				cancelled = true;
			}
		);

		document.getElementById( 'billio-gen-cancel' ).addEventListener( 'click', function () {
			cancelled = true;
			closeModal();
		} );

		document.getElementById( 'billio-gen-start' ).addEventListener( 'click', function () {
			var startBtn = document.getElementById( 'billio-gen-start' );
			startBtn.disabled = true;

			post( 'billio_dashboard_missing_order_ids', {} ).then( function ( idsRes ) {
				if ( ! idsRes || ! idsRes.success ) {
					toast( t( 'error' ), true );
					return;
				}
				var ids = idsRes.data.orderIds;
				var total = ids.length;
				var done = 0;
				var generatedCount = 0;

				function nextBatch() {
					if ( cancelled || done >= total ) {
						if ( ! cancelled ) {
							closeModal();
							toast( tf( 'generatedCount', generatedCount ) );
							data.kpis.ordersWithoutInvoice = Math.max( 0, data.kpis.ordersWithoutInvoice - generatedCount );
							renderKpis();
							buildSkeletonHeaderOnly();
						}
						return;
					}
					var batch = ids.slice( done, done + 5 );
					post( 'billio_dashboard_generate_batch', { order_ids: batch } ).then( function ( res ) {
						if ( res && res.success ) {
							res.data.results.forEach( function ( r ) {
								if ( r.success ) {
									generatedCount++;
								}
							} );
						}
						done += batch.length;
						var pct = Math.round( ( done / total ) * 100 );
						var bar = document.getElementById( 'billio-prog-bar' );
						var txt = document.getElementById( 'billio-prog-text' );
						if ( bar ) {
							bar.style.width = pct + '%';
						}
						if ( txt ) {
							var lastNumber = res && res.data && res.data.results.length
								? ( res.data.results[ res.data.results.length - 1 ].invoiceNumber || '' )
								: '';
							txt.textContent = tf( 'progressOf', done, total, lastNumber );
						}
						nextBatch();
					} );
				}

				if ( total === 0 ) {
					closeModal();
					toast( tf( 'generatedCount', 0 ) );
					return;
				}

				nextBatch();
			} );
		} );
	}

	function buildSkeletonHeaderOnly() {
		var missingCount = data.kpis.ordersWithoutInvoice;
		var qaBadge = document.getElementById( 'billio-qa-missing-badge' );
		var qaGenBtn = document.getElementById( 'billio-qa-generate' );
		var statusSlot = document.getElementById( 'billio-header-status-slot' );

		if ( missingCount > 0 ) {
			if ( qaBadge ) {
				qaBadge.textContent = missingCount;
			} else if ( qaGenBtn ) {
				var newBadge = document.createElement( 'span' );
				newBadge.className = 'billio-qa-badge';
				newBadge.id = 'billio-qa-missing-badge';
				newBadge.textContent = missingCount;
				qaGenBtn.appendChild( newBadge );
			}
			if ( statusSlot ) {
				statusSlot.innerHTML = '';
			}
		} else {
			if ( qaBadge ) {
				qaBadge.remove();
			}
			if ( statusSlot ) {
				statusSlot.innerHTML = '<span class="billio-success-badge" id="billio-header-status"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>' + escapeHtml( t( 'allInvoiced' ) ) + '</span>';
			}
		}
	}

	/* ---------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------- */

	buildSkeleton();
	renderSetup();
	renderKpis();
	renderReview();
	renderConfigCard();
	renderTable();
	wireChartControls();
	wireTableControls();
	drawChart();
}() );
