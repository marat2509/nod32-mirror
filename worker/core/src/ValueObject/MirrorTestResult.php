<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

/**
 * Result of mirror speed test
 */
final readonly class MirrorTestResult
{
    /**
     * @param string $host Mirror host
     * @param float $averageResponseTime Average response time in milliseconds
     * @param int $successCount Number of successful tests
     * @param int $failCount Number of failed tests
     * @param float[] $responseTimes Individual response times
     */
    public function __construct(
        public string $host,
        public float $averageResponseTime,
        public int $successCount,
        public int $failCount,
        public array $responseTimes = []
    ) {
    }

    public function isUsable(): bool
    {
        return $this->successCount > 0;
    }

    public function getSuccessRate(): float
    {
        $total = $this->successCount + $this->failCount;
        if ($total === 0) {
            return 0.0;
        }
        return $this->successCount / $total;
    }

    /**
     * Calculate weighted score (lower is better)
     * Combines response time with success rate penalty
     */
    public function getScore(): float
    {
        if ($this->successCount === 0) {
            return PHP_FLOAT_MAX;
        }

        // Base score is average response time
        $score = $this->averageResponseTime;

        // Penalize low success rate (multiply by inverse of success rate)
        $successRate = $this->getSuccessRate();
        if ($successRate < 1.0) {
            $score *= (1 / max($successRate, 0.1));
        }

        return $score;
    }

    public static function failed(string $host): self
    {
        return new self(
            host: $host,
            averageResponseTime: PHP_FLOAT_MAX,
            successCount: 0,
            failCount: 1
        );
    }
}
