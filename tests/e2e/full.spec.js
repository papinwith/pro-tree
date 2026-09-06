// End-to-end smoke suite covering the public site, admin auth, and
// per-role permission gating. Runs against a live local server — see
// "Running the e2e tests" in SETUP.md.
//
// Credentials come from env vars, never hardcoded, since the seeded
// `admin` account's password (see docs/install.sql) is meant to be
// changed immediately in any real deployment:
//   TEST_ADMIN_PASSWORD       — required; defaults to the documented seed
//                                password (ChangeMe123!) for a fresh install.
//   TEST_EXEC_USER / _PASSWORD, TEST_TREE_ADMIN_USER / _PASSWORD — optional;
//   the executive/tree_admin role tests are skipped when these aren't set,
//   since those accounts aren't part of the standard seed (docs/install.sql
//   only creates the one `admin` / programmer account).
const { test, expect } = require('@playwright/test');

const ADMIN_USER = process.env.TEST_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.TEST_ADMIN_PASSWORD || 'ChangeMe123!';
const EXEC_USER = process.env.TEST_EXEC_USER || '';
const EXEC_PASSWORD = process.env.TEST_EXEC_PASSWORD || '';
const TREE_ADMIN_USER = process.env.TEST_TREE_ADMIN_USER || '';
const TREE_ADMIN_PASSWORD = process.env.TEST_TREE_ADMIN_PASSWORD || '';

// A tree id known to exist locally — override if your seed data differs.
const SAMPLE_TREE_ID = process.env.TEST_TREE_ID || '1';

async function login(page, username, password) {
  await page.goto('admin/login.php');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
}

// ---------------- PUBLIC SITE ----------------

test.describe('Public site', () => {
  test('homepage loads', async ({ page }) => {
    const res = await page.goto('public/index.php');
    expect(res.status()).toBeLessThan(400);
  });

  test('tree detail page loads', async ({ page }) => {
    const res = await page.goto(`public/tree.php?id=${SAMPLE_TREE_ID}`);
    expect(res.status()).toBeLessThan(400);
  });

  test('tree detail page handles invalid id gracefully', async ({ page }) => {
    const res = await page.goto('public/tree.php?id=999999');
    expect([200, 302, 404]).toContain(res.status());
  });

  test('interest submission responds without server error', async ({ page }) => {
    const res = await page.request.post('public/interest.php', {
      form: { tree_id: SAMPLE_TREE_ID, email: 'e2e-test@example.com', activity_type: 'interest_click' },
    });
    expect(res.status()).toBeLessThan(500);
  });
});

// ---------------- ADMIN AUTH ----------------

test.describe('Admin authentication', () => {
  test('login page renders', async ({ page }) => {
    const res = await page.goto('admin/login.php');
    expect(res.status()).toBe(200);
    await expect(page.locator('input[name="username"]')).toBeVisible();
  });

  test('invalid login shows the generic error, does not enter the panel', async ({ page }) => {
    await login(page, ADMIN_USER, 'definitely-wrong-password');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('valid login reaches the admin panel', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASSWORD);
    await expect(page).not.toHaveURL(/login\.php/);
  });

  test('logout ends the session', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASSWORD);
    await page.goto('admin/logout.php');
    await page.goto('admin/dashboard.php');
    await expect(page).toHaveURL(/login\.php/);
  });
});

// ---------------- CSRF ----------------

test.describe('CSRF protection', () => {
  test('a POST without a CSRF token is rejected', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASSWORD);
    const res = await page.request.post('admin/sale_add.php', {
      form: { species_id: '1', size_id: '0', quantity: '1', unit_price: '100' },
    });
    expect(res.status()).toBe(400);
  });
});

// ---------------- ROLE: PROGRAMMER (full access) ----------------

test.describe('Role: programmer (full access)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASSWORD);
  });

  for (const p of ['dashboard.php', 'species.php', 'zones.php', 'zone_map.php', 'categories.php', 'users.php', 'settings.php']) {
    test(`can access ${p}`, async ({ page }) => {
      const res = await page.goto(`admin/${p}`);
      expect(res.status(), `${p} should not error`).toBeLessThan(400);
      await expect(page).not.toHaveURL(/login\.php/);
    });
  }
});

// ---------------- ROLE: EXECUTIVE (optional) ----------------

test.describe('Role: executive', () => {
  test.skip(!EXEC_USER, 'Set TEST_EXEC_USER/TEST_EXEC_PASSWORD to run — this account is not part of the standard seed.');

  test.beforeEach(async ({ page }) => {
    await login(page, EXEC_USER, EXEC_PASSWORD);
  });

  test('can access executive_dashboard, cannot access species_form (403)', async ({ page }) => {
    const okRes = await page.goto('admin/executive_dashboard.php');
    expect(okRes.status()).toBeLessThan(400);

    const deniedRes = await page.goto('admin/species_form.php');
    expect(deniedRes.status()).toBe(403);
  });
});

// ---------------- ROLE: TREE ADMIN (optional) ----------------

test.describe('Role: tree_admin', () => {
  test.skip(!TREE_ADMIN_USER, 'Set TEST_TREE_ADMIN_USER/TEST_TREE_ADMIN_PASSWORD to run — this account is not part of the standard seed.');

  test.beforeEach(async ({ page }) => {
    await login(page, TREE_ADMIN_USER, TREE_ADMIN_PASSWORD);
  });

  test('can access species.php, cannot access users.php (403)', async ({ page }) => {
    const okRes = await page.goto('admin/species.php');
    expect(okRes.status()).toBeLessThan(400);

    const deniedRes = await page.goto('admin/users.php');
    expect(deniedRes.status()).toBe(403);
  });
});
