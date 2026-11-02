<?php

namespace App\Services\AI\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Ordered list of providers for one capability: try each in turn and roll over to the
 * next when one fails (quota exhausted, out of credits, not entitled, upstream down).
 *
 * A failed provider is put on a cooldown so the NEXT call skips it straight away. Without
 * that, a video with ten scenes would spend its first attempt on a provider that is
 * known-dead ten times over, which on a slow upstream is minutes of waiting. If every
 * provider is cooling down the cooldown is ignored for that call rather than failing
 * without even trying — a chain can never lock itself out permanently.
 *
 * Call resetCooldowns() when the user explicitly asks to retry (they may have just added
 * credits), so a stale cooldown doesn't hide a provider that works again.
 */
class ProviderChain
{
    private const COOLDOWN_MINUTES = 15;

    private const CONNECTION_COOLDOWN_MINUTES = 1;

    // Pauses between retries of the SAME provider after a transient error, in seconds.
    private const TRANSIENT_RETRY_DELAYS = [6, 15];

    private ?string $lastUsed = null;

    /** In-process fallback when the cache store is unreachable (e.g. Redis down): cooldown is an optimisation, never a reason to fail. */
    private static array $localCooldowns = [];

    private static int $localEpoch = 1;

    /**
     * @param  array<int, array{label: string, provider: object}>  $candidates
     */
    public function __construct(
        private readonly string $kind,
        private readonly array $candidates,
    ) {
    }

    public function lastUsed(): ?string
    {
        return $this->lastUsed;
    }

    /**
     * Run $call against each candidate until one succeeds; returns the winning label.
     *
     * @param  Closure(object): void  $call
     */
    public function run(Closure $call): string
    {
        $errors = [];
        $attempted = false;

        foreach ([true, false] as $honorCooldown) {
            foreach ($this->candidates as $candidate) {
                if ($honorCooldown && $this->isCoolingDown($candidate['label'])) {
                    $errors[$candidate['label']] ??= 'dilewati (baru gagal, cooldown)';

                    continue;
                }

                $attempted = true;

                $retries = 0;

                while (true) {
                    try {
                        $call($candidate['provider']);

                        return $this->lastUsed = $candidate['label'];
                    } catch (Throwable $e) {
                        // A blip ("server exception, try again later") is worth another go on
                        // the SAME provider: it may be the only one that works. Quota, credit
                        // and entitlement errors will not fix themselves, so those roll over now.
                        if ($this->isTransient($e) && isset(self::TRANSIENT_RETRY_DELAYS[$retries])) {
                            sleep($this->retryDelay($e, $retries++));

                            continue;
                        }

                        $this->trip($candidate['label'], $e);
                        $errors[$candidate['label']] = $this->summarize($e);

                        break;
                    }
                }
            }

            if ($attempted) {
                break;
            }
        }

        $lines = [];
        foreach ($errors as $label => $reason) {
            $lines[] = "- {$label}: {$reason}";
        }

        throw new RuntimeException("Semua provider {$this->kind} gagal:\n" . implode("\n", $lines));
    }

    /** Forget every recorded failure, e.g. right before the user retries a render. */
    public static function resetCooldowns(): void
    {
        self::$localCooldowns = [];
        self::$localEpoch++;

        try {
            Cache::add('provider_chain:epoch', 1);
            Cache::increment('provider_chain:epoch');
        } catch (Throwable) {
        }
    }

    private function key(string $label): string
    {
        try {
            $epoch = Cache::get('provider_chain:epoch', 1);
        } catch (Throwable) {
            $epoch = 'local' . self::$localEpoch;
        }

        return "provider_chain:{$epoch}:{$this->kind}:{$label}";
    }

    private function isCoolingDown(string $label): bool
    {
        $key = $this->key($label);

        if ((self::$localCooldowns[$key] ?? 0) > time()) {
            return true;
        }

        try {
            return Cache::has($key);
        } catch (Throwable) {
            return false;
        }
    }

    private function trip(string $label, Throwable $e): void
    {
        $minutes = $e instanceof ConnectionException ? self::CONNECTION_COOLDOWN_MINUTES : self::COOLDOWN_MINUTES;
        $key = $this->key($label);
        self::$localCooldowns[$key] = time() + $minutes * 60;

        try {
            Cache::put($key, true, now()->addMinutes($minutes));
        } catch (Throwable) {
        }
    }

    /**
     * 9Router puts a failing upstream account on a cooldown and says how long ("reset after
     * 30s"); retrying sooner than that just bounces off the same wall, so wait it out
     * (capped, so one bad provider can't stall a scene for minutes).
     */
    private function retryDelay(Throwable $e, int $attempt): int
    {
        if (preg_match('/reset after (\d+)s/i', $e->getMessage(), $m)) {
            return min(60, (int) $m[1] + 2);
        }

        return self::TRANSIENT_RETRY_DELAYS[$attempt];
    }

    private function isTransient(Throwable $e): bool
    {
        $message = $e->getMessage();

        if (preg_match('/quota|credit|billing|entitle|not supported|exhausted|subscription|permission|unauthori|forbidden|invalid|does not exist|not found|\b(?:400|401|403|404|429)\b/i', $message)) {
            return false;
        }

        return $e instanceof ConnectionException
            || (bool) preg_match('/server exception|try again|temporar|timed? ?out|overloaded|unavailable|\b(?:500|502|503|504)\b/i', $message);
    }

    private function summarize(Throwable $e): string
    {
        // Upstream errors arrive as JSON-in-text with long nested detail; the first
        // couple of lines are what tells a human whether it's quota, credits or auth.
        return mb_substr(trim(preg_replace('/\s+/', ' ', $e->getMessage())), 0, 220);
    }
}
