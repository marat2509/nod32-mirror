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

        $json = Tools::jsonEncodePrettyTabs($metadata);

        if ($json === false) {
            $this->log->warning($this->language->t('report.json_encoding_failed', json_last_error_msg()));
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
        if (!Tools::writeJsonPrettyTabsFile($targetPath, $metadata)) {
            $this->log->warning($this->language->t('report.json_encoding_failed', json_last_error_msg()));
            return;
        }

        $this->log->debug($this->language->t('report.saved_to', $targetPath));
    }
}
