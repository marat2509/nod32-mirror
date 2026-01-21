<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

use Nod32Mirror\Enum\LinkMethod;

/**
 * Result of file linking operation
 */
final readonly class LinkResult
{
    /**
     * @param DownloadableFile[] $filesToDownload Files that need to be downloaded
     * @param string[] $neededFiles All files that should exist after operation
     * @param array<string, LinkInfo> $linkedFiles Files that were successfully linked/copied
     * @param int $linkedCount Number of files that were linked/copied
     */
    public function __construct(
        public array $filesToDownload,
        public array $neededFiles,
        public array $linkedFiles = [],
        public int $linkedCount = 0
    ) {
    }
}
