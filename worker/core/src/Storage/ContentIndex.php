<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Language;
use Nod32Mirror\ValueObject\ReferenceCollection;
use PDO;
use RuntimeException;
use Throwable;

final class ContentIndex
{
    private const SCHEMA_VERSION = '1';

    private ?PDO $database = null;

    private ?string $databasePath = null;

    private string $hashAlgorithm = 'sha256';

    private bool $loaded = false;

    public function __construct(
        private readonly SafeFileOperations $fileOps,
        private readonly Language $language
    ) {
    }

    public function load(string $path, string $hashAlgorithm): void
    {
        $this->database = null;
        $this->databasePath = $path;
        $this->hashAlgorithm = strtolower(trim($hashAlgorithm));
        $this->loaded = is_file($path);

        $this->fileOps->createDirectory(dirname($path));
        $this->database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $this->database->exec('PRAGMA foreign_keys = ON');
        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = NORMAL');
        $this->createSchema();
        $this->initializeMetadata();
        $this->database->beginTransaction();
    }

    public function save(string $path): void
    {
        $database = $this->requireDatabase();
        if ($this->databasePath !== $path) {
            throw new RuntimeException(sprintf(
                'Content index is open at %s and cannot be saved to %s',
                $this->databasePath ?? '(unknown)',
                $path
            ));
        }

        $this->setMetadata('updated_at', self::now());
        if ($database->inTransaction()) {
            $database->commit();
        }
        $database->exec('PRAGMA wal_checkpoint(PASSIVE)');
    }

    public function wasLoaded(): bool
    {
        return $this->loaded;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }

    public function setHashAlgorithm(string $hashAlgorithm): void
    {
        $hashAlgorithm = strtolower(trim($hashAlgorithm));
        if ($hashAlgorithm === '') {
            throw new RuntimeException('Content index hash algorithm cannot be empty');
        }

        $storedAlgorithm = $this->getMetadata('hash_algorithm');
        if ($storedAlgorithm !== null && $storedAlgorithm !== $hashAlgorithm) {
            throw new RuntimeException($this->language->t(
                'storage.index_hash_algorithm_mismatch',
                $storedAlgorithm,
                $hashAlgorithm
            ));
        }

        $this->hashAlgorithm = $hashAlgorithm;
        $this->setMetadata('hash_algorithm', $hashAlgorithm);
    }

