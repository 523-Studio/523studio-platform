<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Models\User;
use App\Services\DelayRiskPredictionService;
use App\Support\ContentComplexityCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Input yang dikirim ke model Delay Risk harus bermakna sama dengan data
 * latihnya: batas kompleksitas Video dari tertil data latih, dan beban kerja
 * = konten LAIN milik PIC yang sama dengan deadline di minggu kalender yang
 * sama (bukan jumlah task aktif).
 *
 * Minggu acuan di test ini: Senin 2026-10-12 00:00 sampai Minggu 2026-10-18
 * 23:59:59.
 */
class DelayRiskFeatureInputTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'http://delay-risk.test/predict';

    private const FEATURE_KEYS = [
        'client_category',
        'pillar',
        'content_complexity',
        'workload_pic_same_week',
        'current_status',
        'revision_count',
        'days_in_current_status',
    ];

    private ?Client $client = null;
    private ?ContentPlan $plan = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.delay_risk.url' => self::API_URL,
            'services.delay_risk.key' => 'test-key',
        ]);
    }

    private function fakeApi(string $riskLevel = 'low', int $riskScore = 10): void
    {
        Http::fake([
            self::API_URL => fn ($request) => Http::response(
                collect($request['items'])->map(fn ($row) => [
                    'content_item_id' => $row['content_item_id'],
                    'risk_score' => $riskScore,
                    'risk_level' => $riskLevel,
                ])->all(),
                200
            ),
        ]);
    }

    private function pic(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    private function item(?User $pic, string $deadlineAt, string $status = 'in_progress', array $overrides = []): ContentItem
    {
        $this->client ??= Client::create([
            'client_category_id' => ClientCategory::firstOrCreate(['name' => 'UMKM'])->id,
            'name' => 'Klien Delay Risk '.uniqid(),
            'status' => 'active',
        ]);

        $this->plan ??= ContentPlan::create([
            'client_id' => $this->client->id,
            'created_by' => User::factory()->create()->id,
            'month' => 10,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $item = ContentItem::create(array_merge([
            'content_plan_id' => $this->plan->id,
            'client_id' => $this->client->id,
            'content_pillar_id' => ContentPillar::firstOrCreate(['name' => 'Hard Selling'])->id,
            'content_type_id' => ContentType::firstOrCreate(['name' => 'Video'])->id,
            'title' => 'Konten Delay Risk '.uniqid(),
            'deadline_at' => $deadlineAt,
        ], $overrides));

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => $pic?->id,
            'current_status' => $status,
        ]);

        if ($pic) {
            ContentItemAssignment::create([
                'content_item_id' => $item->id,
                'user_id' => $pic->id,
                'assignment_role' => 'primary',
            ]);
        }

        return $item;
    }

    /**
     * Skor sebelumnya dengan snapshot fitur tertentu. $featureVersion null =
     * snapshot lama (sebelum ada key feature_version, workload masih bermakna
     * "task aktif").
     */
    private function previousScore(ContentItem $item, ?int $workload, ?int $featureVersion = 2): void
    {
        $snapshot = [
            'client_category' => 'UMKM',
            'pillar' => 'Hard Selling',
            'content_complexity' => 1,
            'workload_pic_same_week' => $workload,
            'current_status' => 'in_progress',
            'revision_count' => 0,
            'days_in_current_status' => 0,
        ];

        if ($featureVersion !== null) {
            $snapshot['feature_version'] = $featureVersion;
        }

        DelayRiskScore::create([
            'content_item_id' => $item->id,
            'risk_score' => 20,
            'risk_level' => 'low',
            'top_factor' => 'Skor sebelumnya',
            'features_snapshot' => $snapshot,
        ]);
    }

    private function latestScore(ContentItem $item): DelayRiskScore
    {
        $this->assertSame(1, app(DelayRiskPredictionService::class)->predictForItems([$item->id]));

        return DelayRiskScore::where('content_item_id', $item->id)->latest('id')->firstOrFail();
    }

    private function workloadOf(ContentItem $item): ?int
    {
        $snapshot = $this->latestScore($item)->features_snapshot;
        $this->assertArrayHasKey('workload_pic_same_week', $snapshot);

        return $snapshot['workload_pic_same_week'];
    }

    // =================================================================
    // ContentComplexityCalculator
    // =================================================================

    public function test_video_complexity_follows_training_tertile_boundaries(): void
    {
        $this->assertSame(1, ContentComplexityCalculator::fromContentItem(25, null, 'Video'));
        $this->assertSame(2, ContentComplexityCalculator::fromContentItem(26, null, 'Video'));
        $this->assertSame(2, ContentComplexityCalculator::fromContentItem(40, null, 'Video'));
        $this->assertSame(3, ContentComplexityCalculator::fromContentItem(41, null, 'Video'));
        $this->assertSame(2, ContentComplexityCalculator::fromContentItem(null, null, 'Video'), 'Durasi kosong harus jatuh ke nilai netral.');
    }

    public function test_non_video_complexity_boundaries_are_unchanged(): void
    {
        $this->assertSame(1, ContentComplexityCalculator::fromContentItem(null, 1, 'Feed'));
        $this->assertSame(2, ContentComplexityCalculator::fromContentItem(null, 2, 'Feed'));
        $this->assertSame(2, ContentComplexityCalculator::fromContentItem(null, 4, 'Feed'));
        $this->assertSame(3, ContentComplexityCalculator::fromContentItem(null, 5, 'Feed'));
        $this->assertSame(1, ContentComplexityCalculator::fromContentItem(null, null, 'Feed'));
    }

    // =================================================================
    // workload_pic_same_week
    // =================================================================

    public function test_workload_counts_other_items_of_same_pic_due_in_same_week(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-12 09:00:00');
        $this->item($pic, '2026-10-16 17:00:00');

        $this->assertSame(2, $this->workloadOf($item), 'Item itu sendiri tidak boleh ikut terhitung.');
    }

    public function test_workload_ignores_items_due_in_another_week(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-15 10:00:00');
        $this->item($pic, '2026-10-07 10:00:00');
        $this->item($pic, '2026-10-21 10:00:00');

        $this->assertSame(1, $this->workloadOf($item));
    }

    public function test_workload_ignores_items_of_another_pic(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-15 10:00:00');
        $this->item($this->pic(), '2026-10-15 10:00:00');
        $this->item(null, '2026-10-15 10:00:00');

        $this->assertSame(1, $this->workloadOf($item));
    }

    public function test_workload_ignores_cancelled_items_but_counts_every_other_status(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-15 10:00:00', 'cancelled');
        // Data latih menghitung semua konten di planner tanpa melihat status,
        // jadi yang sudah tayang maupun yang masih draf tetap terhitung.
        $this->item($pic, '2026-10-15 10:00:00', 'uploaded');
        $this->item($pic, '2026-10-15 10:00:00', 'draft');

        $this->assertSame(2, $this->workloadOf($item));
    }

    public function test_workload_counts_an_item_once_even_with_several_assignments(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00');
        $other = $this->item($pic, '2026-10-15 10:00:00');
        ContentItemAssignment::create([
            'content_item_id' => $other->id,
            'user_id' => $pic->id,
            'assignment_role' => 'designer',
        ]);

        $this->assertSame(1, $this->workloadOf($item));
    }

    public function test_workload_is_null_when_item_has_no_pic(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $item = $this->item(null, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-15 10:00:00');

        $this->assertNull($this->workloadOf($item), 'Tanpa PIC harus null (diisi median oleh pipeline model), bukan 0.');
    }

    public function test_workload_is_null_when_item_has_no_deadline(): void
    {
        $pic = $this->pic();
        $stored = $this->item($pic, '2026-10-14 10:00:00');
        $this->item($pic, '2026-10-15 10:00:00');

        // Kolom deadline_at NOT NULL di skema, jadi item tanpa deadline tidak
        // bisa disimpan - cabang pengaman ini diuji lewat model di memori.
        $item = ContentItem::with(['client.category', 'contentPillar', 'contentType', 'workflow.currentPic'])->findOrFail($stored->id);
        $item->deadline_at = null;

        $features = (new \ReflectionMethod(DelayRiskPredictionService::class, 'buildFeatures'))
            ->invoke(app(DelayRiskPredictionService::class), $item);

        $this->assertArrayHasKey('workload_pic_same_week', $features);
        $this->assertNull($features['workload_pic_same_week'], 'Tanpa deadline harus null, bukan 0.');
    }

    public function test_sunday_2359_and_monday_0000_fall_in_different_weeks(): void
    {
        $this->fakeApi();
        $pic = $this->pic();

        $monday = $this->item($pic, '2026-10-12 00:00:00');
        $sunday = $this->item($pic, '2026-10-18 23:59:00');
        $nextMonday = $this->item($pic, '2026-10-19 00:00:00');

        $this->assertSame(1, $this->workloadOf($monday), 'Senin 00:00 dan Minggu 23:59 pada minggu yang sama saling terhitung.');
        $this->assertSame(1, $this->workloadOf($sunday));
        $this->assertSame(0, $this->workloadOf($nextMonday), 'Senin 00:00 berikutnya sudah minggu yang berbeda.');
    }

    // =================================================================
    // Payload ke API
    // =================================================================

    public function test_payload_still_sends_content_item_id_plus_the_same_seven_features(): void
    {
        $this->fakeApi();
        $item = $this->item($this->pic(), '2026-10-14 10:00:00');

        $score = $this->latestScore($item);

        // Penanda versi makna fitur hanya disimpan di snapshot, tidak dikirim.
        $this->assertSame(2, $score->features_snapshot['feature_version']);

        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];

        $this->assertSame(self::API_URL, $request->url());
        $this->assertCount(1, $request['items']);
        $this->assertSame(
            array_merge(['content_item_id'], self::FEATURE_KEYS),
            array_keys($request['items'][0])
        );
        $this->assertSame($item->id, $request['items'][0]['content_item_id']);
    }

    public function test_unreachable_api_is_skipped_instead_of_throwing(): void
    {
        Http::fake([self::API_URL => Http::failedConnection()]);
        $item = $this->item($this->pic(), '2026-10-14 10:00:00');

        $this->assertSame(0, app(DelayRiskPredictionService::class)->predictForItems([$item->id]));
        $this->assertSame(0, DelayRiskScore::count());
    }

    // =================================================================
    // Teks penjelas beban kerja
    // =================================================================

    public function test_workload_increase_text_uses_same_week_deadline_wording(): void
    {
        $this->fakeApi('medium', 55);
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00', 'in_progress', ['estimated_duration_seconds' => 20]);
        foreach (range(1, 6) as $i) {
            $this->item($pic, '2026-10-15 10:00:00');
        }

        $this->previousScore($item, 2);

        $topFactor = $this->latestScore($item)->top_factor;

        $this->assertSame('Beban kerja PIC naik dari 2 ke 6 konten ber-deadline minggu ini', $topFactor);
        $this->assertStringStartsWith('Beban kerja', $topFactor);
        $this->assertLessThanOrEqual(255, mb_strlen($topFactor));
    }

    public function test_workload_is_not_compared_against_a_snapshot_with_the_old_meaning(): void
    {
        $this->fakeApi('medium', 55);
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00', 'in_progress', ['estimated_duration_seconds' => 20]);
        foreach (range(1, 6) as $i) {
            $this->item($pic, '2026-10-15 10:00:00');
        }

        // Snapshot lama: 2 "task aktif", bukan 2 konten ber-deadline minggu ini.
        $this->previousScore($item, 2, featureVersion: null);

        $this->assertStringNotContainsString('naik dari', $this->latestScore($item)->top_factor);
    }

    public function test_workload_not_worrying_text_uses_same_week_deadline_wording(): void
    {
        $this->fakeApi('low', 10);
        $pic = $this->pic();

        // Kompleksitas 1 (rasio 0,33) supaya beban kerja 3 (rasio 0,375)
        // jadi faktor paling menonjol.
        $item = $this->item($pic, '2026-10-14 10:00:00', 'in_progress', ['estimated_duration_seconds' => 20]);
        foreach (range(1, 3) as $i) {
            $this->item($pic, '2026-10-15 10:00:00');
        }

        $topFactor = $this->latestScore($item)->top_factor;

        $this->assertSame('Beban kerja PIC (3 konten lain ber-deadline minggu ini) - belum mengkhawatirkan', $topFactor);
        $this->assertStringStartsWith('Beban kerja', $topFactor);
        $this->assertStringNotContainsString('task aktif', $topFactor);
        $this->assertLessThanOrEqual(255, mb_strlen($topFactor));
    }

    public function test_unknown_previous_workload_is_never_reported_as_an_increase(): void
    {
        $this->fakeApi('medium', 55);
        $pic = $this->pic();

        $item = $this->item($pic, '2026-10-14 10:00:00', 'in_progress', ['estimated_duration_seconds' => 20]);
        foreach (range(1, 6) as $i) {
            $this->item($pic, '2026-10-15 10:00:00');
        }

        // Skor sebelumnya dihitung saat item belum punya PIC (workload null).
        $this->previousScore($item, null);

        $this->assertStringNotContainsString('naik dari', $this->latestScore($item)->top_factor);
    }

    public function test_unknown_current_workload_is_never_reported_as_an_increase(): void
    {
        $this->fakeApi('medium', 55);
        $item = $this->item(null, '2026-10-14 10:00:00', 'in_progress', ['estimated_duration_seconds' => 20]);

        $this->previousScore($item, 7);

        $this->assertStringStartsNotWith('Beban kerja', $this->latestScore($item)->top_factor);
    }
}
