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
		return str.replace( /%(\d\$)?[ds]/g, function () {
			return args[ i++ ];
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

	function openModal( html, onOpen ) {
		modalReturnFocus = document.activeElement;
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
		ov.classList.remove( 'billio-show' );
		modalEl.innerHTML = '';
		if ( modalReturnFocus && typeof modalReturnFocus.focus === 'function' ) {
			modalReturnFocus.focus();
		}
	}

	ov.addEventListener( 'click', function ( e ) {
		if ( e.target === ov ) {
			closeModal();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ov.classList.contains( 'billio-show' ) ) {
			closeModal();
			return;
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
		}
	} );

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
		var headerBtn = missingCount > 0
			? '<button type="button" class="billio-btn billio-pri" id="billio-open-generate">' +
				'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>' +
				escapeHtml( tf( 'generateMissing', missingCount ) ) + '</button>'
			: '<span class="billio-success-badge"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>' + escapeHtml( t( 'allInvoiced' ) ) + '</span>';

		root.innerHTML =
			'<div class="billio-hdr">' +
				'<div class="billio-logo">' + iconGeneric() + '</div>' +
				'<div><h2>Billio <span class="billio-ver">v' + escapeHtml( root.dataset.version || '' ) + '</span></h2>' +
				'<div class="billio-sub">' + escapeHtml( 'Invoices, credit notes & packing slips for your WooCommerce store' ) + '</div></div>' +
				'<div class="billio-sp"></div>' +
				'<a class="billio-btn" href="' + escapeHtml( data.urls.preview ) + '">' + escapeHtml( 'Preview template' ) + '</a>' +
				'<a class="billio-btn" href="' + escapeHtml( data.urls.settings ) + '">' + escapeHtml( 'Settings' ) + '</a>' +
				headerBtn +
			'</div>' +
			'<div id="billio-setup-slot"></div>' +
			'<div class="billio-kpis" id="billio-kpis"></div>' +
			'<div id="billio-review-slot"></div>' +
			'<div class="billio-grid">' +
				'<div class="billio-card billio-chart-card">' +
					'<div class="billio-ch"><h3>' + escapeHtml( 'Invoice activity' ) + '</h3><div class="billio-sp"></div>' +
						'<div class="billio-seg" id="billio-metric-seg">' +
							'<button type="button" aria-pressed="true" data-m="invoices">' + escapeHtml( 'Invoices' ) + '</button>' +
							'<button type="button" aria-pressed="false" data-m="revenue">' + escapeHtml( 'Revenue' ) + '</button>' +
						'</div>' +
						'<div class="billio-seg" id="billio-range-seg">' +
							'<button type="button" aria-pressed="false" data-r="7">7D</button>' +
							'<button type="button" aria-pressed="true" data-r="30">30D</button>' +
							'<button type="button" aria-pressed="false" data-r="90">90D</button>' +
						'</div>' +
					'</div>' +
					'<div class="billio-chart-summary">' +
						'<div><div class="billio-label">' + escapeHtml( 'Total in range' ) + '</div><div class="billio-value" id="billio-chart-tot">—</div></div>' +
						'<div><div class="billio-label">' + escapeHtml( 'Daily average' ) + '</div><div class="billio-value" id="billio-chart-avg">—</div></div>' +
						'<div><div class="billio-label">' + escapeHtml( 'Best day' ) + '</div><div class="billio-value" id="billio-chart-best">—</div></div>' +
					'</div>' +
					'<div class="billio-chartbox" id="billio-chart" aria-label="' + escapeHtml( 'Invoice activity chart' ) + '">' +
						'<div class="billio-tip" id="billio-tip"></div>' +
					'</div>' +
					'<table class="billio-sr-table" id="billio-chart-table"><caption>' + escapeHtml( 'Invoice activity, by day' ) + '</caption></table>' +
					'<div class="billio-legend">' +
						'<span><i style="background:#4f46e5"></i>' + escapeHtml( 'Invoices' ) + '</span>' +
						'<span><i style="background:#f87171"></i>' + escapeHtml( 'Credit notes' ) + '</span>' +
					'</div>' +
				'</div>' +
				'<div class="billio-card">' +
					'<div class="billio-ch"><h3>' + escapeHtml( 'Invoice setup at a glance' ) + '</h3></div>' +
					'<div class="billio-cfg" id="billio-cfg"></div>' +
				'</div>' +
			'</div>' +
			'<div class="billio-card">' +
				'<div class="billio-ch"><h3>' + escapeHtml( 'Recent documents' ) + '</h3><div class="billio-sp"></div>' +
					'<div class="billio-seg" id="billio-ftype">' +
						'<button type="button" aria-pressed="true" data-t="all">' + escapeHtml( 'All' ) + '</button>' +
						'<button type="button" aria-pressed="false" data-t="inv">' + escapeHtml( 'Invoices' ) + '</button>' +
						'<button type="button" aria-pressed="false" data-t="cn">' + escapeHtml( 'Credit notes' ) + '</button>' +
					'</div>' +
				'</div>' +
				'<div class="billio-tools"><div class="billio-search">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
					'<input type="search" id="billio-q" placeholder="' + escapeHtml( 'Search by number, order or customer…' ) + '">' +
				'</div></div>' +
				'<div class="billio-table-wrap"><table class="billio-tbl">' +
					'<thead><tr>' +
						'<th>' + escapeHtml( 'Document' ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( 'Order' ) + '</th>' +
						'<th>' + escapeHtml( 'Customer' ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( 'Order status' ) + '</th>' +
						'<th class="billio-hm">' + escapeHtml( 'Date' ) + '</th>' +
						'<th class="billio-r">' + escapeHtml( 'Total' ) + '</th>' +
						'<th class="billio-r">' + escapeHtml( 'Actions' ) + '</th>' +
					'</tr></thead>' +
					'<tbody id="billio-rows"></tbody>' +
				'</table></div>' +
				'<div class="billio-tfoot"><span id="billio-cnt"></span><a class="billio-lnk" href="' + escapeHtml( data.urls.ordersList ) + '">' + escapeHtml( t( 'viewAllInOrders' ) ) + '</a></div>' +
			'</div>';
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
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#eef2ff;color:#4f46e5">' + iconGeneric() + '</span>' + escapeHtml( 'Invoices this month' ) + '</div>' +
				'<div class="billio-v" id="billio-kpi-inv">0</div>' +
				'<div class="billio-d">' + trendHtml( k.invoicesThisMonth, k.invoicesLastMonth ) + '</div>' + sparkline( '#4f46e5' ) +
			'</div>' +
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#ecfdf5;color:#059669">' + iconGeneric() + '</span>' + escapeHtml( 'Revenue invoiced this month' ) + '</div>' +
				'<div class="billio-v" id="billio-kpi-rev">' + fmtMoney( 0 ) + '</div>' +
				'<div class="billio-d">' + trendHtml( k.revenueThisMonth, k.revenueLastMonth ) + '</div>' + sparkline( '#059669' ) +
			'</div>' +
			'<div class="billio-kpi"><div class="billio-top"><span class="billio-ic" style="background:#fef2f2;color:#dc2626">' + iconGeneric() + '</span>' + escapeHtml( 'Credit notes this month' ) + '</div>' +
				'<div class="billio-v">' + k.creditNotesThisMonth + '</div>' +
				'<div class="billio-d">' + ( k.creditNotesRefundedTotal > 0
					? '<span class="billio-dn">−' + fmtMoney( k.creditNotesRefundedTotal ) + '</span> refunded this month'
					: escapeHtml( t( 'noRefundsThisMonth' ) ) ) +
				'</div>' +
			'</div>' +
			'<div class="billio-kpi' + ( k.ordersWithoutInvoice > 0 ? ' billio-alert' : '' ) + '"><div class="billio-top"><span class="billio-ic" style="background:#fef3c7;color:#d97706">' + iconGeneric() + '</span>' + escapeHtml( 'Orders without invoice' ) + '</div>' +
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

		if ( s.allDone || s.dismissed ) {
			slot.innerHTML = '';
			return;
		}

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
				return '<a class="billio-step billio-todo" href="' + escapeHtml( data.urls.settings + step.anchor ) + '">＋ ' + escapeHtml( step.label ) + ' →</a>';
			}
			return '<span class="billio-step">○ ' + escapeHtml( step.label ) + '</span>';
		} ).join( '' );

		var pct = ( s.done / s.total ) * 100;

		slot.innerHTML =
			'<div class="billio-setup" id="billio-setup">' +
				'<button type="button" class="billio-x" id="billio-setup-dismiss" aria-label="' + escapeHtml( 'Hide' ) + '">×</button>' +
				'<div class="billio-ring"><svg viewBox="0 0 36 36" width="72" height="72">' +
					'<circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="3"/>' +
					'<circle cx="18" cy="18" r="15.9" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-dasharray="' + pct + ' 100" transform="rotate(-90 18 18)"/>' +
				'</svg><b>' + s.done + '/' + s.total + '</b></div>' +
				'<div style="flex:1;min-width:240px;position:relative;z-index:1">' +
					'<h3>' + escapeHtml( 'Finish setting up Billio' ) + '</h3>' +
					'<p>' + escapeHtml( 'A few quick steps make every invoice fully branded and compliant.' ) + '</p>' +
					'<div class="billio-steps">' + stepsHtml + '</div>' +
				'</div>' +
			'</div>';

		document.getElementById( 'billio-setup-dismiss' ).addEventListener( 'click', function () {
			document.getElementById( 'billio-setup' ).remove();
			post( 'billio_dashboard_dismiss_setup', {} );
		} );
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
				escapeHtml( 'Billio has created ' + r.total + ' invoices for you. Enjoying it?' ) +
				'<span class="billio-sp"></span>' +
				'<a class="billio-lnk" href="' + escapeHtml( r.url ) + '" target="_blank" rel="noopener">' + escapeHtml( 'Leave a review' ) + '</a>' +
				'<button type="button" class="billio-lnk" id="billio-review-dismiss" style="color:var(--billio-muted)">' + escapeHtml( 'Not now' ) + '</button>' +
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
		var rows = '<tr><th>' + escapeHtml( 'Date' ) + '</th><th>' + escapeHtml( 'Invoices' ) + '</th><th>' + escapeHtml( 'Revenue' ) + '</th><th>' + escapeHtml( 'Credit notes' ) + '</th></tr>';
		days.forEach( function ( d ) {
			rows += '<tr><td>' + escapeHtml( d.date ) + '</td><td>' + d.invoices + '</td><td>' + fmtMoney( d.revenue ) + '</td><td>' + d.creditNotes + '</td></tr>';
		} );
		srTable.innerHTML = '<caption>' + escapeHtml( 'Invoice activity, by day' ) + '</caption>' + rows;
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
			'<div class="billio-row"><span class="billio-ic">#</span><div class="billio-t"><small>' + escapeHtml( 'Next invoice number' ) + '</small><span class="billio-mono">' + escapeHtml( data.nextInvoiceNumber ) + '</span></div><a class="billio-lnk" href="' + escapeHtml( data.urls.editFormatField ) + '">' + escapeHtml( 'Edit format' ) + '</a></div>' +
			'<div class="billio-row"><span class="billio-ic">✉</span><div class="billio-t"><small>' + escapeHtml( 'Attach to email' ) + '</small><span>' + escapeHtml( 'Processing order' ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-processing" role="switch" aria-checked="' + ( processingOn ? 'true' : 'false' ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic">✉</span><div class="billio-t"><small>' + escapeHtml( 'Attach to email' ) + '</small><span>' + escapeHtml( 'Completed order' ) + '</span></div><button type="button" class="billio-tg" id="billio-tg-completed" role="switch" aria-checked="' + ( completedOn ? 'true' : 'false' ) + '"></button></div>' +
			'<div class="billio-row"><span class="billio-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/></svg></span><div class="billio-t"><small>' + escapeHtml( 'Paper size' ) + '</small><span id="billio-paper-value">' + escapeHtml( qs.paperSize ) + '</span></div><div class="billio-seg" id="billio-paper-seg">' +
				'<button type="button" data-v="A4" aria-pressed="' + ( qs.paperSize === 'A4' ? 'true' : 'false' ) + '">A4</button>' +
				'<button type="button" data-v="Letter" aria-pressed="' + ( qs.paperSize === 'Letter' ? 'true' : 'false' ) + '">Letter</button>' +
			'</div></div>' +
			'<div class="billio-row" style="flex-direction:column;align-items:stretch">' +
				'<small style="color:var(--billio-muted);font-size:12px">' + escapeHtml( 'Invoice template' ) + '</small>' +
				'<div class="billio-tpl" id="billio-tpl-seg">' +
					'<button type="button" data-v="default" aria-pressed="' + ( qs.template === 'default' ? 'true' : 'false' ) + '"><div class="billio-mini"><i style="width:40%"></i><i></i><i style="width:70%"></i><i></i></div>' + escapeHtml( 'Default' ) + '</button>' +
					'<button type="button" data-v="modern" aria-pressed="' + ( qs.template === 'modern' ? 'true' : 'false' ) + '"><div class="billio-mini billio-modern"><i></i><i style="width:60%"></i><i></i><i style="width:80%"></i></div>' + escapeHtml( 'Modern' ) + '</button>' +
				'</div>' +
			'</div>';

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

	var tableState = { type: 'all', q: '' };

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

	function renderTable() {
		var docs = data.recentDocuments;
		var q = tableState.q.toLowerCase();
		var filtered = docs.filter( function ( doc ) {
			var matchesType = tableState.type === 'all' || doc.type === tableState.type;
			var haystack = ( doc.number + ' #' + doc.orderNumber + ' ' + doc.customerName ).toLowerCase();
			return matchesType && haystack.indexOf( q ) !== -1;
		} );

		var rowsEl = document.getElementById( 'billio-rows' );

		if ( ! docs.length ) {
			renderEmptyRow( t( 'noDocumentsYet' ) );
			document.getElementById( 'billio-cnt' ).textContent = '';
			return;
		}

		if ( ! filtered.length ) {
			renderEmptyRow( emptyStateMessage() );
		} else {
			rowsEl.innerHTML = filtered.map( function ( doc ) {
				var actions = '<button type="button" class="billio-ib billio-act-download" data-url="' + escapeHtml( doc.downloadUrl ) + '" data-number="' + escapeHtml( doc.number ) + '" aria-label="' + escapeHtml( 'Download PDF' ) + '" title="' + escapeHtml( 'Download PDF' ) + '">' +
					'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg></button>';
				if ( 'inv' === doc.type ) {
					actions += '<button type="button" class="billio-ib billio-act-resend" data-order-id="' + doc.orderId + '" aria-label="' + escapeHtml( 'Resend email' ) + '" title="' + escapeHtml( 'Resend email' ) + '">' +
						'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg></button>' +
						'<button type="button" class="billio-ib billio-act-regen" data-order-id="' + doc.orderId + '" data-number="' + escapeHtml( doc.number ) + '" aria-label="' + escapeHtml( 'Regenerate' ) + '" title="' + escapeHtml( 'Regenerate' ) + '">' +
						'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg></button>';
				}

				return '<tr>' +
					'<td><span class="billio-type ' + ( doc.type === 'cn' ? 'billio-cn' : '' ) + '">' + ( doc.type === 'cn' ? 'CN' : 'INV' ) + '</span> <span class="billio-num">' + escapeHtml( doc.number ) + '</span></td>' +
					'<td class="billio-hm"><a href="' + escapeHtml( doc.orderEditUrl ) + '" style="color:var(--billio-brand)">#' + escapeHtml( doc.orderNumber ) + '</a></td>' +
					'<td><div class="billio-cust"><span class="billio-av" style="background:' + avatarColor( doc.customerName || '?' ) + '">' + escapeHtml( initials( doc.customerName || '?' ) ) + '</span>' + escapeHtml( doc.customerName ) + '</div></td>' +
					'<td class="billio-hm"><span class="billio-pill ' + statusPillClass( doc.orderStatus ) + '">' + escapeHtml( doc.orderStatusLabel ) + '</span></td>' +
					'<td class="billio-hm" style="color:var(--billio-ink2)">' + escapeHtml( doc.dateDisplay ) + '</td>' +
					'<td class="billio-r billio-num" style="' + ( doc.total < 0 ? 'color:var(--billio-bad)' : '' ) + '">' + fmtMoney( doc.total ) + '</td>' +
					'<td><div class="billio-acts">' + actions + '</div></td>' +
				'</tr>';
			} ).join( '' );
		}

		document.getElementById( 'billio-cnt' ).textContent = tf( 'showingOf', filtered.length, docs.length );
		wireRowActions();
	}

	function wireRowActions() {
		document.querySelectorAll( '.billio-act-download' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				toast( t( 'downloadingPdf' ) );
				window.open( btn.dataset.url, '_blank', 'noopener' );
			} );
		} );
		document.querySelectorAll( '.billio-act-resend' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				postOrderAction( 'billio_resend_invoice', btn.dataset.orderId ).then( function ( res ) {
					btn.disabled = false;
					var message = res && res.data && res.data.message;
					toast( message || ( res && res.success ? t( 'invoiceEmailed' ) : t( 'error' ) ), ! ( res && res.success ) );
				} );
			} );
		} );
		document.querySelectorAll( '.billio-act-regen' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
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
				renderTable();
			} );
		} );
		document.getElementById( 'billio-q' ).addEventListener( 'input', function ( e ) {
			tableState.q = e.target.value;
			renderTable();
		} );
	}

	/* ---------------------------------------------------------------
	 * Generate-missing modal
	 * ------------------------------------------------------------- */

	function openGenerateModal() {
		openModal(
			'<h3>' + escapeHtml( t( 'generateModalTitle' ) ) + '</h3>' +
			'<p>' + escapeHtml( t( 'generateModalNote' ) ) + '</p>' +
			'<div class="billio-prog"><i id="billio-prog-bar"></i></div>' +
			'<div id="billio-prog-text" style="font-size:12.5px;color:var(--billio-muted);margin:-8px 0 16px">' + escapeHtml( t( 'ready' ) ) + '</div>' +
			'<div class="billio-f"><button type="button" class="billio-btn" id="billio-gen-cancel">' + escapeHtml( t( 'cancel' ) ) + '</button>' +
			'<button type="button" class="billio-btn billio-pri" id="billio-gen-start">' + escapeHtml( t( 'start' ) ) + '</button></div>'
		);

		var cancelled = false;
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
		// Re-render just the header's primary button after a batch completes.
		var missingCount = data.kpis.ordersWithoutInvoice;
		var hdr = root.querySelector( '.billio-hdr' );
		if ( ! hdr ) {
			return;
		}
		var old = hdr.querySelector( '#billio-open-generate, .billio-success-badge' );
		if ( ! old ) {
			return;
		}
		if ( missingCount > 0 ) {
			old.outerHTML = '<button type="button" class="billio-btn billio-pri" id="billio-open-generate">' +
				'<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>' +
				escapeHtml( tf( 'generateMissing', missingCount ) ) + '</button>';
			document.getElementById( 'billio-open-generate' ).addEventListener( 'click', openGenerateModal );
		} else {
			old.outerHTML = '<span class="billio-success-badge"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>' + escapeHtml( t( 'allInvoiced' ) ) + '</span>';
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

	var openGenBtn = document.getElementById( 'billio-open-generate' );
	if ( openGenBtn ) {
		openGenBtn.addEventListener( 'click', openGenerateModal );
	}
}() );
