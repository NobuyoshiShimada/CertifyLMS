<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを新規作成するユースケース。新規作成時の状態は常に下書き(draft)固定。
 */
final class StoreAction
{
    /**
     * @param array{name: string, description?: ?string, meeting_count: int, price: int, stripe_price_id?: ?string, sort_order?: ?int} $data
     */
    public function __invoke(User $admin, array $data): MeetingPack
    {
        return DB::transaction(function () use ($admin, $data) {
            return MeetingPack::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'meeting_count' => $data['meeting_count'],
                'price' => $data['price'],
                'stripe_price_id' => $data['stripe_price_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => MeetingPackStatus::Draft->value,
                'created_by_user_id' => $admin->id,
                'updated_by_user_id' => $admin->id,
            ]);
        });
    }
}
