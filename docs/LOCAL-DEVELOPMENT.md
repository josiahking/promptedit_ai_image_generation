# Local development with Laravel Sail

Use this setup for local development and testing. Railway is the separate public production deployment; it uses its own PostgreSQL database and environment variables.

## Requirements

- Docker Desktop with Linux containers and WSL2 enabled on Windows
- Git
- Composer 2 and PHP 8.3+ **or** a temporary Composer Docker container for bootstrapping

## First-time setup

From Windows Git Bash, WSL or a Linux/macOS terminal:

```bash
git clone https://github.com/josiahking/promptedit_ai_image_generation.git
cd promptedit_ai_image_generation
cp .env.sail.example .env
composer install
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

The final command runs Vite in the foreground. Keep that terminal open and visit **http://localhost:8000**. Worker and scheduler run as separate Docker Compose services.

If Composer is not installed locally, substitute the `composer install` step with the following command in **WSL2/Linux**:

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 install --no-interaction
```

In **Windows Git Bash**, Docker's Windows path translation can interfere with mounts. Use:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 install --no-interaction
```

If Docker Compose reports port 5432 in use, set `FORWARD_DB_PORT=5433` in `.env`. If 8000 is used, set `APP_PORT=8001` and `APP_URL=http://localhost:8001`. Restart Sail after changing ports.

The `.env.sail.example` file is a safe **local-only template**. Keep `.env`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` out of Git. Use **a different Pixelforge API key per environment**, as each key has its own usage report and webhook secret.

## Daily commands

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev
```

The local setup includes Laravel/PHP 8.3, PostgreSQL 17, queue worker, scheduler and Vite. Check that all processes are healthy:

```bash
./vendor/bin/sail ps
docker compose logs --tail=80 worker scheduler
./vendor/bin/sail artisan schedule:list
```

## Testing

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail composer ci:check
```

The project's default test configuration uses SQLite. For a separate PostgreSQL test database, first create it:

```bash
docker compose exec -T pgsql sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" promptedit_test'
```

Then run the suite against **that test database only**:

```bash
./vendor/bin/sail exec -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=pgsql -e DB_DATABASE=promptedit_test laravel.test php artisan test
```

Never run tests using your development database or Railway production database.

## Testing Pixelforge locally

Add your local key to `.env`, then restart the web and worker services:

```bash
./vendor/bin/sail restart
```

Try `[succeed]`, `[fail]`, `[duplicate]`, `[slow]` and `[overloaded]` in prompts to test provider behavior.

Pixelforge cannot deliver webhook events directly to localhost. For real webhooks, expose port 8000 through an HTTPS tunnel and register `https://YOUR-TUNNEL/webhooks/pixelforge` as your **local key's** webhook URL. Use that key's signing secret in `.env`; never reuse the production key or secret. You can verify polling recovery independently with:

```bash
./vendor/bin/sail artisan pixelforge:reconcile
```

After completing real generations, reconcile the key's UTC daily usage:

```bash
./vendor/bin/sail artisan pixelforge:usage-reconcile --start=2026-11-17 --end=2026-11-17
```

Replace the dates with actual generation completion days. The command exits nonzero on any discrepancy.

Stop your local containers using `./vendor/bin/sail down`. Do not run `down -v` unless you intend to remove local PostgreSQL data.

## Railway production

Railway serves the public app at https://prompteditaiimagegeneration-production.up.railway.app and uses a separate PostgreSQL service, queue worker and scheduler. Never copy the local Compose password into Railway. Push changes to `main` only after checks pass; Railway redeploys from GitHub. Set Pixelforge credentials securely in Railway service variables, not in the repository.
