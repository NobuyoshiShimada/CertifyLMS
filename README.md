# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

通知・メールはキュー経由で送信されるため、動作確認時は別ターミナルで worker を起動しておいてください（詳細は下記「通知・メール配信（キュー）」）。

```bash
sail artisan queue:work
```

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
sail artisan test --exclude-group external   # 外部 API 連携のテストを除外して実行
sail artisan test --group external           # 外部 API 連携のテストだけを実行
```

テストは外部 API(Google カレンダー / Gemini / Stripe)へ実通信しません。

- Laravel の Http Facade 経由でモックしていない通信が発生すると、テストが失敗します(`tests/TestCase.php` の `Http::preventStrayRequests()`)
- 外部 API 連携のテスト(`#[Group('external')]`)は、連携ごとに次の方法でモックしています。モックの構造は SDK 更新で壊れやすいため、グループ単位で除外できます
  - Google カレンダー: SDK が独自の HTTP クライアントを使うため、`Google\Client` を Mockery で差し替えます。面談機能側のテストは、連携の窓口(`GoogleCalendarGateway` / `GoogleCalendarService`)ごとスタブ化します
  - Gemini: Http Facade 経由なので `Http::fake()` で応答を差し替えます
  - Stripe Webhook: 受信側なので、`tests/Support/StripeWebhookSigner` で正規の署名を生成してリクエストを組み立てます

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

- `SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS` — 通知ポップオーバーが使う通知 JSON API(`/api/v1/notifications`)の Sanctum SPA Cookie 認証に使用します
  - 同一オリジン運用(既定)では、`APP_URL` のホスト(例: `localhost:8000`)が `SANCTUM_STATEFUL_DOMAINS` に含まれていれば追加設定は不要です
  - FE を別オリジンに置く場合は、その FE のホスト:ポートを `SANCTUM_STATEFUL_DOMAINS` に、オリジン(スキーム付き)を `CORS_ALLOWED_ORIGINS` に追加してください。あわせて Cookie を共有できるよう `SESSION_DOMAIN` の設定が必要です
  - JS は各ページで最初の API 呼び出し前に 1 度だけ `GET /sanctum/csrf-cookie` を呼び、`XSRF-TOKEN` Cookie の値を `X-XSRF-TOKEN` ヘッダで送ります(CSRF トークンのない POST は `419`)
