<?php

// Fixture statis untuk TeamClientSeeder - disalin dari state database dev
// (hasil real `content-planner:import-team` dari sheet GUIDE Content Planner
// lama + 14 Client yang sudah dibuat manual lewat UI), sudah dibersihkan
// dari akun testing/duplikat:
// - 'ahdarindang@gmail.com' (akun kedua Ahda, status 'invited', tidak pernah
//   dipakai) TIDAK diikutkan.
// - 'ghazifadhlullah31@gmail.com' (dipakai CEO role cuma untuk kemudahan
//   testing lokal, bukan roster resmi) TIDAK diikutkan.
// - Akun CEO resmi '523 Studio' (hello523studio@gmail.com) TIDAK diikutkan
//   di sini - itu tanggung jawab RoleSeeder (bootstrap wajib, bukan bagian
//   data historis).
//
// Client & Role direferensikan lewat NATURAL KEY (nama), bukan id - resolve
// ke id dilakukan oleh TeamClientSeeder saat run, supaya tidak bergantung
// pada urutan/nilai id yang beda antar environment.
return [
    'clients' => [
        ['name' => 'Yasmin International Boarding School', 'category' => 'Institusi'],
        ['name' => 'PT Guna Griya Abadi', 'category' => 'Korporat'],
        ['name' => 'LuxSuits', 'category' => 'UMKM'],
        ['name' => 'Top Scorer Arena', 'category' => 'UMKM'],
        ['name' => 'FTI UNAND', 'category' => 'Institusi'],
        ['name' => 'Metro Indonesian Software', 'category' => 'UMKM'],
        ['name' => 'Darwin', 'category' => 'UMKM'],
        ['name' => 'Uthie Cake', 'category' => 'UMKM'],
        ['name' => 'Sato', 'category' => 'UMKM'],
    ],

    'users' => [

    ],
];
