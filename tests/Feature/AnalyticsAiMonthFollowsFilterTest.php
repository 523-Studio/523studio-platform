<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AiStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulan analisis AI Strategy MENGIKUTI filter periode utama halaman
 * Analytics (tidak ada lagi filter bulan terpisah di panel AI).
 */
class AnalyticsAiMonthFollowsFilterTest extends TestCase
{
    use RefreshDatabase;

    private function setupManager(): array
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create(['client_category_id' => $category->id, 'name' => 'Test Client '.uniqid(), 'status' => 'active']);
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $role->permissions()->attach([
            Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view'])->id,
            Permission::firstOrCreate(['module' => 'analytics', 'action' => 'manage'])->id,
        ]);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return [$client, $manager];
    }

    public function test_badge_shows_month_from_main_filter_and_generate_sends_same_month(): void
    {
        [$client, $manager] = $this->setupManager();
        $month = now()->subMonth()->startOfMonth();

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'month', 'month' => $month->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertSee('data-testid="ai-analysis-badge"', false);
        $response->assertSee($month->translatedFormat('F Y'));
        $response->assertSee('name="analysis_month" value="'.$month->format('Y-m').'"', false);
        $response->assertDontSee('id="ai-analysis-month"', false);
        $response->assertDontSee('data-testid="ai-range-notice"', false);
    }

    public function test_range_mode_uses_end_month_and_disables_generate(): void
    {
        [$client, $manager] = $this->setupManager();
        $end = now()->subMonth()->endOfMonth();
        $start = $end->copy()->subDays(9);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'period_mode' => 'custom',
            'date_from' => $start->toDateString(), 'date_to' => $end->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('data-testid="ai-range-notice"', false);
        $response->assertSee($end->translatedFormat('F Y'));
        // Form generate TIDAK dirender sama sekali di mode Rentang.
        $response->assertDontSee('name="analysis_month"', false);
    }

    public function test_legacy_analysis_month_link_drives_main_filter(): void
    {
        [$client, $manager] = $this->setupManager();
        $month = now()->subMonths(2)->startOfMonth();
        $window = app(AiStrategyService::class)->resolveMonthWindow($month->format('Y-m'));
        AiStrategyInsight::create([
            'client_id' => $client->id, 'platform_id' => null, 'generated_by' => $manager->id,
            'period_start' => $window['start'], 'period_end' => $window['end'],
            'summary' => 'Ringkasan dua bulan lalu.', 'action_items' => [], 'status' => 'completed',
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'client_id' => $client->id, 'analysis_month' => $month->format('Y-m'),
        ]));

        $response->assertOk();
        $response->assertSee('Ringkasan dua bulan lalu.');
        $response->assertSee('value="'.$month->format('Y-m').'"', false);
    }
}
