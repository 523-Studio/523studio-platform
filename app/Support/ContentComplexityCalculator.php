<?php

namespace App\Support;

class ContentComplexityCalculator
{
    // Batas Video berasal dari tertil durasi Video data latih model Delay Risk
    // (25,67 dan 40,33 detik). Durasi di sistem berupa detik bulat, jadi
    // batasnya menjadi <= 25, 26-40, dan > 40.
    public static function fromContentItem(?int $durationSeconds, ?int $slideCount, string $jenis): int
    {
        if ($jenis === 'Video' || $jenis === 'video') {
            if ($durationSeconds === null)
                return 2; // fallback netral kalau belum diisi
            if ($durationSeconds <= 25)
                return 1;
            if ($durationSeconds <= 40)
                return 2;
            return 3;
        }

        // Feed/Carousel
        if ($slideCount === null)
            return 1;
        if ($slideCount <= 1)
            return 1;
        if ($slideCount <= 4)
            return 2;
        return 3;
    }
}
