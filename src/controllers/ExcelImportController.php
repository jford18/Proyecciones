<?php

declare(strict_types=1);

namespace App\controllers;

use App\services\ExcelTemplateImportService;
use App\services\ImportTemplateCatalog;

class ExcelImportController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function __construct(
        private ExcelTemplateImportService $service,
        private ImportTemplateCatalog $catalog,
        private string $baseUploadDir
    ) {
    }

    public function templates(): array
    {
        return ['templates' => $this->catalog->templates()];
    }

    public function validate(array $post, array $files, string $user): array
    {
        $template = $this->resolveTemplate($post);
        $uploaded = $this->saveUploadedExcel($files);
        $result = $this->service->validate($uploaded['path'], $template);
        $result['file_name'] = $uploaded['originalName'];
        $result['user'] = $user;

        return $result;
    }

    public function execute(array $post, array $files, string $user): array
    {
        $template = $this->resolveTemplate($post);
        $uploaded = $this->saveUploadedExcel($files);
        $result = $this->service->execute($uploaded['path'], $template, $user);
        $result['file_name'] = $uploaded['originalName'];
        $this->appendLog($result);

        return $result;
    }

    public function logs(int $limit = 50): array
    {
        $file = dirname(__DIR__, 2) . '/var/logs/import_excel_logs.json';
        if (!is_file($file)) {
            return ['logs' => []];
        }

        $logs = json_decode((string) file_get_contents($file), true) ?: [];

        return ['logs' => array_slice(array_reverse($logs), 0, max(1, $limit))];
    }

    private function appendLog(array $entry): void
    {
        $file = dirname(__DIR__, 2) . '/var/logs/import_excel_logs.json';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $logs = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $logs[] = [
            'sheet_name' => $entry['sheet_name'],
            'template_id' => $entry['template_id'],
            'user' => $entry['user'],
            'timestamp' => $entry['timestamp'],
            'total_rows' => $entry['counts']['total_rows'],
            'imported_rows' => $entry['counts']['imported_rows'],
            'updated_rows' => $entry['counts']['updated_rows'],
            'skipped_formula_rows' => $entry['counts']['skipped_formula_rows'],
            'error_rows' => $entry['counts']['error_rows'],
            'errors' => $entry['errors'],
        ];
        file_put_contents($file, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function resolveTemplate(array $post): array
    {
        $templateRef = trim((string) ($post['template_id'] ?? $post['sheet_name'] ?? ''));
        if ($templateRef === '') {
            throw new \RuntimeException('Debe enviar template_id o sheet_name.');
        }

        $template = $this->catalog->findByIdOrSheet($templateRef);
        if ($template === null) {
            throw new \RuntimeException('Plantilla no reconocida.');
        }

        return $template;
    }

    private function saveUploadedExcel(array $files): array
    {
        if (!isset($files['excel']) || !is_array($files['excel'])) {
            throw new \RuntimeException('Debes seleccionar un archivo Excel antes de continuar.');
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

        $bucketPath = rtrim($this->baseUploadDir, '/') . '/excel_tabs';
        if (!is_dir($bucketPath) && !mkdir($bucketPath, 0777, true) && !is_dir($bucketPath)) {
            throw new \RuntimeException('No fue posible preparar la carpeta de subida.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'archivo.xlsx';
        $target = $bucketPath . '/' . uniqid('excel_tab_', true) . '_' . $safeName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new \RuntimeException('No fue posible guardar el archivo en el servidor.');
        }

        return ['path' => $target, 'originalName' => $originalName];
    }
}
