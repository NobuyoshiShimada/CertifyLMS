<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $qa_thread_id 親の質問スレッドID
 * @property int $user_id 回答したユーザーID
 * @property string $body 回答本文
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user 回答したユーザー
 * @property-read QaThread $qaThread 親の質問スレッド
 */
class QaReply extends Model
{
    use HasFactory;

    /**
     * 複数代入可能な属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'body',
        'user_id',
    ];

    /**
     * 質問スレッドに対する回答をトランザクションで保存する。
     *
     * @param QaThread $thread 回答対象の質問スレッドモデル
     * @param User $user 回答を行うユーザーモデル
     * @param string $body 回答本文の文字列
     *
     * @return self 作成された回答のインスタンス
     */
    public static function createWithTransaction(QaThread $thread, User $user, string $body): self
    {
        return DB::transaction(function () use ($thread, $user, $body): self {
            return $thread->replies()->create([
                'body' => $body,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * 該当の回答データをトランザクション保護下で安全に物理削除する。
     *
     * @return void
     */
    public function deleteWithTransaction(): void
    {
        DB::transaction(function (): void {
            $this->delete();
        });
    }

    /**
     * 回答を投稿したユーザー（受講生・コーチ）との多対一リレーションを取得する。
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 親にあたる質問スレッドとの多対一リレーションを取得する。
     *
     * @return BelongsTo
     */
    public function qaThread(): BelongsTo
    {
        return $this->belongsTo(QaThread::class);
    }

    /**
     * 回答の本文をデータベーストランザクションで安全に更新する。
     *
     * @param array $data 更新するデータの配列（body）
     *
     * @return void
     */
    public function updateWithTransaction(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $this->update([
                'body' => $data['body'],
            ]);
        });
    }
}
