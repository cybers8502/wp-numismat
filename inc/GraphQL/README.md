# GraphQL API

Ендпоінт: `/wp-json/graphql` (реєструється плагіном [WPGraphQL](https://www.wpgraphql.com/)).

Це основний і практично єдиний спосіб отримати дані з бекенду — REST лишив тільки видачу app-токена (див. [`inc/Rest/README.md`](../Rest/README.md)). Анонімні запити мають нести заголовок `X-App-Token` (`Coins\Security\ApiGuardService`), інакше отримають `401`.

## Структура

Плоска — один клас на предметну область, а не окремі класи на тип/поле/query/mutation. Свідомо повторює layout сусіднього проєкту `wp-brutmaps` (`AuthGraphQL`, `ObjectsGraphQL`).

| Клас | Відповідає за |
|---|---|
| `CoinGraphQL` | Типи `CoinGalleryImage`/`CoinPriceEntry`/`CoinPriceStats`; поля на `Coin` (ACF): `issueDate`, `bookletUrl`, `descriptionHtml`, `diameterMm`, `mintageDeclared`, `mintageActual`, `gallery`, `designersArtist/Designer/Adaptation/Sculptor`, `priceHistory`, `priceStats(days: Int)` |
| `DesignerGraphQL` | Поля на `Designer`: `fullName`, `note` |
| `CollectionGraphQL` | Типи `CollectionItem`/`CollectionStats`/`AddToCollectionPayload`/`DeleteCollectionItemPayload`; queries `myCollection`, `myCollectionStats`; мутації `addToCollection`, `updateCollectionItem`, `deleteCollectionItem` |
| `AuthGraphQL` | Мутація `logout` (ревокація JWT-секрету). `refreshJwtAuthToken`/`login`/`register` реєструє плагін `wp-graphql-jwt-authentication`, не ця тема |
| `GraphQLRegistrar` | Оркестратор — викликає `registerTypes()` кожного класу на хук `graphql_register_types` |

Кожен клас має один публічний вхід `registerTypes(): void`. Всередині — приватні методи для типів (`registerSharedTypes`), полів/queries/мутацій, а резолвери — публічні методи цього ж класу (`resolveX`), на які посилаються через `[$this, 'resolveX']`.

## Авторизація й права

- `myCollection`, `myCollectionStats`, `addToCollection`, `updateCollectionItem`, `deleteCollectionItem`, `logout` вимагають `is_user_logged_in()`, інакше кидають `\GraphQL\Error\UserError`.
- `updateCollectionItem`/`deleteCollectionItem` додатково перевіряють власника: `coin_collection.user_id` (ACF) має збігатись з `get_current_user_id()`.
- Список/деталі монет (`coins`, `coin` — стандартні WPGraphQL-запити для CPT `coins`) публічні, але захищені гейтом app-токена на рівні транспорту (не тут, а в `ApiGuardService`).

## Приклади запитів

```graphql
query {
  coins(first: 20, where: { coinQualityIn: [5], search: "архангел" }) {
    nodes {
      databaseId
      title
      issueDate
      gallery { id url medium }
      designersArtist { title fullName }
      priceHistory { date price source }
      priceStats(days: 90) {
        latestPrice latestDate trend
        nbuPrice nbuDate vsNbuPct
        periodStart periodEnd periodDeltaPct periodMin periodMax
      }
    }
  }
}

mutation {
  addToCollection(input: { coinId: 123 }) { success }
}

mutation {
  updateCollectionItem(input: { id: 45, quantity: 3, purchasePrice: 1500 }) {
    item { id quantity purchasePrice }
  }
}

mutation {
  deleteCollectionItem(input: { id: 45 }) { success id }
}
```

Повний перелік типів/полів дивіться безпосередньо у файлах-реєстраторах — це джерело правди, README тут навмисно не дублює кожне поле.

**`priceStats`**: агрегати над `priceHistory` (останнє значення, тренд, порівняння з НБУ, min/max/дельта за період), рахуються на бекенді з тих самих `coin_price` записів — щоб не дублювати цю логіку на кожному клієнті (сайт, бот). `nbuPrice` — це **остання відома** ціна з джерела `coins.bank.gov.ua` (снепшот на момент запуску `wp nbuarchive import-prices`), а не ціна на дату випуску монети — окремого поля «ціна при випуску» поки немає.

## Додавання нового типу/поля/query/мутації

- Розширення наявної області (новий field на `Coin`, нова мутація колекції тощо) → додати метод у відповідний `*GraphQL.php`, не створювати новий файл.
- Нова предметна область → створити `inc/GraphQL/NewDomainGraphQL.php` з методом `registerTypes()`, підключити викликом `(new NewDomainGraphQL())->registerTypes()` всередині `GraphQLRegistrar::register()`.
- Мутації/queries, що потребують авторизації — кидати `\GraphQL\Error\UserError`, а не повертати null/false мовчки (консистентно з рештою коду).
