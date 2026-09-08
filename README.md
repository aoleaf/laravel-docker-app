# laravel-docker-app

Laravel 12 + Docker で構築した学習用 Web アプリケーションです。
MVC パターンの練習として、**ブログ**・**商品管理**・**イベント予約** の3つのシステムを実装しています。
ブログは Laravel Breeze による認証を組み込んだ **会員制ブログ** で、
**Repository / Service / Policy パターン**にリファクタリング済みです。

Docker で Nginx・PHP-FPM・MySQL・phpMyAdmin をまとめて起動できるため、ローカルに PHP や MySQL を入れなくても `docker compose up -d` だけで開発環境が立ち上がります。

本番相当の構成として **AWS（EC2 + RDS + S3）** にデプロイしています。手順と設計理由は [AWS へのデプロイ](#aws-へのデプロイ) 以降を参照してください。

## 使用技術

- PHP 8.2（PHP-FPM）
- Laravel 12.x
- MySQL 8.0
- Nginx
- phpMyAdmin
- Docker / Docker Compose
- AWS（EC2 / RDS for MySQL / S3 / IAM）

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
| 投稿一覧 | 新しい順に表示。1ページ10件のページネーション付き（`PostRepository@paginateLatest`）。**未ログインでも閲覧可** |
| 投稿詳細 | 本文の改行を保持して表示（XSS 対策済み）。**未ログインでも閲覧可** |
| 投稿作成 | **ログイン必須**。作者は `auth()->user()->posts()->create()` で自動的に自分になる |
| 投稿編集 | **作者本人のみ**。他人の投稿を開こうとすると 403 |
| 投稿削除 | **作者本人のみ**。確認ダイアログを挟んで削除 |
| ボタンの出し分け | 編集・削除リンクは `@can('update', $post)` で作者本人にだけ表示。サーバー側の 403 チェックと同じ Policy を参照 |
| バリデーション | `FormRequest`（`PostRequest`）に集約し、登録と編集で共通化。エラー時は入力値を保持したまま差し戻し |
| レイアウト継承 | `layouts/blog.blade.php` を全画面で `@extends`。ヘッダーにログイン状態を表示 |
| 画像添付 | 投稿1件につき画像1枚。**S3** に保存し、DB（`posts.image_path`）にはキーのみを持つ。作成・編集フォームで共通 |

アクセス制御は「ルート（ミドルウェア）」と「Policy」の2段構えです。

```php
// routes/web.php ── 閲覧は誰でも、書き込み系はログイン必須
Route::resource('posts', PostController::class)
    ->except(['index', 'show'])
    ->middleware('auth');
Route::resource('posts', PostController::class)->only(['index', 'show']);
```

```php
// PostPolicy.php ── ログインしていても「他人の投稿」なら弾く
public function update(User $user, Post $post): bool
{
    return $post->isOwnedBy($user);
}
```

Controller は `$this->authorize('update', $post)`、Blade は `@can('update', $post)` と書くだけで、
**サーバー側のチェックと画面の出し分けが同じ Policy を参照**します。

画像は EC2 のローカルディスクではなく S3 に保存します。EC2 のディスクはインスタンスと運命を共にするため、
作り直した時点で投稿画像が全て失われるからです。

DB に保存するのは URL ではなく**キー（パス）**です。URL を直接持つと、バケット名やリージョンを変更した時点で
既存レコードが全滅し、署名付き URL 方式へ切り替える余地もなくなります。URL の組み立ては1箇所に閉じ込めています。

```php
// app/Models/Post.php ── 公開方式を変えるときはここだけ直せばよい
protected function imageUrl(): Attribute
{
    return Attribute::get(function (): ?string {
        if (! $this->image_path) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk(self::IMAGE_DISK);

        return $disk->url($this->image_path);
    });
}
```

S3 へのアップロードは `PostService` に置き、Controller は `$request->file('image')` を渡すだけです。
S3 はトランザクションに参加しないため、`DB::transaction` の内側でアップロードするとロールバック時に
ファイルだけが残ります。そのため **アップロードはトランザクションの外**で行い、DB が失敗した場合は
S3 側を明示的に削除しています。更新時の旧ファイル削除は、逆に **DB の更新が確定してから**行います。

> `create` を含むルートを先に登録しています。`show`（`posts/{post}`）を先に書くと `/posts/create` が `{post}` に吸われてしまうためです。

### 3. タスク管理システム

**最初から Repository / Service / Policy パターンで構築**した機能です（ブログはリファクタで到達、こちらは初手からその形）。

| 機能 | 説明 |
| --- | --- |
| タスク一覧 | **自分のタスクのみ**表示。期限が近い順、期限なしは末尾。1ページ10件 |
| 状態サマリー | 未着手／着手中／完了の件数を集計表示（`countByStatusFor` の1クエリ） |
| 絞り込み | 状態での絞り込みと「期限切れのみ」。ページ送りしても条件が消えない（`withQueryString`） |
| タスク登録 / 編集 / 削除 | CRUD 一式。所有者は `$user->tasks()->create()` で自動決定 |
| **完了にする / 未着手に戻す** | 専用ルート（`PATCH tasks/{task}/complete`）。`status` と `completed_at` を必ずセットで更新 |
| 期限切れ表示 | 期限を過ぎた未完了タスクを赤字で表示（完了済みは対象外） |
| バリデーション | `TaskRequest` に集約 |
| 認可 | `TaskPolicy`。他人のタスクは詳細すら開けない（403） |

状態を `string` のベタ書きにせず **Enum** で定義しています。

```php
// app/Enums/TaskStatus.php
enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    public function label(): string { ... }   // 表示名（未着手 / 着手中 / 完了）
}
```

`Task` モデルの `$casts` に指定しているので、`$task->status` は文字列ではなく `TaskStatus` として取り出せます。
`$task->status->label()` で表示名になり、タイプミスは実行前に気づけます。

**「完了にする」を `update` と分けた理由**が、この課題で Service 層の価値が一番出るところです。

```php
// app/Services/TaskService.php
public function complete(Task $task): Task
{
    if ($task->isDone()) {
        return $task;          // 二重実行しても壊れない
    }

    return DB::transaction(fn () => $this->tasks->update($task, [
        'status' => TaskStatus::Done->value,
        'completed_at' => now(),      // ← status と必ずセットで更新
    ]));
}

// 逆に汎用の update では状態を触らせない
public function update(Task $task, array $data): Task
{
    unset($data['status'], $data['completed_at']);

    return $this->tasks->update($task, $data);
}
```

「完了なのに `completed_at` が NULL」という不整合が起こりえない構造になっています。
Controller は `$this->taskService->complete($task);` の1行だけです。

タスクだけ **インターフェースを切って DI** しています。

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(TaskRepositoryInterface::class, EloquentTaskRepository::class);
```

`TaskService` は `TaskRepositoryInterface` を要求するので、Eloquent 実装への依存がありません。
テスト時に偽の Repository へ差し替えられる、というのが「テストしやすい設計」の具体例です。

### 4. 商品管理システム

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

### 5. イベント予約システム

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

## アーキテクチャ（Repository / Service / Policy）

ブログ（`Post`）とタスク管理（`Task`）は次の構成です。
ブログは Fat Controller からのリファクタで到達し、タスク管理は最初からこの形で構築しています。

```
PostController ──→ PostService ──→ PostRepository ──→ Post（Eloquent）
    │
    ├──→ PostRequest（バリデーション）
    └──→ PostPolicy（認可）
```

| 層 | 責務 | 書いてはいけないもの |
| --- | --- | --- |
| Controller | リクエストを受け取り Service を呼び、view / redirect を返す | 条件分岐・クエリ・業務ルール |
| Service | 業務ルール、トランザクション境界 | Eloquent クエリ、`view()`、`redirect()` |
| Repository | Eloquent のクエリのみ | `auth()`、`request()`、`redirect()` |
| Policy | 「この人はこの操作をしてよいか」だけ | データの取得・更新 |

`PostService` は `auth()` を呼ばず、作者を引数で受け取ります。
HTTP リクエストの外（Artisan コマンド、キュー、テスト）からも同じように呼べる形にするためです。

```php
// Controller は「受け取って渡す」だけ
public function update(PostRequest $request, Post $post)
{
    $this->authorize('update', $post);

    $this->postService->update($post, $request->validated());

    return redirect()->route('posts.show', $post)
        ->with('success', '投稿を更新しました');
}
```

`Task` 側はさらに **Repository をインターフェース化**し、`AppServiceProvider` で実装を束縛しています。
`TaskService` が具象クラス（Eloquent 実装）を知らない形で、差し替え可能な設計の例です。

before / after の詳細な比較、数値、トレードオフは
**[docs/refactoring.md](docs/refactoring.md)** にまとめています。

> Laravel 11 以降、基底 `Controller` から `AuthorizesRequests` トレイトが外れています。
> `$this->authorize()` を使うには `app/Http/Controllers/Controller.php` への追加が必要です。

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
| `/tasks` | タスク一覧（自分のみ） | **必須** |
| `/tasks/create` | タスク登録フォーム | **必須** |
| `/tasks/{id}` | タスク詳細 | **必須（所有者のみ）** |
| `/tasks/{id}/edit` | タスク編集フォーム | **必須（所有者のみ）** |

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
| **なりすまし投稿** | `Post` の `$fillable` に `user_id` を含めず、`$user->posts()->create()` で作者を決定。フォームに `user_id` を混ぜても無視される | `app/Models/Post.php`、`PostRepository@createFor` |
| **他人の投稿の改ざん** | `PostPolicy` で作者を照合。Controller は `authorize()`、Blade は `@can` で同じルールを参照し、不一致なら 403 | `app/Policies/PostPolicy.php` |
| **未認証アクセス** | 書き込み系ルートを `auth` ミドルウェアで保護。未ログインは `/login` へリダイレクト | `routes/web.php` |
| **ブルートフォース** | ログイン試行のレートリミット（5回失敗で一時ロック） | `app/Http/Requests/Auth/LoginRequest.php` |
| **セッション固定化** | ログイン時に `session()->regenerate()`、ログアウト時に `invalidate()` + `regenerateToken()` | `AuthenticatedSessionController` |
| **機密情報のコミット** | `.env` は `.gitignore` 済み。共有用に `.env.example` のみコミット | `.gitignore` |
| **SVG による XSS** | `image` ルールは SVG を許可するが、SVG は XML なので `<script>` を埋め込める。S3 の URL に直接アクセスされると実行されるため `mimes:jpeg,png,webp` で除外 | `app/Http/Requests/PostRequest.php` |
| **アップロードファイル名経由の攻撃** | `store()` でランダムなキーを採番し、元のファイル名を一切使わない。パストラバーサル・上書き・URL 推測をまとめて防ぐ | `app/Services/PostService.php` |
| **S3 の過剰な権限** | IAM ユーザーには `PutObject` / `GetObject` / `DeleteObject` のみ付与。`AmazonS3FullAccess` は使わない | IAM ポリシー `LaravelS3UploadPolicy` |

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
| タスク | 12件 | 2人に交互に割り当て。期限切れ・期限なし・完了済みを含む |
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
| http://localhost/tasks | タスク管理（要ログイン） |
| http://localhost/login | ログイン |
| http://localhost:8080 | phpMyAdmin（root / secret） |

「自分の投稿だけ編集・削除できる」ことは、以下の手順で確認できます。

1. `test@example.com` でログインし、`/posts` を開く
2. 自分の投稿にだけ「編集」リンクが出ることを確認
3. 他人（Other User）の投稿の詳細を開き、編集・削除ボタンが**出ない**ことを確認
4. URL を直接叩いて `/posts/{他人の投稿のid}/edit` にアクセス → **403** になることを確認


---

## AWS へのデプロイ

### 構成

```
                インターネット
                      │ HTTPS :443（:80 は 301 リダイレクト）
                      ▼
        ┌──────────────────────────────┐
        │ EC2  Ubuntu 24.04 / t3.micro │  Elastic IP で固定
        │                              │  aoleaf.com
        │  Nginx（ホスト） :80 / :443  │  ← TLS 終端・リバースプロキシ
        │       │ HTTP :8000           │
        │       ▼  (127.0.0.1 のみ)    │
        │  docker-compose.prod.yml     │
        │   ├─ nginx    :80            │  ← 静的配信・PHP-FPM への受け渡し
        │   └─ app (PHP-FPM)           │
        └───────┬──────────────┬───────┘
        MySQL:3306              │ HTTPS
                ▼               ▼
      ┌─────────────────┐  ┌───────────────┐
      │ RDS MySQL 8.0   │  │  S3 バケット   │
      │ 公開アクセス無効 │  │  投稿画像      │
      └─────────────────┘  └───────────────┘
```

**なぜ EC2 の中で MySQL を動かさないのか。** EC2 のディスクはインスタンスと運命を共にするためです。
実際にこの構築では、AMI を選び直すために一度インスタンスを作り直しています。
そのとき DB がインスタンス内にあれば、投稿もユーザーも全て失われていました。

**寿命の異なるものを同じ箱に入れない** —— 作り直せるアプリケーションと、失えないデータを分離する。
これが RDS と S3 を使う理由です。

### 前提

- AWS アカウント。ルートユーザーは MFA を有効化し、日常操作には使わない
- 管理用の IAM ユーザーを作成し、`Admins` グループ経由で `AdministratorAccess` を付与（MFA 有効）
- リージョンは **`ap-northeast-1`（東京）** に統一。EC2 と S3 が別リージョンだと転送料と遅延が発生する

### 1. AWS リソースの作成

#### EC2

| 項目 | 選んだ値 | 理由 |
| --- | --- | --- |
| AMI | Ubuntu 24.04 LTS (noble) | 教材指定の 22.04 はコンソール上で無料利用枠バッジが表示されないため 24.04 を選択 |
| インスタンスタイプ | t3.micro | 2025年7月の無料プラン改定で t2.micro が対象外になったため。現行の対象は t3 / t4g 系 |
| キーペア | 新規作成（`.pem`） | SSH 接続用。ダウンロードできるのは作成時の一度きり |
| パブリック IP | **Elastic IP** を関連付け | 停止・起動のたびに IP が変わると、`APP_URL` と SSH 接続先を毎回書き換えることになる |

#### RDS

| 項目 | 選んだ値 | 理由 |
| --- | --- | --- |
| エンジン | MySQL 8.0 | ローカルの Docker と揃える |
| インスタンスクラス | db.t3.micro（無料利用枠テンプレート） | 学習用途のため |
| 認証 | パスワード認証 | IAM 認証は今回は使わない |
| パブリックアクセス | **無効** | インターネットから到達不可にする。SG と合わせた二重防御 |
| 初期データベース名 | `laravel` | ここを空にすると DB 自体が作られず、`migrate` の前に手動で `CREATE DATABASE` が必要になる |

#### S3

| 項目 | 選んだ値 | 理由 |
| --- | --- | --- |
| バケット名 | `your-bucket-name` | S3 の名前空間は全世界で一意。既存の名前とは衝突する |
| リージョン | ap-northeast-1 | EC2 と同一リージョン |
| ブロックパブリックアクセス | バケットポリシー経由の公開をブロックする2項目のみ解除 | ポリシーを効かせるため。全解除はしない |
| 公開方法 | バケットポリシーの `s3:GetObject` | ACL は使わない（後述） |

### 2. EC2 のセットアップ

#### Docker のインストール（公式リポジトリ）

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER   # 反映には再ログインが必要
docker run --rm hello-world
```

> `$VERSION_CODENAME` が Docker 公式リポジトリに存在するコードネームかを確認してください。
> 新しすぎる Ubuntu（26.04 = `resolute` など）はまだ配布されておらず、`apt-get update` が 404 になります。

#### アプリケーションの配置

```bash
git clone https://github.com/aoleaf/techmeets-month2.git
cd techmeets-month2
cp .env.example .env
```

`.env` の主要項目です。

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://aoleaf.com
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mysql
DB_HOST=<RDS のエンドポイント>
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=<マスターユーザー名>
DB_PASSWORD=<マスターパスワード>

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<laravel-s3-user のアクセスキー>
AWS_SECRET_ACCESS_KEY=<同シークレット>
AWS_DEFAULT_REGION=ap-northeast-1
AWS_BUCKET=your-bucket-name
```

> `APP_DEBUG=false` は必須です。`true` のままだと例外画面に `.env` の値（DB のパスワードや AWS のキー）が
> そのまま表示されます。
>
> `AWS_DEFAULT_REGION` を書き忘れると `config/filesystems.php` が `.env.example` 由来の `us-east-1` を読み、
> 東京リージョンのバケットに届かず `AccessDenied` になります。

#### フロントエンドアセットについて

`public/build/`（Vite のビルド成果物）は、ローカルでビルドして `git add -f` でコミットしています。
`.gitignore` の対象ですが、EC2 上には Node.js が無く、clone しても存在しないためです。

Breeze の `layouts/guest.blade.php` は `@vite()` でアセットを読むため、これが無いと
`/login` `/register` が `Vite manifest not found` で 500 になります。

EC2 上でビルドしない理由は2つです。t3.micro（メモリ1GB）では Vite のビルドが
メモリ不足で失敗するリスクがあること、そして本番サーバーに Node と devDependencies を
常駐させることになり「本番には動かすものだけを置く」原則に反することです。
本来は CI でビルドし、成果物のみをデプロイするのが適切で、これは残課題です。

```bash
# ローカルで実行してコミットする
npm ci
npm run build
git add -f public/build
```

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.prod.yml exec app php artisan key:generate
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data storage bootstrap/cache
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
```

### 3. `docker-compose.prod.yml` の差分

ローカル用の `docker-compose.yml` から、以下を削っています。

| 削ったもの | 理由 |
| --- | --- |
| `db` サービス | DB は RDS を使うため。EC2 内に置くとインスタンスの寿命とデータの寿命が一致してしまう |
| `phpmyadmin` サービス | 認証情報が compose に固定値で書かれたまま 8080 番を公開することになり、DB の管理画面がインターネットに晒される |
| `app` の `environment` | **最重要**。ここに `DB_HOST=db` が残っていると、`.env` に RDS のエンドポイントを書いても反映されない |
| `app` の `depends_on: db` | 参照先のサービスが無くなったため |
| トップレベルの `volumes:`（`db-data`） | MySQL コンテナ用の永続化ボリュームだったため |

`environment` の挙動は特に嵌まりやすい点です。Laravel の `env()` は **OS の環境変数を優先**し、
`.env` ファイルは既に存在する環境変数を上書きしません。compose の `environment:` はコンテナの環境変数として
セットされるため、`.env` をいくら直しても `DB_HOST=db` が勝ち続け、存在しないホストへ接続しようとします。

`app` の `volumes`（`./:/var/www/html`）は残しています。EC2 上のリポジトリをそのままマウントし、
そこへ `composer install` するためです。

#### 残課題：コンテナの自動起動

現状 `restart` ポリシーを設定していないため、EC2 を停止・再起動するとコンテナが停止したままになります。
Docker デーモン自体は systemd で自動起動しますが、コンテナの復帰は別の話です。

本番構成としては以下を追加すべきです。

```yaml
services:
  nginx:
    restart: always
  app:
    restart: always
```

「サーバーが再起動してもサービスが自動復帰するか」は、本番環境で当然考慮すべき点でした。

Week 13 でホストに Nginx と Certbot のタイマーを追加しましたが、これらは systemd 管理のため再起動後も自動復帰します。
そのため EC2 を再起動すると **Nginx だけが動きコンテナが落ちている**状態になり、502 Bad Gateway が返ります。
サイト全体が無反応だった以前と違い、HTTPS も証明書も正常に見えるぶん原因が分かりにくくなりました。


### 4. 詰まった点と対処

| 事象 | 原因 | 対処 |
| --- | --- | --- |
| `apt-get update` が 404 になり Docker が入らない | 選んだ AMI が 26.04（`resolute`）で、Docker 公式リポジトリに該当コードネームが無い | 24.04 LTS の AMI でインスタンスを作り直した |
| t2.micro が選択できない | 2025年7月の無料プラン改定で対象外になった | t3.micro を選択 |
| IAM ダッシュボードで AccessDenied | ポリシーはグループに付与済みだったが、**グループにユーザーが入っていなかった** | `Admins` グループにユーザーを追加 |
| `vendor/autoload.php` not found | `vendor/` は `.gitignore` 対象で clone しても存在しない。加えて `volumes` マウントでイメージ内の `vendor` が隠れる | マウント先で `composer install --no-dev` を実行 |
| 500 エラーだがログも出ない | `storage/` が `ubuntu` 所有で、`www-data` で動く PHP-FPM が**ログすら書けない** | `chown -R www-data:www-data storage bootstrap/cache` |
| `db:seed` で `fake()` が undefined | `--no-dev` で `fakerphp/faker`（`require-dev`）が除外された | 本番ではシーダーを実行しない方針とした |
| S3 が AccessDenied | `AWS_DEFAULT_REGION` が未設定で、`.env.example` 由来の `us-east-1` が使われていた | `.env` に `ap-northeast-1` を明記し `config:clear` |
| `/login` `/register` だけ 500 になる | `layouts/guest.blade.php` が `@vite()` を使うが、`public/build/` は `.gitignore` 対象で clone に含まれない | ローカルでビルドし `git add -f public/build` でコミット |
| EC2 再起動後にアプリが応答しない | コンテナに `restart` ポリシーを設定していないため、Docker デーモンは起動してもコンテナは復帰しない | 手動で `docker compose -f docker-compose.prod.yml up -d`。恒久対応は残課題（後述） |
| `dig` がパーキングの IP を返す | DNS レコードは `01.dnsv.jp` に保存したが、ドメインのネームサーバーは初期値の `dns1.onamae.com` を向いたままだった | ネームサーバーを `01〜04.dnsv.jp` に変更 |
| ネームサーバー変更後も EC2 だけ古い値を返す | NS レコードの TTL が 86400 で、AWS のリゾルバがキャッシュを保持していた | 外部からは正しく引けており Certbot の検証は Let's Encrypt 側から行われるため実害なし。確認時のみ `curl --resolve` で名前解決を上書き |
| セキュリティグループのルール保存に失敗 | 説明欄にアポストロフィ（使用不可文字）を入れていた | 英数字のみに修正 |
| `Unable to register an account with ACME server` | Certbot の利用規約への同意を `N` で答えた（EFF へのメール共有と混同） | 規約は `Y`、EFF 共有は `N` で再実行 |
| www 用のリダイレクトが効かない | 既存 `server_name` から www を外し忘れ、後から追加したブロックが `conflicting server name ... ignored` で無視されていた | 既存ブロックから `www.aoleaf.com` を削除。`nginx -t` は successful でも warn は読む |

DNS 関連の2件は、いずれも「DNS は誰に聞くかで答えが変わる」ことの実例でした。
レジストラの管理画面上の表示・権威サーバーの応答・リゾルバのキャッシュは別のもので、切り分けには段階を踏む必要があります。

---

## 独自ドメインと HTTPS 化

Elastic IP で公開していたアプリケーションに独自ドメイン `https://aoleaf.com` を割り当て、
Let's Encrypt の証明書で HTTPS 化しています。

公開 URL: **https://aoleaf.com**

### Nginx が2段になる理由

この構成では Nginx が2つ動いています。役割が違うため、重複ではありません。

| | 担当 |
| --- | --- |
| **ホストの Nginx**（Ubuntu / :80, :443） | TLS の終端、リバースプロキシ |
| **コンテナの Nginx**（:80） | 静的ファイルの配信、PHP-FPM への受け渡し |

```
ブラウザ ──(443/TLS)──▶ ホスト Nginx ──(8000/平文)──▶ コンテナ Nginx ──▶ PHP-FPM
```

TLS の復号をホスト側の1箇所に集約することで、証明書の管理場所が1つで済み、
後ろのアプリケーションは平文 HTTP のままでいられます。
Certbot の Nginx プラグインもホスト側の設定ファイルだけを見ればよくなります。

### 作業順序

**DNS の反映が全ての前提**になるため、順序を守る必要があります。

1. ドメイン取得
2. DNS レコード設定 → `dig` で反映確認
3. コンテナの公開ポートを 80 から 8000 へ付け替え
4. ホストに Nginx を設置し、HTTP で疎通確認
5. Certbot で証明書取得・HTTPS 化
6. Laravel 側をプロキシ配下の設定に変更

Certbot は Let's Encrypt 側が外部から `http://aoleaf.com/.well-known/...` へアクセスして
ドメインの所有を検証します。DNS が正しく EC2 を指していない状態では必ず失敗します。

### 1. ドメイン取得

お名前.com で `aoleaf.com` を取得しました（初年度 0円 / 翌年以降 1,408円）。

| 設定 | 理由 |
| --- | --- |
| Whois 情報公開代行を有効化 | 有効にしないと登録者の氏名・住所・電話番号が公開データベースに載る。**登録と同時なら無料**だが、後から申し込むと年 980円かかる |
| 自動更新をオフ | 学習用のため1年で失効させる。初年度無料の TLD ほど翌年の更新料が高い |
| レンタルサーバーのセット申込を外す | 「合計 0円」表示でも翌月から月額が発生する。アプリは EC2 で動いており不要 |

`.dev` / `.app` は HSTS プリロードにより HTTP でアクセスできないため、
途中の HTTP 疎通確認ができなくなります。今回は対象外としました。

### 2. DNS レコード設定

| ホスト名 | タイプ | 値 | TTL |
| --- | --- | --- | --- |
| （空欄 = ルート） | A | `<Elastic IP>` | 3600 |
| www | CNAME | aoleaf.com | 3600 |

`www` を CNAME にしているのは、Elastic IP を書き換えるときの修正箇所を A レコード1箇所に閉じ込めるためです。
IP を2箇所に書くと、片方だけ直して不整合を起こします。

### 3. コンテナの公開ポートの付け替え

ホストに Nginx を入れる前に、80 番を空ける必要があります。

```diff
  nginx:
    ports:
-     - "80:80"
+     - "127.0.0.1:8000:80"
```

**`127.0.0.1:` を必ず付けます。** これを省くと 8000 番がインターネットから直接叩けてしまい、
HTTPS を迂回して平文でアクセスできる経路が残ります。
「443 を開ける」と同時に「8000 を閉じる」ことがセットです。

```bash
docker compose -f docker-compose.prod.yml up -d
sudo ss -tlnp | grep :8000
# LISTEN 0 4096 127.0.0.1:8000 0.0.0.0:* users:(("docker-proxy",...))
#            ^^^^^^^^^ 0.0.0.0 ではなくループバックに閉じている
```

### 4. ホスト Nginx のリバースプロキシ設定

実際の設定ファイル全文は [docs/nginx/myapp.conf](docs/nginx/myapp.conf) にあります（Certbot による自動生成部分を含む）。

```nginx
server {
    listen 80;
    server_name aoleaf.com www.aoleaf.com;

    location / {
        proxy_pass         http://localhost:8000;
        proxy_http_version 1.1;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

各ヘッダには役割があり、省くと具体的な不具合が出ます。

| 行 | 省くとどうなるか |
| --- | --- |
| `proxy_http_version 1.1` | 上流へ HTTP/1.0 で接続する。keep-alive が効かず、将来 WebSocket の `Upgrade` も通せない |
| `Host $host` | 上流に `Host: localhost:8000` が渡る。Laravel の `url()` / `route()` がこの値でリンクを生成するため、**全てのリンクが `http://localhost:8000/...` になる** |
| `X-Real-IP` / `X-Forwarded-For` | アプリから見た接続元が Nginx（127.0.0.1）になり、本当のクライアント IP が失われる |
| `X-Forwarded-Proto $scheme` | HTTPS 化後にアプリが「自分は http で呼ばれた」と誤認し、`asset()` が `http://` を返して mixed content でブロックされる |

```bash
sudo ln -s /etc/nginx/sites-available/myapp.conf /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default   # デフォルトの welcome ページを無効化
sudo nginx -t
sudo systemctl reload nginx
```

**この時点で `http://aoleaf.com` がブラウザで開けることを確認してから** Certbot に進みます。
ここが通らないまま実行しても、Certbot は必ず失敗します。

### 5. Certbot による証明書取得

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d aoleaf.com -d www.aoleaf.com
```

Nginx プラグインが設定ファイルを自動的に書き換え、以下を追加します。

- 443 の `server` ブロック（証明書パス、TLS 設定）
- 80 側の `return 301 https://$host$request_uri;`（HTTP → HTTPS リダイレクト）

### 6. Laravel 側の設定

TLS を終端するのはホスト Nginx で、コンテナには**平文 HTTP が届きます**。
そのため Laravel は何もしなければ「自分は http で呼ばれた」と認識し続けます。

Nginx は `X-Forwarded-Proto: https` を付けて転送していますが、
このヘッダは誰でも偽装できるため、Laravel はデフォルトで信用しません。
信頼するプロキシを明示して初めて、自分が HTTPS 配下にいると認識します。

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})
```

```env
APP_URL=https://aoleaf.com
SESSION_SECURE_COOKIE=true
```

`at: '*'`（全プロキシを信頼）は本来ゆるい設定ですが、
8000 番を `127.0.0.1` に閉じているため、ホスト Nginx 以外からリクエストが到達する経路がありません。

`SESSION_SECURE_COOKIE` は `.env.example` に無いので追記が必要です。
これを入れると Cookie に `secure` 属性が付き、HTTPS でのみ送信されるようになります。

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:clear
```

