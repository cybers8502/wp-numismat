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
wp nbu parse-souvenir --pages=all --force   # re-import coins already marked complete too

# Compute the "Оновлювати з НБУ" flag for every coin from its current data
# (see "NBU import" below). Re-running resets flags an admin set by hand.
wp coins backfill-sync-status

# Repair coin images that point at another coin's file, delete duplicate
# attachments (see "NBU import" below). Dry-run first.
wp coins repair-images --dry-run
wp coins repair-images --coin=5899
wp coins repair-images

# One-time backfill of the coin_year term (from issue_date) for posts that
# predate the taxonomy — the importer does it inline for everything it touches.
wp coins backfill-years --dry-run
wp coins backfill-years

# One-time starting sort position (coin_term_order term meta) for taxonomy
# terms — see "Term ordering" below. Idempotent: a term that already has a
# position keeps it unless --force.
wp coins backfill-term-order --dry-run
wp coins backfill-term-order
wp coins backfill-term-order --taxonomy=coin_year
```

Only registered when `WP_CLI` is defined (see `functions.php`), alongside `InstallSchemaCommand`/`MigratePricesCommand` (schema setup/one-off migration for the `coin_prices` table), `BackfillCoinYearsCommand` and `BackfillTermOrderCommand`. Price import (ua-coins.info and coins.bank.gov.ua) used to run from here too — `FetchUaCoinsPricesCommand`/`ImportNbuArchivePricesCommand` (`wp uacoins import-prices` / `wp nbuarchive import-prices`) — but that's been retired in favor of the standalone [`node-coins-price-parser`](../../../../node-coins-price-parser) repo, which writes directly into the `coin_prices` MySQL table over `mysql2`, bypassing WordPress entirely (faster than `wp_insert_post`/ACF writes per price point). It's a faithful port of the same title-matching logic; `inc/Console/README.md` still documents the sources/matching/storage rules both tools share.

## Architecture

**Namespace:** `Coins\` → maps to `inc/` via PSR-4 (composer.json).

**Bootstrap:** `functions.php` loads `vendor/autoload.php` (PSR-4, generated via `composer dump-autoload`), then calls `(new \Coins\App())->boot()`.

**`App::boot()` wires up:**
- `Assets\AssetManager` — enqueue scripts/styles
- `Security\CorsService` — CORS headers for REST/GraphQL, allowlist from `COINS_ALLOWED_ORIGINS` env var (default: `http://localhost:5173`)
- `Security\ApiGuardService` — anon app-token + rate-limit gate on `/graphql`
- `Admin\ThemeSetupService` — theme support (post-thumbnails)
- `Admin\AdminMenuManager` — WP admin menu customization
- `Taxonomy\TermOrderService` — admin-controlled term order (see "Term ordering" below). Booted outside `bootAdmin()` on purpose: the drag-and-drop UI is only half of it, the other half re-sorts term queries on the public API
- `Admin\PostTypes\CoinPostTypeRegistrar` — registers `coins` CPT + coin taxonomies (`CoinPostTypeRegistrar::taxonomies()` is the shared source of truth for which taxonomies are coin facets)
- `Admin\PostTypes\DesignerPostTypeRegistrar` — registers `designer` CPT
- `Admin\PostTypes\CoinPricePostTypeRegistrar` — registers `coin_price` CPT (admin-only)
- `Admin\PostTypes\CoinCollectionPostTypeRegistrar` — registers `coin_collection` CPT (admin-only)
- `Admin\ACFFieldsManager\CoinACFFieldsManager` — ACF fields for `coins`
- `Admin\ACFFieldsManager\DesignerACFFieldsManager` — ACF fields for `designer`
- `Admin\ACFFieldsManager\CoinCollectionACFFieldsManager` — ACF fields for `coin_collection`
- `Sync\SyncAdmin` — "Синхронізація НБУ" meta box, НБУ column/view on the coin list, Coins → Синхронізація НБУ report page
- `Rest\ApiRouter` — registers REST routes via `rest_api_init`
- `GraphQL\GraphQLRegistrar` — registers GraphQL types/fields/queries/mutations via `graphql_register_types` (only if WPGraphQL is active)
- `Cron\DailyImportScheduler` — schedules the recurring NBU import

## NBU import (`wp nbu parse-souvenir`)

Runs nightly from `cron-coins.sh` (import, then `convert-to-webp.php`). Non-obvious rules, each with
its reason in the class docblock:

- **Complete coins are skipped** (`Sync\SyncStatus`). An existing coin is re-imported — every field
  overwritten — only while it's *pending*: fewer than 2 images, no description or mintage, or issued
  less than `GRACE_DAYS` (60) ago, since NBU adds photos late. The flag (`_nbu_sync_pending`, the
  "Оновлювати з НБУ" checkbox) is re-evaluated after each import and clears itself; an admin can tick
  it to force one more re-import or untick it to freeze a coin. No flag stored counts as pending.
  Diameter/series/denomination are deliberately not required — sets and rolls have none. So hand
  edits to a complete coin survive; there is no per-field locking.
- **Every run is logged** in `{prefix}coin_sync_runs` (`Sync\SyncRunRepository`, created on first
  use) and reported on Coins → Синхронізація НБУ. A run left `running` died mid-way.
