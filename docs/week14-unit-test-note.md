# Week14 個人ノート — ユニットテストとモック（Mockery）

Step 2（`PriceCalculator`）は「入力を入れたら正しい値が返るか」だけ見ていた。
Step 3（`TaskService`）は依存先を持つクラスなので、**偽物を注入して呼ばれ方そのものを検証する**方に変わる。
その差分と、書きながらハマったところを残しておく。

- 対象: `app/Services/TaskService.php`
- テスト: `tests/Unit/TaskServiceTest.php`（5件）
- 実行: `docker compose exec app php artisan test --filter=TaskService`

---

## 0. なぜモックが必要になるのか

`TaskService` はコンストラクタで `TaskRepositoryInterface` を必須で受け取る。

```php
public function __construct(private TaskRepositoryInterface $tasks) {}
```

なので `new TaskService()` は `ArgumentCountError`。
本物のリポジトリを渡すとDBが必要になり、テストが遅く・壊れやすくなる。

そこで「インターフェースだけ満たした偽物」を渡す。

```
本番:  TaskService ──> TaskRepository（Eloquent）──> MySQL
テスト: TaskService ──> Mockery の偽物（DBに行かない）
```

インターフェースを受け取る設計にしてあったから差し替えられる。
**DIは設計の綺麗さの話ではなく、テスト可能性そのもの**だった。

---

## 1. Mockery の基本構造

```php
$this->repo = Mockery::mock(TaskRepositoryInterface::class);
$this->service = new TaskService($this->repo);
```

日本語で読むとこうなる。

```
この偽リポジトリは
  createFor というメソッドを呼ばれるはずだ        … shouldReceive('createFor')
  回数はちょうど1回                              … once()
  そのときの引数は 第1引数=$user, 第2引数=(条件)   … with(..., ...)
  条件を満たしたら $created を返せ                … andReturn($created)
```

重要なのは `shouldReceive` の行では**まだ何も実行されていない**こと。
「この後こう呼ばれるはずだから、そのときこう振る舞え」という予告で、
照合が起きるのは実際に Service を動かした瞬間。

`->` が縦に連なるのは、各メソッドが自分自身を返すから（メソッドチェーン）。

### setUp の役割

各テストメソッドの直前に毎回呼ばれる。ここでは「箱」だけ作る。
`shouldReceive`（何を返すか・何回呼ばれるか）はテストごとに違うので**各テストの中に書く**。

---

## 2. `with()` の引数は、検証したい呼び出しの引数の並びそのまま

ここが一番混乱した。

```
Service の中で実際に起きる呼び出し
    $this->tasks->createFor( $user , ['title'=>…, 'status'=>'todo', 'completed_at'=>null] )
                             ↑第1引数    ↑第2引数
                             │          │
テストで書く期待
    ->with(                  $user , Mockery::on(fn($attributes) => …) )
                             ↑同一インスタンスか  ↑この関数に第2引数を食わせる
```

オブジェクトを `with()` に渡すと Mockery は `===`（同一インスタンス）で判定する。
別の `new User()` だと落ちる。つまりこの1行が
「Service が user を加工せずリポジトリへ横流ししている」ことの検証にもなっている。

### `Mockery::on()` のクロージャ引数は誰が渡すのか

```php
Mockery::on(function (array $attributes) {
    return $attributes['status'] === 'todo';
})
```

渡すのは自分ではなく **Mockery**。

1. Service がリポジトリのメソッドを実行
2. Mockery が「第2引数の期待は関数だな」と見て、受け取った実物の配列を突っ込む
3. `true` → 期待通りの呼ばれ方。`false` → `NoMatchingExpectation` で即FAIL

`sorted(items, key=lambda x: x.age)` の lambda を自分では呼ばないのと同じ関係（コールバック）。
`assertSame` のような値の比較ではなく、**引数に対する条件式**を書けるのがポイント。

---

## 3. 書いた5件と、それぞれが証明していること

| # | テスト | 証明できた仕様 | 検証の型 |
|---|---|---|---|
| 1 | `summaryFor` | リポジトリが不完全な配列を返しても3ステータス分のキーが揃う | 戻り値 |
| 2 | `createFor` | 新規作成は必ず `todo` / `completed_at=null` から始まる | 渡した引数 |
| 3 | `update` | 編集操作は状態を変更できない（キーごと落ちる） | 渡した引数 |
| 4 | `complete` | 完了済みに再度完了をかけても UPDATE を投げない | 呼ばれないこと |
| 5 | `reopen` | 未完了に再開をかけても UPDATE を投げない | 呼ばれないこと |

