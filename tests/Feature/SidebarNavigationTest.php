<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga kontrak perilaku sidebar: tidak blink di layar kecil dan navigasi
 * sidebar yang hanya mengganti isi <main>. Tes ini memastikan
 * (1) penanda ada, (2) semua link sidebar bertanda data-spa, dan (3) tiap halaman
 * sidebar merender <main id="app-main">.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const SIDEBAR_ROUTES = [
        'profile.me',
        'dashboard',
        'analytics',
        'content-plan.index',
        'production-workflow.index',
        'team-performance.index',
        'user-management.index',
        'client-management.index',
        'report.index',
        'settings',
    ];

    private function ceo(): User
    {
        $role = Role::firstOrCreate(['name' => UserRole::CEO->value]);
        foreach ([
            ['workflow', 'view'], ['dashboard', 'view'], ['analytics', 'view'],
            ['content_plan', 'view'], ['team_performance', 'view'],
            ['user_management', 'view'], ['client', 'view'], ['client', 'manage'],
            ['report', 'view'], ['settings', 'view'],
        ] as [$module, $action]) {
            $role->permissions()->syncWithoutDetaching(
                Permission::firstOrCreate(['module' => $module, 'action' => $action])->id
            );
        }
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function mainOf(string $html): string
    {
        $this->assertSame(1, preg_match('/<main id="app-main"[^>]*>(.*)<\/main>/s', $html, $m), 'Elemen <main id="app-main"> harus ada.');

        return $m[1];
    }

    public function test_sidebar_and_main_have_navigation_hooks(): void
    {
        $html = $this->actingAs($this->ceo())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<aside data-app-sidebar', $html);
        $this->assertStringContainsString("\$el.setAttribute('data-ready', '')", $html);
        $this->assertStringContainsString('aside[data-app-sidebar]:not([data-ready])', $html);
        $this->assertStringContainsString('id="app-main"', $html);
    }

    public function test_every_sidebar_link_supports_navigation_without_reload(): void
    {
        $html = $this->actingAs($this->ceo())->get(route('dashboard'))->assertOk()->getContent();

        foreach (self::SIDEBAR_ROUTES as $name) {
            $path = parse_url(route($name), PHP_URL_PATH);
            $this->assertMatchesRegularExpression(
                '/<a\s[^>]*data-nav-path="'.preg_quote($path, '/').'"[^>]*\sdata-spa(\s|>)/s',
                $html,
                "Link sidebar {$name} harus bertanda data-spa."
            );
        }
    }

    public function test_production_link_carries_mobile_query_rule(): void
    {
        $html = $this->actingAs($this->ceo())->get(route('dashboard'))->assertOk()->getContent();

        $path = preg_quote(parse_url(route('production-workflow.index'), PHP_URL_PATH), '/');
        $this->assertMatchesRegularExpression('/data-nav-path="'.$path.'"[^>]*data-spa-mobile-query="view=list"/s', $html);
    }

    public function test_every_sidebar_page_renders_main_container(): void
    {
        $ceo = $this->ceo();

        foreach (self::SIDEBAR_ROUTES as $name) {
            $html = $this->actingAs($ceo)->get(route($name))->assertOk()->getContent();
            $this->mainOf($html);
        }
    }
}
