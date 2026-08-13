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

Базовий URL: `/wp-json/coins/v1`

### Монети

| Метод | URL | Auth | Опис |
|---|---|---|---|
| `GET` | `/coins` | — | Список монет з фільтрами |
| `GET` | `/coins/{id}` | — | Одна монета повністю |
| `GET` | `/coins/{id}/price-history` | — | Історія цін монети |

**GET `/coins` — параметри:**

| Параметр | Тип | За замовч. | Опис |
|---|---|---|---|
| `page` | int | `1` | Номер сторінки |
| `per_page` | int | `20` | Кількість (макс. 100) |
| `search` | string | — | Повнотекстовий пошук |
| `orderby` | string | `date` | `date`, `title`, `modified` |
| `order` | string | `DESC` | `ASC` або `DESC` |
| `coin_quality` | int\|list | — | Term ID через кому: `?coin_quality=5,6` |
| `coin_material` | int\|list | — | Term ID |
| `coin_series` | int\|list | — | Term ID |
| `coin_color` | int\|list | — | Term ID |
| `coin_packaging` | int\|list | — | Term ID |
| `coin_denomination` | int\|list | — | Term ID |

**Відповідь `/coins`:**
```json
{
  "total": 1117,
  "total_pages": 56,
  "page": 1,
  "per_page": 20,
  "items": [
    {
      "id": 123,
      "title": "Архістратиг Михаїл",
      "issue_date": "2025-12-29",
      "thumbnail": "https://...",
      "taxonomies": { "coin_quality": [...], "coin_material": [...] }
    }
  ]
}
```

**Відповідь `/coins/{id}`:**
```json
{
  "id": 123,
  "title": "Архістратиг Михаїл",
  "issue_date": "2025-12-29",
  "diameter_mm": 38.6,
  "mintage_declared": 5000,
  "mintage_actual": null,
  "booklet_url": "https://...",
  "description_html": "<p>...</p>",
  "designers": [{ "id": 45, "name": "Іваненко І.І." }],
  "gallery": [{ "id": 78, "url": "https://...", "medium": "https://..." }],
  "taxonomies": { ... }
}
```

### Колекція користувача _(потребує авторизації)_

| Метод | URL | Опис |
|---|---|---|
| `GET` | `/collection` | Моя колекція |
| `POST` | `/collection` | Додати монету |
| `PATCH` | `/collection/{id}` | Оновити кількість / ціну |
| `DELETE` | `/collection/{id}` | Видалити монету |
| `GET` | `/collection/stats` | Статистика колекції |

**POST `/collection` — тіло:**
```json
{ "coin_id": 123, "quantity": 2, "purchase_price": 1500 }
```

**GET `/collection/stats` — відповідь:**
```json
{ "unique_coins": 42, "total_quantity": 58, "total_spent": 87500.00 }
```

Авторизація через WordPress Application Passwords (`Authorization: Basic base64(login:app_password)`).

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
```

---

## Архітектура

```
inc/
├── App.php                          ← bootstrap
├── Admin/
│   ├── PostTypes/                   ← реєстрація CPT
│   └── ACFFieldsManager/            ← ACF field groups
├── Assets/AssetManager.php
├── Security/CorsService.php
├── Rest/
│   ├── ApiRouter.php
│   └── Controllers/
│       ├── CoinController.php
│       ├── CoinPriceController.php
│       └── CoinCollectionController.php
├── GraphQL/
│   ├── GraphQLRegistrar.php         ← оркестратор
│   ├── CustomTypesRegistrar.php
│   ├── CoinFieldsRegistrar.php
│   ├── DesignerFieldsRegistrar.php
│   └── CollectionQueriesRegistrar.php
└── Console/
    └── FetchNbuDataCommand.php      ← WP-CLI імпортер
```

**Namespace:** `Coins\` → `inc/` (PSR-4, composer autoload)

### Додати новий REST ендпоінт

1. Створити контролер в `inc/Rest/Controllers/` з namespace `Coins\Rest\Controllers\`
2. Зареєструвати маршрут в `ApiRouter::registerRoutes()`

### Додати новий CPT або таксономію

1. Створити реєстратор в `inc/Admin/PostTypes/` з методом `boot()`
2. Створити ACF-менеджер в `inc/Admin/ACFFieldsManager/`
3. Підключити обидва в `App::bootAdmin()`

### Додати GraphQL поля/типи

- Нові типи → `CustomTypesRegistrar::register()`
- Нові поля на `Coin` → `CoinFieldsRegistrar`
- Нові root queries → `CollectionQueriesRegistrar` або новий клас у `inc/GraphQL/`