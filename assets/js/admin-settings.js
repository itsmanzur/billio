( function () {
	'use strict';

	var data = window.billioSettings;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n;
	var form = document.getElementById( 'billio-settings-form' );
	if ( ! form ) {
		return;
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
	 * Toast (same shape as the Dashboard's, a fresh instance here since
	 * that script isn't loaded on this page)
	 * ------------------------------------------------------------- */

	var toastEl = document.createElement( 'div' );
	toastEl.className = 'billio-toast';
	toastEl.setAttribute( 'role', 'status' );
	toastEl.setAttribute( 'aria-live', 'polite' );
	toastEl.innerHTML = '<span class="billio-toast-icon"></span><span class="billio-toast-text"></span>';
	document.body.appendChild( toastEl );
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
	 * Dirty-tracking + save bar + Ctrl+S + beforeunload
	 * ------------------------------------------------------------- */

	var dirty = new Set();
	var isSubmitting = false;
	var savebar = document.getElementById( 'billio-savebar' );
	var savebarCount = document.getElementById( 'billio-savebar-count' );
	var topSave = document.getElementById( 'billio-top-save' );

	function markDirty( key ) {
		dirty.add( key );
		savebarCount.textContent = 1 === dirty.size ? tf( 'unsavedChange', dirty.size ) : tf( 'unsavedChanges', dirty.size );
		savebar.classList.add( 'billio-show' );
		topSave.disabled = false;
	}

	function clearDirty() {
		dirty.clear();
		savebar.classList.remove( 'billio-show' );
		topSave.disabled = true;
	}

	function isFormatValid() {
		return document.getElementById( 'billio_number_format' ).value.indexOf( '{NUMBER}' ) !== -1;
	}

	function anyEmailStatusOn() {
		return document.getElementById( 'billio-trigger-processing' ).checked || document.getElementById( 'billio-trigger-completed' ).checked;
	}

	function validateBeforeSave() {
		if ( ! isFormatValid() ) {
			document.getElementById( 'billio-sec-numbering' ).scrollIntoView( { behavior: 'smooth' } );
			document.getElementById( 'billio_number_format' ).focus();
			toast( t( 'fixFormatFirst' ), true );
			return false;
		}
		if ( ! anyEmailStatusOn() ) {
			return window.confirm( t( 'confirmNoEmailStatus' ) ); // eslint-disable-line no-alert
		}
		return true;
	}

	function doSave() {
		if ( ! validateBeforeSave() ) {
			return;
		}
		isSubmitting = true;
		clearDirty();
		form.submit();
	}

	topSave.addEventListener( 'click', doSave );
	document.getElementById( 'billio-savebar-save' ).addEventListener( 'click', doSave );
	document.getElementById( 'billio-discard' ).addEventListener( 'click', function () {
		clearDirty();
		window.location.reload();
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key ) {
			e.preventDefault();
			if ( dirty.size ) {
				doSave();
			}
		}
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! isSubmitting && dirty.size ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// A real submit (Enter key in a text field, etc.) still needs the same
	// validation + confirm as the buttons above.
	form.addEventListener( 'submit', function ( e ) {
		if ( ! validateBeforeSave() ) {
			e.preventDefault();
			isSubmitting = false;
		} else {
			isSubmitting = true;
			clearDirty();
		}
	} );

	/* ---------------------------------------------------------------
	 * Profile-completeness bar
	 * ------------------------------------------------------------- */

	function updateProfile() {
		var hasLogo = !! document.getElementById( 'billio_company_logo_id' ).value && '0' !== document.getElementById( 'billio_company_logo_id' ).value;
		var hasName = !! document.getElementById( 'billio_company_name' ).value.trim();
		var hasAddress = !! document.getElementById( 'billio_company_address' ).value.trim();
		var hasEmail = anyEmailStatusOn();
		var done = [ hasLogo, hasName, hasAddress, hasEmail ].filter( Boolean ).length;
		var pct = Math.round( ( done / 4 ) * 100 );
		document.getElementById( 'billio-profile-pct' ).textContent = 100 === pct ? ( '✓ ' + t( 'profileComplete' ) ) : tf( 'profilePctComplete', pct );
		document.getElementById( 'billio-profile-bar-fill' ).style.width = pct + '%';
	}

	/* ---------------------------------------------------------------
	 * Company section: name/address/vat live-bind + dirty + profile
	 * ------------------------------------------------------------- */

	document.getElementById( 'billio_company_name' ).addEventListener( 'input', function () {
		markDirty( 'name' );
		updateProfile();
		schedulePreview();
	} );

	var addrField = document.getElementById( 'billio_company_address' );
	var addrHint = document.getElementById( 'billio-addr-hint' );
	var addrReq = document.getElementById( 'billio-addr-req' );
	var navDot = document.getElementById( 'billio-nav-dot' );

	addrField.addEventListener( 'input', function () {
		var has = !! addrField.value.trim();
		addrHint.hidden = has;
		addrReq.hidden = has;
		navDot.hidden = has;
		markDirty( 'addr' );
		updateProfile();
		schedulePreview();
	} );

	document.getElementById( 'billio_company_vat' ).addEventListener( 'input', function () {
		markDirty( 'vat' );
		schedulePreview();
	} );

	/* ---------------------------------------------------------------
	 * Logo dropzone: click → wp.media, drag-drop → async upload
	 * ------------------------------------------------------------- */

	var drop = document.getElementById( 'billio-drop' );
	var logoIdField = document.getElementById( 'billio_company_logo_id' );
	var logoPreviewImg = document.getElementById( 'billio-logo-preview' );
	var logoPlaceholder = document.getElementById( 'billio-logo-placeholder' );
	var logoFilename = document.getElementById( 'billio-logo-filename' );
	var logoRemoveBtn = document.getElementById( 'billio-logo-remove' );

	function setLogo( attachment ) {
		logoIdField.value = attachment.id;
		var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
		logoPreviewImg.src = url;
		logoPreviewImg.style.display = '';
		logoPlaceholder.style.display = 'none';
		logoFilename.textContent = attachment.filename || url.split( '/' ).pop();
		logoRemoveBtn.style.display = '';
		markDirty( 'logo' );
		updateProfile();
		schedulePreview();
	}

	function clearLogo() {
		logoIdField.value = '0';
		logoPreviewImg.style.display = 'none';
		logoPreviewImg.src = '';
		logoPlaceholder.style.display = '';
		logoFilename.textContent = t( 'logoRemoved' );
		logoRemoveBtn.style.display = 'none';
		markDirty( 'logo' );
		updateProfile();
		schedulePreview();
	}

	document.getElementById( 'billio-logo-replace' ).addEventListener( 'click', function () {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( {
			title: data.mediaTitle,
			button: { text: data.mediaButton },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			setLogo( frame.state().get( 'selection' ).first().toJSON() );
			toast( t( 'logoUploaded' ) );
		} );
		frame.open();
	} );

	logoRemoveBtn.addEventListener( 'click', function () {
		clearLogo();
		toast( t( 'logoRemoved' ) );
	} );

	[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
		drop.addEventListener( evt, function ( e ) {
			e.preventDefault();
			drop.classList.add( 'billio-drop-over' );
		} );
	} );

	[ 'dragleave', 'drop' ].forEach( function ( evt ) {
		drop.addEventListener( evt, function ( e ) {
			e.preventDefault();
			drop.classList.remove( 'billio-drop-over' );
			if ( 'drop' !== evt ) {
				return;
			}
			var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ];
			if ( ! file ) {
				return;
			}
			if ( ! /^image\/(png|jpe?g)$/.test( file.type ) ) {
				toast( t( 'invalidImageFile' ), true );
				return;
			}
			uploadDroppedFile( file );
		} );
	} );

	function uploadDroppedFile( file ) {
		if ( ! window.wp || ! window.wp.media || ! window.wp.media.model || ! window.wp.media.model.Attachment ) {
			return;
		}
		window.wp.media.model.Attachment.createFromFile( file ).then( function ( resultOrModel ) {
			// wp.media's createFromFile resolves with the attachment model
			// once the async-upload.php round trip finishes, matching the
			// same upload path the click-to-browse flow's wp.media frame uses.
			var attachment = resultOrModel.toJSON ? resultOrModel.toJSON() : resultOrModel;
			setLogo( attachment );
			toast( t( 'logoUploaded' ) );
		} ).catch( function () {
			toast( t( 'invalidImageFile' ), true );
		} );
	}

	/* ---------------------------------------------------------------
	 * Numbering: chips, live next-number recompute, stepper, yearly reset
	 * ------------------------------------------------------------- */

	var fmtField = document.getElementById( 'billio_number_format' );
	var padField = document.getElementById( 'billio_number_padding' );
	var invPrefixField = document.getElementById( 'billio_number_prefix' );
	var cnPrefixField = document.getElementById( 'billio_credit_note_prefix' );
	var fmtErr = document.getElementById( 'billio-fmt-err' );

	document.querySelectorAll( '.billio-chip' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			var start = fmtField.selectionStart === null || fmtField.selectionStart === undefined ? fmtField.value.length : fmtField.selectionStart;
			var end = fmtField.selectionEnd === null || fmtField.selectionEnd === undefined ? start : fmtField.selectionEnd;
			fmtField.value = fmtField.value.slice( 0, start ) + chip.dataset.ins + fmtField.value.slice( end );
			fmtField.focus();
			var caret = start + chip.dataset.ins.length;
			fmtField.setSelectionRange( caret, caret );
			recomputeNumbers();
			markDirty( 'fmt' );
			schedulePreview();
		} );
	} );

	function formatNumber( prefix, sequence, padding, format ) {
		var padded = String( sequence ).padStart( padding, '0' );
		var result = format.replace( /\{PREFIX\}/g, prefix ).replace( /\{YEAR\}/g, String( new Date().getFullYear() ) ).replace( /\{NUMBER\}/g, padded );
		if ( -1 === format.indexOf( '{NUMBER}' ) ) {
			result += '-' + padded;
		}
		return result;
	}

	function recomputeNumbers() {
		var format = fmtField.value;
		var ok = -1 !== format.indexOf( '{NUMBER}' );
		fmtErr.hidden = ok;
		fmtField.style.borderColor = ok ? '' : 'var(--billio-bad)';

		var padding = Math.min( 10, Math.max( 1, parseInt( padField.value, 10 ) || 1 ) );
		document.getElementById( 'billio-next-invoice' ).textContent = formatNumber( invPrefixField.value, data.current.nextInvoiceSeq, padding, format );
		document.getElementById( 'billio-next-creditnote' ).textContent = formatNumber( cnPrefixField.value, data.current.nextCreditNoteSeq, padding, format );

		document.getElementById( 'billio-pad-example' ).textContent = tf( 'paddingExample', padding, '1'.padStart( padding, '0' ) );

		var hasYear = -1 !== format.indexOf( '{YEAR}' );
		document.getElementById( 'billio-reset-hint' ).textContent = hasYear ? t( 'resetYearlyHasYear' ) : t( 'resetYearlyNoYear' );
	}

	[ fmtField, invPrefixField, cnPrefixField ].forEach( function ( field ) {
		field.addEventListener( 'input', function () {
			recomputeNumbers();
			markDirty( field.id );
			schedulePreview();
		} );
	} );

	document.getElementById( 'billio-pad-dec' ).addEventListener( 'click', function () {
		padField.value = Math.max( 1, ( parseInt( padField.value, 10 ) || 4 ) - 1 );
		recomputeNumbers();
		markDirty( 'pad' );
		schedulePreview();
	} );
	document.getElementById( 'billio-pad-inc' ).addEventListener( 'click', function () {
		padField.value = Math.min( 10, ( parseInt( padField.value, 10 ) || 4 ) + 1 );
		recomputeNumbers();
		markDirty( 'pad' );
		schedulePreview();
	} );
	padField.addEventListener( 'input', function () {
		recomputeNumbers();
		markDirty( 'pad' );
		schedulePreview();
	} );

	/* ---------------------------------------------------------------
	 * Switch rows: toggle the hidden real checkbox + visual state
	 * ------------------------------------------------------------- */

	function wireSwitch( rowEl, checkboxId, onToggle ) {
		if ( ! rowEl ) {
			return;
		}
		var checkbox = document.getElementById( checkboxId );
		var btn = rowEl.querySelector( '.billio-tg' );
		if ( ! checkbox || ! btn ) {
			return;
		}

		function apply( checked ) {
			checkbox.checked = checked;
			btn.setAttribute( 'aria-checked', checked ? 'true' : 'false' );
			rowEl.classList.toggle( 'billio-sw-on', checked );
			if ( onToggle ) {
				onToggle( checked );
			}
		}

		btn.addEventListener( 'keydown', function ( e ) {
			if ( ' ' === e.key || 'Enter' === e.key ) {
				e.preventDefault();
				apply( ! checkbox.checked );
			}
		} );

		// The whole row is clickable (matches the mockup) — it only ever
		// contains text and the switch button itself, nothing else
		// interactive, so no extra target-guarding is needed here.
		rowEl.addEventListener( 'click', function () {
			apply( ! checkbox.checked );
		} );
	}

	wireSwitch( document.getElementById( 'billio-reset-row' ), 'billio_number_reset_yearly', function ( checked ) {
		document.getElementById( 'billio-reset-warn' ).hidden = ! checked;
	} );
	document.getElementById( 'billio-reset-row' ).addEventListener( 'click', function () {
		markDirty( 'reset_yearly' );
	} );

	function updateMailError() {
		document.getElementById( 'billio-mail-err' ).hidden = anyEmailStatusOn();
	}

	wireSwitch( document.getElementById( 'billio-sw-processing' ), 'billio-trigger-processing', function () {
		updateMailError();
		updateProfile();
	} );
	document.getElementById( 'billio-sw-processing' ).addEventListener( 'click', function () {
		markDirty( 'trigger_processing' );
		schedulePreview();
	} );

	wireSwitch( document.getElementById( 'billio-sw-completed' ), 'billio-trigger-completed', function () {
		updateMailError();
		updateProfile();
	} );
	document.getElementById( 'billio-sw-completed' ).addEventListener( 'click', function () {
		markDirty( 'trigger_completed' );
		schedulePreview();
	} );

	var thankyouRow = document.getElementById( 'billio-sw-thankyou' );
	if ( thankyouRow ) {
		wireSwitch( thankyouRow, 'billio_show_thankyou_download' );
		thankyouRow.addEventListener( 'click', function () {
			markDirty( 'thankyou_download' );
		} );
	}

	var linkValidityField = document.getElementById( 'billio_customer_link_validity_days' );
	if ( linkValidityField ) {
		linkValidityField.addEventListener( 'input', function () {
			markDirty( 'link_validity' );
		} );
	}

	var ondemandRow = document.getElementById( 'billio-sw-ondemand' );
	if ( ondemandRow ) {
		wireSwitch( ondemandRow, 'billio_customer_ondemand_generate' );
		ondemandRow.addEventListener( 'click', function () {
			markDirty( 'ondemand_generate' );
		} );
	}

	var watermarkRow = document.getElementById( 'billio-sw-watermark' );
	if ( watermarkRow ) {
		wireSwitch( watermarkRow, 'billio_watermark_paid', function () {
			schedulePreview();
		} );
		watermarkRow.addEventListener( 'click', function () {
			markDirty( 'watermark_paid' );
			schedulePreview();
		} );
	}

	var taxIdRow = document.getElementById( 'billio-sw-tax-id' );
	if ( taxIdRow ) {
		wireSwitch( taxIdRow, 'billio_enable_tax_id', function ( checked ) {
			var optRow = document.getElementById( 'billio-tax-id-options' );
			var reqRow = document.getElementById( 'billio-tax-id-req-row' );
			if ( optRow ) {
				optRow.classList.toggle( 'billio-dim-row', ! checked );
			}
			if ( reqRow ) {
				reqRow.classList.toggle( 'billio-dim-row', ! checked );
			}
			schedulePreview();
		} );
		taxIdRow.addEventListener( 'click', function () {
			markDirty( 'enable_tax_id' );
		} );
	}

	var taxReqRow = document.getElementById( 'billio-sw-tax-req' );
	if ( taxReqRow ) {
		wireSwitch( taxReqRow, 'billio_tax_id_required' );
		taxReqRow.addEventListener( 'click', function () {
			markDirty( 'tax_id_required' );
		} );
	}

	var taxIdLabelInput = document.getElementById( 'billio_tax_id_label' );
	if ( taxIdLabelInput ) {
		taxIdLabelInput.addEventListener( 'input', function () {
			markDirty( 'tax_id_label' );
			schedulePreview();
		} );
	}

	/* ---------------------------------------------------------------
	 * Paper size segmented control (real radios underneath)
	 * ------------------------------------------------------------- */

	document.querySelectorAll( '#billio-paper-seg button' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '#billio-paper-seg button' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.setAttribute( 'aria-pressed', 'true' );
			btn.previousElementSibling.checked = true;
			document.getElementById( 'billio-preview-frame' ).classList.toggle( 'billio-letter', 'Letter' === btn.dataset.v );
			markDirty( 'paper' );
			schedulePreview();
		} );
	} );

	/* ---------------------------------------------------------------
	 * Template cards + accent swatches
	 * ------------------------------------------------------------- */

	var accentRow = document.getElementById( 'billio-accent-row' );
	var accentHiddenField = document.getElementById( 'billio_accent_color' );
	var accentPicker = document.getElementById( 'billio-accent-picker' );
	var accentHex = document.getElementById( 'billio-accent-hex' );

	document.querySelectorAll( '.billio-settings-tpl' ).forEach( function ( label ) {
		label.addEventListener( 'click', function () {
			document.querySelectorAll( '.billio-settings-tpl' ).forEach( function ( l ) {
				l.classList.remove( 'billio-settings-tpl-on' );
			} );
			label.classList.add( 'billio-settings-tpl-on' );
			var input = label.querySelector( 'input' );
			input.checked = true;
			accentRow.classList.toggle( 'billio-dim-row', 'default' === input.dataset.v );
			markDirty( 'template' );
			schedulePreview();
		} );
	} );

	function setAccent( hex ) {
		accentHiddenField.value = hex;
		accentPicker.value = hex;
		accentHex.textContent = hex.toUpperCase();
		document.querySelectorAll( '.billio-swc' ).forEach( function ( s ) {
			s.classList.toggle( 'billio-swc-on', s.dataset.c.toLowerCase() === hex.toLowerCase() );
		} );
		document.documentElement.style.setProperty( '--billio-settings-accent', hex );
		markDirty( 'accent' );
		schedulePreview();
	}

	document.querySelectorAll( '.billio-swc' ).forEach( function ( s ) {
		s.addEventListener( 'click', function () {
			setAccent( s.dataset.c );
		} );
	} );
	accentPicker.addEventListener( 'input', function () {
		setAccent( accentPicker.value );
	} );

	/* ---------------------------------------------------------------
	 * Scrollspy
	 * ------------------------------------------------------------- */

	var sections = document.querySelectorAll( '.billio-sec' );
	if ( window.IntersectionObserver ) {
		var io = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						document.querySelectorAll( '.billio-settings-nav a' ).forEach( function ( a ) {
							a.classList.toggle( 'billio-nav-on', a.getAttribute( 'href' ) === '#' + entry.target.id );
						} );
					}
				} );
			},
			{ rootMargin: '-40% 0px -55% 0px' }
		);
		sections.forEach( function ( s ) {
			io.observe( s );
		} );
	}

	/* ---------------------------------------------------------------
	 * Live preview iframe: debounced refresh, doc-type switch
	 * ------------------------------------------------------------- */

	var frame = document.getElementById( 'billio-preview-frame' );
	var previewDoc = 'inv';
	var overridable = [
		'billio_company_name',
		'billio_company_address',
		'billio_company_vat',
		'billio_company_logo_id',
		'billio_number_prefix',
		'billio_credit_note_prefix',
		'billio_number_format',
		'billio_number_padding',
		'billio_template',
		'billio_accent_color',
		'billio_paper_size',
		'billio_watermark_paid',
		'billio_enable_tax_id',
		'billio_tax_id_label',
	];
	var previewTimer = null;

	function refreshPreview() {
		if ( ! frame ) {
			return;
		}
		var url = new URL( data.previewUrl, window.location.origin );
		url.searchParams.set( '_wpnonce', data.nonce );
		url.searchParams.set( 'doc', previewDoc );
		overridable.forEach( function ( name ) {
			var field = document.getElementById( name );
			if ( field ) {
				var val = ( 'checkbox' === field.type ) ? ( field.checked ? '1' : '' ) : field.value;
				url.searchParams.set( name, val );
				return;
			}
			// Radio groups (template, paper size) have no element with the
			// option's id — read the checked radio by name instead.
			var checkedRadio = form.querySelector( 'input[type="radio"][name="' + name + '"]:checked' );
			if ( checkedRadio ) {
				url.searchParams.set( name, checkedRadio.value );
			}
		} );
		frame.src = url.toString();
	}

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( refreshPreview, 300 );
	}

	document.querySelectorAll( '#billio-doc-seg button' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '#billio-doc-seg button' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.setAttribute( 'aria-pressed', 'true' );
			previewDoc = btn.dataset.v;
			refreshPreview();
		} );
	} );

	// Blur-triggered refresh for text fields (per the prompt: debounce
	// ~300ms and also re-render on blur) — covers the case where a field
	// changes fast enough that the debounce alone feels laggy.
	form.querySelectorAll( 'input[type="text"], textarea' ).forEach( function ( field ) {
		field.addEventListener( 'blur', refreshPreview );
	} );

	/* ---------------------------------------------------------------
	 * Init
	 * ------------------------------------------------------------- */

	recomputeNumbers();
	updateProfile();
}() );
