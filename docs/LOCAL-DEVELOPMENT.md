# Local development with Docker Compose

The local environment uses **plain Docker Compose** with a project-owned Dockerfile. Laravel Sail is not required or used. Railway production remains separate and is not changed by these commands.

## Requirements

Install Docker Desktop (Linux containers enabled) and Git. On Windows, Docker Desktop should use the WSL2 backend, but **all commands below can be run from Git Bash or PowerShell**. You do not need PHP, Composer, Node or PostgreSQL installed on the host.

## First-time setup

Clone the repository if needed, then create a local configuration:

```bash
git clone https://github.com/josiahking/promptedit_ai_image_generation.git
cd promptedit_ai_image_generation
cp .env.docker.example .env
```

If you have an existing local `.env`, **back it up first** before replacing it. The example uses `DB_HOST=db` and a local-only password. Do not copy it to Railway.

Build the application image **once** before starting the other services that share it:

```bash
docker compose build app
docker compose up -d db
```

Install the application dependencies **inside Docker**, not on the Windows host:

```bash
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app npm install
docker compose run --rm --no-deps app php artisan key:generate
```

Start the web app, apply migrations, then start the remaining processes:

```bash
docker compose up -d app
docker compose exec -T app php artisan migrate --force
docker compose up -d frontend worker scheduler
docker compose ps
```

Visit **http://localhost:8000**. Vite is already running inside the `frontend` service at port 5173. No separate `npm run dev` terminal is required. The worker and scheduler run as dedicated services.

The image builds PHP 8.3 with PostgreSQL and SQLite extensions, Composer 2, and Node 22. It does not install Sail, a separate PostgreSQL APT repository, or browser-testing packages.

## Daily workflow

```bash
docker compose up -d
docker compose ps
docker compose logs --tail=80 app frontend worker scheduler
```

If you change the Dockerfile, rebuild explicitly:

```bash
docker compose build app
docker compose up -d --no-build --force-recreate app frontend worker scheduler
```

After editing PHP or Vue files, bind mounts make changes available immediately. You can run one-off commands:

```bash
docker compose exec -T app php artisan route:list
docker compose exec -T app php artisan test
docker compose exec -T app composer ci:check
docker compose exec -T app npm run build
```

## Test against PostgreSQL safely

The default test setup uses SQLite in memory. To run the suite against PostgreSQL, **create a dedicated test database**:

```bash
docker compose exec -T db sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" promptedit_test'
```

Then run the suite with explicit test database settings:

```bash
docker compose exec -T -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=db -e DB_DATABASE=promptedit_test app php artisan test
```

Never run destructive tests against your normal development database or Railway production database.

## Pixelforge local testing

Add a **separate local** `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` to `.env`; restart the services after changing environment values:

```bash
docker compose up -d --force-recreate app worker scheduler
```

Try prompts containing `[succeed]`, `[fail]`, `[duplicate]`, `[slow]` and `[overloaded]` to exercise provider behavior.

The provider cannot call localhost for webhooks. Use an HTTPS tunnel pointing at local port 8000, register `https://YOUR-TUNNEL/webhooks/pixelforge` for the **local** key, and configure that key's webhook secret in local `.env`. The local key and production Railway key should be different.

```bash
docker compose exec -T app php artisan pixelforge:reconcile
docker compose exec -T app php artisan pixelforge:usage-reconcile --start=2026-11-17 --end=2026-11-17
```

Replace the dates with the real UTC completion dates.

## Troubleshooting on Windows

- **Sail reports an unsupported operating system:** these instructions do not use Sail; run `docker compose` directly.
- **Docker cannot connect:** start Docker Desktop and enable Linux containers / WSL2 integration.
- **Port 5432 in use:** set `FORWARD_DB_PORT=5433` in `.env`, then recreate the `db` service. The internal database host stays `db:5432`.
- **Port 8000 in use:** set `APP_PORT=8001` and `APP_URL=http://localhost:8001` in `.env`.
- **Missing `vendor/autoload.php` or Node modules:** rerun the corresponding Docker-based Composer or npm install command.
- **Database connection refused:** verify `docker compose ps`, `docker compose logs db`, and `DB_HOST=db`.
- **Blank page or stale Vite:** check `docker compose logs frontend` and ensure both 8000 and 5173 are free.
- **Initial image download fails:** retry `docker compose build app`; don't edit ignored dependencies inside `vendor`.

Shut down containers with `docker compose down`. Do **not** use `down -v` unless you intend to delete your local PostgreSQL data and Node modules.

## Railway production

Railway remains the public demonstration environment at https://prompteditaiimagegeneration-production.up.railway.app with a separate PostgreSQL service and worker/scheduler. This Compose configuration is for **local development only**. Never copy its development credentials to Railway.
