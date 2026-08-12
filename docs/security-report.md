# セキュリティテストレポート

Week 8 練習課題2。会員制ブログ（`/posts` 系）と Laravel Breeze の認証まわりに対して、
XSS・CSRF・SQLインジェクション・認証/認可の各観点でテストを実施した結果をまとめます。

| 項目 | 内容 |
| --- | --- |
| 対象 | laravel-docker-app（Laravel 12 / PHP 8.2 / MySQL 8.0） |
| 対象ブランチ | `week8/auth-blog` |
| 実施日 | 2026-08-12 |
| 環境 | Docker（Nginx + PHP-FPM + MySQL）、`http://localhost` |
| 手法 | `curl` / PowerShell による手動リクエスト、`php artisan tinker` による DB・クエリ確認、PHPUnit フィーチャーテスト |
| テストアカウント | Test User（`test@example.com`） / Other User（`other@example.com`）、どちらもパスワード `password` |
| 対象データ | 投稿 id=16 は Test User、id=15 は Other User の投稿（`migrate:fresh --seed` で作り直すと ID の割り当ては変わります） |

---

## 結果サマリー

| # | 観点 | 期待 | 結果 | 判定 |
| --- | --- | --- | --- | --- |
| T-01 | 未ログインでの閲覧 | 一覧・詳細は 200 | 200 | ✅ |
| T-02 | 未ログインでの保護ページ | ログインへリダイレクト | 302 → `/login` | ✅ |
| T-03 | CSRF トークンなしの POST | 拒否 | 419 | ✅ |
| T-04 | 認証（正しい資格情報） | ログイン成功 | 302 → `/dashboard`、以後 `/dashboard` が 200 | ✅ |
| T-05 | 他人の投稿の編集・更新・削除 | 拒否 | いずれも 403、データ変化なし | ✅ |
| T-06 | XSS（`<script>` の投稿） | エスケープされる | 生のタグ 0 件、`&lt;script&gt;` として出力 | ✅ |
| T-07 | 作者のなりすまし（`user_id` 送信） | 無視される | 送信 `user_id=3` → 実際は `user_id=1` | ✅ |
| T-08 | SQL インジェクション | 構文として解釈されない | プリペアドステートメントで値として束縛 | ✅ |
| T-09 | ログイン試行のレートリミット | 一定回数で制限 | 6回目で `Too many login attempts.` | ✅ |
| T-10 | パスワードの保存形式 | ハッシュ化 | bcrypt（`$2y$12$...`） | ✅ |
| T-11 | 機密情報の管理 | `.env` が Git 管理外 | `.gitignore:3` で除外、追跡ファイル 0 件 | ✅ |
| T-12 | セッション Cookie | HttpOnly | `http_only=true` / `same_site=lax` | ✅ |

すべて期待どおりの結果でした。以下、各項目の詳細です。

---

## T-01 / T-02　認証によるアクセス制御

投稿の**閲覧は誰でも可**、**作成・編集・削除はログイン必須**という設計です。
`routes/web.php` で書き込み系ルートだけを `auth` ミドルウェアで保護しています。

```
### 未ログインでの公開ページ
GET /posts        => 200
GET /posts/16     => 200

### 未ログインでの保護ページ
GET /posts/create => 302 http://localhost/login
GET /dashboard    => 302 http://localhost/login
GET /profile      => 302 http://localhost/login
```

保護対象のページはビューの出し分けに頼らず、ミドルウェアでルートごと塞いでいます。
URL を直接入力してもログイン画面へ飛ばされます。

---

## T-03　CSRF

全ての POST / PUT / DELETE フォームに `@csrf` を入れており、
トークンのないリクエストは `VerifyCsrfToken` ミドルウェアが 419 で拒否します。

```
POST /posts トークン無し             => 419
POST /posts トークン有り・未ログイン => 302 http://localhost/login
```

トークンが正しくても未ログインなら `auth` ミドルウェアで止まります。
**CSRF 対策と認証チェックが独立して効いている**ことが確認できました。

> 補足：PHPUnit のフィーチャーテストでは CSRF 検証がスキップされる仕様
> （`ValidateCsrfToken::runningUnitTests()`）のため、419 の確認は上記のとおり
> 実際に起動したアプリへのリクエストで行っています。

---

## T-04 / T-05　認可（自分の投稿だけ編集・削除できるか）

Test User でログインし、Other User の投稿（id=15）を操作できないことを確認しました。

```
### ログイン
POST /login    => 302 http://localhost/dashboard
GET /dashboard => 200

### Test User が Other User の投稿(id=15)を操作
GET    /posts/15/edit => 403
PUT    /posts/15      => 403
DELETE /posts/15      => 403
GET    /posts/16/edit => 200   （自分の投稿）
投稿15は残っているか  => 200
```

削除を試みた後も投稿15は残っており、**403 が単なる画面上のエラーではなく
実際に処理を中断している**ことが確認できました。

判定は `PostController` で行っています。ビュー側でもボタンを出し分けていますが、
表示制御はあくまで UI の都合であり、防御はサーバー側の以下のチェックです。

```php
private function authorizeOwner(Post $post): void
{
    if (! $post->isOwnedBy(auth()->user())) {
        abort(403, 'この操作は許可されていません');
    }
}
```

---

## T-06　XSS

`<script>alert("XSS")</script>` を本文に含む投稿を作成し、詳細画面の出力を確認しました。

```
POST結果: 200 / 最終URL: http://localhost/posts/22
エスケープ済み(&lt;script&gt;)の出現数: 1
生の<script>alert の出現数        : 0
--- 該当行 ---
<div>&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;</div>
```

