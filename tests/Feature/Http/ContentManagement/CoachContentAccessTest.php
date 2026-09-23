<?php

declare(strict_types=1);

namespace Tests\Feature\Http\ContentManagement;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\QuestionCategory;
use App\Models\Section;
use App\Models\SectionImage;
use App\Models\SectionQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B-B-01 回帰テスト: コーチは担当資格配下の教材管理(Part / Chapter / Section / 演習問題 / 教材内画像 / 出題分野マスタ)を
 * 操作でき、担当外資格では引き続き 403 になることを画面・操作単位で検証する。
 */
class CoachContentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->coach = User::factory()->coach()->create();
    }

    /**
     * @return array{certification: Certification, part: Part, chapter: Chapter, section: Section, question: SectionQuestion, category: QuestionCategory, image: SectionImage}
     */
    private function contentTree(bool $assigned): array
    {
        $certification = Certification::factory()->published()->create();
        if ($assigned) {
            $certification->coaches()->attach($this->coach->id, [
                'id' => (string) Str::ulid(),
                'assigned_by_user_id' => User::factory()->admin()->create()->id,
                'assigned_at' => now(),
            ]);
        }

        $part = Part::factory()->forCertification($certification)->create();
        $chapter = Chapter::factory()->forPart($part)->create();
        $section = Section::factory()->forChapter($chapter)->create();
        $category = QuestionCategory::factory()->forCertification($certification)->create();
        $question = SectionQuestion::factory()->forSection($section)->forCategory($category)->create();
        $image = SectionImage::factory()->forSection($section)->create();

        return compact('certification', 'part', 'chapter', 'section', 'question', 'category', 'image');
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    private function requests(array $tree): array
    {
        return [
            'Part 一覧' => ['get', route('admin.certifications.parts.index', $tree['certification']), []],
            'Part 詳細' => ['get', route('admin.parts.show', $tree['part']), []],
            'Part 作成' => ['post', route('admin.certifications.parts.store', $tree['certification']), ['title' => '新しい Part']],
            'Part 更新' => ['patch', route('admin.parts.update', $tree['part']), ['title' => '更新後 Part']],
            'Part 公開' => ['post', route('admin.parts.publish', $tree['part']), []],
            'Chapter 詳細' => ['get', route('admin.chapters.show', $tree['chapter']), []],
            'Chapter 作成' => ['post', route('admin.parts.chapters.store', $tree['part']), ['title' => '新しい Chapter']],
            'Chapter 並び替え' => ['patch', route('admin.parts.chapters.reorder', $tree['part']), ['ids' => [$tree['chapter']->id]]],
            'Section 編集画面' => ['get', route('admin.sections.show', $tree['section']), []],
            'Section 更新' => ['patch', route('admin.sections.update', $tree['section']), ['title' => '更新後 Section', 'body' => '本文']],
            'Section プレビュー' => ['post', route('admin.sections.preview', $tree['section']), ['body' => '# 見出し']],
            '演習問題一覧' => ['get', route('admin.sections.questions.index', $tree['section']), []],
            '演習問題詳細' => ['get', route('admin.section-questions.show', $tree['question']), []],
            '演習問題 公開' => ['post', route('admin.section-questions.publish', $tree['question']), []],
            '教材内画像アップロード' => ['post', route('admin.sections.images.store', $tree['section']), ['image' => UploadedFile::fake()->image('a.png')]],
            '教材内画像削除' => ['delete', route('admin.section-images.destroy', $tree['image']), []],
            '出題分野マスタ一覧' => ['get', route('admin.certifications.question-categories.index', $tree['certification']), []],
            '出題分野マスタ作成' => ['post', route('admin.certifications.question-categories.store', $tree['certification']), ['name' => '新分野']],
        ];
    }

    public function test_assigned_coach_can_access_all_content_management_screens_and_operations(): void
    {
        $tree = $this->contentTree(assigned: true);

        foreach ($this->requests($tree) as $label => [$method, $url, $data]) {
            $status = $this->actingAs($this->coach)->{$method}($url, $data)->getStatusCode();

            $this->assertNotSame(403, $status, "{$label} が担当コーチに対して 403 になっている");
            $this->assertLessThan(500, $status, "{$label} がサーバーエラー({$status})になっている");
        }
    }

    public function test_assigned_coach_can_open_main_content_management_screens(): void
    {
        $tree = $this->contentTree(assigned: true);

        $this->actingAs($this->coach)
            ->get(route('admin.certifications.parts.index', $tree['certification']))
            ->assertOk();
        $this->actingAs($this->coach)
            ->get(route('admin.sections.show', $tree['section']))
            ->assertOk();
        $this->actingAs($this->coach)
            ->get(route('admin.certifications.question-categories.index', $tree['certification']))
            ->assertOk();
    }

    public function test_unassigned_coach_is_still_forbidden_for_all_content_management(): void
    {
        $tree = $this->contentTree(assigned: false);

        foreach ($this->requests($tree) as $label => [$method, $url, $data]) {
            $this->actingAs($this->coach)
                ->{$method}($url, $data)
                ->assertForbidden();
        }

        $this->assertDatabaseHas('parts', ['id' => $tree['part']->id, 'title' => $tree['part']->title]);
    }
}
