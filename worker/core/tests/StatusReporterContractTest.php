<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\Enum\StatusState;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Status\StatusReporter;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(
            ' Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertArrayMissingKey(string $key, array $array, string $message): void
{
    if (array_key_exists($key, $array)) {
        throw new RuntimeException($message);
    }
}

$tmpDir = sys_get_temp_dir() . '/nod32-status-' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0777, true);
$statusPath = $tmpDir . '/status.json';

$language = new Language(__DIR__ . '/../langpacks/');
$language->init('en');

$reporter = new StatusReporter(
    targetPath: $statusPath,
    language: $language,
    versionNames: [
        'ep9' => 'ESET NOD32 Endpoint 9',
        'v16' => 'ESET NOD32 Antivirus 16',
    ],
    enabled: true
);

$reporter->startRun('20260121', 12345, [
    'ep9' => 'ESET NOD32 Endpoint 9',
    'v16' => 'ESET NOD32 Antivirus 16',
]);
$reporter->startVersion('ep9', StatusAction::CheckingMirrorVersions, 'Checking mirror versions');
$reporter->setCurrent(
    phase: StatusPhase::ProcessingVersion,
    action: StatusAction::CheckingMirrorVersions,
    message: 'Checking 18 mirrors for version ep9',
    version: 'ep9',
    channel: 'production',
    variant: 'production:file',
    mirror: 'host.example'
);
$reporter->updateVersionDatabase('ep9', local: 33200, remote: 33203, result: null);

$status = json_decode((string) file_get_contents($statusPath), true, flags: JSON_THROW_ON_ERROR);

assertSameValue(['key' => 'running', 'label' => 'Running'], $status['state'], 'Root state must be enum ref.');
assertArrayMissingKey('state_label', $status, 'Root state_label must not be emitted.');
assertSameValue(
    ['key' => 'processing_version', 'label' => 'Processing version'],
    $status['current']['phase'],
    'Current phase must be enum ref.'
);
assertSameValue(
    ['key' => 'checking_mirror_versions', 'label' => 'Checking mirror versions'],
    $status['current']['action'],
    'Current action must be enum ref.'
);
assertSameValue('ep9', $status['current']['version'], 'Current version must remain a string key.');
assertSameValue('ESET NOD32 Endpoint 9', $status['current']['version_name'], 'Current version_name must be populated.');
assertSameValue('production', $status['current']['channel'], 'Current channel must be a raw string.');
assertSameValue('production:file', $status['current']['variant'], 'Current variant must be a raw string.');
assertSameValue(33200, $status['versions']['items']['ep9']['database']['version']['local'], 'Local DB version mismatch.');
assertSameValue(33203, $status['versions']['items']['ep9']['database']['version']['remote'], 'Remote DB version mismatch.');
assertSameValue(null, $status['versions']['items']['ep9']['database']['version']['result'], 'Result DB version mismatch.');

@unlink($statusPath);
@rmdir($tmpDir);
