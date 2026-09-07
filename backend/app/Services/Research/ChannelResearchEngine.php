<?php

namespace App\Services\Research;

use App\Models\ContentChannel;
use App\Models\ContentIdea;
use App\Models\ContentIdeaSource;
use App\Models\ResearchResult;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\AI\DTOs\ContentIdeaData;
use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use App\Services\Research\Engine\ContentIdeaPromptBuilder;
use App\Services\Research\Engine\DuplicateDetector;
use App\Services\Research\Engine\ResearchQueryBuilder;
use App\Services\Research\Engine\ResearchTopic;
use App\Services\Research\Engine\TextSignature;
use App\Services\Research\Engine\TopicExtractor;
use App\Services\Research\Engine\TopicScorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The research pipeline for ONE channel, end to end:
 *
 *   load config -> build queries -> query each source -> normalize -> dedupe ->
 *   cluster into topics -> cross-source correlation -> score -> AI ideas ->
 *   duplicate check -> persist evidence + ideas
 *
 * Two fault-tolerance rules are structural here, not incidental:
 *  - one provider failing is recorded against that source and the run continues
 *    (status PARTIAL), and
 *  - the engine never knows any provider by name — it iterates whatever the
 *    channel has configured, resolved through ResearchProviderManager.
 */
class ChannelResearchEngine
{
    public function __construct(
        private readonly ResearchProviderManager $providers,
        private readonly ResearchQueryBuilder $queryBuilder,
        private readonly TopicExtractor $extractor,
        private readonly TopicScorer $scorer,
        private readonly ContentIdeaProvider $ideaProvider,
        private readonly ContentIdeaPromptBuilder $promptBuilder,
    ) {}

