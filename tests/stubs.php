<?php
/**
 * WordPress stubs specific to an admin page that redirects.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// The asset loaders work out a URL by asking where the file sits relative to
// wp-content. The library really is under one during development, so pointing
// at it gives them a true answer rather than a contrived one.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__, 3 ) );
}

/**
 * A redirect, so a test can see where a submission went.
 *
 * Production code redirects and exits, which a test cannot follow. Throwing
 * instead stops the request at the same point and carries the destination
 * out, and satisfies the `never` return type for the same reason `exit` does.
 */
final class OnboardingRedirect extends RuntimeException {

	/**
	 * Where it went.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Construct.
	 *
	 * @param string $url Where it went.
	 */
	public function __construct( string $url ) {
		parent::__construct( 'Redirected to ' . $url );

		$this->url = $url;
	}
}

/**
 * A wp_die(), likewise.
 */
final class OnboardingDied extends RuntimeException {}

/**
 * Reset every stubbed global between tests.
 *
 * @return void
 */
function ob_reset_globals(): void {
	$GLOBALS['ob_options']       = [];
	$GLOBALS['ob_transients']    = [];
	$GLOBALS['ob_hooks']         = [];
	$GLOBALS['ob_fired']         = [];
	$GLOBALS['ob_menu']          = [];
	$GLOBALS['ob_removed_menu']  = [];
	$GLOBALS['ob_styles']        = [];
	$GLOBALS['ob_scripts']       = [];
	$GLOBALS['ob_caps']          = [ 'manage_options' ];
	$GLOBALS['ob_nonce_ok']      = true;
	$GLOBALS['ob_nonce_checked'] = null;
	$GLOBALS['ob_doing_ajax']    = false;
	$GLOBALS['ob_user_id']       = 1;
	$GLOBALS['ob_user_meta']     = [];
	$GLOBALS['ob_routes']        = [];
	$GLOBALS['ob_widgets']       = [];
	$GLOBALS['_parent_pages']    = [];

	$_GET  = [];
	$_POST = [];

	// The registry is static, and so is the flag that stops it attaching its
	// hooks twice — which is right in a request and wrong across tests,
	// where the second test would find no hooks attached at all.
	if ( class_exists( 'SugarCommerce\\Onboarding\\Onboarding' ) ) {
		foreach ( [ 'wizards', 'checklists' ] as $property ) {
			( new ReflectionProperty( 'SugarCommerce\\Onboarding\\Onboarding', $property ) )->setValue( null, [] );
		}

		( new ReflectionProperty( 'SugarCommerce\\Onboarding\\Onboarding', 'hooked' ) )->setValue( null, false );
	}

	// The kit's registry of button handlers is shared for the request, which
	// is right in a request and wrong across tests: a handler a previous test
	// registered would answer for one this test never declared.
	if ( class_exists( 'SugarCommerce\\Fields\\Actions\\Actions' ) ) {
		( new ReflectionProperty( 'SugarCommerce\\Fields\\Actions\\Actions', 'shared' ) )->setValue( null, null );
	}

	if ( class_exists( 'SugarCommerce\\Onboarding\\Rest\\Controller' ) ) {
		( new ReflectionProperty( 'SugarCommerce\\Onboarding\\Rest\\Controller', 'claimed' ) )->setValue( null, [] );
	}
}

ob_reset_globals();

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $group, $name, $args = [] ) {
		$GLOBALS['ob_settings'][ $name ] = $args;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['ob_options'] ) ? $GLOBALS['ob_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['ob_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['ob_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['ob_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['ob_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiry = 0 ) {
		$GLOBALS['ob_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['ob_transients'][ $key ] );

		return true;
	}
}

/*
 * Hooks that run. A wizard's extension points are its filters and actions,
 * and a stub that only recorded them could not show that a filter's answer
 * is used — only that it was asked.
 */
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ob_hooks'][ $hook ][] = [
			'callback' => $callback,
			'priority' => (int) $priority,
			'args'     => (int) $accepted_args,
		];

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'ob_callbacks' ) ) {
	/**
	 * A hook's callbacks, in priority order.
	 *
	 * @param string $hook The hook.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function ob_callbacks( string $hook ): array {
		$callbacks = $GLOBALS['ob_hooks'][ $hook ] ?? [];

		usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		return $callbacks;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( ob_callbacks( (string) $hook ) as $registered ) {
			$value = ( $registered['callback'] )( ...array_slice( array_merge( [ $value ], $args ), 0, max( 1, $registered['args'] ) ) );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['ob_fired'][] = [ $hook, $args ];

		foreach ( ob_callbacks( (string) $hook ) as $registered ) {
			( $registered['callback'] )( ...array_slice( $args, 0, $registered['args'] ) );
		}
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = false ) {
		foreach ( $GLOBALS['ob_hooks'][ $hook ] ?? [] as $registered ) {
			if ( false === $callback || $registered['callback'] === $callback ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ob_fired' ) ) {
	/**
	 * The hooks fired, by name, in order.
	 *
	 * @return string[]
	 */
	function ob_fired(): array {
		return array_column( $GLOBALS['ob_fired'], 0 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return in_array( $capability, (array) $GLOBALS['ob_caps'], true );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$args = is_array( $args ) ? $args : [ $args => $url ];

		$parts = explode( '?', (string) $url, 2 );
		$query = [];

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}

		return $parts[0] . '?' . http_build_query( array_merge( $query, $args ) );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url, $status = 302 ) {
		throw new OnboardingRedirect( (string) $url );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		throw new OnboardingDied( is_string( $message ) ? $message : 'died' );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		if ( ! $GLOBALS['ob_nonce_ok'] ) {
			throw new OnboardingDied( 'nonce' );
		}

		$GLOBALS['ob_nonce_checked'] = $action;

		return 1;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = sprintf( '<input type="hidden" name="%s" value="nonce">', $name );

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $field;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		$GLOBALS['ob_menu'][ $menu_slug ]       = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'callback' );
		$GLOBALS['_parent_pages'][ $menu_slug ] = $parent_slug;

		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'remove_submenu_page' ) ) {
	function remove_submenu_page( $parent_slug, $menu_slug ) {
		$GLOBALS['ob_removed_menu'][] = $menu_slug;

		return false;
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class, $fallback = '' ) {
		$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );

		return '' === $class ? $fallback : $class;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return (bool) $GLOBALS['ob_doing_ajax'];
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $context = 'display' ) {
		return str_replace( '&', '&#038;', (string) $url );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['ob_styles'][ $handle ] = $src;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['ob_scripts'][ $handle ] = $src;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_scripts' ) ) {
	/*
	 * The kit adds the handles a screen's fields need to its own script's
	 * dependencies, which means reading the registered script back. Nothing
	 * here asserts on the result, so a registry that knows nothing is enough
	 * — but it has to answer rather than not exist.
	 */
	function wp_scripts() {
		return new class() {

			/**
			 * A registered script, or false.
			 *
			 * @param string $handle The handle.
			 * @param string $status Which list to look in.
			 *
			 * @return false
			 */
			public function query( $handle, $status = 'registered' ) {
				return false;
			}
		};
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		return isset( $GLOBALS['ob_styles'][ $handle ] );
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		return isset( $GLOBALS['ob_scripts'][ $handle ] );
	}
}

