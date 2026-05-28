# Secure Fund Transfer API

Symfony 7.4 API for transferring funds between accounts with MySQL persistence, Redis idempotency protection, and focused tests around the money-transfer workflow.

## Architecture

The code follows a small layered structure:

- `Api/Controller` exposes HTTP endpoints.
- `Application/Command` and `Application/Handler` contain the transfer use case.
- `Domain` contains pure PHP entities, value objects, events, exceptions, and repository ports.
- `Infrastructure` contains Doctrine/DBAL repositories, Redis adapters, and framework subscribers.

The transfer handler reserves an idempotency key in Redis with `SET NX`, opens a database transaction, loads both accounts using `SELECT ... FOR UPDATE`, applies domain `debit()` and `credit()`, persists a completed transaction, stores the idempotency result for 24 hours, and dispatches `TransferInitiated`.

## Requirements

- PHP 8.3
- Composer
- MySQL 8
- Redis 7
- Docker with Docker Compose, optional

## Setup With Docker

```bash
cp .env.example .env
docker compose build --no-cache php
docker compose up -d
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:seed:demo-accounts
```

If a previous migration attempt failed and left partial tables behind, reset the development volumes and run the setup again:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:seed:demo-accounts
```

The Docker API is exposed at:

```text
http://localhost:8080
```

Swagger UI is served as a static file through Nginx:

```text
http://localhost:8080/docs/
```

If `/docs/` returns a Symfony `No route found` error after changing `nginx.conf`, restart Nginx:

```bash
docker compose restart nginx
```

## Setup Without Docker

Use this path if you already have PHP 8.3, Composer, MySQL 8, and Redis 7 installed locally.

1. Install dependencies:

```bash
composer install
```

2. Copy the environment template:

```bash
cp .env.example .env
```

3. Update `.env` for your local services. Example:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://root:@127.0.0.1:3306/transfer_db?serverVersion=8.0.32&charset=utf8mb4"
REDIS_URL=redis://127.0.0.1:6379
```

If you see `getaddrinfo for redis failed`, the app is running outside Docker while `REDIS_URL` is still set to `redis://redis:6379`. Change it to `redis://127.0.0.1:6379` for local runs.

If you see `No connection could be made because the target machine actively refused it [redis://127.0.0.1:6379]`, Redis is not running locally. Start Redis one of these ways:

```bash
docker compose up -d redis
```

or start your local Redis service, then verify:

```bash
redis-cli ping
```

Expected response:

```text
PONG
```

4. Create the database and run migrations:

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed:demo-accounts
```

5. Start the local PHP server:

```bash
php -S 127.0.0.1:8080 -t public
```

The local API is exposed at:

```text
http://127.0.0.1:8080
```

## Seed Demo Accounts With Docker

Recommended:

```bash
docker compose exec php php bin/console app:seed:demo-accounts
```

Manual SQL alternative:

```bash
docker compose exec mysql mysql -utransfer_user -pchange-app-password transfer_db
```

```sql
INSERT INTO accounts (id, owner_name, balance, currency)
VALUES
  ('11111111-1111-1111-1111-111111111111', 'Alice', 1000.0000, 'USD'),
  ('22222222-2222-2222-2222-222222222222', 'Bob', 100.0000, 'USD');
```

## Seed Demo Accounts Without Docker

Recommended:

```bash
php bin/console app:seed:demo-accounts
```

Manual SQL alternative:

```bash
mysql -uroot transfer_db
```

```sql
INSERT INTO accounts (id, owner_name, balance, currency)
VALUES
  ('11111111-1111-1111-1111-111111111111', 'Alice', 1000.0000, 'USD'),
  ('22222222-2222-2222-2222-222222222222', 'Bob', 100.0000, 'USD');
```

## API Usage

Swagger UI is available at:

```text
http://localhost:8080/docs/
```

The static OpenAPI document is available at:

```text
http://localhost:8080/docs/openapi.json
```

For a non-Docker local PHP server, use:

```text
http://127.0.0.1:8080/docs/
http://127.0.0.1:8080/docs/openapi.json
```

Create a transfer:

```bash
curl -i -X POST http://localhost:8080/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-transfer-1" \
  -H "X-User-Id: demo-user-1" \
  -d '{
    "from_account_id": "11111111-1111-1111-1111-111111111111",
    "to_account_id": "22222222-2222-2222-2222-222222222222",
    "amount": 25.50,
    "currency": "USD"
}'
```

Successful transfers return `201 Created` with the transaction ID. Validation, domain, and unexpected errors are returned as RFC 7807 `application/problem+json`.

Read an account:

```bash
curl http://localhost:8080/accounts/11111111-1111-1111-1111-111111111111
```

Read only the current balance:

```bash
curl http://localhost:8080/accounts/11111111-1111-1111-1111-111111111111/balance
```

## Tests

Without Docker:

```bash
composer install
php bin/phpunit
```

With Docker:

```bash
docker compose exec php php bin/phpunit
```

Current coverage includes pure domain unit tests and integration-style transfer handler tests for successful transfer and duplicate idempotency behavior.

## Reliability Notes

- MySQL transactions protect account balance updates.
- `SELECT ... FOR UPDATE` serializes concurrent transfers touching the same account rows.
- Redis idempotency keys prevent duplicate request processing for 24 hours.
- Symfony RateLimiter uses Redis for a fixed window of 30 transfer requests per minute per user key.
- `transactions.idempotency_key` is unique as a database backstop.
- Money is represented as integer minor units in the domain.
- API exceptions are normalized to RFC 7807 JSON and logs are structured JSON with transfer context.

## Tradeoffs And Next Steps

- Authentication/JWT and rate limiting are not fully implemented yet; the structure is ready for `lexik_jwt` and Symfony rate limiter.
- A real production deployment should use HTTPS, secret management, request tracing, and metrics.
- Additional tests should cover HTTP functional flows against MySQL/Redis containers and high-concurrency transfer attempts.
- The current Messenger transport is synchronous for simple local operation; async Redis/Doctrine transport can be enabled for background workflows.

## Time Spent

Time spent: ~3 hours.

## AI Tools And Prompts Used

AI assistance was used to generate and refine the implementation. Main prompts included:

- "Write a Doctrine migration for Symfony using the schema above..."
- "Create a Symfony 7 project scaffold with Docker Compose for PHP 8.3-fpm, Nginx, MySQL 8, and Redis 7..."
- "Write a PHP 8.3 domain model for a fund transfer system..."
- "Write a Symfony Messenger command and handler for transferring funds..."
- "Add API validation, Redis rate limiting/idempotency, RFC 7807 errors, and structured JSON logging."

All generated code was reviewed and adjusted for the final architecture, idempotency behavior, transaction handling, and tests.
