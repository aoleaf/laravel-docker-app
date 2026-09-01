# Week11 個人ノート — Laravel から S3 に画像をアップロードする

自己採点用の穴埋め問題と、その解説。README には「実際にやった手順」だけを書くので、
理屈・落とし穴・迷ったところはこっちに置いておく。

- 対象: `techmeets-month2` / 投稿機能（`/posts`）に画像1枚を添付できるようにする
- 構成: EC2(Ubuntu 24.04, Docker) + RDS(MySQL 8.0) + S3(`your-bucket-name`) / `ap-northeast-1`

---

## 0. 全体像

```
ブラウザ ──multipart/form-data──> Laravel ──PutObject──> S3
                                     │
                                     └─ DB(posts.image_path) に「パス」だけ保存

表示時: image_path ──Storage::url()──> https://bucket.s3.ap-northeast-1.amazonaws.com/posts/xxx.jpg
```

EC2 のローカルディスクに置かない理由は、**インスタンスを作り直したら消えるから**。
実際に Week11 で1回作り直しているので、そこにデータがあったら失われていた。

触る場所は5つ。

| # | ファイル | 役割 |
|---|---|---|
| 1 | マイグレーション | `posts.image_path` を追加 |
| 2 | `app/Models/Post.php` | `$fillable` と URL アクセサ |
| 3 | `app/Http/Requests/PostRequest.php` | バリデーション |
| 4 | `app/Services/PostService.php` | S3 への put / delete |
| 5 | `resources/views/posts/*` | 入力欄と表示 |

---

# 問題編（穴埋め）

あとで見返して自分でもう一度解く用。解答は次章。

### Q0. パッケージ

```bash
docker compose exec app composer require ____/____-aws-s3-v3 "^3.0"
```

- `config/filesystems.php` に `s3` の設定は**最初から書いてある**のに、これを入れないと動かないのはなぜ？

### Q1. AWS

公開用バケットポリシー:

```json
{
  "Effect": "Allow",
  "Principal": "____",
  "Action": "s3:____",
  "Resource": "arn:aws:s3:____バケット名/____"
}
```

IAM ポリシー（最小権限）:

```json
{ "Action": ["s3:____", "s3:____", "s3:____"], "Resource": "arn:aws:s3:::bucket/*" }
```

- `s3:ListBucket` を足す場合、`Resource` は上と同じで良い？
- IAM に `s3:PutObjectAcl` は必要？

### Q2. `.env`

```
FILESYSTEM_DISK=____
AWS_DEFAULT_REGION=____
```

- `.env` を直したのに反映されないとき、何を実行する？

### Q3. マイグレーション

```php
$table->____('image_path')->____()->after('category');
```

- カラム名は `image_url` と `image_path` のどちらにすべき？その理由は？

### Q4. モデル

- `image_path` を `$fillable` に入れるのは安全か？
- 入れなかった場合、`create()` に `image_path` を渡すとどうなる？

### Q5. バリデーション

```php
'image' => ['____', 'image', 'mimes:jpeg,png,webp', 'max:____'],
```

- `image` と `mimes` は役割が重複していないか？ 両方書く意味は？
- `max` の単位は？

### Q6. Service

```php
$data['image_path'] = $image->____('posts', 's3');
Storage::disk('s3')->____($oldPath);
```

- `store()` と `storePublicly()` のどちらを使う？
- ファイル名をランダムにする理由を3つ。
- `DB::transaction` の中でアップロードすると何が起きる？

### Q7. Controller

```php
$this->postService->createFor($request->user(), $request->validated(), $request->____('image'));
```

- `$request->validated()` の中身に何が混ざる？

### Q8. Blade

```blade
<form method="POST" action="..." ____="multipart/form-data">
<input type="____" name="____" accept="image/*">
```

- `enctype` はどのファイルに書く？ `_form.blade.php` だけで足りる？
- `edit` は `@method('PUT')` を使っているが、ファイルは届く？

---

# 解答・解説編

## A0. `league/flysystem-aws-s3-v3`

理由は**セキュリティではなく依存の重さ**。

このパッケージは `aws/aws-sdk-php` を引っ張ってきて、これが単体で 10MB 超。
S3 を使わない人にまで入れさせるわけにいかないので、Laravel は Flysystem の
**アダプタパターン**を採用している。

- コア (`league/flysystem`) = 「put / get する」という抽象だけ
- S3 / FTP / SFTP / GCS の実装 = 別パッケージ、使う人だけ入れる

だから `config/filesystems.php` に設定だけ存在して実装が無い、という状態が起きる。
入れずに使うと `Driver [s3] is not supported.`

> 「最小権限」は誰が何をできるかの話なので別概念。ここで使うなら「最小依存」。

## A1. AWS

### バケットポリシー

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