    public function recordPublished(
        string $relativePath,
        string $hash,
        int $size,
        string $versionId,
        string $channel,
        string $linkMethod,
        string $blobPath
    ): void {
        $relativePath = $this->normalizePath($relativePath);
        $hash = strtolower(trim($hash));
        if ($relativePath === '' || $hash === '') {
            return;
        }

        $now = self::now();
        $this->transaction(function (PDO $database) use (
            $relativePath,
            $hash,
            $size,
            $versionId,
            $channel,
            $linkMethod,
            $blobPath,
            $now
        ): void {
            $statement = $database->prepare(<<<'SQL'
                INSERT INTO blobs (hash, size, blob_path, created_at, updated_at)
                VALUES (:hash, :size, :blob_path, :created_at, :updated_at)
                ON CONFLICT(hash) DO UPDATE SET
                    size = excluded.size,
                    blob_path = excluded.blob_path,
                    updated_at = excluded.updated_at
                SQL);
            $statement->execute([
                'hash' => $hash,
                'size' => $size,
                'blob_path' => $blobPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $statement = $database->prepare(<<<'SQL'
                INSERT INTO published_paths (path, hash, size, version_id, channel, link_method, updated_at)
                VALUES (:path, :hash, :size, :version_id, :channel, :link_method, :updated_at)
                ON CONFLICT(path) DO UPDATE SET
                    hash = excluded.hash,
                    size = excluded.size,
                    version_id = excluded.version_id,
                    channel = excluded.channel,
                    link_method = excluded.link_method,
                    updated_at = excluded.updated_at
                SQL);
            $statement->execute([
                'path' => $relativePath,
                'hash' => $hash,
                'size' => $size,
                'version_id' => $versionId,
                'channel' => $channel !== '' ? $channel : 'default',
                'link_method' => $linkMethod,
                'updated_at' => $now,
            ]);
        });
    }

    public function getPublishedHash(string $relativePath): ?string
    {
        $value = $this->fetchPublishedColumn($relativePath, 'hash');
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getPublishedSize(string $relativePath): ?int
    {
        $value = $this->fetchPublishedColumn($relativePath, 'size');
        return is_numeric($value) ? (int) $value : null;
    }

    public function getBlobPath(string $hash): ?string
    {
        $statement = $this->requireDatabase()->prepare('SELECT blob_path FROM blobs WHERE hash = :hash');
        $statement->execute(['hash' => strtolower(trim($hash))]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    public function iteratePublished(): iterable
    {
        $statement = $this->requireDatabase()->query(<<<'SQL'
            SELECT path, hash, size, version_id, channel, link_method, updated_at
            FROM published_paths
            ORDER BY path
            SQL);

        while (($row = $statement->fetch()) !== false) {
            $path = (string) $row['path'];
            unset($row['path']);
            $row['size'] = (int) $row['size'];
            yield $path => $row;
        }
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    public function iterateBlobs(): iterable
    {
        $statement = $this->requireDatabase()->query(<<<'SQL'
            SELECT hash, size, blob_path, created_at, updated_at
            FROM blobs
            ORDER BY hash
            SQL);

        while (($row = $statement->fetch()) !== false) {
            $hash = (string) $row['hash'];
            $row['size'] = (int) $row['size'];
            yield $hash => $row;
        }
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    public function iterateUnreferencedBlobs(): iterable
    {
        $statement = $this->requireDatabase()->query(<<<'SQL'
            SELECT b.hash, b.size, b.blob_path, b.created_at, b.updated_at
            FROM blobs b
            WHERE NOT EXISTS (
                SELECT 1 FROM published_paths p WHERE p.hash = b.hash
            )
            ORDER BY b.hash
            SQL);

        while (($row = $statement->fetch()) !== false) {
            $hash = (string) $row['hash'];
            $row['size'] = (int) $row['size'];
            yield $hash => $row;
        }
    }

    public function hasHash(string $hash): bool
    {
        $statement = $this->requireDatabase()->prepare('SELECT 1 FROM blobs WHERE hash = :hash');
        $statement->execute(['hash' => strtolower(trim($hash))]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPublished(): array
    {
        return iterator_to_array($this->iteratePublished());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getHashes(): array
    {
        $hashes = [];
        foreach ($this->iterateBlobs() as $hash => $entry) {
            $entry['published_paths'] = [];
            $entry['version_ids'] = [];
            $hashes[$hash] = $entry;
        }

        $statement = $this->requireDatabase()->query(<<<'SQL'
            SELECT p.hash, p.path, r.version_id, r.channel
            FROM published_paths p
            LEFT JOIN version_references r ON r.path = p.path
            ORDER BY p.hash, p.path, r.version_id, r.channel
            SQL);
        while (($row = $statement->fetch()) !== false) {
            $hash = (string) $row['hash'];
            if (!isset($hashes[$hash])) {
                continue;
            }

            $path = (string) $row['path'];
            if (!in_array($path, $hashes[$hash]['published_paths'], true)) {
                $hashes[$hash]['published_paths'][] = $path;
            }

            if ($row['version_id'] !== null && $row['channel'] !== null) {
                $versionId = (string) $row['version_id'];
                $channel = (string) $row['channel'];
                $hashes[$hash]['version_ids'][$versionId] ??= [];
                if (!in_array($channel, $hashes[$hash]['version_ids'][$versionId], true)) {
                    $hashes[$hash]['version_ids'][$versionId][] = $channel;
                }
            }
        }

        return $hashes;
    }

    public function removePublished(string $relativePath): void
    {
        $statement = $this->requireDatabase()->prepare('DELETE FROM published_paths WHERE path = :path');
        $statement->execute(['path' => $this->normalizePath($relativePath)]);
    }

    public function removeHash(string $hash): void
    {
        $statement = $this->requireDatabase()->prepare('DELETE FROM blobs WHERE hash = :hash');
        $statement->execute(['hash' => strtolower(trim($hash))]);
    }

    public function syncVersionRefs(ReferenceCollection $references): void
    {
        $this->transaction(function (PDO $database) use ($references): void {
            $database->exec('DELETE FROM version_references');
            $statement = $database->prepare(<<<'SQL'
                INSERT INTO version_references (path, version_id, channel)
                SELECT :path, :version_id, :channel
                WHERE EXISTS (SELECT 1 FROM published_paths WHERE path = :path)
                SQL);

            foreach ($references->getPaths() as $path) {
                $path = $this->normalizePath($path);
                foreach ($references->getVersionChannelsForPath($path) as $versionId => $channels) {
                    foreach ($channels as $channel) {
                        $statement->execute([
                            'path' => $path,
                            'version_id' => $versionId,
                            'channel' => $channel,
                        ]);
                    }
                }
            }
        });
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s+00:00');
    }

    private function createSchema(): void
    {
        $this->requireDatabase()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS blobs (
                hash TEXT PRIMARY KEY,
                size INTEGER NOT NULL,
                blob_path TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS published_paths (
                path TEXT PRIMARY KEY,
                hash TEXT NOT NULL,
                size INTEGER NOT NULL,
                version_id TEXT NOT NULL,
                channel TEXT NOT NULL,
                link_method TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (hash) REFERENCES blobs(hash)
            );

            CREATE INDEX IF NOT EXISTS idx_published_paths_hash
                ON published_paths(hash);

            CREATE TABLE IF NOT EXISTS version_references (
                path TEXT NOT NULL,
                version_id TEXT NOT NULL,
                channel TEXT NOT NULL,
                PRIMARY KEY (path, version_id, channel),
                FOREIGN KEY (path) REFERENCES published_paths(path) ON DELETE CASCADE
            );
            SQL);
    }

    private function initializeMetadata(): void
    {
        $schemaVersion = $this->getMetadata('schema_version');
        if ($schemaVersion !== null && $schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported content index schema version: %s',
                $schemaVersion
            ));
        }

        $storedAlgorithm = $this->getMetadata('hash_algorithm');
        if ($storedAlgorithm !== null && $storedAlgorithm !== $this->hashAlgorithm) {
            throw new RuntimeException($this->language->t(
                'storage.index_hash_algorithm_mismatch',
                $storedAlgorithm,
                $this->hashAlgorithm
            ));
        }

        $this->transaction(function (): void {
            $this->setMetadata('schema_version', self::SCHEMA_VERSION);
            $this->setMetadata('hash_algorithm', $this->hashAlgorithm);
            if ($this->getMetadata('created_at') === null) {
                $this->setMetadata('created_at', self::now());
            }
            $this->setMetadata('updated_at', self::now());
        });
    }

    private function getMetadata(string $key): ?string
    {
        $statement = $this->requireDatabase()->prepare('SELECT value FROM metadata WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function setMetadata(string $key, string $value): void
    {
        $statement = $this->requireDatabase()->prepare(<<<'SQL'
            INSERT INTO metadata (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
            SQL);
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    private function fetchPublishedColumn(string $relativePath, string $column): mixed
    {
        if (!in_array($column, ['hash', 'size'], true)) {
            throw new RuntimeException('Unsupported published-path column: ' . $column);
        }

        $statement = $this->requireDatabase()->prepare(
            sprintf('SELECT %s FROM published_paths WHERE path = :path', $column)
        );
        $statement->execute(['path' => $this->normalizePath($relativePath)]);

        return $statement->fetchColumn();
    }

    private function transaction(callable $callback): void
    {
        $database = $this->requireDatabase();
        $ownsTransaction = !$database->inTransaction();
        if ($ownsTransaction) {
            $database->beginTransaction();
        }

        try {
            $callback($database);
            if ($ownsTransaction) {
                $database->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    private function requireDatabase(): PDO
    {
        if ($this->database === null) {
            throw new RuntimeException('Content index has not been loaded');
        }

        return $this->database;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return ltrim($path, '/');
    }
}