### 7. www ありを www なしに統一

Certbot は `aoleaf.com` と `www.aoleaf.com` を**同一の `server` ブロック**にまとめるため、
初期状態では www 付きでもそのまま表示されます。同じ内容が2つの URL で見える状態です。

既存ブロックから www を外し、リダイレクト専用のブロックを追加して分離します。

```nginx
server {
    server_name aoleaf.com;          # ← www.aoleaf.com を削除
    ...
}

server {
    listen 443 ssl;
    server_name www.aoleaf.com;

    # 証明書に www も含まれているため同じパスを流用できる
    ssl_certificate     /etc/letsencrypt/live/aoleaf.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/aoleaf.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    return 301 https://aoleaf.com$request_uri;
}
```

リダイレクトだけを返すブロックにも `ssl_certificate` が必要です。
**TLS ハンドシェイクが成立して初めて HTTP のレスポンスを返せる**ため、
証明書が無ければリダイレクトを届ける前に接続が失敗します。

---

## コマンドによる動作確認

### `dig aoleaf.com +short`

```
13.230.58.143
```

ドメイン名を IPv4 アドレスに変換した結果（A レコードの値）です。
EC2 に割り当てた Elastic IP と一致しており、名前解決が正しく設定されていることを示します。

```
$ dig www.aoleaf.com +short
aoleaf.com.
13.230.58.143
```

