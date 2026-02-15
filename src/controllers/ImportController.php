<?php

declare(strict_types=1);

namespace App\controllers;

use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\repositories\ProyectoRepo;
use App\services\ExcelAnexoImportService;

class ImportController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function __construct(
        private ExcelAnexoImportService $importService,
        private AnexoRepo $anexoRepo,
        private ImportLogRepo $logRepo,
        private ProyectoRepo $proyectoRepo,
        private string $baseUploadDir
    ) {
    }

    public function importAnexo(string $tipoAnexo, array $post, array $files): array
    {
        $proyectoId = (int) ($post['proyecto_id'] ?? 0);
        $tipo = strtoupper(trim((string) ($post['tipo'] ?? '')));
        if (!in_array($tipo, ['PRESUPUESTO', 'REAL'], true)) {
            throw new \RuntimeException('Debe seleccionar tipo PRESUPUESTO o REAL.');
        }

        if ($this->proyectoRepo->findById($proyectoId) === null) {
            throw new \RuntimeException("Proyecto no existe (ID={$proyectoId}).");
        }

        $uploaded = $this->saveUploadedExcel($files, strtolower($tipoAnexo));
        $data = $this->importService->importAnexo($uploaded['path'], $proyectoId, $tipoAnexo, $tipo);
        $inserted = $this->anexoRepo->insertAnexoDetalleBatch($data['rows']);
        $warnings = (int) ($data['warnings'] ?? 0);

        $message = "Importación {$tipoAnexo} ({$tipo}) finalizada. Registros insertados: {$inserted}. Warnings omitidos: {$warnings}.";
        $this->logRepo->insertLog($proyectoId, $uploaded['originalName'], $data['sheet'], $inserted, $message);

        return [
            'ok' => true,
            'type' => $tipoAnexo,
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
            throw new \RuntimeException('No se pudo subir el archivo.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('No se pudo verificar el archivo cargado.');
        }

        $originalName = (string) ($file['name'] ?? 'archivo.xlsx');
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('Formato no permitido. Solo se aceptan archivos .xlsx.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('Tamaño de archivo inválido (máximo 10 MB).');
        }

        $bucketPath = rtrim($this->baseUploadDir, '/') . '/' . $bucket;
        if (!is_dir($bucketPath) && !mkdir($bucketPath, 0777, true) && !is_dir($bucketPath)) {
            throw new \RuntimeException('No fue posible preparar la carpeta de subida.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'archivo.xlsx';
        $target = $bucketPath . '/' . uniqid($bucket . '_', true) . '_' . $safeName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new \RuntimeException('No fue posible guardar el archivo en el servidor.');
        }

        return ['path' => $target, 'originalName' => $originalName];
    }
}
