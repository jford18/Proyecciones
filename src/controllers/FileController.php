<?php

declare(strict_types=1);

namespace App\controllers;

class FileController
{
    public function __construct(private string $uploadDir)
    {
    }

    public function listFiles(): array
    {
        $meta = $this->readMetadata();
        usort($meta, static fn (array $a, array $b): int => strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? '')));

        return $meta;
    }

    public function registerUploadedFile(string $path): void
    {
        $meta = $this->readMetadata();
        $meta[] = [
            'name' => basename($path),
            'path' => $path,
            'size' => file_exists($path) ? (int) filesize($path) : 0,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];

        $this->writeMetadata($meta);
    }

    public function selectFile(string $path): ?array
    {
        foreach ($this->readMetadata() as $file) {
            if (($file['path'] ?? '') === $path && file_exists($path)) {
                return $file;
            }
        }

        return null;
    }

    private function readMetadata(): array
    {
        $index = $this->metadataPath();
        if (!file_exists($index)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($index), true);

        return is_array($data) ? $data : [];
    }

    private function writeMetadata(array $data): void
    {
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }

        file_put_contents($this->metadataPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function metadataPath(): string
    {
        return rtrim($this->uploadDir, '/') . '/files.json';
    }
}
