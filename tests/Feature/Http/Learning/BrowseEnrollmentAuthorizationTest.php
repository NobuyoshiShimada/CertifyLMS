<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-09 回帰テスト: 受講生は受講登録(受講中 / 合格)している資格の教材詳細だけを閲覧でき、
 * 未登録・それ以外の状態の資格の Part / Chapter / Section へ直リンクすると 403 になることを検証する。
 */
class BrowseEnrollmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Part $part;

    private Chapter $chapter;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $this->chapter = Chapter::factory()->for($this->part)->create(['status' => ContentStatus::Published->value]);
        $this->section = Section::factory()->for($this->chapter)->create([
            'status' => ContentStatus::Published->value,
            'body' => '# 教材本文',
        ]);
    }

    private function enroll(string $state): void
    {
        Enrollment::factory()->for($this->student)->for($this->part->certification)->{$state}()->create();
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        return [
            'Part' => route('learning.parts.show', $this->part),
            'Chapter' => route('learning.chapters.show', $this->chapter),
            'Section' => route('learning.sections.show', $this->section),
        ];
    }

    public function test_non_enrolled_student_gets_403_on_all_content_levels(): void
    {
        // 別資格には受講登録しているが、対象資格には未登録
        Enrollment::factory()->for($this->student)->for(Certification::factory()->published())->learning()->create();

        foreach ($this->urls() as $level => $url) {
            $this->actingAs($this->student)->get($url)->assertForbidden();
        }

        $this->assertSame(0, LearningSession::query()->count(), '403 の Section 閲覧で学習セッションが開始されている');
    }

    public function test_failed_enrollment_gets_403_on_all_content_levels(): void
    {
        $this->enroll('failed');

        foreach ($this->urls() as $url) {
            $this->actingAs($this->student)->get($url)->assertForbidden();
        }
    }

    /**
     * @dataProvider allowedEnrollmentProvider
     */
    public function test_enrolled_student_can_view_all_content_levels(string $state): void
    {
        $this->enroll($state);

        foreach ($this->urls() as $level => $url) {
            $this->actingAs($this->student)->get($url)->assertOk();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedEnrollmentProvider(): array
    {
        return ['受講中' => ['learning'], '合格' => ['passed']];
    }
}
