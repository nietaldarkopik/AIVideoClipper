<?php

namespace App\Services\Assistant;

use App\Models\AssistantAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Stores a file the user attached to a chat message and turns it into something the model
 * can read: images go to the model as pictures, everything else is reduced to text here.
 * Whatever is extracted is DATA to analyze — PromptBuilder fences it off from instructions.
 */
class AttachmentProcessor
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const TEXT_EXT = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log', 'xml', 'html', 'htm', 'srt', 'vtt', 'yaml', 'yml'];

    public function process(UploadedFile $file, User $user): AssistantAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $disk = Storage::disk('media');
        $path = $disk->putFileAs("assistant/{$user->id}", $file, Str::uuid()->toString() . ($ext ? ".{$ext}" : ''));

        $attachment = new AssistantAttachment([
            'user_id' => $user->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
            'path' => $path,
            'kind' => AssistantAttachment::KIND_UNSUPPORTED,
        ]);

        $absolute = $disk->path($path);

        try {
            if (in_array($ext, self::IMAGE_EXT, true)) {
                $attachment->kind = AssistantAttachment::KIND_IMAGE;
            } elseif ($ext === 'pdf') {
                $attachment->kind = AssistantAttachment::KIND_DOCUMENT;
                $this->fill($attachment, $this->pdfText($absolute), 'Teks PDF tidak bisa diekstrak (mungkin hasil scan/gambar, atau pdftotext tidak terpasang di server).');
            } elseif ($ext === 'docx') {
                $attachment->kind = AssistantAttachment::KIND_DOCUMENT;
                $this->fill($attachment, $this->docxText($absolute), 'Teks DOCX tidak bisa dibaca.');
            } elseif ($ext === 'xlsx') {
                $attachment->kind = AssistantAttachment::KIND_SPREADSHEET;
                $this->fill($attachment, $this->xlsxText($absolute), 'Isi XLSX tidak bisa dibaca.');
            } elseif (in_array($ext, self::TEXT_EXT, true)) {
                $attachment->kind = in_array($ext, ['csv', 'tsv'], true) ? AssistantAttachment::KIND_SPREADSHEET : AssistantAttachment::KIND_TEXT;
                $this->fill($attachment, $this->plainText($absolute), 'File kosong.');
            } else {
                $attachment->note = 'Format .' . ($ext ?: '?') . ' belum bisa dianalisis. Didukung: gambar, PDF, DOCX, XLSX, CSV, dan file teks.';
            }
        } catch (Throwable $e) {
            $attachment->note = 'Gagal membaca file: ' . mb_substr($e->getMessage(), 0, 150);
        }

        $attachment->save();

        return $attachment;
    }

    /**
     * Downscaled JPEG data URI for the model. Phone photos are routinely 5-10 MB; sending
     * them whole would blow the request size and the token budget for no gain in accuracy.
     */
    public function imageDataUri(AssistantAttachment $attachment): ?string
    {
        $disk = Storage::disk('media');
        $small = $attachment->path . '.vision.jpg';

        if (! $disk->exists($small)) {
            $result = Process::timeout(60)->run([
                config('services.media.ffmpeg_bin', 'ffmpeg'), '-y', '-i', $disk->path($attachment->path),
                '-vf', "scale='min(" . (int) config('assistant.image_max_edge', 1568) . ",iw)':-2", '-frames:v', '1', '-q:v', '3',
                $disk->path($small),
            ]);

            if (! $result->successful() || ! $disk->exists($small)) {
                return null;
            }
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) $disk->get($small));
    }

    private function fill(AssistantAttachment $attachment, ?string $text, string $emptyNote): void
    {
        $text = $text !== null ? trim($text) : '';

        if ($text === '') {
            $attachment->note = $emptyNote;

            return;
        }

        $max = (int) config('assistant.max_extracted_chars', 40000);
        if (mb_strlen($text) > $max) {
            $attachment->note = 'Teks dipotong: hanya ' . number_format($max) . ' karakter pertama yang dibaca.';
            $text = mb_substr($text, 0, $max);
        }

        $attachment->extracted_text = $text;
    }

    private function pdfText(string $path): ?string
    {
        $result = Process::timeout(60)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);

        return $result->successful() ? $result->output() : null;
    }

    private function docxText(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        $xml = preg_replace(['/<\/w:p>/', '/<w:tab\/>/', '/<w:br\/>/'], ["\n", "\t", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function xlsxText(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }

        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = @simplexml_load_string($ss);
            foreach ($doc?->si ?? [] as $si) {
                // A shared string is either one <t> or several rich-text <r><t> runs.
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $text .= (string) $t;
                }
                $shared[] = $text;
            }
        }

        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            if ($sheet === false) {
                break;
            }

            $doc = @simplexml_load_string($sheet);
            if (! $doc) {
                continue;
            }

            $out[] = "## Sheet {$i}";
            $rows = 0;
            foreach ($doc->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $v = (string) $c->v;
                    $cells[] = ((string) $c['t']) === 's' ? ($shared[(int) $v] ?? '') : ((string) $c['t'] === 'inlineStr' ? (string) $c->is->t : $v);
                }
                $out[] = implode("\t", $cells);
                if (++$rows >= 300) {
                    $out[] = '…(baris selanjutnya dipotong)';
                    break;
                }
            }
        }
        $zip->close();

        return implode("\n", $out);
    }

    private function plainText(string $path): ?string
    {
        $raw = file_get_contents($path, false, null, 0, (int) config('assistant.max_extracted_chars', 40000) * 4);
        if ($raw === false) {
            return null;
        }

        // Spreadsheet exports from Windows tools are often UTF-16 or Windows-1252.
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }

        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
