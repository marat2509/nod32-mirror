<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum StatusPhase: string
{
    case Booting = 'booting';
    case AcquiringLock = 'acquiring_lock';
    case LoadingState = 'loading_state';
    case InitializingStorage = 'initializing_storage';
    case StartupCleanup = 'startup_cleanup';
    case SelectingMirrors = 'selecting_mirrors';
    case ProcessingVersion = 'processing_version';
    case CheckingKey = 'checking_key';
    case FindingKey = 'finding_key';
    case CheckingMirrorVersions = 'checking_mirror_versions';
    case ProcessingVariant = 'processing_variant';
    case DownloadingFiles = 'downloading_files';
    case PublishingIndex = 'publishing_index';
    case FinalizingStorage = 'finalizing_storage';
    case GeneratingIndex = 'generating_index';
    case Finished = 'finished';
}