www 側は2行返ります。1行目が CNAME による別名の解決結果、2行目がその先の A レコードです。
CNAME が正しく機能していることが、この2段の出力から確認できます。


反映されない場合は、次の3段階で切り分けます。

```bash
dig aoleaf.com NS +short              # ドメインがどのネームサーバーを向いているか
dig @01.dnsv.jp aoleaf.com A +short   # 権威サーバーに直接聞く（キャッシュを迂回）
dig @8.8.8.8 aoleaf.com +short        # 外部のリゾルバから見た結果
```

上から順に、委任の設定・レコードの保存・キャッシュの状態を確認できます。

### `curl -I https://aoleaf.com`

```
HTTP/1.1 200 OK
Server: nginx/1.24.0 (Ubuntu)
Content-Type: text/html; charset=utf-8
Set-Cookie: XSRF-TOKEN=...; path=/; secure; samesite=lax
Set-Cookie: laravel-session=...; path=/; secure; httponly; samesite=lax
```

| 項目 | 意味 |
| --- | --- |
| `HTTP/1.1 200 OK` | リクエスト成功。TLS ハンドシェイクも完了している（失敗していれば HTTP 応答自体が返らない） |
| `Server: nginx/1.24.0 (Ubuntu)` | 応答しているのが**ホスト側**の Nginx であることが分かる。コンテナ内の Nginx は 1.31.4 なので、2段構成のどちらが返しているか判別できる |
| `Content-Type: text/html; charset=utf-8` | 返却内容が HTML、文字コードが UTF-8 |
| `secure` | HTTPS 接続でのみ送信される Cookie。`SESSION_SECURE_COOKIE=true` の効果 |
| `httponly` | JavaScript から読めない Cookie。XSS でセッションを盗まれることを防ぐ |