**`arn:aws:s3:::` はコロン3つ。** ARN の書式は

```
arn : パーティション : サービス : リージョン : アカウントID : リソース
arn :     aws       :   s3     :   （空）   :   （空）     : bucket/*
```

S3 のバケット名は全世界で一意なので、リージョンとアカウント ID が要らない。
だから空欄になってコロンだけ残る。1つ減らすとコンソールで構文エラー。

`Resource` は `bucket` ではなく `bucket/*`（中のオブジェクト全部）。

前提として、バケットの「ブロックパブリックアクセス」のうち
**バケットポリシー経由の公開をブロックする2項目**を解除しておく必要がある。

### IAM ポリシー

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

**ListBucket だけ `/*` が付かない。** Action の粒度と ARN の粒度を合わせる必要がある。

- `PutObject` / `GetObject` / `DeleteObject` … オブジェクトへの操作 → `bucket/*`
- `ListBucket` … バケットへの操作（中身を一覧する） → `bucket`

なお今回 `ListBucket` は**必須ではない**。`store()` / `delete()` / `url()` はどれも
一覧を必要としないので。`Storage::files()` や `directoryExists()` を使うなら要る。

さらに詰めると、**公開バケット方式なら `GetObject` もアプリには不要**。
画像を読むのはブラウザであってアプリではない。ただし署名付き URL 方式に切り替えたら必要になる。

`s3:PutObjectAcl` は**不要**。付けたくなったら A6 の罠にハマるサイン。

## A2. `.env`

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-northeast-1
AWS_BUCKET=your-bucket-name
```

反映されないときは `php artisan config:clear`。
本番で `config:cache` 済みなら `config:cache` を打ち直す（＋コンテナ再起動）。

### 流儀は2つあり、どちらかに寄せる

| | 書き方 | 利点 |
|---|---|---|
| A. 明示派 | `store('posts', 's3')` / `Storage::disk('s3')->url()` | 読めば S3 と分かる |
| B. 環境切替派 | `store('posts')` / `Storage::url()` + `.env` で切替 | ローカルは AWS キー無しで動く |

実務は B が主流（開発のたびに実 S3 にゴミが溜まらない）。
**今回は課題文が `Storage::disk('s3')` と指定しているので A を採用**した。
切り替えたくなったら `Post::IMAGE_DISK` を消して `Storage::url()` にすれば良い。

> ハマった実例: `AWS_DEFAULT_REGION` が `us-east-1` のままで AccessDenied。
> `config/filesystems.php` は `env()` を読むだけなので、`.env` に書き忘れると
> `.env.example` のフォールバック値がそのまま効く。

## A3. マイグレーション

```php
$table->string('image_path')->nullable()->after('category');
```

**`image_url` ではなく `image_path` を選ぶ。**

- URL を DB に入れると、バケット名やリージョンを変えた瞬間に既存レコードが全部死ぬ
- 署名付き URL 方式（有効期限付き）に切り替えたくなったとき、URL が入っていると詰む
- URL は path からいつでも作れるが、逆は面倒

**DB には復元しにくい方（＝ path）を持つ**、が原則。

`nullable` は画像なしの投稿を許すため。既存レコードがあるのでそもそも必須にできない。
`after()` は MySQL 専用構文（SQLite では無視されるだけ）。

## A4. `$fillable`

ここで守っているのは **Mass Assignment（一括代入）**。コマンドインジェクションではない。

```php
$user->posts()->create($data);   // $data の中身が丸ごとカラムに入る
```

`image_path` を任意に書き換えられると何が起きるか:

- 軽い方 … 他人の画像を自分の投稿に表示させる（表示が変なだけ）
- **重い方 … 更新処理の `Storage::delete($post->image_path)` が効く。
  バケット内の任意のオブジェクトを削除できてしまう**

「表示用の文字列」が「削除対象の指定」に再利用された瞬間、深刻度が跳ね上がる。

ただし**今回は安全**。理由は `$fillable` ではなく `PostRequest::validated()`。
`validated()` はルールに書いたキーしか返さないので、`image_path` は Service に届く前に消える。

防御は二重:

1. `validated()` … 入力のホワイトリスト ← **本丸**
2. `$fillable` … 代入のホワイトリスト ← 保険

なので `image_path` は `$fillable` に**入れてよい**。そこに入るのは
`$image->store()` の戻り値だけで、ユーザー入力ではない。

> ⚠️ 入れないと Eloquent は**エラーを出さず黙って捨てる**。
> 「S3 には上がっているのに DB が空」という原因の分かりにくいバグになる。
> `Model::preventSilentlyDiscardingAttributes()` を開発時だけ有効にすると例外を投げてくれる。

## A5. バリデーション

```php
'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048',
            'dimensions:max_width=4000,max_height=4000'],
