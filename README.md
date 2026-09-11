# UA Coins — Backend

WordPress site for the UA Coins numismatic catalog. Application logic lives in
the theme at `wp-content/themes/ua_coins/` (namespace `Coins\`, PSR-4
autoloaded).

Part of a three-repo project:

| Repo | Role |
| --- | --- |
| **`wp-numismat`** (this repo) | WordPress backend — GraphQL (`/graphql`) API, content/admin |
| [`r-numismat`](../r-numismat) | Web app (React/Vite) |
| [`expo-numismat`](../expo-numismat) | Mobile app (Expo/React Native, iOS/Android) |

GraphQL (via [WPGraphQL](https://www.wpgraphql.com/)) is the sole data API —
coins, coin details, price history, per-user collection CRUD. See
[`wp-content/themes/ua_coins/inc/GraphQL/README.md`](wp-content/themes/ua_coins/inc/GraphQL/README.md)
for the field reference.

Anonymous requests must carry an `X-App-Token` header
(`Coins\Security\ApiGuardService` — rate limit + anti-scraping, not an auth
boundary). Logged-in requests authenticate instead with a JWT
`Authorization: Bearer` token issued by the
**wp-graphql-jwt-authentication** plugin (same plugin as the sibling
`wp-brutmaps` project) — see
[`wp-content/themes/ua_coins/inc/Rest/README.md`](wp-content/themes/ua_coins/inc/Rest/README.md)
for how the app token is issued, and `GraphQLRegistrar`/`AuthGraphQL` for
`login`/`register`/`refreshJwtAuthToken`/`logout`.

## Development

All tooling runs from the theme directory:

```bash
cd wp-content/themes/ua_coins
composer install        # dependencies + git hooks (CaptainHook)
composer check           # lint + phpstan + phpunit + phpcs
```

Individual steps: `composer lint`, `composer phpstan`, `composer test`,
`composer phpcs` (autofix: `composer phpcbf`).

**Requirements:** PHP 8.1+, Composer 2.

### Quality gates

| Tool | Config | Checks |
| --- | --- | --- |
| php-parallel-lint | — | syntax |
| PHPStan (level 5) | `phpstan.neon` | types; WP / ACF / WPGraphQL / WP-CLI stubs |
| PHPUnit 10 | `phpunit.xml` | unit tests (`tests/Unit`, Brain Monkey) |
| PHPCS | `phpcs.xml` | PSR-12 + `WordPress.Security` / DB / I18n + PHPCompatibility 8.1+ |

### Git hooks (installed automatically by `composer install`)

- **pre-commit** — lint + phpcs + phpstan on staged PHP files, then phpunit.
- **pre-push** — full `composer check`.

Bypass in an emergency with `git commit --no-verify`.

### CI

`.github/workflows/ci.yml` runs on push to `main` and on pull requests,
matrix PHP 8.1 / 8.4: lint + phpstan + phpunit + phpcs.

### Smoke test

`wp-content/themes/ua_coins/bin/graphql-smoke-test.php` runs a handful of
public, fixture-free GraphQL queries against a *live* bootstrapped WordPress,
to catch schema field collisions and runtime plugins that report "active"
but aren't actually loaded — the two classes of bug unit tests can't catch.
Run it after every deploy:

```bash
wp eval-file wp-content/themes/ua_coins/bin/graphql-smoke-test.php
# or, from the theme directory:
composer smoke-test
```

---

## Environment

This repo does **not** track WordPress core (`wp-admin/`, `wp-includes/`, root
core files) or vendor/public plugin code — both are identical, downloadable
artifacts that only produce churn when committed. Reproduce them instead of
tracking them:

- **WordPress core:** currently `6.9.1`. `wp core download --version=6.9.1`.
- **Plugins:** managed via Composer + [WPackagist](https://wpackagist.org)
  (the wordpress.org plugin mirror as Composer packages) for `wp-graphql`,
  plus one GitHub-sourced plugin (`wp-graphql/wp-graphql-jwt-authentication`)
  via a VCS repository. Run `composer install` (from the repo root) to fetch
  them into `wp-content/plugins/` (routed there by `composer/installers`).
  Bump a version with `composer require wpackagist-plugin/<slug>:^X.Y.Z`.

  `advanced-custom-fields-pro` isn't on wordpress.org/WPackagist
  (licensed/marketplace-only) and stays manual — see
  [`wp-content/plugins.lock.json`](wp-content/plugins.lock.json) for every
  plugin's slug/version/source, composer-managed or not. Regenerate it after
  any plugin update: `wp plugin list --format=json`.

Unlike `wp-brutmaps`, no project-written custom plugins exist here — all
custom code lives in the theme (`wp-content/themes/ua_coins`), which is
tracked in this repo. Media (`wp-content/uploads`) is not tracked in git.