タグは全て HTML エンティティに変換されており、ブラウザはこれを文字列として表示します。
スクリプトは実行されません。

本文は改行を `<br>` に変換するため `{!! !!}` を使っていますが、
**`e()` でエスケープしてから `nl2br()` に渡している**ため安全です。

```blade
{!! nl2br(e($post->content)) !!}
```

`e()` を外して `{!! nl2br($post->content) !!}` にすると、この時点で XSS が成立します。
`{!! !!}` を使う箇所では必ず `e()` を通すことが必要です。

---

## T-07　作者のなりすまし（Mass Assignment）

投稿フォームに存在しない `user_id` パラメータを混ぜて送信しました。

```
なりすまし投稿: id=20 送信したuser_id=3 / 実際のuser_id=1 (Test User)
```

`Post` モデルの `$fillable` に `user_id` を含めず、
作成をログインユーザー経由で行っているため、リクエストの値は無視されます。

```php
// Post.php
protected $fillable = ['title', 'content', 'category'];   // user_id は入れない

// PostController@store
$post = $request->user()->posts()->create($validated);    // 作者はサーバー側が決める
```

---

## T-08　SQL インジェクション

`' OR '1'='1` を検索値・ID として渡し、発行された SQL を `DB::getQueryLog()` で確認しました。

```
SQL      : select * from `posts` where `title` = ?
bindings : ["' OR '1'='1"]

SQL      : select * from `posts` where `posts`.`id` = ? limit 1
bindings : ["1' OR '1'='1"]

該当件数(title="OR 1=1"): 0
```

SQL 文には `?` のプレースホルダだけが入り、入力値は**バインディングとして分離**されています。
入力が SQL の構造を変えることはなく、該当件数も 0 件でした。

### 注意点として記録

`GET /posts/1' OR '1'='1` は 404 ではなく **200** を返します。

```
<h1>Laravel のルーティングを整理する</h1>
```

これは MySQL が `'1'' OR ...'` を数値比較の文脈で `1` に型変換するためで、
**id=1 の投稿 1件のみ**が返っています。全件が漏れているわけではなく、
インジェクションは成立していません。ただし想定外の入力で 200 が返るのは分かりにくいため、
今後ルートモデルバインディング（`Route::resource` の `{post}` を型付きで受ける）に
移行すれば、数値以外の ID は 404 になり挙動が明確になります。

---

## T-09　ブルートフォース対策

未登録のアドレスに対して連続でログインを試行しました。

```
  試行1 => HTTP 302
  試行2 => HTTP 302
  試行3 => HTTP 302
  試行4 => HTTP 302
  試行5 => HTTP 302
  試行6 => HTTP 302 | Too many login attempts. Please try again in 3 seconds.
  試行7 => HTTP 302
```

Breeze の `LoginRequest` が `RateLimiter` で「メールアドレス + IP」ごとに
5回まで制限しており、6回目でロックされました。

---

## T-10 / T-11 / T-12　保存とインフラまわり

```
usersのpassword列: $2y$12$4F51VKVLzxkpz...

.gitignore:3:.env	.env
追跡中の.envファイル数: 0

driver=database / lifetime=120分 / http_only=true / same_site=lax / encrypt=false
```

- パスワードは bcrypt（コスト12）でハッシュ化。`User` モデルの `casts` に `'password' => 'hashed'` を指定しているため、平文が DB に入ることはありません
- `.env` は `.gitignore` で除外済み、Git の追跡対象に含まれていません
- セッション Cookie は `HttpOnly` なので、万一 XSS が起きても JavaScript から読み出せません。`SameSite=Lax` により外部サイトからの POST にはそもそも Cookie が送られません

---

## 自動テストによる継続確認

上記のうち再現可能なものは `tests/Feature/PostAuthorizationTest.php` にテストとして残しました。
仕様変更で防御が壊れた場合に気づけます。

```bash
docker compose exec app php artisan test --filter=PostAuthorizationTest
```

```
  Tests:    13 passed (27 assertions)
```

---

## 残存リスクと今後の対応

現時点で修正が必要な脆弱性は見つかりませんでしたが、今後の課題として以下を挙げます。

| 項目 | 内容 | 対応時期 |
| --- | --- | --- |
| 認可ロジックの集約 | 現在はコントローラーに `authorizeOwner()` を private メソッドとして置いている。コントローラーが増えると重複するため、Policy に切り出す | Week 9 |
| ルートモデルバインディング | `findOrFail($id)` を型付きバインディングに変更し、数値以外の ID を 404 にする | Week 9 |
| HTTPS | 現在は開発環境のため HTTP。本番では `SESSION_SECURE_COOKIE=true` を設定し、Cookie を HTTPS 限定にする必要がある | 公開時 |
| メール認証 | `MustVerifyEmail` は未有効。登録直後から投稿できる状態 | 任意 |
| APP_DEBUG | 開発環境では `true`。本番では必ず `false`（エラー画面にスタックトレースやパスが出るため） | 公開時 |
| 投稿の文字数制限 | `content` に上限がなく、極端に長い投稿を保存できる | 任意 |

---

## 参考：テストの再現手順

```bash
# 環境を初期化（ユーザー2人と投稿16件が入る）
docker compose exec app php artisan migrate:fresh --seed

# 自動テスト
docker compose exec app php artisan test --filter=PostAuthorizationTest

# 手動確認（未ログイン）
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost/posts/create
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost/posts -d 'title=a&content=b&category=c'
```
