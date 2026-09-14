<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Class AnnouncementController
 *
 * 管理者による受講生向け一斉お知らせ配信(作成・実行・履歴閲覧)を制御するコントローラー。
 * 配信は即時・不可逆(編集/再配信/取消なし)のため update / destroy を持たない。
 */
class AnnouncementController extends Controller
{
    /**
     * 配信履歴一覧の表示
     */
    public function index(): View
    {
        $this->authorize('viewAny', Announcement::class);

        $announcements = Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByDesc('dispatched_at')
            ->paginate(20);

        return view('announcement.management.index', compact('announcements'));
    }

    /**
     * 配信作成フォームの表示
     */
    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        $certifications = Certification::query()
            ->where('status', CertificationStatus::Published)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->unique('name')
            ->values();
        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('announcement.management.create', compact('certifications', 'students'));
    }

    /**
     * 配信の実行。対象受講生を解決し、お知らせを記録したうえで各受講生へ通知(アプリ内+メール)を配信する。
     */
    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $this->authorize('create', Announcement::class);

        $data = $request->validated();
        $targetType = AnnouncementTargetType::from($data['target_type']);

        $recipients = $this->resolveRecipients($targetType, $data);

        $announcement = DB::transaction(function () use ($data, $targetType, $recipients, $request) {
            return Announcement::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'target_type' => $targetType,
                'target_certification_id' => $data['target_certification_id'] ?? null,
                'target_user_id' => $data['target_user_id'] ?? null,
                'dispatched_count' => $recipients->count(),
                'dispatched_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        });

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AdminAnnouncementNotification([
                'title' => $announcement->title,
                'body' => $announcement->body,
            ]));
        }

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('success', 'お知らせを配信しました。');
    }

    /**
     * 配信済みお知らせの詳細表示
     */
    public function show(Announcement $announcement): View
    {
        $this->authorize('view', $announcement);

        $announcement->loadMissing(['targetCertification', 'targetUser', 'createdBy']);

        return view('announcement.management.show', compact('announcement'));
    }

    /**
     * 配信対象種別に応じて、通知を届けるべき対象受講生(アクティブなアカウントのみ)を解決する。
     *
     * @param array<string, mixed> $data
     *
     * @return Collection<int, User>
     */
    private function resolveRecipients(AnnouncementTargetType $targetType, array $data): Collection
    {
        return match ($targetType) {
            AnnouncementTargetType::AllStudents => User::query()
                ->where('role', UserRole::Student->value)
                ->active()
                ->get(),

            AnnouncementTargetType::Certification => User::query()
                ->where('role', UserRole::Student->value)
                ->active()
                ->whereHas('enrollments', function ($query) use ($data) {
                    $query->where('certification_id', $data['target_certification_id'])->learning();
                })
                ->get(),

            AnnouncementTargetType::User => User::query()
                ->where('id', $data['target_user_id'])
                ->where('role', UserRole::Student->value)
                ->active()
                ->get(),
        };
    }
}
