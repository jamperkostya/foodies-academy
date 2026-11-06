# Переезд на foodies.academy

Боевой сайт устроен так же, как dev (`README.md` в этой папке), на том же сервере и под тем же пользователем `jamper`:

| Адрес | Что | Папка на сервере |
|---|---|---|
| `https://foodies.academy` | Статический сайт Astro | `/home/jamper/web/foodies.academy/public_html` |
| `https://api.foodies.academy` | Laravel API | `/home/jamper/web/api.foodies.academy/public_html` |
| `https://wp.foodies.academy` | WordPress: админка и картинки (`wp-content/uploads`) | `/home/jamper/web/wp.foodies.academy/public_html` |

Сейчас в `foodies.academy/public_html` работает старый WordPress. В день переезда его папка переименовывается в `public_html_old`, а на её место встаёт собранный сайт. **Откат** — переименовать обратно.

- На прод деплой идёт **только вручную** и только из `main`: **Actions → workflow → Run workflow → environment: production**.
- Адреса страниц не меняются (`/recipe/x`, без слэша на конце). Старые адреса, которые поменялись (`/course/x`, `/cuisine/x`, статьи `/slug` → `/blog/slug` и т. д.), получают 301 через `.htaccess`, который собирает Astro (`astro-app/src/build/htaccess.ts`).
- Картинки остаются по старым адресам `foodies.academy/wp-content/uploads/…`: nginx отдаёт их из папки WordPress (`nginx.ssl.conf_foodies_uploads`).
- Фронтенд WordPress на `wp.foodies.academy` перенаправляет на сайт (`foodies-frontend.php`), поэтому в поиск попадает только foodies.academy.

Где выполнять команды, указано у каждого блока: `root@server`, `jamper@server` или Mac.

---

## Часть 1. Подготовка (старый сайт работает)

### 1.1. Узнать базу старого сайта

`root@server`:

```bash
grep DB_NAME /home/jamper/web/foodies.academy/public_html/wp-config.php      # имя боевой базы, ниже LIVE_DB
ls /home/jamper/web/foodies.academy/public_html/*.html /home/jamper/web/foodies.academy/public_html/*.txt
```

Файлы подтверждения Search Console и Яндекс.Вебмастера (`google….html`, `yandex_….html`) и `ads.txt`, если они есть, нужно перенести в `astro-app/public/`. Иначе после переезда их не будет: деплой Astro заменяет всё содержимое папки.

### 1.2. Базы

`root@server`:

```bash
v-add-database jamper foodies_prod_site foodies_prod_site 'ПАРОЛЬ_SITE' mysql
v-add-database jamper foodies_prod_laravel foodies_prod_laravel 'ПАРОЛЬ_LARAVEL' mysql
```

Получатся базы `jamper_foodies_prod_site` (копия боевой WordPress, на ней будет работать новый сайт) и `jamper_foodies_prod_laravel`.

### 1.3. Пробная копия базы

`root@server`. Делается так же в день переезда (часть 2), здесь — чтобы проверить всё заранее:

```bash
cd /home/jamper
mariadb-dump --single-transaction LIVE_DB | sed 's/DEFINER=[^*]*\*/\*/' > live.sql
mariadb jamper_foodies_prod_site < live.sql && rm live.sql
```

Адаптацию (`site:prepare-db`) и чистку запустим после установки Laravel (шаг 1.6).

### 1.4. Картинки

`root@server`. Копия, оригинал остаётся на месте:

```bash
mkdir -p /home/jamper/web/wp.foodies.academy/public_html/wp-content
rsync -a /home/jamper/web/foodies.academy/public_html/wp-content/uploads/ \
         /home/jamper/web/wp.foodies.academy/public_html/wp-content/uploads/
chown -R jamper:jamper /home/jamper/web/wp.foodies.academy/public_html/wp-content
```

### 1.5. WordPress на wp.foodies.academy

Как шаг 6 в `README.md`, со своими значениями:

