<?php

namespace App\Services\AI\Mock;

use App\Models\Clip;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\DTOs\ReactionScriptResult;

class MockReactionScriptProvider implements ReactionScriptProvider
{
    public function generateReactionScript(Clip $clip, ?string $referenceContent = null): ReactionScriptResult
    {
        $title = $clip->title ?: 'this clip';
        $score = $clip->clipCandidate?->overall_score;
        $positive = $score === null || $score >= 70;

        return $positive
            ? new ReactionScriptResult("Okay, {$title} actually goes hard — wait for it.", 'positive')
            : new ReactionScriptResult("So {$title} happened. Let's just... watch this.", 'satire');
    }
}
