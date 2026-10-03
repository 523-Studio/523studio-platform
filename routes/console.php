<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\RecomputeDelayRiskScores;
use App\Console\Commands\SendDelayRiskNotifications;
use App\Jobs\RecalculateMonthlyKpi;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting (Hostinger) tidak punya proses latar belakang permanen
// (Supervisor) - queue didorong lewat cron schedule:run tiap menit sebagai
// gantinya, --stop-when-empty supaya proses keluar begitu antrean kosong
// alih-alih jalan terus dan bentrok dengan invocation menit berikutnya.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(2);

// services/delay-risk-api/ di-host di PythonAnywhere free tier, yang
// nonaktifkan web app-nya kalau tidak ada yang login & reactivate berkala.
// Dua lapis jaring pengaman: reminder bulanan (proaktif) + health-check
// harian (reaktif, sadar duluan kalau reminder kelewat).
Schedule::command('delay-risk-api:remind-renewal')->monthlyOn(1, '09:00');
Schedule::command('delay-risk-api:health-check')->daily();

Schedule::command('analytics:detect-anomalies')->hourly();

Schedule::command(RecomputeDelayRiskScores::class)->dailyAt('10:00');
Schedule::command(SendDelayRiskNotifications::class)->dailyAt('08:00');
Schedule::command('workflow:update-overdue')->hourly();

// KPI Team Performance - satu-satunya jadwal otomatis (pemicu kedua adalah
// membuka halaman Team Performance saat hasil bulan berjalan belum ada/basi,
// lihat TeamPerformanceController). ShouldBeUnique pada job mencegah dobel
// kalau trigger halaman kebetulan jalan berdekatan dengan jadwal ini.
Schedule::job(new RecalculateMonthlyKpi(now()->startOfMonth()->toDateString()))->dailyAt('02:00');

// Long-lived token Instagram (OAuth per client) cuma berlaku ~60 hari -
// di-refresh otomatis tiap hari sebelum kadaluarsa (lihat RefreshInstagramTokens).
Schedule::command('analytics:refresh-instagram-tokens')->daily();

// TikTok - access_token JAUH lebih pendek umurnya dari Instagram (~24 jam,
// bukan ~60 hari), jadi refresh dijadwalkan harian juga (lihat
// RefreshTikTokTokens docblock - kontrak refresh token TikTok beda total
// dari Instagram, refresh_token terpisah + dirotasi tiap dipakai).
Schedule::command('analytics:refresh-tiktok-tokens')->daily();

// Analytics V2 Phase B - "AUTO SYNC, ONCE PER 24 HOURS" - SATU command
// terkonsolidasi (lihat AutoSyncAnalytics docblock) menggantikan 3 baris
// jadwal lama (analytics:sync-all-instagram, analytics:sync-all-instagram-
// audience, analytics:sync-all-tiktok - command-nya MASIH ADA & tetap bisa
// dijalankan manual buat debugging, cuma JADWAL OTOMATISNYA yang dipindah
// ke sini biar tidak dispatch dobel). Command baru ini manggil
// AnalyticsSyncOrchestrator::dispatch() PERSIS pipeline yang sama dengan
// tombol manual "Perbarui Data" (trigger='scheduled' saja yang beda) -
// duplicate-protection & AnalyticsSyncRun/Task tracking otomatis ikut.
// Jam dikonfigurasi lewat config('analytics.auto_sync_time')
// (ANALYTICS_AUTO_SYNC_TIME di .env), BUKAN hardcoded di sini.
//
// PASS 1B (Langkah "SCHEDULER TIMEZONE") - ->timezone() DIEKSPLISITKAN
// (bukan diam-diam mengandalkan default implisit) walau SECARA FAKTA
// Laravel bootstrap SUDAH memanggil date_default_timezone_set(config(
// 'app.timezone')) di setiap request/command termasuk schedule:run
// sendiri, jadi tanpa baris ini pun evaluasi jadwal SUDAH konsisten pakai
// config('app.timezone') (Asia/Jakarta, default config/app.php - TIDAK
// bergantung ke timezone OS server manapun app ini di-hosting). Baris ini
// murni membuat kebergantungan itu EKSPLISIT/self-documenting - kalau
// config('app.timezone') pernah berubah di masa depan, satu sumber
// kebenaran yang sama otomatis ikut, TIDAK ADA jam kedua yang bisa drift.
Schedule::command('analytics:auto-sync')
    ->dailyAt(config('analytics.auto_sync_time'))
    ->timezone(config('app.timezone'));

// Retention rolling content_metric_snapshots (audit sync horizon +
// snapshot retention) - command SUDAH ADA & struktural benar sejak lama
// (lihat PruneContentMetricSnapshots buat semantik inclusive/exclusive
// cutoff lengkap), tapi jadwal otomatisnya sempat SENGAJA dinonaktifkan
// sampai ada keputusan retention policy eksplisit.
//
// RETENTION POLICY DECISION (Langkah audit "sync bulan tertentu tidak
// ada loading/hilang setelah 1 minggu?") - keputusan eksplisit: retensi
// 120 hari rolling DIAKTIFKAN sebagai jawaban ke kekhawatiran storage
// tumbuh tak terbatas, TAPI cakupannya TETAP HANYA content_metric_
// snapshots (histori observasi harian) - BUKAN dibuat sebagai TTL 1
// minggu, dan BUKAN menghapus ContentMetric (angka performa terkini)
// ataupun InstagramMediaSnapshot/TikTokVideoSnapshot (identitas konten).
// Konten yang di-backfill lewat "Sinkronisasi Konten Historis" (bulan
// tertentu) TETAP permanen kelihatan di sistem persis seperti konten
// dari sync 90 hari rolling - keduanya menulis ke tabel yang sama, tidak
// dibedakan asal sync-nya, jadi retensi ini berlaku rata untuk semua
// snapshot tanpa perlu tahu dari jalur mana baris itu berasal. Deletion
// snapshot TIDAK BISA direkonstruksi dari API manapun (lihat
// config/analytics.php) - kalau kebutuhan historical-reporting jangka
// panjang berubah nanti, naikkan content_metric_snapshot_retention_days,
// JANGAN menonaktifkan baris ini lagi secara diam-diam.
Schedule::command('analytics:prune-content-metric-snapshots')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'));

// PENTING - dependency operasional yang harus disetup terpisah, BUKAN
// otomatis aktif cuma karena baris ini ada:
// 1. Baris Schedule:: di file ini cuma "terdaftar", baru benar-benar jalan
//    kalau ada cron/Windows Task Scheduler yang memanggil `php artisan
//    schedule:run` tiap menit - belum ada di lingkungan dev ini (dicek
//    langsung, tidak ada Task Scheduler entry apapun untuk project ini).
// 2. Job SyncInstagramAnalyticsJob di atas diproses lewat baris
//    `queue:work --stop-when-empty` di awal file ini (dipicu cron yang sama
//    dengan poin 1, bukan proses worker terpisah) - dipilih karena shared
//    hosting produksi (Hostinger) tidak punya process manager (Supervisor).
//    Kalau pindah ke environment yang punya Supervisor/NSSM, baris itu boleh
//    diganti proses worker permanen untuk latency lebih rendah.
