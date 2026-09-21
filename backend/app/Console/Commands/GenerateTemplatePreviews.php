<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTemplatePreviewJob;
use App\Models\Template;
use Illuminate\Console\Command;

/**
 * Backfills templates.preview_path for existing templates (created before the
 * preview-video feature existed, so GenerateTemplatePreviewJob was never
 * dispatched for them) by queueing a render for each. New/edited templates get
 * this automatically from TemplateController — this command is only for a
 * one-off catch-up pass, or to force-regenerate every template's preview after
 * changing the shared demo source (TemplatePreviewService::DEMO_VIDEO_ID).
 */
class GenerateTemplatePreviews extends Command
{
    protected $signature = 'templates:generate-previews {--id= : Only this template ID} {--missing-only : Skip templates that already have a preview}';

    protected $description = 'Queue GenerateTemplatePreviewJob for templates missing a sample preview video';

    public function handle(): int
    {
        $query = Template::where('status', '!=', 'archived');

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } elseif ($this->option('missing-only')) {
            $query->whereNull('preview_path');
        }

        $templates = $query->get(['id']);

        if ($templates->isEmpty()) {
            $this->info('No templates to generate previews for.');

            return self::SUCCESS;
        }

        foreach ($templates as $template) {
            $template->update(['preview_status' => 'generating']);
            GenerateTemplatePreviewJob::dispatch($template->id);
        }

        $this->info("Queued preview generation for {$templates->count()} template(s).");

        return self::SUCCESS;
    }
}
