# laravel-docker-app

Laravel 12 + Docker で構築した学習用 Web アプリケーションです。
MVC パターンの練習として、**ブログ**・**商品管理**・**イベント予約** の3つのシステムを実装しています。
ブログは Laravel Breeze による認証を組み込んだ **会員制ブログ** になっています。

Docker で Nginx・PHP-FPM・MySQL・phpMyAdmin をまとめて起動できるため、ローカルに PHP や MySQL を入れなくても `docker compose up -d` だけで開発環境が立ち上がります。

## 使用技術

- PHP 8.2（PHP-FPM）
- Laravel 12.x
- MySQL 8.0
- Nginx
- phpMyAdmin
- Docker / Docker Compose

---

## 実装機能

### 1. 認証（Laravel Breeze）

`laravel/breeze`（Blade スタック）で導入しています。

| 機能 | URL | 説明 |
| --- | --- | --- |
| ユーザー登録 | `/register` | 名前・メール・パスワード。パスワードは bcrypt でハッシュ化して保存 |
| ログイン | `/login` | 失敗が続くとレートリミット（`LoginRequest` の `RateLimiter`、5回で一時ロック） |
| ログアウト | `POST /logout` | セッションを無効化し、トークンを再生成 |
| パスワードリセット | `/forgot-password` | メール経由での再設定 |
| プロフィール編集 | `/profile` | 名前・メール・パスワードの変更、退会 |
| ダッシュボード | `/dashboard` | ログイン後の画面 |

### 2. 会員制ブログ

投稿の CRUD に、認証（誰か）と認可（自分の投稿か）を組み合わせています。

| 機能 | 説明 |
| --- | --- |
| 投稿一覧 | 新しい順に表示。1ページ10件のページネーション付き。**未ログインでも閲覧可** |
| 投稿詳細 | 本文の改行を保持して表示（XSS 対策済み）。**未ログインでも閲覧可** |
| 投稿作成 | **ログイン必須**。作者は `auth()->user()->posts()->create()` で自動的に自分になる |
| 投稿編集 | **作者本人のみ**。他人の投稿を開こうとすると 403 |
| 投稿削除 | **作者本人のみ**。確認ダイアログを挟んで削除 |
| ボタンの出し分け | 編集・削除リンクは作者本人にだけ表示（表示制御とは別にサーバー側でも 403 チェック） |
| バリデーション | 必須・文字数チェック。エラー時は入力値を保持したまま差し戻し |
| レイアウト継承 | `layouts/blog.blade.php` を全画面で `@extends`。ヘッダーにログイン状態を表示 |

アクセス制御は「ルート（ミドルウェア）」と「コントローラー」の2段構えです。

```php
// routes/web.php ── 閲覧は誰でも、書き込み系はログイン必須
Route::resource('posts', PostController::class)
    ->except(['index', 'show'])
    ->middleware('auth');
Route::resource('posts', PostController::class)->only(['index', 'show']);
```

```php
// PostController.php ── ログインしていても「他人の投稿」なら弾く
private function authorizeOwner(Post $post): void
{
    if (! $post->isOwnedBy(auth()->user())) {
        abort(403, 'この操作は許可されていません');
    }
}
```

> `create` を含むルートを先に登録しています。`show`（`posts/{post}`）を先に書くと `/posts/create` が `{post}` に吸われてしまうためです。

### 3. 商品管理システム

数値項目と選択式カテゴリーを扱う商品マスタです。

| 機能 | 説明 |
| --- | --- |
| 商品一覧 | テーブル形式。価格はカンマ区切り、在庫0は「在庫切れ」と赤字表示 |
| 商品詳細 | 商品名・価格・在庫・カテゴリー・説明を表示 |
| 商品登録 / 編集 / 削除 | CRUD 一式 |
| バリデーション | `FormRequest`（`ProductRequest`）に集約し、登録と編集で共通化 |
| 数値チェック | 価格・在庫は整数かつ 0 以上のみ許可 |
| カテゴリー制限 | モデル定数 `Product::CATEGORIES` の5種からのみ選択可能 |
| 任意項目 | 説明は未入力でも登録可（`nullable`） |

