<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Free tier PythonAnywhere (host services/delay-risk-api/) nonaktifkan web
 * app-nya kalau tidak ada yang login & klik reactivate secara berkala -
 * PythonAnywhere sendiri kirim email peringatan, tapi command ini jadi
 * jaring pengaman kedua lewat notifikasi in-app supaya tidak bergantung
 * cuma ke satu kanal (email itu gampang kelewat/masuk spam). Dijadwalkan
 * bulanan lewat routes/console.php.
 */
class RemindPythonAnywhereRenewal extends Command
{
    protected $signature = 'delay-risk-api:remind-renewal';
    protected $description = 'Kirim reminder in-app ke CEO/Admin supaya login PythonAnywhere & reactivate web app delay risk API';

    public function handle(): int
    {
        $recipients = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [UserRole::CEO->value, UserRole::Admin->value]))
            ->get();

        if ($recipients->isEmpty()) {
            $this->info('Tidak ada user CEO/Admin aktif, reminder dilewati.');
            return self::SUCCESS;
        }

        foreach ($recipients as $user) {
            NotificationService::notify(
                $user,
                'Reminder: Login PythonAnywhere',
                'pythonanywhere_renewal_reminder',
                'Free tier PythonAnywhere (API delay risk prediction) perlu login berkala supaya tidak nonaktif otomatis. Login ke pythonanywhere.com, buka tab Web, klik tombol reactivate/extend.',
            );
        }

        $this->info("Reminder terkirim ke {$recipients->count()} user.");

        return self::SUCCESS;
    }
}
