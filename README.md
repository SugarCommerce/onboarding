# SugarCommerce Onboarding

A first-run setup wizard and a setup checklist for a WordPress plugin,
described as steps and tasks rather than built as screens.

## What it does

The first five minutes after activation decide whether a plugin gets used.
Shopify, Stripe and WooCommerce all answer them the same way: a short, calm
wizard that asks one thing per screen, then a checklist that stays on the
dashboard until the rest is done. Building that means a multi-step form,
progress, validation, resume, buttons that test things without leaving the
page, somewhere to keep the answers, a redirect on activation, and a card
that knows which tasks are done.

This is that, from a list of steps and a list of tasks. The fields are
[sugarcommerce/fields](https://github.com/SugarCommerce/fields)', so every
control matches the rest of the admin, and answers land where your
[settings page](https://github.com/SugarCommerce/settings) already reads them.

## Features

* Ask one question per screen, with any field sugarcommerce/fields draws
* Save answers straight into your settings page's option, encrypted as it encrypts
* Refuse a step until it is right, with each message beside its field
* Show a step only when an earlier answer calls for it
* Let people skip a step, or save and finish later, and pick up where they left off
* Test a key or create pages from a button, without leaving the step
* Keep a setup checklist on the dashboard until every task is done
* Open the wizard once on activation, and run it again from anywhere

## Installation

```bash
composer require sugarcommerce/onboarding
```

## Quick start

```php
use SugarCommerce\Onboarding\Onboarding;

add_action( 'init', function () {
	Onboarding::register( 'myshop-setup', [
		'title'    => __( 'Set up My Shop', 'my-shop' ),
		'option'   => 'myshop_settings',
		'redirect' => true,
		'steps'    => [
			'welcome' => [
				'title'       => __( 'Welcome to My Shop', 'my-shop' ),
				'description' => __( 'Three questions and your shop is ready for a test sale.', 'my-shop' ),
			],
			'store'   => [
				'title'  => __( 'Your store', 'my-shop' ),
				'fields' => [
					'store_name' => [
						'type'     => 'text',
						'label'    => __( 'Store name', 'my-shop' ),
						'required' => true,
					],
					'country'    => [
						'type'  => 'country',
						'label' => __( 'Country', 'my-shop' ),
					],
				],
			],
		],
	] );
} );

register_activation_hook( __FILE__, function () {
	Onboarding::redirect_after_activation( 'myshop-setup' );
} );
```

`'option' => 'myshop_settings'` is the part worth noticing: answers go into
the option your settings page already uses, so the wizard is a friendlier way
into the same values rather than a second copy of them.

## Contents

* [Steps](#steps)
* [Fields](#fields)
* [Where answers are stored](#where-answers-are-stored)
* [A settings page's own settings](#a-settings-pages-own-settings)
* [Validation](#validation)
* [Conditional steps](#conditional-steps)
* [Skip, finish later and resume](#skip-finish-later-and-resume)
* [Buttons that do something](#buttons-that-do-something)
* [Custom markup](#custom-markup)
* [The finish screen](#the-finish-screen)
* [The setup checklist](#the-setup-checklist)
* [Redirect on activation](#redirect-on-activation)
* [Running it again](#running-it-again)
* [Who may run it](#who-may-run-it)
* [Several wizards on one site](#several-wizards-on-one-site)
* [Layout](#layout)
* [Styling](#styling)
* [Hooks](#hooks)
* [Methods](#methods)
* [REST routes](#rest-routes)
* [Accessibility](#accessibility)
* [Translation](#translation)
* [Moving from arraypress/wp-*](#moving-from-arraypresswp-)

## Steps

A step is a screen. Any step may hold any of these, drawn top to bottom:
`image`, `content` (filtered through `wp_kses_post()`), `items` (an icon and a
line or two each), a `render` callback, `fields`, and `links`. A step with only
`content` explains; one with only `fields` asks.

```php
'steps' => [
	'welcome' => [
		'title'       => __( 'Welcome', 'my-shop' ),
		'description' => __( 'A few minutes now and you are ready to sell.', 'my-shop' ),
		'items'       => [
			[ 'icon' => 'heroicons-outline:building-storefront', 'title' => __( 'Your store', 'my-shop' ), 'description' => __( 'Name and country.', 'my-shop' ) ],
			[ 'icon' => 'admin-network', 'title' => __( 'Payments', 'my-shop' ), 'description' => __( 'Paste a key and test it.', 'my-shop' ) ],
		],
		'button'      => __( 'Get Started', 'my-shop' ),
	],
],
```

An item's `icon` takes either form the field engine's `Icons::render()` reads: a
`set:name` icon (`heroicons-outline:building-storefront`, any set the engine has),
drawn as inline SVG in the accent colour, or a Dashicon by its name or its class
(`admin-network`, `dashicons-admin-network`), drawn as the font's span. With no
icon, or one no set has, an item has a tick (`heroicons-outline:check`). The
wizard's and the checklist's own icons -- the ticks and dashes in the progress,
Back, Show All, the dismiss cross, the mark on a link that opens a new tab --
are Heroicons outline too.

`short_title` is what the progress calls a step whose title is a sentence.
`button` renames Continue for one step. The wizard's own wording is in
`labels` — `next`, `back`, `skip`, `finish`, `later` and `exit` — for a plugin
that wants its own voice or its own translations.

## Fields

Every type sugarcommerce/fields registers works in a step, from `text` to `repeater`,
`email_editor` and `license`, and is stored as the field engine sanitizes it. Media
fields open the media library, relational fields search, conditional fields
(`show_when`) hide and are cleared when hidden. See its README for the
types and their options.

```php
'payments' => [
	'title'  => __( 'Payments', 'my-shop' ),
	'fields' => [
		'currency'  => [
			'type'    => 'currency',
			'label'   => __( 'Currency', 'my-shop' ),
			'default' => 'GBP',
		],
		'test_mode' => [
			'type'        => 'toggle',
			'label'       => __( 'Test mode', 'my-shop' ),
			'default'     => 1,
			'description' => __( 'Nothing real is charged while this is on.', 'my-shop' ),
		],
		'secret'    => [
			'type'   => 'password',
			'label'  => __( 'Secret key', 'my-shop' ),
			'reveal' => true,
		],
	],
],
```

A password box left empty keeps what is stored, as it does on a settings page.

## Where answers are stored

Five arrangements, from the most common:

```php
// The settings page's own: see the next section.
'settings' => 'myshop',

// Every answer in one option, as a settings array.
'option' => 'myshop_settings',

// The plugin's own getter and setter, for settings that are encrypted,
// validated or spread across several stores.
'get_callback'    => fn( string $key ) => myshop_get_setting( $key ),
'update_callback' => fn( string $key, $value ) => myshop_update_setting( $key, $value ),

// Any field context.
'context' => new \SugarCommerce\Fields\Context\OptionContext( 'myshop_settings' ),

// Nothing: an option per field, named by its key or by its own `option`.
```

A step can store its answers itself with `save`, which is handed the
sanitized answers and the wizard. Returning a `WP_Error` or a message stops
the step with it:

```php
'save' => function ( array $values ) {
	return myshop_create_account( $values ) ? true : new WP_Error( 'account', __( 'The account could not be created.', 'my-shop' ) );
},
```

Progress — which steps were answered, skipped, where to pick up, whether it
was finished — is kept apart, in `{id}_onboarding` (or `state_option`), so
running the wizard again keeps every answer.

## A settings page's own settings

A wizard is usually a friendlier way into settings a
[sugarcommerce/settings](https://github.com/SugarCommerce/settings) page
already has. Name the page, and a step's field with no `type` is that page's
field of the same key -- the same control, choices, rules and encryption,
with the step's own keys laid over it:

```php
Onboarding::register( 'myshop-setup', [
	'title'    => __( 'Set up My Shop', 'my-shop' ),
	'settings' => 'myshop', // The id the settings page is registered under.
	'steps'    => [
		'payments' => [
			'title'  => __( 'Connect payments', 'my-shop' ),
			'fields' => [
				'currency'   => [],
				'secret_key' => [ 'required' => true, 'description' => __( 'From your account, under Developers.', 'my-shop' ) ],
				'notes'      => [ 'type' => 'textarea', 'label' => __( 'Anything else?', 'my-shop' ) ],
			],
		],
	],
] );
```

* Answers are read and written through `Page::options( 'myshop' )`, so a
  secret is stored encrypted and read decrypted exactly as the page does it,
  and refused or sanitised by its field the same way.
* Where a field sits on the page -- `tab`, `section`, `width` -- is dropped:
  a wizard is a column, a step at a time.
* A field the page does not have is left out rather than drawn as a text box
  nobody configured. A field with a `type` is the wizard's own.
* The page is looked up the first time the steps are wanted, so it only has
  to be registered before the wizard is drawn or submitted.
* A `get_callback` and `update_callback` pair still wins for storage, for a
  plugin whose own reader fires hooks; the fields are still the page's.

## Validation

A step passes or fails whole: nothing is stored until every check passes.

The field engine refuses a field that is `required` and empty, or that breaks its own
`validate` rule — a rule name (`email`, `url`, `numeric`, `integer`, `slug`,
`alphanumeric`) or a callable answering true or a message.

The step's own `validate` sees every answer together, and the wizard:

```php
'limits' => [
	'title'    => __( 'Order limits', 'my-shop' ),
	'fields'   => [
		'minimum' => [ 'type' => 'number', 'label' => __( 'Smallest order', 'my-shop' ) ],
		'maximum' => [ 'type' => 'number', 'label' => __( 'Largest order', 'my-shop' ) ],
	],
	'validate' => function ( array $values, $wizard ) {
		if ( $values['minimum'] > $values['maximum'] ) {
			return [ 'maximum' => __( 'The largest order has to be at least the smallest.', 'my-shop' ) ];
		}

		return true;
	},
],
```

It may answer:

| Answer | Means |
|---|---|
| `true`, `null`, nothing | Pass |
| `false` | Refuse the step with a general message |
| a string | Refuse the step with that message |
| `WP_Error` | Each code that names a field puts its message beside that field; any other code is about the step |
| an array | Messages keyed by field, or by anything else for the step |

A refused step comes back showing what was typed, with a list of what to fix
at the top — each a link to its field — and each message again beside its
field. `sugarcommerce_onboarding_validate_{id}` can add to or clear the list.

## Conditional steps

A step with a `show_if` that says no is absent: not counted, not reachable by
its URL, and a submission naming it is refused. Either a callable, handed the
wizard, or a field condition over answers already saved:

```php
'shipping' => [
	'title'   => __( 'Shipping', 'my-shop' ),
	'show_if' => [ 'sells' => [ 'physical', 'both' ] ],
	'fields'  => [
		'ship_from' => [ 'type' => 'country', 'label' => __( 'Ship from', 'my-shop' ) ],
	],
],

'tax' => [
	'title'   => __( 'Tax', 'my-shop' ),
	'show_if' => fn( $wizard ) => 'EU' === myshop_region_of( $wizard->value( 'country' ) ),
],
```

The long form takes an operator: `[ 'field' => 'orders', 'operator' => '>', 'value' => 100 ]`.

## Skip, finish later and resume

```php
'extras' => [
	'title'      => __( 'Extras', 'my-shop' ),
	'skippable'  => true,
	'skip_label' => __( 'Not Now', 'my-shop' ),
	'fields'     => [ /* … */ ],
],
```

Skip stores nothing and remembers the step as skipped, so the progress says
so and the step is there to come back to. Save and Finish Later stores the
step if it passes and leaves to `exit_url`. Opening the wizard again, by its
URL or from the checklist, lands on the first step nobody has dealt with.
Steps already dealt with are links in the progress; steps ahead cannot be
opened by URL, since they may depend on an answer not given yet.

## Buttons that do something

A "Test Connection" or "Create Pages" button is the `action_button` field. It
runs over REST and reports in place, and it is handed every answer on the step
as typed — before anything is saved — under each field's key:

```php
'fields' => [
	'api_key' => [
		'type'  => 'password',
		'label' => __( 'API key', 'my-shop' ),
	],
	'test'    => [
		'type'            => 'action_button',
		'label'           => __( 'Check the key', 'my-shop' ),
		'button_label'    => __( 'Test Connection', 'my-shop' ),
		'action_callback' => function ( array $payload ) {
			$key = $payload['api_key'] ?: myshop_get_setting( 'api_key' );

			return myshop_ping( $key )
				? [ 'success' => true, 'message' => __( 'Connected.', 'my-shop' ) ]
				: [ 'success' => false, 'message' => __( 'That key did not work.', 'my-shop' ) ];
		},
	],
],
```

The handler is declared on `rest_api_init` for every step, so a press always
finds it. Whoever may run the wizard may press its buttons; a field that wants
a stricter rule sets `action_capability`.

## Custom markup

For what fields cannot express — a list of the pages a button will make, a
webhook address with its events — `render` draws inside the step, above its
fields:

```php
'pages' => [
	'title'  => __( 'Your pages', 'my-shop' ),
	'render' => function ( array $step, $wizard ) {
		echo '<ul>';
		foreach ( myshop_required_pages() as $page ) {
			printf( '<li>%s</li>', esc_html( $page['title'] ) );
		}
		echo '</ul>';
	},
],
```

Markup between two fields is the `html` field. A step with a `type` this
library does not know is handed to `sugarcommerce_onboarding_render_{type}`.

`'type' => 'sync'` with a `sync_id` draws the button and progress bar of a
[sugarcommerce/jobs](https://github.com/SugarCommerce/jobs) `Sync`, with
Continue waiting until it has run (or failed -- a wizard nobody can finish is
a plugin nobody can set up). Register the sync with the wizard's screen as its
`hook_suffix` so its script loads there. Without the package, or with no such
sync, the step is drawn without them and Continue does not wait.

## The finish screen

Finishing the last step shows a finish screen: a tick, a heading, optional
content, a checklist and somewhere to go. Opening a finished wizard lands
there too.

```php
'finish' => [
	'title'     => __( 'Your shop is ready for a test sale', 'my-shop' ),
	'checklist' => 'myshop-guide',
	'links'     => [
		[ 'label' => __( 'Go to Orders', 'my-shop' ), 'url' => admin_url( 'admin.php?page=myshop-orders' ) ],
		[ 'label' => __( 'Read the Guide', 'my-shop' ), 'url' => 'https://example.com/docs', 'external' => true ],
	],
	'confetti'  => true,
],
```

`'finish' => false` goes straight to `exit_url` instead, and
`completed_redirect` sends a finished wizard anywhere.

## The setup checklist

A card of tasks that stays until they are done. Each task says how to tell —
"Add your first product" is done when a product exists — so the card is never
out of date: delete the only product and the task comes back.

```php
Onboarding::register_checklist( 'myshop-guide', [
	'title'            => __( 'Get your shop ready', 'my-shop' ),
	'description'      => __( 'Each task ticks itself off when it is done.', 'my-shop' ),
	'wizard'           => 'myshop-setup',
	'dashboard_widget' => __( 'Shop setup', 'my-shop' ),
	'tasks'            => [
		'product' => [
			'title'       => __( 'Add your first product', 'my-shop' ),
			'description' => __( 'A name, a price and a file is enough.', 'my-shop' ),
			'done'        => fn() => myshop_count_products() > 0,
			'url'         => admin_url( 'admin.php?page=myshop-products&action=new' ),
			'button'      => __( 'Add Product', 'my-shop' ),
		],
		'domain'  => [
			'title' => __( 'Connect your domain', 'my-shop' ),
			// No `done`: ticked by hand, with a Mark as Done button.
		],
		'theme'   => [
			'title'    => __( 'Pick a theme', 'my-shop' ),
			'optional' => true,
			'done'     => fn() => 'twentytwentysix' !== get_stylesheet(),
		],
	],
] );
```

Then wherever it belongs — a plugin's dashboard, an empty list:

```php
add_action( 'admin_enqueue_scripts', fn() => Onboarding::checklist( 'myshop-guide' )?->enqueue() );

Onboarding::checklist( 'myshop-guide' )?->render();
```

* `done` is a callable; only an answer of exactly `true` is done, so a check
  that returns a count has to say what it means. With no `done`, a task is
  ticked by hand and the tick is stored for the site.
* `optional` tasks are counted but do not hold the list back from complete.
* `show_if` is a callable; a task that says no is not on the list.
* `wizard` adds a Continue Setup button while that wizard is unfinished.
* `dashboard_widget` — true, or the widget's title — puts the card on the
  WordPress dashboard.
* The first task not done is open, with its button; the rest are a native
  accordion.
* Dismissing hides the card for that person only, with an undo.
  `dismissible => false` keeps it; `hide_when_complete => true` removes it once
  every required task is done.
* `status()` answers where it stands — done, total,
  percent, complete, the next task and each task — for an API, an ability or a
  command-line tool. The next task is the first required one not done; an
  optional task is only next once nothing required is left.

### Compact

On a screen people open every day — a list of orders — the full card pushes
the work they came for below the fold on every visit. The compact layout is
the same card folded to one row, about the height of a WordPress notice:

```php
Onboarding::checklist( 'myshop-guide' )?->render( [ 'layout' => 'compact' ] );
```

> **Get your shop ready** · 3 of 7 done ▬▬▬── │ Next: Add your shop's details
> [Add Details] · [Continue Setup] · Show All ⌄ · ×

* The row has the title, the count with a slim bar, the next task with its
  button (or Mark as Done for a task ticked by hand), Continue Setup while the
  wizard is unfinished, Show All, and the dismiss button.
* Show All opens the whole list under the row, in place. Whether it is open is
  remembered per person (`is_expanded()`, `expand()`), so somebody who wants
  the list in view keeps it.
* Ticking, dismissing and bringing the card back keep the layout.
* At phone width the row becomes three short lines: title and count beside the
  dismiss button, the next task, then the controls.

`'layout' => 'full'` is the default, and is what the wizard's finish screen and
the dashboard widget draw.

## Redirect on activation

```php
register_activation_hook( __FILE__, fn() => Onboarding::redirect_after_activation( 'myshop-setup' ) );
```

The wizard has to say `'redirect' => true` as well, so turning it off is one
line. It happens once, never to a finished wizard, never in a bulk activation,
never to somebody who may not run it, and `sugarcommerce_onboarding_redirect_{id}`
can say no.

## Running it again

```php
$wizard = Onboarding::get( 'myshop-setup' );

$wizard->reset();               // Progress only; answers stay.
wp_safe_redirect( $wizard->url() );

$wizard->url( 'payments' );     // Open at a step.
$wizard->is_completed();
$wizard->progress();            // Counts, resume step, each step's status.
```

A "Run Setup Again" button on a Tools screen is those first two lines behind a
nonce.

## Who may run it

`capability` (default `manage_options`) decides who may open the wizard,
submit it, press its buttons and read its progress. A checklist has its own
`capability`. Every submission carries a nonce for its own wizard and step,
and every REST route needs a signed-in user with the capability and a REST
nonce.

## Several wizards on one site

Two plugins can each have a wizard and a checklist. Everything either stores —
the page, the input names, the nonces, the progress, the answers, the
activation flag, the dismissals — is named from its own id. Assets load only on
the wizard's own screen. In a Strauss-prefixed build the script handles, the
REST namespace and the transients carry the plugin's prefix as well, so two
bundled copies never answer for each other.

## Layout

```php
'layout' => 'focus', // or 'admin'
```

`focus` (the default) takes the admin menu, toolbar and footer away and puts
the plugin's name and a way out across the top, as Shopify's, Stripe's and
WooCommerce's first runs do: a first run is a funnel, and twenty menu items
beside it are twenty ways out. It is still an admin page, so the media modal,
colour picker and every script a field needs load as usual.

`admin` keeps the menu, for a wizard that is one tool among others.

```php
'progress' => 'top', // or 'side'
```

`top` (the default) is one column: "Step 2 of 5 · Your shop" and a slim bar
of one segment per step above the card, the card centred under it. A segment
is solid when done, half full where you are, hatched when skipped and empty
when still to come — different shapes, not only different colours — with
each step's name under its segment on a wide screen and done steps linked
back. Under 720px the names go and the count says where you are. `side` puts
the steps in a rail beside the card instead, for a wizard with many steps
whose names are worth seeing at once; on a narrow screen it folds to a count
and a bar above the card. Either way the steps are an ordered list with the
current one marked, so a screen reader hears the same thing.

`parent_slug` (default `index.php`) and `hidden` (default true) decide where
the page hangs; `logo`, `title`, `page_title` and `menu_title` what it is
called; `notices` (default false) whether other plugins' notices show on it.

## Styling

The look matches the flyout panels':
a grey surface, a white card, rounded controls, and colour from the admin
scheme. A plugin brands it with `accent`, or with custom properties rather
than overriding rules:

```php
'accent' => '#7c3aed',
```

```css
.onboarding {
	--onboarding-accent: #7c3aed;
	--onboarding-card-radius: 16px;
	--onboarding-width: 720px;
}

.onboarding-checklist {
	--onboarding-checklist-accent: #7c3aed;
}
```

Every token is listed at the top of `assets/css/onboarding.css` and
`assets/css/checklist.css`. `body_class` adds a class of the plugin's own to
the wizard's page.

## Hooks

Every hook carries the wizard's or checklist's id, so a plugin hears only its
own.

| Hook | Type | Arguments |
|---|---|---|
| `sugarcommerce_onboarding_steps_{id}` | filter | `$steps, $wizard` — add, remove or reorder steps |
| `sugarcommerce_onboarding_step_applies_{id}` | filter | `$applies, $key, $step, $wizard` |
| `sugarcommerce_onboarding_validate_{id}` | filter | `$errors, $key, $values, $wizard` |
| `sugarcommerce_onboarding_values_{id}` | filter | `$values, $key, $wizard` — before storing |
| `sugarcommerce_onboarding_step_saved_{id}` | action | `$key, $values, $wizard` |
| `sugarcommerce_onboarding_step_skipped_{id}` | action | `$key, $wizard` |
| `sugarcommerce_onboarding_completed_{id}` | action | `$wizard` |
| `sugarcommerce_onboarding_completed_redirect_{id}` | filter | `$url, $wizard` |
| `sugarcommerce_onboarding_reset_{id}` | action | `$wizard` |
| `sugarcommerce_onboarding_redirect_{id}` | filter | `$redirect, $wizard` — after activation |
| `sugarcommerce_onboarding_before_step_{id}` | action | `$key, $step, $wizard` — inside the form |
| `sugarcommerce_onboarding_after_step_{id}` | action | `$key, $step, $wizard` |
| `sugarcommerce_onboarding_render_{type}` | action | `$step, $wizard` — a step type of your own |
| `sugarcommerce_onboarding_checklist_tasks_{id}` | filter | `$tasks, $checklist` — add, remove or reorder tasks |
| `sugarcommerce_onboarding_checklist_task_done_{id}` | filter | `$done, $key, $task, $checklist` |
| `sugarcommerce_onboarding_checklist_status_{id}` | filter | `$status, $checklist` |
| `sugarcommerce_onboarding_checklist_task_marked_{id}` | action | `$key, $done, $checklist` |
| `sugarcommerce_onboarding_checklist_dismissed_{id}` | action | `$dismissed, $user_id, $checklist` |

## Methods

| Method | Does |
|---|---|
| `Onboarding::register( $id, $config )` | Register a wizard; the `Wizard`, or null with no steps |
| `Onboarding::get( $id )` | The `Wizard` |
| `Onboarding::redirect_after_activation( $id )` | Open it once, from an activation hook |
| `Onboarding::unregister( $id )` | Forget it |
| `$wizard->url( $step = '' )` | Its URL, where it left off or at a step |
| `$wizard->progress()` | Counts, resume step and each step's status |
| `$wizard->is_completed()`, `->reset()` | Whether it was finished; run it again, answers kept |
| `$wizard->value( $key )` | One stored answer |
| `$wizard->hook()` | The screen's hook suffix, once the menu is registered |
| `Onboarding::register_checklist( $id, $config )` | Register a checklist |
| `Onboarding::checklist( $id )` | The `Checklist` |
| `$checklist->render( $args = [] )`, `->html( $args )` | Print its card, or have it |
| `$checklist->enqueue()` | Load the card's assets in the head |
| `$checklist->status()`, `->is_complete()` | Where it stands |
| `$checklist->mark( $task, $done )`, `->dismiss()`, `->expand()` | What its buttons do |
| `Onboarding::unregister_checklist( $id )` | Forget it |

## REST routes

Under `sugarcommerce-onboarding/v1` (or `{prefix}-sugarcommerce-onboarding/v1`
in a prefixed build). Each
needs a signed-in user with the capability, and a REST nonce. The card's
script sends them through `wp.apiFetch` (a dependency of the script), which
renews an expired nonce and tries again, so a dashboard left open overnight
still works.

| Route | Method | Does |
|---|---|---|
| `/checklists/{id}` | GET | The checklist's status, and whether this person dismissed it |
| `/checklists/{id}/dismiss` | POST | `dismissed: true\|false` — hide or bring back the card |
| `/checklists/{id}/tasks/{task}` | POST | `done: true\|false` — tick a task ticked by hand |
| `/checklists/{id}/expanded` | POST | `expanded: true\|false` — keep the compact card's list open for this person |
| `/wizards/{id}` | GET | The wizard's progress |

Buttons in steps run through the field engine's own action route.

## Accessibility

* Focus moves to each step's heading when it opens, so a screen reader starts
  at the question; to the list of errors when a step comes back refused.
* Errors are an alert, each a link that moves focus to its field, and said
  again beside the field with `aria-invalid` and `aria-describedby`.
* Continue is the first button in the form, so Enter means Continue; Skip and
  Save and Finish Later skip the browser's own checks.
* The progress is an ordered list with `aria-current="step"`; done and skipped
  are said, not only drawn, and told apart by shape as well as colour.
* The checklist is a native accordion; its bar is a labelled `progressbar`;
  ticking and dismissing are announced and keep the focus in place.
* The compact card's Show All is a button with `aria-expanded` and
  `aria-controls`; its row reads, and tabs, in the order it is drawn, with the
  list straight after the button that opens it.
* Everything works from the keyboard, at phone width, right to left, and with
  reduced motion (no confetti, no transitions). Nothing is loaded from another
  site.

## Translation

Every string the library shows is in the `sugarcommerce` text domain. A plugin
that wants its own wording, or its own translations, passes `labels` to the
wizard and checklist and writes its own titles.

## Moving from arraypress/wp-*

From `arraypress/wp-register-onboarding` (`ArrayPress\RegisterOnboarding`).
The global functions are gone -- a global function is the one thing Strauss
cannot prefix -- and each is a method. Every configuration key, option,
user meta key, CSS class and data attribute keeps its name.

| Old | New |
|---|---|
| `ArrayPress\RegisterOnboarding\*` | `SugarCommerce\Onboarding\*` |
| `register_onboarding( $id, $config )` | `Onboarding::register( $id, $config )` |
| `get_onboarding( $id )` | `Onboarding::get( $id )` |
| `get_onboarding_url( $id, $step )` | `Onboarding::get( $id )?->url( $step )` |
| `get_onboarding_progress( $id )` | `Onboarding::get( $id )?->progress()` |
| `is_onboarding_completed( $id )` | `Onboarding::get( $id )?->is_completed()` |
| `reset_onboarding( $id )` | `Onboarding::get( $id )?->reset()` |
| `onboarding_redirect_after_activation( $id )` | `Onboarding::redirect_after_activation( $id )` |
| `unregister_onboarding( $id )` | `Onboarding::unregister( $id )` |
| `register_onboarding_checklist( $id, $config )` | `Onboarding::register_checklist( $id, $config )` |
| `get_onboarding_checklist( $id )` | `Onboarding::checklist( $id )` |
| `render_onboarding_checklist( $id, $args )` | `Onboarding::checklist( $id )?->render( $args )` |
| `enqueue_onboarding_checklist( $id )` | `Onboarding::checklist( $id )?->enqueue()` |
| `get_onboarding_checklist_status( $id )` | `Onboarding::checklist( $id )?->status()` |
| `is_onboarding_checklist_complete( $id )` | `Onboarding::checklist( $id )?->is_complete()` |
| `unregister_onboarding_checklist( $id )` | `Onboarding::unregister_checklist( $id )` |
| hooks `arraypress_onboarding_*_{id}` | `sugarcommerce_onboarding_*_{id}`, the same eighteen with the same arguments |
| REST `onboarding/v1`, `{prefix}-onboarding/v1` | `sugarcommerce-onboarding/v1`, `{prefix}-sugarcommerce-onboarding/v1` |
| handles `onboarding`, `onboarding-checklist` | `sugarcommerce-onboarding`, `sugarcommerce-onboarding-checklist` (`Runtime::handle()`) |
| `Utils\Assets` (its own copy of the asset-URL arithmetic) | `SugarCommerce\Plugin\Assets`, the one asset-URL helper of these packages |
| activation transient `onboarding_{id}_redirect` | `sugarcommerce_onboarding_{id}_redirect` |
| `arraypress/wp-field-kit`, `arraypress/wp-composer-assets` | `sugarcommerce/fields`; the assets are loaded by the package itself |
| step `type => 'sync'` on `wp-inline-sync`, events `inline-sync:*` | on `sugarcommerce/jobs`' `Sync`, events `sugar-sync:*`; Continue waits only when the sync exists |
| icon class `arraypress-icon` | `sugarcommerce-icon` |
| text domain `arraypress` | `sugarcommerce` |
| options `{id}_onboarding`, `{id}_checklist`; user meta `{id}_checklist_dismissed`, `{id}_checklist_expanded` | unchanged |
| — | `'settings' => $page_id`: a settings page's fields by key, stored through its reader; `Checklist::render()` |

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
