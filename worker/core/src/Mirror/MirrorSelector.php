<?php

declare(strict_types=1);

namespace Nod32Mirror\Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Enum\MirrorStrategy;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\MirrorInfo;
use Nod32Mirror\ValueObject\MirrorTestResult;

/**
 * Service for selecting the best mirror based on configured strategy
 */
final class MirrorSelector
{
    /** @var array<string, MirrorTestResult> Cached test results */
    private array $testResultsCache = [];

    public function __construct(
        private readonly DownloaderInterface $downloader,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Select mirrors based on strategy
     *
     * @param string[] $mirrors List of mirror hosts
     * @param Credential $credential Working credential for testing
     * @param array<string, string> $testUrls Map of version => test file path
     * @return MirrorInfo[] Sorted list of mirrors (best first for 'best' strategy)
     */
    public function selectMirrors(array $mirrors, Credential $credential, array $testUrls): array
    {
        $strategy = $this->config->getMirrorStrategy();

        $this->log->debug($this->language->t('mirror.selection_strategy', $strategy->label()));

        return match ($strategy) {
            MirrorStrategy::Best => $this->selectBestMirrors($mirrors, $credential, $testUrls),
            MirrorStrategy::First => $this->selectFirstMirrors($mirrors),
            default => $this->selectRandomMirrors($mirrors),
        };
    }

    /**
     * Test all mirrors and return sorted by speed
     *
     * @param string[] $mirrors
     * @param array<string, string> $testUrls
     * @return MirrorInfo[]
     */
    private function selectBestMirrors(array $mirrors, Credential $credential, array $testUrls): array
    {
        if (empty($testUrls)) {
            $this->log->warning($this->language->t('mirror.no_test_urls'));
            return $this->selectRandomMirrors($mirrors);
        }

        $this->log->info($this->language->t('mirror.testing_mirrors_speed', count($mirrors)));

        $testResults = [];

        foreach ($mirrors as $mirror) {
            $result = $this->testMirrorSpeed($mirror, $credential, $testUrls);
            $testResults[$mirror] = $result;

            if ($result->isUsable()) {
                $this->log->debug(
                    $this->language->t(
                        'mirror.test_result',
                        $mirror,
                        round($result->averageResponseTime),
                        $result->successCount,
                        $result->failCount
                    )
                );
            } else {
                $this->log->debug($this->language->t('mirror.test_failed', $mirror));
            }
        }

        // Sort by score (lower is better)
        uasort($testResults, static fn(MirrorTestResult $a, MirrorTestResult $b): int =>
            $a->getScore() <=> $b->getScore()
        );

        // Filter usable mirrors and convert to MirrorInfo
        $sortedMirrors = [];
        foreach ($testResults as $host => $result) {
            if ($result->isUsable()) {
                $sortedMirrors[] = (new MirrorInfo($host))
                    ->withResponseTime((int) $result->averageResponseTime);
            }
        }

        if (empty($sortedMirrors)) {
            $this->log->warning($this->language->t('mirror.no_usable_mirrors'));
            return $this->selectRandomMirrors($mirrors);
        }

        $bestMirror = $sortedMirrors[0];
        $this->log->info(
            $this->language->t('mirror.best_mirror_selected', $bestMirror->host, $bestMirror->responseTime ?? 0)
        );

        return $sortedMirrors;
    }

    /**
     * Test a single mirror against all test URLs
     *
     * @param array<string, string> $testUrls
     */
    private function testMirrorSpeed(string $mirror, Credential $credential, array $testUrls): MirrorTestResult
    {
        // Check cache first
        $cacheKey = $this->getCacheKey($mirror, $credential);
        if (isset($this->testResultsCache[$cacheKey])) {
            return $this->testResultsCache[$cacheKey];
        }

        $mirrorInfo = new MirrorInfo($mirror);
        $responseTimes = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($testUrls as $version => $testPath) {
            $url = $mirrorInfo->buildUrl($testPath);

            $result = $this->downloader->checkUrl($url, $credential);

            if ($result->isSuccessful()) {
                // Prefer TTFB (Time to First Byte), fallback to total time
                $responseTime = $result->ttfb ?? $result->totalTime;
                $responseTimeMs = $responseTime * 1000;
                $responseTimes[] = $responseTimeMs;
                $successCount++;
            } else {
                $failCount++;
            }
        }

        if ($successCount === 0) {
            $testResult = MirrorTestResult::failed($mirror);
        } else {
            $averageTime = array_sum($responseTimes) / count($responseTimes);
            $testResult = new MirrorTestResult(
                host: $mirror,
                averageResponseTime: $averageTime,
                successCount: $successCount,
                failCount: $failCount,
                responseTimes: $responseTimes
            );
        }

        // Cache the result
        $this->testResultsCache[$cacheKey] = $testResult;

        return $testResult;
    }

    /**
     * Return mirrors in random order
     *
     * @param string[] $mirrors
     * @return MirrorInfo[]
     */
    private function selectRandomMirrors(array $mirrors): array
    {
        $shuffled = $mirrors;
        shuffle($shuffled);

        return array_map(
            static fn(string $host): MirrorInfo => new MirrorInfo($host),
            $shuffled
        );
    }

    /**
     * Return mirrors in original order
     *
     * @param string[] $mirrors
     * @return MirrorInfo[]
     */
    private function selectFirstMirrors(array $mirrors): array
    {
        return array_map(
            static fn(string $host): MirrorInfo => new MirrorInfo($host),
            $mirrors
        );
    }

    private function getCacheKey(string $mirror, Credential $credential): string
    {
        return md5($mirror . ':' . $credential->login);
    }
}
