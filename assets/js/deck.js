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
	var HEAP_SHOW = 130;          // px of a heaped card left showing above the edge

	var root, stage, progressEl, stackEl;
	var zones = null;    // { up: {el,label,arrow}, down: {el,label,arrow} }
	var fullDeck = [];   // original loaded cards
	var queue = [];      // cards still to review this round
	var unknown = [];    // cards swiped down (not yet) this round
	var seenCount = 0;   // cards shown this round (for progress)
	var roundTotal = 0;
	var current = null;  // active card DOM state
	var heap = [];       // desktop only: { el, idx, card } left on the table
	var heapZ = 0;       // later cards lie on top of earlier ones

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
		window.addEventListener( 'resize', onResize );

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
				// This response came back with cache: 'no-store', so it knows
				// the server's true version. If the page around it does not,
				// the page is stale and there is nothing on it that can say so.
				if ( reloadIfStale( data.plugin_version ) ) {
					return;
				}
				fullDeck = data.cards.slice();
				startRound( fullDeck.slice() );
			} )
			.catch( function ( err ) {
				renderMessage( err && err.notFound ? i18n.notFound : i18n.loadError );
			} );
	}

	/* Refetch the page when the HTML we are running in predates the plugin on
	   the server. Returns true when a navigation has been started, in which
	   case the caller must not carry on.

	   Reloading is always the second choice: a student on a misconfigured
	   host must end up with a working game, never a reload loop. So every
	   uncertainty here — no versions to compare, no sessionStorage to record
	   the attempt with, an unparseable URL — resolves to "play on". */
	function reloadIfStale( serverVersion ) {
		if ( ! serverVersion || ! cfg.version || serverVersion === cfg.version ) {
			return false;
		}

		// Keyed on the server version so a later release gets its own single
		// attempt. Private browsing on iOS throws on both read and write.
		var key = 'tbts-reloaded-' + serverVersion;
		try {
			if ( window.sessionStorage.getItem( key ) ) {
				return false;
			}
			window.sessionStorage.setItem( key, '1' );
		} catch ( e ) {
			// No way to record the attempt means no way to stop a loop.
			return false;
		}

		var url;
		try {
			url = new URL( window.location.href );
		} catch ( e ) {
			return false;
		}
		// A changed URL defeats an HTTP, plugin or CDN cache; a plain reload
		// can be answered from the very entry we are trying to escape. Every
		// existing parameter survives — deck=<slug> above all, or the student
		// lands on an empty player.
		url.searchParams.set( 'tbtsv', serverVersion );
		window.location.replace( url.toString() );
		return true;
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
		// A heap card from the previous round must never survive into this
		// one; root.innerHTML below drops the elements, this drops the refs.
		heap = [];
		heapZ = 0;

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
			// `current` is null once a card has been committed; a heaped card
			// keeps its listeners, so every guard here has to tolerate that.
			if ( ! current || current.locked ) {
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
			if ( ! dragging || ! current || current.locked ) {
				return;
			}
			var dx = e.clientX - startX;
			var dy = e.clientY - startY;

			if ( ! moved && Math.abs( dy ) < 4 && Math.abs( dx ) < 4 ) {
				return;
			}
			moved = true;
			applyDrag( dx, dy, height );
		} );

		function endDrag( e ) {
			if ( ! dragging ) {
				return;
			}
			dragging = false;
			cardEl.classList.remove( 'tbts-dragging' );
			try { cardEl.releasePointerCapture( e.pointerId ); } catch ( err ) {}

			if ( ! current || current.locked ) {
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

	function applyDrag( dx, dy, height ) {
		var progress = Math.min( 1, Math.abs( dy ) / ( height * THRESHOLD_RATIO ) );

		current.el.style.transition = 'none';

		if ( isDesktop() ) {
			// The card sits under the cursor: both axes, unattenuated. It is
			// being placed on a table, not dismissed, so it does not shrink.
			// Rotation follows X, not Y — a card pulled sideways pivots, a
			// card pulled straight down does not spin. That is what sells
			// the weight.
			var rot = Math.max( -15, Math.min( 15, dx * 0.06 ) );
			current.el.style.transform =
				'translate(' + Math.round( dx ) + 'px,' + Math.round( dy ) + 'px) rotate(' + rot.toFixed( 2 ) + 'deg)';
			// Remembered so the card can settle where it was let go.
			current.dragX = dx;
			current.dragRot = rot;
		} else {
			// On a phone the gesture is a flick, not a placement, and the
			// recede reads correctly there. Unchanged.
			var scale = 1 - ( 1 - SCALE_MIN ) * progress;
			current.el.style.transform = 'translateY(' + dy + 'px) scale(' + scale.toFixed( 3 ) + ')';
		}

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
		// Clears translation and rotation together, so nothing is left leaning.
		current.el.style.transform = '';
		// The card is back on the pile; a later keyboard or zone commit must
		// fall back to a seeded slot, not to where this drag happened to end.
		current.dragX = null;
		current.dragRot = null;
		resetZones();
	}

	function flip() {
		if ( ! current || current.locked ) {
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
		// null on the keyboard and zone-button paths, which is the signal to
		// fall back to a seeded slot.
		var dragX = typeof current.dragX === 'number' ? current.dragX : null;
		var dragRot = typeof current.dragRot === 'number' ? current.dragRot : null;

		if ( dir === 'up' ) {
			// Known → flick away along the gesture axis.
			if ( reducedMotion ) {
				fadeOut( cardEl, 150, afterCommit );
			} else {
				flickOut( cardEl, afterCommit );
			}
		} else {
			// Not yet → keep for the next round.
			unknown.push( card );
			if ( isDesktop() ) {
				// On the table the card stays put as a record of the round.
				// The next card arrives at 300ms, before the flop finishes:
				// the learner never waits for an animation.
				toHeap( cardEl, card, dragX, dragRot );
				setTimeout( afterCommit, reducedMotion ? 0 : 300 );
			} else if ( reducedMotion ) {
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

	/* ---- Down: the heap (desktop) ----
	   A "not yet" card lands at the bottom edge of the table and stays there
	   for the rest of the round, so the learner can see the pile growing. */
	function toHeap( cardEl, card, releaseX, releaseRot ) {
		var idx = fullDeck.indexOf( card );
		if ( idx < 0 ) {
			idx = heap.length;
		}
		// Resolved once, here, and kept: where a card lands should be a
		// consequence of what the learner did with it, and storing the
		// answer is what keeps a resize from reshuffling the heap.
		var slot = heapSlot( idx, releaseX, releaseRot );

		heapZ++;
		// Cards lie face up on the table, whichever side was showing — but
		// the turn must not animate. .tbts-card-inner carries a 350ms
		// transition, so simply dropping the class would rotate the card from
		// 180 back to 0 during the flight, through the 90 where
		// backface-visibility hides both faces: the card appears to flicker
		// out mid-air and land wrong-side-up. Suppressing the transition for
		// one frame makes it front-side before it launches. This bites hardest
		// from round two on, when every card has been flipped to read it.
		if ( cardEl.classList.contains( 'tbts-flipped' ) ) {
			var inner = cardEl.querySelector( '.tbts-card-inner' );
			if ( inner ) {
				inner.style.transition = 'none';
				cardEl.classList.remove( 'tbts-flipped' );
				void inner.offsetHeight;   // force the reflow before restoring
				inner.style.transition = '';
			} else {
				cardEl.classList.remove( 'tbts-flipped' );
			}
		}
		cardEl.classList.add( 'tbts-heap-card' );
		cardEl.style.zIndex = String( heapZ );
		cardEl.style.opacity = '';
		// Decelerating, so the card settles rather than snaps.
		cardEl.style.transition = reducedMotion ? 'none' : 'transform 420ms cubic-bezier(.25,.9,.3,1)';
		cardEl.style.transform = heapTransform( idx, slot.x, slot.rot );

		heap.push( { el: cardEl, idx: idx, card: card, x: slot.x, rot: slot.rot } );
	}

	/* A cheap seeded hash: deterministic per card, cheap enough to call on
	   every resize. */
	function seeded( i, salt ) {
		var x = Math.sin( ( i + 1 ) * 12.9898 + salt * 78.233 ) * 43758.5453;
		return x - Math.floor( x );   // 0..1
	}

	/* Where the card lands. A drag answers it: the card settles near where it
	   was let go, with a little seeded variation so the pile stays untidy.
	   The keyboard, the zone buttons and onResize() have no release position,
	   and fall back to the seeded scatter. */
	function heapSlot( idx, releaseX, releaseRot ) {
		var stageW = ( stage && stage.offsetWidth ) || window.innerWidth;
		// A card dropped near an edge still has to stay on the table.
		var limit = Math.max( 0, stageW / 2 - 120 );
		return {
			x: typeof releaseX === 'number'
				? Math.max( -limit, Math.min( limit, releaseX ) )
				: ( seeded( idx, 1 ) - 0.5 ) * 520,
			rot: typeof releaseRot === 'number'
				? releaseRot + ( seeded( idx, 2 ) - 0.5 ) * 16   // ±8deg
				: ( seeded( idx, 2 ) - 0.5 ) * 38
		};
	}

	function heapTransform( idx, x, rot ) {
		var stageH = ( stage && stage.offsetHeight ) || window.innerHeight;
		// Depth into the edge stays seeded whichever way the card got here.
		var depth = seeded( idx, 3 ) * 26;
		// The card rests centred, so translate from there to a position that
		// leaves ~130px showing above the table edge. Derived from the table
		// height rather than hard-coded, so it survives any viewport.
		var restTop = ( stageH - CARD_H ) / 2;
		var y = ( stageH - HEAP_SHOW + depth ) - restTop;
		return 'translate(' + x.toFixed( 1 ) + 'px,' + y.toFixed( 1 ) + 'px) rotate(' + rot.toFixed( 2 ) + 'deg)';
	}

	// Keep the heap pinned to the bottom edge, without animating while the
	// window is being dragged.
	function onResize() {
		// Once the heap has been dealt into the summary grid it is no longer
		// a heap; re-pinning it to the bottom edge would yank the tiles back.
		if ( heap.length && heap[ 0 ].el.classList.contains( 'tbts-tile' ) ) {
			return;
		}
		for ( var i = 0; i < heap.length; i++ ) {
			// Only the vertical is recomputed: each card keeps the horizontal
			// position and angle it was left at, so nothing twitches sideways
			// while the window is being dragged.
			heap[ i ].el.style.transition = 'none';
			heap[ i ].el.style.transform = heapTransform( heap[ i ].idx, heap[ i ].x, heap[ i ].rot );
		}
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

	/* ---- Summary screen ----
	   Scoring is per deck, not per round: the number of cards known is
	   fullDeck.length - unknown.length, so there is no counter to keep in
	   sync. The in-round progress pill stays per round — that one is about
	   position, not achievement. */
	var BEAT_HOLD = 900;   // ms the score holds before anything moves

	function endRound() {
		// On the table the heap is the material for the deal, so the stage
		// survives; everywhere else the round is torn down as before.
		var dealing = isDesktop() && heap.length > 0;

		if ( dealing ) {
			removeEl( zones.up.el );
			removeEl( zones.down.el );
			removeEl( stackEl );
			stackEl = null;
		} else {
			root.innerHTML = '';
			heap = [];
			heapZ = 0;
		}
		zones = null;
		progressEl = null;

		if ( ! unknown.length ) {
			renderAllKnown();
			return;
		}

		var known = fullDeck.length - unknown.length;
		var end = el( 'div', 'tbts-end' + ( dealing ? ' tbts-end-table' : '' ) );
		end.appendChild( buildBeat(
			i18n.wellDone,
			fmt( i18n.knewLine, [ known, fullDeck.length, plural( fullDeck.length ) ] ),
			fmt( i18n.toWorkOn, [ unknown.length, plural( unknown.length ) ] )
		) );

		// The reading matter arrives after the beat. "Go again" is appended
		// now and stays put: the choice to skip the reading exists without a
		// gate in front of it.
		var slot = el( 'div', 'tbts-end-slot' );
		end.appendChild( slot );

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

		setTimeout( function () {
			if ( dealing ) {
				dealHeap( end );
			} else {
				slot.appendChild( buildWordList() );
			}
		}, BEAT_HOLD );
	}

	/* Well done! / You knew 12 of 15 words. / 3 WORDS TO WORK ON */
	function buildBeat( heading, line, sub ) {
		var beat = el( 'div', 'tbts-beat' );

		var h = el( 'h2', 'tbts-beat-head' );
		h.textContent = heading;
		beat.appendChild( h );

		var l = el( 'div', 'tbts-beat-line' );
		l.textContent = line;
		beat.appendChild( l );

		var s = el( 'div', 'tbts-beat-sub' );
		s.textContent = sub;
		beat.appendChild( s );

		return beat;
	}

	// <div>s, not <ul>/<li>: the list is presentational (each item is a
	// self-contained card), and divs sidestep the bullet markers Divi and
	// WP core inject into content lists.
	function buildWordList() {
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
		return list;
	}

	function renderAllKnown() {
		var end = el( 'div', 'tbts-end' );
		end.appendChild( buildBeat(
			i18n.allTitle,
			fmt( i18n.allLine, [ fullDeck.length, plural( fullDeck.length ) ] ),
			i18n.allSub
		) );

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

	/* ---- The deal (desktop) ----
	   The heap gathers itself and lays out as a grid of word tiles: each card
	   animates from where it landed to its slot, FLIP-style. */
	function dealHeap( end ) {
		var n = heap.length;
		var perRow = Math.min( n, 5 );
		var rows = Math.ceil( n / perRow );
		var gapX = 26, gapY = 22;
		// The header tightens when the grid is deep, to buy back the room.
		var headH = rows > 2 ? 150 : 210;
		var footH = 110;   // room for "Go again"

		var tableW = stage.offsetWidth || window.innerWidth;
		var tableH = stage.offsetHeight || window.innerHeight;
		var availW = tableW * 0.92;
		var availH = tableH - headH - footH;

		var scaleW = ( availW - ( perRow - 1 ) * gapX ) / ( perRow * CARD_W );
		var scaleH = ( availH - ( rows - 1 ) * gapY ) / ( rows * CARD_H );
		// The 0.44 floor is deliberate: a large heap runs slightly long
		// rather than shrinking the type into illegibility.
		var scale = Math.max( 0.44, Math.min( 0.72, Math.min( scaleW, scaleH ) ) );
		var blockH = rows * CARD_H * scale + ( rows - 1 ) * gapY;

		// …but running long must never hide a tile behind "Go again". The
		// floor gives way just enough to clear the button, down to a hard
		// 0.40: the type on a tile is scale-compensated, so the tile box
		// tightens without the words getting any smaller.
		if ( headH + blockH > tableH - footH ) {
			var fits = ( tableH - footH - headH - ( rows - 1 ) * gapY ) / ( rows * CARD_H );
			scale = Math.max( 0.4, Math.min( scale, fits ) );
			blockH = rows * CARD_H * scale + ( rows - 1 ) * gapY;
		}

		if ( rows > 2 ) {
			end.classList.add( 'is-compact' );
		}

		var tileW = CARD_W * scale;
		var tileH = CARD_H * scale;
		var top = headH + Math.max( 0, ( availH - blockH ) / 2 );

		heap.forEach( function ( h, i ) {
			var row = Math.floor( i / perRow );
			var inRow = i % perRow;
			// A short final row centres on its own.
			var rowCount = Math.min( perRow, n - row * perRow );
			var rowW = rowCount * tileW + ( rowCount - 1 ) * gapX;
			var x = ( tableW - rowW ) / 2 + inRow * ( tileW + gapX );
			var y = top + row * ( tileH + gapY );

			h.el.appendChild( buildTileFace( h.card, scale ) );
			h.el.classList.add( 'tbts-tile' );
			h.el.style.zIndex = String( 10 + i );

			// The card rests centred on the table, and scales about its own
			// centre, so the translation is centre-to-centre.
			var dx = x + tileW / 2 - tableW / 2;
			var dy = y + tileH / 2 - tableH / 2;
			var to = 'translate(' + dx.toFixed( 1 ) + 'px,' + dy.toFixed( 1 ) + 'px) scale(' + scale.toFixed( 4 ) + ')';

			if ( reducedMotion ) {
				h.el.style.transition = 'none';
				h.el.style.transform = to;
				return;
			}
			h.el.style.transition = 'transform 620ms cubic-bezier(.22,.9,.25,1) ' + ( i * 45 ) + 'ms';
			h.el.style.transform = to;
		} );
	}

	/* A fourth face, shown only in the summary. Not the card back: a screen
	   titled "words to work on" that omits the words is the wrong screen, and
	   the card back has never carried the term.

	   Everything here is sized in JS rather than CSS because the tile sits
	   inside a scale() — dividing by the scale cancels the transform, so the
	   learner reads the size intended rather than a thinner, greyer one. */
	function buildTileFace( card, scale ) {
		var big = scale > 0.55;
		function px( v ) {
			return Math.round( v / scale ) + 'px';
		}

		var face = el( 'div', 'tbts-face tbts-face-tile' );
		face.style.borderRadius = px( 14 );
		face.style.padding = px( 18 ) + ' ' + px( 14 );
		face.style.boxShadow = '0 ' + px( 10 ) + ' ' + px( 26 ) + ' rgba(4,30,74,.16)';

		var term = el( 'div', 'tbts-tile-term' );
		term.textContent = card.term;
		term.style.fontSize = px( big ? 22 : 19 );
		face.appendChild( term );

		if ( card.ipa ) {
			var ipa = el( 'div', 'tbts-tile-ipa' );
			ipa.textContent = card.ipa;
			ipa.style.fontSize = px( big ? 12 : 11 );
			ipa.style.marginTop = px( 4 );
			face.appendChild( ipa );
		}

		var rule = el( 'div', 'tbts-tile-rule' );
		rule.style.height = px( 1 );
		rule.style.margin = px( 12 ) + ' 0';
		face.appendChild( rule );

		if ( card.translation ) {
			var tr = el( 'div', 'tbts-tile-tr' );
			tr.textContent = card.translation;
			tr.style.fontSize = px( big ? 19 : 16 );
			face.appendChild( tr );
		}

		if ( card.example ) {
			var ex = el( 'div', 'tbts-tile-ex' );
			ex.textContent = card.example;
			ex.style.fontSize = px( big ? 14 : 12.5 );
			ex.style.marginTop = px( 8 );
			face.appendChild( ex );
		}

		return face;
	}

	/* ---- Helpers ---- */
	function plural( n ) {
		return n === 1 ? i18n.wordOne : i18n.wordMany;
	}
	// Positional placeholders, so a translation can reorder the counts and
	// the inflected noun. Matches the %1$d / %2$s the PHP side ships.
	function fmt( tpl, vals ) {
		return String( tpl || '' ).replace( /%(\d+)\$[ds]/g, function ( m, n ) {
			return vals[ n - 1 ];
		} );
	}
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
