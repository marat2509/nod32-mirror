<?php

declare(strict_types=1);

namespace Nod32Mirror\Report;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\ReportGeneratorInterface;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;

final class JsonReportGenerator implements ReportGeneratorInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function generate(array $metadata): string
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->log->warning('JSON encoding failed: ' . json_last_error_msg());
            return '{}';
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function save(array $metadata, string $targetPath): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->log->info($this->language->t('report.generating_json'));

        $json = $this->generate($metadata);

        $dir = dirname($targetPath);
        Tools::ensureDirectory($dir);

        file_put_contents($targetPath, $json . PHP_EOL);

        $this->log->debug($this->language->t('report.saved_to', $targetPath));
    }

    public function getDefaultFilename(): string
    {
        $scriptConfig = $this->config->getOrDefault('script', []);
        $generateConfig = $scriptConfig['generate'] ?? [];
        $jsonConfig = $generateConfig['json'] ?? [];

        return $jsonConfig['filename'] ?? 'index.json';
    }
}
