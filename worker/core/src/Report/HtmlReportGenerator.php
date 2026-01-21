<?php

declare(strict_types=1);

namespace Nod32Mirror\Report;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\ReportGeneratorInterface;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;

final class HtmlReportGenerator implements ReportGeneratorInterface
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

        $scriptConfig = $this->config->getOrDefault('script', []);
        $generateConfig = $scriptConfig['generate'] ?? [];
        $htmlConfig = $generateConfig['html'] ?? [];
        $onlyTable = !empty($htmlConfig['only_table']);
        $exportCredentials = !empty($generateConfig['export_credentials']);
        $codepage = $htmlConfig['codepage'] ?? 'utf-8';

        $esc = fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, $codepage);

        $html = '';

        if (!$onlyTable) {
            $html .= '<!DOCTYPE HTML>';
            $html .= '<html>';
            $html .= '<head>';
            $html .= '<title>' . $esc($this->language->t('report.title_update_server')) . '</title>';
            $html .= '<meta http-equiv="Content-Type" content="text/html; charset=' . $codepage . '">';
            $html .= '<style type="text/css">html,body{height:100%;margin:0;padding:0;width:100%}table#center{border:0;height:100%;width:100%}table td table td{text-align:center;vertical-align:middle;font-weight:bold;padding:10px 15px;border:0}table tr:nth-child(odd){background:#eee}table tr:nth-child(even){background:#fc0}</style>';
            $html .= '</head>';
            $html .= '<body>';
            $html .= '<table id="center">';
            $html .= '<tr>';
            $html .= '<td align="center">';
        }

        $html .= '<table>';
        $html .= '<tr><td colspan="5">' . $esc($this->language->t('report.title_update_server')) . '</td></tr>';
        $html .= '<tr>';
        $html .= '<td>' . $esc($this->language->t('common.version')) . '</td>';
        $html .= '<td>' . $esc($this->language->t('report.platforms')) . '</td>';
        $html .= '<td>' . $esc($this->language->t('report.database_version')) . '</td>';
        $html .= '<td>' . $esc($this->language->t('report.database_size')) . '</td>';
        $html .= '<td>' . $esc($this->language->t('report.last_update')) . '</td>';
        $html .= '</tr>';

        $versions = $metadata['versions'] ?? [];

        foreach ($versions as $version => $info) {
            $platformsDisplay = !empty($info['platforms'])
                ? implode(', ', $info['platforms'])
                : $this->language->t('common.na');

            $dbVersion = $info['database']['version'] ?? null;
            $timestamp = $info['database']['last_update_ts'] ?? null;
            $sizeBytes = $info['database']['size']['bytes'] ?? null;

            $sizeDisplay = $sizeBytes !== null
                ? Tools::bytesToSize1024((int) $sizeBytes)
                : $this->language->t('common.na');

            $timestampDisplay = $timestamp
                ? date('Y-m-d, H:i:s', (int) $timestamp)
                : $this->language->t('common.na');

            $html .= '<tr>';
            $html .= '<td>' . $esc($info['name'] ?? $version) . '</td>';
            $html .= '<td>' . $esc($platformsDisplay) . '</td>';
            $html .= '<td>' . $esc($dbVersion) . '</td>';
            $html .= '<td>' . $esc($sizeDisplay) . '</td>';
            $html .= '<td>' . $esc($timestampDisplay) . '</td>';
            $html .= '</tr>';
        }

        $lastExec = $metadata['last_update_ts'] ?? time();
        $html .= '<tr>';
        $html .= '<td colspan="2">' . $esc($this->language->t('report.last_execution')) . '</td>';
        $html .= '<td colspan="3">' . $esc(date('Y-m-d, H:i:s', (int) $lastExec)) . '</td>';
        $html .= '</tr>';

        if ($exportCredentials && !empty($metadata['versions'])) {
            $html .= '<tr>';
            $html .= '<td>' . $esc($this->language->t('common.version')) . '</td>';
            $html .= '<td>' . $esc($this->language->t('report.used_login')) . '</td>';
            $html .= '<td>' . $esc($this->language->t('report.used_password')) . '</td>';
            $html .= '</tr>';

            foreach ($versions as $version => $info) {
                $credentials = $info['credentials'] ?? [];

                foreach ($credentials as $cred) {
                    $html .= '<tr>';
                    $html .= '<td>' . $esc($cred['version'] ?? $version) . '</td>';
                    $html .= '<td>' . $esc($cred['login'] ?? '') . '</td>';
                    $html .= '<td>' . $esc($cred['password'] ?? '') . '</td>';
                    $html .= '</tr>';
                }
            }
        }

        $html .= '</table>';

        if (!$onlyTable) {
            $html .= '</td></tr></table></body></html>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function save(array $metadata, string $targetPath): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->log->info($this->language->t('report.generating_html'));

        $html = $this->generate($metadata);

        $scriptConfig = $this->config->getOrDefault('script', []);
        $generateConfig = $scriptConfig['generate'] ?? [];
        $htmlConfig = $generateConfig['html'] ?? [];
        $codepage = $htmlConfig['codepage'] ?? 'utf-8';

        $dir = dirname($targetPath);
        Tools::ensureDirectory($dir);

        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }

        $content = Tools::convertEncoding($html, $codepage);
        file_put_contents($targetPath, $content);

        $this->log->debug($this->language->t('report.saved_to', $targetPath));
    }
}
