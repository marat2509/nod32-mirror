<?php

declare(strict_types=1);

namespace Nod32Mirror\Status;

use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\Enum\StatusState;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;
use Throwable;

final class StatusReporter
{
    private const SCHEMA_VERSION = 1;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    /** @var array<string, string> */
    private array $versionNames = [];

    private ?int $startedAtTs = null;
    private ?string $currentSignature = null;

    /**
     * @param array<string, string> $versionNames
     */
    public function __construct(
        private readonly string $targetPath,
        private readonly Language $language,
        array $versionNames = [],
        private readonly bool $enabled = true
    ) {
        $this->versionNames = $versionNames;
    }

    /**
     * @param array<string, string> $versionNames
     */
    public function startRun(string $appVersion, int|string $pid, array $versionNames): void
    {
        $this->versionNames = array_replace($this->versionNames, $versionNames);

        $now = $this->now();
        $this->startedAtTs = time();
        $this->currentSignature = null;

        $items = [];
        foreach ($versionNames as $version => $name) {
            $items[$version] = $this->createVersionItem($name);
        }

        $this->snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $this->stateRef(StatusState::Running),
            'run' => [
                'id' => $now . '-' . $pid,
                'pid' => $pid,
                'app_version' => $appVersion,
                'started_at' => $now,
                'updated_at' => $now,
                'finished_at' => null,
                'elapsed_seconds' => 0,
            ],
            'current' => $this->createCurrent(
                phase: StatusPhase::Booting,
                action: null,
                message: $this->language->t('status.message.run_started'),
                version: null,
                channel: null,
                variant: null,
                mirror: null,
                startedAt: $now,
                updatedAt: $now
            ),
            'versions' => [
                'total' => count($items),
                'pending' => count($items),
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
                'skipped' => 0,
                'current' => null,
                'items' => $items,
            ],
            'storage' => [
                'state' => $this->stateRef(StatusState::Pending),
                'action' => null,
            ],
            'error' => null,
        ];

