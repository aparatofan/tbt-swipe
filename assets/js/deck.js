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

	var root, stage, progressEl;
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
		renderCard( card );
	}

	function updateProgress() {
		if ( progressEl ) {
			progressEl.textContent = seenCount + ' / ' + roundTotal;
		}
	}

	/* ---- Card rendering ---- */
	function renderCard( card ) {
		var cardEl = el( 'div', 'tbts-card' );
		var inner = el( 'div', 'tbts-card-inner' );

		var front = el( 'div', 'tbts-face tbts-face-front' );
		addLogo( front );
		var term = el( 'div', 'tbts-term' );
		term.textContent = card.term;
		front.appendChild( term );
		var hint = el( 'div', 'tbts-flip-hint' );
		hint.textContent = i18n.tapToFlip;
		front.appendChild( hint );

		var back = el( 'div', 'tbts-face tbts-face-back' );
		addLogo( back );
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

	function addLogo( face ) {
		if ( ! cfg.logo ) {
			return;
		}
		var img = document.createElement( 'img' );
		img.className = 'tbts-logo';
		img.src = cfg.logo;
		img.alt = '';
		img.setAttribute( 'aria-hidden', 'true' );
		face.appendChild( img );
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
			zoneFeedback( zones.up, zones.down, progress );
		} else if ( dy > 0 ) {
			zoneFeedback( zones.down, zones.up, progress );
		} else {
			resetZones();
		}
	}

	// Brighten the zone the card is heading toward, dim the opposite one.
	function zoneFeedback( active, dim, progress ) {
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
			// Known → disintegrate.
			if ( reducedMotion ) {
				fadeOut( cardEl, 150, afterCommit );
			} else {
				disintegrate( cardEl, afterCommit );
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

	/* ---- Up: disintegration ---- */
	function disintegrate( cardEl, done ) {
		var faceClass = cardEl.classList.contains( 'tbts-flipped' ) ? 'tbts-face-back' : 'tbts-face-front';
		var sourceFace = cardEl.querySelector( '.' + faceClass );
		var w = cardEl.offsetWidth;
		var h = cardEl.offsetHeight;

		if ( ! sourceFace || ! w || ! h ) {
			fadeOut( cardEl, 150, done );
			return;
		}

		var cols = 6, rows = 5; // 30 fragments — small enough to read as dust

		// Jittered vertex lattice: interior vertices are nudged so cell
		// boundaries are irregular, but neighbours share vertices so the
		// pieces still tile seamlessly (no visible grid seams). Edge
		// vertices stay pinned to 0/100 so the card outline stays crisp.
		var vx = [], vy = [];
		for ( var r = 0; r <= rows; r++ ) {
			vx[ r ] = []; vy[ r ] = [];
			for ( var c = 0; c <= cols; c++ ) {
				var bx = ( c / cols ) * 100;
				var by = ( r / rows ) * 100;
				var jx = ( c === 0 || c === cols ) ? 0 : ( Math.random() - 0.5 ) * ( 100 / cols ) * 0.6;
				var jy = ( r === 0 || r === rows ) ? 0 : ( Math.random() - 0.5 ) * ( 100 / rows ) * 0.6;
				vx[ r ][ c ] = bx + jx;
				vy[ r ][ c ] = by + jy;
			}
		}

		var layer = el( 'div', 'tbts-frag-layer' );
		var pending = 0;
		var finished = false;
		function tryFinish() {
			if ( finished || pending > 0 ) {
				return;
			}
			finished = true;
			removeEl( layer );
		}

		for ( var rr = 0; rr < rows; rr++ ) {
			for ( var cc = 0; cc < cols; cc++ ) {
				var frag = sourceFace.cloneNode( true );
				frag.classList.add( 'tbts-frag' );
				frag.style.transform = 'none';

				var poly = 'polygon(' +
					pt( vx[ rr ][ cc ], vy[ rr ][ cc ] ) + ',' +
					pt( vx[ rr ][ cc + 1 ], vy[ rr ][ cc + 1 ] ) + ',' +
					pt( vx[ rr + 1 ][ cc + 1 ], vy[ rr + 1 ][ cc + 1 ] ) + ',' +
					pt( vx[ rr + 1 ][ cc ], vy[ rr + 1 ][ cc ] ) + ')';
				frag.style.clipPath = poly;
				frag.style.webkitClipPath = poly;

				// Cell centre as a fraction of the card (0..1).
				var ccx = ( vx[ rr ][ cc ] + vx[ rr ][ cc + 1 ] + vx[ rr + 1 ][ cc + 1 ] + vx[ rr + 1 ][ cc ] ) / 400;
				var ccy = ( vy[ rr ][ cc ] + vy[ rr ][ cc + 1 ] + vy[ rr + 1 ][ cc + 1 ] + vy[ rr + 1 ][ cc ] ) / 400;
				var dirx = ccx - 0.5;
				var diry = ccy - 0.5;
				var dist = Math.sqrt( dirx * dirx + diry * diry ); // 0..~0.707

				// Stagger by distance from centre so the break spreads outward.
				var delay = Math.round( ( dist / 0.7071 ) * 120 );

				// Outward, biased upward (following the gesture).
				var tx = dirx * w * 0.9 + ( Math.random() - 0.5 ) * w * 0.25;
				var ty = diry * h * 0.9 - h * 0.6 + ( Math.random() - 0.5 ) * h * 0.25;
				var rot = ( Math.random() - 0.5 ) * 80;
				var dur = 700 + Math.random() * 150;

				frag.style.willChange = 'transform, opacity';
				frag.style.transition =
					'transform ' + dur + 'ms cubic-bezier(0.25,0.46,0.45,0.94) ' + delay + 'ms, ' +
					'opacity ' + dur + 'ms cubic-bezier(0.25,0.46,0.45,0.94) ' + delay + 'ms';

				layer.appendChild( frag );
				pending++;

				frag.addEventListener( 'transitionend', ( function ( f ) {
					return function ( ev ) {
						if ( ev.propertyName !== 'transform' ) {
							return;
						}
						f.style.willChange = '';
						removeEl( f );
						pending--;
						tryFinish();
					};
				} )( frag ) );

				( function ( f, x, y, rotation ) {
					requestAnimationFrame( function () {
						requestAnimationFrame( function () {
							f.style.transform = 'translate(' + x + 'px,' + y + 'px) rotate(' + rotation + 'deg) scale(0.8)';
							f.style.opacity = '0';
						} );
					} );
				} )( frag, tx, ty, rot );
			}
		}

		// The clones carry the show; drop the real card now.
		removeEl( cardEl );
		stage.appendChild( layer );

		// Safety net in case a transitionend is missed.
		setTimeout( function () { pending = 0; tryFinish(); }, 1100 );

		// The next card must be interactive well before the fragments finish.
		setTimeout( done, 250 );
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

		var list = el( 'ul', 'tbts-end-list' );
		unknown.forEach( function ( card ) {
			var li = el( 'li', 'tbts-end-item' );

			var term = el( 'span', 'tbts-end-term' );
			term.textContent = card.term;
			li.appendChild( term );
			if ( card.ipa ) {
				var ipa = el( 'span', 'tbts-end-ipa' );
				ipa.textContent = card.ipa;
				li.appendChild( ipa );
			}
			if ( card.translation ) {
				var tr = el( 'div', 'tbts-end-tr' );
				tr.textContent = card.translation;
				li.appendChild( tr );
			}
			if ( card.example ) {
				var ex = el( 'div', 'tbts-end-ex' );
				ex.textContent = card.example;
				li.appendChild( ex );
			}
			list.appendChild( li );
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
	function el( tag, cls ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		return e;
	}
	function pt( x, y ) {
		return x.toFixed( 2 ) + '% ' + y.toFixed( 2 ) + '%';
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
