/* TBT Swipe — admin editor. Vanilla JS, no jQuery. */
( function () {
	'use strict';

	var cfg = window.tbtsAdmin || {};
	var i18n = cfg.i18n || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		bindDeleteConfirm();

		if ( document.querySelector( '.tbts-editor' ) ) {
			initEditor();
		}
		if ( document.getElementById( 'tbts-qr' ) ) {
			initQr();
		}
	} );

	/* ---- List screen: confirm delete ---- */
	function bindDeleteConfirm() {
		var links = document.querySelectorAll( 'a.tbts-delete' );
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				if ( ! window.confirm( i18n.confirmDelete ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	/* ---- Editor ---- */
	function initEditor() {
		var terms = document.getElementById( 'tbts-terms' );
		var countEl = document.getElementById( 'tbts-term-count' );
		var hintEl = document.getElementById( 'tbts-term-hint' );
		var genBtn = document.getElementById( 'tbts-generate' );
		var genSpinner = document.getElementById( 'tbts-generate-spinner' );
		var genStatus = document.getElementById( 'tbts-generate-status' );
		var reviewPanel = document.getElementById( 'tbts-review-panel' );
		var reviewBody = document.querySelector( '#tbts-review tbody' );
		var addRowBtn = document.getElementById( 'tbts-add-row' );
		var saveBtn = document.getElementById( 'tbts-save' );
		var saveSpinner = document.getElementById( 'tbts-save-spinner' );
		var errorBox = document.getElementById( 'tbts-error' );

		// Pre-fill from an existing set.
		if ( cfg.set && cfg.set.cards && cfg.set.cards.length ) {
			cfg.set.cards.forEach( addRow );
			reviewPanel.hidden = false;
		}

		updateCount();
		terms.addEventListener( 'input', updateCount );

		function updateCount() {
			var lines = terms.value.split( '\n' ).map( function ( l ) {
				return l.trim();
			} ).filter( Boolean );
			var n = lines.length;
			countEl.textContent = n;
			countEl.classList.remove( 'tbts-ok', 'tbts-bad' );

			if ( n < cfg.minTerms ) {
				hintEl.textContent = i18n.tooFew;
				countEl.classList.add( 'tbts-bad' );
				genBtn.disabled = true;
			} else if ( n > cfg.maxTerms ) {
				hintEl.textContent = i18n.tooMany;
				countEl.classList.add( 'tbts-bad' );
				genBtn.disabled = true;
			} else {
				hintEl.textContent = i18n.ready;
				countEl.classList.add( 'tbts-ok' );
				genBtn.disabled = false;
			}
		}

		function showError( msg ) {
			errorBox.querySelector( 'p' ).textContent = msg;
			errorBox.hidden = false;
			errorBox.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
		function clearError() {
			errorBox.hidden = true;
		}

		genBtn.addEventListener( 'click', function () {
			clearError();
			genBtn.disabled = true;
			genSpinner.classList.add( 'is-active' );
			genStatus.textContent = i18n.generating;

			var body = new FormData();
			body.append( 'action', 'tbts_generate' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'terms', terms.value );

			fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					genSpinner.classList.remove( 'is-active' );
					genStatus.textContent = '';
					genBtn.disabled = false;
					if ( ! res || ! res.success ) {
						showError( ( res && res.data && res.data.message ) || i18n.networkError );
						return;
					}
					// Replace the review table with the generated cards.
					reviewBody.innerHTML = '';
					res.data.cards.forEach( addRow );
					reviewPanel.hidden = false;
					reviewPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} )
				.catch( function () {
					genSpinner.classList.remove( 'is-active' );
					genStatus.textContent = '';
					genBtn.disabled = false;
					showError( i18n.networkError );
				} );
		} );

		addRowBtn.addEventListener( 'click', function () {
			addRow( { term: '', ipa: '', translation: '', example: '' } );
			reviewPanel.hidden = false;
		} );

		function addRow( card ) {
			var tr = document.createElement( 'tr' );
			tr.className = 'tbts-row';
			tr.appendChild( inputCell( 'term', card.term ) );
			tr.appendChild( inputCell( 'ipa', card.ipa ) );
			tr.appendChild( inputCell( 'translation', card.translation ) );
			tr.appendChild( inputCell( 'example', card.example ) );

			var moveTd = document.createElement( 'td' );
			moveTd.className = 'tbts-col-tools';
			var up = rowBtn( '↑', 'tbts-up' );
			var down = rowBtn( '↓', 'tbts-down' );
			up.addEventListener( 'click', function () { moveRow( tr, -1 ); } );
			down.addEventListener( 'click', function () { moveRow( tr, 1 ); } );
			moveTd.appendChild( up );
			moveTd.appendChild( down );
			tr.appendChild( moveTd );

			var delTd = document.createElement( 'td' );
			delTd.className = 'tbts-col-tools';
			var del = rowBtn( '✕', 'tbts-remove' );
			del.addEventListener( 'click', function () { tr.parentNode.removeChild( tr ); } );
			delTd.appendChild( del );
			tr.appendChild( delTd );

			reviewBody.appendChild( tr );
		}

		function inputCell( field, value ) {
			var td = document.createElement( 'td' );
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.setAttribute( 'data-field', field );
			input.value = value || '';
			td.appendChild( input );
			return td;
		}

		function rowBtn( label, cls ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'tbts-row-btn ' + cls;
			b.textContent = label;
			return b;
		}

		function moveRow( tr, dir ) {
			if ( dir < 0 && tr.previousElementSibling ) {
				tr.parentNode.insertBefore( tr, tr.previousElementSibling );
			} else if ( dir > 0 && tr.nextElementSibling ) {
				tr.parentNode.insertBefore( tr.nextElementSibling, tr );
			}
		}

		function collectCards() {
			var cards = [];
			reviewBody.querySelectorAll( 'tr.tbts-row' ).forEach( function ( tr ) {
				var card = {};
				tr.querySelectorAll( 'input[data-field]' ).forEach( function ( input ) {
					card[ input.getAttribute( 'data-field' ) ] = input.value.trim();
				} );
				if ( card.term ) {
					cards.push( card );
				}
			} );
			return cards;
		}

		saveBtn.addEventListener( 'click', function () {
			clearError();
			var title = document.getElementById( 'tbts-title' ).value.trim();
			var status = document.getElementById( 'tbts-status' ).value;
			var cards = collectCards();

			if ( ! title ) {
				showError( i18n.needTitle );
				return;
			}
			if ( ! cards.length ) {
				showError( i18n.noCards );
				return;
			}

			saveBtn.disabled = true;
			saveSpinner.classList.add( 'is-active' );

			var body = new FormData();
			body.append( 'action', 'tbts_save_set' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'set_id', cfg.set ? cfg.set.id : 0 );
			body.append( 'title', title );
			body.append( 'status', status );
			body.append( 'cards', JSON.stringify( cards ) );

			fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						saveBtn.disabled = false;
						saveSpinner.classList.remove( 'is-active' );
						showError( ( res && res.data && res.data.message ) || i18n.networkError );
						return;
					}
					window.location.href = res.data.edit_url;
				} )
				.catch( function () {
					saveBtn.disabled = false;
					saveSpinner.classList.remove( 'is-active' );
					showError( i18n.networkError );
				} );
		} );
	}

	/* ---- QR panel ---- */
	function initQr() {
		var box = document.getElementById( 'tbts-qr' );
		var url = box.getAttribute( 'data-url' );
		if ( ! url || typeof window.QRCode === 'undefined' ) {
			return;
		}

		var qr = new window.QRCode( box, {
			text: url,
			width: 600,
			height: 600,
			correctLevel: window.QRCode.CorrectLevel.M
		} );

		var copyBtn = document.getElementById( 'tbts-copy-url' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				var field = document.getElementById( 'tbts-deck-url' );
				field.select();
				field.setSelectionRange( 0, 99999 );
				var done = function () {
					var orig = copyBtn.textContent;
					copyBtn.textContent = i18n.copied;
					setTimeout( function () { copyBtn.textContent = orig; }, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( field.value ).then( done, function () {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		}

		var dlBtn = document.getElementById( 'tbts-download-qr' );
		if ( dlBtn ) {
			dlBtn.addEventListener( 'click', function () {
				var canvas = box.querySelector( 'canvas' );
				var dataUrl;
				if ( canvas ) {
					dataUrl = canvas.toDataURL( 'image/png' );
				} else {
					var img = box.querySelector( 'img' );
					dataUrl = img ? img.src : '';
				}
				if ( ! dataUrl ) {
					return;
				}
				var a = document.createElement( 'a' );
				a.href = dataUrl;
				a.download = 'tbt-swipe-qr.png';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
			} );
		}
	}
} )();
