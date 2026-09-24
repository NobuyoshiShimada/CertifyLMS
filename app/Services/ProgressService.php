<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Services\Learning\ProgressSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の学習進捗(教材の Section / Chapter / Part / 資格レベルの完了数・完了率)を集計する Service。
 *
 * 集計仕様(全利用側で共通):
 * - 対象は公開済(Published)の Part → Chapter → Section のみ。上位階層が非公開なら配下も対象外
 * - Section は受講登録単位の読了記録(section_progresses)があれば完了
 * - Chapter / Part は配下の公開 Section が 1 件以上あり、すべて完了していれば完了(公開 Section 0 件は未完了)
 * - 完了率は 完了数 / 総数 を小数第 4 位で丸めた 0.0〜1.0(総数 0 は 0.0)。資格全体の完了率は Section 完了率
 */
class ProgressService
{
    /**
     * 1 受講登録の 4 階層(Section → Chapter → Part → 資格)の進捗サマリを返す。
     */
    public function summarize(Enrollment $enrollment): ProgressSummary
    {
        $totals = $this->fetchSectionTotals($enrollment);

        $partsTotal = Part::query()
            ->where('certification_id', $enrollment->certification_id)
            ->where('status', ContentStatus::Published->value)
            ->count();

        $chaptersTotal = Chapter::query()
            ->whereHas('part', function ($q) use ($enrollment) {
                $q->where('certification_id', $enrollment->certification_id)
                    ->where('status', ContentStatus::Published->value);
            })
            ->where('status', ContentStatus::Published->value)
            ->count();

        $sectionsTotal = (int) $totals->sections_total;
        $sectionsCompleted = (int) $totals->sections_completed;
        $sectionRatio = $this->ratio($sectionsCompleted, $sectionsTotal);

        $chaptersCompleted = $this->countCompletedChapters($enrollment);
        $partsCompleted = $this->countCompletedParts($enrollment);

        return new ProgressSummary(
            sectionsTotal: $sectionsTotal,
            sectionsCompleted: $sectionsCompleted,
            sectionCompletionRatio: $sectionRatio,
            chaptersTotal: $chaptersTotal,
            chaptersCompleted: $chaptersCompleted,
            chapterCompletionRatio: $this->ratio($chaptersCompleted, $chaptersTotal),
            partsTotal: $partsTotal,
            partsCompleted: $partsCompleted,
            partCompletionRatio: $this->ratio($partsCompleted, $partsTotal),
            overallCompletionRatio: $sectionRatio,
        );
    }

    /**
     * 複数の受講登録について、資格全体の完了率(Section 完了率)を 1 クエリでまとめて返す(N+1 回避)。
     *
     * @param Collection<int, Enrollment> $enrollments
     *
     * @return array<string, float> キーは Enrollment.id。公開 Section が無い受講登録は 0.0
     */
    public function batchOverallRatios(Collection $enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }

        $enrollmentIds = $enrollments->pluck('id')->all();
        $certificationIds = $enrollments->pluck('certification_id')->unique()->values()->all();

        $rows = DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->join('enrollments', 'enrollments.certification_id', '=', 'parts.certification_id')
            ->leftJoin('section_progresses', function ($join): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->on('section_progresses.enrollment_id', '=', 'enrollments.id');
            })
            ->whereIn('enrollments.id', $enrollmentIds)
            ->whereIn('parts.certification_id', $certificationIds)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->groupBy('enrollments.id')
            ->selectRaw('enrollments.id AS enrollment_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        $result = [];
        foreach ($enrollmentIds as $id) {
            $result[$id] = 0.0;
        }

        foreach ($rows as $row) {
            $result[(string) $row->enrollment_id] = $this->ratio((int) $row->done, (int) $row->total);
        }

        return $result;
    }

    /**
     * 指定 Chapter ごとの読了済(公開)Section 数を返す。受講登録が無い場合は空(= 全 Chapter 0 件扱い)。
     *
     * @param iterable<string> $chapterIds
     *
     * @return array<string, int> キーは Chapter.id。読了 0 件の Chapter はキーを持たない
     */
    public function completedSectionCountsByChapter(?Enrollment $enrollment, iterable $chapterIds): array
    {
        $chapterIds = collect($chapterIds)->values();

        if ($enrollment === null || $chapterIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('sections')
            ->join('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->whereIn('sections.chapter_id', $chapterIds)
            ->where('sections.status', ContentStatus::Published->value)
            ->groupBy('sections.chapter_id')
            ->selectRaw('sections.chapter_id AS chapter_id, COUNT(*) AS done')
            ->get();

        $completedByChapter = [];
        foreach ($rows as $row) {
            $completedByChapter[(string) $row->chapter_id] = (int) $row->done;
        }

        return $completedByChapter;
    }

    private function ratio(int $completed, int $total): float
    {
        return $total === 0 ? 0.0 : round($completed / $total, 4);
    }

    private function fetchSectionTotals(Enrollment $enrollment): object
    {
        return DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->selectRaw('COUNT(sections.id) AS sections_total, COUNT(section_progresses.id) AS sections_completed')
            ->first() ?? (object) ['sections_total' => 0, 'sections_completed' => 0];
    }

    private function countCompletedChapters(Enrollment $enrollment): int
    {
        // 公開済 Chapter のうち、配下の公開済 Section が全て読了済かを Chapter 単位で判定。
        $rows = DB::table('chapters')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('sections', function ($join) {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->groupBy('chapters.id')
            ->selectRaw('chapters.id AS chapter_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        return $this->countFullyCompleted($rows);
    }

    private function countCompletedParts(Enrollment $enrollment): int
    {
        $rows = DB::table('parts')
            ->leftJoin('chapters', function ($join) {
                $join->on('chapters.part_id', '=', 'parts.id')
                    ->where('chapters.status', ContentStatus::Published->value);
            })
            ->leftJoin('sections', function ($join) {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment) {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->groupBy('parts.id')
            ->selectRaw('parts.id AS part_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done')
            ->get();

        return $this->countFullyCompleted($rows);
    }

    /**
     * 集計行({total, done})のうち、公開 Section が 1 件以上ありすべて読了済の行数を数える。
     *
     * @param Collection<int, object> $rows
     */
    private function countFullyCompleted(Collection $rows): int
    {
        return $rows->filter(fn (object $row) => (int) $row->total > 0 && (int) $row->total === (int) $row->done)->count();
    }
}
