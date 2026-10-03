<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Menjalankan antrean sebentar SESUDAH respons terkirim ke browser.
 *
 * Latar belakang: di shared hosting (Hostinger) tidak ada worker permanen,
 * antrean hanya jalan kalau cron schedule:run aktif. Kalau cron telat atau
 * tidak jalan, sinkronisasi menggantung di "antre" tanpa batas. Dengan
 * kicker ini, setiap kali pengguna menekan "Perbarui Data" atau halaman
 * memantau progres, antrean didorong beberapa detik lewat request itu
 * sendiri, jadi sinkronisasi tetap maju walau cron bermasalah.
 *
 * Kunci cache mencegah dua kicker (atau kicker dan cron) menjalankan worker
 * bersamaan.
 */
class QueueKicker
{
    public static function kick(int $maxSeconds = 25): void
    {
        if (config('queue.default') === 'sync' || app()->runningUnitTests()) {
            return;
        }

        app()->terminating(function () use ($maxSeconds) {
            try {
                $lock = Cache::lock('queue-kicker', $maxSeconds + 15);

                if (! $lock->get()) {
                    return;
                }

                @set_time_limit($maxSeconds + 30);
                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--max-time' => $maxSeconds,
                    '--tries' => 3,
                    '--quiet' => true,
                ]);

                $lock->release();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
