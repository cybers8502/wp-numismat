# ua_coins — WordPress Theme

Headless WordPress theme для українського нумізматичного каталогу. Реєструє CPT, таксономії, ACF-поля, REST API, GraphQL та WP-CLI імпортер.

---

## Вимоги

- PHP 8.1+
- WordPress 6.0+
- [ACF Pro](https://www.advancedcustomfields.com/pro/)
- [WPGraphQL](https://www.wpgraphql.com/) _(для GraphQL)_
- WP-CLI _(для імпорту)_

---

## Встановлення

```bash
cd wp-content/themes/ua_coins
composer install
```

---

## WP-CLI — імпорт монет НБУ

```bash
# Всі сторінки
wp nbu parse-souvenir --pages=all

# Діапазон сторінок, 100 монет на сторінку
wp nbu parse-souvenir --pages=1-3 --per-page=100

# Одна сторінка, ліміт 5 записів
wp nbu parse-souvenir --pages=1 --per-page=5 --limit=5

# Dry run — без запису в БД
wp nbu parse-souvenir --pages=1 --dry-run
```

Парсер завантажує монети з `bank.gov.ua` через AJAX POST (`/ua/component/source/searchSouvenierCoinResult`), визначає пакування та завантажує зображення з перевіркою дублікатів.

---

## WP-CLI — імпорт історії цін з ua-coins.info

```bash
# Прев'ю без запису в БД
wp uacoins import-prices --dry-run

# Одна монета
wp uacoins import-prices --post_id=169

# Весь каталог coins
wp uacoins import-prices
```

Матчить наші `coins`-пости з монетами на ua-coins.info за схожістю назви (кешує в `_uacoins_id`), скрейпить сторінку монети за підписаним посиланням на ціни, пише в CPT `coin_price`. Повна документація джерела, матчингу, збереження і відомих обмежень — `inc/Console/README.md`.

---

## Дата модель

### CPT `coins`

| Таксономія | Slug | Тип |
|---|---|---|
| Denomination | `coin_denomination` | flat |
| Quality | `coin_quality` | flat |
| Material | `coin_material` | flat |
| Series | `coin_series` | flat |
| Edge | `coin_edge` | flat |
| Diameter | `coin_diameter` | flat |
| Mintage declared | `coin_mintage_declared` | flat |
| Mintage actual | `coin_mintage_actual` | flat |
| Color | `coin_color` | flat |
| Packaging | `coin_packaging` | flat |
| Type | `coin_type` | flat |

ACF-поля: `issue_date`, `diameter_mm`, `mintage_declared`, `mintage_actual`, `booklet_url`, `description_html` (wysiwyg), `designers` (relationship), `images_gallery`

Фіксовані терміни `coin_color`: `Кольорова`, `Некольорова`
Фіксовані терміни `coin_packaging`: `Без пакування`, `В сувенірному пакуванні`, `Набір`, `Ролик`
Фіксовані терміни `coin_type`: `Монета`, `Банкнота`, `Сувенірна продукція`, `Медаль`, `Інвестиційна`

### CPT `designer`

ACF-поля: `full_name`, `note`

### CPT `coin_price` _(адмінка)_

ACF-поля: `coin_id`, `price_date`, `price`, `source`

### CPT `coin_collection` _(адмінка)_

ACF-поля: `user_id`, `coin_id`, `quantity`, `purchase_price`

---

## REST API

Уся дані (монети, колекція) обслуговуються через GraphQL — REST лишився лише для видачі анонімного app-токена, який потрібен, щоб взагалі достукатись до `/graphql` (див. розділ "Захист API" нижче).

| Метод | URL | Auth | Опис |
|---|---|---|---|
| `GET` | `/wp-json/coins/v1/app-token` | — | Видає короткоживий `X-App-Token` |

Роути `/coins`, `/coins/{id}`, `/coins/{id}/price-history`, `/collection*` існували раніше, але видалені — жоден клієнт (`r-numismat`, `expo-numismat`, telegram-бот) ними не користувався, усі йдуть через GraphQL.

---

## GraphQL

Ендпоінт: `/wp-json/graphql` (після активації плагіну WPGraphQL)

### Монети

```graphql
# Список з пагінацією та фільтрами
query {
  coins(first: 20, where: { coinQualityIn: [5], search: "архангел" }) {
    nodes {
      databaseId
      title
      issueDate
      diameterMm
      mintageDeclared
      bookletUrl
      gallery { id url medium }
      designers { title fullName note }
      priceHistory { date price source }
      coinQualities { nodes { name } }
      coinMaterials { nodes { name } }
      coinPackagings { nodes { name } }
      coinColors { nodes { name } }
      coinTypes { nodes { name } }
    }
  }
}

# Одна монета
query {
  coin(id: 123, idType: DATABASE_ID) {
    title
    issueDate
    descriptionHtml
    gallery { url medium }
    priceHistory { date price source }
  }
}
```

### Колекція _(потребує авторизації)_

```graphql
query {
  myCollection {
    id
    coinId
    coinTitle
    coinThumbnail
    quantity
    purchasePrice
  }

  myCollectionStats {
    uniqueCoins
    totalQuantity
    totalSpent
  }
}

mutation {
  addToCollection(input: { coinId: 123 }) {
    success
  }
}

mutation {
  updateCollectionItem(input: { id: 45, quantity: 3, purchasePrice: 1500 }) {
    item {
      id
      quantity
      purchasePrice
    }
  }
}

mutation {
  deleteCollectionItem(input: { id: 45 }) {
    success
    id
  }
}
```

---

## Захист API від анонімного скрапінгу

Каталог монет лишається доступним без логіну, але прямі HTTP-запити ботів/скраперів блокуються. Захист складається з трьох шарів, застосованих до `/graphql` (єдиний ендпоінт з даними — REST лишив тільки видачу токена):

1. **CORS** (`Security\CorsService`) — `Access-Control-Allow-Origin` виставляється лише для origin-ів зі списку `COINS_ALLOWED_ORIGINS` (`.env`, через кому). За замовчуванням — `http://localhost:5173` (dev-сервер `r-numismat`). **Для продакшену обов'язково додати реальний домен фронтенду в `.env`.**
2. **App-токен** (`Security\AppTokenService` + `Security\ApiGuardService`) — веб-застосунок один раз за сесію викликає `GET /wp-json/coins/v1/app-token`, отримує токен на 45 хв, і передає його в заголовку `X-App-Token` на кожному запиті до `/graphql`. Запити без валідного токена отримують `401`.
3. **Rate limiting** (`Security\RateLimiter`) — по IP, окремо для видачі токена (10/хв) і для захищених ендпоінтів (60/хв). Перевищення — `429`.

Запити з заголовком `Authorization` (JWT / Application Passwords) пропускаються без app-токена — залогінений користувач уже підтвердив особу сильнішим механізмом.

**Важливо:** ці механізми не дають криптографічної гарантії — вони підіймають вартість скрапінгу (Origin-перевірка, дворівневий флоу, rate limit), а не унеможливлюють його повністю. Для протидії вмотивованому скраперу з headless-браузером потрібен захист на рівні edge (Cloudflare bot management тощо).

`r-numismat` вже реалізує цей флоу (`src/lib/appToken.ts` + Apollo-лінки в `src/lib/apollo.ts`) — будь-який новий клієнт має зробити те саме: отримати токен через `GET /coins/v1/app-token` і передавати `X-App-Token` у кожному GraphQL-запиті.

---

## Архітектура

```
inc/
├── App.php                          ← bootstrap
├── Admin/
│   ├── PostTypes/                   ← реєстрація CPT
│   └── ACFFieldsManager/            ← ACF field groups
├── Assets/AssetManager.php
├── Security/
│   ├── CorsService.php        ← CORS allowlist (COINS_ALLOWED_ORIGINS)
│   ├── ApiGuardService.php    ← app-token + rate limit gate на /graphql
│   ├── AppTokenService.php    ← видача/валідація анонімних app-токенів
│   └── RateLimiter.php        ← generic per-key rate limiter (transient-based)
├── Rest/                      ← див. inc/Rest/README.md
│   ├── ApiRouter.php
│   └── Controllers/
│       └── AppTokenController.php   ← GET /app-token (єдиний REST-роут)
├── GraphQL/
│   ├── GraphQLRegistrar.php   ← оркестратор
│   ├── CoinGraphQL.php        ← типи CoinGalleryImage/CoinPriceEntry, поля на Coin, gallery, designers, priceHistory
│   ├── DesignerGraphQL.php    ← поля на Designer (fullName, note)
│   ├── CollectionGraphQL.php  ← типи, myCollection/myCollectionStats, addToCollection/updateCollectionItem/deleteCollectionItem
│   └── AuthGraphQL.php        ← logout
└── Console/                   ← див. inc/Console/README.md
    ├── FetchNbuDataCommand.php        ← WP-CLI імпортер монет (bank.gov.ua)
    └── FetchUaCoinsPricesCommand.php  ← WP-CLI імпортер цін (ua-coins.info)
```

**Namespace:** `Coins\` → `inc/` (PSR-4, composer autoload)

### REST і GraphQL

Детальна документація кожного шару — в `inc/Rest/README.md` та `inc/GraphQL/README.md` (як додати роут/тип/мутацію, конвенції, приклади).

### Додати новий CPT або таксономію

1. Створити реєстратор в `inc/Admin/PostTypes/` з методом `boot()`
2. Створити ACF-менеджер в `inc/Admin/ACFFieldsManager/`
3. Підключити обидва в `App::bootAdmin()`