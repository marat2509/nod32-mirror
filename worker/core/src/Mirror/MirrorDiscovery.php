<?php

declare(strict_types=1);

namespace Nod32Mirror\Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Status\StatusReporter;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\MirrorInfo;

final class MirrorDiscovery
{
    public function __construct(
        private readonly DownloaderInterface $downloader,
        private readonly Parser $parser,
        private readonly MirrorHostValidator $hostValidator,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language,
        private readonly StatusReporter $statusReporter
    ) {
    }

    /**
     * @param string[] $bootstrapMirrors
     * @param array<string, string> $sourcePaths Map of version => update.ver path
     * @return string[]
     */
    public function discover(array $bootstrapMirrors, Credential $credential, array $sourcePaths): array
    {
        if (!$this->config->isMirrorDiscoveryEnabled()) {
            return [];
        }

        $sourcePaths = $this->filterSourcePaths($sourcePaths);
        if ($sourcePaths === [] || $bootstrapMirrors === []) {
            return [];
        }

        $this->log->info($this->language->t('mirror.discovery_started', count($sourcePaths)));
        $discovered = [];

        foreach ($sourcePaths as $version => $sourcePath) {
            foreach ($bootstrapMirrors as $bootstrapMirror) {
                $this->statusReporter->setCurrent(
                    StatusPhase::SelectingMirrors,
                    StatusAction::DownloadUpdateVer,
                    $this->language->t('status.message.discovering_mirrors', $version, $bootstrapMirror),
                    version: $version,
                    mirror: $bootstrapMirror
                );

                $temporaryPath = Tools::ds(
                    $this->config->getTmpDir(),
                    sprintf('mirror_discovery_%s_%s.ver', md5($version), md5($bootstrapMirror))
                );

                try {
                    $url = (new MirrorInfo($bootstrapMirror))->buildUrl($sourcePath);
                    $result = $this->downloader->downloadToFile($url, $temporaryPath, $credential);
                    if (!$result->isSuccessful()) {
                        $this->log->debug(
                            $this->language->t('mirror.discovery_fetch_failed', $bootstrapMirror, $version)
                        );
                        continue;
                    }

                    $content = @file_get_contents($temporaryPath);
                    if ($content === false) {
                        continue;
                    }

                    $advertisedHosts = $this->parser->parseUpdateServers($content);
                    $this->log->debug($this->language->t(
                        'mirror.discovery_source_result',
                        $version,
                        $bootstrapMirror,
                        count($advertisedHosts)
                    ));

                    if ($advertisedHosts === []) {
                        continue;
                    }

                    foreach ($advertisedHosts as $advertisedHost) {
                        $normalizedHost = $this->hostValidator->normalize($advertisedHost);
                        if ($normalizedHost === null) {
                            $this->log->debug($this->language->t(
                                'mirror.discovery_host_rejected',
                                $advertisedHost
                            ));
                            continue;
                        }

                        $discovered[strtolower($normalizedHost)] = $normalizedHost;
                    }

                    break;
                } finally {
                    @unlink($temporaryPath);
                }
            }
        }

        $hosts = array_values($discovered);
        natcasesort($hosts);
        $hosts = array_values(array_slice($hosts, 0, $this->config->getMirrorDiscoveryMaxHosts()));

        $this->log->info($this->language->t('mirror.discovery_complete', count($hosts)));
        return $hosts;
    }

    /**
     * @param array<string, string> $sourcePaths
     * @return array<string, string>
     */
    private function filterSourcePaths(array $sourcePaths): array
    {
        $versions = $this->config->getMirrorDiscoveryVersions();
        if ($versions === true) {
            return $sourcePaths;
        }

        $selected = array_fill_keys(array_map('strtolower', $versions), true);
        return array_filter(
            $sourcePaths,
            static fn(string $version): bool => isset($selected[strtolower($version)]),
            ARRAY_FILTER_USE_KEY
        );
    }
}
