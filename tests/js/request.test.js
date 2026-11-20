/**
 * The card's requests go through apiFetch, and every failure comes back as
 * an Error carrying the words to say, or none.
 */

import { describe, expect, test, vi } from 'vitest';

import { createPost } from '../../src/js/checklist/request';

const URL = 'https://example.test/wp-json/x/v1/checklists/guide/dismiss';

describe( 'createPost()', () => {
	test( 'posts JSON with the nonce, parsed, and hands back the answer', async () => {
		const apiFetch = vi.fn( () =>
			Promise.resolve( { html: '<div></div>' } )
		);

		await expect(
			createPost( apiFetch )( URL, 'n0nce', { dismissed: true } )
		).resolves.toEqual( { html: '<div></div>' } );

		expect( apiFetch ).toHaveBeenCalledWith( {
			url: URL,
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'n0nce',
			},
			body: '{"dismissed":true}',
			parse: true,
		} );
	} );

	test.each( [
		[
			'a refusal: the server’s reason',
			{
				code: 'rest_forbidden',
				message: 'Not allowed.',
				data: { status: 403 },
			},
			'Not allowed.',
		],
		[
			'no answer: apiFetch’s words',
			{ code: 'fetch_error', message: 'You are probably offline.' },
			'You are probably offline.',
		],
		[ 'a refusal with no reason: none', { code: 'rest_error' }, '' ],
		[ 'something that is not an object: none', 'down', '' ],
		[ 'nothing at all: none', undefined, '' ],
		[
			'an abort: its message',
			{ name: 'AbortError', message: 'Aborted' },
			'Aborted',
		],
	] )( '%s', async ( what, rejection, message ) => {
		const apiFetch = vi.fn( () => Promise.reject( rejection ) );
		const error = await createPost( apiFetch )( URL, '', {} ).catch(
			( e ) => e
		);

		expect( error ).toBeInstanceOf( Error );
		expect( error.message ).toBe( message );
	} );
} );
