# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress theme called **ua_coins** for a Ukrainian numismatic (coins) catalog site. It functions primarily as a headless backend — registering custom post types, taxonomies, ACF fields, a REST API, a GraphQL API (via WPGraphQL), and a WP-CLI importer. There is minimal frontend (index.php, page.php, single.php are nearly empty shells).

## WP-CLI Commands

```bash
# Import souvenir coins from the National Bank of Ukraine (NBU) website
wp nbu parse-souvenir --pages=all
wp nbu parse-souvenir --pages=1-3 --per-page=100
wp nbu parse-souvenir --pages=1 --per-page=5 --limit=1
wp nbu parse-souvenir --pages=1 --dry-run   # preview without writing to DB
```

Only registered when `WP_CLI` is defined (see `functions.php`), alongside `InstallSchemaCommand`/`MigratePricesCommand` (schema setup/one-off migration for the `coin_prices` table). Price import (ua-coins.info and coins.bank.gov.ua) used to run from here too — `FetchUaCoinsPricesCommand`/`ImportNbuArchivePricesCommand` (`wp uacoins import-prices` / `wp nbuarchive import-prices`) — but that's been retired in favor of the standalone [`node-coins-price-parser`](../../../../node-coins-price-parser) repo, which writes directly into the `coin_prices` MySQL table over `mysql2`, bypassing WordPress entirely (faster than `wp_insert_post`/ACF writes per price point). It's a faithful port of the same title-matching logic; `inc/Console/README.md` still documents the sources/matching/storage rules both tools share.

## Architecture

**Namespace:** `Coins\` → maps to `inc/` via PSR-4 (composer.json).

**Bootstrap:** `functions.php` loads `vendor/autoload.php` (PSR-4, generated via `composer dump-autoload`), then calls `(new \Coins\App())->boot()`.

**`App::boot()` wires up:**
- `Assets\AssetManager` — enqueue scripts/styles
- `Security\CorsService` — CORS headers for REST/GraphQL, allowlist from `COINS_ALLOWED_ORIGINS` env var (default: `http://localhost:5173`)
- `Security\ApiGuardService` — anon app-token + rate-limit gate on `/graphql`
- `Admin\ThemeSetupService` — theme support (post-thumbnails)
- `Admin\AdminMenuManager` — WP admin menu customization
- `Admin\PostTypes\CoinPostTypeRegistrar` — registers `coins` CPT + coin taxonomies
- `Admin\PostTypes\DesignerPostTypeRegistrar` — registers `designer` CPT
- `Admin\PostTypes\CoinPricePostTypeRegistrar` — registers `coin_price` CPT (admin-only)
- `Admin\PostTypes\CoinCollectionPostTypeRegistrar` — registers `coin_collection` CPT (admin-only)
- `Admin\ACFFieldsManager\CoinACFFieldsManager` — ACF fields for `coins`
- `Admin\ACFFieldsManager\DesignerACFFieldsManager` — ACF fields for `designer`
- `Admin\ACFFieldsManager\CoinCollectionACFFieldsManager` — ACF fields for `coin_collection`
- `Rest\ApiRouter` — registers REST routes via `rest_api_init`
- `GraphQL\GraphQLRegistrar` — registers GraphQL types/fields/queries/mutations via `graphql_register_types` (only if WPGraphQL is active)
- `Cron\DailyImportScheduler` — schedules the recurring NBU import

## Data Model

**CPT `coins`** with taxonomies:
- `coin_denomination`, `coin_quality`, `coin_material`, `coin_series`, `coin_edge`, `coin_diameter`, `coin_mintage_declared`, `coin_mintage_actual`
- `coin_color`, `coin_packaging`, `coin_type`

**ACF fields on `coins`:** `issue_date`, `diameter_mm`, `quality`, `edge`, `designers` (relationship to `designer` CPT), `mintage_declared`, `mintage_actual`, `booklet_url`, `description_html` (wysiwyg), `images_gallery`

**CPT `designer`** — linked from coins via ACF relationship field (`designers`). ACF fields: `full_name`, `note`.

**`{prefix}coin_prices` table** (not a CPT) — historical price entries, one row per `coin_id`+`source`+`price_date` (unique key), columns `id`/`coin_id`/`source`/`price_date`/`price`/`sku`/`updated_at` — see `Prices\PriceSchema`/`Prices\PriceRepository`. Replaced the old `coin_price` CPT (post-per-price-point was too slow to import at scale). Populated by the external [`node-coins-price-parser`](../../../../node-coins-price-parser) repo, not by anything in this theme.

**CPT `coin_collection`** _(admin-only)_ — one post per (user, coin) pair in a user's collection. ACF fields: `user_id`, `coin_id`, `quantity`, `purchase_price`.

## REST vs GraphQL

GraphQL (`inc/GraphQL/`) is the sole data API — coins, coin details, price history, per-user collection CRUD. REST (`inc/Rest/`) only issues the anonymous app-token needed to call GraphQL (see below); it used to also expose `coins/v1/coins*` and `coins/v1/collection*` routes, but those were deleted (no known consumer — every client, including `expo-numismat` and the Telegram bot, only ever called GraphQL). Detailed per-layer docs: `inc/Rest/README.md`, `inc/GraphQL/README.md`.

