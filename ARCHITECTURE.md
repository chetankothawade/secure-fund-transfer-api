# Architecture Guide

This guide explains the structure and design decisions for the Secure Fund Transfer API. It is written for interview discussion: what was built, why it is structured this way, and how the critical transfer path protects correctness.

## High-Level Summary

The project implements a small Symfony 7.4 API for transferring funds between accounts. The core goal is reliability: a transfer must either fully complete or not happen, concurrent requests must not corrupt balances, and repeated client requests must not double-charge an account.

Main design choices:

- Symfony 7.4 with PHP 8.3.
- MySQL 8 for durable account, transaction, and audit data.
- Redis 7 for idempotency keys and Symfony rate-limiter state.
- Shared API key guard for the transfer endpoint.
- Symfony Validator for request validation.
- Symfony Messenger for command/handler wiring and domain-event dispatch.
- RFC 7807 `application/problem+json` responses for API errors.
- Structured JSON logs through Monolog.
- A layered structure inspired by DDD/hexagonal architecture.

## Folder Structure

```text
src/
|-- Api/
|   |-- Controller/
|   |   |-- AccountController.php
|   |   `-- TransferController.php
|   |-- Request/
|   |   `-- CreateTransferRequest.php
|   `-- Response/
|       `-- ProblemJsonFactory.php
|-- Application/
|   |-- Command/
|   |   `-- TransferFundsCommand.php
|   `-- Handler/
|       `-- TransferFundsHandler.php
|-- Domain/
|   |-- Entity/
|   |   |-- Account.php
|   |   `-- Transaction.php
|   |-- Event/
|   |   |-- FundsTransferred.php
|   |   `-- TransferInitiated.php
|   |-- Exception/
|   |   |-- AccountNotFoundException.php
|   |   |-- CurrencyMismatchException.php
|   |   |-- DomainException.php
|   |   |-- InsufficientFundsException.php
|   |   `-- InvalidTransactionState.php
|   |-- Repository/
|   |   |-- AccountRepositoryInterface.php
|   |   `-- TransactionRepositoryInterface.php
|   `-- ValueObject/
|       |-- Money.php
|       `-- TransferStatus.php
|-- Infrastructure/
|   |-- Audit/
|   |-- Console/
|   |-- EventSubscriber/
|   |   `-- ExceptionSubscriber.php
|   |-- Persistence/
|   |   |-- DoctrineAccountRepository.php
|   |   `-- DoctrineTransactionRepository.php
|   `-- Redis/
|       |-- IdempotencyResult.php
|       |-- IdempotencyService.php
|       `-- IdempotencyStoreInterface.php
`-- Kernel.php

config/
|-- packages/
|   |-- cache.yaml
|   |-- doctrine.yaml
|   |-- messenger.yaml
|   |-- monolog.yaml
|   |-- rate_limiter.yaml
|   `-- validator.yaml
`-- services.yaml

public/docs/
|-- index.html
`-- openapi.json
```

## API Layer

The API layer translates HTTP requests into application commands and formats HTTP responses. It stays thin and avoids business logic.

### TransferController

Endpoint:

```text
POST /transfers
```

Responsibilities:

- Reads and decodes the JSON request body.
- Requires `X-Api-Key` to match `TRANSFER_API_KEY`.
- Merges the `Idempotency-Key` header into the request DTO.
- Validates the DTO with Symfony Validator constraints:
  `NotBlank`, `Positive`, `Currency`, and `Uuid`.
- Applies Symfony RateLimiter before dispatching the command.
- Dispatches `TransferFundsCommand` through Messenger.
- Returns `201 Created` with `transaction_id`.
- Logs `transaction_id`, `user_id`, and `duration_ms`.

Important detail:

The seeded demo account IDs are UUID-shaped but not strict RFC UUID variants, so account ID validation uses `#[Assert\Uuid(strict: false)]`. This keeps the current seed data usable while still catching malformed account IDs.

### AccountController

Endpoints:

```text
GET /accounts/{id}
GET /accounts/{id}/balance
```

Responsibilities:

- `GET /accounts/{id}` returns demo account details.
- `GET /accounts/{id}/balance` returns only current balance and currency.
- Missing accounts return RFC 7807 `404 application/problem+json`.

### ProblemJsonFactory

Builds consistent RFC 7807 responses:

```json
{
  "type": "https://example.com/problems/validation-error",
  "title": "Invalid request body",
  "status": 400,
  "detail": "One or more request fields failed validation.",
  "errors": []
}
```

Validation failures include a flat `errors` array with `field` and `message`.

## Application Layer

The application layer owns use-case orchestration. It coordinates Redis, transactions, repositories, domain methods, logging, and event dispatch.

### TransferFundsCommand

Message/DTO used by Symfony Messenger.

Fields:

