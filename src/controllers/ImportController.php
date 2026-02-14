<?php

declare(strict_types=1);

namespace App\controllers;

use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\services\ExcelAnexoImportService;

class ImportController
{
    public function __construct(
        private ExcelAnexoImportService $importService,
        private AnexoRepo $anexoRepo,
        private ImportLogRepo $logRepo
    ) {
    }

    public function uploadExcel(array $files, array $config): array
    {
        if (!isset($files['excel']) || $files['excel']['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'No se recibió archivo válido.'];
        }

        $uploadDir = $config['upload_dir'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $files['excel']['name']);
        $target = rtrim($uploadDir, '/') . '/' . uniqid('excel_', true) . '_' . $name;

        if (!move_uploaded_file($files['excel']['tmp_name'], $target)) {
            return ['ok' => false, 'message' => 'No fue posible guardar el archivo.'];
        }

        return ['ok' => true, 'message' => 'Archivo subido correctamente.', 'path' => $target, 'file' => basename($target)];
    }

    public function importGastos(int $proyectoId, string $path): array
    {
        $data = $this->importService->importGastos($path, $proyectoId);
        $inserted = $this->anexoRepo->insertAnexoDetalleBatch($data['rows']);
        $warnings = (int) ($data['warnings'] ?? 0);
        $message = "Importación GASTOS finalizada. Registros insertados: {$inserted}. Warnings omitidos: {$warnings}";
        $this->logRepo->insertLog($proyectoId, basename($path), $data['sheet'], $inserted, $message);

        return ['ok' => true, 'message' => $message, 'inserted' => $inserted, 'warnings' => $warnings];
    }

    public function importNomina(int $proyectoId, string $path): array
    {
        $data = $this->importService->importNomina($path, $proyectoId);
        $inserted = $this->anexoRepo->insertAnexoDetalleBatch($data['rows']);
        $message = "Importación NOMINA finalizada. Registros: {$inserted}";
        $this->logRepo->insertLog($proyectoId, basename($path), $data['sheet'], $inserted, $message);

        return ['ok' => true, 'message' => $message, 'inserted' => $inserted];
    }
}
