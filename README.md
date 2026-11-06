# Foodies Academy

Recipe website: content is edited in **WordPress**, served by a **Laravel** API and built into a static site by **Astro**.
Visitor activity (likes, views, reviews, searches, subscriptions, accounts) is written into the WordPress plugins' own tables, so it is all visible and managed in the WordPress admin.

- Live site: https://foodies.academy
- Production after the move: https://foodies.academy · https://api.foodies.academy/api · https://wp.foodies.academy/wp-admin
- Dev: https://dev.foodies.academy (site) · https://api.dev.foodies.academy/api (API) · https://wp.dev.foodies.academy/wp-admin

## Architecture

```
WordPress admin ──▶ site database (WordPress, prefix artit_) ◀── Laravel writes likes, views, reviews, users
                              │
                              ▼
                    Laravel API (/api/*) ──▶ Astro build ──▶ static HTML (dev.foodies.academy)
                              ▲
        browser: reviews, likes, views, search, log-in, favourites
```

- Laravel reads the WordPress database directly (connection `site`). It calls WordPress itself only for users (mu-plugin `foodies-users.php`) and subscriptions (Contact Form 7), with a shared `FOODIES_API_SECRET`.
- Laravel has its own small database for sessions, cache and log-in tokens.
- A content change in WordPress notifies Laravel (`foodies-rebuild.php`), which clears its cache and starts the GitHub Actions build a few minutes later; there is also a nightly build.

## Stack

| Part | Technology | Folder |
|---|---|---|
| Site | Astro 7 (static), Less, TypeScript, Vitest | `astro-app/` |
| API | Laravel 12, PHP 8.3, PHPUnit | `laravel-app/` |
| CMS | WordPress 7.0.2, pinned plugins, own mu-plugins | `wp-content/mu-plugins/`, `wordpress/` |
| Databases | MySQL (MAMP locally, MariaDB on the server) | |
| Local | Docker Compose | `docker-compose.yml` |
| Deploy | GitHub Actions → HestiaCP over SSH/rsync | `.github/workflows/`, `deploy/hestia/` |

## Local development

1. Start MAMP (MySQL on port 3306): it holds the site database (`foodies_dev`).
2. Copy `.env.example` to `.env` and `laravel-app/.env.example` to `laravel-app/.env`; set `FOODIES_API_SECRET` and the `SITE_DB_*` values (the same database name in both files).
3. `docker compose up -d`

| Service | URL |
|---|---|
| Site (Astro dev server) | http://localhost:8082 |
| Laravel API | http://localhost:8000/api |
| WordPress admin | http://localhost:8081/wp-admin |
| Laravel's MySQL | localhost:3307 |

WordPress plugins are installed on container start (`wordpress/install-plugins.sh`); Laravel's scheduled commands run in the `scheduler` container.

## Tests

```bash
cd astro-app && npx vitest run                 # never calls a real API (tests/setup.ts)
docker exec foodies_laravel php artisan test   # WordPress tables in foodies_site_test
```

## Laravel commands

| Command | What it does |
|---|---|
| `site:prepare-db` | Adapts a copy of the live database: removes non-Russian WPML content, adds lifehacks, imports old likes and views, syncs videos |
| `site:rebuild [--force]` | Starts the site build in GitHub Actions |
| `videos:sync [--latest]` | Imports the YouTube channel's videos |
| `likes:import-legacy`, `views:import-legacy` | Move old counters into the plugins' ones |
| `slugs:check` | Reports invalid and duplicate slugs |

## Deploy

A push to `main` deploys only what changed to **dev**: `astro-app/**` → site, `laravel-app/**` → API (tests, rsync, migrations), `wp-content/mu-plugins/**` → WordPress code. Every workflow can also be run by hand from any branch.
**Production** (foodies.academy) is deployed by hand from `main` only: Actions → workflow → Run workflow → environment `production`.
Server setup, step by step: [`deploy/hestia/README.md`](deploy/hestia/README.md); moving to foodies.academy and rolling back: [`deploy/hestia/PRODUCTION.md`](deploy/hestia/PRODUCTION.md).

## More

Full description of the features, data model and working rules: [`CLAUDE.md`](CLAUDE.md).
