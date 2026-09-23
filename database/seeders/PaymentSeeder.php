<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 開発用 追加面談の購入記録シーダー。
 *
 * 固定 student@ に 決済完了 / 決済待ち / 決済失敗 を 1 件ずつ、demo 受講生の一部にも購入記録を散らす。
 * 決済完了分のみ MeetingQuotaTransaction(purchased) を起票し、残数に反映される(決済待ち / 失敗は反映されない)ことを
 * 面談回数履歴・ダッシュボードで確認できるようにする。MeetingPackSeeder / UserSeeder の後に実行する。
 */
class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $packs = MeetingPack::query()->published()->ordered()->get();

        if ($packs->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 公開中の面談パックがありません。先に MeetingPackSeeder を実行してください。');

            return;
        }

        $fixedStudent = User::query()->where('email', 'student@certify-lms.test')->first();
        if ($fixedStudent !== null) {
            $this->seedPayment($fixedStudent, $packs->get(1) ?? $packs->first(), PaymentStatus::Succeeded, daysAgo: 14);
            $this->seedPayment($fixedStudent, $packs->first(), PaymentStatus::Pending, daysAgo: 1);
            $this->seedPayment($fixedStudent, $packs->last(), PaymentStatus::Failed, daysAgo: 7);
        }

        $demoStudents = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->whereNotIn('email', ['student@certify-lms.test', 'student-noquota@certify-lms.test'])
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $statuses = [PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Succeeded];
        foreach ($demoStudents as $i => $student) {
            $this->seedPayment($student, $packs->get($i % $packs->count()), $statuses[$i], daysAgo: 3 + $i * 5);
        }
    }

    private function seedPayment(User $student, MeetingPack $pack, PaymentStatus $status, int $daysAgo): void
    {
        $createdAt = now()->subDays($daysAgo);
        $paidAt = $status === PaymentStatus::Succeeded ? $createdAt->copy()->addMinutes(3) : null;

        $payment = Payment::query()->create([
            'user_id' => $student->id,
            'meeting_pack_id' => $pack->id,
            'stripe_checkout_session_id' => 'cs_test_seed_'.Str::lower(Str::random(20)),
            'stripe_payment_intent_id' => $paidAt !== null ? 'pi_test_seed_'.Str::lower(Str::random(20)) : null,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
            'status' => $status->value,
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'updated_at' => $paidAt ?? $createdAt,
        ]);

        if ($paidAt !== null) {
            MeetingQuotaTransaction::query()->create([
                'user_id' => $student->id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $pack->meeting_count,
                'related_payment_id' => $payment->id,
                'occurred_at' => $paidAt,
            ]);
        }
    }
}
