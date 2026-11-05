# Деплой Foodies на HestiaCP (dev)

Три сайта на одном сервере, без Docker: каждый сервис — обычный веб-домен Hestia.

| Адрес | Что | Откуда берётся |
|---|---|---|
| `https://dev.foodies.academy` | Сайт: статические HTML из Astro | GitHub Actions собирает `astro-app` и копирует `dist/` (`deploy.yml`) |
| `https://api.dev.foodies.academy` | Laravel API | GitHub Actions: тесты → rsync кода → миграции (`deploy-laravel.yml`) |
| `https://wp.dev.foodies.academy` | WordPress: админка, контент | Ядро ставится один раз на сервере; mu-plugins и плагины — из git (`deploy-wordpress.yml`) |

```
Браузер ──▶ dev.foodies.academy (HTML)
   │ отзывы, лайки, вход
   ▼
api.dev.foodies.academy (Laravel) ──▶ БД jamper_foodies_site (WordPress)  ◀── wp.dev.foodies.academy
   │                                   БД jamper_foodies_laravel
   └─ правки контента в WordPress → Laravel → GitHub Actions пересобирает dev.foodies.academy
```

Все деплои запускаются при push в `main` (каждый — только если менялась его папка), вручную — **Actions → workflow → Run workflow**.
Workflow видны в GitHub только после того, как файлы попадут в `main`.

Ниже `SERVER` — IP сервера, пользователь Hestia — `jamper`.

---

## 1. DNS

Три A-записи на IP сервера (если DNS-зона домена в самой Hestia — **DNS → foodies.academy → Add Record**):

```
dev.foodies.academy      A  SERVER
api.dev.foodies.academy  A  SERVER
wp.dev.foodies.academy   A  SERVER
```

Проверка: `dig +short api.dev.foodies.academy` отдаёт IP сервера. Без этого не выпустится SSL в шаге 3.

## 2. Пользователь, SSH и WP-CLI

Под root на сервере. Пользователь создаётся в панели: **Users → Add User** → `jamper` (пароль задайте сами). Дальше:

```bash
# PHP 8.3 и bash для пользователя (rsync и деплой не работают с nologin)
ls /usr/bin/php8.3                    # если нет: Server → Configure → PHP → включить 8.3
v-change-user-shell jamper bash
```

Ключ, которым GitHub Actions заходит на сервер. Сгенерировать **локально**:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/foodies_deploy -N "" -C "github-deploy"
```

Публичную часть (`~/.ssh/foodies_deploy.pub`) добавить пользователю: **Users → jamper → Edit → SSH keys** (или `v-add-user-ssh-key jamper "ssh-ed25519 AAAA… github-deploy"` под root). Приватная пойдёт в GitHub (шаг 8).

WP-CLI для пользователя (под `jamper`):

```bash
su - jamper
mkdir -p ~/bin
curl -fsSL -o ~/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
chmod +x ~/bin/wp
php8.3 ~/bin/wp --info
```

## 3. Три веб-домена

Под root (то же можно сделать в **Web → Add Web Domain**, убрав алиас `www.` и включив SSL):

```bash
for d in dev.foodies.academy api.dev.foodies.academy wp.dev.foodies.academy; do
  v-add-web-domain jamper $d
  v-delete-web-domain-alias jamper $d www.$d     # www.dev… нет в DNS, Let's Encrypt на нём упадёт
  v-add-letsencrypt-domain jamper $d
  v-add-web-domain-ssl-force jamper $d          # http → https
done

# PHP 8.3 для Laravel и WordPress
v-change-web-domain-backend-tpl jamper api.dev.foodies.academy PHP-8_3
v-change-web-domain-backend-tpl jamper wp.dev.foodies.academy PHP-8_3

# Laravel лежит в public_html, наружу смотрит только public_html/public
# (в панели: Edit Web Domain → Advanced Options → Custom document root)
v-change-web-domain-docroot jamper api.dev.foodies.academy api.dev.foodies.academy public
```

Laravel кладётся внутрь `public_html`, а не рядом: так он остаётся в пределах `open_basedir`, который Hestia задаёт для PHP домена. `.env` и код при этом недоступны из браузера — корень сайта `public/`.

Запрет индексации (заголовок `X-Robots-Tag: noindex`) и, по желанию, пароль на dev-сайт — файл `nginx.ssl.conf_foodies` из этой папки:

```bash
for d in dev.foodies.academy api.dev.foodies.academy wp.dev.foodies.academy; do
  cp nginx.ssl.conf_foodies /home/jamper/conf/web/$d/nginx.ssl.conf_foodies
