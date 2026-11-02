<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * Runs one tool call: checks and cleans the model's arguments against the tool's declared
 * schema, performs the mapped internal API call as the user, and shapes the response into
 * compact JSON text for the model.
 */
class ToolExecutor
{
    public function __construct(
        private readonly InternalApiClient $api,
    ) {
    }

    /**
     * Validate $args against the tool's schema. Returns the cleaned arguments (undeclared
     * ones dropped, types coerced) or an error message to hand back to the model.
     *
     * @return array{args: array<string, mixed>}|array{error: string}
     */
    public function prepare(ToolDefinition $tool, array $args): array
    {
        $clean = [];

        foreach ($tool->params as $name => $schema) {
            $present = array_key_exists($name, $args) && $args[$name] !== null && $args[$name] !== '';

            if (! $present) {
                if (! empty($schema['required'])) {
                    return ['error' => "Missing required argument \"{$name}\"."];
                }

                // An explicit empty string is meaningful for a few fields (e.g. clearing a
                // schedule); keep it rather than treating it as absent.
                if (array_key_exists($name, $args) && $args[$name] === '') {
                    $clean[$name] = '';
                }

                continue;
            }

            $value = $this->coerce($args[$name], $schema);
            if ($value === null) {
                return ['error' => "Argument \"{$name}\" must be of type {$schema['type']}" . (isset($schema['enum']) ? ' (one of: ' . implode(', ', $schema['enum']) . ')' : '') . '.'];
            }

            $clean[$name] = $value;
        }

        return ['args' => $clean];
    }

    /** Human-readable one-liner for an approval card. */
    public function summarize(ToolDefinition $tool, array $args): string
    {
        $template = $tool->summary !== '' ? $tool->summary : $tool->name;

        $text = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($args) {
            $v = $args[$m[1]] ?? null;
            if ($v === null || $v === '') {
                return '-';
            }

            return mb_substr(is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v, 0, 100);
        }, $template);

        return mb_substr($text, 0, 480);
    }

    /**
     * Execute with already-prepared arguments.
     *
     * @return array{ok: bool, content: string}
     */
    public function execute(User $user, ToolDefinition $tool, array $args): array
    {
        $path = $tool->path;
        foreach ($tool->pathParams() as $p) {
            $path = str_replace('{' . $p . '}', rawurlencode((string) ($args[$p] ?? '')), $path);
            unset($args[$p]);
        }

        $isRead = in_array($tool->method, ['GET', 'DELETE'], true);
        $result = $this->api->call($user, $tool->method, $path, $isRead ? $args : [], $isRead ? [] : $args);

        if ($result['status'] >= 400) {
            return ['ok' => false, 'content' => $this->errorContent($result['status'], $result['data'])];
        }

        return ['ok' => true, 'content' => $this->shape($tool, $result['data'])];
    }

    private function coerce(mixed $value, array $schema): mixed
    {
        switch ($schema['type']) {
            case 'integer':
                return is_numeric($value) && (int) $value == $value ? (int) $value : null;
            case 'boolean':
                $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $b;
            case 'array':
                if (! is_array($value)) {
                    return null;
                }
                $isInt = ($schema['items']['type'] ?? '') === 'integer';
                $out = [];
                foreach ($value as $item) {
                    if ($isInt) {
                        if (! is_numeric($item)) {
                            return null;
                        }
                        $out[] = (int) $item;
                    } else {
                        $out[] = (string) $item;
                    }
                }

                return $out;
            default:
                if (! is_scalar($value)) {
                    return null;
                }
                $s = (string) $value;
                if (isset($schema['enum']) && ! in_array($s, $schema['enum'], true)) {
                    return null;
                }

                return $s;
        }
    }

    private function errorContent(int $status, mixed $data): string
    {
        $message = is_array($data) ? ($data['message'] ?? 'Request failed.') : 'Request failed.';
        $payload = ['error' => true, 'status' => $status, 'message' => $message];

        if (is_array($data) && ! empty($data['errors'])) {
            $payload['errors'] = $data['errors'];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function shape(ToolDefinition $tool, mixed $data): string
    {
        if (! is_array($data)) {
            return mb_substr((string) $data, 0, (int) config('assistant.max_tool_result_chars', 9000));
        }

        $payload = $data;

        if ($tool->omit !== []) {
            $payload = $this->omitKeys($payload, $tool->omit);
        }

        if ($tool->pick !== []) {
            if (isset($payload['data']) && array_is_list($payload['data'])) {
                $payload['data'] = array_map(fn ($item) => is_array($item) ? array_intersect_key($item, array_flip($tool->pick)) : $item, $payload['data']);
            } elseif (array_is_list($payload)) {
                $payload = array_map(fn ($item) => is_array($item) ? array_intersect_key($item, array_flip($tool->pick)) : $item, $payload);
            }
        }

        // Pagination links/paths are noise for the model; keep only what it needs to page.
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $payload['meta'] = array_intersect_key($payload['meta'], array_flip(['current_page', 'last_page', 'per_page', 'total']));
        }
        unset($payload['links']);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $max = (int) config('assistant.max_tool_result_chars', 9000);

        if (mb_strlen($json) > $max) {
            return mb_substr($json, 0, $max) . '…[TRUNCATED — result too large; narrow the query with filters or a smaller per_page]';
        }

        return $json;
    }

    private function omitKeys(array $node, array $keys): array
    {
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array($k, $keys, true)) {
                unset($node[$k]);

                continue;
            }
            if (is_array($v)) {
                $node[$k] = $this->omitKeys($v, $keys);
            }
        }

        return $node;
    }
}