HTTP 側も確認します。

```
$ curl -I http://aoleaf.com
HTTP/1.1 301 Moved Permanently
Location: https://aoleaf.com/
```

`301` は恒久的な移転を示すステータスコードで、ブラウザや検索エンジンは以後 HTTPS を使います。
`Location` が転送先です。HTTP → HTTPS の自動リダイレクトが機能しています。

```
$ curl -I https://www.aoleaf.com
HTTP/1.1 301 Moved Permanently
Location: https://aoleaf.com/
```

www ありも www なしへ統一されています。

### `sudo certbot certificates`

```
Certificate Name: aoleaf.com
  Key Type: ECDSA
  Domains: aoleaf.com www.aoleaf.com
  Expiry Date: 2026-12-07 15:27:46+00:00 (VALID: 89 days)
  Certificate Path: /etc/letsencrypt/live/aoleaf.com/fullchain.pem
  Private Key Path: /etc/letsencrypt/live/aoleaf.com/privkey.pem
```

| 項目 | 意味 |
| --- | --- |
| `Domains` | この証明書がカバーするドメイン。www あり・なしの両方が含まれるため、www 用の `server` ブロックでも同じ証明書を使える |
| `Expiry Date ... (VALID: 89 days)` | 有効期限。Let's Encrypt の証明書は**90日**で切れる。短いのは、自動更新を前提とし、漏洩時の影響期間を短くするため |

