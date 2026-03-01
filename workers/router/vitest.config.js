import { defineWorkersConfig } from '@cloudflare/vitest-pool-workers/config';

export default defineWorkersConfig({
  test: {
    globals: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      include: ['src/**/*.js'],
      exclude: ['src/index.js'],
      thresholds: {
        branches: 80,
        functions: 90,
        lines: 85,
        statements: 85
      }
    }
  }
});