### GraphQL (`inc/GraphQL/`)

Flat structure, one class per domain — deliberately mirrors the sister project `wp-brutmaps`'s `inc/GraphQL/` layout (`AuthGraphQL`, `ObjectsGraphQL`) instead of splitting into `Types/Fields/Queries/Mutations` subfolders. Each domain class exposes a single public `registerTypes(): void` entry point; internally it has private methods to register shared object types, fields, queries, and mutations, and public `resolveX()` methods (referenced as `[$this, 'resolveX']`) that hold the actual resolver logic.

Registered from `GraphQLRegistrar::register()`, hooked on `graphql_register_types`:
- `CoinGraphQL` — object types `CoinGalleryImage`/`CoinPriceEntry`/`CoinPriceStats`; extra fields on `Coin` (ACF-backed: `issueDate`, `bookletUrl`, `descriptionHtml`, `diameterMm`, `mintageDeclared`, `mintageActual`, `gallery`, `designersArtist/Designer/Adaptation/Sculptor`, `priceHistory`, `priceStats(days: Int)` — aggregates over `priceHistory`: latest price/trend, comparison vs. last-known NBU price, min/max/delta over the period)
- `DesignerGraphQL` — extra fields on `Designer` (`fullName`, `note`)
- `CollectionGraphQL` — object types `CollectionItem`/`CollectionStats`/`AddToCollectionPayload`/`DeleteCollectionItemPayload`; root queries `myCollection`, `myCollectionStats`; mutations `addToCollection`, `updateCollectionItem`, `deleteCollectionItem`
- `AuthGraphQL` — mutation `logout` (JWT secret revocation; `refreshJwtAuthToken` comes from the wp-graphql-jwt-authentication plugin, not this theme)

Collection mutations/queries throw `\GraphQL\Error\UserError` for auth/ownership failures (logged in + `coin_collection.user_id` must match the current user — see `CollectionGraphQL::authorizeItem()`).

To add a new registrar: create `inc/GraphQL/NewDomainGraphQL.php` with a `registerTypes()` method, then instantiate it and call `->registerTypes()` inside `GraphQLRegistrar::register()`. Prefer extending an existing domain class over creating a new one for closely related fields/queries/mutations (e.g. a new field on `Coin` goes in `CoinGraphQL`, not a new file).

### REST (`inc/Rest/`)

Just `ApiRouter` + `Controllers\AppTokenController`, registering `GET coins/v1/app-token`. Only add a new REST route here if it genuinely can't be a GraphQL field/query/mutation (e.g. it must run outside the GraphQL schema, like the app-token endpoint itself).

## API access control (anti-scraping, not auth)

Goal: keep the catalog browsable without login, but make it costly for anonymous bots/scrapers to hit `/graphql` directly. This is **not** a hard security boundary — see `README.md`'s "Захист API від анонімного скрапінгу" section for the full rationale and tradeoffs.

- `Security\CorsService` — only echoes `Access-Control-Allow-Origin` for origins in `COINS_ALLOWED_ORIGINS` (comma-separated env var; falls back to the `r-numismat` dev origin `http://localhost:5173`). **Set this in production `.env` or the real frontend domain gets silently CORS-blocked.**
- `Security\AppTokenService` — issues/validates short-lived (45 min) anonymous tokens via WP transients. Not a secret in any cryptographic sense (visible in browser devtools) — it just enforces a two-step flow.
- `Security\ApiGuardService` — hooked on `rest_pre_dispatch`; for unauthenticated requests to `/graphql` (i.e. no `Authorization` header — real logged-in users/bots are exempt), requires a valid `X-App-Token` header and enforces per-IP rate limits (`Security\RateLimiter`, transient-based). Returns `401 missing_app_token` / `429 rate_limited` on failure.
- `Rest\Controllers\AppTokenController` — `GET /coins/v1/app-token`, itself rate-limited (10/min/IP), returns `{ token, expires_in }`.

**Every anonymous GraphQL caller must fetch a token first and send it as `X-App-Token` on every request** — `r-numismat` implements this (`src/lib/appToken.ts` + Apollo links in `src/lib/apollo.ts`). `node-coin-telegram-bot`'s unauthenticated catalog calls (`searchCoins`, `getCoinDetails`, `getPriceHistory`, `getCoinGallery` in `app/api.js`) do **not** yet — they will start failing with `401` once this deploys, until updated the same way. Its authenticated calls (`getCollection`, `addToCollection`, etc., which already send `Authorization: Bearer`) are unaffected.

## Adding New Post Types or ACF Fields

1. Create a registrar in `inc/Admin/PostTypes/` extending the pattern of existing registrars (implement `boot()` with `add_action('init', ...)`).
2. Create an ACF manager in `inc/Admin/ACFFieldsManager/` using `acf_add_local_field_group()` on `acf/init`.
3. Instantiate both in `App::bootAdmin()`.
4. No `require_once` needed — PSR-4 autoloader handles it.

## Plugin Dependencies

- **ACF Pro** — all custom fields use `acf_add_local_field_group()` / `update_field()`
- **WPGraphQL** + **wp-graphql-jwt-authentication** — required for the GraphQL API and its auth mutations
- **WP-CLI** — required for the NBU importer command