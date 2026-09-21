<?php

namespace App\Http\Controllers;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaStreamController extends Controller
{
    /**
     * Serves files from the "media" disk with HTTP Range support so <video> elements
     * can seek. Laravel's static "public" disk serving doesn't handle Range requests,
     * which is why source videos and rendered clips go through here instead.
     */
    public function stream(Request $request, string $path)
    {
        // Anonymous by design (media tags can't send a token) but never open: every
        // URL carries an expiring HMAC issued by Media::url() / POST /media/sign.
        if (! Media::valid($path, $request->query('expires'), $request->query('signature'))) {
            abort(403, 'Invalid or expired media link.');
        }

        $disk = Storage::disk('media');
        $normalized = str_replace('\\', '/', $path);

        // Prevent path traversal outside the media root.
        if (str_contains($normalized, '..') || ! $disk->exists($normalized)) {
            throw new NotFoundHttpException();
        }

        $fullPath = $disk->path($normalized);
        $size = filesize($fullPath);

        if ($size === false) {
            throw new NotFoundHttpException();
        }

        $mime = $disk->mimeType($normalized) ?: 'application/octet-stream';

        $start = 0;
        $end = max($size - 1, 0);
        $statusCode = 200;
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Single "bytes=a-b", "bytes=a-" or suffix "bytes=-n" only. Anything else
        // (multi-range, garbage) is ignored and answered with the full file, which
        // RFC 9110 allows.
        $range = $request->header('Range');
        if ($range && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) && ($matches[1] !== '' || $matches[2] !== '')) {
            if ($matches[1] === '') {
                // Suffix range: the last N bytes.
                $start = max($size - (int) $matches[2], 0);
                $end = $size - 1;
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
            }

            if ($size === 0 || $start > $end || $start >= $size) {
                return response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }

            $statusCode = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                basename($normalized),
                Str::ascii(basename($normalized)),
            );
        }

        $headers['Content-Length'] = $size === 0 ? 0 : $end - $start + 1;

        return new StreamedResponse(function () use ($fullPath, $start, $end) {
            $stream = fopen($fullPath, 'rb');
            fseek($stream, $start);
            $bytesLeft = $end - $start + 1;
            $chunkSize = 1024 * 512;

            while ($bytesLeft > 0 && ! feof($stream)) {
                $read = min($chunkSize, $bytesLeft);
                echo fread($stream, $read);
                $bytesLeft -= $read;
                flush();
            }

            fclose($stream);
        }, $statusCode, $headers);
    }
}
