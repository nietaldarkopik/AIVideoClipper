<?php

namespace App\Services\Video;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a Project and everything under it — DB rows (Video/Clip/ClipCandidate/
 * Transcript cascade automatically via FK ON DELETE CASCADE, see their migrations)
 * AND the on-disk files those rows point at, which nothing else in the app cleans
 * up (confirmed: ProjectController::destroy used to just call $project->delete()
 * and leave every rendered clip/downloaded source video orphaned on the 'media'
 * disk forever).
 *
 * Deletes by directory (videos/{id}/, clips/{id}/) rather than chasing individual
 * *_path columns — ImportVideoJob/RenderClipJob write everything for one video/clip
 * under its own id-named directory (source file, thumbnail, waveform, captions,
 * intermediate render steps, etc.), so this stays correct even as new file types
 * get added to those jobs without a matching DB column each time.
 */
class ProjectDeletionService
{
    public function delete(Project $project): void
    {
        $disk = Storage::disk('media');

        foreach ($project->videos()->pluck('id') as $videoId) {
            $disk->deleteDirectory("videos/{$videoId}");
        }

        foreach ($project->clips()->pluck('id') as $clipId) {
            $disk->deleteDirectory("clips/{$clipId}");
        }

        $project->delete();
    }
}
