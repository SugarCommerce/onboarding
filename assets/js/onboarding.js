/**
 * Onboarding wizard.
 *
 * Everything here is an enhancement: every step submits, skips, goes back
 * and finishes with no script at all. What this adds is where the focus
 * lands, the step's answers travelling with a button's action, a guard
 * against a double submit, the import step's wait, and the confetti.
 *
 * @package SugarCommerce\Onboarding
 */

( function () {
	'use strict';

	var root = document.querySelector( '.onboarding' );

	if ( ! root ) {
		return;
	}

	var form = root.querySelector( '.onboarding__form' );

	/* ---------------------------------------------------------------------
	 * Focus
	 *
	 * Each step is a new page, and a new page starts a screen reader at the
	 * top of the admin. Focus goes to the step's heading instead, so the
	 * first thing read is the question — or to the list of errors, when the
	 * step came back refused, so the first thing read is what to fix.
	 * ------------------------------------------------------------------ */

	var errors = root.querySelector( '.onboarding__errors' );
	var heading = root.querySelector( '.onboarding__heading' );

	if ( errors ) {
		errors.focus();
	} else if ( heading ) {
		heading.focus( { preventScroll: true } );
	}

	// A link in the error list moves focus to its field, not only the view:
	// a fragment link scrolls, and leaves the focus where it was.
	if ( errors ) {
		errors.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href^="#"]' );
			var target = link && document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );

			if ( target ) {
				event.preventDefault();
				target.focus();
				target.scrollIntoView( { block: 'center' } );
			}
		} );
	}

	if ( form ) {
		enhance( form );
	}

	celebrate();

	/**
	 * Wire up a step's form.
	 *
	 * @param {HTMLFormElement} form The form.
	 */
	function enhance( form ) {

		/* -----------------------------------------------------------------
		 * The step's answers, sent with a button's action.
		 *
		 * The kit sends a button only the inputs inside its own field. A
		 * "Test connection" button needs the key typed two fields above it,
		 * not yet saved, so every answer on the step goes too, under its
		 * field key, wherever the handler did not already get it.
		 * -------------------------------------------------------------- */

		var prefix = form.dataset.fields || '';

		form.addEventListener( 'fieldkit:action-payload', function ( event ) {
			var payload = event.detail && event.detail.payload;

			if ( ! payload || ! prefix ) {
				return;
			}

			new FormData( form ).forEach( function ( value, name ) {
				var match = name.match( /^[^[]+\[([^\]]+)\]$/ );

				if ( 0 !== name.indexOf( prefix + '[' ) || ! match || 'string' !== typeof value ) {
					return;
				}

				if ( ! Object.prototype.hasOwnProperty.call( payload, match[ 1 ] ) ) {
					payload[ match[ 1 ] ] = value;
				}
			} );
		} );

		/* -----------------------------------------------------------------
		 * One submission at a time.
		 *
		 * The buttons are disabled a tick after the submit, not during it:
		 * a disabled submitter is left out of the form data, and its value
		 * is what says Continue from Skip.
		 * -------------------------------------------------------------- */

		form.addEventListener( 'submit', function ( event ) {
			if ( form.getAttribute( 'aria-busy' ) === 'true' ) {
				event.preventDefault();

				return;
			}

			form.setAttribute( 'aria-busy', 'true' );

			window.setTimeout( function () {
				form.querySelectorAll( '.onboarding__actions button' ).forEach( function ( button ) {
					button.disabled = true;
				} );
			}, 0 );
		} );

		// Coming back with the browser's Back button can restore the page
		// from its cache, buttons still disabled.
		window.addEventListener( 'pageshow', function () {
			form.removeAttribute( 'aria-busy' );
			form.querySelectorAll( '.onboarding__actions button' ).forEach( function ( button ) {
				button.disabled = false;
			} );
		} );

		/* -----------------------------------------------------------------
		 * Import step
		 *
		 * Continue waits until the import has run. The events are
		 * sugarcommerce/jobs' Sync's, on the document.
		 * -------------------------------------------------------------- */

		if ( '1' !== form.dataset.sync ) {
			return;
		}

		var next = form.querySelector( '.onboarding__next' );
		var trigger = form.querySelector( '.sugar-sync-trigger' );

		if ( next ) {
			next.disabled = true;
		}

		document.addEventListener( 'sugar-sync:complete', function () {
			if ( next ) {
				next.disabled = false;
			}

			if ( trigger ) {
				trigger.hidden = true;
			}
		} );

		// A run that failed still lets you past: a wizard that cannot be
		// finished because an import failed is a plugin that cannot be set
		// up at all.
		document.addEventListener( 'sugar-sync:error', function () {
			if ( next ) {
				next.disabled = false;
			}
		} );
	}

	/**
	 * Confetti, on a finish screen that asked for it.
	 *
	 * Only for someone who has not asked the browser for less movement.
	 */
	function celebrate() {
		var finish = root.querySelector( '.onboarding__finish' );

		if ( ! finish || '1' !== finish.dataset.celebrate || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}

		var canvas = document.createElement( 'canvas' );
		var styles = window.getComputedStyle( root );
		var accent = styles.getPropertyValue( '--onboarding-accent' ).trim();

		canvas.className = 'onboarding__confetti';
		canvas.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( canvas );

		var context = canvas.getContext( '2d' );
		var pieces = [];
		var colours = [ accent || '#2271b1', '#00a32a', '#dba617', '#d63638', '#8c8f94' ];
		var started = null;

		function size() {
			canvas.width = window.innerWidth;
			canvas.height = window.innerHeight;
		}

		size();
		window.addEventListener( 'resize', size );

		for ( var i = 0; i < 90; i++ ) {
			pieces.push( {
				x: Math.random() * canvas.width,
				y: -20 - Math.random() * canvas.height * 0.5,
				w: 6 + Math.random() * 6,
				h: 8 + Math.random() * 8,
				spin: Math.random() * Math.PI,
				drift: -1 + Math.random() * 2,
				fall: 2 + Math.random() * 3,
				colour: colours[ Math.floor( Math.random() * colours.length ) ]
			} );
		}

		function frame( now ) {
			if ( null === started ) {
				started = now;
			}

			var elapsed = now - started;

			context.clearRect( 0, 0, canvas.width, canvas.height );

			pieces.forEach( function ( piece ) {
				piece.y += piece.fall;
				piece.x += piece.drift;
				piece.spin += 0.05;

				context.save();
				context.translate( piece.x, piece.y );
				context.rotate( piece.spin );
				context.fillStyle = piece.colour;
				context.globalAlpha = Math.max( 0, 1 - elapsed / 4000 );
				context.fillRect( -piece.w / 2, -piece.h / 2, piece.w, piece.h );
				context.restore();
			} );

			if ( elapsed < 4000 ) {
				window.requestAnimationFrame( frame );
			} else {
				canvas.remove();
				window.removeEventListener( 'resize', size );
			}
		}

		window.requestAnimationFrame( frame );
	}
}() );
