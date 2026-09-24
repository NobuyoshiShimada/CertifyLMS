<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 学習進捗の集計(Section / Chapter / Part / 資格)を、完了 0 件 / 一部完了 / 全完了 の境界で検証する。
 *
 * 教材構成(公開分のみが集計対象):
 * - Part A: Chapter A1(公開 Section 2 + 下書き 1) / Chapter A2(Section 1)
 * - Part B: Chapter B1(Section 1) / Chapter B-empty(公開 Section 0)/ 下書き Chapter(Section 1)
 * - 下書き Part(Chapter / Section 付き)
 * → Section 4 / Chapter 4 / Part 2
 */
class ProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgressService $service;

    private Certification $certification;

    private Enrollment $enrollment;

    /** @var array<string, Section> */
    private array $sections = [];

    /** @var array<string, Chapter> */
    private array $chapters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProgressService::class);
        $this->certification = Certification::factory()->published()->create();
        $this->enrollment = Enrollment::factory()->for($this->certification)->learning()->create();

        $partA = Part::factory()->published()->for($this->certification)->create();
        $this->chapters['A1'] = Chapter::factory()->published()->for($partA)->create();
        $this->chapters['A2'] = Chapter::factory()->published()->for($partA)->create();
        $this->sections['A1-1'] = Section::factory()->published()->for($this->chapters['A1'])->create();
        $this->sections['A1-2'] = Section::factory()->published()->for($this->chapters['A1'])->create();
        $this->sections['A1-draft'] = Section::factory()->draft()->for($this->chapters['A1'])->create();
        $this->sections['A2-1'] = Section::factory()->published()->for($this->chapters['A2'])->create();

        $partB = Part::factory()->published()->for($this->certification)->create();
        $this->chapters['B1'] = Chapter::factory()->published()->for($partB)->create();
        $this->chapters['B-empty'] = Chapter::factory()->published()->for($partB)->create();
        $this->sections['B1-1'] = Section::factory()->published()->for($this->chapters['B1'])->create();
        $draftChapter = Chapter::factory()->draft()->for($partB)->create();
        $this->sections['B-draft-chapter'] = Section::factory()->published()->for($draftChapter)->create();

        $draftPart = Part::factory()->draft()->for($this->certification)->create();
        $this->sections['draft-part'] = Section::factory()->published()->for(Chapter::factory()->published()->for($draftPart)->create())->create();
    }

    private function read(string ...$keys): void
    {
        foreach ($keys as $key) {
            SectionProgress::factory()->forEnrollment($this->enrollment)->forSection($this->sections[$key])->create();
        }
    }

    public function test_nothing_completed(): void
    {
        $summary = $this->service->summarize($this->enrollment);

        $this->assertSame([4, 0, 0.0], [$summary->sectionsTotal, $summary->sectionsCompleted, $summary->sectionCompletionRatio]);
        $this->assertSame([4, 0, 0.0], [$summary->chaptersTotal, $summary->chaptersCompleted, $summary->chapterCompletionRatio]);
        $this->assertSame([2, 0, 0.0], [$summary->partsTotal, $summary->partsCompleted, $summary->partCompletionRatio]);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_partially_completed(): void
    {
        $this->read('A1-1', 'A1-2', 'B1-1');

        $summary = $this->service->summarize($this->enrollment);

        $this->assertSame([3, 0.75], [$summary->sectionsCompleted, $summary->sectionCompletionRatio]);
        $this->assertSame([2, 0.5], [$summary->chaptersCompleted, $summary->chapterCompletionRatio], 'A1 / B1 のみ完了');
        $this->assertSame([1, 0.5], [$summary->partsCompleted, $summary->partCompletionRatio], 'Part B のみ完了(A2 が未読)');
        $this->assertSame(0.75, $summary->overallCompletionRatio);
    }

    public function test_all_published_sections_completed(): void
    {
        $this->read('A1-1', 'A1-2', 'A2-1', 'B1-1');

        $summary = $this->service->summarize($this->enrollment);

        $this->assertSame([4, 1.0], [$summary->sectionsCompleted, $summary->sectionCompletionRatio]);
        // 公開 Section が 0 件の Chapter は完了扱いにならない
        $this->assertSame([3, 0.75], [$summary->chaptersCompleted, $summary->chapterCompletionRatio]);
        $this->assertSame([2, 1.0], [$summary->partsCompleted, $summary->partCompletionRatio]);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_reads_of_unpublished_content_and_other_enrollments_are_ignored(): void
    {
        $this->read('A1-draft', 'B-draft-chapter', 'draft-part');
        SectionProgress::factory()
            ->forEnrollment(Enrollment::factory()->for($this->certification)->learning()->create())
            ->forSection($this->sections['A2-1'])
            ->create();

        $summary = $this->service->summarize($this->enrollment);

        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0, $summary->partsCompleted);
    }

    public function test_ratio_is_rounded_to_four_decimals(): void
    {
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($certification)->learning()->create();
        $chapter = Chapter::factory()->published()->for(Part::factory()->published()->for($certification)->create())->create();
        $sections = Section::factory()->published()->for($chapter)->count(3)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sections[0])->create();

        $this->assertSame(0.3333, $this->service->summarize($enrollment)->sectionCompletionRatio);
    }

    public function test_certification_without_content_is_all_zero(): void
    {
        $enrollment = Enrollment::factory()->for(Certification::factory()->published()->create())->learning()->create();

        $summary = $this->service->summarize($enrollment);

        $this->assertSame([0, 0, 0, 0.0, 0.0, 0.0], [
            $summary->sectionsTotal, $summary->chaptersTotal, $summary->partsTotal,
            $summary->sectionCompletionRatio, $summary->chapterCompletionRatio, $summary->partCompletionRatio,
        ]);
    }

    public function test_batch_overall_ratios_match_summary_for_each_enrollment(): void
    {
        $this->read('A1-1', 'B1-1');
        $other = Enrollment::factory()->for($this->certification)->learning()->create();
        $empty = Enrollment::factory()->for(Certification::factory()->published()->create())->learning()->create();

        $ratios = $this->service->batchOverallRatios(collect([$this->enrollment, $other, $empty]));

        $this->assertSame([
            $this->enrollment->id => 0.5,
            $other->id => 0.0,
            $empty->id => 0.0,
        ], $ratios);
        $this->assertSame($this->service->summarize($this->enrollment)->overallCompletionRatio, $ratios[$this->enrollment->id]);
        $this->assertSame([], $this->service->batchOverallRatios(collect()));
    }

    public function test_completed_section_counts_by_chapter(): void
    {
        $this->read('A1-1', 'A1-draft', 'A2-1');
        $chapterIds = collect($this->chapters)->pluck('id');

        $counts = $this->service->completedSectionCountsByChapter($this->enrollment, $chapterIds);

        // 下書き Section の読了は数えず、読了 0 件の Chapter はキーを持たない
        $this->assertEqualsCanonicalizing([
            $this->chapters['A1']->id => 1,
            $this->chapters['A2']->id => 1,
        ], $counts);
        $this->assertSame([], $this->service->completedSectionCountsByChapter(null, $chapterIds));
        $this->assertSame([], $this->service->completedSectionCountsByChapter($this->enrollment, []));
    }
}