        $this->write();
    }

    public function setCurrent(
        StatusPhase $phase,
        ?StatusAction $action = null,
        ?string $message = null,
        ?string $version = null,
        ?string $channel = null,
        ?string $variant = null,
        ?string $mirror = null
    ): void {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $signature = implode("\0", [
            $phase->value,
            $action?->value ?? '',
            $version ?? '',
            $channel ?? '',
            $variant ?? '',
            $mirror ?? '',
        ]);

        $startedAt = ($signature === $this->currentSignature)
            ? ($this->snapshot['current']['started_at'] ?? $now)
            : $now;

        $this->currentSignature = $signature;
        $this->snapshot['current'] = $this->createCurrent(
            phase: $phase,
            action: $action,
            message: $message,
            version: $version,
            channel: $channel,
            variant: $variant,
            mirror: $mirror,
            startedAt: is_string($startedAt) ? $startedAt : $now,
            updatedAt: $now
        );

        if ($version !== null) {
            $this->snapshot['versions']['current'] = $version;
        }

        $this->write();
    }

    public function startVersion(string $version, ?StatusAction $action = null, ?string $message = null): void
    {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->ensureVersionItem($version);

        $this->snapshot['versions']['items'][$version]['state'] = $this->stateRef(StatusState::Running);
        $this->snapshot['versions']['items'][$version]['action'] = $this->actionRef($action);
        $this->snapshot['versions']['items'][$version]['started_at'] ??= $now;
        $this->snapshot['versions']['items'][$version]['updated_at'] = $now;
        $this->snapshot['versions']['current'] = $version;

        $this->setCurrent(StatusPhase::ProcessingVersion, $action, $message, $version);
    }

    public function updateVersionAction(
        string $version,
        StatusPhase $phase,
        StatusAction $action,
        ?string $message = null,
        ?string $channel = null,
        ?string $variant = null,
        ?string $mirror = null
    ): void {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->ensureVersionItem($version);

        $this->snapshot['versions']['items'][$version]['action'] = $this->actionRef($action);
        $this->snapshot['versions']['items'][$version]['updated_at'] = $now;

        $this->setCurrent($phase, $action, $message, $version, $channel, $variant, $mirror);
    }

    public function finishVersion(
        string $version,
        StatusState $state,
        ?StatusAction $action = null,
        ?string $message = null
    ): void {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->ensureVersionItem($version);

        $this->snapshot['versions']['items'][$version]['state'] = $this->stateRef($state);
        $this->snapshot['versions']['items'][$version]['action'] = $this->actionRef($action);
        $this->snapshot['versions']['items'][$version]['updated_at'] = $now;
        $this->snapshot['versions']['items'][$version]['finished_at'] = $now;
        $this->snapshot['versions']['current'] = $version;

        $this->setCurrent(StatusPhase::ProcessingVersion, $action, $message, $version);
    }

    public function updateVersionDatabase(
        string $version,
        ?int $local = null,
        ?int $remote = null,
        ?int $result = null
    ): void {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->ensureVersionItem($version);

        if ($local !== null) {
            $this->snapshot['versions']['items'][$version]['database']['version']['local'] = $local;
        }
        if ($remote !== null) {
            $this->snapshot['versions']['items'][$version]['database']['version']['remote'] = $remote;
        }
        if ($result !== null) {
            $this->snapshot['versions']['items'][$version]['database']['version']['result'] = $result;
        }

        $this->snapshot['versions']['items'][$version]['updated_at'] = $now;
        $this->write();
    }

    public function updateVersionDownloads(
        string $version,
        ?int $plannedFiles = null,
        ?int $processedFiles = null,
        ?int $downloadedBytes = null
    ): void {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->ensureVersionItem($version);

        if ($plannedFiles !== null) {
            $this->snapshot['versions']['items'][$version]['downloads']['planned_files'] = $plannedFiles;
        }
        if ($processedFiles !== null) {
            $this->snapshot['versions']['items'][$version]['downloads']['processed_files'] = $processedFiles;
        }
        if ($downloadedBytes !== null) {
            $this->snapshot['versions']['items'][$version]['downloads']['downloaded_bytes'] = $downloadedBytes;
        }

        $this->snapshot['versions']['items'][$version]['updated_at'] = $now;
        $this->write();
    }

    public function updateStorage(StatusState $state, ?StatusAction $action = null): void
    {
        if ($this->snapshot === []) {
            return;
        }

        $this->snapshot['storage'] = [
            'state' => $this->stateRef($state),
            'action' => $this->actionRef($action),
        ];

        $this->write();
    }

    public function completeRun(?string $message = null): void
    {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $this->snapshot['state'] = $this->stateRef(StatusState::Completed);
        $this->snapshot['run']['finished_at'] = $now;
        $this->snapshot['current'] = $this->createCurrent(
            phase: StatusPhase::Finished,
            action: StatusAction::Complete,
            message: $message ?? $this->language->t('status.message.run_completed'),
            version: null,
            channel: null,
            variant: null,
            mirror: null,
            startedAt: $now,
            updatedAt: $now
        );
        $this->snapshot['storage']['state'] = $this->stateRef(StatusState::Completed);
        $this->snapshot['storage']['action'] = null;
        $this->snapshot['error'] = null;

        $this->write();
    }

    public function failRun(Throwable|string $error): void
    {
        if ($this->snapshot === []) {
            return;
        }

        $now = $this->now();
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $this->snapshot['state'] = $this->stateRef(StatusState::Failed);
        $this->snapshot['run']['finished_at'] = $now;
        $this->snapshot['current']['updated_at'] = $now;
        $this->snapshot['error'] = [
            'message' => $message,
            'type' => $error instanceof Throwable ? $error::class : null,
        ];

        $this->write();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    private function ensureVersionItem(string $version): void
    {
        if (isset($this->snapshot['versions']['items'][$version])) {
            return;
        }

        $name = $this->versionNames[$version] ?? $version;
        $this->snapshot['versions']['items'][$version] = $this->createVersionItem($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function createVersionItem(string $name): array
    {
        return [
            'state' => $this->stateRef(StatusState::Pending),
            'action' => null,
            'name' => $name,
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
            'database' => [
                'version' => [
                    'local' => null,
                    'remote' => null,
                    'result' => null,
                ],
            ],
            'downloads' => [
                'planned_files' => null,
                'processed_files' => null,
                'downloaded_bytes' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createCurrent(
        StatusPhase $phase,
        ?StatusAction $action,
        ?string $message,
        ?string $version,
        ?string $channel,
        ?string $variant,
        ?string $mirror,
        string $startedAt,
        string $updatedAt
    ): array {
        return [
            'phase' => $this->phaseRef($phase),
            'action' => $this->actionRef($action),
            'message' => $message,
            'version' => $version,
            'version_name' => $version !== null ? ($this->versionNames[$version] ?? $version) : null,
            'channel' => $channel,
            'variant' => $variant,
            'mirror' => $mirror,
            'started_at' => $startedAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array{key: string, label: string}
     */
    private function stateRef(StatusState $state): array
    {
        return [
            'key' => $state->value,
            'label' => $this->language->t('status.state.' . $state->value),
        ];
    }

    /**
     * @return array{key: string, label: string}
     */
    private function phaseRef(StatusPhase $phase): array
    {
        return [
            'key' => $phase->value,
            'label' => $this->language->t('status.phase.' . $phase->value),
        ];
    }

    /**
     * @return array{key: string, label: string}|null
     */
    private function actionRef(?StatusAction $action): ?array
    {
        if ($action === null) {
            return null;
        }

        return [
            'key' => $action->value,
            'label' => $this->language->t('status.action.' . $action->value),
        ];
    }

    private function write(): void
    {
        if (!$this->enabled || $this->targetPath === '' || $this->snapshot === []) {
            return;
        }

        $this->refreshRunTime();
        $this->recalculateVersionCounters();

        $json = Tools::jsonEncodePrettyTabs($this->snapshot);
        if ($json === false) {
            return;
        }

        Tools::ensureDirectory(dirname($this->targetPath));

        $tmpPath = $this->targetPath . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmpPath, $this->targetPath)) {
            @unlink($this->targetPath);
            if (!@rename($tmpPath, $this->targetPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function refreshRunTime(): void
    {
        if (!isset($this->snapshot['run']) || !is_array($this->snapshot['run'])) {
            return;
        }

        $this->snapshot['run']['updated_at'] = $this->now();
        $this->snapshot['run']['elapsed_seconds'] = $this->startedAtTs !== null
            ? max(0, time() - $this->startedAtTs)
            : 0;
    }

    private function recalculateVersionCounters(): void
    {
        if (!isset($this->snapshot['versions']['items']) || !is_array($this->snapshot['versions']['items'])) {
            return;
        }

        $counts = [
            StatusState::Pending->value => 0,
            StatusState::Running->value => 0,
            StatusState::Completed->value => 0,
            StatusState::Failed->value => 0,
            StatusState::Skipped->value => 0,
        ];

        foreach ($this->snapshot['versions']['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $state = $item['state']['key'] ?? StatusState::Pending->value;
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        $this->snapshot['versions']['total'] = count($this->snapshot['versions']['items']);
        $this->snapshot['versions']['pending'] = $counts[StatusState::Pending->value];
        $this->snapshot['versions']['running'] = $counts[StatusState::Running->value];
        $this->snapshot['versions']['completed'] = $counts[StatusState::Completed->value];
        $this->snapshot['versions']['failed'] = $counts[StatusState::Failed->value];
        $this->snapshot['versions']['skipped'] = $counts[StatusState::Skipped->value];
    }

    private function now(): string
    {
        return date('c');
    }
}