- `GEMINI_API_KEY` / `GEMINI_MODEL` / `AI_CHAT_*` — 受講生の AI 相談(Gemini チャットボット)に使用します。API キーは [Google AI Studio](https://aistudio.google.com/) で個人取得し、必ず `.env` の `GEMINI_API_KEY` に設定してください(コードに直接書かないでください)
  - `GEMINI_API_KEY` が未設定の場合、AI 相談の画面・送信は「AI 相談機能は現在ご利用いただけません」(500)になります(他の機能は動作します)
  - `AI_CHAT_ENABLED=false` にすると AI 相談のルート・サイドバー項目・フローティングウィジェットをまとめて無効化します(404)
  - `GEMINI_MODEL` の既定は `gemini-2.5-flash-lite` です。無料枠の上限はモデル・時期で変わるため、利用前に AI Studio で確認してください
  - `AI_CHAT_DAILY_MESSAGE_LIMIT`(既定 50)は受講生 1 人あたりの 1 日の送信上限、`AI_CHAT_HISTORY_LIMIT`(既定 20)は AI に渡す直近の会話履歴の件数、`AI_CHAT_TITLE_GENERATION_ENABLED` は会話タイトルの AI 自動生成の ON / OFF です
  - AI 応答の失敗などの運用ログは `storage/logs/ai-chat-*.log` に出力されます
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` — コーチの Google カレンダー連携(OAuth 2.0)に使用します。値は必ず `.env` で設定し、コードに直接書かないでください。未設定でも面談機能は従来どおり動作します(連携開始だけができません)。設定手順は下記「Google カレンダー連携の動作確認」を参照してください
- `ADMIN_DASHBOARD_CACHE_TTL` — 管理者ダッシュボードの全体 KPI / 資格別修了率をキャッシュする秒数(既定 300 = 5 分)。受講状態の遷移(受講登録 / 合格 / 不合格 / 再開など)では即時に無効化され、それ以外の変化(資格の公開・非公開、受講登録の削除など)は TTL の失効で反映されます
- `QUEUE_CONNECTION` — 通知・メール送信のキュー接続です。既定は `database`(`jobs` テーブル)で、外部ミドルウェアは不要です。`sync` にすると従来どおり発火元リクエスト内で同期送信します(worker 不要)
- `STRIPE_*` — 追加面談の購入(Stripe Checkout + Webhook)に使用します。キーは必ず `.env` で設定し、コードに直接書かないでください。未設定でも他の機能は動作しますが、購入画面から決済画面へは進めません
  - `STRIPE_SECRET` — API シークレットキー(`sk_test_...`)。Stripe ダッシュボード Developers > API keys で取得します
  - `STRIPE_KEY` — 公開キー(`pk_test_...`)。同じ画面で取得します(Checkout ベースのため現状はサーバ側で未使用)
  - `STRIPE_WEBHOOK_SECRET` — Webhook 署名検証用シークレット(`whsec_...`)。ローカルでは下記の Stripe CLI が表示する値を使います。本番は Developers > Webhooks でエンドポイントを登録して取得します

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。

## 通知・メール配信（キュー）

通知(チャット / Q&A 返信 / 面談の予約・キャンセル・リマインダー / 管理者お知らせ)と招待メールは、発火元のリクエストでは `jobs` テーブルへジョブを積むだけで即応答し、実際の送信は worker が行います。トランザクション内で発火した送信は commit 後にだけ積まれます(ロールバック時は送信されません)。

```bash
# worker の起動(常駐。停止は Ctrl+C)
sail artisan queue:work

# 開発中にコード変更を即反映したい場合(ジョブごとにコードを読み直す)
sail artisan queue:listen
```

- worker を起動していない間、ジョブは `jobs` テーブルに積まれたまま処理されません(起動すると順次送信されます)
- 送信が一時的に失敗した場合、最大 3 回まで 10 秒 → 60 秒と待機を伸ばしながら自動リトライします
- 上限を超えて失敗した送信は `failed_jobs` テーブルに記録されます。確認・再投入は次のコマンドで行います

```bash
sail artisan queue:failed          # 失敗ジョブの一覧
sail artisan queue:retry all       # 失敗ジョブをすべて再投入(個別は queue:retry <ID>)
sail artisan queue:flush           # 失敗ジョブをすべて削除
```

- コードを変更したら `sail artisan queue:restart` で常駐中の worker を再起動してください(`queue:work` は起動時のコードを保持し続けます)
- 自動テスト(`phpunit.xml`)は `QUEUE_CONNECTION=sync` で実行されます

## 修了証 PDF

受講生が「修了証を受け取る」を実行すると、修了証の発行と同じ処理の中で日本語の修了証 PDF(A4 横向き)を生成し、`storage/app/private/certificates/` に保存します。追加の環境変数は不要です。

- PDF 生成には `mpdf/mpdf`(`composer install` で導入済み)を使用します。日本語は mpdf 同梱の CJK フォント(Sun-ExtA)で描画されるため、フォントの追加インストールは不要です
- mpdf の一時ファイルは `storage/framework/cache/mpdf/` に作成されます(`storage/` が書き込み可能であれば設定不要)
- 保存先は Web から直接アクセスできない `private` ディスク(`storage/app/private`)です。`public` ディスクや `storage:link` の公開領域には置かず、ダウンロードは `GET /certificates/{certificate}/download`(本人 / 担当コーチ / 管理者のみ、`certificate-{修了証 ID}.pdf` の添付形式)経由に限定しています
- PDF の生成に失敗した場合は、修了証の発行と受講登録の修了への遷移を巻き戻し、書きかけのファイルも削除します
- 初期データ(`sail artisan db:seed`)では、投入された修了証すべての PDF 実体を `CertificatePdfSeeder` が生成します

## Google カレンダー連携の動作確認

コーチが面談設定タブから自分の Google アカウントを連携すると、Google カレンダー(プライマリカレンダー)に予定がある時刻は受講生の予約画面の空き枠から外れ、面談の予約成立 / キャンセルに合わせて予定が登録 / 削除されます。Google との通信に失敗した場合は「連携なし」として扱い、面談の予約・キャンセル・空き枠の表示は止まりません。

1. [Google Cloud Console](https://console.cloud.google.com/) でプロジェクトを作成し、「Google Calendar API」を有効にします
2. 「OAuth 同意画面」を作成し、テストユーザーに連携に使う Google アカウントを追加します(スコープ: `calendar.events` / `calendar.freebusy`)
3. 「認証情報」で OAuth クライアント ID(種類: ウェブ アプリケーション)を作成し、承認済みのリダイレクト URI に `http://localhost:8000/settings/google-calendar/callback` を登録します
4. 取得したクライアント ID / シークレットを `.env` の `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` に設定し、`sail artisan config:clear` を実行します
5. コーチ(例: `coach2@certify-lms.test`)でログインし、設定 > 面談設定 の「Googleカレンダーと連携する」から連携します

初期データでは `coach@certify-lms.test` が連携済み、`coach2@certify-lms.test` が未連携です。`coach@` の連携情報はダミーのトークンのため、実際の Google API 呼び出しは失敗し「連携なし」として動作します(連携状態の表示と解除の確認用)。実際の空き枠除外や予定登録は、上記の手順で実アカウントを連携して確認してください。

> **本番運用での注意**: 本機能はアクセストークン / リフレッシュトークンを `google_credentials` テーブルに平文で保存しています。本番運用では、Laravel の暗号化キャスト(`encrypted`)などでトークンを暗号化して保存することを推奨します。

## 追加面談購入(Stripe)の動作確認

決済結果は Stripe からの Webhook(`POST /webhooks/stripe`)で受け取り、署名検証を通ったイベントだけを反映します。残面談回数の加算は決済完了画面の表示時ではなく、Webhook(`checkout.session.completed`)の受信時に行われます。

1. `.env` にテストモードの `STRIPE_SECRET` を設定します
2. [Stripe CLI](https://docs.stripe.com/stripe-cli) をインストールしてログインします

   ```bash
   stripe login
   ```

3. Webhook をローカルへ転送します。表示される `whsec_...` を `.env` の `STRIPE_WEBHOOK_SECRET` に設定し、`sail artisan config:clear` を実行します

   ```bash
   stripe listen --forward-to localhost:8000/webhooks/stripe
   ```

4. `student@certify-lms.test` でログインし、「追加面談の購入」からパックを選んで、テストカード `4242 4242 4242 4242`(有効期限は未来の任意の日付 / CVC は任意)で決済します
5. `stripe listen` のターミナルに `checkout.session.completed` の転送結果(`200`)が表示され、ダッシュボード / 面談予約画面 / 面談回数履歴の残数が購入回数分増えていることを確認します

決済画面で「戻る」を押して中断した場合は、ダッシュボードへ戻り、購入記録は「決済待ち」のまま残ります(Stripe のセッション期限切れイベント受信時に「決済失敗」になります)。署名が正しくない通知は `400` で拒否され、購入記録や残数は変更されません。
