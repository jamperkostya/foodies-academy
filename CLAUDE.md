# Foodies Academy

Сайт рецептов foodies.academy. Контент ведётся в WordPress, Laravel отдаёт его API, Astro собирает из API статический сайт. Активность посетителей (лайки, просмотры, отзывы, поиски, подписки, аккаунты) Laravel пишет в таблицы плагинов WordPress, чтобы всё было видно и управлялось в админке WordPress.

## Стек

| Часть | Технология | Где |
|---|---|---|
| Сайт | Astro 7 (статическая сборка, без адаптера), Less, TypeScript, Vitest | `astro-app/` |
| API | Laravel 12, PHP 8.3, PHPUnit | `laravel-app/` |
| CMS | WordPress 7.0.2 + плагины с закреплёнными версиями, свои mu-plugins | `wp-content/mu-plugins/`, `wordpress/` |
| Базы | MySQL: база сайта (WordPress, префикс `artit_`) + собственная база Laravel | MAMP локально, MariaDB 11.4 на сервере |
| Локально | Docker Compose (`docker-compose.yml`) | |
| Деплой | GitHub Actions → HestiaCP по SSH/rsync | `.github/workflows/`, `deploy/hestia/` |

## Архитектура

```
WordPress (админка) ──┐
                      ▼
            база сайта (artit_*)  ◀── Laravel пишет активность: лайки, просмотры, отзывы, пользователи
                      │
                      ▼
            Laravel API (/api/*) ──▶ Astro build (getStaticPaths + fetch) ──▶ статические HTML
                      ▲
   браузер: отзывы, лайки, просмотры, поиск, вход, избранное (PUBLIC_API_URL)
```

- Laravel читает базу WordPress **напрямую** (соединение `site`, `config/database.php`), а не через WP REST API. Исключения, где нужен сам WordPress: пользователи (регистрация, вход, профиль — mu-plugin `foodies-users.php`) и подписки (REST Contact Form 7). Ключ — общий `FOODIES_API_SECRET`.
- Ответы API кэшируются на 10 минут, кэш `failover` (база, а при её падении — память).
- Пересборка сайта: правка в WordPress → mu-plugin `foodies-rebuild.php` → `POST /api/internal/content-changed` → Laravel сбрасывает кэш → `site:rebuild` (раз в минуту) запускает GitHub Actions через `repository_dispatch`, выждав 5 минут тишины (максимум 30). Плюс ночная сборка в 04:30. Без `GITHUB_DEPLOY_TOKEN` только пишет в лог.

## Базы данных

- **База сайта** — база WordPress. Локально в MAMP: `foodies_dev` (адаптированная копия боевой `foodies.academy`). На dev-сервере: `jamper_foodies_site`. Старая проектная `foodies.academy_with_content` больше не используется.
- **База Laravel** — сессии, кэш, токены входа (`api_tokens`), очередь. Локально контейнер `mysql` (`foodies_laravel`), на сервере `jamper_foodies_laravel`. Заполняется миграциями.
- **Тестовая** — `foodies_site_test` (контейнер `mysql`), схема из `laravel-app/tests/fixtures/site-schema.sql`. Тесты отказываются работать с базой, имя которой не кончается на `_test`.
- **Боевая база хранит часть данных в полях старой темы (ACF)**, проектная — в новых. Код читает оба варианта, не убирать:
  - видео рецепта: `recipe_video_embed` → `video`;
  - история происхождения: `_crb_recipe_history` → `history` → блок `origin-story` в тексте;
  - обложка рецепта: `_thumbnail_id` → первое фото `gallery`;
  - картинка рубрики: `_crb_category_thumb` → `image`;
  - обложка статьи блога/новости: `_thumbnail_id` → первая `wp-image-ID` в тексте → превью YouTube из `link_to_video`.
- Число отзывов считается по одобренным комментариям, не по `posts.comment_count` (он устаревает, если отзыв одобрен в обход WordPress).
- `php artisan site:prepare-db` приводит копию боевой базы к виду, который ждёт сайт: удаляет переводы WPML не на русском (кроме картинок), добавляет лайфхаки из `laravel-app/database/content/lifehacks.json`, переносит старые лайки и просмотры, загружает видео с YouTube. Повторный запуск безопасен. Запускать на **копии** базы.

## Функционал

