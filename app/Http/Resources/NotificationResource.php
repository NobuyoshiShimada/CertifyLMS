<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知ポップオーバー(JS)向けの通知 1 件の整形 Resource。
 *
 * 遷移先は通知種別(`type`)と業務 URL(`url`)を渡し、JS 側で解決する(お知らせは通知詳細、未対応種別は一覧へフォールバック)。
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $data['notification_type'] ?? null,
            'title' => (string) ($data['title'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'url' => isset($data['url']) && $data['url'] !== '' ? (string) $data['url'] : null,
            'detail_url' => route('notifications.show', ['notification' => $this->id]),
            'is_unread' => $this->read_at === null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
