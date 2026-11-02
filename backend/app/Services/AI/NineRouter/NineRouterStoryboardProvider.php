<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\StoryboardProvider;
use App\Services\AI\DTOs\StoryboardResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Splits a narration script into illustrated scenes via a self-hosted 9Router chat
 * model. The narration itself is NOT rewritten — only distributed across scenes — so
 * the research-backed script the user already reviewed is what gets voiced.
 */
class NineRouterStoryboardProvider implements StoryboardProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
    ) {
    }

    public function generateStoryboard(string $topic, string $material, int $maxScenes): StoryboardResult
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_STORYBOARD_MODEL (or NINE_ROUTER_VIDEO_NARRATIVE_MODEL / NINE_ROUTER_MODEL) is not set. Check GET ' .
                rtrim($this->baseUrl, '/') . '/models for the model ids your 9Router instance actually has credentials for.'
            );
        }

        $system = $this->systemPrompt($maxScenes);
        $user = "Topik: {$topic}\n\nMateri riset/naskah sumber:\n" . mb_substr($material, 0, 24000);
        $span = $this->aiLogger()->start('storyboard', 'nine_router', $this->model, $system . "\n\n" . $user);

        $request = Http::timeout(300)->retry(1, 2000)->withOptions(['version' => 1.1]);
        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
            'model' => $this->model,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router storyboard generation failed: ' . $response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw) ?? [];

        $scenes = [];
        foreach (is_array($parsed['scenes'] ?? null) ? $parsed['scenes'] : [] as $s) {
            if (! is_array($s) || empty($s['narration']) || empty($s['visual_prompt'])) {
                continue;
            }

            $scenes[] = [
                'narration' => trim((string) $s['narration']),
                'visual_prompt' => trim((string) $s['visual_prompt']),
                'motion_prompt' => trim((string) ($s['motion_prompt'] ?? '')) ?: 'Slow zoom in',
            ];
        }

        if (empty($scenes)) {
            throw new RuntimeException('9Router storyboard generation returned no usable scenes.');
        }

        return new StoryboardResult(
            trim((string) ($parsed['character_description'] ?? '')),
            array_slice($scenes, 0, $maxScenes),
        );
    }

    private function systemPrompt(int $maxScenes): string
    {
        return <<<PROMPT
Anda adalah penulis naskah sekaligus sutradara storyboard untuk video pendek
vertikal (9:16) bergaya kartun penjelasan fakta — seperti channel "fakta singkat"
dengan ilustrasi kartun berwarna cerah. Anda menerima materi riset (bisa naskah
panjang atau hanya ringkasan ide). Tugas Anda: meringkasnya menjadi narasi pendek
(total sekitar 45-60 detik, 110-160 kata) lalu memecahnya menjadi scene-scene dan
menentukan visual tiap scene.

Aturan:
- Hasilkan MAKSIMAL {$maxScenes} scene (idealnya 6-10). Tiap scene = 1 gambar kartun
  + 1 potong narasi sekitar 8-20 kata (3-8 detik saat dibacakan).
- Field "narration": Bahasa Indonesia, gaya bertutur santai dan mudah dipahami
  orang awam, kalimat pendek. Ambil hanya fakta yang ADA di materi — jangan
  mengarang angka, nama, atau kejadian baru. Pilih fakta paling menarik; buang detail
  sampingan.
- Scene pertama harus berfungsi sebagai hook yang menarik perhatian; scene terakhir
  ditutup dengan satu kalimat penutup/ajakan singkat.
- Field "visual_prompt" ditulis dalam BAHASA INGGRIS (model gambar bekerja lebih baik):
  deskripsi satu adegan kartun yang menjelaskan isi narasi — subjek, aksi, ekspresi,
  latar. Gaya sudah ditambahkan otomatis (flat cartoon), jadi JANGAN menulis gaya.
  DILARANG meminta teks/huruf/logo/watermark di gambar, dan jangan menyebut tokoh
  nyata atau merek; gunakan karakter kartun generik.
- Field "motion_prompt" (Inggris, singkat): apa yang bergerak di scene itu, mis.
  "slow zoom in on the mascot, confetti falls" atau "camera pans right across the city".
- Field "character_description" (Inggris, 2-3 kalimat): "character sheet" satu
  maskot/karakter utama yang muncul di scene-scene. Wajib KONKRET dan tidak ambigu
  karena model gambar membuat tiap scene secara terpisah dan hanya deskripsi ini yang
  menjaga tampilannya sama: sebutkan spesies/bentuk badan, WARNA PASTI tiap bagian
  (mis. "bright orange body, yellow fins, white belly"), ciri wajah (mis. "two big
  round white eyes with black pupils, wide smile"), dan satu aksesori khas. Jangan
  memakai kata samar seperti "colorful". Jangan masukkan karakter ini manual ke
  visual_prompt — sistem yang menempelkannya di setiap scene; di visual_prompt cukup
  tulis "the mascot" dan aksinya, dan JANGAN mengubah warna/penampilannya.

Jawab HANYA dengan objek JSON persis:
{"character_description":"...","scenes":[{"narration":"...","visual_prompt":"...","motion_prompt":"..."}]}
PROMPT;
    }
}
