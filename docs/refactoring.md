# リファクタリング before / after

Week 8 までの `PostController`（Fat Controller）を、Repository / Service / Policy パターンに
分解した記録です。リファクタ前のコードは
**[docs/refactor/PostController.before.php](refactor/PostController.before.php)** に保存しています。

---

## 1. 何をしたか

```
【before】
PostController ──→ Post（Eloquent）
    ↑
  バリデーション・クエリ・認可・画面遷移が全部ここ

【after】
PostController ──→ PostService ──→ PostRepository ──→ Post（Eloquent）
    │
    ├──→ PostRequest（バリデーション）
    └──→ PostPolicy（認可）
```

依存の矢印は右にしか向きません。判定基準は1つで、
**Repository の中に `auth()` や `request()` が出てきたら設計を間違えている**、と考えます。

| 層 | 責務 | 禁止 |
| --- | --- | --- |
| Controller | リクエストを受け取り Service を呼び、view / redirect を返す | 条件分岐・クエリ・業務ルール |
| Service | 業務ルール、トランザクション境界 | Eloquent クエリ、`view()`、`redirect()` |
| Repository | Eloquent のクエリのみ | `auth()`、`request()`、`redirect()` |
| Policy | 「この人はこの操作をしてよいか」だけ | データの取得・更新 |

---

## 2. 数値で見る変化

| 指標 | before | after |
| --- | --- | --- |
| PostController の行数 | 87行 | 74行 |
| 1メソッドの最大行数 | 15行（`update`） | 10行（`update`） |
| Controller 内の Eloquent 呼び出し | 8箇所 | **0箇所** |
| バリデーション定義の重複 | 2箇所（`store` / `update`） | **1箇所**（`PostRequest`） |
| 認可ロジックの定義箇所 | 5箇所（Controller 3 + Blade 2） | **1箇所**（`PostPolicy`） |
| ファイル数 | 1 | 5 |
| 総行数（関連ファイル計） | 87行 | 215行 |

**総行数は 2.5 倍に増えています。** 行数削減はこのリファクタの目的ではありません。
目的は次章の「変更理由の分離」です。

---

## 3. コード比較：`update`

### before

```php
public function update(Request $request, $id)
{
    $validated = $request->validate([
        'title' => 'required|max:200',
        'content' => 'required',
        'category' => 'required|max:50',
    ]);
    $post = Post::findOrFail($id);
    $this->authorizeOwner($post);
    $post->update($validated);

    return redirect()
        ->route('posts.show', $post)
        ->with('success', '投稿を更新しました');
}
```

1つのメソッドが「入力検証」「データ取得」「認可」「更新」「画面遷移」の
**5つの責務**を持っていました。つまり**変更理由が5種類**あります。

### after

```php
public function update(PostRequest $request, Post $post)
{
    $this->authorize('update', $post);

    $this->postService->update($post, $request->validated());

    return redirect()
        ->route('posts.show', $post)
        ->with('success', '投稿を更新しました');
}
```

- 検証 → `PostRequest`（型宣言するだけで実行される）
- 取得 → Route Model Binding（`$id` をやめて `Post $post` にしたので `findOrFail` が不要）
- 認可 → `PostPolicy`
- 更新 → `PostService` → `PostRepository`

Controller に残ったのは「認可を確認して、Service を呼んで、リダイレクトする」だけです。

---

## 4. 一番の成果：変更理由が分離された

「この仕様を変えたい」と言われたときに開くファイルの数が、リファクタの本当の指標です。

| 変更したいこと | before で触るファイル | after で触るファイル |
| --- | --- | --- |
| 一覧の表示件数を 10 → 20 に | PostController | PostRepository |
| タイトル上限を 200 → 250 文字に | PostController（**2箇所**、片方の直し忘れが起きる） | PostRequest（1箇所） |
| 「管理者は他人の投稿も編集可」 | PostController（3箇所）＋ Blade 2枚 | **PostPolicy のみ** |
| 投稿時にメール通知を送る | PostController | PostService |
| 一覧を「いいね数順」に | PostController | PostRepository |

特に3行目です。before は編集画面の403判定・更新の403判定・削除の403判定・
一覧の編集リンク表示・詳細の編集ボタン表示の**5箇所が独立して同じルールを持っていた**ため、
1箇所直し忘れると「ボタンは出るのに押すと403」のような不整合が起きる状態でした。

```php
// before ── Controller と Blade に同じルールが散っていた
// PostController.php
private function authorizeOwner(Post $post): void
{
    if (! $post->isOwnedBy(auth()->user())) {
        abort(403, 'この操作は許可されていません');
    }
}

// posts/index.blade.php, posts/show.blade.php
@if ($post->isOwnedBy(auth()->user()))
```

```php
// after ── ルールの定義は PostPolicy だけ
// PostPolicy.php
public function update(User $user, Post $post): bool
{
    return $post->isOwnedBy($user);
}

// PostController.php
$this->authorize('update', $post);

// posts/index.blade.php, posts/show.blade.php
@can('update', $post)
```

サーバー側チェックと画面の出し分けが**同じ Policy を見る**ようになりました。

---

## 5. テストしやすさ

`PostService` は `auth()` も `request()` も呼びません。作者は引数で受け取ります。

```php
public function createFor(User $user, array $data): Post
```