自動更新は systemd のタイマーが担当します。

```
$ sudo systemctl status certbot.timer
● certbot.timer - Run certbot twice daily
     Loaded: loaded (/usr/lib/systemd/system/certbot.timer; enabled; ...)
     Active: active (waiting) since Tue 2026-09-08 16:24:07 UTC
    Trigger: Wed 2026-09-09 04:34:50 UTC; 11h left
```

`enabled`（サーバー再起動後も有効）かつ `active (waiting)`（次回実行を待機中）。
1日2回起動し、期限が30日を切っている証明書だけを実際に更新します。

```
$ sudo certbot renew --dry-run
Congratulations, all simulated renewals succeeded:
  /etc/letsencrypt/live/aoleaf.com/fullchain.pem (success)
```

更新処理を本番の証明書を消費せずに試験実行した結果です。
90日後に自動更新が確実に動くことを、期限切れを待たずに検証できます。


## 残課題

| 項目 | 内容 |
| --- | --- |
| HSTS 未設定 | `Strict-Transport-Security` ヘッダを返していない。設定すると、ブラウザが2回目以降は最初から HTTPS で接続し、初回リダイレクト時の中間者攻撃の余地を無くせる。有効期間を長く設定すると取り消しが難しいため、運用が安定してから導入するのが望ましい |
| Certbot による設定の上書き | www 分離のため 443 の `server` ブロックを手動編集している。`certbot --nginx` を再実行すると www が書き戻される可能性がある（`certbot renew` は設定ファイルを変更しないため通常運用では影響しない） |
| コンテナの自動起動 | Week 11 からの継続課題。`restart: always` が未設定のため、EC2 再起動後にコンテナが復帰しない。ホストの Nginx と Certbot タイマーは systemd により自動復帰するので、**アプリだけが起動しない**状態になる |