- **`short_title` is written once, at creation** — an editorial field, never updated afterwards.
- **Type and packaging come from the title** (`Catalog\CoinTitleClassifier`). Packaging is not a
  type: a coin "у сувенірному пакованні" is `Монета` with packaging `В сувенірному пакуванні`,
  souvenir banknotes are `Банкнота`. `Сувенірна продукція` only applies to «сувенір» outside the
  packaging phrase (none in the catalog today). Both NBU spellings (пакованні / упаковці) count.
- **Images are identified by URL without its query string** (`Media\NbuImageSource`): NBU bumps a
  site-wide `?v=N`, which used to re-download the whole catalog as new attachments. Commemorative
  photos all share the basenames `avers.jpg`/`revers.jpg`, so they're saved as `nbu-{id}-avers.jpg`.
- **`convert-to-webp.php` never reuses an existing `.webp`** — it gets a `-N` suffix. Treating it as
  "already converted" once repointed ~400 attachments at other coins' photos;
  `wp coins repair-images` is what fixed that data.

Coins are edited on the **classic screen, not Gutenberg** (`CoinPostTypeRegistrar::useClassicEditor`):
Gutenberg moves ACF meta boxes with `appendChild`, which reloads the Description field's TinyMCE
iframe blank — the Visual tab randomly showed nothing while the Text tab had the text.

## Data Model

**CPT `coins`** with taxonomies:
- `coin_denomination`, `coin_quality`, `coin_material`, `coin_series`, `coin_edge`, `coin_diameter`, `coin_mintage_declared`, `coin_mintage_actual`, `coin_year`
- `coin_color`, `coin_packaging`, `coin_type`

Several taxonomies deliberately **mirror an ACF field as terms**: `coin_diameter` ← `diameter_mm`, `coin_mintage_declared`/`coin_mintage_actual` ← their meta of the same name, and `coin_year` ← the year part of `issue_date`. The ACF field stays the exact display value; the term exists so clients can list what actually occurs in the catalog (`hideEmpty` + `count`) and filter with `tax_query`, which plain meta can't do efficiently. `FetchNbuDataCommand::fill_meta_acf()` assigns all of them on import — if you add another mirrored facet, assign it there too, and ship a backfill command for the posts that predate it (see `BackfillCoinYearsCommand`). Note none of them re-sync when an editor changes the ACF field in wp-admin; they're import-time only, which is a known gap shared by every mirrored facet.

### Term ordering (`coin_term_order`)

Terms of every coin taxonomy carry an optional integer position in term meta (`coin_term_order`), edited in wp-admin by dragging rows on the taxonomy screen (the handle in the "Порядок" column) or by typing an exact number into the term's edit form. `Taxonomy\TermOrderService` then makes that the **default sort of every term query** via `terms_clauses` — i.e. inside `WP_Term_Query`, not inside GraphQL — so one ordering shows up in the GraphQL root listings (`coinMaterials`, `coinYears`, …), in a coin's own term connections (`wp_get_object_terms`), and in the admin list table alike. Clients don't sort: `expo-numismat`/`r-numismat` send no `orderby` at all, WPGraphQL fills in its own `name`/ASC default, and that exact "caller has no preference" case is what gets rewritten.

- An **explicit** `orderby` is left alone — `where: { orderby: COUNT }`, an admin clicking a sortable column, a plugin asking for `term_id`.
- Terms with no stored position sort **last**, by name, so nothing looks different until someone actually reorders something.
- Defaults come from `TermOrderService::defaultSortKey()`, shared by everything that seeds: years newest-first; `coin_denomination`/`coin_diameter`/`coin_mintage_*` by numeric value (by name, "10 грн" sorts before "2 грн"); `coin_type`/`coin_color`/`coin_packaging` in `CoinPostTypeRegistrar::fixedTerms()`' declared order (Монета first, where the alphabet says Банкнота); everything else by name. `BackfillTermOrderCommand` writes them for an existing install, `created_term` for a new year term, and `seedFixedTerms()` at the moment it inserts a fixed term. All of them go through `seedDefaultOrder()`, which only writes when the term has no position yet — a manual ordering can't be clobbered.
- `GraphQL\TaxonomyGraphQL` adds a `termOrder: Int` field to every coin term type. It exists for clients that had their own sort to unlearn (expo-numismat sorted years newest-first client-side), not because reading the list in order needs it.
- The ordering SQL LEFT JOINs `termmeta` and orders by `COALESCE(CAST(meta_value AS SIGNED), 999999)`, which MySQL rejects under `ONLY_FULL_GROUP_BY` for the `DISTINCT` variants of the term query (`object_ids`, `meta_query`). That's fine because `wpdb` strips `ONLY_FULL_GROUP_BY` from the session — the same reason core's own `ORDER BY t.name` works there — but it's the thing to look at if term queries ever start erroring with "incompatible with DISTINCT".

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
- `TaxonomyGraphQL` — `termOrder` on every coin term type (`CoinMaterial`, `CoinYear`, …), reading the `coin_term_order` meta behind admin-controlled facet ordering

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