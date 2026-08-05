/**
 * QR adapter for the TBT Notes panel.
 *
 * Notes ships no QR library and must not gain one, so it looks for
 * window.TBTNotesQR.render() and falls back to a plain link when nothing
 * defines it. Swipe already carries the library for its own catalogue, so it
 * provides the implementation — same library, same options, so a code rendered
 * in the notes panel is identical to the one on the Swipe page.
 */
( function () {
	'use strict';

	window.TBTNotesQR = window.TBTNotesQR || {};

	/**
	 * Draw a QR code for a URL into an element.
	 *
	 * @param {HTMLElement} el  Target container, emptied first.
	 * @param {string}      url URL to encode.
	 */
	window.TBTNotesQR.render = function ( el, url ) {
		if ( ! el || ! url || typeof window.QRCode === 'undefined' ) {
			return;
		}
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		new window.QRCode( el, {
			text: url,
			width: 320,
			height: 320,
			correctLevel: window.QRCode.CorrectLevel.M
		} );
	};
}() );