**Сайт (`astro-app/src/pages`)**
- Главная: слайдер топ-рецептов (значок YouTube у видеорецептов), рецепты по ингредиентам с фильтрами, популярные категории, подборки, новинки, видео, новости, блок «Нам доверяют» (цифры из базы), промо-блоки и SEO-текст.
- Рецепт `/recipe/[slug]`: карусель фото (`gallery`) с видео первым слайдом (плеер YouTube грузится только по клику), описание `recipe_description`, быстрые факты, ингредиенты по группам с пересчётом порций, шаги с прогрессом, совет шефа, пищевая ценность, история происхождения, лайки, отзывы со звёздами и ответами, автор, похожие рецепты, JSON-LD Recipe, печать, избранное, «поделиться».
- Каталог: `/categories`, `/category/[slug]`, `/kitchens`, `/ingredient/[slug]` (все ингредиенты, у которых есть рецепт), `/collections/[slug]` — с пагинацией по 24 и «Показать ещё».
- Видео `/video`, лайфхаки `/lifehacks`, новости `/news`, блог `/blog`, авторы `/authors` и `/author/[slug]` (рейтинг, подписка), страницы сайта `/[slug]` (галочка «Отдельная страница сайта» в WordPress), `/about`.
- Поиск `/search` по `search-index.json` (рецепты по названию и ингредиентам, категории, кухни, авторы); запросы пишутся в Search Meter.
- Аккаунт: `/register`, `/login`, `/profile` — избранное, подписки на авторов, новые рецепты подписок. Токен в `localStorage`, заголовок `Authorization: Bearer`.
- `sitemap.xml` — индекс карт по сущностям (`sitemap-recipes.xml`, `-images`, `-categories`, `-ingredients`, `-authors`, `-articles`, `-videos`, `-lifehacks`, `-collections`, `-pages`; список в `src/sitemaps/sitemaps.ts`), `robots.txt` (на любом хосте, кроме foodies.academy, — `Disallow: /`), 404 со свежими рецептами, Yoast SEO на всех типах страниц.

**API (`laravel-app/routes/api.php`)** — `recipes`, `categories`, `ingredients`, `collections`, `authors`, `news`, `blog`, `videos`, `lifehacks`, `pages`, `stats`; запись: отзывы (`POST …/comments`, на модерацию), лайки и просмотры (`/{recipes|videos}/{slug}/likes|view`), рейтинг автора, `search-log`, `subscribe`, `auth/*`, `me/*` (избранное, подписки). У каждой записи свой лимит частоты (`AppServiceProvider`).

**Где хранится активность (плагины WordPress)**
- лайки — Posts Like Dislike (`pld_like_count` / `pld_dislike_count`);
- просмотры — Post Views Counter (`post_views`) и старый `post_views_realtime`;
- поиски — Search Meter; подписки — Contact Form 7 + CFDB7, у каждого списка своя форма: рассылка (`CF7_SUBSCRIBE_FORM_ID`) и книга рецептов (`CF7_BOOK_FORM_ID`), оба обязательны в `.env` Laravel; это ID записи формы, а не хеш из шорткода;
- отзывы — комментарии WordPress, звёзды в `commentmeta.rating`;
- пользователи — пользователи WordPress (роль subscriber); избранное и подписки — `usermeta` `foodies_favorite` / `foodies_follow_author`.

**WordPress (`wp-content/mu-plugins`)** — типы записей `video`, `lifehack`, `news`; поле «SEO-текст»; блоки «Галерея фото» и «История происхождения» у рецепта (поля `gallery` и `history` старой темы: ACF и Carbon Fields не установлены, `foodies-recipe-fields.php`); страницы сайта (создаются сами при `init`); шаблон «История происхождения»; безопасные slug'и (`a-z0-9-_`); API пользователей; уведомление Laravel о правках; перенаправление фронтенда WordPress на сайт. Плагины и версии — `wordpress/install-plugins.sh`.

**Команды Laravel**
- `site:prepare-db` — адаптация копии боевой базы (см. выше);
- `site:rebuild [--force]` — пересборка сайта через GitHub Actions;
- `videos:sync [--latest]` — видео канала YouTube (без `YOUTUBE_API_KEY` — только 15 последних из RSS);
- `likes:import-legacy`, `views:import-legacy` — перенос старых счётчиков;
- `slugs:check` — недопустимые и повторяющиеся slug'и.
- `recipes:move-legacy-fields` — видео из старого поля ACF `video` в «Recipe Video» WP Ultimate Recipe (`recipe_video_embed`, старое поле удаляется) и первое фото галереи в «Изображение записи», где его нет; входит в `site:prepare-db`.
- `deploy/hestia/clean-site-db.sh` — чистка копии боевой базы от спама (комментарии, пользователи, таблицы старых плагинов), после `site:prepare-db`.

## Локальная разработка

