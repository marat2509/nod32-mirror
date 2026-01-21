<?php

declare(strict_types=1);

namespace Nod32Mirror\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger as MonologLogger;
use Nod32Mirror\Config\Config;
use Nod32Mirror\Enum\LogLevel;
use Nod32Mirror\Tools;

final class Log
{
    private MonologLogger $logger;
    private ?StreamHandler $fileHandler = null;
    private ?StreamHandler $stdoutHandler = null;

    /** @var array<string, mixed> */
    private array $logConfig = [];

    private bool $initialized = false;

    public function __construct(
        private readonly Config $config,
        private readonly Language $language
    ) {
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->logConfig = $this->config->getOrDefault('log', []);
        $this->buildLogger();
        $this->initialized = true;
    }

    public function error(string $text, ?string $version = null, ?string $channel = null): void
    {
        $this->log(LogLevel::Error, $text, $version, $channel);
    }

    public function warning(string $text, ?string $version = null, ?string $channel = null): void
    {
        $this->log(LogLevel::Warning, $text, $version, $channel);
    }

    public function info(string $text, ?string $version = null, ?string $channel = null): void
    {
        $this->log(LogLevel::Info, $text, $version, $channel);
    }

    public function debug(string $text, ?string $version = null, ?string $channel = null): void
    {
        $this->log(LogLevel::Debug, $text, $version, $channel);
    }

    public function trace(string $text, ?string $version = null, ?string $channel = null): void
    {
        $this->log(LogLevel::Trace, $text, $version, $channel);
    }

    public function informer(string $text, ?string $version, LogLevel $level = LogLevel::Notice, ?string $channel = null): void
    {
        $this->log($level, $text, $version, $channel);
    }

    public function log(LogLevel $level, string $text, ?string $version = null, ?string $channel = null): void
    {
        if (empty($text)) {
            return;
        }

        if (!$this->initialized) {
            error_log($this->formatFallback($text, $level, $version, $channel));
            return;
        }

        $fileConfig = $this->logConfig['file'] ?? [];
        $stdoutConfig = $this->logConfig['stdout'] ?? [];

        $logToFile = $this->isChannelEnabled($fileConfig, $level);
        $logToStdout = $this->isChannelEnabled($stdoutConfig, $level);

        if (!$logToFile && !$logToStdout) {
            return;
        }

        $versionLabel = $this->formatVersionLabel($version, $channel);
        $monologLevel = $this->mapToMonologLevel($level);
        $finalMessage = $versionLabel . ($level === LogLevel::Trace ? '[trace] ' . $text : $text);

        if ($logToFile && !empty($fileConfig['rotate']['enabled'])) {
            $this->rotateIfNeeded($fileConfig);
        }

        $this->logger->log($monologLevel, $finalMessage);
    }

    private function buildLogger(): void
    {
        $this->logger = new MonologLogger('nod32ms');

        $formatter = new LineFormatter(
            "[%datetime%] [%level_name%] %message%\n",
            'Y-m-d, H:i:s',
            true,
            true
        );

        $fileConfig = $this->logConfig['file'] ?? [];
        if (!empty($fileConfig['enabled'])) {
            $filePath = Tools::ds($fileConfig['dir'] ?? 'log', LOG_FILE);
            $minLevel = $this->mapToMonologLevel(LogLevel::fromMixed($fileConfig['level'] ?? LogLevel::Debug));

            $this->fileHandler = new StreamHandler($filePath, $minLevel);
            $this->fileHandler->setFormatter($formatter);
            $this->logger->pushHandler($this->fileHandler);
        }

        $stdoutConfig = $this->logConfig['stdout'] ?? [];
        if (!empty($stdoutConfig['enabled'])) {
            $minLevel = $this->mapToMonologLevel(LogLevel::fromMixed($stdoutConfig['level'] ?? LogLevel::Debug));

            $this->stdoutHandler = new StreamHandler('php://stdout', $minLevel);
            $this->stdoutHandler->setFormatter($formatter);
            $this->logger->pushHandler($this->stdoutHandler);
        }
    }

    /**
     * @param array<string, mixed> $fileConfig
     */
    private function rotateIfNeeded(array $fileConfig): void
    {
        if (empty($fileConfig['enabled'])) {
            return;
        }

        $limit = (int) ($fileConfig['rotate']['size'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $filePath = Tools::ds($fileConfig['dir'] ?? 'log', LOG_FILE);
        if (!file_exists($filePath)) {
            return;
        }

        clearstatcache(true, $filePath);
        if (filesize($filePath) < $limit) {
            return;
        }

        $archExt = Tools::getArchiveExtension();
        $qty = (int) ($fileConfig['rotate']['qty'] ?? 5);

        for ($i = $qty; $i > 1; $i--) {
            @unlink($filePath . '.' . $i . $archExt);
            @rename($filePath . '.' . ($i - 1) . $archExt, $filePath . '.' . $i . $archExt);
        }

        @unlink($filePath . '.1' . $archExt);
        Tools::archiveFile($filePath);
        @unlink($filePath);

        if ($this->fileHandler !== null) {
            $this->fileHandler->close();
        }

        $this->buildLogger();
    }

    private function mapToMonologLevel(LogLevel $level): MonologLevel
    {
        return match ($level) {
            LogLevel::Error => MonologLevel::Error,
            LogLevel::Warning => MonologLevel::Warning,
            LogLevel::Notice => MonologLevel::Notice,
            LogLevel::Info => MonologLevel::Info,
            LogLevel::Debug, LogLevel::Trace => MonologLevel::Debug,
        };
    }

    private function formatVersionLabel(?string $version, ?string $channel = null): string
    {
        if ($version === null || $version === '') {
            return '';
        }

        $label = '[' . $version;

        if (!empty($channel)) {
            $label .= ' (' . $channel . ')';
        }

        return $label . '] ';
    }

    private function formatFallback(string $message, LogLevel $level, ?string $version, ?string $channel): string
    {
        $versionLabel = $version !== null ? $this->formatVersionLabel($version, $channel) : '';
        $levelName = strtoupper($level->label());

        return sprintf('[bootstrap][%s] %s%s', $levelName, $versionLabel, $message);
    }

    /**
     * @param array<string, mixed> $channelConfig
     */
    private function isChannelEnabled(array $channelConfig, LogLevel $level): bool
    {
        if (empty($channelConfig['enabled'])) {
            return false;
        }

        $channelLevel = LogLevel::fromMixed($channelConfig['level'] ?? LogLevel::Info);

        return $level->isEnabled($channelLevel);
    }
}