---

## セキュリティグループの設計

### EC2 側

| タイプ | ポート | ソース | 理由 |
| --- | --- | --- | --- |
| SSH | 22 | マイ IP（`/32`） | ログインするのは自分だけでよい。`0.0.0.0/0` にすると、公開直後から総当たりログイン試行の標的になる |
| HTTP | 80 | `0.0.0.0/0` | HTTPS へのリダイレクトと、Let's Encrypt の HTTP-01 検証で必要 |
| HTTPS | 443 | `0.0.0.0/0` | 不特定多数が閲覧する Web サーバーのため、全許可でなければ役目を果たさない 

### RDS 側（`rds-ec2-1`）

| タイプ | ポート | ソース | 理由 |
| --- | --- | --- | --- |
| MySQL/Aurora | 3306 | **EC2 のセキュリティグループ ID** | 接続元はアプリケーションサーバーだけでよい。インターネットからは到達できない |

### 設計上の判断

>**443 を後から開けた理由**
>
> Week 11 の時点では HTTPS を提供していなかったため、443 は閉じていました。
> 待ち受けるプロセスが無いポートを開けても、攻撃対象領域が増えるだけで得るものがありません。
> ドメインと証明書を用意した Week 13 で追加しています。
> **必要になってから開ける**という判断そのものが、最小権限の実践です。
>
> 逆に、アプリケーションコンテナの公開ポートは `127.0.0.1:8000` に**閉じました**。
> 開けるだけでなく、不要になったものを閉じるのも同じ原則の一部です（後述）。

