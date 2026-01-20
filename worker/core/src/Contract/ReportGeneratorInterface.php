<?php

declare(strict_types=1);

namespace Nod32Mirror\Contract;

interface ReportGeneratorInterface
{
    /**
     * Generate report from metadata
     *
     * @param array<string, mixed> $metadata
     */
    public function generate(array $metadata): string;

    /**
     * Save report to file
     *
     * @param array<string, mixed> $metadata
     */
    public function save(array $metadata, string $targetPath): void;

    /**
     * Get default filename for this report type
     */
    public function getDefaultFilename(): string;
}
