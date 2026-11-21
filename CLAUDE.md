# Foodies Academy

Сайт рецептов foodies.academy. Контент ведётся в WordPress, Laravel отдаёт его API, Astro собирает из API статический сайт. Активность посетителей (лайки, просмотры, отзывы, поиски, подписки, аккаунты) Laravel пишет в таблицы плагинов WordPress, чтобы всё было видно и управлялось в админке WordPress.

## Стек

| Часть | Технология | Где |
|---|---|---|
| Сайт | Astro 7 (статическая сборка, без адаптера), Less, TypeScript, Vitest | `astro-app/` |
| API | Laravel 12, PHP 8.3, PHPUnit | `laravel-app/` |
| CMS | WordPress 7.1.5 + плагины с закреплёнными версиями (Polylang, Yoast, CF7…), свои mu-plugins | `wp-content/mu-plugins/`, `wordpress/` |
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
- Пересборка сайта: правка в WordPress → mu-plugin `foodies-rebuild.php` → `POST /api/internal/content-changed` → Laravel сбрасывает кэш (SEO-поля Yoast лежат в отдельном кэше `seo` на неделю: сбрасываются только у изменённой записи, целиком — перед ночной сборкой) → `site:rebuild` (раз в минуту) запускает GitHub Actions через `repository_dispatch`, выждав 5 минут тишины (максимум 30). Плюс ночная сборка в 04:30. Сборка идёт ~25–30 минут. Без `GITHUB_DEPLOY_TOKEN` только пишет в лог.

## Базы данных

- **База сайта** — база WordPress. Локально в MAMP (MySQL 8, клиент `/Applications/MAMP/Library/bin/mysql80/bin/mysql`) — копия боевой, имя в `SITE_DB_DATABASE`: сейчас `foodies_fresh` (дамп прода от 21.11.2026 с переводами), до неё `foodies_dev`. На dev-сервере: `jamper_foodies_site`, на проде: `foodies_wordpress`. Старая проектная `foodies.academy_with_content` больше не используется.
- Свежая копия прода: `root@server` — `mariadb-dump --single-transaction foodies_wordpress | gzip > …` (бэкап базы и файлов — `deploy/hestia/README.md`), на Mac — импорт в новую базу MAMP. `site:prepare-db` для неё не нужен: база прода уже приведена к виду сайта.
- **База Laravel** — сессии, кэш, токены входа (`api_tokens`), очередь. Локально контейнер `mysql` (`foodies_laravel`), на dev-сервере `jamper_foodies_laravel`, на проде `foodies_laravel`. Заполняется миграциями.
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
- Каталог: `/categories`, `/category/[slug]`, `/kitchens`, `/ingredient/[slug]` (все ингредиенты, у которых есть рецепт), `/collections/[slug]` — с пагинацией по 24 и «Показать ещё». Тонкие ингредиенты — меньше 3 рецептов и нет своего SEO-текста от 1500 символов — `noindex, follow` и не в sitemap (поле `indexed` API, `Ingredients::INDEXED_FROM`).
- Видео `/video`, лайфхаки `/lifehacks`, новости `/news`, блог `/blog`, авторы `/authors` и `/author/[slug]` (рейтинг, подписка), страницы сайта `/[slug]` (галочка «Отдельная страница сайта» в WordPress; title и description Yoast у «О проекте», «Контакты», «Политика», «Правила» один раз заполняет `foodies-site-pages.php`), `/about`. Просмотры (с сайта, не YouTube) видны у статей, новостей, лайфхаков и видео — на странице и в карточках.
- Поиск `/search` по `search-index.json` (рецепты по названию и ингредиентам, категории, кухни, авторы); запросы пишутся в Search Meter.
- Аккаунт: `/register`, `/login`, `/profile` — избранное, подписки на авторов, новые рецепты подписок. Токен в `localStorage`, заголовок `Authorization: Bearer`.
- `sitemap.xml` — индекс карт по сущностям (`sitemap-recipes.xml`, `-images`, `-categories`, `-ingredients`, `-authors`, `-articles`, `-videos`, `-lifehacks`, `-collections`, `-pages`; список в `src/sitemaps/sitemaps.ts`), `robots.txt` (на любом хосте, кроме foodies.academy, — `Disallow: /`), 404 со свежими рецептами, Yoast SEO на всех типах страниц.

