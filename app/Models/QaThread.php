<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id 投稿した受講生ID
 * @property int|null $certification_id 紐づく資格ID
 * @property string $title 質問タイトル
 * @property string $body 質問本文
 * @property QaThreadStatus $status 解決状態 (unresolved / resolved)
 * @property Carbon|null $resolved_at 解決日時
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user 投稿したユーザー
 * @property-read Certification|null $certification 紐づく資格
 * @property-read Collection<int, QaReply> $replies 紐づく回答一覧
 */
class QaThread extends Model
{
    use HasFactory;

    /**
     * 複数代入可能な属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'certification_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    /**
     * 属性に対するキャスト定義。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * 新しい質問スレッドをトランザクションで保存する。
     *
     * @param User $user 投稿を行うユーザーモデル
     * @param array $data 登録データ（certification_id, title, body）
     *
     * @return self 作成された質問スレッドのインスタンス
     */
    public static function createWithTransaction(User $user, array $data): self
    {
        return DB::transaction(function () use ($user, $data): self {
            return $user->qaThreads()->create([
                'certification_id' => $data['certification_id'],
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => QaThreadStatus::Unresolved,
            ]);
        });
    }

    /**
     * 質問スレッドのステータスを「解決済」に更新する。
     *
     * @return void
     */
    public function markAsResolved(): void
    {
        DB::transaction(function (): void {
            $this->update([
                'status' => QaThreadStatus::Resolved,
                'resolved_at' => now(),
            ]);
        });
    }

    /**
     * 質問スレッドのステータスを「未解決」に戻す。
     *
     * @return void
     */
    public function markAsUnresolved(): void
    {
        DB::transaction(function (): void {
            $this->update([
                'status' => QaThreadStatus::Unresolved,
                'resolved_at' => null,
            ]);
        });
    }

    /**
     * 質問を投稿したユーザー（受講生）との多対一リレーションを取得する。
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 質問に紐づく資格マスタとの多対一リレーションを取得する。
     *
     * @return BelongsTo
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * 質問に紐づく回答一覧との一対多リレーションを取得する。
     *
     * @return HasMany
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }

    /**
     * 質問スレッドのタイトルと本文をデータベーストランザクションで安全に更新する。
     *
     * @param array $data 更新するデータの配列（title, body）
     *
     * @return void
     */
    public function updateWithTransaction(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $this->update([
                'title' => $data['title'],
                'body' => $data['body'],
            ]);
        });
    }

    /**
     * 該当の質問スレッドデータをデータベーストランザクションで安全に物理削除する。
     *
     * @return void
     */
    public function deleteWithTransaction(): void
    {
        DB::transaction(function (): void {
            $this->delete();
        });
    }
}
