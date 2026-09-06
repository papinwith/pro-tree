// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    // Trailing slash matters: URL resolution treats a leading-slash test
    // path (e.g. '/admin/login.php') as relative to the origin root, which
    // silently drops this subpath — so every test path in full.spec.js is
    // written *without* a leading slash (e.g. 'admin/login.php').
    baseURL: (process.env.APP_BASE_URL_ROOT || 'http://localhost/tree-siam-main') + '/',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