2・3は業務ルールの強制、4・5は無駄な処理をしていないことの検証。

### テスト1: わざと不完全な入力を渡す

```php
->andReturn(['todo' => 2]);   // doing と done を欠かせる
```

完璧な配列を返させると `?? 0` の0埋めコードを通らず、テストの意味が薄くなる。
**通したい経路を通す入力を選ぶ**。

キー名は enum の `value` そのもの（`doing`、`in_progress` ではない）。
`assertSame` はキーの順序と型まで見るので、配列比較ではこちらを使う。

### テスト2と3の違い — 上書きか削除か

| | やっていること | リポジトリに渡る形 |
|---|---|---|
| `createFor()` | `todo` / `null` で**上書き** | キーは存在する（値が強制されている） |
| `update()` | `unset` で**キーごと削除** | キーが存在しない |

`update()` が削除なのは、状態変更の権利を持たないから。
状態変更は `complete()` / `reopen()` 専用にしてある。
上書きだと「update で勝手に todo に戻る」事故が起きるので、そもそも渡さないのが正解。

**罠**: 「キーが無いこと」の確認に `isset()` を使うとバグる。

```php
! isset($attributes['status'])                 // ❌ 値がnullでも false になる
! array_key_exists('status', $attributes)      // ✅ キーの有無だけを見る
```

あわせて `$attributes['title'] === '新タイトル'` も見ておく。
これが無いと「全部消す実装」でも通ってしまい、**消しすぎ**を捕まえられない。

### テスト4: DBなしで「完了済みのTask」を作る

`Task::factory()->create()` はDBに書くので使えない。`new Task()` してプロパティを直接セットする。

`isDone()` は `$this->status === TaskStatus::Done` と **enum インスタンスとの `===`**。
`Task` の `$casts` に `'status' => TaskStatus::class` があるおかげで、
代入時に保存値へ・取得時に enum へ自動変換される。だからどちらの書き方でも `isDone()` は true。

```php
$task = new Task();
$task->status = TaskStatus::Done;   // enum直接（意図が明快でこちらを採用）
// $task->status = 'done';          // 文字列でも casts が変換する
```

`save()` を呼ばない限りDBには何も起きない。

### `shouldNotReceive` を書く意味

`Mockery::mock()` で作った厳格なモックは、`shouldReceive` していないメソッドを
呼ばれた時点で例外を投げる。つまり書かなくても落ちる。

それでも書くのは **「このテストは update が呼ばれないことを確かめている」とコードで宣言するため**。
テストコードは仕様書でもあるので意図を明示する価値がある。

---

## 4. 継承するのは `PHPUnit\Framework\TestCase` か `Tests\TestCase` か

5件すべて `PHPUnit\Framework\TestCase` で書き切れた。
Eloquentモデル（`new Task()`）も `now()` も、アプリのコンテナ起動を必要としなかった。

ただしテスト4・5が通るのは **`DB::transaction` に到達しない早期リターンの経路だけ**。

```php
public function complete(Task $task): Task
{
    if ($task->isDone()) {
        return $task;          // ← ここで返る経路しかテストしていない
    }
    return DB::transaction(fn () => $this->tasks->update($task, [...]));   // ← ここはDBファサード
}
```

「未完了のタスクを `complete()` したら update が正しい引数で呼ばれるか」という正常系を書くと
`DB::transaction` を通るので、そこで初めて `Tests\TestCase` が必要になる。

> **判断基準はテスト対象のクラスではなく、そのテストが通るコード経路。**

`Mockery::close()` の後始末も違う。`PHPUnit\Framework\TestCase` を継承する場合、
`once()` などの回数検証は `Mockery::close()` が呼ばれて初めて実行されるので、
`Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration` トレイトを use する
（`Tests\TestCase` には最初から入っている）。

---

## 5. IDE が `shouldReceive` を知らないと言ってくる

```
Undefined method 'shouldReceive'. intelephense(P1013)
```

実行時エラーではない。プロパティを `TaskRepositoryInterface` 型で宣言しているから、
「そのインターフェースに `shouldReceive()` は無い」と静的解析が言っている。
実体はインターフェース実装でもあり Mockery のモックでもあるオブジェクト。