- `fromAccountId`
- `toAccountId`
- `amount`
- `currency`
- `idempotencyKey`

It contains data only and no business logic.

### TransferFundsHandler

Critical transfer flow:

1. Validate idempotency key exists.
2. Validate source and destination accounts differ.
3. Build a canonical request fingerprint.
4. Reserve idempotency in Redis through `IdempotencyService`.
5. Reject idempotency-key reuse with a different fingerprint.
6. Recover an existing committed transaction from MySQL if Redis does not have the completed result.
7. Open a MySQL transaction.
8. Load both accounts using `SELECT ... FOR UPDATE` in deterministic account-ID order.
9. Create a `Money` value object from fixed-scale decimal text.
10. Call `fromAccount->debit($amount)` and `toAccount->credit($amount)`.
11. Save both accounts.
12. Create and complete a `Transaction`.
13. Persist the transaction.
14. Commit the database transaction.
15. Store the completed idempotency result in Redis for 24 hours.
16. Dispatch `TransferInitiated`.
17. Log the completed transfer.

Reliability details:

- Redis idempotency prevents duplicate request execution early.
- MySQL row locks protect balances during concurrent transfers.
- Deterministic lock ordering reduces deadlock risk for opposing transfers.
- Retryable DB failures are retried up to three times.
- `transactions.idempotency_key` is unique as a durable backstop.
- On failure, the reserved Redis key is released so the client can retry.

## Domain Layer

The domain layer is pure PHP. It has no Symfony, Doctrine, Redis, or HTTP dependencies.

### Money

Value object for money:

- Stores amount as integer minor units.
- Normalizes currency to uppercase.
- Validates ISO-like 3-letter currency format.
- Supports decimal parsing, decimal serialization, `add()`, `subtract()`, and `isGreaterThan()`.
- Throws `CurrencyMismatchException` when operations mix currencies.

Interview point:

Money should not be represented as raw floats in business logic. This implementation converts decimal text to integer minor units and keeps arithmetic deterministic.

### Account

Entity representing an account balance.

Key methods:

- `debit(Money $amount)`
- `credit(Money $amount)`
- `balance()`

`debit()` throws `InsufficientFundsException` if the debit amount exceeds the current balance.

### Transaction

Entity representing a transfer record. It has a simple state machine:

```text
pending -> completed
pending -> failed
```

Once completed or failed, a transaction cannot transition again.

### Domain Exceptions

Named domain exceptions make error mapping explicit:

- `InsufficientFundsException` -> `409 Conflict`
- `AccountNotFoundException` -> `404 Not Found`
- `CurrencyMismatchException` -> `422 Unprocessable Entity`

## Infrastructure Layer

### DoctrineAccountRepository

Responsibilities:

- Loads accounts with `SELECT * FROM accounts WHERE id = ? FOR UPDATE`.
- Throws `AccountNotFoundException` for missing rows.
- Hydrates domain `Account` objects.
- Saves balance and version updates.

### DoctrineTransactionRepository

Responsibilities:

- Generates transaction IDs with MySQL `UUID()`.
- Inserts transaction rows.

### IdempotencyService

Redis-backed idempotency adapter.

Behavior:

- Uses `SET key {"status":"processing","fingerprint":"..."} EX 86400 NX` to reserve the key.
- Returns a cached transaction ID when the key already contains one.
- Rejects reuse of an idempotency key when the stored fingerprint differs from the incoming request fingerprint.
- Reports `processing` when another request reserved the key but has not completed.
- Replaces the processing marker with the transaction ID after success.
- Deletes the key after a failed transfer attempt.

Redis key format:

```text
idempotency:{idempotency-key}
```

TTL:

```text
24 hours
```

### Rate Limiter

Configured in:

```text
config/packages/rate_limiter.yaml
config/packages/cache.yaml
```

Policy:

```text
fixed_window, 30 requests per 1 minute
```

Storage:

```text
cache.rate_limiter -> Redis
```

Limiter key:

- authenticated user when available
- `X-User-Id` for local/demo requests
- fallback to anonymous client IP

### ExceptionSubscriber

Converts exceptions into RFC 7807 `application/problem+json`.

Mappings:

- invalid JSON -> `400`
- validation failure -> handled in controller as `400`
- invalid request -> `400`
- account not found -> `404`
- insufficient funds -> `409`
- currency mismatch -> `422`
- Redis unavailable -> `503`
- unexpected error -> `500`

It unwraps Messenger `HandlerFailedException` so domain exceptions thrown by handlers map to the correct HTTP status.

### Monolog

Configured in:

```text
config/packages/monolog.yaml
```

Dev, test, and prod log handlers use JSON formatting. Transfer request logs include:

- `transaction_id`
- `user_id`
- `duration_ms`

### SeedDemoAccountsCommand

