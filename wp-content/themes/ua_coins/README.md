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

# Оновити й монети, вже позначені повними
wp nbu parse-souvenir --pages=all --force
```

Парсер завантажує монети з `bank.gov.ua` через AJAX POST (`/ua/component/source/searchSouvenierCoinResult`), визначає тип і пакування за назвою та завантажує зображення з перевіркою дублікатів.

**Повні монети не оновлюються.** Існуючу монету імпорт перезаписує лише поки стоїть галочка
«Оновлювати з НБУ» (бічна панель «Синхронізація НБУ» на екрані монети). Галочка ставиться сама,
якщо бракує аверсу/реверсу, опису чи тиражу або з випуску минуло менше 60 днів, і знімається сама,
коли все стягнуто. Адмін може поставити її вручну (перезаписати ще раз) або зняти (більше не чіпати).
Ручні правки повної монети лишаються. `short_title` пишеться лише при створенні.

**Тип — це вміст, а не упаковка:** монета «у сувенірному пакованні» — `Монета` з пакуванням
`В сувенірному пакуванні`, сувенірні банкноти — `Банкнота`.

Кожен запуск пишеться в журнал; звіт (стан каталогу, підсумки по місяцях, останні запуски) —
**Coins → Синхронізація НБУ**. Після першого деплою цієї логіки: `wp coins backfill-sync-status`.

### Ремонт зображень

```bash
wp coins repair-images --dry-run     # що буде змінено
wp coins repair-images --coin=5899   # лише одна монета
wp coins repair-images
```

Перезавантажує з НБУ картинки галерей, що вказують на файл іншої монети, видаляє невикористані
дублікати вкладень і файли, на які ніхто не посилається. Причина була в `convert-to-webp.php`
(вважав чужий `avers.webp` уже сконвертованим) і в `?v=N` в URL НБУ — обидва виправлено.

---

## WP-CLI — бекфіл років (`coin_year`)

```bash
# Подивитись, що зміниться, без запису
wp coins backfill-years --dry-run

# Проставити терміни
wp coins backfill-years

# Менші пачки (за замовчуванням 500 постів на прохід)
wp coins backfill-years --batch=100
```

Проставляє кожній опублікованій монеті термін `coin_year` з року її `issue_date`. Потрібен **один раз** — після деплою таксономії, щоб усі старі пости отримали рік; далі `FetchNbuDataCommand` робить це сам на імпорті. Ідемпотентна: монета, що вже має правильний термін, не перезаписується, тож повторний запуск дешевий і безпечний. Монети без розбірного `issue_date` пропускаються з попередженням, а не вгадуються з `post_date` (для створених вручну постів це дата створення, а не випуску — і монета потрапила б не в той рік).

---

## Порядок термінів у таксономіях (`coin_term_order`)

Порядок, у якому терміни виходять з API (а отже — порядок чипів у фільтрах застосунку), задається в адмінці: **Coins → будь-яка таксономія → перетягнути рядок за ручку в колонці «Порядок»**. Перетягування зберігається одразу (AJAX), без окремої кнопки. Точне число можна вписати й руками — поле «Порядок» на сторінці редагування терміна (менше число = вище; порожнє = в кінець списку).

Як це працює: позиція лежить у метаполі терміна `coin_term_order`, а `Coins\Taxonomy\TermOrderService` робить її **сортуванням за замовчуванням** для будь-якого запиту термінів монетних таксономій (хук `terms_clauses`, тобто сам `WP_Term_Query`). Тому порядок однаковий скрізь: у GraphQL-списках (`coinMaterials`, `coinYears`, …), у термінах конкретної монети (`coin.coinDenominations.nodes`) і в таблиці термінів у самій адмінці. Клієнтам нічого сортувати не треба — `expo-numismat` та `r-numismat` не передають `orderby` взагалі.

Деталі, про які легко забути:

- Явний `orderby` поважається — `where: { orderby: COUNT }` у GraphQL або клік по колонці в адмінці сортують як просили, без нашого порядку.
- Терміни **без** записаної позиції йдуть після всіх впорядкованих, між собою — за назвою. Тобто до першого перетягування (і на свіжому імпорті) списки виглядають рівно так, як виглядали раніше.
- Перетягування недоступне, коли список відфільтровано пошуком або відсортовано по колонці: на екрані тоді не справжній порядок, і записані позиції нічого б не означали.
- Позиції глобальні, а не посторінкові: перетягування на 2-й сторінці пише 20…39, а не починає з нуля.
- Поле `termOrder` у GraphQL віддає цю позицію (`null`, якщо не задана). Списки й так приходять у правильному порядку — поле потрібне клієнту лише щоб зрозуміти, чи порядок узагалі задавали (`expo-numismat` так вирішує, чи можна вимкнути власне сортування років).

### WP-CLI — стартові позиції

```bash
# Подивитись, що зміниться, без запису
wp coins backfill-term-order --dry-run

# Проставити стартовий порядок усім таксономіям
wp coins backfill-term-order

# Тільки одна таксономія
wp coins backfill-term-order --taxonomy=coin_year