PHP 8.2 なので交差型で「両方である」と宣言すれば消える。

```php
use Mockery\MockInterface;

private TaskRepositoryInterface&MockInterface $repo;
```

判断基準は `php artisan test` が通るかどうか。Intelephense の指摘は参考情報。

---

## 6. Python（unittest.mock）との対応

思想の違いを押さえておくと読み替えが早い。

- **Mockery**: 先に期待を宣言し、実行時に自動照合する（事前宣言型）
- **unittest.mock**: まず動かして、後から呼ばれ方を振り返る（事後検証型）

だから Mockery ではアサートより前に `shouldReceive` を書くことになり、順序が逆に見える。

| Mockery (PHP) | unittest.mock (Python) |
|---|---|
| `Mockery::mock(Iface::class)` | `Mock(spec=Iface)` |
| `->andReturn($x)` | `mock.method.return_value = x` |
| `->once()` | `assert_called_once()` |
| `->with($a, $b)` | `assert_called_once_with(a, b)` |
| `Mockery::on(fn)` | `call_args` を取り出して assert / `__eq__` 自作マッチャ |
| `Mockery::any()` | `unittest.mock.ANY` |
| `shouldNotReceive('delete')` | `mock.delete.assert_not_called()` |
| `assertSame($a, $b)` | `assert a is b` |
| `use App\Models\Task;` | `from app.models import Task` |
| `->` / `::` | `.` / クラスメソッド呼び出し |

継承先の選択も Django と同じ構図。`unittest.TestCase` で足りるか、
DBトランザクション付きの `django.test.TestCase` が必要か、という判断にそのまま対応する。

---

## 7. ハマったところ（時系列）

| 症状 | 原因 |
|---|---|
| `ArgumentCountError` | `new TaskService()` にリポジトリを渡していない |
| `Undefined method 'shouldReceive'` | IDEの静的解析。型宣言を交差型にすれば消える |
| 期待 `in_progress` / 実際 `doing` | enum の value は `doing`。PHPUnitの差分は `-` が期待値、`+` が実際の値（git diff と逆） |
| `Class "Tests\Unit\Task" not found` | `use App\Models\Task;` の import 漏れ。名前空間内を探しに行っていた |

---

## 8. 結果

```
$ docker compose exec app php artisan test --filter=TaskService
  PASS  Tests\Unit\TaskServiceTest
  ✓ summary for
  ✓ create for
  ✓ update
  ✓ complete
  ✓ reopen
  Tests: 5 passed (9 assertions)
```

全体スイートも 62 passed で影響なし。

---
---

# Week14 個人ノート その2 — Feature Test / カバレッジ / TDD

上はStep 3（ユニットテスト）まで。ここからStep 4以降の学び。

- Feature Test: `tests/Feature/PostCrudTest.php`（10件）、`tests/Feature/TaskCrudTest.php`（14件）
- TDD: `tests/Feature/PostLikeTest.php`（7件）といいね機能
- 静的解析・CI: `eslint.config.js`、`.github/workflows/`

---

## 9. Feature Test はユニットテストと何が違うか

ユニットテストは対象クラスだけを取り出して周りを偽物にする。Feature Testは本物のLaravelを起動して
HTTPリクエストを投げ、通り道を全部通す。

```
$this->get('/posts')      疑似HTTPリクエスト（ネットワークは使わない）
      │
   ルーティング            routes/web.php
      │
   ミドルウェア            auth
      │
   FormRequest            PostRequest のバリデーション
      │
   コントローラ            PostController
      │
   サービス / リポジトリ    PostService → PostRepository
      │
   DB                     assertDatabaseHas で覗く
      │
   Blade                  assertViewIs / assertSee
      │
   TestResponse           アサーションが生えたレスポンス
```

ユニットテストでは見つけられないバグ（ルート名のタイポ、ミドルウェアの付け忘れ、
バリデーションルールの抜け、Bladeの変数名違い）がここで捕まる。
代わりに遅く、落ちたときの切り分けが難しい。だから両方書く。

土台は3つ。

| もの | 役割 |
|---|---|
| `Tests\TestCase` を継承 | Laravelを起動する（ルーティング・DB・Bladeを使うので必須） |
| `use RefreshDatabase` | マイグレーション実行 + 各テストをトランザクションで包んでロールバック |
| `$this->get()` / `actingAs()` | 疑似HTTPリクエスト。`actingAs`がログインを代行 |