Seeds deterministic demo accounts:

```text
11111111-1111-1111-1111-111111111111  Alice  1000.0000 USD
22222222-2222-2222-2222-222222222222  Bob    100.0000 USD
```

The command uses `ON DUPLICATE KEY UPDATE`, so it can be safely rerun.

## Database Design

Migration:

```text
migrations/Version20260527200931.php
```

Tables:

- `accounts`
- `transactions`
- `audit_log`

### accounts

Columns:

- `id`
- `owner_name`
- `balance`
- `currency`
- `version`
- `created_at`

Notes:

- `balance` uses `DECIMAL(19,4)`.
- `version` supports optimistic-lock style versioning.
- The transfer flow additionally uses pessimistic row locks.

### transactions

Columns:

- `id`
- `from_account_id`
- `to_account_id`
- `amount`
- `currency`
- `status`
- `idempotency_key`
- `created_at`

Indexes:

- unique `idempotency_key`
- `from_account_id`
- `to_account_id`
- `status`

### audit_log

Ready for future domain-event/audit persistence.

## Request Flow

```text
HTTP client
  -> POST /transfers
  -> TransferController
  -> JSON deserialize + Symfony validation
  -> Symfony RateLimiter using Redis
  -> TransferFundsCommand
  -> Messenger
  -> TransferFundsHandler
  -> shared API key check
  -> IdempotencyService SET NX in Redis with request fingerprint
  -> MySQL transaction starts
  -> AccountRepository SELECT ... FOR UPDATE in stable order
  -> Account::debit()
  -> Account::credit()
  -> TransactionRepository save()
  -> MySQL transaction commits
  -> Redis stores transaction_id for 24h
  -> TransferInitiated dispatched
  -> 201 JSON response
```

## API Documentation

Swagger UI is static:

```text
public/docs/index.html
public/docs/openapi.json
```

URLs:

```text
http://localhost:8080/docs/
http://localhost:8080/docs/openapi.json?v=20260528
```

`index.html` includes a cache-busting query string so the browser does not keep showing stale response codes or schemas.

## Dependencies Worth Calling Out

- `symfony/rate-limiter` is required for the Redis-backed fixed-window transfer limiter.
- `symfony/intl` is required by Symfony Validator's `Currency` constraint.
- `predis/predis` is used for Redis access.

Without `symfony/intl`, valid transfer requests fail with a server-side `LogicException` from the `Currency` constraint.

## Docker Structure

Services:

- `php`: PHP 8.3 FPM application runtime.
- `nginx`: HTTP server exposed on port `8080`.
- `mysql`: MySQL 8 database.
- `redis`: Redis 7 with port `6379` exposed for local access.

The PHP and Nginx services mount the project directory, so most source changes are reflected immediately. When changing dependencies or PHP attributes used by cached metadata, restart PHP:

```bash
docker compose restart php
```

## Tests

Unit tests cover:

- money arithmetic
- fixed-scale decimal parsing
- insufficient funds
- transaction state transitions

Integration-style handler tests cover:

- successful transfer orchestration
- balance mutation
- transaction persistence
- Redis idempotency storage
- domain event dispatch
- duplicate idempotency returning cached transaction ID
- idempotency key reuse with different payload rejected
- MySQL recovery when Redis is missing a committed idempotency result
- deterministic lock ordering

Run:

```bash
docker compose exec php php bin/phpunit
```

## Interview Talking Points

### Why layered architecture?

It keeps business rules independent from Symfony and infrastructure. The domain can be tested without a database, Redis, or HTTP.

### Why Redis?

Redis is fast and well-suited for short-lived idempotency keys and distributed rate-limiter state.

### Why `SELECT ... FOR UPDATE`?

Financial transfers need strict balance consistency. Pessimistic locks serialize concurrent writes to the same account rows.

### Why both Redis idempotency and DB unique key?

Redis prevents duplicate work early and returns cached results. The unique DB key is a durable final backstop.

### Why RFC 7807?

It gives clients a predictable, standards-based error shape and prevents framework HTML error pages from leaking through the API.

### What would be improved next?

- Replace the demo shared API key with JWT/OAuth2 or mTLS and account-level authorization.
- Add HTTP functional tests using real MySQL and Redis containers.
- Add request IDs and distributed tracing.
- Add async event consumers for audit logging, notifications, or webhooks.
- Add CI running Composer validation, PHPUnit, linting, and container checks.

## Common Demo Commands

Start Docker:

```bash
docker compose up -d --build
```

Install dependencies:

```bash
docker compose exec php composer install
```

Run migrations:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Seed demo accounts:

```bash
docker compose exec php php bin/console app:seed:demo-accounts
```

Create transfer:

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

Read balance:

```bash
curl http://localhost:8080/accounts/11111111-1111-1111-1111-111111111111/balance
```