このため HTTP リクエストが無い文脈（Artisan コマンド、キュー、テスト）からも
そのまま呼べます。また `PostRepository` はコンストラクタで注入されているので、
テスト時には偽物の Repository に差し替えられます。

> テストコードの作成は Month 3 以降のため、今週は「差し替えられる構造にする」までを実施。
> 既存の `tests/Feature/PostAuthorizationTest.php`（13件）は
> リファクタ前後で**1行も変更せずに全て通過**しており、これがリファクタの安全網になりました。
>
> ```bash
> docker compose exec app php artisan test --filter=PostAuthorizationTest
> # Tests: 13 passed (27 assertions)
> ```

---

## 6. デメリット・トレードオフ

正直に書いておきます。

- **ファイル数が 1 → 5 に増えた。** 「投稿の更新処理を追う」のに4ファイル開くことになる
- **総行数が 2.5 倍。** 単純な CRUD だけを見れば、明らかに記述量は増えている
- **`PostService` の `update` / `delete` はほぼ素通し。** Repository を1行呼ぶだけで、
  現時点では単独の価値がない
- **小規模アプリなら過剰設計。** 画面数が5つ程度で仕様変更の予定も無いなら、
  Fat Controller のままのほうが速く読める

それでも Service 層を先に置いた理由は、**業務ルールが増えたときの受け皿を用意しておく**ためです。
実際 `createPost` はすでに「投稿作成 + ログ記録」の2処理を `DB::transaction()` で括っており、
ここに通知送信やタグ付けが増えても Controller は1行も変わりません。

---

## 7. ハマった点（Laravel 11 以降の仕様変更）

| 症状 | 原因 | 対処 |
| --- | --- | --- |
| `Call to undefined method PostController::authorize()` | Laravel 11 から基底 `Controller` が空になり、`AuthorizesRequests` トレイトが外れた | `app/Http/Controllers/Controller.php` に `use AuthorizesRequests;` を追加 |
| Policy が呼ばれない | — | `App\Models\Post` ↔ `App\Policies\PostPolicy` の命名なら**自動検出され、登録は不要**。名前を崩したときだけ `Gate::policy()` で登録 |
| `Undefined variable $post`（destroy） | 引数が `$id` のままだった | `Route::resource` のパラメータ名が `{post}` なので、引数名も `$post` にする。名前が一致しないと Route Model Binding が効かない |
| `user_id` が NULL で INSERT エラー | `$validated['user_id'] = auth()->id()` は `Post::$fillable` に `user_id` が無いため黙って捨てられる | `$user->posts()->create()` を使う。作者の偽装も同時に防げる |

---

## 8. リファクタで到達した設計 vs 最初からその設計

同じアプリ内に、**Fat Controller からリファクタした `Post`** と、
**最初から Repository / Service で組んだ `Task`** の2つが並んでいます。

| | Post（リファクタで到達） | Task（最初からこの形） |
| --- | --- | --- |
| Repository | 具象クラス（`PostRepository`） | **インターフェース**＋Eloquent 実装 |
| Service の中身 | `update` / `delete` はほぼ素通し | `complete()` / `reopen()` に業務ルールがある |
| 状態の表現 | — | Enum（`TaskStatus`） |
| 認可 | Policy | Policy（`view` も含む） |

差が出たのは **Service の厚み**です。Post は「既存の CRUD を層に分けた」だけなので
Service に書くことがありませんでした。一方 Task は設計段階で
「完了にする」を `update` とは別の操作として切り出せたため、Service に意味のあるコードが載りました。

```php
// TaskService ── status と completed_at を必ずセットで更新する
public function complete(Task $task): Task
{
    if ($task->isDone()) {
        return $task;                 // 二重実行しても壊れない
    }

    return DB::transaction(fn () => $this->tasks->update($task, [
        'status' => TaskStatus::Done->value,
        'completed_at' => now(),
    ]));
}

// 汎用の update には状態を触らせない
public function update(Task $task, array $data): Task
{
    unset($data['status'], $data['completed_at']);

    return $this->tasks->update($task, $data);
}
```

これを Fat Controller で書くと `update()` の中に `if ($request->status === 'done')` が生え、
やがて「完了なのに `completed_at` が NULL」のデータが生まれます。

**得られた教訓**：Repository / Service は「あとから分ける」こともできるが、
**業務操作に名前を付ける（`complete`）のは設計段階のほうが圧倒的にやりやすい**。
リファクタで層を分けても、`update` という名前のまま残った操作の意味は自動では回復しません。

---

## 9. 作成・変更したファイル

| ファイル | 内容 |
| --- | --- |
| `app/Repositories/PostRepository.php` | 新規。Eloquent クエリを集約 |
| `app/Services/PostService.php` | 新規。業務ルールとトランザクション境界 |
| `app/Policies/PostPolicy.php` | 新規。`update` / `delete` の認可 |
| `app/Http/Requests/PostRequest.php` | 新規。`store` / `update` 共通のバリデーション |
| `app/Http/Controllers/PostController.php` | 全面書き換え。`authorizeOwner()` を削除 |
| `app/Http/Controllers/Controller.php` | `AuthorizesRequests` トレイトを追加 |
| `resources/views/posts/index.blade.php` | `@if ($post->isOwnedBy(...))` → `@can('update', $post)` |
| `resources/views/posts/show.blade.php` | 同上 |
| `docs/refactor/PostController.before.php` | リファクタ前のコードを保存 |
