# laravel-docker-app

## 概要

Laravel 12 で構築した Web アプリケーションです。
Docker で Nginx・PHP-FPM・MySQL・phpMyAdmin をまとめて起動できるため、ローカルに PHP や MySQL を入れなくても `docker compose up -d` だけで開発環境が立ち上がります。

## 使用技術

- PHP 8.2（PHP-FPM）
- Laravel 12.x
- MySQL 8.0
- Nginx
- phpMyAdmin
- Docker / Docker Compose

## セットアップ手順

### 1. リポジトリを取得してコンテナを起動

```bash
git clone https://github.com/aoleaf/techmeets-month2.git
cd techmeets-month2
docker compose up -d
```

### 2. 依存パッケージをインストール

`vendor/` は Git 管理外なので、コンテナ内でインストールします。

```bash
docker compose exec app composer install
```

### 3. 環境設定ファイルを作成

```bash
docker compose exec app cp .env.example .env
docker compose exec app php artisan key:generate
```

`.env` の DB 設定を、以下のように MySQL コンテナへ向けます。
`DB_HOST` は `127.0.0.1` ではなく **`db`**（docker-compose のサービス名）です。

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=secret
```

### 4. 書き込み権限とマイグレーション

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
docker compose exec app php artisan migrate
```

### 5. 動作確認

| URL | 内容 |
| --- | --- |
| http://localhost | アプリケーション |
| http://localhost:8080 | phpMyAdmin（root / secret） |

## よく使うコマンド

```bash
# コンテナに入る
docker compose exec app bash

# コンテナの停止
docker compose down

# ログの確認
docker compose logs -f app
```

## 補足

- Windows のバインドマウント上では `chown` が反映されない場合があります。画面が 500 エラーになるときは `chmod -R 777 storage bootstrap/cache` を試してください。
- `.env` は Git 管理外です。設定を変えたときは `.env.example` 側も更新してください。
