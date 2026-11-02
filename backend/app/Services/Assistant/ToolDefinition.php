<?php

namespace App\Services\Assistant;

/**
 * One assistant tool: what the model sees (name, description, JSON schema) and how it
 * maps onto an internal API route.
 *
 * `risk` is the safety contract and is enforced server-side, never by the model:
 *   read        runs immediately
 *   write       changes data in this app          -> needs the user's approval
 *   outbound    publishes/schedules to social media -> needs the user's approval
 *   destructive deletes something                  -> needs the user's approval
 */
class ToolDefinition
{
    public const RISK_READ = 'read';

    public const RISK_WRITE = 'write';

    public const RISK_OUTBOUND = 'outbound';

    public const RISK_DESTRUCTIVE = 'destructive';

    /**
     * @param  array<string, array<string, mixed>>  $params  name => schema fragment (+ 'required' => bool)
     * @param  string[]  $pick  keep only these keys on each item of a `data` list (empty = keep all)
     * @param  string[]  $omit  drop these keys from every returned object (bulky fields)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $risk,
        public readonly string $method,
        public readonly string $path,
        public readonly array $params = [],
        public readonly string $summary = '',
        public readonly array $pick = [],
        public readonly array $omit = [],
    ) {
    }

    public function needsApproval(): bool
    {
        return $this->risk !== self::RISK_READ;
    }

    /** OpenAI-format function definition. */
    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->params as $name => $schema) {
            if (! empty($schema['required'])) {
                $required[] = $name;
            }
            unset($schema['required']);
            $properties[$name] = $schema;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description . ($this->needsApproval() ? ' (Requires the user\'s approval before it runs.)' : ''),
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    /** Names of `{placeholders}` in the path template. */
    public function pathParams(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->path, $m);

        return $m[1];
    }
}
