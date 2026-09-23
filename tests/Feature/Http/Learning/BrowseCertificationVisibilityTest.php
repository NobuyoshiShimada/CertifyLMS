<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-03 回帰テスト: 受講生の教材詳細(Part / Chapter / Section)は親資格が公開中のときだけ閲覧でき、
 * 公開中でない資格(下書き / アーカイブ)では受講登録の状態(受講中 / 合格)に関わらず 404 になることを検証する。
 */
class BrowseCertificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 受講登録済み受講生と、全階層 Published の教材ツリーを指定状態の資格配下に作る。
     *
     * @return array{User, Part, Chapter, Section}
     */
    private function buildTree(string $certificationState, string $enrollmentState = 'learning'): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->{$certificationState}()->create();
        Enrollment::factory()->for($student)->for($certification)->{$enrollmentState}()->create();
        $part = Part::factory()->for($certification)->create(['status' => ContentStatus::Published->value]);
        $chapter = Chapter::factory()->for($part)->create(['status' => ContentStatus::Published->value]);
        $section = Section::factory()->for($chapter)->create([
            'status' => ContentStatus::Published->value,
            'body' => '# テスト本文',
        ]);

        return [$student, $part, $chapter, $section];
    }

    /**
     * @dataProvider unpublishedCertificationProvider
     */
    public function test_all_content_levels_return_404_when_certification_is_not_published(string $certificationState, string $enrollmentState): void
    {
        [$student, $part, $chapter, $section] = $this->buildTree($certificationState, $enrollmentState);

        $this->actingAs($student);
        $this->get(route('learning.parts.show', $part))->assertNotFound();
        $this->get(route('learning.chapters.show', $chapter))->assertNotFound();
        $this->get(route('learning.sections.show', $section))->assertNotFound();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unpublishedCertificationProvider(): array
    {
        return [
            '下書き資格 × 受講中' => ['draft', 'learning'],
            'アーカイブ資格 × 合格' => ['archived', 'passed'],
        ];
    }

    public function test_all_content_levels_are_visible_when_certification_is_published(): void
    {
        [$student, $part, $chapter, $section] = $this->buildTree('published');

        $this->actingAs($student);
        $this->get(route('learning.parts.show', $part))->assertOk();
        $this->get(route('learning.chapters.show', $chapter))->assertOk();
        $this->get(route('learning.sections.show', $section))->assertOk()->assertSee('テスト本文');
    }
}
