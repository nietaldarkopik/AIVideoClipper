<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\VideoNarrativeProvider;
use App\Services\AI\DTOs\VideoNarrativeResult;
use App\Services\AI\DTOs\VideoNarrativeSection;

/**
 * Zero-cost placeholder: deterministic filler Indonesian narrative naming the
 * topic, no real network call — same role as every other mock provider in this
 * app. Real generation needs AI_VIDEO_NARRATIVE_PROVIDER=nine_router.
 */
class MockVideoNarrativeProvider implements VideoNarrativeProvider
{
    public function generateNarrative(string $topic, array $sources): VideoNarrativeResult
    {
        $sections = [
            new VideoNarrativeSection(
                heading: 'Latar Belakang',
                narration_text: "(Mock) Berikut latar belakang mengenai \"{$topic}\" — naskah ini adalah teks placeholder, belum dihasilkan oleh AI sungguhan.",
                duration_estimate_seconds: 60,
            ),
            new VideoNarrativeSection(
                heading: 'Poin Penting',
                narration_text: "(Mock) Poin-poin penting seputar \"{$topic}\" akan muncul di sini setelah AI_VIDEO_NARRATIVE_PROVIDER diaktifkan.",
                duration_estimate_seconds: 90,
            ),
            new VideoNarrativeSection(
                heading: 'Penutup',
                narration_text: '(Mock) Itulah ringkasan sementara. Aktifkan provider AI sungguhan untuk naskah yang lebih lengkap dan orisinal.',
                duration_estimate_seconds: 30,
            ),
        ];

        $fullScript = "(Mock) Halo, hari ini kita bahas {$topic}.\n\n"
            . implode("\n\n", array_map(fn (VideoNarrativeSection $s) => $s->narration_text, $sections));

        return new VideoNarrativeResult(
            title: "(Mock) Naskah tentang {$topic}",
            hook: "(Mock) Halo, hari ini kita bahas {$topic}.",
            sections: $sections,
            full_script: $fullScript,
            suggested_description: "(Mock) Video ini membahas {$topic}.",
            suggested_hashtags: ['#mock', '#trending', '#kontenkreator'],
        );
    }
}
