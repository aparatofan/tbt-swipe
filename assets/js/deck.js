/* TBT Swipe — frontend deck (design v1.1).
   Vanilla JS, Pointer Events, no dependencies.
   Stateless: everything lives in memory; refreshing restarts the deck.

   Gesture axis is vertical: swipe up = "I know it", swipe down = "Not yet".
   The card translates on Y and recedes with a subtle scale; there is no
   card tint — the zone labels give all the feedback. */
( function () {
	'use strict';

	var cfg = window.tbtsDeck || {};
	var i18n = cfg.i18n || {};
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var THRESHOLD_RATIO = 0.25;   // fraction of card height to commit a swipe
	var FLICK_VELOCITY = 0.5;     // px/ms — a fast flick commits below threshold
	var SCALE_MIN = 0.94;         // card scale at threshold (recedes)

	/* Desktop: the card becomes a fixed 380x500 portrait card on a table, with
	   a stack of backs beneath it. Deliberately width-only — a Surface or an
	   iPad in landscape should get the table, so no `pointer: fine`. These two
	   constants must match the width/height in the min-width:900px block. */
	var DESKTOP_MQ = window.matchMedia ? window.matchMedia( '( min-width: 900px )' ) : null;
	var CARD_W = 380;
	var CARD_H = 500;
	var STACK_MAX = 4;            // card backs drawn under the live card
	var ZONE_ARM = 40;            // px of drag before a desktop zone lights up

	var root, stage, progressEl, stackEl;
	var zones = null;    // { up: {el,label,arrow}, down: {el,label,arrow} }
	var fullDeck = [];   // original loaded cards
	var queue = [];      // cards still to review this round
	var unknown = [];    // cards swiped down (not yet) this round
	var seenCount = 0;   // cards shown this round (for progress)
	var roundTotal = 0;
	var current = null;  // active card DOM state

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		root = document.getElementById( 'tbts-deck-root' );
		if ( ! root ) {
			return;
		}

		// The deck is a fixed full-viewport overlay; lock the page behind it
		// so nothing scrolls under it (iOS keeps scrolling the body otherwise).
		document.body.classList.add( 'tbts-deck-open' );

		// One document-level key handler for the whole session; it consults
		// `current`, so it is inert on the loading and summary screens.
		document.addEventListener( 'keydown', onKeydown );

		var slug = getSlug();
		if ( ! slug ) {
			renderMessage( i18n.noSet );
			return;
		}
		load( slug );
	}

	function getSlug() {
		try {
			var params = new URLSearchParams( window.location.search );
			// 'deck' is our slug param. 's' is reserved by WordPress for
			// site search, so we must not use it. Accept 's' only as a
			// fallback for any old QR codes still in circulation.
			var slug = params.get( 'deck' ) || params.get( 's' );
			if ( slug && /^[A-Za-z0-9]{12}$/.test( slug ) ) {
				return slug;
			}
		} catch ( e ) {}
		return null;
	}

	function load( slug ) {
		fetch( cfg.restBase + encodeURIComponent( slug ), {
			headers: { 'Accept': 'application/json' },
			cache: 'no-store'
		} )
			.then( function ( r ) {
				if ( r.status === 404 ) {
					throw { notFound: true };
				}
				if ( ! r.ok ) {
					throw { load: true };
				}
				return r.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.cards || ! data.cards.length ) {
					renderMessage( i18n.empty );
					return;
				}
				fullDeck = data.cards.slice();
				startRound( fullDeck.slice() );
			} )
			.catch( function ( err ) {
				renderMessage( err && err.notFound ? i18n.notFound : i18n.loadError );
			} );
	}

	function renderMessage( msg ) {
		root.innerHTML = '';
		var div = document.createElement( 'div' );
		div.className = 'tbts-message';
		div.textContent = msg;
		root.appendChild( div );
	}

	/* ---- Round lifecycle ---- */
	function startRound( cards ) {
		queue = cards.slice();
		unknown = [];
		seenCount = 0;
		roundTotal = queue.length;
		current = null;

		root.innerHTML = '';

		var up = buildZone( 'up' );
		stage = el( 'div', 'tbts-stage' );
		var down = buildZone( 'down' );

		// The undealt remainder, drawn beneath the live card. Presentational
		// only; CSS hides it below the desktop breakpoint.
		stackEl = el( 'div', 'tbts-stack' );
		stackEl.setAttribute( 'aria-hidden', 'true' );
		stage.appendChild( stackEl );

		root.appendChild( up.el );
		root.appendChild( stage );
		root.appendChild( down.el );

		zones = { up: up, down: down };

		nextCard();
	}

	function buildZone( dir ) {
		var isUp = dir === 'up';
		var btn = el( 'button', 'tbts-zone ' + ( isUp ? 'tbts-zone-up' : 'tbts-zone-down' ) );
		btn.type = 'button';
		btn.setAttribute( 'aria-label', isUp ? i18n.knowIt : i18n.notYet );

		if ( isUp ) {
			progressEl = el( 'span', 'tbts-progress' );
			btn.appendChild( progressEl );
		}

		var arrow = el( 'span', 'tbts-zone-arrow' );
		arrow.textContent = isUp ? '↑' : '↓';
		var label = el( 'span', 'tbts-zone-label' );
		label.textContent = isUp ? i18n.knowIt : i18n.notYet;

		// Arrow points away from the card on both faces.
		if ( isUp ) {
			btn.appendChild( arrow );
			btn.appendChild( label );
		} else {
			btn.appendChild( label );
			btn.appendChild( arrow );
		}

		btn.addEventListener( 'click', function () { commit( dir ); } );

		return { el: btn, label: label, arrow: arrow };
	}

	function nextCard() {
		if ( ! queue.length ) {
			endRound();
			return;
		}
		var card = queue.shift();
		seenCount++;
		updateProgress();
		renderStack();
		renderCard( card );
	}

	function updateProgress() {
		if ( progressEl ) {
			progressEl.textContent = seenCount + ' / ' + roundTotal;
		}
	}

	/* The stack of backs under the live card: one per undealt card, up to
	   four, so the deck visibly thins as it is played and vanishes on the
	   last card. No pointer events, no content. */
	function renderStack() {
		if ( ! stackEl ) {
			return;
		}
		stackEl.innerHTML = '';
		var n = Math.min( STACK_MAX, queue.length );
		for ( var i = 0; i < n; i++ ) {
			var back = el( 'div', 'tbts-stack-card' );
			var depth = i + 1;
			var rot = ( depth % 2 ? 1 : -1 ) * 0.5 * depth;
			back.style.transform = 'translate(' + ( depth * 2 ) + 'px,' + ( depth * 3 ) + 'px) rotate(' + rot + 'deg)';
			back.style.opacity = ( 1 - depth * 0.08 ).toFixed( 2 );
			// Deeper cards paint behind, so the faded ones never wash over
			// the card immediately under the live one.
			back.style.zIndex = String( STACK_MAX - depth );
			stackEl.appendChild( back );
		}
	}

	/* ---- Card rendering ---- */
	function renderCard( card ) {
		var cardEl = el( 'div', 'tbts-card' );
		var inner = el( 'div', 'tbts-card-inner' );

		var front = el( 'div', 'tbts-face tbts-face-front' );
		var term = el( 'div', 'tbts-term' );
		term.textContent = card.term;
		front.appendChild( term );
		var hint = el( 'div', 'tbts-flip-hint' );
		hint.textContent = i18n.tapToFlip;
		front.appendChild( hint );

		var back = el( 'div', 'tbts-face tbts-face-back' );
		if ( card.ipa ) {
			var ipa = el( 'div', 'tbts-ipa' );
			ipa.textContent = card.ipa;
			back.appendChild( ipa );
		}
		if ( card.translation ) {
			var tr = el( 'div', 'tbts-translation' );
			tr.textContent = card.translation;
			back.appendChild( tr );
		}
		if ( card.example ) {
			var ex = el( 'div', 'tbts-example' );
			ex.textContent = card.example;
			back.appendChild( ex );
		}

		inner.appendChild( front );
		inner.appendChild( back );
		cardEl.appendChild( inner );

		current = { card: card, el: cardEl, inner: inner, flipped: false, locked: false };

		stage.appendChild( cardEl );
		attachPointer( cardEl );
	}

	/* ---- Pointer / drag handling ---- */
	function attachPointer( cardEl ) {
		var startX = 0, startY = 0, startT = 0;
		var dragging = false;
		var moved = false;
		var height = cardEl.offsetHeight || 360;

		cardEl.addEventListener( 'pointerdown', function ( e ) {
			if ( current.locked ) {
				return;
			}
			dragging = true;
			moved = false;
			height = cardEl.offsetHeight || height;
			startX = e.clientX;
			startY = e.clientY;
			startT = now();
			cardEl.classList.add( 'tbts-dragging' );
			try { cardEl.setPointerCapture( e.pointerId ); } catch ( err ) {}
		} );

		cardEl.addEventListener( 'pointermove', function ( e ) {
			if ( ! dragging || current.locked ) {
				return;
			}
			var dx = e.clientX - startX;
			var dy = e.clientY - startY;

			if ( ! moved && Math.abs( dy ) < 4 && Math.abs( dx ) < 4 ) {
				return;
			}
			moved = true;
			applyDrag( dy, height );
		} );

		function endDrag( e ) {
			if ( ! dragging ) {
				return;
			}
			dragging = false;
			cardEl.classList.remove( 'tbts-dragging' );
			try { cardEl.releasePointerCapture( e.pointerId ); } catch ( err ) {}

			if ( current.locked ) {
				return;
			}

			var dy = e.clientY - startY;
			var dt = Math.max( now() - startT, 1 );
			var velocity = dy / dt; // px/ms on Y
			var pastThreshold = Math.abs( dy ) > height * THRESHOLD_RATIO;
			var flick = Math.abs( velocity ) > FLICK_VELOCITY && Math.abs( dy ) > 24;

			if ( ! moved ) {
				// A tap: flip.
				flip();
				return;
			}

			if ( pastThreshold || flick ) {
				commit( dy < 0 ? 'up' : 'down' );
			} else {
				springBack();
			}
		}

		cardEl.addEventListener( 'pointerup', endDrag );
		cardEl.addEventListener( 'pointercancel', endDrag );
	}

	function applyDrag( dy, height ) {
		var progress = Math.min( 1, Math.abs( dy ) / ( height * THRESHOLD_RATIO ) );
		var scale = 1 - ( 1 - SCALE_MIN ) * progress;

		current.el.style.transition = 'none';
		current.el.style.transform = 'translateY(' + dy + 'px) scale(' + scale.toFixed( 3 ) + ')';

		if ( dy < 0 ) {
			zoneFeedback( zones.up, zones.down, progress, dy );
		} else if ( dy > 0 ) {
			zoneFeedback( zones.down, zones.up, progress, dy );
		} else {
			resetZones();
		}
	}

	// Brighten the zone the card is heading toward, dim the opposite one.
	// Mobile ramps the opacity with the drag; on the desktop table the label
	// is a typographic mark that simply arms once the drag passes 40px, so
	// the state lives in a class and the inline opacity stays out of it.
	function zoneFeedback( active, dim, progress, dy ) {
		if ( isDesktop() ) {
			var armed = Math.abs( dy ) > ZONE_ARM;
			active.el.classList.toggle( 'is-active', armed );
			dim.el.classList.toggle( 'is-dim', armed );
			return;
		}
		var a = ( 0.6 + 0.4 * progress ).toFixed( 3 );
		var d = ( 0.6 - 0.3 * progress ).toFixed( 3 );
		active.label.style.opacity = a;
		active.arrow.style.opacity = a;
		dim.label.style.opacity = d;
		dim.arrow.style.opacity = d;
	}

	function resetZones() {
		if ( ! zones ) {
			return;
		}
		[ zones.up, zones.down ].forEach( function ( z ) {
			z.label.style.opacity = '';
			z.arrow.style.opacity = '';
			z.el.classList.remove( 'is-active', 'is-dim' );
		} );
	}

	function springBack() {
		current.el.style.transition = 'transform 250ms ease-out';
		current.el.style.transform = '';
		resetZones();
	}

	function flip() {
		if ( current.locked ) {
			return;
		}
		current.flipped = ! current.flipped;
		current.el.classList.toggle( 'tbts-flipped', current.flipped );
	}

	/* ---- Commit a decision ---- */
	function commit( dir ) {
		if ( ! current || current.locked ) {
			return;
		}
		current.locked = true;
		resetZones();

		var card = current.card;
		var cardEl = current.el;

		if ( dir === 'up' ) {
			// Known → flick away along the gesture axis.
			if ( reducedMotion ) {
				fadeOut( cardEl, 150, afterCommit );
			} else {
				flickOut( cardEl, afterCommit );
			}
		} else {
			// Not yet → slide down and off, keep for the next round.
			unknown.push( card );
			if ( reducedMotion ) {
				fadeOut( cardEl, 150, afterCommit );
			} else {
				slideDown( cardEl, afterCommit );
			}
		}
	}

	function afterCommit() {
		current = null;
		nextCard();
	}

	/* ---- Up: the flick (known) ---- */
	/* A fast, decisive exit: the card accelerates off the top of the screen on a
	   slight arc and is gone in about a fifth of a second. The next card is
	   dealt at 210ms — the learner never waits on the animation. */
	function flickOut( cardEl, done ) {
		var rect = cardEl.getBoundingClientRect();
		// Past the top of the viewport plus a margin, so the card clears any
		// screen height before it is removed.
		var ty = -( rect.bottom + 300 );
		// ±10–18deg, with a matching horizontal drift so the card leaves on a
		// believable arc rather than straight up.
		var rot = ( 10 + Math.random() * 8 ) * ( Math.random() < 0.5 ? -1 : 1 );
		var tx = rot * 4;

		cardEl.style.willChange = 'transform, opacity';
		cardEl.style.transition =
			'transform 200ms cubic-bezier(.4,0,.9,.5), ' +
			// The fade trails the movement instead of pre-empting it.
			'opacity 200ms linear 60ms';
		cardEl.style.transform = 'translate(' + tx.toFixed( 1 ) + 'px,' + ty.toFixed( 1 ) + 'px) rotate(' + rot.toFixed( 1 ) + 'deg)';
		cardEl.style.opacity = '0';

		setTimeout( function () {
			removeEl( cardEl );
			done();
		}, 210 );
	}

	/* ---- Down: plain exit ---- */
	function slideDown( cardEl, done ) {
		var h = stage.offsetHeight || cardEl.offsetHeight || 500;
		cardEl.style.transition = 'transform 300ms ease-in, opacity 300ms ease-in';
		cardEl.style.transform = 'translateY(' + ( h + 120 ) + 'px)';
		cardEl.style.opacity = '0';
		whenDone( cardEl, 320, function () {
			removeEl( cardEl );
			done();
		} );
	}

	function fadeOut( cardEl, dur, done ) {
		cardEl.style.transition = 'opacity ' + dur + 'ms ease';
		cardEl.style.opacity = '0';
		whenDone( cardEl, dur + 40, function () {
			removeEl( cardEl );
			done();
		} );
	}

	/* ---- Keyboard ---- */
	function onKeydown( e ) {
		if ( ! current || current.locked ) {
			return;
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			commit( 'up' );
		} else if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			commit( 'down' );
		}
	}

	/* ---- Summary screen ---- */
	function endRound() {
		root.innerHTML = '';
		zones = null;
		progressEl = null;

		if ( ! unknown.length ) {
			renderAllKnown();
			return;
		}

		var end = el( 'div', 'tbts-end' );
		var h2 = el( 'h2' );
		h2.textContent = i18n.stillLearn;
		end.appendChild( h2 );

		// <div>s, not <ul>/<li>: the list is presentational (each item is a
		// self-contained card), and divs sidestep the bullet markers Divi and
		// WP core inject into content lists.
		var list = el( 'div', 'tbts-end-list' );
		unknown.forEach( function ( card ) {
			var item = el( 'div', 'tbts-end-item' );

			var term = el( 'span', 'tbts-end-term' );
			term.textContent = card.term;
			item.appendChild( term );
			if ( card.ipa ) {
				var ipa = el( 'span', 'tbts-end-ipa' );
				ipa.textContent = card.ipa;
				item.appendChild( ipa );
			}
			if ( card.translation ) {
				var tr = el( 'div', 'tbts-end-tr' );
				tr.textContent = card.translation;
				item.appendChild( tr );
			}
			if ( card.example ) {
				var ex = el( 'div', 'tbts-end-ex' );
				ex.textContent = card.example;
				item.appendChild( ex );
			}
			list.appendChild( item );
		} );
		end.appendChild( list );

		var again = el( 'button', 'tbts-again' );
		again.type = 'button';
		again.textContent = i18n.goAgain;
		again.addEventListener( 'click', function () {
			startRound( shuffle( unknown.slice() ) );
		} );

		var actions = el( 'div', 'tbts-end-actions' );
		actions.appendChild( again );
		end.appendChild( actions );

		root.appendChild( end );
	}

	function renderAllKnown() {
		var end = el( 'div', 'tbts-end' );
		var msg = el( 'div', 'tbts-end-success' );
		msg.textContent = i18n.allKnown;
		end.appendChild( msg );

		var restart = el( 'button', 'tbts-again' );
		restart.type = 'button';
		restart.textContent = i18n.restart;
		restart.addEventListener( 'click', function () {
			startRound( fullDeck.slice() );
		} );

		var actions = el( 'div', 'tbts-end-actions' );
		actions.appendChild( restart );
		end.appendChild( actions );

		root.appendChild( end );
	}

	/* ---- Helpers ---- */
	function isDesktop() {
		return !! ( DESKTOP_MQ && DESKTOP_MQ.matches );
	}
	function el( tag, cls ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		return e;
	}
	function removeEl( node ) {
		if ( node && node.parentNode ) { node.parentNode.removeChild( node ); }
	}
	function now() {
		return window.performance && performance.now ? performance.now() : Date.now();
	}
	function shuffle( arr ) {
		for ( var i = arr.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var t = arr[ i ]; arr[ i ] = arr[ j ]; arr[ j ] = t;
		}
		return arr;
	}
	// Run cb on transitionend, with a timeout fallback so we never stall.
	function whenDone( node, timeout, cb ) {
		var called = false;
		function fire() {
			if ( called ) { return; }
			called = true;
			node.removeEventListener( 'transitionend', fire );
			node.removeEventListener( 'animationend', fire );
			cb();
		}
		node.addEventListener( 'transitionend', fire );
		node.addEventListener( 'animationend', fire );
		setTimeout( fire, timeout + 60 );
	}
} )();
