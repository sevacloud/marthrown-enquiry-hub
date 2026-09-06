/**
 * Jest configuration for the admin UI component tests.
 *
 * Everything comes from `@wordpress/scripts`' own unit config — the WordPress
 * Babel transform, the jsdom environment, the style mock and the console
 * assertions — with one file appended to the setup Jest already runs. Jest
 * merges a preset's `setupFilesAfterEnv` ahead of the ones named here, so the
 * preset's own setup still runs first.
 */
const jestUnitConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...jestUnitConfig,
	setupFilesAfterEnv: [ '<rootDir>/src/testing/setup-jest.js' ],
};
