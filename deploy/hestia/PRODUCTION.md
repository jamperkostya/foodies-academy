# Прод: foodies.academy

Переезд выполнен: на foodies.academy работает собранный сайт, старого WordPress в `public_html` больше нет. Ниже — как устроен прод и как проходил переезд (для повторной подготовки базы и на случай, если его придётся повторить).

Боевой сайт устроен так же, как dev (`README.md` в этой папке), на том же сервере, но под **отдельным пользователем Hestia `foodies`** (dev — у `jamper`):

| Адрес | Что | Папка на сервере |
|---|---|---|
| `https://foodies.academy` | Статический сайт Astro | `/home/foodies/web/foodies.academy/public_html` |
| `https://api.foodies.academy` | Laravel API | `/home/foodies/web/api.foodies.academy/public_html` |
| `https://wp.foodies.academy` | WordPress: админка и картинки (`wp-content/uploads`) | `/home/foodies/web/wp.foodies.academy/public_html` |

| | dev (`jamper`) | прод (`foodies`) |
|---|---|---|
| База сайта (WordPress) | `jamper_foodies_site` | `foodies_wordpress` |
| База Laravel | `jamper_foodies_laravel` | `foodies_laravel` |
| Cron `schedule:run` | у `jamper` | у `foodies` |
| nginx `noindex` (`nginx.ssl.conf_foodies`) | `api.dev`, `wp.dev` | `api`, `wp` |
| nginx картинок (`nginx.ssl.conf_foodies_uploads`) | — | `foodies.academy` |

Команды Laravel и WordPress прода выполняются от `foodies`: `foodies@server` или под root через `sudo -u foodies …`, иначе файлы в `storage/` и `bootstrap/cache/` получат чужого владельца. `mariadb` и `mariadb-dump` без пароля работают только у root; у `jamper` нет `sudo`.

- На прод деплой идёт **только вручную** и только из `main`: **Actions → workflow → Run workflow → environment: production**.
- Адреса страниц не меняются (`/recipe/x`, без слэша на конце). Старые адреса, которые поменялись (`/course/x`, `/cuisine/x`, статьи `/slug` → `/blog/slug` и т. д.), получают 301 через `.htaccess`, который собирает Astro (`astro-app/src/build/htaccess.ts`).
- Картинки остаются по старым адресам `foodies.academy/wp-content/uploads/…`: nginx отдаёт их из папки WordPress (`nginx.ssl.conf_foodies_uploads`).
- Фронтенд WordPress на `wp.foodies.academy` перенаправляет на сайт (`foodies-frontend.php`), поэтому в поиск попадает только foodies.academy.

Где выполнять команды, указано у каждого блока: `root@server`, `foodies@server` или Mac.

---

## Часть 1. Подготовка (старый сайт работает)

### 1.1. Узнать базу старого сайта

`root@server`:

```bash
grep DB_NAME /home/foodies/web/foodies.academy/public_html/wp-config.php      # имя боевой базы, ниже LIVE_DB
ls /home/foodies/web/foodies.academy/public_html/*.html /home/foodies/web/foodies.academy/public_html/*.txt
```

Файлы подтверждения Search Console и Яндекс.Вебмастера (`google….html`, `yandex_….html`) и `ads.txt`, если они есть, нужно перенести в `astro-app/public/`. Иначе после переезда их не будет: деплой Astro заменяет всё содержимое папки.

### 1.2. Базы

`root@server`:

```bash
v-add-database foodies wordpress wordpress 'ПАРОЛЬ_SITE' mysql localhost utf8mb4
v-add-database foodies laravel laravel 'ПАРОЛЬ_LARAVEL' mysql localhost utf8mb4
```

Получатся базы и пользователи `foodies_wordpress` (копия боевой WordPress, на ней работает новый сайт) и `foodies_laravel`. Без `utf8mb4` Hestia создаёт базу в `utf8mb3_general_ci`, и такую кодировку получают новые таблицы плагинов. Исправить у существующей базы: `mariadb -e "ALTER DATABASE имя CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"` (данные не трогает).

### 1.3. Пробная копия базы

