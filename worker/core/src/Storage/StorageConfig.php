<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Enum\StorageLinkMethod;

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
        return $this->config->getStorageBlobDir();
    }

    public function getIndexPath(): string
    {
        return $this->config->getStorageIndexPath();
    }

    public function getTmpDir(): string
    {
        return $this->config->getTmpDir();
    }

    public function getQuarantineDir(): string
    {
        return $this->config->getStorageQuarantineDir();
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
