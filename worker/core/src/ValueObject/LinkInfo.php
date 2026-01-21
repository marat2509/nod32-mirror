<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

use Nod32Mirror\Enum\LinkMethod;

/**
 * Information about a linked file
 */
final readonly class LinkInfo
{
    public function __construct(
        public string $sourcePath,
        public string $targetPath,
        public LinkMethod $method,
        public bool $success
    ) {
    }

    public function getRelativeSource(string $baseDir): string
    {
        return str_replace($baseDir . DIRECTORY_SEPARATOR, '', $this->sourcePath);
    }

    public function getRelativeTarget(string $baseDir): string
    {
        return str_replace($baseDir . DIRECTORY_SEPARATOR, '', $this->targetPath);
    }
}
