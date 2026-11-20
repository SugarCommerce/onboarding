/**
 * Onboarding checklist card.
 *
 * Hide the card and bring it back, tick a task that is ticked by hand, and
 * open the compact card's list: each through the library's REST route, by
 * wp.apiFetch, which renews an expired nonce and tries again, so a card left
 * open for a day still works.
 *
 * Loaded by Checklist::enqueue() on the screens that draw a card, under the
 * copy's handle (Runtime::handle( 'checklist' )), in the footer. Bound on the
 * document at once: nothing waits for the page, since a click cannot come
 * before it.
 */

import apiFetch from '@wordpress/api-fetch';

import { bindChecklists } from './checklist/card';
import { createPost } from './checklist/request';

bindChecklists( document, { post: createPost( apiFetch ) } );
