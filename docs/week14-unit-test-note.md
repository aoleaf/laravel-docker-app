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
