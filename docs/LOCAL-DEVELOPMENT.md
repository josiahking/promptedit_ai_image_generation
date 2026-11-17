# Local testing with Laravel Sail

Sail runs Laravel, PostgreSQL, a database queue worker and the reconciliation scheduler without paid hosting. It is a local development environment, not a production deployment.

## Prerequisites

Install Docker Desktop (Linux containers / WSL2 on Windows), Git, PHP 8.3+ and Composer 2. Composer is needed once on the host to install the Sail executable; subsequent PHP and Node commands run inside Sail. If Composer is unavailable locally, use a temporary Composer container as shown below.

```bash
git clone https://github.com/josiahking/promptedit_ai_image_generation.git
cd promptedit_ai_image_generation
cp .env.example .env
```

Set the following values in `.env` before booting:

```dotenv
APP_NAME=PromptEdit
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_PORT=8000
VITE_PORT=5173
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=promptedit
DB_USERNAME=sail
DB_PASSWORD=password
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Use real Pixelforge keys only in your local `.env`. The webhook signing secret belongs to the exact API key whose webhooks you configure. The placeholder password is **only for local Docker**; never use it on a public database.

Install Composer dependencies with either your host Composer:

```bash
composer install
```

Or, from the repository root, without local Composer:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 install --no-interaction
```

On Windows PowerShell, prefer the host Composer method (or use the Composer container from WSL2). Docker must be running.

Start the stack:

```bash
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
./vendor/bin/sail npm run dev
```

The final command runs Vite in the foreground; use another terminal to browse http://localhost:8000. Worker and scheduler are already separate Compose services. Inspect them with `docker compose ps` and `docker compose logs --tail=100 worker scheduler`.

## Verification

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail composer ci:check
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan pixelforge:reconcile
```

To exercise tests against **PostgreSQL** instead of the project's default SQLite PHPUnit configuration:

```bash
./vendor/bin/sail exec -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=pgsql -e DB_DATABASE=promptedit_test laravel.test php artisan test
```

Create the `promptedit_test` database first:

```bash
docker compose exec -T pgsql sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" promptedit_test'
```

The test database command overrides the testing connection and assumes a separately created `promptedit_test` database; do not point tests at a database holding data you want to keep.

For provider reconciliation use UTC dates after completing real generations:

```bash
./vendor/bin/sail artisan pixelforge:usage-reconcile --start=2026-11-17 --end=2026-11-17
```

The reconciliation exits nonzero if any provider/local daily totals or stored per-generation charges disagree. It uses the API key in your local `.env`, so ensure those generations were made using that key.

## Live webhook test

A localhost URL is not publicly reachable by Pixelforge. Expose port 8000 through your preferred HTTPS tunnel, register `https://YOUR-TUNNEL/webhooks/pixelforge` in Pixelforge, and copy its signing secret to `PIXELFORGE_WEBHOOK_SECRET`. Restart/reload the app after changing configuration. Use `[succeed]`, `[fail]`, `[duplicate]`, `[slow]` and `[overloaded]` prompts to verify provider behavior. Compare completion-day usage with the command above. Temporary tunnels are for local tests only, not the final submitted public app.

Stop the environment with `./vendor/bin/sail down`. Avoid `down -v` unless you intend to delete the PostgreSQL volume.