/*
 * Enough of the path helpers for the asset loaders to work out a URL for a
 * file inside vendor/. They only ever concatenate and compare, so a stub that
 * agrees with itself is enough.
 */
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return preg_replace( '|(?<=.)/+|', '/', $path ) ?? $path;
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.test/wp-content/plugins/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The parts of WP_Error a validator and a controller use: several codes,
	 * each with its messages, and data.
	 */
	class WP_Error {

		/**
		 * Messages by code.
		 *
		 * @var array<string, string[]>
		 */
		public array $errors = [];

		/**
		 * Data by code.
		 *
		 * @var array<string, mixed>
		 */
		public array $error_data = [];

		/**
		 * Construct.
		 *
		 * @param string|int $code    Its code.
		 * @param string     $message Its message.
		 * @param mixed      $data    Its data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== (string) $code ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Add a message.
		 *
		 * @param string|int $code    Its code.
		 * @param string     $message Its message.
		 * @param mixed      $data    Its data.
		 *
		 * @return void
		 */
		public function add( $code, $message, $data = '' ): void {
			$this->errors[ (string) $code ][] = (string) $message;

			if ( '' !== $data ) {
				$this->error_data[ (string) $code ] = $data;
			}
		}

		/**
		 * Every code.
		 *
		 * @return string[]
		 */
		public function get_error_codes(): array {
			return array_map( 'strval', array_keys( $this->errors ) );
		}

		/**
		 * The first code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->get_error_codes()[0] ?? '';
		}

		/**
		 * One code's messages, or every message.
		 *
		 * @param string|int $code The code.
		 *
		 * @return string[]
		 */
		public function get_error_messages( $code = '' ): array {
			if ( '' === (string) $code ) {
				return array_merge( [], ...array_values( $this->errors ) );
			}

			return $this->errors[ (string) $code ] ?? [];
		}

		/**
		 * One message.
		 *
		 * @param string|int $code The code. The first by default.
		 *
		 * @return string
		 */
		public function get_error_message( $code = '' ): string {
			$code = '' === (string) $code ? $this->get_error_code() : (string) $code;

			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * One code's data.
		 *
		 * @param string|int $code The code. The first by default.
		 *
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			$code = '' === (string) $code ? $this->get_error_code() : (string) $code;

			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'remove_all_actions' ) ) {
	function remove_all_actions( $hook, $priority = false ) {
		unset( $GLOBALS['ob_hooks'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['ob_user_id'];
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (int) $GLOBALS['ob_user_id'] > 0;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		return $GLOBALS['ob_user_meta'][ (int) $user_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value, $previous = '' ) {
		$GLOBALS['ob_user_meta'][ (int) $user_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		unset( $GLOBALS['ob_user_meta'][ (int) $user_id ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route_namespace, $route, $args = [], $override = false ) {
		$GLOBALS['ob_routes'][ $route_namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() {
		return is_user_logged_in() ? 403 : 401;
	}
}

if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	function wp_add_dashboard_widget( $widget_id, $widget_name, $callback, $control_callback = null, $callback_args = null, $context = 'normal', $priority = 'core' ) {
		$GLOBALS['ob_widgets'][ $widget_id ] = [
			'title'    => $widget_name,
			'callback' => $callback,
		];
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The method constants a route names.
	 */
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Parameters, and nothing else a controller here reads.
	 */
	class WP_REST_Request {

		/**
		 * The parameters.
		 *
		 * @var array<string, mixed>
		 */
		private array $params;

		/**
		 * Construct.
		 *
		 * @param array<string, mixed> $params The parameters.
		 */
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}

		/**
		 * One parameter.
		 *
		 * @param string $key The parameter.
		 *
		 * @return mixed
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * A body and a status.
	 */
	class WP_REST_Response {

		/**
		 * The body.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * The status.
		 *
		 * @var int
		 */
		private int $status;

		/**
		 * Construct.
		 *
		 * @param mixed $data   The body.
		 * @param int   $status The status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * The body.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * The status.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}
	}
}
