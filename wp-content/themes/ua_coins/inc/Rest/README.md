# REST API

Базовий URL: `/wp-json/coins/v1`

Уся предметна логіка (монети, колекція) обслуговується через [GraphQL](../GraphQL/README.md). REST тут лишився з єдиною метою — видати анонімний app-токен, без якого `/graphql` взагалі не відповість анонімним запитам (див. `Coins\Security\ApiGuardService`).

## Маршрути

| Метод | URL | Auth | Опис |
|---|---|---|---|
| `GET` | `/app-token` | — | Видає короткоживий (45 хв) `X-App-Token`; сам rate-limited (10/хв на IP) |

**Відповідь `GET /app-token`:**
```json
{ "token": "…", "expires_in": 2700 }
```

Клієнт передає отриманий токен у заголовку `X-App-Token` на кожному запиті до `/graphql`.

## Чому колишні `/coins`, `/collection*` видалені

Ці роути (список/деталі монет, колекція користувача) дублювали GraphQL і не мали жодного реального споживача — усі клієнти (`r-numismat`, `expo-numismat`, telegram-бот) з самого початку йшли через `/graphql`. Видалені разом з `CoinController`, `CoinPriceController`, `CoinCollectionController`.

## Додавання нового REST-роуту

Робіть це, лише якщо функціональність справді не покривається GraphQL (наприклад, потрібен ендпоінт поза GraphQL-схемою — як `app-token`).

1. Створити контролер у `inc/Rest/Controllers/` під namespace `Coins\Rest\Controllers\` — автолоадер підхопить сам (PSR-4).
2. Зареєструвати маршрут у `ApiRouter::registerRoutes()` через `register_rest_route()`.
3. Якщо роут має бути захищений тим самим анти-скрапінг гейтом, що й `/graphql` — додати перевірку маршруту в `Coins\Security\ApiGuardService::guard()`.

## CORS

`Coins\Security\CorsService` дозволяє лише `GET, POST, OPTIONS` для origin-ів зі списку `COINS_ALLOWED_ORIGINS` (`.env`). Якщо новий роут потребує інших методів — розширити цей список там само.