**API (`laravel-app/routes/api.php`)** — `recipes`, `categories`, `ingredients`, `collections`, `authors`, `news`, `blog`, `videos`, `lifehacks`, `pages`, `stats`; запись: отзывы (`POST …/comments`, на модерацию), лайки (`/{recipes|videos}/{slug}/likes`), просмотры (`/{recipes|videos|lifehacks|news|blog|pages}/{slug}/view`, `PostViews::TYPES`; в ответах API — `views`, у видео `siteViews`, `views` у него — YouTube), рейтинг автора, `search-log`, `subscribe`, `auth/*`, `me/*` (избранное, подписки). У каждой записи свой лимит частоты (`AppServiceProvider`).

**Где хранится активность (плагины WordPress)**
- лайки — Posts Like Dislike (`pld_like_count` / `pld_dislike_count`);
- просмотры — Post Views Counter (`post_views`) и старый `post_views_realtime`;
- поиски — Search Meter; подписки — Contact Form 7 + CFDB7, у каждого списка своя форма: рассылка (`CF7_SUBSCRIBE_FORM_ID`) и книга рецептов (`CF7_BOOK_FORM_ID`), оба обязательны в `.env` Laravel; это ID записи формы, а не хеш из шорткода;
- отзывы — комментарии WordPress, звёзды в `commentmeta.rating`;
- пользователи — пользователи WordPress (роль subscriber); избранное и подписки — `usermeta` `foodies_favorite` / `foodies_follow_author`.

**WordPress (`wp-content/mu-plugins`)** — типы записей `video`, `lifehack`, `news`; поле «SEO-текст»; блоки «Галерея фото» и «История происхождения» у рецепта (поля `gallery` и `history` старой темы: ACF и Carbon Fields не установлены, `foodies-recipe-fields.php`); страницы сайта (создаются сами при `init`); шаблон «История происхождения»; безопасные slug'и (`a-z0-9-_`); API пользователей; фото профиля (мета `artit_user_avatar` старого плагина, вместо Gravatar) и «Биография» с визуальным редактором (`foodies-user-profile.php`, на сайте — HTML); уведомление Laravel о правках; не больше 10 ревизий у записи (`foodies-revisions.php`); перенаправление фронтенда WordPress на сайт.
- Версии ядра WordPress и плагинов закреплены в `wordpress/install-plugins.sh`: при старте Docker и при деплое WordPress он поднимает ядро и плагины до них (только вверх: версию новее, поставленную из админки, не откатывает, а пишет в лог) и активирует плагины. Обновление — сменить версии там и в `wordpress/Dockerfile` (образ `wordpress:X-php8.3-apache`), перед этим бэкап базы. Ядро в Docker лежит в томе, поэтому новый образ сам его не обновляет — это делает скрипт.

**Команды Laravel**
- `site:prepare-db` — адаптация копии боевой базы (см. выше);
- `site:rebuild [--force]` — пересборка сайта через GitHub Actions;
- `videos:sync [--latest]` — видео канала YouTube (без `YOUTUBE_API_KEY` — только 15 последних из RSS);
- `likes:import-legacy`, `views:import-legacy` — перенос старых счётчиков;
- `slugs:check` — недопустимые и повторяющиеся slug'и.
- `recipes:move-legacy-fields` — видео из старого поля ACF `video` в «Recipe Video» WP Ultimate Recipe (`recipe_video_embed`, старое поле удаляется) и первое фото галереи в «Изображение записи», где его нет; входит в `site:prepare-db`.
- `recipes:prepare-translations {файл} [--lang=en]` — переводы рецептов из JSON (без ID: рецепт ищется по фото) в `database/content/translations/recipes.{lang}.json` для импорта в WordPress: ингредиенты выровнены по русскому рецепту (берутся только названия, заметки, группы), заголовки из КАПСА, °C → «°C (°F)», глоссарий рубрик и стран; отчёт о расхождениях.
- `units:check` — единицы ингредиентов, которых нет в `config/units.php` (опечатки, количество в поле единицы).
- `recipes:fix-ingredients [--dry-run]` — исправляет количества и единицы русских рецептов: опечатки («шепотка», «CV» = «см»), количество в поле единицы и наоборот, «1?2» → «1/2», «дольки» чеснока → «зубчика», опечатки в названиях групп. Повторный запуск безопасен.
- `translations:sync-counters` — копирует лайки, дизлайки и просмотры русских записей в переводы; после импорта переводов.
- `deploy/hestia/clean-site-db.sh` — чистка копии боевой базы: спам (комментарии, пользователи), таблицы старых плагинов, ревизии старше 10-й, перевод всех таблиц в `utf8mb4_unicode_ci`; после `site:prepare-db`.

