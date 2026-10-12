<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TeamClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamClientSeeder menggantikan ContentPlannerSeeder/
 * ContentPlannerPrerequisiteSeeder untuk instalasi baru - hanya membawa
 * Client asli yang masih aktif, TANPA histori Content Plan/Content Item
 * sama sekali.
 *
 * Roster staf SENGAJA kosong di fixture: akun didaftarkan manual lewat
 * Kelola Pengguna, bukan pre-seeded pakai Gmail pribadi dev.
 */
class TeamClientSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedTeamAndClients(): void
    {
        (new RoleSeeder)->run();
        (new MasterDataSeeder)->run();
        (new TeamClientSeeder)->run();
    }

    public function test_seeds_real_clients_without_any_content_plan_or_item(): void
    {
        $this->seedTeamAndClients();

        $this->assertSame(9, Client::count());
        $this->assertTrue(Client::where('name', 'Top Scorer Arena')->exists());
        $this->assertTrue(Client::where('name', 'Metro Indonesian Software')->exists());

        $this->assertSame(0, \App\Models\ContentPlan::count());
        $this->assertSame(0, \App\Models\ContentItem::count());
    }

    public function test_excludes_clients_that_are_no_longer_active(): void
    {
        $this->seedTeamAndClients();

        foreach (['Oleo', 'Tatitatu', 'Alfa Sport', 'Odamilk', 'Labertha', 'Indonesian Software'] as $name) {
            $this->assertFalse(Client::where('name', $name)->exists(), "Client '{$name}' tidak boleh ikut di-seed.");
        }
    }

    public function test_does_not_preseed_any_staff_account(): void
    {
        (new RoleSeeder)->run();
        (new MasterDataSeeder)->run();
        // RoleSeeder sudah membuat akun CEO - yang diuji di sini hanya bahwa
        // TeamClientSeeder tidak menambah akun apa pun di atasnya.
        $usersBefore = User::count();

        (new TeamClientSeeder)->run();

        $this->assertSame($usersBefore, User::count());
    }

    public function test_excludes_testing_only_and_duplicate_accounts(): void
    {
        $this->seedTeamAndClients();

        $this->assertFalse(User::where('email', 'ghazifadhlullah31@gmail.com')->exists());
        $this->assertFalse(User::where('email', 'ahdarindang@gmail.com')->exists());
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $this->seedTeamAndClients();
        (new TeamClientSeeder)->run();

        $this->assertSame(9, Client::count());
        $this->assertSame(1, Client::where('name', 'Top Scorer Arena')->count());
    }
}
