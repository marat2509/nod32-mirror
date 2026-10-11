<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum StatusAction: string
{
    case AcquireLock = 'acquire_lock';
    case LoadSizes = 'load_sizes';
    case InitStorage = 'init_storage';
    case CleanupTmp = 'cleanup_tmp';
    case CleanupStorageTmp = 'cleanup_storage_tmp';
    case CleanupPublishTmp = 'cleanup_publish_tmp';
    case PrepareMirrors = 'prepare_mirrors';
    case PreselectBestMirrors = 'preselect_best_mirrors';
    case TestMirror = 'test_mirror';
    case ProcessVersion = 'process_version';
    case TestStoredKey = 'test_stored_key';
    case SearchKeys = 'search_keys';
    case CheckingMirrorVersions = 'checking_mirror_versions';
    case CheckRemoteVersion = 'check_remote_version';
    case CheckLocalDatabase = 'check_local_database';
    case ProcessVariant = 'process_variant';
    case DownloadUpdateVer = 'download_update_ver';
    case ParseUpdateVer = 'parse_update_ver';
    case DownloadBatch = 'download_batch';
    case PublishUpdateVer = 'publish_update_ver';
    case FinalizeStorage = 'finalize_storage';
    case CollectReferences = 'collect_references';
    case RunStorageGc = 'run_storage_gc';
    case WriteIndexJson = 'write_index_json';
    case WriteIndexHtml = 'write_index_html';
    case WriteStatusJson = 'write_status_json';
    case Complete = 'complete';
}
