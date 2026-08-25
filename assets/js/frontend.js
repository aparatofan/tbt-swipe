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

	function pluralCard( n ) {
		return 1 === n ? i18n.cardOne : i18n.cardMany;
	}

	/**
	 * Rewrite one row of the deck list after its deck has been edited.
	 *
	 * The list is server-rendered, so without this a saved edit leaves the row
	 * describing the deck as it was until the next page load. Only the three
	 * facts an edit can change are touched; the row keeps its place and its
	 * group, which the next page load re-sorts.
	 *
	 * @param {number} id   Deck ID.
	 * @param {Object} info title, cards, open, lesson, and keepChip when the
	 *                      builder could not show the deck's class and so has
	 *                      nothing true to say about the chip.
	 */
	function refreshDeckRow( id, info ) {
		var list = document.getElementById( 'tbts-fe-sets' );
		var row = list ? list.querySelector( '[data-set-id="' + String( id ).replace( /[^0-9]/g, '' ) + '"]' ) : null;
		if ( ! row ) {
			return;
		}

		var title = role( row, 'deck-title' );
		if ( title ) {
			title.textContent = info.title;
		}

		var count = role( row, 'deck-cards' );
		if ( count ) {
			count.textContent = info.cards + ' ' + pluralCard( info.cards );
		}

		// The QR modal takes its heading from the button, so a renamed deck
		// must not open a code labelled with its old name.
		var qr = role( row, 'qr' );
		if ( qr ) {
			qr.setAttribute( 'data-title', info.title );
		}

		if ( info.keepChip ) {
			return;
		}

		var chip = role( row, 'deck-chip' );
		if ( chip ) {
			chip.classList.remove( 'tbt-chip--open', 'tbt-chip--none' );
			if ( info.open ) {
				chip.textContent = i18n.openDeck;
				chip.classList.add( 'tbt-chip--open' );
			} else if ( info.lesson ) {
				chip.textContent = info.lesson;
			} else {
				chip.textContent = i18n.unattached;
				chip.classList.add( 'tbt-chip--none' );
			}
		}

		row.classList.toggle( 'tbt-deck--none', ! info.open && ! info.lesson );
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
		var deckTypeInputs = root.querySelectorAll( '[data-role="deck-type"] input[type="radio"]' );
		var classPair = role( root, 'class-pair' );
		var frontFaceInputs = root.querySelectorAll( '[data-role="front-face"] input[type="radio"]' );
		var editingBanner = role( root, 'editing' );
		var editingTitle = role( root, 'editing-title' );
		var discardBtn = role( root, 'discard' );
		// The library page, when the shortcode was given one. Empty means
		// there is nowhere to go back to, and Discard stays out of the way.
		var libraryUrl = root.getAttribute( 'data-library-url' ) || '';
		var classInput = root.querySelector( '#tbts-fe-class' );
		var classList = root.querySelector( '#tbts-fe-class-list' );
		var classIdField = role( root, 'class-id' );
		var classHint = role( root, 'class-hint' );
		var lessonSelect = root.querySelector( '#tbts-fe-lesson' );
		var lessonWrap = role( root, 'lesson-wrap' );
		var terms = root.querySelector( '#tbts-fe-terms' );
		var levelInputs = root.querySelectorAll( '.tbt-level-input' );
		var levelNote = role( root, 'level-note' );
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

		// The deck being edited, or 0 while building a new one. Everything that
		// differs between the two modes reads this one value.
		var editingId = 0;
		// Set when an edited deck's class could not be shown in the picker —
		// TBT Notes inactive, or the class gone. The save then leaves the
		// deck's class alone instead of detaching it from a class the teacher
		// was never given the chance to see.
		var classUnavailable = false;
		// The create label, kept so leaving edit mode can put it back rather
		// than hardcoding a string the template owns.
		var saveLabel = saveBtn ? saveBtn.textContent : '';

		terms.addEventListener( 'input', updateCount );
		updateCount();

		/* ------------------------------------------------------------ *
		 * Deck type
		 *
		 * An open deck has no class and no lesson. The two controls are
		 * disabled and dimmed rather than hidden, so the stage keeps its
		 * height and nothing under the teacher's cursor moves.
		 * ------------------------------------------------------------ */

		function currentDeckType() {
			var chosen = root.querySelector( '[data-role="deck-type"] input[type="radio"]:checked' );
			return chosen && 'open' === chosen.value ? 'open' : 'class';
		}

		function setDeckType( type ) {
			Array.prototype.forEach.call( deckTypeInputs, function ( input ) {
				input.checked = input.value === ( 'open' === type ? 'open' : 'class' );
			} );
		}

		/**
		 * Match the class/lesson controls to the chosen deck type.
		 *
		 * @param {boolean} keepValues Leave the current class and lesson in
		 *                             place — only ever true while restoring a
		 *                             saved class deck for editing.
		 */
		function applyDeckType( keepValues ) {
			var open = 'open' === currentDeckType();

			if ( ! keepValues && open ) {
				// Nothing half-chosen is left behind to be submitted, or to
				// reappear if the teacher switches back.
				if ( classInput ) {
					classInput.value = '';
				}
				setClass( '' );
				if ( lessonSelect ) {
					resetLessons();
				}
				if ( lessonWrap ) {
					lessonWrap.hidden = true;
				}
				if ( classHint ) {
					classHint.hidden = true;
				}
			}

			if ( classPair ) {
				classPair.classList.toggle( 'is-off', open );
			}
			[ classInput, lessonSelect ].forEach( function ( field ) {
				if ( field ) {
					field.disabled = open;
				}
			} );
		}

		Array.prototype.forEach.call( deckTypeInputs, function ( input ) {
			input.addEventListener( 'change', function () {
				applyDeckType( false );
			} );
		} );
		applyDeckType( false );

		/* Which face the students see first. Term unless the deck says
		   otherwise, matching the server's default. */
		function currentFrontFace() {
			var chosen = root.querySelector( '[data-role="front-face"] input[type="radio"]:checked' );
			return chosen && 'translation' === chosen.value ? 'translation' : 'term';
		}

		function setFrontFace( face ) {
			Array.prototype.forEach.call( frontFaceInputs, function ( input ) {
				input.checked = input.value === ( 'translation' === face ? 'translation' : 'term' );
			} );
		}

		function lines() {
			return terms.value.split( /\r?\n/ ).map( function ( line ) {
				return line.trim();
			} ).filter( Boolean );
		}

		function lineCount() {
			return lines().length;
		}

		/**
		 * The preview stack is the only word counter on the page — it shows the
		 * count, the first card's term and the empty state from one read of the
		 * textarea, so there is nothing for a second counter to disagree with.
		 *
		 * The count is a pre-check only — the server enforces the real cap.
		 */
		function updateCount() {
			var list = lines();
			var n = list.length;

			if ( stackCount ) {
				stackCount.textContent = n;
				stackCount.classList.toggle( 'is-over', n > cfg.maxTerms );
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
		/**
		 * The class the typed text stands for.
		 *
		 * The datalist hands back a name, so the id it means is looked up here.
		 * A name that matches nothing resolves to no class — deliberately, so a
		 * half-typed name can never attach a deck to the wrong class.
		 *
		 * @param {string} text What the teacher typed.
		 * @return {HTMLOptionElement|null} The matching entry, if there is one.
		 */
		function classOptionFor( text ) {
			var wanted = ( text || '' ).trim().toLowerCase();
			var found = null;

			if ( ! wanted || ! classList ) {
				return null;
			}

			classList.querySelectorAll( 'option' ).forEach( function ( option ) {
				if ( ! found && option.value.trim().toLowerCase() === wanted ) {
					found = option;
				}
			} );

			return found;
		}

		/**
		 * The datalist entry for a class ID, for the reverse lookup edit mode
		 * needs: the deck stores an id, the field shows a name.
		 *
		 * @param {number|string} id Class ID.
		 * @return {HTMLOptionElement|null}
		 */
		function classOptionById( id ) {
			if ( ! classList || ! id ) {
				return null;
			}
			return classList.querySelector( 'option[data-id="' + String( id ).replace( /[^0-9]/g, '' ) + '"]' );
		}

		function setClass( id ) {
			if ( classIdField ) {
				classIdField.value = id || '';
			}
		}

		/* ------------------------------------------------------------ *
		 * Level picker
		 *
		 * Precedence, highest first: a manual choice, a class suggestion, the
		 * band the teacher last generated with, B1. The last two are already
		 * settled — the server checked the radio before the page was sent — so
		 * only the first two are decided here.
		 * ------------------------------------------------------------ */

		// Set once the teacher touches the picker, and never unset. A class
		// chosen afterwards must not quietly undo a deliberate choice: the
		// teacher knows something about this deck that the class average does
		// not.
		var levelChosenByHand = false;

		function currentLevel() {
			var chosen = root.querySelector( '.tbt-level-input:checked' );
			return chosen ? chosen.value : '';
		}

		function setLevel( band ) {
			Array.prototype.forEach.call( levelInputs, function ( input ) {
				input.checked = input.value === band;
			} );
		}

		function showLevelNote( text ) {
			if ( ! levelNote ) {
				return;
			}
			levelNote.textContent = text || '';
			levelNote.hidden = ! text;
		}

		Array.prototype.forEach.call( levelInputs, function ( input ) {
			input.addEventListener( 'change', function () {
				levelChosenByHand = true;
				// The note explains where a suggested value came from, so it
				// stops being true the moment the teacher overrides it.
				showLevelNote( '' );
			} );
		} );

		/**
		 * Ask what level this class suggests, and take it unless the teacher
		 * has already chosen one by hand.
		 *
		 * Every "no idea" answer — no TBT Students, no students, no levels —
		 * comes back as suggested: null and leaves the picker exactly as it is.
		 * A failed request is treated the same way and stays silent: the
		 * teacher asked for a class, not for a level, and the picker already
		 * holds a usable value.
		 *
		 * @param {string} classId The class just chosen.
		 */
		function suggestLevelFor( classId ) {
			if ( ! levelInputs.length || levelChosenByHand ) {
				return;
			}

			request( 'classes/' + encodeURIComponent( classId ) + '/level' ).then( function ( data ) {
				if ( levelChosenByHand || ! data || ! data.suggested ) {
					return;
				}
				// The lessons request runs alongside this one and can drop the
				// class before this answer lands — a class with no lessons is
				// taken back out of play. A suggestion for a class that is no
				// longer chosen is not this deck's suggestion.
				if ( classIdField && classIdField.value !== String( classId ) ) {
					return;
				}
				setLevel( data.suggested );
				showLevelNote( data.note );
			} ).catch( function () {} );
		}

		/**
		 * Attach the deck to a class and load that class's lessons.
		 *
		 * Shared by the picker and by edit mode, so a deck reopened for editing
		 * lands in exactly the state the teacher left it in — including a class
		 * that has since lost its lessons, which is taken out of play here in
		 * both directions rather than only when typed.
		 *
		 * @param {HTMLOptionElement} option   Datalist entry for the class.
		 * @param {string}            classId  Class ID.
		 * @param {Object}            settings suggest: ask for a level for this
		 *                                     class; lessonId: the lesson to
		 *                                     select instead of the newest.
		 */
		function attachClass( option, classId, settings ) {
			var opts = settings || {};

			setClass( classId );
			if ( opts.suggest ) {
				suggestLevelFor( classId );
			}

			request( 'classes/' + encodeURIComponent( classId ) + '/lessons' ).then( function ( data ) {
				var lessons = data.lessons || [];

				if ( ! lessons.length ) {
					// Nothing to attach to. Say so, and take the class back
					// out of play rather than leaving a selection that
					// cannot be saved and does not explain itself.
					markClassLessonless( option );
					lessonWrap.hidden = true;
					classInput.value = '';
					setClass( '' );
					// The class is out of play, so a note crediting it
					// would name a class the teacher can no longer see.
					showLevelNote( '' );
					showError( root, i18n.classNoLessons );
					return;
				}

				// The route returns lessons newest-first; option 0 is the
				// most recent, so no client-side sorting is involved.
				lessonSelect.innerHTML = '';
				lessons.forEach( function ( lesson ) {
					var entry = document.createElement( 'option' );
					entry.value = lesson.id;
					entry.textContent = lesson.title;
					lessonSelect.appendChild( entry );
				} );
				lessonSelect.selectedIndex = 0;
				if ( opts.lessonId ) {
					// The deck's own lesson, when it is still one of the
					// class's. A lesson deleted since falls back to the
					// newest, which is what a new deck would get.
					lessonSelect.value = String( opts.lessonId );
					if ( '' === lessonSelect.value ) {
						lessonSelect.selectedIndex = 0;
					}
				}
				lessonWrap.hidden = false;
			} ).catch( function ( error ) {
				lessonWrap.hidden = true;
				setClass( '' );
				showLevelNote( '' );
				showError( root, error.message );
			} );
		}

		/**
		 * The lesson the deck is on, as the list chip spells it — or '' when
		 * the deck is on no lesson at all.
		 *
		 * @return {string}
		 */
		function lessonLabel() {
			if ( ! lessonSelect || ! classIdField || ! classIdField.value ) {
				return '';
			}
			var chosen = lessonSelect.options[ lessonSelect.selectedIndex ];
			return chosen && chosen.value ? chosen.textContent : '';
		}

		if ( classInput && lessonSelect && lessonWrap ) {
			// 'change' rather than 'input': it fires both when a suggestion is
			// picked and when the field is left, so the lessons load once
			// instead of on every keystroke.
			classInput.addEventListener( 'change', function () {
				var typed = classInput.value.trim();
				var option = classOptionFor( typed );
				var classId = option ? option.getAttribute( 'data-id' ) : '';

				resetLessons();
				lessonWrap.hidden = true;
				setClass( '' );
				if ( classHint ) {
					classHint.hidden = true;
				}
				// The note names a class. With no class resolved there is
				// nothing for it to name, whatever the picker is showing.
				showLevelNote( '' );

				if ( ! typed ) {
					return;
				}

				if ( ! option ) {
					// Typed, but not one of this teacher's classes. Say so
					// rather than silently saving the deck unattached.
					if ( classHint ) {
						classHint.hidden = false;
					}
					return;
				}

				if ( option.hasAttribute( 'data-lessonless' ) ) {
					classInput.value = '';
					showError( root, i18n.classNoLessons );
					return;
				}

				attachClass( option, classId, { suggest: true } );
			} );
		}

		/**
		 * Take a class with no lessons out of the picker, so the teacher cannot
		 * land on it again and wonder why saving fails.
		 *
		 * The entry keeps its place in the list, renamed so the reason is
		 * visible, and is flagged so picking it again explains itself instead
		 * of quietly resolving to a class that cannot be saved.
		 *
		 * @param {HTMLOptionElement} option Entry for the class that came back empty.
		 */
		function markClassLessonless( option ) {
			option.setAttribute( 'data-lessonless', '' );
			if ( option.value.indexOf( i18n.noLessonsSuffix ) === -1 ) {
				option.value = option.value.trim() + ' ' + i18n.noLessonsSuffix;
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

			request( 'generate', { method: 'POST', body: { terms: terms.value, level: currentLevel() } } ).then( function ( data ) {
				generateBtn.disabled = false;
				generateStatus.textContent = '';
				// A fresh deck starts from the generated cards. An edit adds to
				// the deck's existing ones: generating two more words there is
				// how a teacher extends a deck, not how they throw it away.
				if ( ! editingId ) {
					reviewBody.innerHTML = '';
				}
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
		 * scrollHeight covers the content and the padding but not the border,
		 * and the reset makes every field border-box, so the border has to be
		 * added back or the last line is clipped by a couple of pixels.
		 *
		 * @param {HTMLTextAreaElement} el Example field.
		 */
		function grow( el ) {
			el.style.height = 'auto';
			el.style.height = ( el.scrollHeight + ( el.offsetHeight - el.clientHeight ) ) + 'px';
		}

		function growAll() {
			reviewBody.querySelectorAll( '[data-field="example"]' ).forEach( grow );
		}

		// A narrower row wraps the same sentence onto more lines, and a height
		// fixed in pixels does not follow it, so every field is remeasured
		// after a resize or an orientation change.
		var growTimer = null;
		window.addEventListener( 'resize', function () {
			clearTimeout( growTimer );
			growTimer = setTimeout( growAll, 120 );
		} );

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

			var open = 'open' === currentDeckType();
			var body = {
				title: title,
				deck_type: currentDeckType(),
				level: currentLevel(),
				front_face: currentFrontFace(),
				cards: cards
			};

			// Omitted, not emptied: the server leaves a column it was sent
			// nothing for exactly as it was. Sending an empty class here would
			// detach the deck rather than leave it alone.
			if ( ! classUnavailable ) {
				// An open deck carries no class or lesson. The server drops
				// them anyway; sending them empty keeps the two in step.
				body.class_id = ( ! open && classIdField ) ? classIdField.value : '';
				body.lesson_id = ( ! open && lessonSelect ) ? lessonSelect.value : '';
			}

			// An edited deck is saved over itself, so its id, slug, link and QR
			// code survive the edit — the whole point of the Edit button.
			request( editingId ? 'sets/' + encodeURIComponent( editingId ) : 'sets', {
				method: 'POST',
				body: body
			} ).then( function ( data ) {
				saveBtn.disabled = false;
				saveStatus.textContent = '';

				if ( data.updated ) {
					refreshDeckRow( data.id, {
						title: title,
						cards: cards.length,
						open: open,
						lesson: lessonLabel(),
						keepChip: classUnavailable
					} );
				}

				// The edit is finished: the banner would otherwise keep
				// offering to cancel a change that has already been saved.
				leaveEditMode();
				// The review panel closes on save, as it always has — stage 3
				// is the answer to "I pressed save", not another edit surface.
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
			leaveEditMode();
			titleInput.value = '';
			terms.value = '';
			reviewBody.innerHTML = '';
			reviewPanel.hidden = true;
			resultPanel.hidden = true;
			if ( resultWait ) {
				resultWait.hidden = false;
			}
			// A new deck starts from the defaults, not from the last deck's
			// answers: a class deck showing its term first.
			setDeckType( 'class' );
			applyDeckType( false );
			setFrontFace( 'term' );
			setStages( 'active', 'active', 'waiting' );
			updateCount();
			titleInput.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			titleInput.focus();
		}

		if ( discardBtn ) {
			discardBtn.addEventListener( 'click', function () {
				if ( ! editingId || ! libraryUrl || ! window.confirm( i18n.confirmDiscard ) ) {
					return;
				}

				clearError( root );
				discardBtn.disabled = true;

				request( 'sets/' + encodeURIComponent( editingId ), { method: 'DELETE' } )
					.then( function () {
						window.location.href = libraryUrl;
					} )
					.catch( function ( error ) {
						discardBtn.disabled = false;
						showError( root, error.message );
					} );
			} );
		}

		[ role( root, 'reset' ), role( root, 'start-over' ) ].forEach( function ( button ) {
			if ( button ) {
				button.addEventListener( 'click', reset );
			}
		} );

		/* ------------------------------------------------------------ *
		 * Edit mode
		 *
		 * Reached by the Edit button in the deck list, which links to this
		 * page with the deck's id in the query string. Loading the deck rather
		 * than rendering it server-side keeps the builder one template: the
		 * same stages, the same controls, one extra banner.
		 * ------------------------------------------------------------ */

		function enterEditMode( id, title ) {
			editingId = id;
			if ( editingTitle ) {
				editingTitle.textContent = title;
			}
			if ( editingBanner ) {
				editingBanner.hidden = false;
			}
			if ( saveBtn ) {
				saveBtn.textContent = i18n.saveChanges || saveLabel;
			}
		}

		function leaveEditMode() {
			if ( ! editingId ) {
				return;
			}
			editingId = 0;
			classUnavailable = false;
			if ( editingBanner ) {
				editingBanner.hidden = true;
			}
			if ( discardBtn ) {
				discardBtn.hidden = true;
				discardBtn.disabled = false;
			}
			if ( saveBtn ) {
				saveBtn.textContent = saveLabel;
			}
			// Drop the parameter so a reload does not reopen an edit the
			// teacher has already finished or abandoned.
			clearEditParam();
		}

		function clearEditParam() {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}
			try {
				var url = new URL( window.location.href );
				if ( ! url.searchParams.has( EDIT_PARAM ) ) {
					return;
				}
				url.searchParams.delete( EDIT_PARAM );
				window.history.replaceState( null, '', url.toString() );
			} catch ( e ) {}
		}

		/**
		 * Load a deck back into the builder.
		 *
		 * Every field is filled from the saved deck: a save that follows must
		 * be able to leave anything the teacher did not touch exactly as it
		 * was, so a blank the loader skipped would quietly erase itself.
		 *
		 * @param {number} id Deck ID.
		 */
		function loadForEdit( id ) {
			request( 'sets/' + encodeURIComponent( id ) ).then( function ( data ) {
				enterEditMode( id, data.title || '' );

				titleInput.value = data.title || '';
				setDeckType( data.deckType );
				setFrontFace( data.frontFace );
				// Keep the class and lesson: they are restored below, and a
				// class deck must not be cleared on the way in.
				applyDeckType( true );

				if ( data.level ) {
					// The band the deck was generated at. It is the deck's own
					// answer, so a class suggestion must not talk over it.
					setLevel( data.level );
					levelChosenByHand = true;
				}

				classUnavailable = false;
				if ( 'open' !== data.deckType && data.classId ) {
					var option = classInput && lessonSelect && lessonWrap ? classOptionById( data.classId ) : null;
					if ( option ) {
						classInput.value = option.value;
						attachClass( option, data.classId, { lessonId: data.lessonId } );
					} else {
						// The picker cannot show this class, so the teacher
						// cannot confirm or change it. Leaving it out of the
						// save is the only reading that does not throw away an
						// attachment behind their back.
						classUnavailable = true;
					}
				}

				reviewBody.innerHTML = '';
				( data.cards || [] ).forEach( addRow );

				// A deck named in the library's create modal arrives with no
				// cards. An empty "Check and fix" table with a Save button
				// under it would put the last step first; what the teacher
				// does next is compose and generate.
				var hasCards = !! ( data.cards && data.cards.length );
				reviewPanel.hidden = ! hasCards;
				resultPanel.hidden = true;
				if ( resultWait ) {
					resultWait.hidden = false;
				}
				if ( hasCards ) {
					// Rows built inside a hidden panel all measure zero, so
					// the example fields only get their true height once it
					// is shown.
					growAll();
				}

				// Discard is for drafts alone: deleting a published deck lives
				// in the library, where the row it removes is in view.
				if ( discardBtn && libraryUrl && 'published' !== data.status ) {
					discardBtn.hidden = false;
				}

				setStages( 'active', 'active', 'waiting' );
			} ).catch( function ( error ) {
				clearEditParam();
				showError( root, error.message );
			} );
		}

		var editId = editParam();
		if ( editId ) {
			loadForEdit( editId );
		}
	}

	/** Query parameter the Edit button uses. Mirrors TBTS_Frontend::EDIT_PARAM. */
	var EDIT_PARAM = 'tbts_edit';

	/**
	 * The generator page with a deck marked for editing.
	 *
	 * Built through URL so a query string the generator page already carries
	 * survives having the edit parameter added to it.
	 *
	 * @param {string} base Generator page URL.
	 * @param {number} id   Deck to edit.
	 * @return {string}
	 */
	function editHref( base, id ) {
		try {
			var url = new URL( base, window.location.href );
			url.searchParams.set( EDIT_PARAM, String( id ) );
			return url.toString();
		} catch ( e ) {
			return base + ( -1 === base.indexOf( '?' ) ? '?' : '&' ) + EDIT_PARAM + '=' + encodeURIComponent( id );
		}
	}

	/**
	 * The deck the URL asks to edit, or 0.
	 *
	 * @return {number}
	 */
	function editParam() {
		try {
			var id = parseInt( new URLSearchParams( window.location.search ).get( EDIT_PARAM ), 10 );
			return id > 0 ? id : 0;
		} catch ( e ) {}
		return 0;
	}

	/* ---------------------------------------------------------------- *
	 * Set list
	 * ---------------------------------------------------------------- */

	function initSets( root ) {
		var modal = role( root, 'qr-modal' );
		var qrTarget = role( root, 'qr-target' );
		var qrTitle = role( root, 'qr-title' );

		// The create dialog is rendered by PHP, hidden, like the QR one. All
		// this does is open it, guard it and post what it collects.
		var createModal = role( root, 'create-modal' );
		var createInput = createModal ? createModal.querySelector( '#tbts-create-input' ) : null;
		var createError = role( root, 'create-error' );
		var createSubmit = role( root, 'create-submit' );
		var createCancel = role( root, 'create-cancel' );
		var createLabel = createSubmit ? createSubmit.textContent : '';
		// True from the moment the draft is posted. The dialog refuses to
		// close while it holds: a teacher who dismissed it mid-flight would be
		// left on the library with a deck appearing behind them.
		var createPending = false;
		// The button that opened the dialog, so focus can go back to it.
		var createOpener = null;

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
					closeModal( modal );
					break;
				case 'create':
					openCreate( button );
					break;
				case 'create-cancel':
					closeModal( createModal );
					break;
				case 'create-submit':
					submitCreate();
					break;
				case 'delete':
					deleteSet( button );
					break;
			}
		} );

		/* Which modal is open is a question about the DOM, not a flag to keep
		   in sync, so Escape and the backdrop ask it rather than each growing
		   a second copy of themselves. */
		function openModal() {
			if ( modal && ! modal.hidden ) {
				return modal;
			}
			if ( createModal && ! createModal.hidden ) {
				return createModal;
			}
			return null;
		}

		function closeModal( which ) {
			if ( ! which || which.hidden ) {
				return;
			}
			if ( which === createModal ) {
				if ( createPending ) {
					return;
				}
				which.hidden = true;
				if ( createOpener ) {
					createOpener.focus();
					createOpener = null;
				}
				return;
			}
			which.hidden = true;
		}

		[ modal, createModal ].forEach( function ( which ) {
			if ( ! which ) {
				return;
			}
			which.addEventListener( 'click', function ( event ) {
				if ( event.target === which ) {
					closeModal( which );
				}
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			var open = openModal();
			if ( ! open ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				closeModal( open );
				return;
			}
			if ( 'Tab' === event.key && open === createModal ) {
				trapFocus( createModal, event );
			}
		} );

		/**
		 * Keep Tab inside the open dialog. Disabled controls are skipped, so
		 * the cycle follows what the teacher can actually reach.
		 */
		function trapFocus( box, event ) {
			var candidates = box.querySelectorAll( 'input, button' );
			var focusable = [];
			var i;

			for ( i = 0; i < candidates.length; i++ ) {
				if ( ! candidates[ i ].disabled ) {
					focusable.push( candidates[ i ] );
				}
			}
			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		function openCreate( button ) {
			if ( ! createModal ) {
				return;
			}

			createOpener = button;
			createPending = false;
			setCreateError( '' );
			if ( createInput ) {
				createInput.value = '';
			}
			if ( createCancel ) {
				createCancel.disabled = false;
			}
			if ( createSubmit ) {
				createSubmit.textContent = createLabel;
			}
			syncCreateSubmit();

			createModal.hidden = false;
			if ( createInput ) {
				createInput.focus();
			}
		}

		/* Nothing to create from an empty title, and nothing to create twice
		   from one already in flight. */
		function syncCreateSubmit() {
			if ( ! createSubmit ) {
				return;
			}
			createSubmit.disabled = createPending || ! createInput || '' === createInput.value.trim();
		}

		function setCreateError( message ) {
			if ( ! createError ) {
				return;
			}
			createError.textContent = message;
			createError.hidden = '' === message;
		}

		if ( createInput ) {
			createInput.addEventListener( 'input', syncCreateSubmit );
			createInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					// The input is not in a form, but Enter is what a teacher
					// reaches for after typing a title.
					event.preventDefault();
					submitCreate();
				}
			} );
		}

		function submitCreate() {
			if ( ! createInput || createPending ) {
				return;
			}

			var title = createInput.value.trim();
			var target = root.getAttribute( 'data-generator-url' ) || '';
			if ( '' === title || '' === target ) {
				return;
			}

			createPending = true;
			syncCreateSubmit();
			if ( createCancel ) {
				createCancel.disabled = true;
			}
			if ( createSubmit ) {
				createSubmit.textContent = i18n.creating || createLabel;
			}
			setCreateError( '' );

			request( 'sets/draft', { method: 'POST', body: { title: title } } )
				.then( function ( data ) {
					window.location.href = editHref( target, data.id );
				} )
				.catch( function ( error ) {
					createPending = false;
					if ( createCancel ) {
						createCancel.disabled = false;
					}
					if ( createSubmit ) {
						createSubmit.textContent = createLabel;
					}
					syncCreateSubmit();
					setCreateError( error.message || i18n.createFailed );
					createInput.focus();
				} );
		}

		/* Rows and groups are found by their behavioural attributes, never by
		   the class names the design owns. */
		function deleteSet( button ) {
			var row = button.closest( '[data-set-id]' );
			if ( ! row ) {
				return;
			}

			// A draft usually holds no cards, so the published wording would
			// overstate what is about to be lost. The row says which it is.
			var confirmed = '1' === row.getAttribute( 'data-draft' ) ? i18n.confirmDiscard : i18n.confirmDelete;
			if ( ! window.confirm( confirmed ) ) {
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
