<?php

namespace App\Jobs;

use App\Models\Template;
use App\Services\Video\TemplatePreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateTemplatePreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $templateId) {}

    public function handle(TemplatePreviewService $previewService): void
    {
        $template = Template::findOrFail($this->templateId);

        try {
            $previewPath = $previewService->generate($template);
            $template->update([
                'preview_path' => $previewPath,
                'preview_status' => 'ready',
                'preview_generated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Template preview generation failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
            $template->update(['preview_status' => 'failed']);
        }
    }
}
