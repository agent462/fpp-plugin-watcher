/** @type {import('jest').Config} */
module.exports = {
  testEnvironment: 'jsdom',
  setupFilesAfterEnv: ['<rootDir>/tests/js/setup.js'],
  testMatch: ['<rootDir>/tests/js/**/*.test.js'],
  collectCoverageFrom: [
    'js/**/*.js',
    '!js/**/*.min.js'
  ],
  coverageDirectory: 'tests/js-coverage',
  coverageReporters: ['text', 'html'],
  verbose: true,
  testTimeout: 10000
};