`phpunit.xml` の `DB_CONNECTION=testing`（SQLiteの`:memory:`）なので、
本番DBもローカルDBも汚れないし速い。

---

## 10. アサーションの使い分け

| 検証したいこと | 使うもの |
|---|---|
| HTTPステータス | `assertOk()` / `assertForbidden()` / `assertRedirect()` |
| どのビューが返ったか | `assertViewIs('posts.index')` |
| ビューに何が渡ったか | `assertViewHas('posts')` |
| 画面に文字が出るか | `assertSee($post->title)` |
| バリデーションエラー | `assertSessionHasErrors(['title'])` |
| エラーが無いこと | `assertSessionHasNoErrors()` |
| DBの状態 | `assertDatabaseHas` / `assertDatabaseMissing` / `assertDatabaseCount` |
| ファイル保存 | `Storage::disk(...)->assertExists($path)` |

`assertOk()` だけのテストは**中身を何も見ていない**。投稿が1件も表示されない空ページでも通る。
既存の`PostAuthorizationTest`がその状態だった。

`assertViewHas` と `assertSee` は役割が違う。

- `assertViewHas('posts')` … コントローラが変数を渡したか（サーバ側の責務）
- `assertSee($post->title)` … Bladeが実際に描画したか（テンプレート側の責務）

変数名をタイポすると前者が落ち、`@forelse`を書き間違えると後者が落ちる。

### 成功系テストの罠

バリデーション失敗時も**リダイレクトが返る**ので、`assertRedirect()`だけでは
成功と失敗を区別できない。成功系には必ず次のどちらかを入れる。

```php
->assertSessionHasNoErrors()
$this->assertDatabaseHas('posts', [...]);
```

`assertSessionHasNoErrors()`は将来誰かがルールを追加して意図せず弾かれるようになったとき、
原因が分かる形で落ちてくれる保険になる。

### 異常系テストの罠

「403が返った」と「実際に変更されていない」は別物。ポリシー判定より先に更新処理が
走っていたら、403を返しながらデータが書き換わる。だから必ずDBも見る。

```php
->assertForbidden();
$this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo']);
```

---

## 11. 境界値は対で撃つ

`max:200` の検証は 200（OK）と 201（NG）の**両方**が必要。
200だけだと `max:500` に書き換えられてもテストが通り続け、上限の位置を何も固定していない。

```
        200      201
         │        │
    ─────┴────────┴─────
        OK       NG      ← この2点で「境界はここ」と言える
```

Laravelの`max`は文字列に対して**文字数**で測る（`mb_strlen`）。だから`str_repeat('あ', 201)`で落ちる。
ASCIIでしかテストしないと、バイト基準の実装との違いに気づけない。
マイグレーションが`string('title', 200)`でMySQLのvarcharも文字数基準なので、両者は一致している。

**限界**: テストDBがSQLiteなのでカラム長の不足は検出できない（SQLiteは長さ制限を無視する）。
仮に`string('title', 50)`でもテストは通り、本番のMySQLで初めて落ちる。

---

## 12. 絞り込みのテストは「除外されるもの」を複数用意する

`TaskCrudTest`の絞り込み（`status` + `overdue`）は3件のタスクを作った。

```
'期限切れの着手中タスク'      status=doing, 期限=過去   → 表示される
'未来の着手中タスク'          status=doing, 期限=未来   → overdueで除外
'期限切れだが未着手のタスク'  status=todo,  期限=過去   → statusで除外
```

3件目が無いと「overdueは効いているがstatusは無視されている」実装でも通ってしまう。
2つのフィルタを同時に指定するなら、**それぞれが独立に効いていることを示す除外対象**を
それぞれ用意する。境界値で両側を撃つのと同じ考え方。

同じ理由で、いいね機能では「2回いいねしても1件」と「別人がいいねすると2件」を対にした。
`unique(['user_id','post_id'])`を`unique('post_id')`と書き間違えると、前者は通るのに
後者が落ちる（別人のいいねまで潰れる）。片方だけでは検出できない。

### flaky test を作らない

ファクトリのランダム値に検証を依存させると、実行日によって通ったり落ちたりする。

```php
// TaskFactory の due_date は -1week ~ +3weeks のランダム
'due_date' => now()->subDay(),    // 検証に関わる値は明示する
```