### 4. イベント予約システム

**テーブル間のリレーション**を扱う、最も複雑なシステムです。

| 機能 | 説明 |
| --- | --- |
| イベント一覧 | 開催日時順。残席数・満席・開催終了を判定して表示 |
| イベント詳細 | 定員・残席と、そのイベントの予約者一覧を表示 |
| 予約作成 | 名前・メール・人数・予約日時を入力 |
| 予約一覧 | 全イベント横断の予約一覧。イベント名をリンク表示 |
| 予約キャンセル | 確認ダイアログを挟んで削除。残席が自動的に戻る |
| **定員チェック** | 残席を超える人数の予約を拒否（`FormRequest` の `after()` フックで実装） |
| N+1 対策 | 一覧で `with()` / `withSum()` を使い、追加クエリを発生させない |

> イベント自体の登録画面はありません。データはシーダー（`EventSeeder`）から投入します。

---

## 画面一覧

| URL | 機能 | ログイン |
| --- | --- | --- |
| `/register` | ユーザー登録 | 不要 |
| `/login` | ログイン | 不要 |
| `/dashboard` | ダッシュボード | **必須** |
| `/profile` | プロフィール編集 | **必須** |
| `/posts` | 投稿一覧 | 不要 |
| `/posts/{id}` | 投稿詳細 | 不要 |
| `/posts/create` | 投稿作成フォーム | **必須** |
| `/posts/{id}/edit` | 投稿編集フォーム | **必須（作者本人のみ）** |

その他の画面（ログイン不要）。

| URL | 機能 |
| --- | --- |
| `/products` | 商品一覧 |
| `/products/create` | 商品登録フォーム |
| `/products/{id}` | 商品詳細 |
| `/products/{id}/edit` | 商品編集フォーム |
| `/events` | イベント一覧 |
| `/events/{id}` | イベント詳細 |
| `/events/{id}/reservations/create` | 予約フォーム |
| `/reservations` | 予約一覧 |

---

## DB設計

テーブル定義（users / posts / products / events / reservations）と ER 図は
**[docs/database.md](docs/database.md)** にまとめています。

投稿は `posts.user_id` で作者と紐づいており、`users` を削除すると
`ON DELETE CASCADE` でその人の投稿も削除されます。

---

## セキュリティ対策

実際にリクエストを投げて検証した結果は **[docs/security-report.md](docs/security-report.md)** にまとめています。

| 攻撃 | 対策 | 実装箇所 |
| --- | --- | --- |
| **XSS** | Blade の `{{ }}` による自動エスケープ。改行を活かす箇所も `e()` でエスケープしてから `nl2br()` に渡している | `posts/show.blade.php` の `{!! nl2br(e($post->content)) !!}` |
| **CSRF** | 全ての POST / PUT / DELETE フォームに `@csrf`。トークンなしのリクエストは 419 で拒否 | 各 Blade フォーム、`VerifyCsrfToken` ミドルウェア |
| **SQL インジェクション** | Eloquent / クエリビルダーのみを使用（生 SQL の文字列結合なし）。内部でプリペアドステートメントが使われる | 全コントローラー |
| **パスワード漏洩** | `User` モデルの `casts` に `'password' => 'hashed'` を指定し、bcrypt でハッシュ化して保存。`$hidden` で JSON 出力からも除外 | `app/Models/User.php` |
| **なりすまし投稿** | `Post` の `$fillable` に `user_id` を含めず、`auth()->user()->posts()->create()` で作者を決定。フォームに `user_id` を混ぜても無視される | `app/Models/Post.php`、`PostController@store` |
| **他人の投稿の改ざん** | `edit` / `update` / `destroy` で作者を照合し、不一致なら `abort(403)` | `PostController@authorizeOwner` |
| **未認証アクセス** | 書き込み系ルートを `auth` ミドルウェアで保護。未ログインは `/login` へリダイレクト | `routes/web.php` |
| **ブルートフォース** | ログイン試行のレートリミット（5回失敗で一時ロック） | `app/Http/Requests/Auth/LoginRequest.php` |
| **セッション固定化** | ログイン時に `session()->regenerate()`、ログアウト時に `invalidate()` + `regenerateToken()` | `AuthenticatedSessionController` |
| **機密情報のコミット** | `.env` は `.gitignore` 済み。共有用に `.env.example` のみコミット | `.gitignore` |

