<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    public static function defaults(): array
    {
        return [
            'ai_model' => 'mock',
            'transcript_model' => config('services.ai.transcription_provider', 'mock'),
            'clip_scoring_model' => config('services.ai.analysis_provider', 'mock'),
            'reaction_script_model' => config('services.ai.reaction_script_provider', 'mock'),
            'tts_model' => config('services.ai.tts_provider', 'mock'),
            'content_idea_model' => config('services.ai.content_idea_provider', 'mock'),
            'default_clip_duration' => (int) config('services.ai.default_clip_duration', 30),
            'default_template_id' => null,
            'max_clips_per_video' => (int) config('services.ai.max_clips_per_video', 10),
        ];
    }

    public static function allWithDefaults(): array
    {
        $defaults = static::defaults();
        $stored = static::whereIn('key', array_keys($defaults))->get()->mapWithKeys(
            fn ($row) => [$row->key => $row->value['v'] ?? null]
        );

        return array_merge($defaults, $stored->all());
    }
}
