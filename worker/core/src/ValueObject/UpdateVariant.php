<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final readonly class UpdateVariant
{
    public function __construct(
        public string $key,
        public string $source,
        public string $fixedPath,
        public string $tmpPath,
        public string $localPath,
        public ?string $channel = null,
        public ?string $type = null
    ) {
    }

    public static function create(
        string $channel,
        string $type,
        string $sourcePath,
        string $webDir,
        string $tmpDir,
        string $verFolder
    ): self {
        $key = $channel . ':' . $type;
        $localSuffix = $type === 'dll'
            ? DIRECTORY_SEPARATOR . 'dll' . DIRECTORY_SEPARATOR . 'update.ver'
            : DIRECTORY_SEPARATOR . 'update.ver';

        $fixedPath = 'eset_upd' . DIRECTORY_SEPARATOR . $verFolder . DIRECTORY_SEPARATOR . $channel . $localSuffix;
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $fixedPath;
        $localPath = $webDir . DIRECTORY_SEPARATOR . $fixedPath;

        return new self(
            key: $key,
            source: $sourcePath,
            fixedPath: $fixedPath,
            tmpPath: $tmpPath,
            localPath: $localPath,
            channel: $channel,
            type: $type
        );
    }

    public function getChannel(): ?string
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        if (str_contains($this->key, ':')) {
            return explode(':', $this->key, 2)[0];
        }

        return null;
    }

    public function getType(): ?string
    {
        if ($this->type !== null) {
            return $this->type;
        }

        if (str_contains($this->key, ':')) {
            return explode(':', $this->key, 2)[1] ?? null;
        }

        return $this->key;
    }
}