### 自動テスト

`tests/Feature/PostAuthorizationTest.php` に、上記のうち検証可能なものをテストとして落としています。

```bash
docker compose exec app php artisan test --filter=PostAuthorizationTest
```

| テスト | 確認内容 |
| --- | --- |
| ゲストでも一覧と詳細は見られる | 公開ルートが 200 |
| ゲストは投稿フォームにアクセスできない | `/posts/create` が `/login` へリダイレクト |
| ゲストは投稿を保存できない | POST が弾かれ、DB に保存されない |
| ログインユーザーは投稿でき作者が自分になる | `user_id` にログインユーザーが入る |
| `user_id` を送りつけても作者を偽装できない | 一括代入対策 |
| 他人の投稿は編集画面を開けない / 更新できない / 削除できない | いずれも 403、DB も変化なし |
| 自分の投稿は更新できる / 削除できる | 正常系 |
| 投稿フォームに CSRF トークンが埋め込まれている | `name="_token"` の存在 |
| 投稿内容のスクリプトタグはエスケープされる | `<script>` がそのまま出力されない |
| SQL インジェクションを試みても全件は漏れない | `1' OR '1'='1` が 404 |

> CSRF 検証そのものはテスト実行中スキップされる仕様（`ValidateCsrfToken::runningUnitTests()`）のため、419 になることは curl で確認しています。
>
> ```bash
> curl -i -X POST http://localhost/posts -d "title=a&content=b&category=c"   # → 419
> ```

---

## スクリーンショット

### 認証

| ログイン | ユーザー登録 |
| --- | --- |
| ![ログイン](docs/screenshots/auth-login.png) | ![ユーザー登録](docs/screenshots/auth-register.png) |

| ダッシュボード |
| --- |
| ![ダッシュボード](docs/screenshots/auth-dashboard.png) |

### 会員制ブログ

未ログインでも一覧・詳細は読めますが、「新規投稿」は出ず、代わりにログインへの導線が出ます。

| 未ログインの投稿一覧 | ログイン後の投稿一覧 |
| --- | --- |
| ![未ログインの一覧](docs/screenshots/blog-index-guest.png) | ![ログイン後の一覧](docs/screenshots/blog-index.png) |

ログイン後の一覧では、**自分（Test User）の投稿にだけ「編集」リンク**が出ています。Other User の投稿には出ません。

| 自分の投稿の詳細 | 他人の投稿の詳細 |
| --- | --- |
| ![自分の投稿](docs/screenshots/blog-show-own.png) | ![他人の投稿](docs/screenshots/blog-show-other.png) |

編集・削除ボタンは作者本人にだけ表示されます。URL を直接叩いても、サーバー側のチェックで 403 になります。

| 他人の投稿の編集URLに直接アクセス |
| --- |
| ![403](docs/screenshots/blog-403.png) |

| 投稿作成フォーム | バリデーションエラー |
| --- | --- |
| ![投稿作成](docs/screenshots/blog-create.png) | ![バリデーション](docs/screenshots/blog-validation.png) |

### 商品管理システム

| 商品一覧（在庫切れ表示） | 商品登録フォーム |
| --- | --- |
| ![商品一覧](docs/screenshots/product-index.png) | ![商品登録](docs/screenshots/product-create.png) |