- папка `~/web/wp.foodies.academy/public_html`, база `jamper_foodies_prod_site`;
- в `wp-config-foodies.php`:
  - `WP_HOME`/`WP_SITEURL` → `https://wp.foodies.academy`;
  - `WP_ENVIRONMENT_TYPE` → `production`;
  - `FOODIES_LARAVEL_URL` → `https://api.foodies.academy`;
  - `FOODIES_SITE_URL` → `https://foodies.academy`;
  - `FOODIES_API_SECRET` — **новый** ключ (`openssl rand -hex 32`), не тот, что на dev;
- `wp option update blog_public 1` — индексирование разрешено. Сам WordPress в поиск всё равно не попадёт: его страницы перенаправляют на сайт.

`root@server` — только админка и API закрыты от поиска:

```bash
for d in api.foodies.academy wp.foodies.academy; do
  cp nginx.ssl.conf_foodies /home/jamper/conf/web/$d/nginx.ssl.conf_foodies
done
nginx -t && systemctl reload nginx
```

Для самого `foodies.academy` файл `nginx.ssl.conf_foodies` **не ставить**: в нём `noindex`.

### 1.6. Laravel на api.foodies.academy

Как шаг 7 в `README.md`. В `.env` отличия от dev:

```
APP_ENV=production
APP_URL=https://api.foodies.academy
DB_DATABASE=jamper_foodies_prod_laravel
DB_USERNAME=jamper_foodies_prod_laravel
SITE_DB_DATABASE=jamper_foodies_prod_site
SITE_DB_USERNAME=jamper_foodies_prod_site
WP_INTERNAL_URL=https://wp.foodies.academy
FOODIES_API_SECRET=…тот же новый ключ, что в wp-config-foodies.php
GITHUB_DEPLOY_EVENT=content-changed-production
```

`GITHUB_DEPLOY_TOKEN` можно взять тот же, что на dev. `ACADEMY_UPLOADS_URL` оставить `https://foodies.academy/wp-content/uploads`.

`root@server` — cron:

```bash
v-add-cron-job jamper '*' '*' '*' '*' '*' \
  "cd /home/jamper/web/api.foodies.academy/public_html && php8.3 artisan schedule:run >> /dev/null 2>&1"
```

### 1.7. GitHub: окружение production

**Settings → Environments → New environment → `production`**:

- **Deployment branches and tags** → Selected branches → `main`;
- **Environment secrets** (остальные секреты — `SSH_*` — общие с dev):

| Имя | Значение |
|---|---|
| `DEPLOY_PATH` | `/home/jamper/web/foodies.academy/public_html_new` — до переезда, потом `…/public_html` |
| `LARAVEL_PATH` | `/home/jamper/web/api.foodies.academy/public_html` |
| `WP_PATH` | `/home/jamper/web/wp.foodies.academy/public_html` |

- **Environment variables**:

| Имя | Значение |
|---|---|
| `API_URL` | `https://api.foodies.academy/api` |
| `PUBLIC_API_URL` | `https://api.foodies.academy/api` |
| `SITE_URL` | `https://foodies.academy` |

Секреты и переменные уровня репозитория остаются для dev. Окружение `dev` GitHub создаст сам при первом запуске.

`root@server` — папка для сборки, пока старый сайт работает:

```bash
cd /home/jamper/web/foodies.academy
mkdir public_html_new && chown --reference=public_html public_html_new && chmod --reference=public_html public_html_new
```

### 1.8. Пробный запуск

**Actions → Run workflow → environment: production**, по порядку:

1. **Deploy WordPress code to Hestia** → `https://wp.foodies.academy/wp-admin` открывается, плагины активны.
2. **Deploy Laravel API to Hestia**.
3. `jamper@server` — адаптация и чистка базы:

   ```bash
   cd ~/web/api.foodies.academy/public_html
   php8.3 artisan site:prepare-db --force
   ```

   `root@server` (скрипт из `deploy/hestia/clean-site-db.sh`, скопировать на сервер):

   ```bash
   bash clean-site-db.sh jamper_foodies_prod_site
   ```

   Скрипт покажет новые отзывы на модерации, которых не было при чистке `foodies_dev`: их стоит посмотреть в админке.

   `jamper@server`: `php8.3 artisan cache:clear`.
   Проверка: `curl https://api.foodies.academy/api/recipes/slugs | head -c 200`.
