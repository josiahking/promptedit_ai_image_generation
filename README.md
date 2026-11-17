# PromptEdit — Pixelforge Image Generation

Senior Product Engineer take-home assessment, built on the official Laravel 13 Vue/Inertia starter kit.

**Status:** Laravel/Vue foundation and WP2 provider submission are implemented and CI-verified. Signed webhooks, polling recovery, generation UI, and public deployment are not yet complete. Do not submit this assessment until the required flows work.

## Stack

PHP 8.3+, Laravel 13, Vue 3, TypeScript, Inertia and Tailwind; PostgreSQL in deployment (SQLite permitted for local and CI testing).

## Setup

1. Install PHP 8.3+, Composer 2, Node 22+, npm and PostgreSQL.
2. Clone this repository; run composer install and npm install.
3. Copy .env.example to .env and run php artisan key:generate.
4. Configure DB_CONNECTION=pgsql and PostgreSQL connection fields. SQLite is the starter's default for quick tests.
5. Run php artisan migrate, npm run build and php artisan test.
6. Run php artisan serve and, once background jobs exist, php artisan queue:work.

Set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET only in .env or hosting secrets, never in Git. Local and production use distinct keys.

## Architecture

- Thin HTTP controllers and cohesive app/Actions.
- PixelforgeClient adapter handles authenticated HTTP, pricing, idempotent creation, and classified provider errors independently of billing.
- Money Value Object uses integer picodollars (12 decimal places); no PHP float arithmetic.
- PricingService computes provider charges from returned per-generation usage and creation-time rates; customer markup is 25%.
- PersistGeneration creates local ownership, ID and stable idempotency key before any external POST. SubmitGeneration schedules a queued job; RecordPixelforgeAcknowledgement cannot reverse terminal state.
- Migrations provide generation records and a deduplicated webhook-event inbox.

## CI verification

WP2 passed GitHub Actions on commit 6069cd5fe572bb522b690d236ef8679d893d4834: 55 PHPUnit tests (182 assertions), PHPStan, formatting, Vue typecheck and frontend build. CI uses in-memory SQLite, not production PostgreSQL. No live Pixelforge calls have been made.

## Work packages

WP1: starter, exact pricing and local persistence (CI verified).
WP2: Pixelforge HTTP adapter, queued submission, idempotent retry, rate limit handling and acknowledgement race guards (CI verified, live API pending).
WP3: HMAC-signed webhooks, event deduplication, terminal state transitions and polling recovery (pending).
WP4: Vue/Inertia generation form, history, images and estimates (pending).
WP5: tested public deployment, usage reconciliation and application submission (pending).

Source: https://pixelforge.promptedit.dev/docs

Working budget: 150-minute target and 180-minute maximum. Record actual time honestly.
