-- Empty copy of the WordPress tables Laravel's tests run against (laravel-app/tests/SiteDatabase.php
-- creates the tables from tests/fixtures/site-schema.sql), so tests never touch the real site data.
CREATE DATABASE IF NOT EXISTS foodies_site_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON foodies_site_test.* TO 'laravel_user'@'%';
FLUSH PRIVILEGES;
