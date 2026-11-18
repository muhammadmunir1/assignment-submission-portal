<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App;

use JsonException;
use RuntimeException;

/**
 * JSON file store. Reads and writes under an exclusive lock,
 * and replaces the file by writing a temp file then renaming it.
 */
final class Store
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = self::resolvePath($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->withLock(
            static fn (array $rows): array => $rows,
            false
        );

        return $this->newestFirst($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function add(array $row): void
    {
        $this->withLock(
            static function (array $rows) use ($row): array {
                array_unshift($rows, $row);

                return $rows;
            },
            true
        );
    }

    private static function resolvePath(?string $path): string
    {
        if ($path !== null && $path !== '') {
            return $path;
        }

        $fromEnv = getenv('SUBMISSIONS_FILE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return 'data/submissions.json';
    }

    /**
     * A missing, empty, or corrupt file is an empty list.
     *
     * @return list<array<string, mixed>>
     */
    private function readRows(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            return [];
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeAtomically(array $rows): void
    {
        $json = json_encode(
            array_values($rows),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $dir = dirname($this->path);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write the submissions file.');
        }

        // Windows rename() cannot replace a file that already exists.
        if (is_file($this->path) && PHP_OS_FAMILY === 'Windows' && !unlink($this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace the submissions file.');
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace the submissions file.');
        }
    }

    /**
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $action
     * @return list<array<string, mixed>>
     */
    private function withLock(callable $action, bool $persist): array
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the submissions directory.');
        }

        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the submissions lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not lock the submissions file.');
            }

            $rows = $this->readRows();
            $updated = $action($rows);
            if ($persist) {
                $this->writeAtomically($updated);
            }

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function newestFirst(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $index => $row) {
            $indexed[] = ['index' => $index, 'row' => $row];
        }

        usort(
            $indexed,
            static function (array $left, array $right): int {
                $byTime = strcmp(
                    (string) ($right['row']['submittedAt'] ?? ''),
                    (string) ($left['row']['submittedAt'] ?? '')
                );

                return $byTime !== 0 ? $byTime : ($left['index'] <=> $right['index']);
            }
        );

        return array_map(static fn (array $item): array => $item['row'], $indexed);
    }
}