done
nginx -t && systemctl reload nginx
```

Пароль (`auth_basic`) включайте только на `dev.foodies.academy`: на `api.dev` и `wp.dev` он сломает сайт — браузер ходит в API напрямую, Laravel — в WordPress. `robots.txt` сайта на любом адресе, кроме foodies.academy, и так закрывает всё (`Disallow: /`).

## 4. Базы данных

**DB → Add Database** (Hestia сама добавит префикс `jamper_`) или под root:

```bash
v-add-database jamper foodies_site foodies_site 'ПАРОЛЬ_SITE' mysql        # → база и пользователь jamper_foodies_site
v-add-database jamper foodies_laravel foodies_laravel 'ПАРОЛЬ_LARAVEL' mysql  # → jamper_foodies_laravel
```

`jamper_foodies_site` — **копия** базы WordPress. Боевую базу foodies.academy к dev не подключать: Laravel пишет в неё лайки, просмотры, поиски и пользователей.

## 5. Перенос базы сайта

База для dev — копия боевой базы foodies.academy, приведённая к тому, что ждёт сайт, командой
`php artisan site:prepare-db`: удаляет переводы WPML не на русском, добавляет лайфхаки
(`laravel-app/database/content/lifehacks.json`), переносит старые лайки и просмотры в счётчики плагинов,
загружает видео с YouTube. Команду можно запускать повторно; она же понадобится при переезде на боевой домен.

Локально (MAMP должен быть запущен):

```bash
# 1. Копия боевой базы: foodies.academy → foodies_dev (оригинал не меняется)
M=/Applications/MAMP/Library/bin
$M/mysql -h127.0.0.1 -uroot -proot -e "CREATE DATABASE foodies_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$M/mysqldump -h127.0.0.1 -uroot -proot --single-transaction foodies.academy | $M/mysql -h127.0.0.1 -uroot -proot foodies_dev

# 2. Адаптация
docker exec -e SITE_DB_DATABASE=foodies_dev foodies_laravel php artisan site:prepare-db --force

# 3. Дамп и копирование на сервер
deploy/hestia/export-site-db.sh                 # → backups/jamper_foodies_site-ДАТА.sql.gz
scp backups/jamper_foodies_site-*.sql.gz root@SERVER:/home/jamper/
```

На сервере под root:

```bash
gunzip -c /home/jamper/jamper_foodies_site-*.sql.gz | mariadb jamper_foodies_site
rm /home/jamper/jamper_foodies_site-*.sql.gz
```

Скрипт убирает из дампа `DEFINER=` (пользователь базы на сервере не root). База локально на MySQL 5.7 с `utf8mb4_unicode_ci`, поэтому импортируется и в MariaDB, и в MySQL.

Страницы сайта (политика, правила, контакты, блоки главной) создаст mu-plugin `foodies-site-pages.php` при первом запуске WordPress (шаг 9.1).

## 6. WordPress на wp.dev.foodies.academy

Под `jamper`:

```bash
cd ~/web/wp.dev.foodies.academy/public_html
rm -f index.html robots.txt                      # заглушка Hestia
alias wp='php8.3 ~/bin/wp'

# Ядро той же версии, что в Docker (wordpress:7.0.2)
wp core download --version=7.0.2 --locale=ru_RU
wp config create --dbname=jamper_foodies_site --dbuser=jamper_foodies_site --dbpass='ПАРОЛЬ_SITE' \
  --dbhost=localhost --dbprefix=artit_ --skip-check
```

Настройки, которые в Docker приходят из переменных окружения, — файл `wp-config-foodies.example.php` из этой папки. Скопировать на сервер как `public_html/wp-config-foodies.php`, вписать ключ и подключить:

```bash
openssl rand -hex 32                              # ключ → FOODIES_API_SECRET здесь и в .env Laravel
nano wp-config-foodies.php                        # вставить ключ
sed -i "1a require_once __DIR__ . '/wp-config-foodies.php';" wp-config.php
chmod 600 wp-config-foodies.php

wp option get home                                # https://wp.dev.foodies.academy
wp option update blog_public 0                    # «Попросить поисковые системы не индексировать сайт»
wp theme list                                     # если активной темы нет на сервере:
wp theme activate twentytwentyfive                #   сайт headless, тема нужна только чтобы WordPress не ругался
```

mu-plugins и плагины (Contact Form 7, Yoast, WP Ultimate Recipe…) поставит первый запуск **Deploy WordPress code to Hestia** (шаг 9). Вручную то же самое:

```bash
WP_PATH=$PWD WP_CLI="php8.3 $HOME/bin/wp" bash /путь/к/install-plugins.sh
```

`wp-content/uploads` не нужен: картинки рецептов сайт берёт с foodies.academy (`ACADEMY_UPLOADS_URL`). Поэтому в админке dev старые картинки медиатеки будут без превью, а новые, загруженные на dev, не появятся на dev-сайте — контент с картинками правится на боевом сайте.

## 7. Laravel на api.dev.foodies.academy

Под `jamper` — `.env` до первого деплоя (миграции без него не запустятся):

```bash
cd ~/web/api.dev.foodies.academy/public_html
rm -f index.html robots.txt
nano .env                                          # содержимое laravel.env.example, заполнить пустые
php8.3 -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # → APP_KEY
chmod 600 .env
```

- `FOODIES_API_SECRET` — тот же ключ, что в `wp-config-foodies.php`.
- `GITHUB_DEPLOY_TOKEN` — fine-grained token GitHub: репозиторий `jamperkostya/foodies-academy`, **Contents: Read and write**. Без него правки в WordPress не пересобирают сайт (только пишется в лог).

Расписание (синхронизация видео с YouTube, пересборка сайта) — вместо контейнера `scheduler`. Под root:

```bash
v-add-cron-job jamper '*' '*' '*' '*' '*' \
  "cd /home/jamper/web/api.dev.foodies.academy/public_html && php8.3 artisan schedule:run >> /dev/null 2>&1"
