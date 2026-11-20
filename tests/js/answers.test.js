/**
 * A button's action carries every answer on the step, under its field key,
 * wherever the handler did not already get it.
 */

import { describe, expect, test } from 'vitest';

import {
	addAnswers,
	fieldKey,
	sendAnswers,
} from '../../assets/js/onboarding/answers';
import { loadStep } from './support/wizard';

/**
 * Ask the form for a payload as fields' action button does: a bubbling
 * CustomEvent from the button, whose payload a listener writes to.
 *
 * @param {Object} payload What the button already has.
 * @return {Object} The payload, after the listeners.
 */
function ask( payload ) {
	document.querySelector( '.field-kit-action-button' ).dispatchEvent(
		new window.CustomEvent( 'fieldkit:action-payload', {
			bubbles: true,
			detail: { action: 'test', payload },
		} )
	);

	return payload;
}

describe( 'fieldKey()', () => {
	test.each( [
		[ 'onboarding_myshop[store_name]', 'store_name' ],
		[ 'onboarding_myshop[a-b.c]', 'a-b.c' ],
		[ 'onboarding_myshop[tags][]', '' ],
		[ 'onboarding_myshop[address][city]', '' ],
		[ 'onboarding_myshop[]', '' ],
		[ 'onboarding_myshop', '' ],
		[ 'other[store_name]', '' ],
		[ 'onboarding_myshopx[store_name]', '' ],
		[ 'xonboarding_myshop[store_name]', '' ],
	] )( '%s is %j', ( name, key ) => {
		expect( fieldKey( name, 'onboarding_myshop' ) ).toBe( key );
	} );
} );

describe( 'the payload', () => {
	test( "gets every one of the step's answers, under its key", () => {
		const form = loadStep().querySelector( 'form' );

		sendAnswers( form, 'onboarding_myshop' );

		expect( ask( {} ) ).toEqual( {
			store_name: 'My shop',
			contact: '',
			country: 'GB',
			test_mode: '1',
			api_key: 'demo_typed',
		} );
	} );

	test( 'keeps what the handler already has, even when empty', () => {
		const form = loadStep().querySelector( 'form' );

		sendAnswers( form, 'onboarding_myshop' );

		expect( ask( { api_key: '', value: 'x' } ) ).toEqual( {
			api_key: '',
			value: 'x',
			store_name: 'My shop',
			contact: '',
			country: 'GB',
			test_mode: '1',
		} );
	} );

	test( 'reads the answers as they are now, typed and not saved', () => {
		const form = loadStep().querySelector( 'form' );

		sendAnswers( form, 'onboarding_myshop' );
		form.querySelector( '[name="onboarding_myshop[api_key]"]' ).value =
			'demo_new';

		expect( ask( {} ).api_key ).toBe( 'demo_new' );
	} );

	test( 'the first of two answers under one name wins', () => {
		const form = loadStep().querySelector( 'form' );

		form.insertAdjacentHTML(
			'beforeend',
			'<input type="hidden" name="onboarding_myshop[store_name]" value="Second">'
		);
		sendAnswers( form, 'onboarding_myshop' );

		expect( ask( {} ).store_name ).toBe( 'My shop' );
	} );

	test( 'leaves out an unticked box, as the form would', () => {
		const form = loadStep().querySelector( 'form' );

		form.querySelector( '[name="onboarding_myshop[test_mode]"]' ).checked =
			false;
		sendAnswers( form, 'onboarding_myshop' );

		expect( ask( {} ) ).not.toHaveProperty( 'test_mode' );
	} );

	test( 'a step with no input name adds nothing', () => {
		const form = loadStep( { prefix: '' } ).querySelector( 'form' );

		sendAnswers( form, '' );

		expect( ask( { value: 'x' } ) ).toEqual( { value: 'x' } );
	} );

	test( 'an event with no payload is left alone', () => {
		const form = loadStep().querySelector( 'form' );

		sendAnswers( form, 'onboarding_myshop' );

		expect( () =>
			form.dispatchEvent(
				new window.CustomEvent( 'fieldkit:action-payload', {
					bubbles: true,
				} )
			)
		).not.toThrow();
		expect( () =>
			form.dispatchEvent(
				new window.CustomEvent( 'fieldkit:action-payload', {
					detail: { action: 'test' },
				} )
			)
		).not.toThrow();
	} );

	test( 'a button outside the form gets nothing from it', () => {
		const form = loadStep().querySelector( 'form' );

		sendAnswers( form, 'onboarding_myshop' );
		document.body.insertAdjacentHTML(
			'beforeend',
			'<button type="button" id="outside">x</button>'
		);

		const payload = {};

		document.getElementById( 'outside' ).dispatchEvent(
			new window.CustomEvent( 'fieldkit:action-payload', {
				bubbles: true,
				detail: { payload },
			} )
		);

		expect( payload ).toEqual( {} );
	} );

	test( 'addAnswers() writes to the payload it is given', () => {
		const form = loadStep().querySelector( 'form' );
		const payload = { contact: 'me@example.test' };

		addAnswers( form, 'onboarding_myshop', payload );

		expect( payload.contact ).toBe( 'me@example.test' );
		expect( payload.store_name ).toBe( 'My shop' );
	} );
} );