4. **Deploy Astro to Hestia** → сборка ляжет в `public_html_new`. Проверка, `root@server`:

   ```bash
   ls /home/jamper/web/foodies.academy/public_html_new | head
   grep -c RewriteRule /home/jamper/web/foodies.academy/public_html_new/.htaccess
   ```

Если всё прошло, подготовка закончена. Старый сайт всё это время работает как прежде.

---

## Часть 2. День переезда (около часа, лучше ночью)

1. **Старый WordPress:** **Настройки → Обсуждение** → снять «Разрешить оставлять комментарии». Контент больше не править.
2. **Свежая база** (`root@server`): повторить шаг 1.3 (перед этим `mariadb -e "DROP DATABASE jamper_foodies_prod_site; CREATE DATABASE jamper_foodies_prod_site CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`), потом пункт 3 шага 1.8: `site:prepare-db`, `clean-site-db.sh`, `cache:clear`.
3. **Свежие картинки** (`root@server`): повторить `rsync` из шага 1.4. Он докопирует только новые файлы.
4. **Сборка:** **Run workflow → Deploy Astro to Hestia → production**. Сборка ляжет в `public_html_new`.
5. **Переключение** (`root@server`):

   ```bash
   cd /home/jamper/web/foodies.academy
   mv public_html public_html_old && mv public_html_new public_html
   cp /путь/к/nginx.ssl.conf_foodies_uploads /home/jamper/conf/web/foodies.academy/nginx.ssl.conf_foodies_uploads
   nginx -t && systemctl reload nginx
   ```

6. В GitHub, окружение `production`: `DEPLOY_PATH` → `/home/jamper/web/foodies.academy/public_html`.
7. **Проверка** (Mac):

   ```bash
   curl -sI https://foodies.academy/recipe/spaghetti-alla-carbonara | head -1          # 200
   curl -sI https://foodies.academy/recipe/spaghetti-alla-carbonara/ | grep -i location # без слэша
   curl -sI https://foodies.academy/course/burgers | grep -i location                   # /category/burgers
   curl -sI https://foodies.academy/wp-content/uploads/2018/09/DSC02931-1-300x300.jpg | head -1   # 200
   curl -s https://foodies.academy/robots.txt                                          # без Disallow: /
   curl -sI https://wp.foodies.academy/recipe/spaghetti-alla-carbonara | grep -i location  # на foodies.academy
   ```

   В браузере: главная, рецепт, отзыв (появится на модерации в `wp.foodies.academy/wp-admin`), лайк, регистрация и вход.
8. **Search Console и Яндекс.Вебмастер:** отправить `https://foodies.academy/sitemap.xml` (индекс: внутри отдельные карты рецептов, картинок, ингредиентов и т. д.). Старые карты Yoast (`sitemap_index.xml`, `recipe-sitemap.xml`…) перенаправляются на неё; в Search Console их можно удалить. Несколько дней смотреть ошибки 404 и при необходимости добавлять редиректы в `astro-app/src/build/htaccess.ts`.

**Откат** (`root@server`):

```bash
cd /home/jamper/web/foodies.academy
mv public_html public_html_new && mv public_html_old public_html
rm /home/jamper/conf/web/foodies.academy/nginx.ssl.conf_foodies_uploads && nginx -t && systemctl reload nginx
```

Через пару недель стабильной работы `public_html_old` и старую базу можно архивировать.

## Дальше

- Правки контента — в `https://wp.foodies.academy/wp-admin`. Сайт пересобирается сам через 5 минут (событие `content-changed-production`), плюс ночная сборка.
- Новый код: PR в `main` → автоматически dev → проверка → **Run workflow → production** для нужных частей (Astro, Laravel, WordPress).
