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
 * sidebar yang hanya mengganti isi <main>. Skrip navigasi di layout hanya
 * boleh dipakai untuk halaman tanpa script inline, jadi tes ini memastikan
 * (1) penanda ada, (2) hanya halaman allowlist yang bertanda data-spa, dan
 * (3) halaman allowlist memang tidak punya <script> di dalam <main>.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ROUTES = [
        'profile.me',
        'dashboard',
        'content-plan.index',
        'team-performance.index',
        'user-management.index',
        'client-management.index',
    ];

    private const FULL_RELOAD_ROUTES = [
        'analytics',
        'production-workflow.index',
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

    public function test_only_allowlisted_sidebar_links_are_marked_spa(): void
    {
        $html = $this->actingAs($this->ceo())->get(route('dashboard'))->assertOk()->getContent();

        foreach (self::SPA_ROUTES as $name) {
            $path = parse_url(route($name), PHP_URL_PATH);
            $this->assertMatchesRegularExpression(
                '/<a\s[^>]*data-nav-path="'.preg_quote($path, '/').'"[^>]*\sdata-spa(\s|>)/s',
                $html,
                "Link sidebar {$name} harus bertanda data-spa."
            );
        }

        foreach (self::FULL_RELOAD_ROUTES as $name) {
            $path = parse_url(route($name), PHP_URL_PATH);
            $this->assertMatchesRegularExpression('/data-nav-path="'.preg_quote($path, '/').'"/', $html, "Link {$name} harus punya data-nav-path.");
            $this->assertDoesNotMatchRegularExpression(
                '/<a\s[^>]*data-nav-path="'.preg_quote($path, '/').'"[^>]*\sdata-spa(\s|>)/s',
                $html,
                "Link sidebar {$name} punya script inline, jadi TIDAK boleh data-spa."
            );
        }
    }

    public function test_allowlisted_pages_have_no_inline_script_inside_main(): void
    {
        $ceo = $this->ceo();

        foreach (self::SPA_ROUTES as $name) {
            $html = $this->actingAs($ceo)->get(route($name))->assertOk()->getContent();

            $this->assertStringNotContainsString(
                '<script',
                $this->mainOf($html),
                "Halaman {$name} punya <script> di dalam <main>; keluarkan dari allowlist data-spa."
            );
        }
    }
}
