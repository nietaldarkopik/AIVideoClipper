<?php

namespace App\Services\Video;

/**
 * Appends a "Sumber: <channel>" credit line to a clip's caption/description,
 * crediting the original video's source channel/uploader — see
 * Video::channelName(). Deterministic string formatting rather than an AI
 * prompt instruction, so the credit always appears exactly as captured,
 * regardless of which SocialMetadataProvider (or none) generated the rest of
 * the text.
 */
class ClipCreditFormatter
{
    public static function append(?string $text, ?string $channelName): ?string
    {
        $channelName = trim((string) $channelName);
        if ($channelName === '') {
            return $text;
        }

        $text = trim((string) $text);
        $credit = "Sumber: {$channelName}";

        return $text === '' ? $credit : "{$text}\n\n{$credit}";
    }
}
