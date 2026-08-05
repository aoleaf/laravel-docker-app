FROM php:8.2-fpm

# 必要なパッケージをインストール
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# PHP拡張機能をインストール
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Composerをインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 作業ディレクトリ
WORKDIR /var/www/html

# 権限設定
RUN chown -R www-data:www-data /var/www/html