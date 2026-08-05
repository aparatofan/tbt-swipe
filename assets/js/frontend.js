/* TBT Swipe — frontend management page. Vanilla JS, no jQuery. */
( function () {
	'use strict';

	var cfg = window.tbtsFe || {};
	var i18n = cfg.i18n || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var generator = document.getElementById( 'tbts-fe-generator' );
		var sets = document.getElementById( 'tbts-fe-sets' );

		if ( generator ) {
			initGenerator( generator );
		}
		if ( sets ) {
			initSets( sets );
		}
	} );

	/* ---------------------------------------------------------------- *
	 * REST helper
	 * ---------------------------------------------------------------- */

	/**
	 * Every write goes through here so nonce staleness is handled in one place.
	 *
	 * A page cache serving /swipe/ hands out a nonce minted for whoever warmed
	 * the cache, so it can be stale on arrival. WordPress answers those with
	 * 403 rest_cookie_invalid_nonce; a generic "something went wrong" would
	 * leave the teacher retrying forever, so that case gets its own message.
	 */
	function request( path, options ) {
		var opts = options || {};
		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		};

		if ( opts.body ) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}

		return fetch( cfg.restBase + path, init ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( response.ok ) {
					return data;
				}
				throw restError( response.status, data );
			}, function () {
				throw { code: 'tbts_network', message: i18n.networkError };
			} );
		}, function () {
			throw { code: 'tbts_network', message: i18n.networkError };
		} );
	}

	function restError( status, data ) {
		var code = ( data && data.code ) || 'tbts_unknown';

		if ( 403 === status && 'rest_cookie_invalid_nonce' === code ) {
			return { code: code, message: i18n.staleNonce };
		}
		if ( 'tbts_quota_exceeded' === code ) {
			return { code: code, message: i18n.quota };
		}
		if ( 'tbts_api_error' === code ) {
			return { code: code, message: i18n.apiError };
		}
		// Server-side messages are already user-facing English and safe to show.
		return { code: code, message: ( data && data.message ) || i18n.networkError };
	}

	/* ---------------------------------------------------------------- *
	 * Shared UI bits
	 * ---------------------------------------------------------------- */

	function role( root, name ) {
		return root.querySelector( '[data-role="' + name + '"]' );
	}

	function showError( root, message ) {
		var box = role( root, 'error' );
		if ( ! box ) {
			return;
		}
		box.textContent = message;
		box.hidden = false;
		box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function clearError( root ) {
		var box = role( root, 'error' );
		if ( box ) {
			box.hidden = true;
		}
	}

	function copyText( text, button ) {
		var done = function () {
			var original = button.textContent;
			button.textContent = i18n.copied;
			setTimeout( function () { button.textContent = original; }, 1500 );
		};
		var fallback = function () {
			var field = document.createElement( 'textarea' );
			field.value = text;
			field.setAttribute( 'readonly', 'readonly' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();
			try {
				document.execCommand( 'copy' );
			} catch ( e ) {}
			document.body.removeChild( field );
			done();
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function pluralWord( n ) {
		return 1 === n ? i18n.wordOne : i18n.wordMany;
	}

	function pluralCard( n ) {
		return 1 === n ? i18n.cardOne : i18n.cardMany;
	}

	function renderQr( target, url ) {
		target.innerHTML = '';
		if ( ! url || typeof window.QRCode === 'undefined' ) {
			return;
		}
		new window.QRCode( target, {
			text: url,
			width: 320,
			height: 320,
			correctLevel: window.QRCode.CorrectLevel.M
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Generator
	 * ---------------------------------------------------------------- */

	function initGenerator( root ) {
		var titleInput = root.querySelector( '#tbts-fe-title' );
		var classSelect = root.querySelector( '#tbts-fe-class' );
		var lessonSelect = root.querySelector( '#tbts-fe-lesson' );
		var lessonWrap = role( root, 'lesson-wrap' );
		var terms = root.querySelector( '#tbts-fe-terms' );
		var countEl = role( root, 'count' );
		var countNoun = role( root, 'count-noun' );
		var stack = role( root, 'stack' );
		var stackTerm = role( root, 'stack-term' );
		var stackCount = role( root, 'stack-count' );
		var generateBtn = role( root, 'generate' );
		var generateStatus = role( root, 'generate-status' );
		var reviewPanel = role( root, 'review' );
		var reviewBody = role( root, 'review-body' );
		var saveBtn = role( root, 'save' );
		var saveStatus = role( root, 'save-status' );
		var resultWait = role( root, 'result-wait' );
		var resultPanel = role( root, 'result' );
		var resultTitle = role( root, 'result-title' );
		var resultMeta = role( root, 'result-meta' );
		var resultUrl = role( root, 'result-url' );
		var resultQr = role( root, 'result-qr' );
		var resultOpen = role( root, 'result-open' );

		terms.addEventListener( 'input', updateCount );
		updateCount();

		function lines() {
			return terms.value.split( /\r?\n/ ).map( function ( line ) {
				return line.trim();
			} ).filter( Boolean );
		}

		function lineCount() {
			return lines().length;
		}

		/**
		 * The word counter and the preview stack read the same lines, so they
		 * are updated together and can never disagree.
		 *
		 * The count is a pre-check only — the server enforces the real cap.
		 */
		function updateCount() {
			var list = lines();
			var n = list.length;

			countEl.textContent = n;
			countEl.classList.toggle( 'is-over', n > cfg.maxTerms );
			if ( countNoun ) {
				countNoun.textContent = pluralWord( n );
			}

			if ( stackCount ) {
				stackCount.textContent = n;
			}
			if ( stackTerm ) {
				stackTerm.textContent = n ? list[ 0 ] : i18n.stackEmpty;
			}
			if ( stack ) {
				stack.setAttribute( 'data-empty', n ? 'false' : 'true' );
			}
		}

		/**
		 * Where the teacher is in the flow. All three stages are always in the
		 * DOM; only their data-state changes, and the CSS does the rest.
		 *
		 * @param {string} first  State for stage 1.
		 * @param {string} second State for stage 2.
		 * @param {string} third  State for stage 3.
		 */
		function setStages( first, second, third ) {
			[ first, second, third ].forEach( function ( state, index ) {
				var stage = root.querySelector( '[data-stage="' + ( index + 1 ) + '"]' );
				if ( stage ) {
					stage.setAttribute( 'data-state', state );
				}
			} );
		}

		/*
		 * A deck attached to a class must name a lesson, so choosing a class
		 * loads that class's lessons and picks the newest one straight away.
		 * Mandatory should not mean an extra click: the lesson a teacher wants
		 * is nearly always the one they just taught.
		 *
		 * "No class" stays the default and needs no lesson — only the class →
		 * lesson pairing is required.
		 *
		 * Lessons load only for a class the server agrees this user owns.
		 */
		if ( classSelect && lessonSelect && lessonWrap ) {
			classSelect.addEventListener( 'change', function () {
				var classId = classSelect.value;
				resetLessons();

				if ( ! classId ) {
					lessonWrap.hidden = true;
					return;
				}

				request( 'classes/' + encodeURIComponent( classId ) + '/lessons' ).then( function ( data ) {
					var lessons = data.lessons || [];

					if ( ! lessons.length ) {
						// Nothing to attach to. Say so, and take the class back
						// out of play rather than leaving a selection that
						// cannot be saved and does not explain itself.
						markClassLessonless( classId );
						lessonWrap.hidden = true;
						classSelect.value = '';
						showError( root, i18n.classNoLessons );
						return;
					}

					// The route returns lessons newest-first; option 0 is the
					// most recent, so no client-side sorting is involved.
					lessonSelect.innerHTML = '';
					lessons.forEach( function ( lesson ) {
						var option = document.createElement( 'option' );
						option.value = lesson.id;
						option.textContent = lesson.title;
						lessonSelect.appendChild( option );
					} );
					lessonSelect.selectedIndex = 0;
					lessonWrap.hidden = false;
				} ).catch( function ( error ) {
					lessonWrap.hidden = true;
					showError( root, error.message );
				} );
			} );
		}

		/**
		 * Take a class with no lessons out of the picker, so the teacher cannot
		 * land on it again and wonder why saving fails.
		 *
		 * @param {string} classId Class that came back empty.
		 */
		function markClassLessonless( classId ) {
			var option = classSelect.querySelector( 'option[value="' + classId + '"]' );
			if ( ! option ) {
				return;
			}
			option.disabled = true;
			if ( option.textContent.indexOf( i18n.noLessonsSuffix ) === -1 ) {
				option.textContent = option.textContent.trim() + ' ' + i18n.noLessonsSuffix;
			}
		}

		/* The empty first option exists only until a class is chosen. */
		function resetLessons() {
			lessonSelect.innerHTML = '';
			var blank = document.createElement( 'option' );
			blank.value = '';
			lessonSelect.appendChild( blank );
		}

		generateBtn.addEventListener( 'click', function () {
			clearError( root );

			var n = lineCount();
			if ( 0 === n ) {
				showError( root, i18n.noTerms );
				return;
			}
			if ( n > cfg.maxTerms ) {
				showError( root, i18n.tooMany );
				return;
			}

			generateBtn.disabled = true;
			generateStatus.textContent = i18n.generating;

			request( 'generate', { method: 'POST', body: { terms: terms.value } } ).then( function ( data ) {
				generateBtn.disabled = false;
				generateStatus.textContent = '';
				reviewBody.innerHTML = '';
				( data.cards || [] ).forEach( addRow );
				reviewPanel.hidden = false;
				resultPanel.hidden = true;
				// Rows built inside a hidden panel all measure zero, so the
				// example fields only get their true height once it is shown.
				growAll();
				setStages( 'done', 'active', 'waiting' );
				reviewPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} ).catch( function ( error ) {
				generateBtn.disabled = false;
				generateStatus.textContent = '';
				showError( root, error.message );
			} );
		} );

		/**
		 * An example sentence is never truncated: the field grows to fit it.
		 *
		 * @param {HTMLTextAreaElement} el Example field.
		 */
		function grow( el ) {
			el.style.height = 'auto';
			el.style.height = el.scrollHeight + 'px';
		}

		function growAll() {
			reviewBody.querySelectorAll( '[data-field="example"]' ).forEach( grow );
		}

		/* Numbering is positional, so it is recomputed rather than stored:
		   remove row 2 of 5 and the rest still read 01…04. */
		function renumber() {
			Array.prototype.forEach.call( reviewBody.children, function ( row, index ) {
				var label = row.querySelector( '[data-role="row-n"]' );
				if ( label ) {
					label.textContent = ( index + 1 < 10 ? '0' : '' ) + ( index + 1 );
				}
			} );
		}

		/* Every generated field is editable — this is the teacher's only
		   chance to fix AI output before students see it.

		   Term, pronunciation and translation share the top line so they stay
		   comparable down the column; the example sits on its own line below,
		   with the full width of the row to grow into. */
		function addRow( card ) {
			var data = card || {};
			var row = document.createElement( 'div' );
			row.className = 'tbt-row';

			var top = document.createElement( 'div' );
			top.className = 'tbt-row-top';

			var number = document.createElement( 'span' );
			number.className = 'tbt-row-n';
			number.setAttribute( 'data-role', 'row-n' );
			top.appendChild( number );

			[
				[ 'term', 'tbt-cell tbt-cell--term' ],
				[ 'ipa', 'tbt-cell tbt-cell--ipa' ],
				[ 'translation', 'tbt-cell tbt-cell--tr' ]
			].forEach( function ( field ) {
				var input = document.createElement( 'input' );
				input.type = 'text';
				input.className = field[ 1 ];
				input.setAttribute( 'data-field', field[ 0 ] );
				input.value = data[ field[ 0 ] ] || '';
				top.appendChild( input );
			} );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'tbt-row-x';
			remove.textContent = '×';
			remove.setAttribute( 'aria-label', i18n.removeRow );
			remove.addEventListener( 'click', function () {
				row.parentNode.removeChild( row );
				renumber();
			} );
			top.appendChild( remove );

			// The two spacer spans hold the example line to the same grid as
			// the line above. They carry no label: the columns are already
			// named in the header strip.
			var exampleRow = document.createElement( 'div' );
			exampleRow.className = 'tbt-row-ex';
			exampleRow.appendChild( document.createElement( 'span' ) );

			var example = document.createElement( 'textarea' );
			example.className = 'tbt-cell tbt-cell--ex';
			example.rows = 1;
			example.setAttribute( 'data-field', 'example' );
			example.value = data.example || '';
			example.addEventListener( 'input', function () {
				grow( example );
			} );
			exampleRow.appendChild( example );
			exampleRow.appendChild( document.createElement( 'span' ) );

			row.appendChild( top );
			row.appendChild( exampleRow );
			reviewBody.appendChild( row );

			grow( example );
			renumber();

			return row;
		}

		function collectCards() {
			var cards = [];
			Array.prototype.forEach.call( reviewBody.children, function ( row ) {
				var card = {};
				row.querySelectorAll( '[data-field]' ).forEach( function ( field ) {
					card[ field.getAttribute( 'data-field' ) ] = field.value.trim();
				} );
				if ( card.term ) {
					cards.push( card );
				}
			} );
			return cards;
		}

		saveBtn.addEventListener( 'click', function () {
			clearError( root );

			var title = titleInput.value.trim();
			var cards = collectCards();

			if ( ! title ) {
				showError( root, i18n.needTitle );
				titleInput.focus();
				return;
			}
			if ( ! cards.length ) {
				showError( root, i18n.noCards );
				return;
			}

			saveBtn.disabled = true;
			saveStatus.textContent = i18n.saving;

			request( 'sets', {
				method: 'POST',
				body: {
					title: title,
					class_id: classSelect ? classSelect.value : '',
					lesson_id: lessonSelect ? lessonSelect.value : '',
					cards: cards
				}
			} ).then( function ( data ) {
				saveBtn.disabled = false;
				saveStatus.textContent = '';
				// Saved cards are no longer editable here, so the review panel
				// closes rather than offering edits that would go nowhere.
				reviewPanel.hidden = true;

				if ( resultTitle ) {
					resultTitle.textContent = title;
				}
				if ( resultMeta ) {
					resultMeta.textContent = cards.length + ' ' + pluralCard( cards.length );
				}

				if ( data.deckUrl ) {
					resultUrl.value = data.deckUrl;
					resultUrl.hidden = false;
					renderQr( resultQr, data.deckUrl );
					if ( resultOpen ) {
						resultOpen.href = data.deckUrl;
						resultOpen.hidden = false;
					}
				} else {
					resultUrl.hidden = true;
					if ( resultOpen ) {
						resultOpen.hidden = true;
					}
					showError( root, i18n.noDeckPage );
				}

				if ( resultWait ) {
					resultWait.hidden = true;
				}
				resultPanel.hidden = false;
				setStages( 'done', 'done', 'active' );
				resultPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} ).catch( function ( error ) {
				saveBtn.disabled = false;
				saveStatus.textContent = '';
				showError( root, error.message );
			} );
		} );

		var addRowBtn = role( root, 'add-row' );
		if ( addRowBtn ) {
			addRowBtn.addEventListener( 'click', function () {
				var row = addRow( {} );
				var term = row.querySelector( '[data-field="term"]' );
				if ( term ) {
					term.focus();
				}
			} );
		}

		var copyBtn = role( root, 'copy' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				copyText( resultUrl.value, copyBtn );
			} );
		}

		/* Back to an empty first stage, from either "Start over" in the review
		   or "Create another deck" once a deck is saved. */
		function reset() {
			clearError( root );
			titleInput.value = '';
			terms.value = '';
			reviewBody.innerHTML = '';
			reviewPanel.hidden = true;
			resultPanel.hidden = true;
			if ( resultWait ) {
				resultWait.hidden = false;
			}
			setStages( 'active', 'active', 'waiting' );
			updateCount();
			titleInput.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			titleInput.focus();
		}

		[ role( root, 'reset' ), role( root, 'start-over' ) ].forEach( function ( button ) {
			if ( button ) {
				button.addEventListener( 'click', reset );
			}
		} );
	}

	/* ---------------------------------------------------------------- *
	 * Set list
	 * ---------------------------------------------------------------- */

	function initSets( root ) {
		var modal = role( root, 'qr-modal' );
		var qrTarget = role( root, 'qr-target' );
		var qrTitle = role( root, 'qr-title' );

		root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-role]' );
			if ( ! button || ! root.contains( button ) ) {
				return;
			}

			switch ( button.getAttribute( 'data-role' ) ) {
				case 'copy':
					copyText( button.getAttribute( 'data-url' ), button );
					break;
				case 'qr':
					qrTitle.textContent = button.getAttribute( 'data-title' ) || '';
					renderQr( qrTarget, button.getAttribute( 'data-url' ) );
					modal.hidden = false;
					break;
				case 'qr-close':
					modal.hidden = true;
					break;
				case 'delete':
					deleteSet( button );
					break;
			}
		} );

		if ( modal ) {
			modal.addEventListener( 'click', function ( event ) {
				if ( event.target === modal ) {
					modal.hidden = true;
				}
			} );
			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					modal.hidden = true;
				}
			} );
		}

		/* Rows and groups are found by their behavioural attributes, never by
		   the class names the design owns. */
		function deleteSet( button ) {
			var row = button.closest( '[data-set-id]' );
			if ( ! row || ! window.confirm( i18n.confirmDelete ) ) {
				return;
			}

			clearError( root );
			button.disabled = true;

			request( 'sets/' + encodeURIComponent( row.getAttribute( 'data-set-id' ) ), { method: 'DELETE' } )
				.then( function () {
					var group = row.closest( '[data-role="group"]' );
					row.parentNode.removeChild( row );
					// Drop a group that just lost its last deck.
					if ( group && ! group.querySelector( '[data-set-id]' ) ) {
						group.parentNode.removeChild( group );
					}
				} )
				.catch( function ( error ) {
					button.disabled = false;
					showError( root, error.message );
				} );
		}
	}
} )();
