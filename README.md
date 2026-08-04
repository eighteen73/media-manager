# Media Manager

Native media folders, private media, shared multisite media, and safety-first cleanup for WordPress.

## Requirements

- WordPress 6.8+
- PHP 7.4+
- Node 20+ for development builds
- Docker (required by `@wordpress/env`) for development testing

## Development

```bash
composer install
npm install
npm run build
npm run env:start
npm run test:unit
npm run test:php
npm run test:e2e
```

| Script | Purpose |
|--------|---------|
| `npm run env:start` | Start Docker wp-env (dev `:8888`, tests `:8889`) |
| `npm run env:stop` | Stop wp-env |
| `npm run env:destroy` | Destroy containers and volumes (needed after env config changes) |
| `npm run test:unit` | Jest unit/component tests (no WordPress required) |
| `npm run test:php` | PHPUnit integration tests inside wp-env `tests-cli` |
| `npm run test:e2e` | Playwright E2E against the tests site (`:8889`) |
| `npm run test:e2e:ui` | Playwright UI mode |
| `npm run test` | Run unit, PHP, then E2E |
| `npm run lint` | CSS/JS/PHP lint |
| `composer test` | PHPUnit only (expects WordPress test lib; prefer `npm run test:php` on the host) |

Default wp-env credentials: `admin` / `password`.

`npm run build` must succeed before E2E. First-time Playwright setup:

```bash
npm run playwright:install
```

E2E global setup authenticates as admin and activates Media Manager on the tests site (`:8889`).

## Testing standard (eighteen73)

This plugin is the reference layout for other eighteen73 WordPress plugins.

### Layout

```
tests/
  php/           # WP_UnitTestCase integration (PHPUnit)
    bootstrap.php
    {Area}/test-*.php
  e2e/           # Playwright browser specs + global-setup.js
  fixtures/      # Seeds / helpers (not a suite)
src/**/*.test.js # Jest co-located with admin JS
```

### Runtimes

| Layer | Runner | Environment |
|-------|--------|-------------|
| PHP | PHPUnit + `WP_UnitTestCase` | Docker **wp-env** `tests-cli` |
| JS | Jest via `@wordpress/scripts` | Node only |
| E2E | Playwright + `@wordpress/e2e-test-utils-playwright` | wp-env **tests** site `http://localhost:8889` |

**Keep Docker wp-env** for PHP and E2E when the plugin needs MySQL, multisite, or real filesystem behavior (private media, shared network media).

**WordPress Playground** is complementary — demos, Blueprints, and PR previews — not a drop-in replacement for this plugin’s PHP/E2E suite:

```bash
npx @wp-playground/cli server --auto-mount
```

### Multisite note

- Development site (`:8888`) is multisite for shared-media work.
- Tests site (`:8889`) stays single-site at the wp-env layer so PHPUnit can install its own network via `WP_TESTS_MULTISITE` (avoids the known wp-env multisite + PHPUnit domain conflict).
- After changing [`.wp-env.json`](.wp-env.json), run `npm run env:destroy && npm run env:start`.

### Writing tests

- **PHP:** extend `WP_UnitTestCase`, name methods `test_*`, load the plugin from [`tests/php/bootstrap.php`](tests/php/bootstrap.php).
- **JS:** co-locate `*.test.js` next to the module; use Testing Library for components.
- **E2E:** import `{ test, expect }` from `@wordpress/e2e-test-utils-playwright`, use `admin.visitAdminPage()`, prefer role/label locators, add `data-testid` on plugin UI when needed.

## License

GPL-2.0-or-later
