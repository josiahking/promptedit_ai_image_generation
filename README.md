# Pixelforge Image Generation

An authenticated Laravel, Vue, and Inertia application with a public introduction and private per-user generation studio, integrating the [Pixelforge API](https://pixelforge.promptedit.dev/docs) for asynchronous AI image generation.

## Technology

- PHP 8.3+, Laravel 13, Vue 3, Inertia, TypeScript, and Tailwind CSS
- PostgreSQL for application data
- Laravel database queues for asynchronous provider requests

## Local development with Docker Compose

Use Docker Desktop (Linux containers enabled), Git and Docker Compose. No host PHP, Composer, Node, PostgreSQL or Laravel Sail installation is needed.

```bash
git clone https://github.com/josiahking/promptedit_ai_image_generation.git
cd promptedit_ai_image_generation
cp .env.docker.example .env

docker compose build app
docker compose up -d db
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app npm install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d app
docker compose exec -T app php artisan migrate --force
docker compose up -d frontend worker scheduler
docker compose ps
```

Open **http://localhost:8000**. Docker Compose runs the PHP web application, Vite, PostgreSQL, a queue worker and a scheduler. All services share one project-owned PHP/Node development image.

Keep API keys and webhook signing secrets in your **local** `.env`, never in Git. Your Railway production deployment has its own database and secrets.

For tests, Windows Git Bash setup, PostgreSQL test isolation, troubleshooting and live Pixelforge webhooks, follow [local development and testing](docs/LOCAL-DEVELOPMENT.md).

## Architecture

The application follows Laravel conventions with a small service layer. Controllers own HTTP concerns; application actions coordinate workflows; the Pixelforge client isolates provider-specific requests and responses.

| Component                                                 | Responsibility                                                                             |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `app/Support/Money.php`                                   | Immutable, exact USD arithmetic                                                            |
| `app/Services/PricingService.php`                         | Token-based estimates, provider costs, and 25% markup                                      |
| `app/Services/PixelforgeClient.php`                       | API authentication, pricing lookup, generation creation, and provider error classification |
| `app/Actions/PersistGeneration.php`                       | Store user ownership, request parameters, estimate, and idempotency key                    |
| `app/Actions/SubmitGeneration.php`                        | Obtain pricing and dispatch generation submission                                          |
| `app/Jobs/SubmitPixelforgeGeneration.php`                 | Execute queued provider requests with bounded retries                                      |
| `app/Actions/RecordPixelforgeAcknowledgement.php`         | Attach provider identity and rates without regressing generation state                     |
| `app/Models/Generation.php`                               | Generation state, costs, usage, and provider metadata                                      |
| `app/Http/Controllers/PixelforgeWebhookController.php`    | Verify raw-body HMAC signature and persist event receipt                                   |
| `app/Jobs/ProcessPixelforgeWebhook.php`                   | Deduplicate processing and lock generation updates                                         |
| `app/Actions/ApplyPixelforgeGeneration.php`               | Shared webhook/polling transition and exact final billing policy                           |
| `app/Console/Commands/ReconcilePixelforgeGenerations.php` | Retry unprocessed events and poll stale provider states                                    |
| `app/Services/UsageReconciliationService.php`             | Reconcile provider usage and exact charges by UTC completion day                           |
| `app/Console/Commands/ReconcilePixelforgeUsage.php`       | CLI date-range validation and discrepancy reporting                                        |

### Technical decisions and trade-offs

**Exact pricing.** Money is represented as integer picodollars (12 decimal places), not PHP floating-point values. Token costs are calculated from exact rate strings, and the customer markup is 25%. Estimates use the provider's pricing rules; final billing must use the usage and rates associated with the individual completed generation. Precision is preserved in storage rather than rounding early to cents.

**Persist before submission.** Every generation has a local UUID and an immutable idempotency key before any outbound creation request. The local UUID is passed as `metadata.order_id`. Repeated requests use the same key and parameters so a timeout cannot silently create a second billable generation.

**Asynchronous processing.** Provider creation runs in a database-backed queue instead of holding an HTTP request open. The additional worker process is a deliberate trade-off for responsiveness and recoverable retries.

**Failure semantics.** Validation and policy rejections are distinct from provider failures. Rate limits and overloads use bounded retries and `Retry-After` where available. If the outcome of a submission cannot be determined, its state remains `submission_unknown` rather than being treated as a confirmed failure.

**State safety.** Webhooks are verified with HMAC-SHA256 over the timestamp and raw body, with a five-minute timestamp tolerance. A unique provider event ID makes receipt idempotent. The event is persisted before a quick 2xx response; queued processing uses transactions and row locks to prevent duplicate finalization or terminal-state regression. The same pricing and transition service handles polling recovery if webhook delivery fails. This requires a queue worker and scheduler, but avoids doing slow work inside webhook delivery.

**Reconciliation.** `php artisan pixelforge:usage-reconcile --start=YYYY-MM-DD --end=YYYY-MM-DD` compares the provider's per-key UTC daily usage against local succeeded generations. It checks the four token categories, exact provider cost, and locally stored 25% markup. Mismatches make the command fail so reporting differences are not silently ignored.

Eloquent is used directly for persistence. A generic repository abstraction is intentionally avoided because it would add indirection without improving these workflows.

## Testing and quality checks

Inside Docker Compose, use the isolated test settings in [local development and testing](docs/LOCAL-DEVELOPMENT.md#daily-workflow) for `php artisan test` and `composer ci:check`.

The CI checks include PHP formatting, static analysis, frontend checks, and PHPUnit. Integration tests use Laravel's HTTP fakes to exercise the provider contract, idempotency, timeouts, overloads, acknowledgement races, raw webhook signatures, duplicate and out-of-order events, exact final charges, polling recovery, and persisted generation state. The CI suite runs on SQLite in memory and PostgreSQL 17; local Sail uses PostgreSQL.

## API documentation

[Pixelforge API reference](https://pixelforge.promptedit.dev/docs)
