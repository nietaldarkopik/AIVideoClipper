<?php

namespace App\Services\Video;

use App\Models\Clip;
use App\Models\CoverTemplate;
use App\Models\SocialAccount;
use App\Services\AI\Contracts\ImageGenerationProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a rendered clip into a clickbait-style social cover/thumbnail: gets a
 * background image — either a real frame grabbed from the clip's own output
 * (FFmpegService::generateThumbnail()), or, for a template whose
 * config['background']['source'] is 'ai_generated', a whole AI-synthesized
 * scene from that template's own prompt (ImageGenerationProvider, falling
 * back to the clip's own frame if generation fails) — then burns in a
 * headline + optional badge per the chosen CoverTemplate's config
 * (FFmpegService::renderCoverImage()). The clip-frame path is the still-image
 * sibling of TemplatePreviewService; the AI-generated path is the still-image
 * sibling of RenderClipJob's AI reaction-intro cover.
 */
class CoverGeneratorService
{
    public function __construct(
        private readonly FFmpegService $ffmpeg,
        private readonly ImageGenerationProvider $imageGen,
    ) {}

    /**
     * Generates this clip's cover with whichever template resolveTemplate()
     * picks, and records it on the clip. Returns the disk-relative path, or null
     * when there's no usable template at all (none published yet).
     *
     * Callers treat a failure here as non-fatal — see RenderClipJob and
     * PublishClipJob: a clip without a cover is still a perfectly good clip, so
     * neither rendering nor publishing should die over one.
     */
    public function generateForClip(Clip $clip, ?SocialAccount $preferAccount = null): ?string
    {
        $template = $this->resolveTemplate($clip, $preferAccount);
        if (! $template) {
            return null;
        }

        $relative = $this->generate($clip->loadMissing('clipCandidate'), $template);

        $clip->update([
            'cover_template_id' => $template->id,
            'cover_text' => $clip->cover_text
                ?? $clip->clipCandidate?->cover_titles[0]
                ?? $clip->clipCandidate?->hook_text
                ?? $clip->title,
            'cover_path' => $relative,
        ]);

        return $relative;
    }

    /**
     * Which cover template a clip should use, most specific first:
     *
     *  1. one already on the clip — either hand-picked in the Cover tab or an
     *     earlier auto-pick, kept so a re-render never silently restyles a cover
     *     someone already approved;
     *  2. the destination channel's own default, when the caller knows which
     *     channel this is for (publish time does, render time doesn't);
     *  3. any default configured on the owner's other channels — a template they
     *     deliberately chose somewhere beats an unrelated one picked at random;
     *  4. a random published template, preferring the clip's own aspect ratio.
     *
     * Step 4 is what makes "just render a cover with every clip" work for an
     * account that has never configured one, which is the common case at render
     * time — the pick is persisted by generateForClip(), so it stays stable for
     * that clip instead of changing on every re-render.
     */
    public function resolveTemplate(Clip $clip, ?SocialAccount $preferAccount = null): ?CoverTemplate
    {
        if ($clip->cover_template_id && $existing = CoverTemplate::find($clip->cover_template_id)) {
            return $existing;
        }

        if ($preferAccount?->default_cover_template_id
            && $accountDefault = CoverTemplate::find($preferAccount->default_cover_template_id)) {
            return $accountDefault;
        }

        $ownerId = $clip->project?->user_id ?? $clip->loadMissing('project')->project?->user_id;
        if ($ownerId) {
            $configured = CoverTemplate::whereIn(
                'id',
                SocialAccount::where('user_id', $ownerId)->whereNotNull('default_cover_template_id')->pluck('default_cover_template_id')
            )->inRandomOrder()->first();

            if ($configured) {
                return $configured;
            }
        }

        $published = CoverTemplate::where('status', 'published');

        return (clone $published)->where('aspect_ratio', $clip->aspect_ratio)->inRandomOrder()->first()
            ?? $published->inRandomOrder()->first();
    }

    public function generate(Clip $clip, CoverTemplate $coverTemplate, ?string $text = null): string
    {
        $disk = Storage::disk('media');

        if (! $clip->output_path || ! $disk->exists($clip->output_path)) {
            throw new RuntimeException('Clip has no rendered output yet — render it before generating a cover.');
        }

        $config = array_replace_recursive(DefaultCoverTemplateConfig::config(), $coverTemplate->config ?? []);
        [$width, $height] = AspectRatio::resolution($coverTemplate->aspect_ratio);

        // A clip's own AI-written labels (see ClipGenerationService) replace the
        // template's generic placeholder text: the TEMPLATE decides which
        // elements a design has and how they look, this CLIP decides what they
        // say. Deliberately never flips enabled on/off — a design that has no
        // kicker slot shouldn't sprout one just because the analysis produced a
        // label, and the template editor is where that choice belongs.
        if (! empty($clip->cover_kicker) && ! empty($config['kicker']['enabled'])) {
            $config['kicker']['text'] = $clip->cover_kicker;
        }
        if (! empty($clip->cover_subline) && ! empty($config['subline']['enabled'])) {
            $config['subline']['text'] = $clip->cover_subline;
        }

        $text = trim((string) ($text ?? $clip->cover_text ?? $clip->clipCandidate?->hook_text ?? $clip->title ?? $clip->caption ?? ''));
        // A clickbait cover headline reads best short and punchy — clamp a long
        // fallback (a hook_text/title written for a caption, not a thumbnail)
        // to a few words rather than wrapping a whole sentence across the image.
        if (mb_strlen($text) > 70) {
            $text = mb_substr(Str::words($text, 10, ''), 0, 70);
        }

        $frameRelative = "covers/{$clip->id}/frame.jpg";
        // A third of the way in rather than frame 0 — a raw cut very often
        // opens on a black/transitional frame that makes a poor cover.
        $atSecond = max(0.0, (float) $clip->duration * 0.3);
        $this->ffmpeg->generateThumbnail($disk->path($clip->output_path), $disk->path($frameRelative), $atSecond);

        $backgroundRelative = $frameRelative;
        $prompt = trim((string) ($config['background']['ai_prompt'] ?? ''));
        if (($config['background']['source'] ?? 'clip_frame') === 'ai_generated' && $prompt !== '') {
            $aiRelative = "covers/{$clip->id}/{$coverTemplate->id}_bg.jpg";
            try {
                // Falls back to the just-grabbed clip frame if generation fails
                // (real provider quota/error) or MockImageGenerationProvider is
                // bound (dev/no API key) — same resilience as the AI reaction-
                // intro cover this mirrors.
                $this->imageGen->generateCoverImage($prompt, $disk->path($aiRelative), $disk->path($frameRelative));
                $backgroundRelative = $aiRelative;
            } catch (Throwable) {
                $backgroundRelative = $frameRelative;
            }
        }

        $outputRelative = "covers/{$clip->id}/{$coverTemplate->id}.jpg";

        try {
            $this->ffmpeg->renderCoverImage(
                $disk->path($backgroundRelative),
                $disk->path($outputRelative),
                $width,
                $height,
                $text,
                $config,
            );
        } finally {
            $disk->delete($frameRelative);
            if ($backgroundRelative !== $frameRelative) {
                $disk->delete($backgroundRelative);
            }
        }

        return $outputRelative;
    }
}
