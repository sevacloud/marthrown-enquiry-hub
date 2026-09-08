<?php
/**
 * Minimal filter registry for the `pure` suite.
 *
 * Several pure classes read site configuration through a WordPress filter and
 * guard the call with `function_exists( 'apply_filters' )`, so with WordPress
 * unloaded they fall back to a default. The Validator's multi-select
 * vocabularies work that way: `Validator::allowed_terms()` asks
 * `meh_enquiry_terms_{taxonomy}` for the permitted values and treats an empty
 * answer as "unconstrained". A property covering the vocabulary rule therefore
 * has to supply a vocabulary, which is what this fake is for.
 *
 * Usage:
 *
 *     FakeFilters::install();                                   // in setUp
 *     FakeFilters::set( 'meh_enquiry_terms_event_type', array( 'wedding' ) );
 *     FakeFilters::uninstall();                                 // in tearDown
 *
 * Loading: the file is picked up by the Composer classmap over tests/, so the
 * global `apply_filters()` shim at the bottom is declared the moment
 * FakeFilters is first referenced. The shim is declared only when WordPress has
 * not already declared one, so a wordpress-suite test loading this file gets the
 * real hook system; there, `set()` registers the value through `add_filter()`
 * instead of through the registry.
 *
 * That second case is why `uninstall()` matters and why it has to undo the real
 * registrations as well as the registry. Running both suites in one process
 * loads WordPress before the pure suite runs, so a pure test's pin becomes a
 * live hook on the same WordPress the wordpress suite then uses — and one that
 * outlived its test would answer for every later test in the process. It has
 * done exactly that: pinned vocabularies made valid intake submissions read as
 * `not_allowed`, and a pinned empty `meh_field_map` emptied the configured
 * mapping, in fourteen tests that each passed on their own.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub\Tests\Fakes {

	/**
	 * Class FakeFilters
	 */
	class FakeFilters {

		/**
		 * Hook name => value the shim answers with.
		 *
		 * @var array
		 */
		protected static $values = array();

		/**
		 * Whether the registry is currently serving answers.
		 *
		 * @var bool
		 */
		protected static $installed = false;

		/**
		 * The real hooks set() registered, as hook name => callbacks.
		 *
		 * Kept so uninstall() can take them off again. Without this, a pin made
		 * with WordPress loaded would outlive the test that made it: the callback
		 * is a closure, so nothing else holds a reference that `remove_filter()`
		 * could be given.
		 *
		 * @var array<string,callable[]>
		 */
		protected static $registered = array();

		/**
		 * Start with an empty registry.
		 *
		 * @return void
		 */
		public static function install() {
			self::uninstall();

			self::$installed = true;
		}

		/**
		 * Clear the registry, so a hook falls back to the value passed to it.
		 *
		 * @return void
		 */
		public static function uninstall() {
			if ( function_exists( 'remove_filter' ) ) {
				foreach ( self::$registered as $hook => $callbacks ) {
					foreach ( $callbacks as $callback ) {
						remove_filter( $hook, $callback );
					}
				}
			}

			self::$registered = array();
			self::$values     = array();
			self::$installed  = false;
		}

		/**
		 * Pin a hook's answer.
		 *
		 * @param string $hook  Hook name.
		 * @param mixed  $value Value the hook answers with.
		 * @return void
		 */
		public static function set( $hook, $value ) {
			self::$values[ $hook ] = $value;
			self::$installed       = true;

			// With a real WordPress loaded the shim below was never declared, so
			// the only way to be heard is the real hook system.
			if ( function_exists( 'add_filter' ) ) {
				$callback = function () use ( $value ) {
					return $value;
				};

				self::$registered[ $hook ][] = $callback;

				add_filter( $hook, $callback, 10, 1 );
			}
		}

		/**
		 * The answer for a hook: the pinned value, or the passed value untouched.
		 *
		 * @param string $hook  Hook name.
		 * @param mixed  $value Value the caller passed in.
		 * @return mixed
		 */
		public static function filtered( $hook, $value ) {
			if ( ! self::$installed || ! array_key_exists( $hook, self::$values ) ) {
				return $value;
			}

			return self::$values[ $hook ];
		}
	}
}

namespace {

	/*
	 * The shim. Declared only in the absence of WordPress, and deliberately no
	 * more than a registry lookup: nothing in the pure suite needs hook
	 * priorities, and pretending to offer them would invite a pure test to lean
	 * on behaviour this file does not really have.
	 */
	if ( ! function_exists( 'apply_filters' ) ) {

		/**
		 * Stand-in for the WordPress function of the same name.
		 *
		 * @param string $hook_name Hook name.
		 * @param mixed  $value     Value to filter.
		 * @param mixed  ...$args   Further arguments, accepted and ignored.
		 * @return mixed
		 */
		function apply_filters( $hook_name, $value = null, ...$args ) {
			return \MarthrownEnquiryHub\Tests\Fakes\FakeFilters::filtered( $hook_name, $value );
		}
	}
}
