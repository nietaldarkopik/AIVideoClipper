<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\VideoNarrativeProvider;
use App\Services\AI\DTOs\VideoNarrativeResult;
use App\Services\AI\DTOs\VideoNarrativeSection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real long-form Indonesian narrative generation via a self-hosted 9Router
 * instance — same OpenAI-compatible /chat/completions call as
 * NineRouterReactionScriptProvider (which this mirrors), just a different,
 * much longer-form prompt and JSON shape.
 */
class NineRouterVideoNarrativeProvider implements VideoNarrativeProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    private const MAX_SOURCE_CHARS = 4000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
    ) {
    }

    public function generateNarrative(string $topic, array $sources): VideoNarrativeResult
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_VIDEO_NARRATIVE_MODEL (or NINE_ROUTER_MODEL) is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models for the model ids your 9Router instance actually has credentials for.'
            );
        }

        $context = $this->buildContext($topic, $sources);
        $systemPrompt = $this->systemPrompt();
        $span = $this->aiLogger()->start('video_narrative', 'nine_router', $this->model, $systemPrompt . "\n\n" . $context);

        // A long-form narrative from several full articles' worth of context is a
        // genuinely slow generation — observed ~285s end-to-end against a combo
        // model that routed to a reasoning model (claude-opus-5) for 7 sections of
        // real multi-source content. This runs inside a queued job the user watches
        // via a progress bar, not a live request, so a generous per-attempt timeout
        // is fine; 450s leaves real margin above the observed ~285s. Only 1 retry
        // (not the usual 2): retrying a call that's already this slow just
        // multiplies the wait for little benefit.
        $request = Http::timeout(450)
            ->retry(1, 2000)
            ->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
            'model' => $this->model,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.8,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $context],
            ],
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router video narrative generation failed: ' . $response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw) ?? [];

        $title = trim((string) ($parsed['title'] ?? ''));
        $hook = trim((string) ($parsed['hook'] ?? ''));

        $sectionsRaw = is_array($parsed['sections'] ?? null) ? $parsed['sections'] : [];
        $sections = array_values(array_filter(array_map(function ($s) {
            if (! is_array($s) || empty($s['heading']) || empty($s['narration_text'])) {
                return null;
            }

            return new VideoNarrativeSection(
                heading: (string) $s['heading'],
                narration_text: (string) $s['narration_text'],
                duration_estimate_seconds: (int) ($s['duration_estimate_seconds'] ?? 60),
            );
        }, $sectionsRaw)));

        if ($title === '' || empty($sections)) {
            throw new RuntimeException('9Router video narrative generation returned an incomplete result.');
        }

        // full_script is assembled here rather than asked of the model: having it
        // write the same content twice (once per-section, once concatenated) roughly
        // doubled the output length it had to generate, which was the direct cause
        // of repeated timeouts (observed >300s with 0 bytes back on real multi-source
        // topics) — concatenating deterministically in PHP is also guaranteed to
        // match the sections exactly, with no risk of the model's copy drifting.
        $fullScript = trim($hook . "\n\n" . implode("\n\n", array_map(
            fn (VideoNarrativeSection $s) => $s->narration_text,
            $sections
        )));

        $hashtagsRaw = is_array($parsed['suggested_hashtags'] ?? null) ? $parsed['suggested_hashtags'] : [];

        return new VideoNarrativeResult(
            title: $title,
            hook: $hook,
            sections: $sections,
            full_script: $fullScript,
            suggested_description: trim((string) ($parsed['suggested_description'] ?? '')),
            suggested_hashtags: array_values(array_filter(array_map('strval', $hashtagsRaw))),
        );
    }

    private function buildContext(string $topic, array $sources): string
    {
        $context = "Topik: {$topic}\n\nSumber-sumber riset yang sudah dikumpulkan:\n";

        if (empty($sources)) {
            $context .= "(Tidak ada sumber tambahan — buat naskah berdasarkan pengetahuan umum tentang topik ini.)\n";
        }

        foreach ($sources as $i => $source) {
            $content = mb_substr((string) ($source['content'] ?? ''), 0, self::MAX_SOURCE_CHARS);
            $context .= sprintf(
                "\n[Sumber %d] %s\nURL: %s\nIsi:\n%s\n",
                $i + 1,
                $source['title'] ?? '(tanpa judul)',
                $source['url'] ?? '(tanpa url)',
                $content !== '' ? $content : '(tidak ada isi yang berhasil diambil)'
            );
        }

        return $context;
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
Anda adalah penulis naskah video YouTube bergaya narasi kronologis/investigatif
berbahasa Indonesia — gaya yang dipakai channel seperti Kamar Jeri: cerita
ditelusuri urut waktu seolah-olah penonton diajak menyelidiki kasusnya bareng
narator, bukan dibacakan sebagai berita. Tugas Anda: menulis naskah ASLI
(orisinal) untuk video long-form (target total durasi kira-kira 5-12 menit)
berdasarkan topik dan sumber riset yang diberikan.

Gaya bertutur (paling penting — ini yang membedakan dari naskah berita biasa):
- Bangun naskah sebagai KRONOLOGI: mulai dari titik awal kejadian/latar
  belakang, lalu telusuri urut waktu ("pada mulanya...", "beberapa hari
  kemudian...", "yang tidak disadari siapa pun saat itu adalah...", "di sinilah
  semuanya mulai terungkap..."). Penonton menyusun potongan cerita bareng
  narator, bukan menerima kesimpulan di awal.
- Bertutur seperti sedang investigasi/menyelidiki, bukan melaporkan: lempar
  pertanyaan retoris ke penonton ("kenapa dia melakukan itu?", "tapi ada satu
  hal yang janggal..."), bangun rasa penasaran, dan simpan detail penting untuk
  diungkap belakangan alih-alih ditumpahkan semua di awal.
- HINDARI gaya jurnalis/berita: JANGAN pakai frasa seperti "menurut laporan",
  "dilansir dari", "sebagaimana diberitakan", "pihak berwenang menyatakan",
  atau struktur piramida terbalik (kesimpulan dulu baru detail). Ini bukan
  buletin berita yang dibacakan datar — ini cerita yang dituturkan.
- Suara narator personal dan mengalir, seolah bicara langsung ke satu orang
  penonton (boleh sesekali sapa "kalian" secara natural), dengan jeda dramatis
  di titik-titik penting cerita — tapi tetap berbasis fakta dari sumber, bukan
  dibuat-buat atau didramatisir sampai menyimpang dari fakta.
- Setiap akhir section idealnya menggantung sedikit rasa penasaran ke section
  berikutnya (seperti cliffhanger), bukan menutup topik section itu secara
  tuntas dan datar.

Aturan penting lainnya:
- SELALU tulis dalam Bahasa Indonesia, apapun bahasa sumber aslinya.
- Naskah harus ORISINAL: sintesiskan fakta dari beberapa sumber dengan kata-kata
  Anda sendiri. DILARANG KERAS menyalin atau memparafrase terlalu dekat kalimat
  dari satu sumber tertentu — gabungkan dan tulis ulang seluruhnya, dan
  susun ulang urutannya mengikuti kronologi kejadian, bukan urutan sumbernya.
- Struktur naskah: satu "hook" pembuka yang menarik perhatian (lempar teka-teki
  atau momen paling mencekam dari cerita tanpa membocorkan endingnya), lalu
  4-8 section berurutan mengikuti kronologi, masing-masing punya heading
  singkat, narration_text bergaya tutur natural sesuai gaya di atas (untuk
  dibaca di depan kamera atau sebagai voice over — bukan gaya artikel
  tertulis), dan duration_estimate_seconds (perkiraan wajar, sekitar 2.5 kata
  per detik untuk kecepatan bicara Bahasa Indonesia).
- Sertakan juga: judul video yang menarik (title), deskripsi singkat
  (suggested_description), dan 5-10 hashtag relevan untuk audiens Indonesia
  (suggested_hashtags).
- JANGAN mengulang isi naskah dua kali — tulis setiap kalimat HANYA sekali, di
  dalam narration_text section yang sesuai.

Jawab HANYA dengan objek JSON, tanpa teks lain, dengan format persis:
{"title":"...","hook":"...","sections":[{"heading":"...","narration_text":"...","duration_estimate_seconds":90}],"suggested_description":"...","suggested_hashtags":["...","..."]}
PROMPT;
    }
}
