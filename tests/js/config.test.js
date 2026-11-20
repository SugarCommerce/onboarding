/**
 * What PHP printed on the form and the card, read in one place.
 */

import { describe, expect, test } from 'vitest';

import { cardSettings, formSettings } from '../../assets/js/lib/config';

const element = ( html ) => {
	document.body.innerHTML = html;

	return document.body.firstElementChild;
};

describe( 'formSettings()', () => {
	test( 'reads the input name and whether Continue waits', () => {
		expect(
			formSettings(
				element(
					'<form data-fields="onboarding_myshop" data-sync="1"></form>'
				)
			)
		).toEqual( { prefix: 'onboarding_myshop', sync: true } );
	} );

	test.each( [ '0', '', 'true', 'yes' ] )(
		'data-sync="%s" does not wait',
		( sync ) => {
			expect(
				formSettings( element( `<form data-sync="${ sync }"></form>` ) )
					.sync
			).toBe( false );
		}
	);

	test( 'a form printed without them has no prefix and does not wait', () => {
		expect( formSettings( element( '<form></form>' ) ) ).toEqual( {
			prefix: '',
			sync: false,
		} );
	} );
} );

describe( 'cardSettings()', () => {
	test( 'reads the route, the nonce, where it is, its layout and its words', () => {
		expect(
			cardSettings(
				element(
					'<section data-endpoint="https://example.test/wp-json/x/v1/checklists/guide" data-nonce="n" data-context="widget" data-layout="compact" data-failed="Nope."></section>'
				)
			)
		).toEqual( {
			endpoint: 'https://example.test/wp-json/x/v1/checklists/guide',
			nonce: 'n',
			context: 'widget',
			layout: 'compact',
			failed: 'Nope.',
		} );
	} );

	test( 'a card printed without them is a full card, with nothing to say', () => {
		expect( cardSettings( element( '<section></section>' ) ) ).toEqual( {
			endpoint: '',
			nonce: '',
			context: 'card',
			layout: 'full',
			failed: '',
		} );
	} );
} );