flaky testの害は「落ちること」ではなく**「落ちても誰も驚かなくなること」**。
「たまに落ちるやつだから再実行すれば通る」という空気ができた瞬間、テスト全体が信用を失う。

---

## 13. S3もファイルも偽物に差し替えられる

Step 3で「本物のリポジトリの代わりに偽物を注入した」のと同じ発想が、Laravelに組み込まれている。

```
Step 3:  本物のリポジトリ → Mockeryの偽物を注入（自分で書く）
Step 4:  本物のS3        → Storage::fake('s3')（1行）
```

```php
Storage::fake(Post::IMAGE_DISK);   // リクエストより前に呼ぶ
$image = UploadedFile::fake()->image('test.jpg', 800, 600);
```

`Storage::fake()`はDIコンテナのディスクをローカル一時ディレクトリを指すものに差し替える。
`PostService`のコードは1行も変えずに、S3に行くはずの処理がローカルに落ちる。

`UploadedFile::fake()->image()`はGDで**本物のJPEGを生成する**。
`create()`の空ファイルだと`image`/`mimes`/`dimensions`ルール（中身を解析する）で弾かれて、
検証したい保存経路に到達できない。

保存パスは`store()`がランダム生成するのでテストから予測できない。**DBから引く**。

```php
$post = Post::firstOrFail();
Storage::disk(Post::IMAGE_DISK)->assertExists($post->image_path);
```

「DBにパスはあるがファイルが無い」「ファイルはあるがDBが空」という壊れ方が実際に起きるので、
両者を突き合わせることに意味がある。

### GDのJPEG対応でハマった

```
LogicException: imagejpeg function is not defined and image cannot be generated.
```

スタックトレースが`vendor/`の`FileFactory`で止まっていた＝自分のコードに到達していない。
`docker-php-ext-install gd`だけではPNGのみ有効になる。configureで明示する必要がある。

```dockerfile
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install ... gd
```

なお本番のJPEGアップロードは壊れていない。`mimes`判定はfileinfo、`dimensions`は
`getimagesize()`（PHP本体）なのでGD不要。GDが要るのは**テストがダミー画像を生成する場面だけ**。

---

## 14. カバレッジの読み方

`pcov`を入れて計測（Xdebugでも測れるがデバッガ本体なので数倍遅い）。

```
55.2% → 72.4%（Task機能のFeature Test 14件を足した結果）
      → 72.7%（TDDで足したいいね機能。PostLikeController / Models\Like は100%）
```

### 除外は数値を上げるとは限らない

`phpunit.xml`の`<source><exclude>`で`app/Providers`を外す例が教材にあるが、
このプロジェクトでは**逆に下がる**。それらは既に100%カバーされているので、
分母と分子が同じだけ減る。除外が数値を上げるのは未カバーのファイルを外すときだけ。

### モックの代償が数値に出る

```
Services/TaskService ............... 63%   ← ユニットテストで上がった
Repositories/EloquentTaskRepository .. 0%   ← モックにしたので1行も通っていない
```

ユニットテストで偽物を注入した分、本物のリポジトリは実行されない。
Feature Test 1本で7ファイルが 0% → 100% になったのはこの逆で、通り道を全部通るから。
**カバレッジを上げる局面では統合テストの方が効率が良い。**

### クロージャの中身は条件が真にならないと通らない

```php
->when($filters['status'] ?? null, fn ($query, $status) => ...)
```

`GET /tasks`だけではクロージャが呼ばれず未カバーのまま。
`GET /tasks?status=doing&overdue=1`が必要。これは小細工ではなく、
絞り込み機能が動くことの検証そのもの。

### 見積もりは当たる

未カバー行数から「Task系96行を埋めれば 310+96 = 406/562 = 72.2%」と予測し、実測72.4%。
Feature Testは「そのファイルの全行が通る」という仮定が成立しやすいので精度が出る。

---

## 15. テストが増えると壊れるバグ

全件実行だけで落ちる現象に遭遇した。

```
--filter=TaskCrud だけ → 14件パス
全件                   → Allowed memory size of 134217728 bytes exhausted
                          at Faker/Provider/Text.php:149
```

原因は`TaskFactory`の`fake()->realText()`。マルコフ連鎖を構築するのでメモリを大量に使う。
Feature Testは**テストごとにアプリを作り直す**のでFaker Generatorも作り直され、
テスト数が増えるにつれて積み上がって上限に当たった。

