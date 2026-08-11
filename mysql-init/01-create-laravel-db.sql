CREATE DATABASE IF NOT EXISTS foodies_laravel;
CREATE USER IF NOT EXISTS 'laravel_user'@'%' IDENTIFIED BY 'laravel_password';
GRANT ALL PRIVILEGES ON foodies_laravel.* TO 'laravel_user'@'%';
FLUSH PRIVILEGES;