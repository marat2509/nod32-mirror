<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Enum\StorageLinkMethod;
use Nod32Mirror\Tools;

final class StorageConfig
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getStorageDir(): string
    {
        return $this->config->getStorageDir();
    }

    public function getBlobDir(): string
    {
        return Tools::ds($this->getStorageDir(), 'blobs');
    }

    public function getTmpDir(): string
    {
        return Tools::ds($this->getStorageDir(), 'tmp');
    }

    public function getQuarantineDir(): string
    {
        return Tools::ds($this->getStorageDir(), 'quarantine');
    }

    public function getHashAlgorithm(): string
    {
        return $this->config->getStorageHashAlgorithm();
    }

    public function getLinkMethod(): StorageLinkMethod
    {
        return $this->config->getStorageLinkMethod();
    }

    public function isGcEnabled(): bool
    {
        return $this->config->isStorageGcEnabled();
    }
}
