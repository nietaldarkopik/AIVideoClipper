<?php

namespace Tests\Unit;

use App\Jobs\AnalyzeVideoJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AnalyzeVideoJobWordSnapTest extends TestCase
{
    /** @var array<int, array{word: string, start: float, end: float}> */
    private array $words = [
        ['word' => 'Halo,', 'start' => 0.0, 'end' => 0.5],
        ['word' => 'ini', 'start' => 0.96, 'end' => 1.24],
        ['word' => 'adalah', 'start' => 1.24, 'end' => 1.46],
        ['word' => 'contoh', 'start' => 1.46, 'end' => 1.9],
        ['word' => 'kalimat', 'start' => 1.9, 'end' => 2.4],
    ];

    private function snap(float $start, float $end, array $words): array
    {
        $job = new AnalyzeVideoJob(1, 1);
        $method = new ReflectionMethod($job, 'snapToWordBoundaries');
        $method->setAccessible(true);

        return $method->invoke($job, $start, $end, $words);
    }

    public function test_pulls_a_boundary_back_out_of_the_middle_of_a_word(): void
    {
        [$start, $end] = $this->snap(1.1, 1.7, $this->words);

        $this->assertSame(0.96, $start);
        $this->assertSame(1.9, $end);
    }

    public function test_leaves_boundaries_already_on_a_word_edge_unchanged(): void
    {
        [$start, $end] = $this->snap(0.96, 1.46, $this->words);

        $this->assertSame(0.96, $start);
        $this->assertSame(1.46, $end);
    }

    public function test_leaves_a_boundary_in_a_silence_gap_unchanged(): void
    {
        [$start, $end] = $this->snap(0.7, 2.4, $this->words);

        $this->assertSame(0.7, $start);
        $this->assertSame(2.4, $end);
    }

    public function test_passes_through_unchanged_when_no_words_are_available(): void
    {
        [$start, $end] = $this->snap(5.0, 10.0, []);

        $this->assertSame(5.0, $start);
        $this->assertSame(10.0, $end);
    }
}
