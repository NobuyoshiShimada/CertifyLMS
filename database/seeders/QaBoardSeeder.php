<?php

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 質問掲示板（Q&A）に意味の通じる人間が読めるリアルなテストデータを散布するシーダー。
 */
class QaBoardSeeder extends Seeder
{
    /**
     * 質問掲示板の初期データ生成処理を実行する。
     *
     * @return void
     */
    public function run(): void
    {
        // 1. 固定のテスト用ユーザー（受講生）を用意する
        /** @var User $targetStudent */
        $targetStudent = User::where('role', UserRole::Student)->first()
            ?? User::factory()->create(['name' => '山田太郎', 'role' => UserRole::Student]);

        // 2. 回答用のコーチや他の受講生たちのプール
        $coaches = User::where('role', UserRole::Coach)->get();
        if ($coaches->isEmpty()) {
            $coaches = User::factory()->count(2)->create(['role' => UserRole::Coach]);
        }
        $otherStudents = User::factory()->count(4)->create(['role' => UserRole::Student]);
        $usersPool = $coaches->concat($otherStudents);

        // 3. 意味の通じるリアルなQ&Aテンプレート（10選）
        $qaTemplates = collect([
            [
                'title' => '2分探索木の平均比較回数のオーダーがイメージできません',
                'body' => "教材のアルゴリズムの章を読んでいます。\n2分探索木の最悪の場合のオーダーが O(n) で、平均の場合が O(log n) になる理由が数学的にしっくりきていません。\n木構造の高さと関係しているということは分かったのですが、なぜ log になるのか分かりやすく教えていただけないでしょうか？",
                'replies' => [
                    '木構造の各ノードが完全に2つずつ枝分かれしている「完全2分木」をイメージすると分かりやすいですよ。要素数がN個のとき、木の高さ（深さ）はおおよそ log2(N) になります。探索は根から葉に向かって1段ずつ進むため、最大でも木の高さ分しか比較しません。これが平均 O(log n) になる理由です！',
                    '補足として、最悪の場合 O(n) になるのは、データが昇順に並んでいて、木が一直線に偏ってしまった場合（事実上の線形リスト）ですね。あわせて覚えておくと理解が深まります。'
                ]
            ],
            [
                'title' => 'データベースの第3正規化を行うメリットについて',
                'body' => "実務のデータベース設計の課題をやっています。\n第2正規化まででもデータは綺麗に分かれているように見えるのですが、あえて「主キー以外のカラムに依存している関係（推移的関数従属）」を排除して第3正規化まで行う最大のメリットは何でしょうか？更新異常の具体例などがあれば教えてほしいです。",
                'replies' => [
                    '最大のメリットは「データの不整合（矛盾）を防ぐこと」です。例えば、[社員テーブル]に[部署コード]と[部署名]が同居していた場合、その部署に所属する社員が全員退職すると、部署名というデータまでデータベースから消滅してしまいます。これを第3正規化で別テーブルに分けることで、部署データだけを独立して保持できます。'
                ]
            ],
            [
                'title' => 'オブジェクト指向の「多態性（ポリモーフィズム）」の利点が分かりません',
                'body' => "Javaの教材を読み進めています。継承やインターフェースを使ったポリモーフィズムの仕組みはコードの書き方としては理解できたのですが、なぜわざわざこんな複雑なことをするのか、具体的な開発においてどう嬉しくなるのかがピンときていません。普通の if 文で分岐させるのと何が違うのでしょうか？",
                'replies' => [
                    'ポリモーフィズムの最大の利点は「呼び出し側のコードを一切書き換えずに、新しい機能を追加できること」です。もし if 文で分岐させていると、新しいキャラクターや新しい決済方法が増えるたびに、すべての if 文の場所にコードを追加してテストし直す必要があります。ポリモーフィズムを使えば、共通のインターフェースを持った新しいクラスを1つ追加するだけで済みます。'
                ]
            ],
            [
                'title' => 'Gitの merge と rebase の使い分けの基準を教えてください',
                'body' => "チーム開発の演習を始めるにあたりGitの勉強をしています。\nブランチの履歴を統合するときに、merge を使うべきケースと、rebase を使うべきケースの一般的な使い分けの基準や、現場での運用ルールがあれば知りたいです。よろしくお願いいたします。",
                'replies' => [
                    '一般的な基準としては「共有ブランチ（mainやdevelop）には merge を使い、自分のローカルでの作業ブランチを最新に追従させるときには rebase を使う」ことが多いです。rebase を使うとコミット履歴が一本の直線になり見やすくなりますが、すでにリモートにプッシュした共有ブランチで rebase を行うと、他のメンバーの履歴と衝突して大混乱が起きるので絶対にNGです。'
                ]
            ],
            [
                'title' => 'MVCモデルにおけるビジネスロジックの適切な記述場所はどこですか？',
                'body' => "Laravelを使ってWebアプリケーションを構築しています。\nコントローラーがどんどん肥大化（いわゆるFat Controller）してしまい、コードのメンテナンスが辛くなってきました。バリデーション以外の複雑な計算やDBへの保存ロジックは、Modelに書くべきか、それとも別のクラスを作るべきでしょうか？",
                'replies' => [
                    'まさに今学ばれている「Fat Model, Thin Controller（モデルを厚く、コントローラーを薄く）」がLaravelの王道の設計思想ですね。データの更新に伴うトランザクションやビジネスロジックは、Modelのカスタムメソッドに隠蔽するか、より複雑であれば「Serviceクラス」を新しく作成してそこに切り出すのが現場では一般的です。コントローラーは受付とレスポンスだけに特化させましょう。'
                ]
            ],
            [
                'title' => 'Cookieとセッション情報の違いとセキュリティ上の注意点',
                'body' => "Webセキュリティの章を勉強しています。ログイン状態の保持にCookieとセッションが使われることは理解したのですが、具体的なデータの「保存場所」と、なぜセッションIDを暗号化したりセッション側で管理しなければ安全ではないのか、その理由を解説していただきたいです。",
                'replies' => [
                    '決定的な違いは「データの保存場所」です。Cookieはユーザーのブラウザ（クライアント側）に保存されるため、ユーザーが自由に中身を書き換えることができます。もしCookieに「ユーザーID=1」とそのまま入れておくと、ユーザーがそこを「2」に書き換えるだけで他人のアカウントに乗っ取れてしまいます。そのため、本物のデータはサーバー側（セッション）で安全に管理し、ブラウザにはその鍵となる「ランダムなセッションID」だけをCookieとして渡す仕組みになっています。'
                ]
            ],
            [
                'title' => 'REST APIにおけるURL設計とHTTPメソッドのベストプラクティス',
                'body' => "API開発の課題に取り組んでいます。\nリソース（例えば商品データなど）に対して操作を行う際、URLの命名（複数形にすべきかなど）や、GET/POST/PUT/DELETEといったHTTPメソッドをどのように割り当てるのが最も美しい設計とされるのか、標準的なルールを知りたいです。",
                'replies' => [
                    'URLは「名詞の複数形」を基本とし、操作はHTTPメソッドで表現するのがRESTの美しい設計です。例えば、商品（products）を対象とする場合、一覧取得は「GET /products」、新規作成は「POST /products」、特定商品の更新は「PATCH /products/{id}」、削除は「DELETE /products/{id}」とします。URLの中に「/delete-product」のように動詞を入れないのがポイントです。'
                ]
            ],
            [
                'title' => '公開鍵暗号方式で、なぜ「公開鍵」で暗号化して「秘密鍵」で復号するのか',
                'body' => "ネットワークセキュリティの基礎を学んでいます。\nデータの暗号化において、なぜ誰に渡してもいい公開鍵で暗号化すると、本人しか持っていない秘密鍵でしか解読できなくなるのか、その数学的（あるいは論理的）な仕組みのイメージが湧きません。南京錠などの例えではなく仕組みが知りたいです。",
                'replies' => [
                    '数学的には「一方通行の計算（大きな素数の因数分解など）」を利用しています。公開鍵は「誰でも簡単に計算（暗号化）できる計算式」ですが、その計算を元に戻す（復号する）には、「秘密の数字（秘密鍵）」を知らないと天文学的な時間がかかるような数式で作られています。つまり、かけるのは簡単だけど、外すには専用の特殊な工具（秘密鍵）が絶対に必要になる数式のペアになっているためです。'
                ]
            ],
            [
                'title' => 'インデックス（Index）をデータベースに貼るべきカラムの基準',
                'body' => "SQLのパフォーマンスチューニングについて学習しています。\n検索を高速化するためにインデックスが有効なのは理解したのですが、すべてのカラムに貼ると逆にパフォーマンスが落ちると聞きました。具体的にどのようなカラムに絞ってインデックスを貼るべきでしょうか？",
                'replies' => [
                    'インデックスを貼るべき主な基準は3つあります。1つ目は「WHERE句での絞り込みによく使われるカラム」、2つ目は「JOINの結合条件に使われる外部キーのカラム」、3つ目は「ORDER BYでの並び替えによく指定されるカラム」です。逆に、データの種類が少ないカラム（例えば、男/女や、有効/無効の2種類しかないような「カーディナリティが低い」カラム）に貼っても、検索効率はほとんど上がらず、データの追加・更新（INSERT/UPDATE）が重くなるだけの逆効果になります。'
                ]
            ],
            [
                'title' => 'CSSの Flexbox と Grid レジアウトの使い分けの明確な基準',
                'body' => "フロントエンドの画面UIを組んでいます。\n横並びのメニューやカード一覧を作るとき、FlexboxでもGridでもどちらでも同じような見た目が作れてしまうため、どちらを選択すべきか迷うことが多いです。プロの現場ではどのように使い分けているのでしょうか？",
                'replies' => [
                    '最も明確な基準は「1次元（直線）か、2次元（格子）か」です。Flexboxは「横一列」または「縦一列」という、1つの軸に沿ったレイアウト（1次元）に最適で、要素の幅がコンテンツに応じて柔軟に変わるメニューバーなどに適しています。一方、Gridは「縦と横の両方の位置を完全に揃えた碁盤の目（2次元）」のレイアウトに最適で、ダッシュボードの枠組みや、縦横がきっちり揃ったカード型の一覧を作るのに適しています。まずは「縦横を完全にコントロールしたいか」で考えてみてください。'
                ]
            ]
        ]);

        // 4. 現在「公開中（Published）」の資格をすべて取得する
        $publishedCertifications = Certification::where('status', CertificationStatus::Published)->get();
        if ($publishedCertifications->isEmpty()) {
            $publishedCertifications = Certification::factory()->count(3)->create(['status' => CertificationStatus::Published]);
        }

        // 5. 公開済みの資格ごとにQ&Aテンプレートを散布
        $publishedCertifications->each(function (Certification $certification) use ($targetStudent, $usersPool, $qaTemplates): void {

            // テンプレートをシャッフルして資格ごとに割り振る
            $shuffledTemplates = $qaTemplates->shuffle();

            // 5-1. 一般の受講生たちのリアルな質問スレッドを8件作成（未解決・解決済混在）
            for ($i = 0; $i < 8; $i++) {
                $template = $shuffledTemplates->get($i);
                if (!$template) break;

                $createdAt = fake()->dateTimeBetween('-2 weeks', 'now');
                $isResolved = fake()->boolean(50); // 50%の確率で解決済

                $thread = QaThread::factory()->create([
                    'certification_id' => $certification->id,
                    'user_id'          => $usersPool->random()->id,
                    'title'            => $template['title'],
                    'body'             => $template['body'],
                    'status'           => $isResolved ? \App\Enums\QaThreadStatus::Resolved : \App\Enums\QaThreadStatus::Unresolved,
                    'resolved_at'      => $isResolved ? fake()->dateTimeBetween($createdAt, 'now') : null,
                    'created_at'       => $createdAt,
                    'updated_at'       => $createdAt,
                ]);

                // 関連する意味の通じる回答（Reply）を紐付ける
                foreach ($template['replies'] as $replyBody) {
                    QaReply::factory()->create([
                        'qa_thread_id' => $thread->id,
                        'user_id'      => $usersPool->random()->id,
                        'body'         => $replyBody,
                        'created_at'   => fake()->dateTimeBetween($thread->created_at, 'now'),
                    ]);
                }
            }

            // 5-2. 固定の検証用ユーザー（山田太郎）が投稿した「マイ質問」を各資格2件ずつ用意
            for ($i = 8; $i < 10; $i++) {
                $template = $shuffledTemplates->get($i);
                if (!$template) break;

                $createdAt = fake()->dateTimeBetween('-5 days', 'now');

                $thread = QaThread::factory()->create([
                    'certification_id' => $certification->id,
                    'user_id'          => $targetStudent->id,
                    'title'            => '[マイ質問] ' . $template['title'],
                    'body'             => $template['body'],
                    'status'           => \App\Enums\QaThreadStatus::Unresolved, // 自分の質問は一旦未解決にする（動線確認用）
                    'resolved_at'      => null,
                    'created_at'       => $createdAt,
                    'updated_at'       => $createdAt,
                ]);

                // コーチ陣が自分の質問に親身に回答してくれているデータを模倣
                if (!empty($template['replies'])) {
                    QaReply::factory()->create([
                        'qa_thread_id' => $thread->id,
                        'user_id'      => $usersPool->random()->id,
                        'body'         => $template['replies'][0], // 最初の回答をセット
                        'created_at'   => fake()->dateTimeBetween($thread->created_at, 'now'),
                    ]);
                }
            }
        });
    }
}
