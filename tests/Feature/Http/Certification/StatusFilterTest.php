<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-11 回帰テスト: 資格マスタ一覧の状態フィルタが、公開中 / 下書き / アーカイブの 3 状態すべてで
 * 指定した状態の資格のみを返し、未指定なら全状態を返すことを検証する。
 */
class StatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, Certification> */
    private array $byStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->byStatus = [
            CertificationStatus::Published->value => Certification::factory()->published()->create(),
            CertificationStatus::Draft->value => Certification::factory()->draft()->create(),
            CertificationStatus::Archived->value => Certification::factory()->archived()->create(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listedIds(array $query): array
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.certifications.index', $query))
            ->assertOk()
            ->viewData('certifications')
            ->pluck('id')
            ->all();
    }

    /**
     * @dataProvider statusProvider
     */
    public function test_status_filter_returns_only_selected_status(CertificationStatus $status): void
    {
        $ids = $this->listedIds(['status' => $status->value]);

        $this->assertSame([$this->byStatus[$status->value]->id], $ids);
    }

    /**
     * @return array<string, array{CertificationStatus}>
     */
    public static function statusProvider(): array
    {
        return [
            '公開中' => [CertificationStatus::Published],
            '下書き' => [CertificationStatus::Draft],
            'アーカイブ' => [CertificationStatus::Archived],
        ];
    }

    public function test_no_status_filter_returns_all_statuses(): void
    {
        $ids = $this->listedIds([]);

        $this->assertEqualsCanonicalizing(
            array_map(fn (Certification $c) => $c->id, array_values($this->byStatus)),
            $ids,
        );
    }
}