## Переводы

- Языки: русский — оригинал в корне (`/recipe/x`), английский — под `/en` (`/en/recipe/y`). Polylang: перевод — отдельная запись (термин) того же типа, связанная с русской. `wp foodies languages` (mu-plugin `foodies-translations.php`) создаёт ru/en, включает переводы `recipe`, `video`, `lifehack`, `news` и их таксономий, одну медиатеку, без синхронизации полей; всему без языка ставит ru.
- **API**: `?lang=en` (middleware `SetLanguage`, `App\Support\Lang`; неизвестный язык — 404, ключи кэша с префиксом языка). Списки, счётчики, термины, фото, видео, время — у русского оригинала (`App\Services\Translations::scope()`), тексты — у перевода: заголовок, slug, названия ингредиентов (количества русского рецепта, единица по-английски), шаги, совет, описание, отзывы. Термины — переводы Polylang; если английский slug — это русский с `-2`, отдаётся русский. В английском API нет того, что не переведено (новости, видео, блог, лайфхаки, страницы WordPress), и нет полей Yoast (шаблоны Yoast русские). `id` рецепта — всегда русского (избранное).
- Лайки, дизлайки, просмотры общие: Laravel пишет их во все переводы записи (`Translations::group()`); `translations:sync-counters` копирует счётчики оригиналов в новые переводы.
- **Строки интерфейса**: в Astro `t(lang, "Русская строка", { vars })`, в браузерных скриптах `tc("…")` (`src/scripts/i18n.ts`); множественное число — `pluralOf(lang, n, [3 формы])` / `pluralc`. Список строк с английскими значениями по умолчанию — `wp-content/mu-plugins/foodies-strings.json` (тест `tests/I18n.test.ts` проверяет, что все строки из кода там есть). В админке WordPress: «Языки → Переводы строк», группа Foodies; правки отдаёт `GET /api/strings?lang=en` и пересобирают сайт. `wp foodies import-strings` дописывает в Polylang переводы из JSON, отредактированные не трогает.
- **Страницы Astro**: язык страницы — `langOf(Astro.url)`, в `getStaticPaths` — `langOfRoute(routePattern)`; ссылки через `localize(lang, path)`, запросы через `withLang(lang, url)`. Английские страницы — обёртки в `src/pages/en/`, которые рендерят русскую страницу. Новостей, блога, видео, лайфхаков и страниц WordPress на английском нет — их обёрток нет, ссылок на них в английском меню нет. Переключатель языка и hreflang — только если есть перевод страницы (`translations` у `BaseLayout`, `bilingual` для страниц без своего контента).
- Импорт: `recipes:prepare-translations` → `wp foodies import-recipe-translations recipes.en.json [--dry-run] [--force]` (новые — черновики, опубликованные без `--force` не трогаются; в мете `foodies_translation` хеш русских текстов) → `translations:sync-counters`. Термины: `wp foodies import-term-translations database/content/translations/terms.en.json [--force]` (18 типов блюд, 100 стран, 1269 ингредиентов; переименованные в админке не трогаются).
- Единицы: `config/units.php` — русские написания → коды, системы `metric`, `uk`, `us`; пересчёт и температуры — `App\Support\Units`. Опечатки в рецептах исправляет `recipes:fix-ingredients`, неизвестные показывает `units:check`. На английской странице рецепта переключатель Metric / US (`astro-app/src/utils/units.ts` — те же правила, что в PHP; выбор в `localStorage`).
- Ингредиенты в английской версии: строка рецепта, совпадающая с названием своего термина, называется как переведённый термин. Русские термины с одним английским названием («Масло оливковое», «Оливковое масло») — одна английская страница (`Ingredients::groups()`).
- SEO английской версии: `wp foodies seo-templates` заполняет пустые поля Yoast у переводов рецептов и терминов и SEO-текст терминов английскими шаблонами (импорты вызывают его сами), заполненные вручную не трогает. Шаблоны самого Yoast русские, поэтому у авторов и страниц WordPress в английской версии полей Yoast нет. Старые slug английских рецептов — 301 на `/en/recipe/…`; 404 под `/en` — `/en/404.html`.
- Админка: английский рецепт — только перевод русского (кнопка «+» у русского), без него он остаётся черновиком; термины ингредиентов, которые WP Ultimate Recipe создаёт из английских названий при сохранении, снимаются и удаляются (`foodies-translations.php`).
- Подписки: английские подписчики — в копиях форм CF7 (`wp foodies copy-forms <id рассылки> <id книги>` → `CF7_SUBSCRIBE_FORM_ID_EN`, `CF7_BOOK_FORM_ID_EN` в `.env` Laravel). Отзывы — к записи своего языка. Ответы API (сообщения, ошибки входа и валидации, даты отзывов) — на языке `?lang=`.
- Сборка по языкам: правка перевода (запись, термин, отзыв, строки интерфейса) пересобирает только его язык (`client_payload.langs` → `BUILD_LANGS` в Astro: `getStaticPaths` других языков пусты, деплой выкладывает только собранное), правка русского и ночная сборка — оба. Вручную: `gh workflow run deploy.yml -f langs=en`.
- Читателям из ЕС, ЕЭЗ, Великобритании и Швейцарии (по часовому поясу браузера) счётчики аналитики загружаются только после согласия (`CookieConsent.astro`, `SiteCounters.astro`).