# Перенумерувати все «природним» порядком, затерши ручні позиції
wp coins backfill-term-order --force
```

Проставляє «природний» порядок там, де він очевидний: роки — від найновішого; числові таксономії (`coin_denomination`, `coin_diameter`, `coin_mintage_declared`, `coin_mintage_actual`) — за числом, а не за назвою (інакше «10 грн» стоїть перед «2 грн»); `coin_type`/`coin_color`/`coin_packaging` — у тому порядку, у якому вони перелічені в `CoinPostTypeRegistrar::fixedTerms()` (там `Монета` свідомо перша, бо за алфавітом вона четверта); решта — за назвою. Ідемпотентна: термін, у якого позиція вже є, лишається як є (хтось поставив її свідомо), якщо не передати `--force`.

Потрібна **один раз** після деплою — саме для термінів, які вже є в базі. Далі все тримається саме: новий термін `coin_year` отримує позицію на хуку `created_term` (тож імпорт нового року не ламає порядок), а фіксовані терміни — у момент створення в `seedFixedTerms()`.

---

## Імпорт цін

Ціни (`ua-coins.info` та `coins.bank.gov.ua`) більше не імпортуються звідси —
цим займається окремий репозиторій
[`node-coins-price-parser`](../../../../node-coins-price-parser), що пише
напряму в MySQL-таблицю `{prefix}coin_prices` (`PriceRepository`/
`PriceSchema`), оминаючи WordPress повністю. Колишні WP-CLI команди
`wp uacoins import-prices` / `wp nbuarchive import-prices`
(`FetchUaCoinsPricesCommand`/`ImportNbuArchivePricesCommand`) видалені —
node-coins-price-parser є їх прямим портом (той самий матчинг за схожістю
назви, ті самі `source`-теги). Історія джерел, матчингу й відомих обмежень
лишається задокументованою в `inc/Console/README.md` як довідка про те, як
ці дані структуровані.

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
| Year | `coin_year` | flat |
| Color | `coin_color` | flat |
| Packaging | `coin_packaging` | flat |
| Type | `coin_type` | flat |

ACF-поля: `issue_date`, `diameter_mm`, `mintage_declared`, `mintage_actual`, `booklet_url`, `description_html` (wysiwyg), `designers` (relationship), `images_gallery`

`coin_year` дублює **рік** з `issue_date` окремим терміном — так само, як `coin_diameter`/`coin_mintage_*` дублюють свої числові ACF-поля. Сенс той самий: клієнт отримує список років, які реально є в каталозі (`coinYears(where: {hideEmpty: true})`, разом з `count`), і фільтрує по них через `tax_query`, замість вгадувати діапазон по min/max. Термін проставляє `FetchNbuDataCommand` при кожному імпорті/оновленні; для постів, створених до появи таксономії, є `wp coins backfill-years` (див. нижче).

Фіксовані терміни `coin_color`: `Кольорова`, `Некольорова`
Фіксовані терміни `coin_packaging`: `Без пакування`, `В сувенірному пакуванні`, `Набір`, `Ролик`
Фіксовані терміни `coin_type`: `Монета`, `Банкнота`, `Сувенірна продукція`, `Медаль`, `Інвестиційна`
(визначаються за назвою — `Catalog\CoinTitleClassifier`; упаковка на тип не впливає)

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
├── Catalog/
│   ├── CoinTitleClassifier.php      ← тип і пакування монети за назвою НБУ
│   └── SortKeyService.php           ← денормалізовані ключі сортування
├── Media/NbuImageSource.php         ← ідентичність/ім'я файлу картинки НБУ
├── Sync/
│   ├── SyncStatus.php               ← «Оновлювати з НБУ»: правила повноти монети
│   ├── SyncRunRepository.php        ← журнал запусків ({prefix}coin_sync_runs)
│   └── SyncAdmin.php                ← бічна панель, колонка НБУ, сторінка «Синхронізація НБУ»
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
    ├── FetchNbuDataCommand.php     ← WP-CLI імпортер монет (bank.gov.ua)
    ├── BackfillSyncStatusCommand.php ← WP-CLI: порахувати «Оновлювати з НБУ» для всіх монет
    ├── RepairImagesCommand.php     ← WP-CLI: ремонт картинок і дублікатів вкладень
    ├── InstallSchemaCommand.php    ← WP-CLI: створити/оновити таблицю coin_prices
    └── MigratePricesCommand.php    ← WP-CLI: одноразова міграція coin_price CPT → таблиця coin_prices
```

**Namespace:** `Coins\` → `inc/` (PSR-4, composer autoload)

### REST і GraphQL

Детальна документація кожного шару — в `inc/Rest/README.md` та `inc/GraphQL/README.md` (як додати роут/тип/мутацію, конвенції, приклади).

### Додати новий CPT або таксономію

1. Створити реєстратор в `inc/Admin/PostTypes/` з методом `boot()`
2. Створити ACF-менеджер в `inc/Admin/ACFFieldsManager/`
3. Підключити обидва в `App::bootAdmin()`