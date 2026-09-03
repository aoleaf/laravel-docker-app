# Week12 個人ノート — Stripe決済 / SendGridメール / Webhook

README には「動かすための手順」だけを書くので、
理屈・教材との差分・自分でハマったところはこっちに置いておく。

- 対象: 商品詳細（`/products/{id}`）から Stripe Checkout で1点購入する
- SDK: `stripe/stripe-php` v21.3.1（教材執筆時より新しい）

---

## 0. 全体像

```
ブラウザ ──POST /products/{id}/checkout──> Laravel
                                            │  ① Checkout Session を作る（APIキーを使うのはここ）
                                            ▼
                                          Stripe
                                            │  ② session.url へリダイレクト
ブラウザ ──カード番号を入力───────────────> Stripe の決済ページ
                                            │
             ③ GET /checkout/success ◀──────┤ ブラウザを戻す（ユーザーが閉じたら届かない）
             ④ POST /api/webhook/stripe ◀───┘ サーバー間通知（ブラウザに依存しない）
                                            │
                                            ▼
                                     purchases テーブルに保存
```

ブラウザが戻ってくる③と、Stripeがサーバーを直接叩く④の**両方**から
同じ `recordPurchase()` を呼んでいる。二重に走っても1行にしかならない仕掛けは後述。

触った場所。

| # | ファイル | 役割 |
|---|---|---|
| 1 | `config/services.php` | `key` / `secret` / `webhook_secret` |
| 2 | `app/Services/StripeCheckoutService.php` | Session 作成・取得・購入履歴の保存 |
| 3 | `app/Http/Controllers/CheckoutController.php` | 決済開始 / 完了 / キャンセル / 履歴 |
| 4 | `app/Http/Controllers/Api/StripeWebhookController.php` | 署名検証とイベント振り分け |
| 5 | `database/migrations/..._create_purchases_table.php` | 購入履歴（Stripe側IDにユニーク制約） |
| 6 | `app/Mail/WelcomeMail.php` | 会員登録時のウェルカムメール |

---

## 1. Checkout Session とは Stripe 側の何か

**「この金額・この商品で決済しますよ」という1回きりの取引をStripe側に予約したもの**。
作ると `cs_test_...` というIDと、Stripeがホストする決済ページの `url` が返ってくる。

自分のサーバーが持つのはこのIDだけで、カード情報は一切持たない。
Session には有効期限（既定24時間）があり、期限切れなら `checkout.session.expired` が飛ぶ。

`mode` は3種類あるが、今回は都度払いなので `payment`。

---

## 2. APIキーはどこで使っているか / なぜカード番号が自社サーバーを通らないか

APIキーを触るのは `StripeCheckoutService::stripe()` の1箇所だけ。

```php
private function stripe(): StripeClient
{
    return $this->stripe ??= new StripeClient(config('services.stripe.secret'));
}
```

- `env()` を直接呼ばず `config()` 経由。`config:cache` を効かせると `env()` は null を返すため
- 遅延生成にしてあるのは、**Webhookの署名検証にAPIキーが要らない**から。
  コンストラクタで `new StripeClient()` していた最初の版は、`STRIPE_SECRET` が空のときに
  署名検証に到達する前に例外で落ちていた

カード番号が自社サーバーを通らないのは、**入力欄が Stripe のドメイン上にあるから**。
うちがやるのは「金額と商品名を伝えてURLをもらい、そこへリダイレクトする」ところまで。
カード番号は `checkout.stripe.com` に直接送信され、うちのnginxにもPHPにもログにも残らない。
だから PCI DSS の対象範囲がぐっと小さくなる。

---

## 3. Webhook が必要な理由（ポーリングとの違い）

`success_url` へのリダイレクトは**ブラウザ頼み**なので、こういうときに届かない。

- 決済直後にユーザーがタブを閉じた
- 戻ってくる途中で回線が切れた
- コンビニ決済など、後から支払われる決済手段

「じゃあ定期的にStripeに聞けばいい（ポーリング）」だと、
未確定のSessionを全部覚えておいて、何秒おきに、いつまで聞くのかを自前で決めることになる。
大半の問い合わせは「まだです」で、無駄なAPIコールがレートリミットを食う。

Webhook は逆向きで、**確定した瞬間にStripeがこっちを叩いてくれる**。
リトライもStripe側が指数バックオフでやってくれる。
だから「注文を確定させる処理」は success ページではなく Webhook に置くのが正しい。

---

## 4. 署名検証が防いでいる攻撃

`/api/webhook/stripe` は認証なしで外部に開いている。CSRF検証も外している。
つまり**URLさえ知っていれば誰でもPOSTできる**。

署名検証が無いと、攻撃者はこういうJSONを自分で投げるだけでよくなる。

```json
{ "type": "checkout.session.completed",
  "data": { "object": { "payment_status": "paid", "amount_total": 1000 } } }
```

1円も払わずに購入履歴が立ち、商品が発送される。**偽装リクエストによる不正な注文確定**。

これを止めているのが `Webhook::constructEvent()`。
Stripe は `Stripe-Signature` ヘッダに
`t=<UNIX時刻>,v1=<HMAC-SHA256(t + "." + 生ボディ, webhook_secret)>` を入れて送ってくる。
`webhook_secret`（`whsec_...`）を知らないと正しい `v1` を作れないので、偽装できない。

タイムスタンプも一緒に署名されているため、**過去の正規リクエストを録音して投げ直す
リプレイ攻撃**も既定の許容差（300秒）を超えると弾かれる。