| 商品詳細 | 数値バリデーション |
| --- | --- |
| ![商品詳細](docs/screenshots/product-show.png) | ![商品バリデーション](docs/screenshots/product-validation.png) |

### イベント予約システム

| イベント一覧（残席表示） | イベント詳細（予約者一覧） |
| --- | --- |
| ![イベント一覧](docs/screenshots/event-index.png) | ![イベント詳細](docs/screenshots/event-show.png) |

| 予約フォーム | 定員オーバーのエラー |
| --- | --- |
| ![予約フォーム](docs/screenshots/reservation-create.png) | ![定員エラー](docs/screenshots/reservation-capacity-error.png) |

| 予約一覧 |
| --- |
| ![予約一覧](docs/screenshots/reservation-index.png) |

---

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

### 4. 書き込み権限を設定

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### 5. フロントエンドアセットのビルド（ホストPCで実行）

Breeze の画面（ログイン・登録・プロフィール）は Tailwind CSS を使うため、Vite でのビルドが必要です。
`app` コンテナに Node.js は入っていないので、**ホストPC側**のプロジェクトルートで実行します。

```bash
node -v          # 入っていなければ https://nodejs.org からインストール
npm install
npm run build    # 開発中は npm run dev でもOK（ホットリロード）
```

> ビルドしていないと `/login` などで `Vite manifest not found` エラーになります。
> `/posts` 系の画面は `public/css/app.css` を直接読み込んでいるため、ビルドなしでも表示されます。

### 6. テーブル作成とサンプルデータ投入

```bash
docker compose exec app php artisan migrate --seed
```

以下のサンプルデータが投入されます。

| データ | 件数 | 内容 |
| --- | --- | --- |
| ユーザー | 2件 | 認可（他人の投稿は編集できない）の確認用 |
| 投稿 | 15件 | 2人のユーザーに交互に割り当て。ページネーションの確認用 |
| 商品 | 15件 | 在庫切れ商品を2件含む |
| イベント | 3件 | 定員 30 / 100 / 5 名。定員5名は定員オーバーの確認用 |

| ログイン用アカウント | パスワード |
| --- | --- |
| test@example.com | password |
| other@example.com | password |

### 7. 動作確認

| URL | 内容 |
| --- | --- |
| http://localhost | アプリケーション |
| http://localhost/posts | 会員制ブログ |
| http://localhost/login | ログイン |
| http://localhost:8080 | phpMyAdmin（root / secret） |

「自分の投稿だけ編集・削除できる」ことは、以下の手順で確認できます。

1. `test@example.com` でログインし、`/posts` を開く
2. 自分の投稿にだけ「編集」リンクが出ることを確認
3. 他人（Other User）の投稿の詳細を開き、編集・削除ボタンが**出ない**ことを確認
4. URL を直接叩いて `/posts/{他人の投稿のid}/edit` にアクセス → **403** になることを確認

---

## よく使うコマンド

```bash
# コンテナに入る
docker compose exec app bash

# コンテナの停止
docker compose down

# ログの確認
docker compose logs -f app

# DBを作り直してサンプルデータを入れ直す（データは全て消えます）
docker compose exec app php artisan migrate:fresh --seed

# ルート一覧の確認
docker compose exec app php artisan route:list --except-vendor
```

> `php artisan` は必ず **コンテナ内** で実行してください。ホスト側の PHP で実行すると、mbstring 拡張が無い場合に `Call to undefined function mb_split()` エラーになります。

---

## 補足

- Windows のバインドマウント上では `chown` が反映されない場合があります。画面が 500 エラーになるときは `chmod -R 777 storage bootstrap/cache` を試してください。
- `.env` は Git 管理外です。設定を変えたときは `.env.example` 側も更新してください。
- バリデーションのエラーメッセージは、項目名のみ日本語化しています（文章部分は Laravel 標準の英語のままです）。
