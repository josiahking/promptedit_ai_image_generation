# Pixelforge Image Generation

A Laravel, Vue, and Inertia application integrating the [Pixelforge API](https://pixelforge.promptedit.dev/docs) for asynchronous AI image generation.

## Technology

- PHP 8.3+, Laravel 13, Vue 3, Inertia, TypeScript, and Tailwind CSS
- PostgreSQL for application data
- Laravel database queues for asynchronous provider requests

## Local setup

### Requirements

PHP 8.3+, Composer 2, Node.js 22+, npm, and PostgreSQL.

```bash
git clone https://github.com/josiahking/promptedit_ai_image_generation.git
cd promptedit_ai_image_generation

composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure the database and Pixelforge credentials in `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=promptedit
DB_USERNAME=postgres
DB_PASSWORD=

PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Create the database before running migrations. Use separate Pixelforge API keys and webhook signing secrets for local and deployed environments; never commit credentials.

```bash
php artisan migrate
php artisan serve
```

In separate terminals:

```bash
npm run dev
php artisan queue:work
```

## Architecture

The application follows Laravel conventions with a small service layer. Controllers own HTTP concerns; application actions coordinate workflows; the Pixelforge client isolates provider-specific requests and responses.

| Component | Responsibility |
| --- | --- |
| `app/Support/Money.php` | Immutable, exact USD arithmetic |
| `app/Services/PricingService.php` | Token-based estimates, provider costs, and 25% markup |
| `app/Services/PixelforgeClient.php` | API authentication, pricing lookup, generation creation, and provider error classification |
| `app/Actions/PersistGeneration.php` | Store user ownership, request parameters, estimate, and idempotency key |
| `app/Actions/SubmitGeneration.php` | Obtain pricing and dispatch generation submission |
| `app/Jobs/SubmitPixelforgeGeneration.php` | Execute queued provider requests with bounded retries |
| `app/Actions/RecordPixelforgeAcknowledgement.php` | Attach provider identity and rates without regressing generation state |
| `app/Models/Generation.php` | Generation state, costs, usage, and provider metadata |

### Technical decisions and trade-offs

**Exact pricing.** Money is represented as integer picodollars (12 decimal places), not PHP floating-point values. Token costs are calculated from exact rate strings, and the customer markup is 25%. Estimates use the provider's pricing rules; final billing must use the usage and rates associated with the individual completed generation. Precision is preserved in storage rather than rounding early to cents.

**Persist before submission.** Every generation has a local UUID and an immutable idempotency key before any outbound creation request. The local UUID is passed as `metadata.order_id`. Repeated requests use the same key and parameters so a timeout cannot silently create a second billable generation.

**Asynchronous processing.** Provider creation runs in a database-backed queue instead of holding an HTTP request open. The additional worker process is a deliberate trade-off for responsiveness and recoverable retries.

**Failure semantics.** Validation and policy rejections are distinct from provider failures. Rate limits and overloads use bounded retries and `Retry-After` where available. If the outcome of a submission cannot be determined, its state remains `submission_unknown` rather than being treated as a confirmed failure.

**State safety.** The acknowledgement handler uses a database transaction and row lock. An acknowledgement cannot overwrite an already terminal generation or replace an established provider ID.

Eloquent is used directly for persistence. A generic repository abstraction is intentionally avoided because it would add indirection without improving these workflows.

## Testing and quality checks

```bash
php artisan test
composer ci:check
```

The CI checks include PHP formatting, static analysis, frontend checks, and PHPUnit. Integration tests use Laravel's HTTP fakes to exercise the provider contract, idempotency, timeouts, overloads, acknowledgement races, and persisted generation state. SQLite in-memory is used for automated tests; PostgreSQL is the target database for deployment.

## API documentation

[Pixelforge API reference](https://pixelforge.promptedit.dev/docs)
