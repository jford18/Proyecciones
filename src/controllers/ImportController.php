<?php

declare(strict_types=1);

namespace App\controllers;

use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\services\ExcelAnexoImportService;

class ImportController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function __construct(
        private ExcelAnexoImportService $importService,
        private AnexoRepo $anexoRepo,
        private ImportLogRepo $logRepo,
        private string $baseUploadDir
    ) {
    }

    public function importGastos(int $proyectoId, array $files): array
    {
        $uploaded = $this->saveUploadedExcel($files, 'gastos');
        $data = $this->importService->importGastos($uploaded['path'], $proyectoId);
        $inserted = $this->anexoRepo->insertAnexoDetalleBatch($data['rows']);
        $warnings = (int) ($data['warnings'] ?? 0);
        $message = "Importación GASTOS finalizada. Registros insertados: {$inserted}. Warnings omitidos: {$warnings}.";
        $this->logRepo->insertLog($proyectoId, $uploaded['originalName'], $data['sheet'], $inserted, $message);

        return [
            'ok' => true,
            'type' => 'GASTOS',
            'message' => $message,
            'inserted' => $inserted,
            'warnings' => $warnings,
            'fileName' => $uploaded['originalName'],
        ];
    }

    public function importNomina(int $proyectoId, array $files): array
    {
        $uploaded = $this->saveUploadedExcel($files, 'nomina');
        $data = $this->importService->importNomina($uploaded['path'], $proyectoId);
        $inserted = $this->anexoRepo->insertAnexoDetalleBatch($data['rows']);
        $warnings = (int) ($data['warnings'] ?? 0);
        $message = "Importación NÓMINA finalizada. Registros insertados: {$inserted}. Warnings omitidos: {$warnings}.";
        $this->logRepo->insertLog($proyectoId, $uploaded['originalName'], $data['sheet'], $inserted, $message);

        return [
            'ok' => true,
            'type' => 'NOMINA',
            'message' => $message,
            'inserted' => $inserted,
            'warnings' => $warnings,
            'fileName' => $uploaded['originalName'],
        ];
    }

    private function saveUploadedExcel(array $files, string $bucket): array
    {
        if (!isset($files['excel']) || !is_array($files['excel'])) {
            throw new \RuntimeException('Debes seleccionar un archivo Excel antes de importar.');
        }

        $file = $files['excel'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->friendlyUploadError($errorCode));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('No se pudo verificar el archivo cargado. Intenta nuevamente.');
        }

        $originalName = (string) ($file['name'] ?? 'archivo.xlsx');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            throw new \RuntimeException('Formato no permitido. Solo se aceptan archivos .xlsx.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('El archivo está vacío.');
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('El archivo excede el tamaño máximo permitido (10 MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ];

        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new \RuntimeException('El archivo no parece ser un Excel válido (.xlsx).');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'archivo.xlsx';
        $bucketPath = rtrim($this->baseUploadDir, '/') . '/' . $bucket;
        if (!is_dir($bucketPath) && !mkdir($bucketPath, 0777, true) && !is_dir($bucketPath)) {
            throw new \RuntimeException('No fue posible preparar la carpeta de subida.');
        }

        $target = $bucketPath . '/' . uniqid($bucket . '_', true) . '_' . $safeName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new \RuntimeException('No fue posible guardar el archivo en el servidor.');
        }

        return ['path' => $target, 'originalName' => $originalName];
    }

    private function friendlyUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_PARTIAL => 'El archivo se cargó parcialmente. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'Debes seleccionar un archivo Excel antes de importar.',
            default => 'No se pudo procesar el archivo cargado.',
        };
    }
}