```

Очередей в коде нет, отдельный `queue:work` не нужен.

## 8. GitHub: секреты и переменные

**Settings → Secrets and variables → Actions**.

Secrets:

| Имя | Значение |
|---|---|
| `SSH_HOST` | IP сервера |
| `SSH_PORT` | `22` (или ваш порт SSH) |
| `SSH_USER` | `jamper` |
| `SSH_KEY` | содержимое **приватного** `~/.ssh/foodies_deploy` |
| `DEPLOY_PATH` | `/home/jamper/web/dev.foodies.academy/public_html` |
| `LARAVEL_PATH` | `/home/jamper/web/api.dev.foodies.academy/public_html` |
| `WP_PATH` | `/home/jamper/web/wp.dev.foodies.academy/public_html` |

Variables:

| Имя | Значение |
|---|---|
| `API_URL` | `https://api.dev.foodies.academy/api` — откуда сборка берёт данные |
| `PUBLIC_API_URL` | `https://api.dev.foodies.academy/api` — куда браузер шлёт отзывы, лайки, вход |
| `SITE_URL` | `https://dev.foodies.academy` — canonical, sitemap, robots.txt |
| `PHP_BIN` | `php8.3` (необязательно, это значение по умолчанию) |

## 9. Первый запуск — по порядку

Каждый следующий зависит от предыдущего. **Actions → … → Run workflow**, ветка `main`:

1. **Deploy WordPress code to Hestia** — mu-plugins и плагины.
   Проверка: `https://wp.dev.foodies.academy/wp-admin` открывается, в **Плагины** всё активно.
2. **Deploy Laravel API to Hestia** — тесты, код, миграции.
   Проверка: `curl https://api.dev.foodies.academy/api/recipes/slugs | head -c 200` — JSON со списком.
3. **Deploy Astro to Hestia** — сборка ~1000 страниц из API (3–5 минут) и выкладка.
   Проверка: `https://dev.foodies.academy` — главная с рецептами, `/no-such-page` — наша страница 404, `/robots.txt` — `Disallow: /`.

Затем — связки между сервисами:

- **Отзыв:** оставить на странице рецепта → в админке WP **Комментарии** он «на модерации» → одобрить → через несколько минут сборка в Actions запускается сама (`content-changed`), отзыв на сайте.
- **Регистрация и вход** на `dev.foodies.academy/register` — пользователь появляется в WP **Пользователи** (Laravel → WordPress API по `FOODIES_API_SECRET`).
- **Cron:** `tail ~/web/api.dev.foodies.academy/public_html/storage/logs/laravel-*.log` — нет ошибок `site:rebuild` / `videos:sync`.

## 10. Как дальше

| Что изменилось в `main` | Что происходит |
|---|---|
| `astro-app/**` | сборка и выкладка сайта |
| `laravel-app/**` | тесты → код → миграции → сброс кэша API |
| `wp-content/mu-plugins/**`, `wordpress/install-plugins.sh` | mu-plugins и плагины |
| контент в WordPress (публикация, правка, одобренный отзыв) | Laravel сбрасывает кэш и через несколько минут запускает сборку сайта |
| ничего | сборка каждую ночь в 04:30 (просмотры, рейтинги, новые видео) |

Не трогать руками: `dev.foodies.academy/public_html` (rsync `--delete` удалит всё, чего нет в сборке), код в `api.dev…/public_html` и `wp-content/mu-plugins` — перезапишутся при следующем деплое. На сервере правятся только `.env`, `wp-config-foodies.php` и `wp-config.php`.

## Если что-то не работает

| Симптом | Причина |
|---|---|
| Actions: `Permission denied (publickey)` | ключ не добавлен пользователю `jamper` или у него shell `nologin` (шаг 2) |
| Laravel 500, в `storage/logs` — `open_basedir` | код положен не в `public_html` или docroot не `public` (шаг 3) |
| Регистрация или вход выдают ошибку | разные `FOODIES_API_SECRET` в `.env` и `wp-config-foodies.php`, или `WP_INTERNAL_URL` не `https://wp.dev…` |
| Правки в WordPress не пересобирают сайт | пустой `GITHUB_DEPLOY_TOKEN`, нет cron (шаг 7) или `FOODIES_LARAVEL_URL` в `wp-config-foodies.php` |
| Сборка Astro падает на `fetch` | API недоступен из интернета: проверить `curl` из шага 9.2 и переменную `API_URL` |
| Страницы сайта отдают 404 Apache | не скопирован `.htaccess` из сборки; при nginx без Apache добавить в `nginx.ssl.conf_foodies` строку `error_page 404 /404.html;` (только для dev.foodies.academy) |
