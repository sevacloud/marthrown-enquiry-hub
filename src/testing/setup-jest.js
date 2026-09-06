/**
 * Test support: additional Jest setup, run after `@wordpress/jest-preset-default`.
 *
 * That preset turns any unexpected `console.warn` into a test failure, which is
 * worth keeping. `@wordpress/components` emits one warning through
 * `@wordpress/deprecated` for every control that has not opted into the 40px
 * default size, so rendering any of this plugin's forms trips it before a single
 * assertion runs.
 *
 * Whether those controls should opt in is a decision about how the admin screens
 * look, not something these tests are placed to settle, so the notice is stubbed
 * out here rather than answered by changing the components. Every other warning
 * — and every `console.error`, which is how React reports a mistake in a test —
 * still fails the suite.
 */
jest.mock( '@wordpress/deprecated', () => ( {
	__esModule: true,
	default: () => {},
	logged: {},
} ) );
