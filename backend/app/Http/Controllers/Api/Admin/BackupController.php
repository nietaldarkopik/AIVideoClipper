<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\DatabaseBackupService;
use Throwable;

/**
 * Admin-only database backup management (list/create/download/delete). The
 * matching media-file archive (FileBackupService, backup:files) is CLI-only —
 * it routinely runs into the hundreds of GB, too large for a browser download
 * or a request-lifetime-bound HTTP action, so it has no endpoint here.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    public function index()
    {
        return response()->json(['data' => $this->backups->list()]);
    }

    public function store()
    {
        // pg_dump on this app's schema/data is normally seconds to low minutes,
        // but give it real headroom on a slow disk/large table rather than
        // racing PHP's default request timeout.
        set_time_limit(600);

        try {
            $result = $this->backups->create();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['data' => $result], 201);
    }

    public function download(string $filename)
    {
        try {
            $path = $this->backups->absolutePath($filename);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! is_file($path)) {
            return response()->json(['message' => 'Backup file not found.'], 404);
        }

        return response()->download($path, basename($path));
    }

    public function destroy(string $filename)
    {
        try {
            $this->backups->delete($filename);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Backup deleted.']);
    }
}
