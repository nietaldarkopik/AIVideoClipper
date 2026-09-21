<?php

namespace App\Support;

use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;

/**
 * Decides whether a user may be handed a signed URL for a media-disk path. Paths
 * embed the owning row's id ("videos/12/...", "clips/7/..."), so ownership is
 * resolved from the prefix; anything unrecognised is denied.
 */
class MediaAccess
{
    /** Prefixes holding non-user-specific assets (template previews, sticker sheet, cover demo frame). */
    private const SHARED = ['stickers', 'templates', 'covers/_demo'];

    public static function allows(User $user, string $path): bool
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));

        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        foreach (self::SHARED as $prefix) {
            if (str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        $segments = explode('/', $path);
        $root = $segments[0];
        $second = $segments[1] ?? '';

        return match ($root) {
            'uploads' => ctype_digit($second) && (int) $second === $user->id,
            'exports' => str_starts_with($second, $user->id . '_'),
            'videos' => ctype_digit($second) && self::ownsProject($user, Video::find((int) $second)?->project_id),
            'clips', 'subtitles', 'reactions-intro', 'covers' => ctype_digit($second)
                && self::ownsProject($user, Clip::find((int) $second)?->project_id),
            'reactions' => self::ownsReaction($user, $second),
            default => false,
        };
    }

    private static function ownsReaction(User $user, string $segment): bool
    {
        if (str_starts_with($segment, 'clip-') && ctype_digit($id = substr($segment, 5))) {
            return self::ownsProject($user, Clip::find((int) $id)?->project_id);
        }

        return ctype_digit($segment) && self::ownsProject($user, ClipCandidate::find((int) $segment)?->project_id);
    }

    private static function ownsProject(User $user, ?int $projectId): bool
    {
        if ($projectId === null) {
            return false;
        }

        return $user->isAdmin() || Project::whereKey($projectId)->where('user_id', $user->id)->exists();
    }
}