**ソースに IP ではなくセキュリティグループ ID を指定した理由**

- EC2 の IP が変わっても RDS 側の設定を変更しなくてよい
- 同じ SG に属するインスタンスを増やしても、自動的に許可対象になる
- IP 指定だと、その IP が解放されて第三者に再割り当てされたとき、意図しない許可が残り続ける

「どのアドレスか」ではなく「**どの役割のサーバーか**」で許可する、という考え方です。

**パブリックアクセス無効との二重防御**

RDS は SG で 3306 を絞るだけでなく、パブリックアクセス自体を無効にしています。
仮に SG の設定を誤って広げてしまっても、そもそもインターネットからは到達できません。
層を分けて守る設計（多層防御）です。

**`ec2-rds-1` と `rds-ec2-1` の対**

RDS の「EC2 接続を設定」機能で自動生成された2つの SG です。
`ec2-rds-1` が EC2 側に、`rds-ec2-1` が RDS 側に付きます。
「EC2 が RDS へ出ていく」「RDS が EC2 から受ける」という**方向の対**になっており、
片側だけでは通信が成立しません。

**未使用のセキュリティグループ**

`default` や作成過程で生成された `launch-wizard-*` が残っています。
使われていない SG が放置されること自体がリスクで、どれが実際に効いているのか分からなくなります。
リソース削除の際にあわせて整理します。

