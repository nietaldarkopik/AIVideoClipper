<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $disk = Storage::disk('media');
        $normalized = str_replace('\\', '/', $path);

        // Prevent path traversal outside the media root.
        if (str_contains($normalized, '..') || ! $disk->exists($normalized)) {
            throw new NotFoundHttpException();
        }

        $fullPath = $disk->path($normalized);
        $size = filesize($fullPath);
        $mime = $disk->mimeType($normalized) ?: 'application/octet-stream';

        $start = 0;
        $end = $size - 1;
        $statusCode = 200;
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        if ($range = $request->header('Range')) {
            if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
                $start = $matches[1] === '' ? 0 : (int) $matches[1];
                $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
                $end = min($end, $size - 1);
                $statusCode = 206;
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            }
        }

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="' . basename($normalized) . '"';
        }

        $headers['Content-Length'] = $end - $start + 1;

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
