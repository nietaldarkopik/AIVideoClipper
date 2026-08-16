<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by a queue job's own cooperative cancellation check (see
 * App\Jobs\Concerns\ChecksCancellation) when it notices its ProcessingJob row was
 * marked cancelled — either by a user hitting "Stop" or by the stalled-job watchdog
 * (App\Console\Commands\ReapStalledProcessingJobs). Jobs catch this separately from
 * a generic Throwable: it's an intentional stop, not a failure, so it must not be
 * retried and must not overwrite the ProcessingJob's status back to "failed".
 */
class JobCancelledException extends RuntimeException
{
}