---

## S3 の権限設計

S3 の公開は **アイデンティティベース（IAM ポリシー）** と **リソースベース（バケットポリシー）** の
2種類が噛み合って成立します。役割が異なるので、両方を理解する必要があります。

### IAM ポリシー（`LaravelS3UploadPolicy` / `laravel-s3-user`）

「**アプリケーションが何をできるか**」を定義します。

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::your-bucket-name/*"
    },
    {
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::your-bucket-name"
    }
  ]
}
```

`AmazonS3FullAccess` は使っていません。アプリが実際に行うのは
「アップロード」「取得」「差し替え・削除時の削除」の3つだけで、
バケットの作成や削除、他バケットへのアクセスは不要だからです。
キーが漏れたときの被害範囲は、付与した権限の広さでそのまま決まります。

**`ListBucket` だけ `Resource` に `/*` が付きません。** Action の粒度と ARN の粒度を一致させる必要があるためです。

| Action | 対象 | ARN |
| --- | --- | --- |
| `PutObject` / `GetObject` / `DeleteObject` | バケット内のオブジェクト | `arn:aws:s3:::bucket/*` |
| `ListBucket` | バケットそのもの（中身を一覧する操作） | `arn:aws:s3:::bucket` |

なお ARN の `arn:aws:s3:::` はコロンが3つです。S3 のバケット名は全世界で一意なので、
ARN のリージョン欄とアカウント ID 欄が空になり、区切りのコロンだけが残ります。

### バケットポリシー

「**そのバケットに誰がアクセスできるか**」を定義します。投稿画像は誰でも閲覧できる必要があるため、
読み取りだけを全公開しています。

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicRead",
    "Effect": "Allow",
    "Principal": "*",
    "Action": "s3:GetObject",
    "Resource": "arn:aws:s3:::your-bucket-name/*"
  }]
}
```

### ブロックパブリックアクセスとの2段構え

ブロックパブリックアクセスを解除しただけでは公開されません。
**「公開を許可するか」（ブロック設定）と「実際に公開する」（バケットポリシー）が別レイヤー**になっており、
両方が揃って初めて公開されます。誤操作による意図しない公開を防ぐための構造です。

### ACL を使わない理由

現行の S3 はデフォルトでオブジェクト ACL が無効（Bucket owner enforced）です。
そのため Laravel 側で `storePublicly()` を使ったり visibility に `public` を渡したりすると、
`AccessControlListNotSupported` で失敗します。
公開はバケットポリシーで行い、アプリケーションは ACL を一切触りません。

### 残課題：アクセスキーから IAM ロールへ

現状は IAM ユーザーのアクセスキーを `.env` に置いていますが、**本番では EC2 に IAM ロールを
アタッチするべき**です。

- 長期の認証情報がどこにも存在しなくなる（ファイルに書かれたキーは漏れうる）
- 認証情報が自動でローテーションされ、手動更新が不要になる
- `.env` の管理ミスによる漏洩経路そのものが消える

ルートユーザーを日常操作に使わず MFA を必須にしたのと同じ、
**長期の認証情報を極力存在させない**という原則の延長線上にあります。

---

## リソースの削除

学習用の環境のため、確認後は課金を止めるために削除します。**削除には順序があります。**

### 1. S3

バケットは**空でないと削除できません**。先に中身を削除します。

1. S3 コンソール → バケットを選択 → 「空にする」
2. その後「削除」

### 2. RDS

1. RDS コンソール → DB インスタンスを選択 → アクション → 「削除」
2. 最終スナップショットの作成：**不要**（学習用のため）
3. 削除保護が有効な場合は、先に「変更」から無効化する

### 3. EC2

1. インスタンスを選択 → インスタンスの状態 → **「インスタンスを終了（削除）」**
2. 「停止」ではデータもディスクも残り、EBS の課金が継続する

### 4. Elastic IP

**ここが最も忘れやすく、忘れると課金が続きます。**

1. Elastic IP → 対象を選択 → 「関連付けの解除」
2. 続けて **「Elastic IP アドレスの解放」**

Elastic IP は「インスタンスに関連付いている間は無料、未関連付けだと課金」という料金体系です。
EC2 を削除すると自動的に未関連付けの状態になるため、**解放まで実行しないと課金され続けます**。
関連付けの解除だけでは不十分です。

### 5. セキュリティグループ（任意）

`default` と `launch-wizard-*`、`ec2-rds-1` / `rds-ec2-1` が残ります。
SG 自体に課金はありませんが、使われていないルールが残っていると、
次に環境を作るときにどれが有効なのか分からなくなります。あわせて整理しておきます。

### 6. IAM（任意）

`laravel-s3-user` のアクセスキーは、使わなくなった時点で無効化・削除します。
長期の認証情報を必要以上に存在させないためです。

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