```php
'title' => fake()->sentence(),      // 単語リストから組むだけで軽い
'description' => fake()->paragraph(),
```

`phpunit.xml`で`memory_limit`を上げて逃げる手もあるが、原因を残したまま上限を上げるだけなので
テストが増えればまた当たる。原因側を直した。

なお`php -d memory_limit=512M artisan test`は効かない。`artisan test`は
**子プロセスでphpunitを起動する**ので`-d`が伝わらない。

**`--filter`だけで確認して満足せず、定期的に全件を流す。**

---

## 16. ESLint（Flat Config）

`resources/js`は素のJS + Alpine.jsの2ファイルだけなのでreact系プラグインは入れない。

```
eslint / @eslint/js / globals
```

`globals`が必要な理由: `bootstrap.js`が`window.axios = axios`と書いている。
ESLintから見ると`window`は未宣言の識別子なので`no-undef`で落ちる。
**ESLintは実行環境を自動判別しない**（ブラウザかNodeかWorkerかで組み込み変数が違う）。

```js
languageOptions: { globals: globals.browser }   // 旧 .eslintrc の env: { browser: true }
```

### ツールは一度落として確認する

```
const unused = 1;
if (1 == "1") { console.log("check"); }
```

を入れて、3ルールが反応し**終了コードが1になる**ことを確認した。
ここが0のままだと「CIは動いているのに何も止めない」最悪の状態になる。

これはTDDのRedと同じ発想。**通ることの確認より、落ちることの確認の方が情報量が多い。**

### npmはホストで実行する

`docker compose exec app npm install`は失敗する。appコンテナは`php:8.2-fpm`ベースで
Nodeが入っておらず、`docker-compose.yml`にもNodeのサービスが無い。
このプロジェクトはフロントのビルドをホスト側でやる構成。

---

## 17. 依存スキャンの判断手順

`npm audit`は0件。`composer audit`は`league/commonmark` 2.9.0にhigh 4件。

`npm audit fix`を即打たず、この順で判断する。

```
① 本番依存か？        composer why で確認 → laravel/framework経由の require
② 制約内で直るか？    影響範囲 <2.10.0、laravel は ^2.8.1 を要求 → 2.10.1 が範囲内
                      → 即更新（今回）。メジャーが動かないので破壊的変更のリスクなし
③ 制約内で直らない    実際に踏む経路があるか調べて、無ければ --ignore で記録を残して許容
```

`--force`が要るのは②がNoのとき。viteやtailwindのメジャーが上がってビルドが壊れ得る。

### `composer install --no-dev` にしなかった理由

`--no-dev`だとphpunitやmockeryの脆弱性が検出されない。開発環境も攻撃対象になり得る
（悪意あるパッケージ → 開発者マシンで任意コード実行 → `.env`の本番キーが流出、
CI上で実行 → GitHub Secretsが流出）。本番に配置されないことは安全を意味しない。

修正できないdev依存で赤くなったら`composer audit --ignore=<ID>`で個別に無視する。
**`--ignore`は認識して判断した記録が残るが、`--no-dev`はそもそも見ていない。**

なお`composer audit`には`--audit-level`相当のオプションが無く、1件でも非0で終わる
（あるのは`--abandoned`だけ）。`npm audit`は既定でlow以上を拾うので`--audit-level=high`を付けた。
修正不能なlow 1件でCIが永久に赤いと「赤は無視していい」空気が生まれ、
本当に危険なhighを見逃す。

### セキュリティは週次スケジュールが本体

```yaml
schedule:
  - cron: '0 0 * * 1'   # UTC月曜0時 = JST月曜9時
```

lintと違い、監査結果は自分がコードを書かなくても変わる。
今回の4件もアドバイザリ公開日が`2026-09-01`で、こちらは何も変えていないのに脆弱になった。
push時だけでは、開発が止まっている期間にまったく気づけない。

GitHub Actionsのcronは常にUTC。日本時間で考えるときは9時間ずらす。

---

## 18. TDD（いいね機能）

### Redの質を見分ける

「失敗すればRed」ではない。**期待どおりの理由で失敗しているか**を見る。

| 失敗メッセージ | 判定 |
|---|---|
| `Route [posts.like] not defined` | ✅ 実装が無い |
| `Class "Tests\Feature\Post" not found` | ❌ import漏れ＝テスト自身のバグ |
| `Entries found: 2.` | ✅ 重複が防がれていない |
| `Attempt to read property "id" on null` | ✅ ミドルウェアが無くコントローラまで届いた |

