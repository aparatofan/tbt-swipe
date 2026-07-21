/* TBT Swipe — frontend deck. Vanilla JS, Pointer Events, no dependencies.
   Stateless: everything lives in memory; refreshing restarts the deck. */
( function () {
	'use strict';

	var cfg = window.tbtsDeck || {};
	var i18n = cfg.i18n || {};
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var THRESHOLD_RATIO = 0.30;   // fraction of card width to commit a swipe
	var FLICK_VELOCITY = 0.6;     // px/ms — a fast flick commits below threshold
	var MAX_ROTATE = 15;          // deg
	var EDGE_LABEL_CARDS = 3;     // show edge labels for the first N cards

	var root, stage, progressEl, controls;
	var fullDeck = [];   // original loaded cards
	var queue = [];      // cards still to review this round
	var unknown = [];    // cards swiped left this round
	var seenCount = 0;   // cards shown this round (for progress + edge labels)
	var roundTotal = 0;
	var current = null;  // active card DOM state

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		root = document.getElementById( 'tbts-deck-root' );
		if ( ! root ) {
			return;
		}

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
			var s = params.get( 's' );
			if ( s && /^[A-Za-z0-9]{12}$/.test( s ) ) {
				return s;
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

		root.innerHTML = '';

		progressEl = el( 'div', 'tbts-progress' );
		stage = el( 'div', 'tbts-stage' );
		controls = buildControls();

		root.appendChild( progressEl );
		root.appendChild( stage );
		root.appendChild( controls );

		nextCard();
	}

	function buildControls() {
		var wrap = el( 'div', 'tbts-controls' );

		var notBtn = el( 'button', 'tbts-btn tbts-btn-not' );
		notBtn.type = 'button';
		notBtn.textContent = i18n.notYet;
		notBtn.addEventListener( 'click', function () { commit( 'left' ); } );

		var knowBtn = el( 'button', 'tbts-btn tbts-btn-know' );
		knowBtn.type = 'button';
		knowBtn.textContent = i18n.knowIt;
		knowBtn.addEventListener( 'click', function () { commit( 'right' ); } );

		wrap.appendChild( notBtn );
		wrap.appendChild( knowBtn );
		return wrap;
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
		progressEl.textContent = seenCount + ' / ' + roundTotal;
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

		var tintR = el( 'div', 'tbts-tint tbts-tint-right' );
		var tintL = el( 'div', 'tbts-tint tbts-tint-left' );
		inner.appendChild( tintR );
		inner.appendChild( tintL );

		cardEl.appendChild( inner );

		// Edge labels for the first few cards.
		if ( seenCount <= EDGE_LABEL_CARDS ) {
			var know = el( 'div', 'tbts-edge-label tbts-edge-know' );
			know.textContent = i18n.knowIt;
			var not = el( 'div', 'tbts-edge-label tbts-edge-not' );
			not.textContent = i18n.notYet;
			cardEl.appendChild( know );
			cardEl.appendChild( not );
			current = { card: card, el: cardEl, inner: inner, tintR: tintR, tintL: tintL, know: know, not: not };
		} else {
			current = { card: card, el: cardEl, inner: inner, tintR: tintR, tintL: tintL, know: null, not: null };
		}

		current.flipped = false;
		current.locked = false;

		stage.appendChild( cardEl );
		attachPointer( cardEl );
	}

	/* ---- Pointer / drag handling ---- */
	function attachPointer( cardEl ) {
		var startX = 0, startY = 0, startT = 0;
		var lastX = 0, lastT = 0;
		var dragging = false;
		var moved = false;
		var width = cardEl.offsetWidth || 320;

		cardEl.addEventListener( 'pointerdown', function ( e ) {
			if ( current.locked ) {
				return;
			}
			dragging = true;
			moved = false;
			width = cardEl.offsetWidth || width;
			startX = lastX = e.clientX;
			startY = e.clientY;
			startT = lastT = now();
			cardEl.classList.add( 'tbts-dragging' );
			try { cardEl.setPointerCapture( e.pointerId ); } catch ( err ) {}
		} );

		cardEl.addEventListener( 'pointermove', function ( e ) {
			if ( ! dragging || current.locked ) {
				return;
			}
			var dx = e.clientX - startX;
			var dy = e.clientY - startY;

			// Ignore predominantly-vertical gestures (let the page scroll).
			if ( ! moved && Math.abs( dy ) > Math.abs( dx ) && Math.abs( dy ) > 8 ) {
				return;
			}
			if ( Math.abs( dx ) > 4 ) {
				moved = true;
			}
			lastX = e.clientX;
			lastT = now();

			applyDrag( dx, width );
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

			var dx = e.clientX - startX;
			var dt = Math.max( now() - startT, 1 );
			var velocity = ( e.clientX - startX ) / dt; // px/ms overall
			var pastThreshold = Math.abs( dx ) > width * THRESHOLD_RATIO;
			var flick = Math.abs( velocity ) > FLICK_VELOCITY && Math.abs( dx ) > 30;

			if ( ! moved ) {
				// A tap: flip.
				flip();
				return;
			}

			if ( pastThreshold || flick ) {
				commit( dx > 0 ? 'right' : 'left' );
			} else {
				springBack();
			}
		}

		cardEl.addEventListener( 'pointerup', endDrag );
		cardEl.addEventListener( 'pointercancel', endDrag );
	}

	function applyDrag( dx, width ) {
		var rotate = Math.max( -MAX_ROTATE, Math.min( MAX_ROTATE, ( dx / width ) * MAX_ROTATE * 2 ) );
		current.inner.style.transition = 'none';
		current.inner.style.transform = 'translateX(' + dx + 'px) rotate(' + rotate + 'deg)';

		var ratio = Math.min( 1, Math.abs( dx ) / ( width * THRESHOLD_RATIO ) );
		if ( dx > 0 ) {
			current.tintR.style.opacity = ratio * 0.9;
			current.tintL.style.opacity = 0;
			if ( current.know ) { current.know.style.opacity = ratio; }
			if ( current.not ) { current.not.style.opacity = 0; }
		} else {
			current.tintL.style.opacity = ratio * 0.9;
			current.tintR.style.opacity = 0;
			if ( current.not ) { current.not.style.opacity = ratio; }
			if ( current.know ) { current.know.style.opacity = 0; }
		}
	}

	function springBack() {
		current.inner.style.transition = 'transform 250ms ease';
		current.inner.style.transform = '';
		current.tintR.style.opacity = 0;
		current.tintL.style.opacity = 0;
		if ( current.know ) { current.know.style.opacity = 0; }
		if ( current.not ) { current.not.style.opacity = 0; }
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
		var card = current.card;
		var cardEl = current.el;
		var inner = current.inner;

		if ( dir === 'right' ) {
			// Known → burn immediately.
			burn( cardEl, function () {
				afterCommit();
			} );
		} else {
			// Unknown → slide off left, add to pile.
			unknown.push( card );
			var w = cardEl.offsetWidth || 320;
			inner.style.transition = 'transform 300ms ease, opacity 300ms ease';
			inner.style.transform = 'translateX(' + ( -w - 80 ) + 'px) rotate(-' + MAX_ROTATE + 'deg)';
			inner.style.opacity = '0';
			whenDone( inner, 320, function () {
				removeEl( cardEl );
				afterCommit();
			} );
		}
	}

	function afterCommit() {
		current = null;
		nextCard();
	}

	/* ---- Burn animation ---- */
	function burn( cardEl, done ) {
		var w = cardEl.offsetWidth;
		var h = cardEl.offsetHeight;

		if ( reducedMotion || ! w || ! h ) {
			// Simple fade fallback.
			cardEl.style.transition = 'opacity 300ms ease';
			cardEl.style.opacity = '0';
			whenDone( cardEl, 320, function () {
				removeEl( cardEl );
				done();
			} );
			return;
		}

		// Snapshot the visible face as a solid-coloured stand-in. We can't
		// rasterise the DOM without a library, so we clone the card face into
		// fragments clipped with different polygons and fling them outward.
		var layer = el( 'div', 'tbts-burn-layer' );
		var faceClass = cardEl.classList.contains( 'tbts-flipped' ) ? 'tbts-face-back' : 'tbts-face-front';
		var sourceFace = cardEl.querySelector( '.' + faceClass );

		var fragCount = 14;
		var cols = 4, rows = 4;
		for ( var i = 0; i < fragCount; i++ ) {
			var frag = sourceFace.cloneNode( true );
			frag.classList.add( 'tbts-frag' );
			frag.style.backfaceVisibility = 'hidden';
			frag.style.transform = 'none';
			// Random-ish polygon slice using a grid cell region.
			var cx = ( i % cols ) / cols;
			var cy = Math.floor( i / cols ) / rows;
			var x0 = Math.round( cx * 100 );
			var y0 = Math.round( cy * 100 );
			var x1 = Math.min( 100, x0 + Math.round( 100 / cols ) + rand( 4, 12 ) );
			var y1 = Math.min( 100, y0 + Math.round( 100 / rows ) + rand( 4, 12 ) );
			frag.style.clipPath = 'polygon(' + x0 + '% ' + y0 + '%, ' + x1 + '% ' + y0 + '%, ' + x1 + '% ' + y1 + '%, ' + x0 + '% ' + y1 + '%)';
			frag.style.webkitClipPath = frag.style.clipPath;

			var tx = ( Math.random() - 0.5 ) * w * 1.6;
			var ty = ( Math.random() - 0.7 ) * h * 1.4;
			var rot = ( Math.random() - 0.5 ) * 120;
			var sc = 0.4 + Math.random() * 0.5;
			var dur = 480 + Math.random() * 180;

			frag.style.transition = 'transform ' + dur + 'ms cubic-bezier(0.22,0.61,0.36,1), opacity ' + dur + 'ms ease-out, filter ' + dur + 'ms ease-out';
			frag.style.opacity = '1';
			layer.appendChild( frag );

			( function ( f, tx, ty, rot, sc ) {
				requestAnimationFrame( function () {
					requestAnimationFrame( function () {
						f.style.transform = 'translate(' + tx + 'px,' + ty + 'px) rotate(' + rot + 'deg) scale(' + sc + ')';
						f.style.opacity = '0';
						f.style.filter = 'sepia(1) saturate(6) hue-rotate(-20deg) brightness(1.1)';
					} );
				} );
			} )( frag, tx, ty, rot, sc );
		}

		// Hide the real faces; the fragments carry the show. The burn layer
		// re-asserts visibility so it isn't hidden along with the card.
		cardEl.style.visibility = 'hidden';
		layer.style.visibility = 'visible';
		cardEl.appendChild( layer );

		whenDone( layer, 700, function () {
			removeEl( cardEl );
			done();
		} );
	}

	/* ---- End screen ---- */
	function endRound() {
		removeChildren( stage );
		if ( controls ) { controls.style.display = 'none'; }
		progressEl.textContent = '';

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

		var actions = el( 'div', 'tbts-end-actions' );
		var again = el( 'button', 'tbts-btn tbts-btn-know' );
		again.type = 'button';
		again.textContent = i18n.goAgain;
		again.addEventListener( 'click', function () {
			startRound( shuffle( unknown.slice() ) );
		} );
		actions.appendChild( again );
		end.appendChild( actions );

		stage.appendChild( end );
	}

	function renderAllKnown() {
		var end = el( 'div', 'tbts-end' );
		var msg = el( 'div', 'tbts-end-success' );
		msg.textContent = i18n.allKnown;
		end.appendChild( msg );

		var actions = el( 'div', 'tbts-end-actions' );
		var restart = el( 'button', 'tbts-btn tbts-btn-know' );
		restart.type = 'button';
		restart.textContent = i18n.restart;
		restart.addEventListener( 'click', function () {
			startRound( fullDeck.slice() );
		} );
		actions.appendChild( restart );
		end.appendChild( actions );

		stage.appendChild( end );
	}

	/* ---- Helpers ---- */
	function el( tag, cls ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		return e;
	}
	function removeEl( node ) {
		if ( node && node.parentNode ) { node.parentNode.removeChild( node ); }
	}
	function removeChildren( node ) {
		while ( node && node.firstChild ) { node.removeChild( node.firstChild ); }
	}
	function now() {
		return window.performance && performance.now ? performance.now() : Date.now();
	}
	function rand( min, max ) {
		return Math.floor( min + Math.random() * ( max - min ) );
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