`root@server`. Делается так же в день переезда (часть 2), здесь — чтобы проверить всё заранее:

```bash
cd /root
mariadb-dump --single-transaction LIVE_DB | sed 's/DEFINER=[^*]*\*/\*/' > live.sql
mariadb foodies_wordpress < live.sql && rm live.sql
```

Адаптацию (`site:prepare-db`) и чистку запустим после установки Laravel (шаг 1.6).

### 1.4. Картинки

`root@server`. Копия, оригинал остаётся на месте:

```bash
mkdir -p /home/foodies/web/wp.foodies.academy/public_html/wp-content
rsync -a /home/foodies/web/foodies.academy/public_html/wp-content/uploads/ \
         /home/foodies/web/wp.foodies.academy/public_html/wp-content/uploads/
chown -R foodies:foodies /home/foodies/web/wp.foodies.academy/public_html/wp-content
```

### 1.5. WordPress на wp.foodies.academy

Как шаг 6 в `README.md`, со своими значениями:

- папка `/home/foodies/web/wp.foodies.academy/public_html` (команды `wp` — от `foodies`), база `foodies_wordpress`;
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
  cp nginx.ssl.conf_foodies /home/foodies/conf/web/$d/nginx.ssl.conf_foodies
done
nginx -t && systemctl reload nginx
```

Для самого `foodies.academy` файл `nginx.ssl.conf_foodies` **не ставить**: в нём `noindex`.

### 1.6. Laravel на api.foodies.academy

Как шаг 7 в `README.md`. В `.env` отличия от dev:

```
APP_ENV=production
APP_URL=https://api.foodies.academy
DB_DATABASE=foodies_laravel
DB_USERNAME=foodies_laravel
SITE_DB_DATABASE=foodies_wordpress
SITE_DB_USERNAME=foodies_wordpress
WP_INTERNAL_URL=https://wp.foodies.academy
FOODIES_API_SECRET=…тот же новый ключ, что в wp-config-foodies.php
GITHUB_DEPLOY_EVENT=content-changed-production
CF7_SUBSCRIBE_FORM_ID=28089
CF7_BOOK_FORM_ID=77
LOG_STACK=daily,sentry
LOG_LEVEL=warning
SENTRY_LARAVEL_DSN=…
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0
```

Без `GITHUB_DEPLOY_EVENT=content-changed-production` правки в боевом WordPress пересобирают **dev**, а не foodies.academy.

`GITHUB_DEPLOY_TOKEN` можно взять тот же, что на dev. `ACADEMY_UPLOADS_URL` оставить `https://foodies.academy/wp-content/uploads`.

`root@server` — cron:

```bash
v-add-cron-job foodies '*' '*' '*' '*' '*' \
  "cd /home/foodies/web/api.foodies.academy/public_html && php8.3 artisan schedule:run >> /dev/null 2>&1"
```

### 1.7. GitHub: окружение production

**Settings → Environments → New environment → `production`**:

- **Deployment branches and tags** → Selected branches → `main`;
- **Environment secrets** (`SSH_*` берутся из репозитория, если окружение их не переопределяет). Пользователь `SSH_USER` должен иметь право записи в папки прода в `/home/foodies/web/`; если у пользователя из репозитория таких прав нет, задать `SSH_USER` и `SSH_KEY` в окружении `production`.

| Имя | Значение |
|---|---|
| `DEPLOY_PATH` | `/home/foodies/web/foodies.academy/public_html_new` — до переезда, потом `…/public_html` |
| `LARAVEL_PATH` | `/home/foodies/web/api.foodies.academy/public_html` |
| `WP_PATH` | `/home/foodies/web/wp.foodies.academy/public_html` |

- **Environment variables**:

| Имя | Значение |
|---|---|
| `API_URL` | `https://api.foodies.academy/api` |
| `PUBLIC_API_URL` | `https://api.foodies.academy/api` |
| `SITE_URL` | `https://foodies.academy` |

Секреты и переменные уровня репозитория остаются для dev. Окружение `dev` GitHub создаст сам при первом запуске.

`root@server` — папка для сборки, пока старый сайт работает:

