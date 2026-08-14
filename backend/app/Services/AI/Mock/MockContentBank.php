<?php

namespace App\Services\AI\Mock;

/**
 * Deterministic, hand-authored sample content used by the mock AI providers so the
 * whole pipeline produces believable transcripts, hooks, and captions without calling
 * a real speech-to-text or LLM API. Swap the providers in App\Services\AI\Mock for real
 * ones (OpenAI/Whisper/Deepgram/etc.) behind the same contracts when API keys exist.
 */
class MockContentBank
{
    public const MOMENT_TYPES = [
        'hook', 'question', 'emotional', 'funny', 'controversial',
        'educational', 'story_peak', 'conclusion',
    ];

    public static function fillerSentences(): array
    {
        return [
            "So here's the thing nobody talks about when you're starting out.",
            "I used to think the same way, and honestly it cost me a lot of time.",
            "Let me walk you through exactly what happened next.",
            "That's when everything started to click for me.",
            "A lot of people skip this step and then wonder why it doesn't work.",
            "You'd be surprised how often this comes up in real projects.",
            "Okay, so let's break this down piece by piece.",
            "Honestly, this part still surprises me every time.",
            "There's a reason most tutorials never mention this.",
            "I want to show you the exact process, not just the theory.",
            "This is where most people give up, and that's a mistake.",
            "Let's talk about why that actually matters.",
            "It sounds simple, but the execution is where it gets tricky.",
            "I've tested this across dozens of projects now.",
            "Stick with me, because the payoff here is huge.",
            "That single change made the biggest difference.",
            "Here's what I wish someone had told me on day one.",
            "The data backs this up more than you'd expect.",
            "It's not about working harder, it's about this one shift.",
            "Let me show you what that looks like in practice.",
        ];
    }

    public static function hooks(): array
    {
        return [
            'hook' => [
                'This is the biggest mistake people make when they get started.',
                'Nobody tells you this about growing an audience from zero.',
                'I wasted two years before I figured this out.',
                'This one habit changed everything about how I work.',
            ],
            'question' => [
                "Why does nobody talk about this before you start?",
                "What if everything you learned about this was backwards?",
                "Have you ever wondered why some people just get lucky?",
            ],
            'emotional' => [
                "I almost quit right before this happened.",
                "This moment genuinely changed how I see my work.",
                "I still get emotional thinking about how close I was to giving up.",
            ],
            'funny' => [
                "I did this so badly the first time it's actually embarrassing.",
                "My reaction here is exactly what you'd expect. It was not smart.",
                "This is the part where I completely underestimated the problem.",
            ],
            'controversial' => [
                "Most advice you hear about this is actually wrong.",
                "I'm going to say something that a lot of people will disagree with.",
                "This industry standard is honestly holding people back.",
            ],
            'educational' => [
                "Here's exactly how this works, step by step.",
                "Let me break down the framework I use for every project.",
                "This is the underlying principle nobody explains clearly.",
            ],
            'story_peak' => [
                "And that's the exact moment everything changed.",
                "This is where the whole plan almost fell apart.",
                "Right here is the turning point of the entire story.",
            ],
            'conclusion' => [
                "So if you take one thing from this, let it be this.",
                "That's the whole strategy, distilled into one idea.",
                "And that is exactly how you turn this around.",
            ],
        ];
    }

    public static function reasonsFor(string $momentType): array
    {
        return match ($momentType) {
            'hook' => [
                'Strong hook in first 3 seconds',
                'Clear problem statement',
                'Self-contained story',
                'No additional context required',
            ],
            'question' => [
                'Opens with a curiosity gap',
                'Invites the viewer to keep watching for the answer',
                'Clear, punchy delivery',
            ],
            'emotional' => [
                'High emotional intensity',
                'Strong personal vulnerability',
                'Relatable turning point',
            ],
            'funny' => [
                'Natural comedic timing',
                'Self-deprecating, relatable tone',
                'Short setup with a clear punchline',
            ],
            'controversial' => [
                'Contrarian opinion likely to drive comments',
                'Clear, confident statement',
                'Challenges a common assumption',
            ],
            'educational' => [
                'Clear, structured explanation',
                'Actionable takeaway',
                'Strong information density',
            ],
            'story_peak' => [
                'Narrative tension resolves here',
                'High rewatch value',
                'Strong emotional payoff',
            ],
            'conclusion' => [
                'Clear, quotable conclusion',
                'Strong call-to-action potential',
                'Wraps the story with a satisfying takeaway',
            ],
            default => ['Strong overall engagement signal'],
        };
    }

    public static function titlesFor(string $momentType): array
    {
        return match ($momentType) {
            'hook' => ['The Biggest Mistake You\'re Probably Making', 'This Changes Everything'],
            'question' => ['Nobody Tells You This', 'The Question Nobody Asks'],
            'emotional' => ['I Almost Gave Up', 'The Moment That Changed Everything'],
            'funny' => ['This Did NOT Go As Planned', 'I Can\'t Believe I Did This'],
            'controversial' => ['Unpopular Opinion', 'Most People Get This Wrong'],
            'educational' => ['Here\'s How It Actually Works', 'The Framework I Use Every Time'],
            'story_peak' => ['The Turning Point', 'Everything Changed Right Here'],
            'conclusion' => ['The One Thing To Remember', 'Here\'s The Takeaway'],
            default => ['Watch This'],
        };
    }

    public static function hashtagsFor(string $momentType): array
    {
        $base = ['#fyp', '#viral', '#contentcreator'];

        $extra = match ($momentType) {
            'hook', 'conclusion' => ['#lifehack', '#mindset', '#growth'],
            'question' => ['#didyouknow', '#learnontiktok'],
            'emotional', 'story_peak' => ['#storytime', '#realtalk'],
            'funny' => ['#funny', '#relatable', '#comedy'],
            'controversial' => ['#unpopularopinion', '#hottake'],
            'educational' => ['#howto', '#tutorial', '#tips'],
            default => ['#trending'],
        };

        return array_merge($base, $extra);
    }
}
