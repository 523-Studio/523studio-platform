<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Jaring pengaman kedua di luar RemindPythonAnywhereRenewal - kalau reminder
 * bulanan kelewat dan web app-nya benar-benar nonaktif, command ini yang
 * pertama sadar (cek /health harian) dan langsung kirim notifikasi urgent,
 * bukan nunggu ada yang ngeh dari fitur delay risk yang diam-diam berhenti
 * update. Dijadwalkan harian lewat routes/console.php.
 */
class CheckDelayRiskApiHealth extends Command
{
    protected $signature = 'delay-risk-api:health-check';
    protected $description = 'Cek endpoint /health API delay risk, kirim notifikasi urgent kalau tidak bisa dihubungi';

    public function handle(): int
    {
        $predictUrl = config('services.delay_risk.url');

        if (! $predictUrl) {
            $this->info('DELAY_RISK_API_URL belum di-set, health-check dilewati.');
            return self::SUCCESS;
        }

        $healthUrl = Str::endsWith($predictUrl, '/predict')
            ? Str::replaceLast('/predict', '/health', $predictUrl)
            : rtrim($predictUrl, '/').'/health';

        try {
            $response = Http::timeout(10)->get($healthUrl);
            $healthy = $response->successful() && $response->json('status') === 'ok';
        } catch (\Throwable) {
            $healthy = false;
        }

        if ($healthy) {
            $this->info('API delay risk sehat.');
            return self::SUCCESS;
        }

        $alreadySentToday = Notification::where('type', 'delay_risk_api_down')
            ->whereDate('created_at', now())
            ->exists();

        if ($alreadySentToday) {
            $this->info('API down, tapi notifikasi hari ini sudah pernah dikirim.');
            return self::SUCCESS;
        }

        $recipients = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [UserRole::CEO->value, UserRole::Admin->value]))
            ->get();

        foreach ($recipients as $user) {
            NotificationService::notify(
                $user,
                'API Delay Risk Tidak Bisa Dihubungi',
                'delay_risk_api_down',
                'Endpoint /health API delay risk gagal dihubungi. Kemungkinan web app PythonAnywhere nonaktif karena belum login berkala, cek pythonanywhere.com dan klik reactivate.',
            );
        }

        $this->warn("API down, notifikasi terkirim ke {$recipients->count()} user.");

        return self::SUCCESS;
    }
}
