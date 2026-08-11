# foodies-academy
Foodies Academy — recipe website. Astro frontend, Laravel backend, WordPress as headless CMS. Dockerized dev &amp; prod setup.

# Foodies Academy

Recipe website with a decoupled architecture: **Astro** for the public-facing frontend, **Laravel** as an API/business-logic layer, and **WordPress** as a headless CMS for content management. Fully dockerized for local development and production deployment.

**Live site:** https://foodies.academy

---

## Table of Contents

- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Services & Ports](#services--ports)
- [Environment Variables](#environment-variables)
- [Common Commands](#common-commands)
- [Database](#database)
- [WordPress ↔ Laravel Integration](#wordpress--laravel-integration)
- [Development Workflow](#development-workflow)
- [Production](#production)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

---

## Architecture

```
┌─────────────┐      HTTP (SSR/SSG)      ┌──────────────┐
│   Browser   │ ───────────────────────▶ │    Astro     │  :80 (dev) / :443 (prod)
└─────────────┘                          │  (frontend)  │
										  └──────┬───────┘
												 │ REST API calls
												 ▼
										  ┌──────────────┐
										  │   Laravel    │  :8000
										  │ (API layer)  │
										  └──────┬───────┘
												 │ WP REST API (read-only)
												 ▼
										  ┌──────────────┐
										  │  WordPress   │  :8081
										  │(headless CMS)│
										  └──────┬───────┘
												 │
												 ▼
										  ┌──────────────┐
										  │    MySQL     │  :3306
										  │ (2 databases)│
										  └──────────────┘
```

**Key architectural decisions:**

- **WordPress is content-only.** Editors use `/wp-admin` to manage recipes, categories, and media. WordPress is never queried directly by the frontend — it's not exposed as the site's rendering layer.
- **WordPress ↔ Laravel communication happens over the WP REST API (`/wp-json/wp/v2/...`)**, not direct MySQL access. This keeps Laravel decoupled from WordPress's internal schema (which can change with core/plugin updates) and avoids dealing with PHP-serialized meta fields directly. See [WordPress ↔ Laravel Integration](#wordpress--laravel-integration).
- **Laravel and WordPress each own a separate MySQL database** (`foodies_laravel` and `foodies_wp`) under separate credentials. Laravel never writes to WordPress's tables.
- **Astro consumes Laravel's API**, not WordPress directly, so there's a single source of truth for business logic (caching, transformation, auth) sitting in front of the CMS.

---

## Tech Stack

| Layer | Technology | Version |
|---|---|---|
| Frontend | [Astro](https://astro.build) | Node.js 22.22.2 |
| API / Backend | [Laravel](https://laravel.com) | 12.x (PHP 8.3) |
| CMS | [WordPress](https://wordpress.org) | 7.0.2 (PHP 8.3, Apache) |
| Database | [MySQL](https://www.mysql.com) | 9.7.1 |
| Containerization | Docker / Docker Compose | — |

Versions are pinned deliberately across every service (see [Environment Variables](#environment-variables) and the Dockerfiles) so that local, staging, and production environments stay in sync. Avoid `latest` tags — see [Contributing](#contributing).

---

## Prerequisites

- **Docker Desktop** (or an OCI-compatible alternative such as OrbStack/Colima) — version **4.20+** recommended. Older versions are known to have file-sync issues on macOS with bind-mounted `vendor`/`node_modules` directories.
- **Git**
- No local installation of PHP, Node.js, Composer, or MySQL is required — everything runs inside containers.

---

## Project Structure

```
foodies-academy/
├── docker-compose.yml          # Dev orchestration (with bind-mount volumes)
├── docker-compose.prod.yml     # Production orchestration (no bind mounts)
├── mysql-init/
│   └── 01-create-laravel-db.sql   # Runs once on first MySQL container init
├── laravel-app/
│   ├── Dockerfile.dev
│   ├── Dockerfile               # Production build (multi-stage)
│   ├── .env.example
│   └── ...                      # Standard Laravel 12 structure
├── astro-app/
│   ├── Dockerfile.dev
│   ├── Dockerfile               # Production build (multi-stage)
│   └── ...                      # Standard Astro structure
├── wp-content/
│   ├── themes/
│   ├── plugins/
│   └── uploads/                 # gitignored — not versioned
├── .gitignore
└── README.md
```

WordPress core (`wp-admin`, `wp-includes`, etc.) is **not** present on disk — it ships inside the official `wordpress` image. Only `wp-content` (themes, plugins, uploads) is bind-mounted, which is standard practice: WordPress core is never hand-edited or version-controlled.

---

## Getting Started

Clone the repo and bring up the full stack:

```bash
git clone <repo-url> foodies-academy
cd foodies-academy
docker-compose up -d
```

First run will build the `laravel` and `astro` images and pull `mysql`/`wordpress`. Then install dependencies (not baked into the dev images by design — see [Development Workflow](#development-workflow)):

```bash
# Laravel
docker-compose run --rm laravel composer install
docker-compose run --rm laravel php artisan key:generate
docker-compose run --rm laravel php artisan migrate

# Astro
docker-compose run --rm --entrypoint sh astro -c "npm install"
docker-compose restart astro
```

Then visit:

| Service | URL |
|---|---|
| Astro (site) | http://localhost |
| Laravel (API) | http://localhost:8000 |
| WordPress (admin) | http://localhost:8081/wp-admin |

On first WordPress run you'll be walked through the standard install wizard (site title, admin user, etc.).

---

## Services & Ports

| Service | Container name | Host port | Container port | Notes |
|---|---|---|---|---|
| `mysql` | `foodies_mysql` | `3306` | `3306` | Two databases: `foodies_wp`, `foodies_laravel` |
| `wordpress` | `foodies_wordpress` | `8081` | `80` | `8080` is avoided — commonly occupied by other local projects |
| `laravel` | `foodies_laravel` | `8000` | `8000` | `artisan serve` in dev; php-fpm + nginx in prod |
| `astro` | `foodies_astro` | `80` | `4321` | Default entry point for the site |

> Port `8081` (not the conventional `8080`) is used for WordPress specifically to avoid collisions with other Docker projects that commonly bind `8080`. Adjust freely in `docker-compose.yml` if it conflicts with your local setup — check first with `lsof -i :<port>`.

---

## Environment Variables

Each app has its own `.env`, gitignored, with a checked-in `.env.example` template.

### `laravel-app/.env`

| Variable | Dev value | Purpose |
|---|---|---|
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | `mysql` | Docker service name, **not** `localhost` |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | `foodies_laravel` | Laravel's own database — never WordPress's |
| `DB_USERNAME` | `laravel_user` | |
| `DB_PASSWORD` | `laravel_password` | |
| `WORDPRESS_API_URL` | `http://wordpress` | Internal Docker network hostname — **not** `localhost:8081` |
| `APP_KEY` | *(generated)* | Run `artisan key:generate`; never commit this |

### Root-level MySQL credentials (`docker-compose.yml`)

| Variable | Value | Purpose |
|---|---|---|
| `MYSQL_ROOT_PASSWORD` | `root_password` | Change before any non-local deployment |
| `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` | `foodies_wp` / `wp_user` / `wp_password` | WordPress's database, created automatically by the official image |

> ⚠️ **All credentials above are development defaults and are intentionally weak/predictable.** They must be overridden via a proper secrets mechanism (`.env` files excluded from git, Docker secrets, or your platform's secret manager) before any staging/production deployment. See [Production](#production).

**Why Docker service names instead of `localhost` for inter-service URLs:** containers on the same Docker Compose network resolve each other by service name via Docker's internal DNS. `localhost` inside a container refers to *that container itself*, not the host machine or a sibling container — a common source of confusion when wiring up service-to-service communication.

---

## Common Commands

```bash
# Start everything
docker-compose up -d

# Start a subset
docker-compose up -d mysql wordpress

# Tail logs for one service
docker-compose logs -f laravel

# Rebuild an image after Dockerfile changes
docker-compose build laravel

# Run a one-off command inside a service (container must not be running, or use exec)
docker-compose run --rm laravel php artisan migrate
docker-compose exec laravel php artisan tinker   # for an already-running container

# Shell into a running container
docker-compose exec laravel bash
docker-compose exec astro sh

# Stop everything (keeps volumes/data)
docker-compose down

# Stop and wipe all data (MySQL data, node_modules volume, etc.) — destructive
docker-compose down -v
```

---

## Database

Two logically separate databases live in the same `mysql` container:

- **`foodies_wp`** — owned by WordPress. Created automatically by the official `wordpress` image's entrypoint on first boot.
- **`foodies_laravel`** — owned by Laravel. Created via the init script in `mysql-init/01-create-laravel-db.sql`.

**Important:** scripts in `mysql-init/` only run once, the very first time the `db_data` volume is created. If you need to re-run them (e.g. after changing the init script), you must destroy and recreate the volume:

```bash
docker-compose down
docker volume rm foodies-academy_db_data   # check exact name via `docker volume ls`
docker-compose up -d mysql
```

This is destructive — it wipes all MySQL data, including anything entered through `/wp-admin`.

---

## WordPress ↔ Laravel Integration

Laravel treats WordPress strictly as a **read-only content source**, consumed over the WP REST API rather than direct SQL:

- Base URL: `WORDPRESS_API_URL` (`http://wordpress` inside the Docker network).
- Example: fetching published recipes → `GET http://wordpress/wp-json/wp/v2/posts`.
- Responses should be cached in Laravel (file/Redis cache) to avoid hammering WordPress on every request — content changes infrequently relative to site traffic.
- Laravel never writes to WordPress's database or tables. All content edits happen through `/wp-admin`.

**Why REST over direct DB access:** WordPress's schema (`wp_posts`, `wp_postmeta`, serialized meta, taxonomy joins) is an implementation detail that can shift with core/plugin updates. The REST API is a stable contract, decouples Laravel's data layer from WordPress internals, and lets both Laravel and Astro consume the same interface if needed. If a specific query pattern later proves too slow over HTTP, consider [Corcel](https://github.com/corcel-corp/corcel) as a scoped, read-only fallback rather than reverting wholesale to raw table access.

---

## Development Workflow

All three application containers (`laravel`, `astro`) bind-mount source code from the host, so edits made locally in your editor are reflected instantly inside the container — no rebuild needed for code changes.

- **Laravel**: `artisan serve --host=0.0.0.0` runs inside the container; PHP file changes are picked up on the next request.
- **Astro**: `astro dev --host 0.0.0.0` runs with hot module reload; browser refreshes automatically on save.
- **Dependencies are intentionally *not* baked into the dev images.** `vendor/` and `node_modules/` are installed post-build via `docker-compose run` into either a bind-mounted host directory (Laravel) or a named volume (`astro_node_modules`, for Astro — see note below). This keeps the images lean and avoids re-pulling dependencies on every rebuild.

  > **Why Astro uses a named volume for `node_modules` instead of a bind mount:** binaries inside `node_modules` are compiled for the container's architecture/OS, which can differ from the host (e.g. Apple Silicon Mac vs. the container's Linux). A named volume keeps `node_modules` entirely inside Docker's storage, isolated from whatever (or nothing) exists in the host's `astro-app/node_modules`.

- **Installing a new dependency:**
  ```bash
  # Laravel
  docker-compose exec laravel composer require some/package

  # Astro
  docker-compose exec astro npm install some-package
  ```

- **Only `wp-content` (themes/plugins/uploads) is editable on the host** for WordPress — core files live inside the image and are not meant to be hand-modified. If you need file-level access to WordPress core (rare, and generally discouraged), that's a deliberate deviation from this setup and should be discussed before implementing.

---

## Production

Production uses a **separate Compose file** (`docker-compose.prod.yml`) and **separate Dockerfiles** (`Dockerfile`, without the `.dev` suffix) for Laravel and Astro:

- No bind-mounted source code — application code is copied into the image at build time (`COPY . .`), producing immutable, deployable artifacts.
- Laravel serves via `php-fpm` behind `nginx`, not `artisan serve` (which is dev-only and not designed for production load).
- Astro is built to static/SSR output (`astro build`) and served via a production Node adapter or static file server, not the dev server.
- All credentials are injected via environment variables from a secrets manager — never committed, never defaulted to the dev placeholders above.

```bash
docker-compose -f docker-compose.prod.yml up -d --build
```

> **Status:** production configuration is in progress. This section will be expanded once `Dockerfile` (prod) for Laravel/Astro and `docker-compose.prod.yml` are finalized.

---

## Troubleshooting

**`yaml: line X: found character that cannot start any token`**
Your `docker-compose.yml` has tab characters instead of spaces (YAML doesn't allow tabs). Recreate the file with a text editor configured to use spaces, or via `cat > docker-compose.yml << 'EOF' ... EOF` in the terminal, which sidesteps editor auto-indent issues. Verify with `cat -et docker-compose.yml` (macOS: no `-A` flag; use `-e -t`) — tabs show as `^I`.

**`Bind for 0.0.0.0:PORT failed: port is already allocated`**
Another container (possibly from an unrelated project) already holds that port.
```bash
lsof -i :<port>
docker ps -a   # look for a container binding that port, even if Exited
```
Either stop the conflicting container or change the host-side port mapping in `docker-compose.yml`.

**Laravel: `Failed opening required '.../vendor/autoload.php'`**
Dependencies haven't been installed into the bind-mounted directory yet. Run:
```bash
docker-compose run --rm laravel composer install
```

**Composer: `symfony/... requires php >=8.4.1 -> your php version (8.3.x) does not satisfy that requirement`**
The `composer.lock` was generated against a different PHP version than what the container actually runs (e.g. if `composer create-project` was run via the standalone `composer` image, which may bundle a newer PHP than your app container). Fix by re-resolving the lock file **inside the actual app container**:
```bash
docker-compose run --rm laravel composer update
```
Always run `composer install`/`update` via `docker-compose run --rm laravel composer ...`, not a bare `docker run composer ...`, so dependency resolution happens against the correct PHP version.

**Astro: `sh: 1: astro: not found`**
`node_modules` didn't persist — likely because dependencies were installed with `docker-compose run --rm`, and `--rm` deletes anonymous volumes along with the container. Ensure `node_modules` is mapped to a **named** volume (not an anonymous one) in `docker-compose.yml`, then reinstall:
```bash
docker-compose run --rm --entrypoint sh astro -c "npm install"
docker-compose up -d astro
```

**Laravel: `MissingAppKeyException`**
```bash
docker-compose run --rm laravel php artisan key:generate
docker-compose restart laravel
```

**Laravel: `SQLSTATE[42S02]: Base table or view not found: ... sessions`**
Migrations haven't run against the target database yet:
```bash
docker-compose run --rm laravel php artisan migrate
```

**File sync is slow / intermittent `realpath`, `ENOENT`, or partial-file errors under heavy I/O (macOS)**
Usually caused by an outdated Docker Desktop version with a slow/buggy file-sharing backend. Update Docker Desktop to the latest version and ensure **VirtioFS** is selected under *Settings → General → File sharing implementation*. If issues persist, consider [OrbStack](https://orbstack.dev) as a drop-in replacement — same `docker`/`docker-compose` CLI, generally faster file I/O on macOS.

---

## Contributing

- **Pin versions explicitly** for every image (`mysql:9.7.1`, not `mysql:9` or `mysql:latest`) so dev, staging, and production stay reproducible. When bumping a version, update it in one place per service and note the change in the PR description.
- **Never commit `.env` files.** Update the corresponding `.env.example` whenever you add a new required variable.
- **`vendor/` and `node_modules/` are never committed.** They're gitignored and reproducible from `composer.lock` / `package-lock.json`.
- **WordPress core is never modified or committed.** Only `wp-content/themes`, `wp-content/plugins`, and custom code belong in version control; `wp-content/uploads` is gitignored (media should live in object storage in production, not the repo).
- Keep `Dockerfile.dev` (bind-mounted, live-reload) and `Dockerfile` (production, immutable build) in sync conceptually — same base image versions, same PHP/Node versions — even though their build steps differ.