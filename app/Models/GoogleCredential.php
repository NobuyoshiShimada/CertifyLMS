<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチの Google カレンダー連携情報(1 コーチ : 1 行、プライマリカレンダー固定)。
 *
 * アクセストークンは短命のため、期限切れ時は GoogleCalendarService がリフレッシュトークンで更新して保存し直す。
 * トークンは平文保存(本番運用では暗号化を推奨)。
 */
class GoogleCredential extends Model
{
    /** @use HasFactory<GoogleCredentialFactory> */
    use HasFactory, HasUlids;

    public const PRIMARY_CALENDAR = 'primary';

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'calendar_id',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 有効期限の 1 分前から期限切れとみなす(通信中の失効を避けるため)。
     */
    public function isAccessTokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->lte(now()->addMinute());
    }
}