```bash
cd /home/foodies/web/foodies.academy
mkdir public_html_new && chown --reference=public_html public_html_new && chmod --reference=public_html public_html_new
```

### 1.8. Пробный запуск

**Actions → Run workflow → environment: production**, по порядку:

1. **Deploy WordPress code to Hestia** → `https://wp.foodies.academy/wp-admin` открывается, плагины активны.
2. **Deploy Laravel API to Hestia**.
3. `foodies@server` — адаптация базы:

   ```bash
   cd /home/foodies/web/api.foodies.academy/public_html
   php8.3 artisan site:prepare-db --force
   ```

   Чистка — `root@server`. Скрипт деплой на сервер не кладёт, его копируют с Mac: `scp deploy/hestia/clean-site-db.sh root@SERVER:/root/`. Перед запуском — бэкап, и проверить, что он весит мегабайты, а не байты (с `| gzip` файл создаётся, даже если `mariadb-dump` упал):

   ```bash
   mariadb-dump --single-transaction foodies_wordpress | gzip > /root/foodies_wordpress-before-clean-$(date +%Y%m%d-%H%M).sql.gz
   ls -lh /root/foodies_wordpress-before-clean-*.sql.gz
   bash /root/clean-site-db.sh foodies_wordpress
   ```

   Скрипт покажет новые отзывы на модерации, которых не было при чистке `foodies_dev`: их стоит посмотреть в админке. Повторный запуск безопасен.

   `foodies@server` (или `root@server`: `sudo -u foodies …`): `php8.3 artisan cache:clear`.
   Проверка: `curl https://api.foodies.academy/api/recipes/slugs | head -c 200`.
4. **Deploy Astro to Hestia** → сборка ляжет в `public_html_new`. Проверка, `root@server`:

   ```bash
   ls /home/foodies/web/foodies.academy/public_html_new | head
   grep -c RewriteRule /home/foodies/web/foodies.academy/public_html_new/.htaccess
   ```

Если всё прошло, подготовка закончена. Старый сайт всё это время работает как прежде.

---

## Часть 2. День переезда (около часа, лучше ночью)

1. **Старый WordPress:** **Настройки → Обсуждение** → снять «Разрешить оставлять комментарии». Контент больше не править.
2. **Свежая база** (`root@server`): повторить шаг 1.3 (перед этим `mariadb -e "DROP DATABASE foodies_wordpress; CREATE DATABASE foodies_wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`), потом пункт 3 шага 1.8: `site:prepare-db`, `clean-site-db.sh`, `cache:clear`.
3. **Свежие картинки** (`root@server`): повторить `rsync` из шага 1.4. Он докопирует только новые файлы.
4. **Сборка:** **Run workflow → Deploy Astro to Hestia → production**. Сборка ляжет в `public_html_new`.
5. **Переключение** (`root@server`):

   ```bash
   cd /home/foodies/web/foodies.academy
   mv public_html public_html_old && mv public_html_new public_html
   cp /путь/к/nginx.ssl.conf_foodies_uploads /home/foodies/conf/web/foodies.academy/nginx.ssl.conf_foodies_uploads
   nginx -t && systemctl reload nginx
   ```

6. В GitHub, окружение `production`: `DEPLOY_PATH` → `/home/foodies/web/foodies.academy/public_html`.
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

**Откат** (`root@server`). После переезда `public_html_old` на сервере уже нет: откатиться можно, только восстановив старый WordPress из бэкапа в `public_html`.

```bash
cd /home/foodies/web/foodies.academy
mv public_html public_html_new && mv public_html_old public_html
rm /home/foodies/conf/web/foodies.academy/nginx.ssl.conf_foodies_uploads && nginx -t && systemctl reload nginx
```

Через пару недель стабильной работы `public_html_old` и старую базу можно архивировать.

## Дальше

- Правки контента — в `https://wp.foodies.academy/wp-admin`. Сайт пересобирается сам через 5 минут тишины после последней правки, но не позже чем через 30 минут после первой (событие `content-changed-production`); сама сборка ~25–30 минут. Плюс ночная сборка.
- Новый код: PR в `main` → автоматически dev → проверка → **Run workflow → production** для нужных частей (Astro, Laravel, WordPress).
