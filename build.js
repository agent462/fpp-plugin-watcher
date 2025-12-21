/**
 * Build script for watcher.js bundle
 *
 * Usage:
 *   node build.js          - Build minified production bundle
 *   node build.js --watch  - Watch mode (rebuilds on changes)
 *   node build.js --dev    - Development build with sourcemaps (local use only)
 *
 * Note: FPP auto-loads ALL .js files in the js/ directory, so we only build
 * watcher.min.js to avoid duplicate loading. Use --dev locally for debugging.
 */

const esbuild = require('esbuild');

const isWatch = process.argv.includes('--watch');
const isDev = process.argv.includes('--dev');

const common = {
  entryPoints: ['js/src/index.js'],
  bundle: true,
  target: ['es2018'],  // Good browser support
  format: 'iife',      // Self-executing for browser
  globalName: 'Watcher',
};

async function build() {
  try {
    // Production build (always created)
    const prodOptions = {
      ...common,
      outfile: 'js/watcher.min.js',
      minify: true,
      sourcemap: false,
    };

    // Development build (local use only - not committed)
    const devOptions = {
      ...common,
      outfile: 'js/watcher.dev.js',
      sourcemap: true,
    };

    if (isWatch) {
      // Watch mode - production build
      const ctx = await esbuild.context(prodOptions);
      await ctx.watch();
      console.log('Watching for changes... (output: js/watcher.min.js)');
    } else if (isDev) {
      // Development build with sourcemaps
      await esbuild.build(devOptions);
      console.log('Development build complete:');
      console.log('  - js/watcher.dev.js (with sourcemaps)');
      console.log('Note: This file is for local debugging only. Do not commit.');
    } else {
      // Standard production build
      await esbuild.build(prodOptions);
      console.log('Build complete:');
      console.log('  - js/watcher.min.js');
    }
  } catch (error) {
    console.error('Build failed:', error);
    process.exit(1);
  }
}

build();
