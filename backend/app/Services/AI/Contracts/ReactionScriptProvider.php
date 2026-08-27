<?php

namespace App\Services\AI\Contracts;

use App\Models\Clip;
use App\Services\AI\DTOs\ReactionScriptResult;

interface ReactionScriptProvider
{
    /**
     * Write a short, provocative reaction line for the clip's own content — hype/
     * positive tone if the content is genuinely good, satirical/sindiran tone if
     * it's bad or cringe. Meant to be narrated over an intro cover before the clip
     * plays (see FFmpegService::renderCoverSegment() / RenderClipJob).
     */
    public function generateReactionScript(Clip $clip): ReactionScriptResult;
}
