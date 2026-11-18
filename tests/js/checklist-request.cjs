/**
 * Runs assets/js/checklist.js against a pretend page and reports, as JSON,
 * how its buttons reached the server. Called by ChecklistScriptTest.
 *
 * Usage: node checklist-request.cjs <path to checklist.js> <ok|denied>
 */

'use strict';

const fs = require( 'fs' );
const vm = require( 'vm' );

const [ , , file, scenario ] = process.argv;
const report = { apiFetch: [], fetch: [], replaced: '', announced: '' };

const region = { textContent: '' };
const card = {
	dataset: { endpoint: 'https://example.test/wp-json/guide/v1/checklist', nonce: 'stale', failed: 'That did not work.' },
	attrs: {},
	getAttribute( name ) {
		return this.attrs[ name ] ?? null;
	},
	setAttribute( name, value ) {
		this.attrs[ name ] = value;
	},
	removeAttribute( name ) {
		delete this.attrs[ name ];
	},
	querySelector( selector ) {
		return '[aria-live]' === selector ? region : null;
	},
	replaceWith( fresh ) {
		report.replaced = fresh.html;
	},
};
const button = {
	dataset: {},
	hasAttribute: ( name ) => 'data-checklist-dismiss' === name,
	closest: ( selector ) => ( '.onboarding-checklist' === selector ? card : null ),
};

let onClick = null;

const window = {
	requestAnimationFrame: ( fn ) => fn(),
	fetch( url, init ) {
		report.fetch.push( { url, init } );

		return Promise.reject( new TypeError( 'Failed to fetch' ) );
	},
	wp: {
		apiFetch( options ) {
			report.apiFetch.push( options );

			if ( 'denied' === scenario ) {
				return Promise.reject( { code: 'rest_forbidden', message: 'Not allowed.', data: { status: 403 } } );
			}

			return Promise.resolve( { html: '<div class="fresh"></div>', message: 'Hidden.' } );
		},
	},
};

const document = {
	addEventListener: ( type, fn ) => {
		onClick = fn;
	},
	createElement: () => {
		const holder = {
			set innerHTML( html ) {
				holder.firstElementChild = { html, querySelector: () => null, ...{ querySelectorAll: () => [] } };
				holder.firstElementChild.querySelector = ( selector ) => ( '[aria-live]' === selector ? region : null );
			},
		};

		return holder;
	},
};

vm.runInNewContext( fs.readFileSync( file, 'utf8' ), { window, document, Promise, Error, JSON, encodeURIComponent } );

onClick( { target: { closest: () => button }, preventDefault() {} } );

setTimeout( () => {
	report.announced = region.textContent;
	report.apiFetch = report.apiFetch.map( ( options ) => ( { url: options.url, method: options.method, body: options.body } ) );
	report.fetch = report.fetch.map( ( call ) => call.url );
	process.stdout.write( JSON.stringify( report ) );
}, 20 );
