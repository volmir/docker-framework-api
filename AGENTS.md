# Repository Guidelines

## Структура проекта

Это JSON API на PHP 8.4 без runtime-пакетов Composer; окружение включает Nginx, PHP-FPM и MySQL 8.4. Пространство имён `App\` соответствует `src/`, `App\Tests\` — `tests/`.

- `src/Controller/` — HTTP-контроллеры; `src/Users/` — пример домена с SQL и общими операциями.
- `src/Http/`, `Middleware/`, `Database/`, `Console/`, `Log/` — инфраструктура.
- `src/routes.php` и `src/middleware.php` — явная регистрация маршрутов и middleware.
- `public/index.php` и `bin/console` — точки входа; `commands/` — CLI-команды.
- `database/migrations/` и `database/seeds/` — миграции и начальные данные.
- `tests/` — тесты; `docs/` — архитектура, решения и примеры запросов; `.docker/` — конфигурация контейнеров.

## Установка и команды разработки

Используйте Docker Compose v2 и Make. PHP, Composer и MySQL запускайте внутри контейнеров.

```sh
cp .env.example .env
make install
make up
make migrate
make seed
```

`make install` собирает PHP-образ и устанавливает зависимости; `make up` собирает и запускает стек. Проверка API: `curl -i http://localhost:8082/health`.

- `make check` — последовательно проверяет синтаксис, PHPStan, маршруты и тесты.
- `make lint`, `make analyse`, `make routes-check` — отдельные проверки.
- `make test` — PHPUnit в изолированном окружении; `make test TEST_VERBOSE=1` выводит журналы приложения.
- `make restart` — пересоздаёт контейнеры после изменения `.env`.
- `make domain name=Posts` — создаёт домен и тест.

## Стиль и архитектура

Следуйте PER-CS и существующему оформлению: четыре пробела, `declare(strict_types=1)`, классы `final`, классы в PascalCase, методы приложения в camelCase. Указывайте типы и PHPDoc для структур массивов. Автоматический форматтер не настроен; PHPStan работает на уровне 8 без baseline и `ignoreErrors`.

Контроллеры отвечают за валидацию и HTTP-ответы; команды вызывают домены, а не контроллеры. Домены не зависят от HTTP-слоя. Не добавляйте ORM, контейнер зависимостей, общие Helpers/Utils или слои без конкретной необходимости. Подробности: [архитектура](docs/architecture.md).

## Тестирование

Используйте PHPUnit 12: `tests/Unit/`, `tests/Feature/`, `tests/Architecture/`, `tests/<Domain>/`. Имена файлов — `*Test.php`, методов — `test_descriptive_behavior`.

Проверяйте новое поведение и ошибки. Тесты БД используют настоящий MySQL `db-test`; наследуйтесь от `DatabaseTestCase` для транзакционной изоляции. Числовой порог покрытия не задан. Выборочный запуск:

```sh
docker compose --profile test run --rm tests sh bin/test --filter UserControllerTest
```

## Коммиты и pull request

В последних коммитах используются `feat:`, `fix:`, `docs:`, `style:` и `feat!:` для несовместимых изменений. Продолжайте этот формат с кратким описанием на английском.

В PR описывайте проблему, результат, связанные задачи и выполненные проверки. При изменении API приводите запрос и ответ; отмечайте миграции и изменения конфигурации.

## Конфигурация и секреты

Не коммитьте `.env`, пароли и API-ключи. Читайте окружение через `Config`; изменения переменных документируйте в `.env.example`. Перед развёртыванием выполняйте `make prod-check`; следуйте [SECURITY.md](SECURITY.md).