    public function run(ResearchRun $run): ResearchRun
    {
        $channel = $run->channel()->with(['platform', 'researchSources'])->firstOrFail();
        $startedAt = microtime(true);

        $run->update([
            'status' => ResearchRun::STATUS_RUNNING,
            'started_at' => now(),
            'progress' => 5,
            'message' => 'Mengumpulkan data riset...',
        ]);

        Log::info('research.run.start', ['run_id' => $run->id, 'channel_id' => $channel->id, 'channel' => $channel->name]);

        try {
            [$items, $sourceWeights, $used, $failed, $skipped] = $this->collect($channel);

            $run->update(['progress' => 40, 'message' => 'Menormalisasi hasil...', 'results_collected' => count($items)]);

            $query = $this->firstQueryFor($channel);
            $items = $this->extractor->applyExclusions($this->extractor->deduplicate($items), $query);

            $topics = $this->scorer->score($this->extractor->cluster($items, $sourceWeights), $channel);
            $topics = $this->applyThresholds($topics, $channel);
            $topics = array_slice($topics, 0, (int) config('research.topics.max_per_run', 25));

            $run->update(['progress' => 60, 'message' => 'Menyusun ide konten...', 'topics_found' => count($topics)]);

            $resultIdsByTopic = $this->persistResults($run, $channel, $topics);

            [$ideasCreated, $duplicatesSkipped] = $this->generateAndStoreIdeas($run, $channel, $topics, $resultIdsByTopic);

            $status = $this->resolveStatus($failed, $used, $ideasCreated);

            $run->update([
                'status' => $status,
                'progress' => 100,
                'message' => $this->summaryMessage($status, count($topics), $ideasCreated, count($failed)),
                'ideas_generated' => $ideasCreated,
                'duplicates_skipped' => $duplicatesSkipped,
                'providers_used' => $used,
                'providers_failed' => array_merge($failed, $skipped),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'finished_at' => now(),
            ]);

            Log::info('research.run.finish', [
                'run_id' => $run->id,
                'status' => $status,
                'topics' => count($topics),
                'ideas' => $ideasCreated,
                'duplicates_skipped' => $duplicatesSkipped,
                'providers_failed' => array_column($failed, 'source_key'),
                'duration_ms' => $run->duration_ms,
            ]);
        } catch (Throwable $e) {
            // Only a failure of the engine itself lands here — provider failures are
            // caught per source inside collect(). A channel failing must not affect any
            // other channel, which is why ResearchChannelJob catches nothing further.
            $run->update([
                'status' => ResearchRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'message' => 'Riset gagal.',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'finished_at' => now(),
            ]);

            Log::error('research.run.failed', [
                'run_id' => $run->id,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * Query every source this channel has enabled.
     *
     * @return array{0: ResearchItem[], 1: array<string, float>, 2: array<int, array<string, mixed>>, 3: array<int, array<string, string>>, 4: array<int, array<string, string>>}
     */
    private function collect(ContentChannel $channel): array
    {
        $items = [];
        $sourceWeights = [];
        $used = [];
        $failed = [];
        $skipped = [];

        $sources = $channel->researchSources()
            ->wherePivot('enabled', true)
            ->where('research_sources.enabled', true)
            ->orderByPivot('priority')
            ->get();

        foreach ($sources as $source) {
            /** @var ResearchSource $source */
            $reason = $this->skipReason($source);

            if ($reason !== null) {
                $skipped[] = ['source_key' => $source->key, 'error' => $reason, 'skipped' => true];

                continue;
            }

            $weight = (float) ($source->pivot->weight ?? 1.0);
            $sourceWeights[$source->key] = $weight;

            try {
                $provider = $this->providers->resolve($source->provider);
                $query = $this->queryBuilder->build($channel, $source);

                // Both signals are gathered: trending answers "what is hot regardless of
                // my keywords", search answers "what is happening in my niche". Providers
                // with no trending concept return [], so this costs nothing for them.
                $fetched = array_merge($provider->search($query), $provider->trending($query));

                foreach ($fetched as $item) {
                    $items[] = $item;
                }

                $source->markSuccess();
                $used[] = ['source_key' => $source->key, 'items' => count($fetched)];

                Log::info('research.provider.ok', ['source' => $source->key, 'items' => count($fetched)]);
            } catch (Throwable $e) {
                // One provider down must never end the run — record it, mark the source,
                // move to the next one (spec sections 22 and 32).
                $source->markFailure($e->getMessage());
                $failed[] = ['source_key' => $source->key, 'error' => mb_substr($e->getMessage(), 0, 500)];

                Log::warning('research.provider.failed', ['source' => $source->key, 'error' => $e->getMessage()]);
            }
        }

        return [$items, $sourceWeights, $used, $failed, $skipped];
    }

    /**
     * Why this source cannot run right now, or null if it can.
     */
    private function skipReason(ResearchSource $source): ?string
    {
        if (! $this->providers->has($source->provider)) {
            return "Provider [{$source->provider}] tidak terdaftar.";
        }

        if ($source->isCircuitOpen()) {
            // Repeated failures stop being retried every run until a manual test
            // succeeds — otherwise every run pays the full timeout for a dead endpoint.
            return sprintf('Dilewati: %d kegagalan berturut-turut. Jalankan Test Connection untuk mengaktifkan lagi.', $source->consecutive_failures);
        }

        $provider = $this->providers->resolve($source->provider);

        if ($provider->requiresCredentials() && ! $provider->isConfigured()) {
            return 'Dilewati: kredensial belum dikonfigurasi.';
        }

        return null;
    }

    /**
     * Applies the channel's minimum score gates. Enforced here rather than inside
     * the scorer so the raw scores stay visible in the run for debugging.
     *
     * @param  ResearchTopic[]  $topics
     * @return ResearchTopic[]
     */
    private function applyThresholds(array $topics, ContentChannel $channel): array
    {
        $minRelevance = (int) $channel->min_relevance_score;
        $minTrend = (int) $channel->min_trend_score;

        if ($minRelevance <= 0 && $minTrend <= 0) {
            return $topics;
        }

        $passing = array_values(array_filter(
            $topics,
            fn (ResearchTopic $topic) => $topic->relevanceScore >= $minRelevance && $topic->trendScore >= $minTrend,
        ));

        // Thresholds set too high would otherwise produce a silently empty run every
        // day. Keeping the best few makes the miscalibration visible in the UI (low
        // scores on the ideas) instead of looking like the sources are broken.
        return $passing !== [] ? $passing : array_slice($topics, 0, 3);
    }

    /**
     * @param  ResearchTopic[]  $topics
     * @return array<int, int[]> topic index => research_result ids
     */
    private function persistResults(ResearchRun $run, ContentChannel $channel, array $topics): array
    {
        $idsByTopic = [];

        foreach ($topics as $index => $topic) {
            $topicKey = TextSignature::fingerprint($topic->label);
            $ids = [];

            foreach ($topic->evidence(10) as $item) {
                $result = ResearchResult::create([
                    'research_run_id' => $run->id,
                    'content_channel_id' => $channel->id,
                    'source_key' => $item->sourceKey,
                    'external_id' => $item->externalId,
                    'title' => $item->title,
                    'url' => $item->url,
                    'summary' => $item->summary,
                    'author' => $item->author,
                    'published_at' => $item->publishedAt,
                    'discovered_at' => now(),
                    'engagement' => $item->engagement,
                    'source_score' => min(100, (int) ($item->engagement['score'] ?? 0)),
                    'topic_key' => $topicKey,
                    'raw' => $item->raw,
                ]);

                $ids[] = $result->id;
            }

            $idsByTopic[$index] = $ids;
        }

        return $idsByTopic;
    }

    /**
     * @param  ResearchTopic[]  $topics
     * @param  array<int, int[]>  $resultIdsByTopic
     * @return array{0: int, 1: int} [ideas created, duplicates skipped]
     */
    private function generateAndStoreIdeas(ResearchRun $run, ContentChannel $channel, array $topics, array $resultIdsByTopic): array
    {
        if ($topics === []) {
            return [0, 0];
        }

        $detector = (new DuplicateDetector)->loadFor($channel);
        $wanted = max(1, min((int) config('research.ideas.max_per_run', 20), (int) $channel->ideas_per_run));

        $context = $this->promptBuilder->build(
            $channel,
            $topics,
            $detector->previousTitles((int) config('research.ideas.previous_content_sample', 40)),
        );

        $ideas = $this->ideaProvider->generateIdeas($context, $wanted);

        if ($ideas === []) {
            Log::warning('research.ideas.empty', ['run_id' => $run->id, 'topics' => count($topics)]);

            return [0, 0];
        }

        $created = 0;
        $duplicates = 0;

        foreach ($ideas as $idea) {
            $topicIndex = $this->matchTopicIndex($idea, $topics);
            $topic = $topics[$topicIndex] ?? null;

            if ($topic === null) {
                continue;
            }

            $dedupeText = $idea->title.' '.$idea->topic.' '.implode(' ', $idea->keywords);

            if ($detector->isDuplicate($dedupeText)) {
                $duplicates++;
                Log::info('research.idea.duplicate_skipped', ['run_id' => $run->id, 'title' => $idea->title]);

                continue;
            }

            // Measured originality wins over the model's self-assessment when the model
            // is the more optimistic of the two — a model rating its own idea 95 while
            // the channel already has something 70% similar is exactly the failure mode
            // the duplicate check exists for.
            $measured = $detector->originalityScore($dedupeText);
            $originality = $idea->originalityScore === null ? $measured : min($idea->originalityScore, $measured);
            $this->scorer->applyOriginality($topic, $originality, $channel);

            DB::transaction(function () use ($run, $channel, $idea, $topic, $topicIndex, $resultIdsByTopic, &$created) {
                $model = ContentIdea::create([
                    'content_channel_id' => $channel->id,
                    'research_run_id' => $run->id,
                    'user_id' => $channel->user_id,
                    'research_date' => now($channel->timezone ?: config('app.timezone'))->toDateString(),
                    'topic' => $idea->topic,
                    'title' => $idea->title,
                    'alternative_titles' => $idea->alternativeTitles,
                    'short_description' => $idea->shortDescription,
                    'content_angle' => $idea->contentAngle,
                    'why_this_topic' => $idea->whyThisTopic,
                    'target_audience' => $idea->targetAudience ?? $channel->target_audience,
                    'keywords' => $idea->keywords,
                    'source_summary' => $this->sourceSummary($topic),
                    'trend_score' => $topic->trendScore,
                    'relevance_score' => $topic->relevanceScore,
                    'originality_score' => $topic->originalityScore,
                    'freshness_score' => $topic->freshnessScore,
                    'engagement_score' => $topic->engagementScore,
                    'cross_source_score' => $topic->crossSourceScore,
                    'priority_score' => $topic->priorityScore,
                    'suggested_content_type' => $idea->suggestedContentType,
                    'suggested_format' => $idea->suggestedFormat,
                    'status' => ContentIdea::STATUS_IDEA,
                    'fingerprint' => TextSignature::fingerprint($idea->title.' '.$idea->topic),
                ]);

                $this->attachEvidence($model, $topic, $resultIdsByTopic[$topicIndex] ?? []);

                $created++;
            });

            // Registered so a second idea in this same run cannot duplicate this one.
            $detector->remember($dedupeText);
        }

        return [$created, $duplicates];
    }

    /**
     * @param  int[]  $resultIds
     */
    private function attachEvidence(ContentIdea $idea, ResearchTopic $topic, array $resultIds): void
    {
        foreach ($topic->evidence(10) as $position => $item) {
            ContentIdeaSource::create([
                'content_idea_id' => $idea->id,
                'research_result_id' => $resultIds[$position] ?? null,
                'source_key' => $item->sourceKey,
                'source_title' => $item->title,
                'source_url' => $item->url,
                'extracted_summary' => $item->summary,
                'engagement_metrics' => $item->engagement,
                'source_score' => min(100, (int) ($item->engagement['score'] ?? 0)),
                'published_at' => $item->publishedAt,
                'discovered_at' => now(),
            ]);
        }
    }

    /**
     * Maps a generated idea back to the topic it came from.
     *
     * Prefers the topic_ref the model was asked to echo; falls back to title token
     * similarity, because attaching an idea to the wrong topic would attach the
     * wrong evidence to it — a worse outcome than any scoring inaccuracy.
     *
     * @param  ResearchTopic[]  $topics
     */
    private function matchTopicIndex(ContentIdeaData $idea, array $topics): int
    {
        if ($idea->topicRef !== null && preg_match('/^T(\d+)$/i', $idea->topicRef, $matches) === 1) {
            $index = ((int) $matches[1]) - 1;
            if (isset($topics[$index])) {
                return $index;
            }
        }

        $tokens = TextSignature::tokens($idea->topic.' '.$idea->title);
        $bestIndex = 0;
        $bestSimilarity = -1.0;

        foreach ($topics as $index => $topic) {
            $similarity = TextSignature::similarity($tokens, $topic->tokens);
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function sourceSummary(ResearchTopic $topic): string
    {
        return sprintf(
            'Ditemukan di %d sumber (%s) dari %d hasil riset.',
            $topic->distinctSourceCount(),
            implode(', ', $topic->sourceKeys()),
            count($topic->items),
        );
    }

    /**
     * @param  array<int, array<string, string>>  $failed
     * @param  array<int, array<string, mixed>>  $used
     */
    private function resolveStatus(array $failed, array $used, int $ideasCreated): string
    {
        if ($used === [] && $failed !== []) {
            // Every configured source failed — nothing was retrieved, so this is a real
            // failure, not a partial result.
            return ResearchRun::STATUS_FAILED;
        }

        if ($failed !== []) {
            return ResearchRun::STATUS_PARTIAL;
        }

        return $ideasCreated > 0 ? ResearchRun::STATUS_SUCCESS : ResearchRun::STATUS_PARTIAL;
    }

    private function summaryMessage(string $status, int $topics, int $ideas, int $failedCount): string
    {
        if ($status === ResearchRun::STATUS_FAILED) {
            return 'Semua sumber riset gagal dihubungi.';
        }

        $message = sprintf('%d topik, %d ide konten.', $topics, $ideas);

        if ($failedCount > 0) {
            $message .= sprintf(' %d sumber gagal.', $failedCount);
        }

        return $message;
    }

    /**
     * A query built without a specific source, used for channel-level filtering
     * (excluded keywords) that is not source-dependent.
     */
    private function firstQueryFor(ContentChannel $channel): ResearchQuery
    {
        return $this->queryBuilder->build($channel, new ResearchSource(['key' => 'internal', 'provider' => 'internal']));
    }
}
