<?php

namespace App\Services\AI\Concerns;

/**
 * Every content-analysis/social-metadata provider that doesn't have a hard JSON
 * mode enforced server-side (Gemini's responseSchema, Claude's forced tool_choice)
 * asks the model to "respond with ONLY a JSON object" and then json_decode()s the
 * raw text. In practice some upstream models ignore that instruction and wrap the
 * object in a markdown code fence (```json ... ```) or add a stray sentence before/
 * after it — seen concretely via 9Router's "Kombo" routing, which can land on a
 * different underlying model per request. json_decode() fails outright on that,
 * which silently produced zero candidates. This strips the common wrapping shapes
 * before falling back to a raw decode attempt.
 */
trait ParsesJsonResponses
{
    /**
     * @return array<string, mixed>|null
     */
    protected function extractJsonObject(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = trim($raw);

        // ```json { ... } ``` or ``` { ... } ``` — the most common deviation.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            return $parsed;
        }

        // Last resort: a stray sentence before/after the object (no fence at all) —
        // grab from the first '{' to the last '}' and try again.
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }
}