Redのメッセージは**次に何を作るかの指示**になっている。

### 6サイクルの記録

| # | Red | 実装 |
|---|---|---|
| 1 | `Route [posts.like] not defined` | マイグレーション・Likeモデル・ルート・コントローラ |
| 2 | `Entries found: 2.` | `firstOrCreate` + UNIQUE制約 |
| 3 | `Attempt to read property "id" on null` | `auth`ミドルウェア |
| 4 | `Route [posts.unlike] not defined` | `destroy()` + DELETEルート |
| 5 | **Redにならなかった** | 実装なし |
| 6 | `いいね 2` が画面に無い | `withCount('likes')` + ビュー |

各Greenで1コミットにしたので、`git log`がそのままTDDのリズムの記録になる。
レビュアーは「このテストのためにこの実装が入った」と辿れる。

### 「最小限」を守る意味

サイクル1では`unique`制約を入れたくなるが我慢する。理由は2つ。

1. その制約が正しいことを証明するテストが無い状態になる
2. **制約が本当に効いているかを確認する機会を失う**

サイクル2で「2回で1件」のテストを書くとまずRedになる（重複が防がれていない証明）。
そこで制約を入れてGreenになれば、その制約が実際に重複を防いだと証明できる。
先に入れるとテストは最初から通り、「制約のおかげか、たまたまか」が区別できない。

### 重複防止は2層でやる

```php
$post->likes()->firstOrCreate([...]);      // アプリ側：エラーにせず弾く
$table->unique(['user_id', 'post_id']);    // DB側：最後の砦
```

`firstOrCreate`はSELECT→INSERTの2段階なので、同時リクエストで両方が「無い」と判断して
両方INSERTする競合が起こり得る（連打・ダブルサブミットで踏む）。UNIQUEがあれば2件目は
DBレベルで失敗する。逆にUNIQUEだけだと2回目で例外→500になり、仕様（エラーにしない）に反する。

### Redが出なかったテストの扱い

サイクル5の2件は書いた瞬間Greenだった。`where('user_id')->delete()`が0件でも例外を投げず、
`user_id`で絞っているので他人の行は対象外——サイクル4の実装が既に満たしていた。

捨てずに**回帰テストとして残した**。誰かが`$post->likes()->delete()`と書き換えたら
即座に落ちて、他人のいいねを全部消すバグを止めてくれる。
コミットメッセージを`feat:`ではなく`test:`にして、実装を足していないことを履歴に明示した。

**Redが出ないことは失敗ではなく「その仕様は既に満たされている」という発見。**
ただしテストを残す理由（回帰防止）を自分に説明できる必要がある。

### リファクタの定義と判定

**外から見た振る舞いを変えずに内部構造を改善する**こと。判定は単純。

```
テストを1行も変えずにGreenのまま → リファクタ
テストを直さないと通らない       → 仕様変更
```

`withCount('likes')`はN+1を避けている。ビューのループ内で`$post->likes()->count()`を
呼ぶと投稿10件で11クエリになる。`withCount`は一覧取得のクエリにサブクエリとして含めるので
投稿が何件でもクエリ数は変わらない。

`PostService`への切り出しは**しなかった**。コントローラは実質2行で業務ルールが無く、
移すと`PostService`が「投稿CRUD + S3画像 + いいね」を抱えて責務が薄く広がる。
**層を増やすこと自体は改善ではない。** 通知やキャッシュが必要になった時点で切り出す。

いずれにせよ、7件のテストは全てHTTP経由の検証なので内部構造をどう変えても変更不要。
**リファクタできる自由は、テストがあって初めて手に入る。**

---

## 19. Step 4以降でハマったところ

| 症状 | 原因 |
|---|---|
| `Class "Tests\Feature\Post" not found` | import漏れ。Step 3で踏んだのと同型 |
| `imagejpeg function is not defined` | GDがPNGのみでビルドされていた。configureが必要 |
| `npm: executable file not found` | appコンテナにNodeが無い。npmはホストで実行する |
| `Allowed memory size exhausted` | `fake()->realText()`。全件実行でのみ発生 |
| `php -d memory_limit=512M artisan test` が効かない | `artisan test`は子プロセスでphpunitを起動する |
| `docker compose exec`が突然失敗する | Docker Desktopが落ちている。起動してから実行 |
