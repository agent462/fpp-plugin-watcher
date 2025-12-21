/** @type {import('jest').Config} */
module.exports = {
  testEnvironment: 'jsdom',
  setupFilesAfterEnv: ['<rootDir>/tests/js/setup.js'],
  testMatch: ['<rootDir>/tests/js/**/*.test.js'],

  // Transform ES modules in js/src/ using Babel
  transform: {
    '^.+\\.js$': 'babel-jest',
  },

  // Don't transform node_modules (default behavior)
  transformIgnorePatterns: [
    '/node_modules/',
  ],

  // Module path mapping for @/ alias to js/src/
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/js/src/$1',
  },

  collectCoverageFrom: [
    'js/**/*.js',
    '!js/**/*.min.js',
    '!js/watcher.js',      // Exclude bundled output
    '!js/watcher.min.js',  // Exclude minified bundle
  ],
  coverageDirectory: 'tests/js-coverage',
  coverageReporters: ['text', 'html', 'lcov'],
  verbose: true,
  testTimeout: 10000
};