- Перед запуском нужен MAMP с MySQL на порту 3306: в нём база сайта.
- `docker compose up -d`. Порты: сайт http://localhost:8082, API http://localhost:8000/api, WordPress http://localhost:8081/wp-admin, MySQL Laravel — 3307.
- Имя базы сайта: `SITE_DB_DATABASE` в корневом `.env` (для WordPress) и в `laravel-app/.env` (для Laravel) — должны совпадать. Оба файла не в git.
- После смены `.env` Laravel: `docker restart foodies_laravel foodies_scheduler` и `php artisan cache:clear`. Dev-сервер Astro кэширует `getStaticPaths` — при смене данных `docker restart foodies_astro`.
- Сборка: `docker exec foodies_astro npx astro build` (~7 минут, ~2500 страниц).

## Тесты

- Astro: `cd astro-app && npx vitest run`. Тесты не ходят в настоящий API: `tests/setup.ts` отвечает пустыми данными на любой неподменённый `fetch` (модуль меню `src/data/menu.ts` грузит данные при импорте). Тест, которому нужны данные, подменяет `fetch` сам.
- Laravel: `docker exec foodies_laravel php artisan test`. Таблицы Laravel — SQLite в памяти, таблицы WordPress — `foodies_site_test`; помощники `recipe()`, `term()`, `comment()`, `makePost()` в `tests/SiteDatabase.php`.
- Новый функционал и исправления — с тестами. Проверять Astro-тесты и без запущенного API: `API_URL=http://127.0.0.1:9/api npx vitest run`.

## Деплой

- dev: https://dev.foodies.academy (сайт), https://api.dev.foodies.academy (Laravel), https://wp.dev.foodies.academy (WordPress). Прод: те же адреса без `dev.`. Один сервер HestiaCP, пользователь `jamper`, без Docker.
- Push в `main` деплоит на **dev** только нужный workflow: `astro-app/**` → `deploy.yml` (тесты, сборка, rsync `dist/`), `laravel-app/**` → `deploy-laravel.yml` (тесты, rsync, миграции, `optimize`), `wp-content/mu-plugins/**` → `deploy-wordpress.yml`. Вручную — с любой ветки на dev: `gh workflow run deploy.yml --ref <ветка>`.
- **Прод — только вручную и только из `main`**: `gh workflow run deploy.yml -f environment=production`. Пути и адреса прода — в GitHub Environment `production`, у dev — секреты репозитория. Боевой Laravel пересобирает сайт событием `content-changed-production` (`GITHUB_DEPLOY_EVENT`). Переезд и откат: `deploy/hestia/PRODUCTION.md`.
- Адреса страниц без слэша на конце, как на старом сайте: Astro собирает `recipe/x.html` (`build.format: "file"`), `.htaccess` с 301 со старых адресов WordPress пишет `src/build/htaccess.ts` после сборки.
- Фронтенд WordPress (`wp.*`) перенаправляет на сайт (`foodies-frontend.php`, `FOODIES_SITE_URL`); картинки на сайте — по старым адресам `foodies.academy/wp-content/uploads`, на проде nginx отдаёт их из папки WordPress.
- На сервере вручную правятся только `.env` Laravel и `wp-config-foodies.php`; остальное перезаписывает деплой. Cron пользователя `jamper` запускает `schedule:run`.
- Полная инструкция по серверу: `deploy/hestia/README.md`.

## Как работать в этом проекте

- Пользователь общается по-русски: ответы, описания PR — на русском. Код, комментарии, сообщения коммитов — на английском.
- Senior-подход без лишнего: никаких абстракций и гибкости «на будущее», задача решается прямо.
- Разметка и CSS — БЭМ. Стили — Less в компоненте (`<style lang="less">`), медиа-миксины из `src/styles/media.less`.
- Комментарии короткие и объясняют «почему», а не пересказывают код.
- Git: работа в ветке, PR в `main` (слияние = деплой на dev). Коммиты — conventional (`feat:`, `fix:`, `chore:`) с поясняющим телом. Коммитить и пушить только по просьбе пользователя.
- Данные в базе сайта реальные: тестовые записи (отзывы, лайки, пользователи) за собой удалять, перед массовыми изменениями — бэкап (`deploy/hestia/export-site-db.sh` или `backups/`, не в git).
- Не подключать dev или локальную среду к боевой базе foodies.academy: Laravel пишет в неё.
- Команды для сервера и для Mac легко перепутать: в инструкциях всегда указывать, где выполнять (`root@server`, `jamper@server` или Mac).

## Известное

- 35 тыс. комментариев боевой базы ждут модерации (в основном спам); на сайте только одобренные.
- У части статей блога и кухонь нет картинок в самом WordPress — карточки показывают заглушку.
