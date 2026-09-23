<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * B-B-14 回帰テスト: 未読集計の 3 経路(ルーム単体 / ルーム一覧バッジ / サイドバーの未読ルーム数)すべてで
 * 自分の発言を未読に含めず、相手の発言は既読時刻より後のものだけを数えることを検証する。
 */
class ChatUnreadOwnMessageTest extends TestCase
{
    use RefreshDatabase;

    private ChatUnreadCountService $service;

    private User $student;

    private User $coach;

    /** 自分の発言だけが既読時刻より後に残っているルーム */
    private ChatRoom $ownOnlyRoom;

    /** 相手の新着が既読時刻より後にあるルーム */
    private ChatRoom $unreadRoom;

    /** 相手の発言がすべて既読時刻より前のルーム */
    private ChatRoom $readRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
        $this->service = app(ChatUnreadCountService::class);
        $this->student = User::factory()->student()->inProgress()->create();
        $this->coach = User::factory()->coach()->inProgress()->create();
        $lastReadAt = now()->subHour();

        $this->ownOnlyRoom = $this->room($lastReadAt, [
            [$this->coach, now()->subHours(2)],
            [$this->student, now()->subMinutes(30)],
            [$this->student, now()->subMinutes(10)],
        ]);
        $this->unreadRoom = $this->room($lastReadAt, [
            [$this->student, now()->subMinutes(40)],
            [$this->coach, now()->subMinutes(20)],
        ]);
        $this->readRoom = $this->room($lastReadAt, [
            [$this->coach, now()->subHours(3)],
        ]);
    }

    /**
     * @param array<int, array{User, Carbon}> $messages
     */
    private function room(Carbon $studentLastReadAt, array $messages): ChatRoom
    {
        $room = ChatRoom::factory()->for(Enrollment::factory()->for($this->student))->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $this->student->id, 'last_read_at' => $studentLastReadAt]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $this->coach->id, 'last_read_at' => null]);

        foreach ($messages as [$sender, $at]) {
            ChatMessage::factory()->create([
                'chat_room_id' => $room->id,
                'sender_user_id' => $sender->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $room;
    }

    public function test_room_list_badges_exclude_own_messages(): void
    {
        $this->assertSame(0, $this->service->messageCountInRoom($this->ownOnlyRoom, $this->student));
        $this->assertSame(1, $this->service->messageCountInRoom($this->unreadRoom, $this->student));
        $this->assertSame(0, $this->service->messageCountInRoom($this->readRoom, $this->student));

        $this->assertSame([
            $this->ownOnlyRoom->id => 0,
            $this->unreadRoom->id => 1,
            $this->readRoom->id => 0,
        ], $this->service->messageCountsByRoomForUser([$this->ownOnlyRoom, $this->unreadRoom, $this->readRoom], $this->student));
    }

    public function test_sidebar_room_count_excludes_rooms_with_only_own_messages(): void
    {
        $this->assertSame(1, $this->service->roomCountForUser($this->student));
    }

    public function test_counterpart_still_sees_student_messages_as_unread(): void
    {
        // コーチは last_read_at 未設定: 受講生の発言は未読、自分(コーチ)の発言は含めない
        $this->assertSame(2, $this->service->messageCountInRoom($this->ownOnlyRoom, $this->coach));
        $this->assertSame(1, $this->service->messageCountInRoom($this->unreadRoom, $this->coach));
        $this->assertSame(2, $this->service->roomCountForUser($this->coach));
    }

    public function test_opening_room_marks_unread_as_read(): void
    {
        ChatMember::query()
            ->where('chat_room_id', $this->unreadRoom->id)
            ->where('user_id', $this->student->id)
            ->update(['last_read_at' => now()]);

        $this->assertSame(0, $this->service->messageCountInRoom($this->unreadRoom, $this->student));
        $this->assertSame(0, $this->service->roomCountForUser($this->student));
    }
}