> ハマりどころ: 検証は**生のリクエストボディ**に対して行う。
> `$request->all()` で連想配列にしてから `json_encode()` し直すとキー順や空白が変わって必ず失敗する。
> 必ず `$request->getContent()` を渡すこと。

動作確認は署名を壊して投げれば分かる。

```bash
curl -i -X POST http://localhost/api/webhook/stripe \
  -H "Stripe-Signature: t=1,v1=deadbeef" -d '{"type":"checkout.session.completed"}'
# → HTTP 400 Invalid signature
```

---

## 5. 同じイベントが2回届いたとき、何が重複保存を防いでいるか

Stripe は「最低1回」配信する。成功しても再送されることがあるし、
success ページと Webhook が両方走れば同じ決済を2回処理しようとする。

防いでいるのは次の2つ。

**① DBのユニーク制約**（最後の砦）

```php
$table->string('stripe_session_id')->unique();
```

**② `updateOrCreate` の第1引数**（通常の経路）

```php
return Purchase::updateOrCreate(
    ['stripe_session_id' => $session->id],   // ← 探すキー
    [ /* 更新する中身 */ ]
);
```

`cs_test_...` は決済1回につき1つなので、これを冪等キーにすれば
2回目以降は INSERT ではなく UPDATE になる。テストで担保してある
（`tests/Feature/StripeWebhookTest.php::test_同じイベントが2回届いても1件しか保存されない`）。

イベントID（`evt_...`）で弾く手もあるが、それだと
「success ページ経由の保存」と「Webhook 経由の保存」が別物として2行入ってしまう。
**決済そのものを指すID**をキーにするのがポイント。

---

## 6. 教材との差分

| # | 教材 | ここでの実装 | 理由 |
|---|---|---|---|
| 1 | `config/services.php` に `webhook_secret` が無い | 追加した | 無いと署名検証に null が渡って必ず失敗する |
| 2 | `'payment_method_types' => ['card']` | 書かない | 現行の推奨。Dashboard側で決済手段を管理し、ウォレットも自動で有効になる |
| 3 | `Stripe::setApiKey()` + `Session::create()` | `new StripeClient()` | 7.33.0以前のレガシー。グローバル状態にキーを置かない |
| 4 | Webhookは `payment_intent.succeeded` | `checkout.session.completed` で確定、`payment_intent.succeeded` はログのみ | Checkout フローで注文を確定させる正しいイベントは前者 |
| 5 | catch は署名例外のみ | `UnexpectedValueException` も catch | v20 から V2 ペイロードでこれが飛ぶようになった |
| 6 | `$e->getError()->message` | `?? 'カード情報を確認してください'` | v21 で `ErrorObject` の型が nullable になった |

`'currency' => 'jpy'` は**ゼロdecimal通貨**なので `unit_amount` は円そのまま。
USD の感覚で100倍しない（`1000` = 1,000円）。

---

## 7. 例外の使い分け

外部APIは落ちる前提で書く。`ApiErrorException` が親なので、**具体的なものから先に**書く。

| 例外 | 原因 | 対処 |
|---|---|---|
| `CardException` | カード拒否・残高不足 | ユーザーに見せる。`getError()->message` は null があり得る |
| `InvalidRequestException` | パラメータ不正 | こっちのバグ。ログに残して汎用メッセージ |
| `AuthenticationException` | APIキー不正 | `.env` を確認。ユーザーには詳細を出さない |
| `ApiConnectionException` | 通信失敗 | 一時障害。リトライを案内 |

---

## 8. 動かす手順

### Stripe

1. Dashboard の**テストモード**で `pk_test_...` / `sk_test_...` を取得
2. `.env` に入れる（`STRIPE_KEY` / `STRIPE_SECRET`）
3. `docker compose exec app php artisan config:clear`
4. `/products/{id}` の「この商品を購入する」→ `4242 4242 4242 4242` / 有効期限は未来 / CVCは任意の3桁

### Webhook（ローカル転送）

```bash
stripe login
stripe listen --forward-to http://localhost/api/webhook/stripe
```

起動時に出る `whsec_...` を `STRIPE_WEBHOOK_SECRET` に入れて `config:clear`。
**この値は `stripe listen` を起動し直すたびに変わる**ので、届かなくなったらまずここを疑う。

確認:

```bash
docker compose exec app tail -f storage/logs/laravel.log   # 別ターミナルで
stripe trigger checkout.session.completed                  # 手動発火
```

### SendGrid

1. Sender Authentication で送信元を認証（ここを飛ばすと送信が弾かれる）
2. API Key（Mail Send 権限）を発行
3. `.env`: `MAIL_USERNAME=apikey` は**固定文字列**。ユーザー名ではない。
   `MAIL_PASSWORD` に `SG.` で始まるAPIキーを入れ、`MAIL_FROM_ADDRESS` を認証済みアドレスにする
4. `/register` から登録 → SendGrid の Activity Feed で `Delivered` を確認

送信は try-catch で囲んであるので、**SendGridが落ちても会員登録自体は通る**。
失敗は `storage/logs/laravel.log` に残る。

---

## 9. 鍵の扱い

- `.env` は `.gitignore` 済み。`.env.example` にはキー名だけ書いて値は空
- テストキー（`sk_test_...`）のみ。本番キーは触らない
- 履歴に混入していないことの確認:

```bash
git log --all --full-history -- .env   # 何も出なければOK
```