## Локальная разработка

- Перед запуском нужен MAMP с MySQL на порту 3306: в нём база сайта.
- `docker compose up -d`. Порты: сайт http://localhost:8082, API http://localhost:8000/api, WordPress http://localhost:8081/wp-admin, MySQL Laravel — 3307.
- Имя базы сайта: `SITE_DB_DATABASE` в корневом `.env` (для WordPress) и в `laravel-app/.env` (для Laravel) — должны совпадать. Оба файла не в git.
- После смены `.env` Laravel: `docker restart foodies_laravel foodies_scheduler` и `php artisan cache:clear`. Dev-сервер Astro кэширует `getStaticPaths` — при смене данных `docker restart foodies_astro`.
- Сборка: `docker exec foodies_astro npx astro build` (~10 минут, ~4500 страниц: русская и английская версии). Во время сборки не перезапускать WordPress: поля Yoast Laravel берёт из его REST API.
- Образы Docker закреплены: `wordpress:7.1.5-php8.3-apache` (+ WP-CLI 2.12.0), `php:8.3.37-cli` + Composer 2.10, `node:22.23.5`, `mysql:9.7.4` (только база Laravel). После смены версий: `docker compose build && docker compose up -d`.

## Тесты

- Astro: `cd astro-app && npx vitest run`. Тесты не ходят в настоящий API: `tests/setup.ts` отвечает пустыми данными на любой неподменённый `fetch` (модуль меню `src/data/menu.ts` грузит данные при импорте). Тест, которому нужны данные, подменяет `fetch` сам.
- Laravel: `docker exec foodies_laravel php artisan test`. Таблицы Laravel — SQLite в памяти, таблицы WordPress — `foodies_site_test`; помощники `recipe()`, `term()`, `comment()`, `makePost()` в `tests/SiteDatabase.php`.
- Новый функционал и исправления — с тестами. Проверять Astro-тесты и без запущенного API: `API_URL=http://127.0.0.1:9/api npx vitest run`.

