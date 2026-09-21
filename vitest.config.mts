import path from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	resolve: {
		alias: {
			'@': path.resolve( __dirname, 'src/' ),
		},
	},
	test: {
		environment: 'happy-dom',
		exclude: [
			'**/.claude/**',
			'**/.playwright-mcp/**',
			'**/.scratch/**',
			'**/build/**',
			'**/node_modules/**',
			'**/tests/e2e/**',
			'**/vendor/**',
		],
		setupFiles: [ './tests/src/vitest.setup.ts' ],
		globals: true,
		coverage: {
			provider: 'v8',
			reporter: [ 'text', 'html', 'clover', 'json' ],
			reportsDirectory: './coverage/vitest',
			include: [ 'src/**/*.{ts,tsx}' ],
			exclude: [
				'src/**/*.test.{ts,tsx}',
				'src/**/*.spec.{ts,tsx}',
				'src/index.tsx',
				'src/history.tsx',
			],
		},
	},
} );