```

- `nullable` … 編集時に再アップロードを強制しないため（`required` にしない）
- `max` の単位は **KB**。2048 = 2MB
- `dimensions` … 巨大画像で GD がメモリを食い尽くすのを防ぐ

**`mimes` は拡張子を見ていない。** ファイルの中身から MIME を推測し、それが指定拡張子に
対応する MIME かを照合する（拡張子そのものを見るのは `extensions:`、MIME 直書きは `mimetypes:`）。

では `image` との使い分けは何か。

**`image` は SVG を許可する。**

SVG は XML なので中に `<script>` を書ける。`<img src>` 経由なら実行されないが、
S3 の URL に直接アクセスされると JS が動く。つまり `mimes` は重複ではなく
**`image` の穴を塞ぐ絞り込み**。

PHP 側の上限（`upload_max_filesize` / `post_max_size`）を超えると、
バリデーションに到達する前に空リクエストになる点にも注意。

## A6. Service

```php
$data['image_path'] = $image->store('posts', 's3');   // ランダム名で保存し、パスを返す
Storage::disk('s3')->delete($oldPath);
```

| メソッド | 挙動 |
|---|---|
| `store('posts', 's3')` | ランダム名で保存 ← **これ** |
| `storeAs('posts', 'name.jpg', 's3')` | 名前を自分で指定 |
| `storePublicly('posts', 's3')` | ランダム名 + ACL を public に |

> ⚠️ **`storePublicly()` を使わない。**
> 現在の S3 はデフォルトで ACL 無効（Bucket owner enforced）なので
> `AccessControlListNotSupported` で落ちる。公開はバケットポリシーでやっているので ACL は不要。
> 名前的にこれを選びたくなるのが罠。

ランダム名にする理由:

1. **衝突回避** — 全員が `IMG_0001.jpg` を上げても上書きされない
2. **文字化け回避** — 日本語や空白を含む名前は URL エンコードで事故る
3. **推測防止** — `posts/1.jpg`, `posts/2.jpg` なら総当たりで全画像を舐められる
4. **パストラバーサル遮断** — 元ファイル名を一切使わないので `../` の経路が消える

### トランザクションと外部ストレージ

`DB::transaction` の中で S3 に put すると、**ロールバックしてもファイルは残る**（孤児ファイル）。
S3 はトランザクションに参加しない。

今回の実装では

- 作成: put → `try { transaction } catch { S3 から消して再スロー }`
- 更新: 新ファイルを put → transaction → **確定後に**旧ファイルを削除
- 削除: DB 削除 → S3 削除

「削除は必ず DB 確定後」にするのがポイント。逆にすると、DB 失敗時に画像だけ消える。

## A7. Controller

```php
$request->file('image')
```

**`$request->validated()` には `UploadedFile` オブジェクトが混ざる。**

```php
['title' => '...', 'content' => '...', 'category' => '...', 'image' => UploadedFile {#1234}]
```

`image` というカラムは無いので、そのまま `create()` に渡すと
`$fillable` 次第で黙って捨てられるか SQL エラーになる。

対処は `$request->safe()->except('image')` か、Service 側で `unset($data['image'])`。
今回は Service 側で落としている（呼び出し側が2箇所あるので一箇所にまとめた）。

> 一度 `dd($request->validated())` で自分の目で見ておくと、以後この手のバグを即座に見抜ける。

## A8. Blade

### enctype

```blade
<form method="POST" action="..." enctype="multipart/form-data">
```

HTML フォームのデフォルトは `application/x-www-form-urlencoded` で、
`title=hello&content=world` という**テキストの連結**しか表現できない。バイナリを送る手段が無い。

`multipart/form-data` は本文を境界線で区切り、各パートにファイル名と Content-Type を持たせる別形式。
**指定しない限りブラウザはファイルの中身を送らない**（ファイル名だけが文字列で飛ぶ）。

**`create.blade.php` と `edit.blade.php` の両方に必要。**
`_form.blade.php` には `<form>` タグが無いので、ここだけ共通化が効かない。

### `@method('PUT')` でファイルは届くか → 届く

`@method('PUT')` が生成するのは

```html
<input type="hidden" name="_method" value="PUT">
```

という隠しフィールドだけで、**実際の HTTP メソッドは POST のまま**。
Laravel のミドルウェアが `_method` を読んでルーティング時に PUT として扱っている。

これが重要なのは、**PHP は本物の PUT の multipart 本文をパースしない**（`$_FILES` が空になる）から。
メソッドスプーフィングという回避策が、たまたまこの問題も回避している。

### 入力欄

```blade
<input type="file" class="form-control" id="image" name="image" accept="image/*">
```

`name` は `$request->file('image')` の文字列と一致していること。
`accept` はダイアログを絞るだけの UX 用で、開発者ツールで消せるのでバリデーションの代わりにはならない。

`old()` はファイル入力に効かない。バリデーションエラーで戻ると選択が消えるが、
JS がローカルファイルを勝手に `<input type=file>` に入れられないセキュリティ制約なので諦める。

### 表示

Blade に `Storage::disk('s3')->url()` を直書きせず、**モデルにアクセサを生やす**。

```php
protected function imageUrl(): Attribute
{
    return Attribute::get(fn () => $this->image_path
        ? Storage::disk(self::IMAGE_DISK)->url($this->image_path)
        : null);
}
```

メソッド名 `imageUrl` に対し、Blade からは `{{ $post->image_url }}`（スネークケースに変換）。

`url()` は URL 文字列を組み立てて返すだけで、**S3 への通信は発生しない**。だから速いし権限も要らない。

署名付き URL 方式に切り替えるとき、直書きしていると index / show / edit を全部直すことになる。
アクセサならこの1メソッドだけ。A3 で `image_path` を選んだ判断がここで回収される。

---

# 落とし穴一覧（答え合わせ用）

| 症状 | 原因 |
|---|---|
| `Driver [s3] is not supported.` | `league/flysystem-aws-s3-v3` 未インストール |
| `$request->file('image')` が null | `<form>` に `enctype` が無い |
| `AccessControlListNotSupported` | `storePublicly()` / visibility に `public` を渡した（ACL 無効バケット） |
| URL は返るが 403 | バケットポリシー未設定 or ブロックパブリックアクセス未解除 |
| `.env` を直したのに変わらない | `config:clear` 忘れ |
| `SignatureDoesNotMatch` | シークレットキー取り違え or リージョン不一致 |
| AccessDenied（tinker で put 失敗） | `AWS_DEFAULT_REGION` が `us-east-1` のまま |
| ARN がコンソールで弾かれる | `arn:aws:s3::` とコロンが1つ足りない |
| DB に image_path が入らない | `$fillable` に追加していない（**エラーは出ない**） |
| 大きい画像で何も起きない | php.ini の `post_max_size` |
| IDE が `url()` を Undefined method と言う | `Storage::disk()` の戻り型 `Filesystem` 契約に `url()` が無い（実体は `FilesystemAdapter`）。実行時は問題なし |

# Week11 でハマった環境まわり（S3 以外）

| 事象 | 原因 |
|---|---|
| Docker が入らない | AMI が 26.04 で、Docker 公式リポジトリに `resolute` が無い |
| t2.micro が選べない | 2025年7月の無料プラン改定で対象外（t3/t4g 系へ） |
| IAM ダッシュボードで AccessDenied | ポリシーはグループに付いたが、グループが空だった |
| `vendor/autoload.php` not found | `.gitignore` 対象 + volumes マウントでイメージ内 vendor が隠れる |
| 500 エラー・ログも出ない | `storage/` が `ubuntu` 所有で `www-data` が書けない |
| `db:seed` で `fake()` undefined | `--no-dev` で faker が除外された |
| ローカルの `composer require` が unzip でタイムアウト | `aws/aws-sdk-php` は1.5万ファイル超。Windowsのバインドマウント越しの展開が composer の既定300秒を超える。`COMPOSER_PROCESS_TIMEOUT=0` を付ける。EC2(Linuxネイティブ)では起きない — A0「依存の重さ」の実例 |

---

# 動作確認の順番

一気に通さず切り分ける。

```bash
# 1. S3 に届くか（アプリ無関係）
php artisan tinker
>>> Storage::disk('s3')->put('test.txt', 'hello');
>>> Storage::disk('s3')->url('test.txt');
```

ここが通らなければ原因は **パッケージ / キー / 権限 / リージョン** のどれかで、Laravel のコードは無罪。

```
2. 1 の URL をブラウザで開く → 403 ならバケットポリシー側
3. フォームから投稿 → DB に image_path が入るか
4. 一覧・詳細で表示
5. 編集で差し替え → S3 から旧ファイルが消えているか
6. 投稿削除 → S3 からも消えているか
```

---

# README に書く論点（練習課題2の材料）

1. `image_url` ではなく `image_path` を DB に持った理由
2. S3 アップロードを Controller ではなく Service に置いた理由
3. トランザクションと外部ストレージの整合性（孤児ファイル対策）
4. 公開バケット vs 署名付き URL の使い分け
5. **本番 EC2 ではアクセスキーではなく IAM ロールを使うべき**理由
   （キーが漏れない / ローテーション不要 / `.env` に秘密を置かない）
6. SG 設計: EC2 は 80/443 を全開 + 22 は自宅 IP のみ / RDS は EC2 の SG からの 3306 のみ