## Деплой

- dev: https://dev.foodies.academy (сайт), https://api.dev.foodies.academy (Laravel), https://wp.dev.foodies.academy (WordPress). Прод: те же адреса без `dev.`. Один сервер HestiaCP без Docker: dev — у пользователя `jamper` (`/home/jamper/web/…`), прод — у пользователя `foodies` (`/home/foodies/web/…`). Все базы и таблицы должны быть в `utf8mb4_unicode_ci` (таблицы плагинов переводит `clean-site-db.sh`).
- Push в `main` деплоит на **dev** только нужный workflow: `astro-app/**` → `deploy.yml` (тесты, сборка, rsync `dist/`), `laravel-app/**` → `deploy-laravel.yml` (тесты, rsync, миграции, `optimize`), `wp-content/mu-plugins/**` → `deploy-wordpress.yml`. Вручную — с любой ветки на dev: `gh workflow run deploy.yml --ref <ветка>`.
- **Прод — только вручную и только из `main`**: `gh workflow run deploy.yml -f environment=production`. Пути и адреса прода — в GitHub Environment `production`, у dev — секреты репозитория. Боевой Laravel пересобирает сайт событием `content-changed-production` (`GITHUB_DEPLOY_EVENT`). Устройство прода и переезд: `deploy/hestia/PRODUCTION.md`.
- Адреса страниц без слэша на конце, как на старом сайте: Astro собирает `recipe/x.html` (`build.format: "file"`), `.htaccess` с 301 со старых адресов WordPress пишет `src/build/htaccess.ts` после сборки: короткие ссылки `?p=ID` и старые slug'и рецептов (`_wp_old_slug`) берёт из `GET /api/redirects`, ленты `/…/feed` ведёт на саму страницу, старые кнопки лайков (`?act=like`) отвечают 410. Поэтому боевой Laravel деплоится раньше сайта.
- Фронтенд WordPress (`wp.*`) перенаправляет на сайт (`foodies-frontend.php`, `FOODIES_SITE_URL`); картинки на сайте — по старым адресам `foodies.academy/wp-content/uploads`, на проде nginx отдаёт их из папки WordPress.
- На сервере вручную правятся только `.env` Laravel и `wp-config-foodies.php`; остальное перезаписывает деплой. `schedule:run` запускает cron своего пользователя: `jamper` на dev, `foodies` на проде. Команды прода — от `foodies` (`sudo -u foodies` под root); `mariadb` без пароля — только у root.
- Полная инструкция по серверу: `deploy/hestia/README.md`.

## Как работать в этом проекте

- Пользователь общается по-русски: ответы, описания PR — на русском. Код, комментарии, сообщения коммитов — на английском.
- Senior-подход без лишнего: никаких абстракций и гибкости «на будущее», задача решается прямо.
- Разметка и CSS — БЭМ. Стили — Less в компоненте (`<style lang="less">`), медиа-миксины из `src/styles/media.less`.
- Комментарии короткие и объясняют «почему», а не пересказывают код.
- Git: работа в ветке, PR в `main` (слияние = деплой на dev). Коммиты — conventional (`feat:`, `fix:`, `chore:`) с поясняющим телом. Коммитить и пушить только по просьбе пользователя.
- Данные в базе сайта реальные: тестовые записи (отзывы, лайки, пользователи) за собой удалять, перед массовыми изменениями — бэкап (`deploy/hestia/export-site-db.sh` или `backups/`, не в git).
- Не подключать dev или локальную среду к боевой базе foodies.academy: Laravel пишет в неё.
- Команды для сервера и для Mac легко перепутать: в инструкциях всегда указывать, где выполнять (`root@server`, `jamper@server`, `foodies@server` или Mac).

## Известное

- MySQL в MAMP иногда останавливается сам («Received SHUTDOWN from user root» в логе MAMP) — сборки и API тогда падают с `Connection refused`; запустить MySQL в MAMP заново.

- 35 тыс. комментариев боевой базы ждут модерации (в основном спам); на сайте только одобренные.
- У части статей блога и кухонь нет картинок в самом WordPress — карточки показывают заглушку.
