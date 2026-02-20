<?php

declare(strict_types=1);

namespace App\controllers;

use App\services\ExcelTemplateImportService;
use App\services\ExcelIngresosImportService;
use App\services\ImportTemplateCatalog;

class ExcelImportController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;
    private const IMPORT_LOG_FILE = '/var/log/import_excel.log';

    public function __construct(
        private ExcelTemplateImportService $service,
        private ImportTemplateCatalog $catalog,
        private string $baseUploadDir,
        private ?ExcelIngresosImportService $ingresosService = null
    ) {
    }

    public function templates(): array
    {
        return ['templates' => $this->catalog->templates()];
    }

    public function handleActionRequest(?string $action, string $user): bool
    {
        $action = isset($_GET['action']) ? (string) $_GET['action'] : $action;
        if ($action === null || $action === '') {
            return false;
        }

        if ($action === 'validate') {
            $this->validateJson($user);
            exit;
        }

        if ($action === 'execute') {
            $this->executeJson($user);
            exit;
        }

        $this->respondJson([
            'ok' => false,
            'message' => 'Action no encontrada',
            'details' => ['action' => $action],
        ], 404);
    }

    public function validate(array $post, array $files, string $user): array
    {
        $template = $this->resolveTemplate($post);
        $uploaded = $this->saveUploadedExcel($files);
        if (($template['id'] ?? '') === 'ingresos' && $this->ingresosService instanceof ExcelIngresosImportService) {
            return $this->ingresosService->validate($uploaded['path'], (string) ($post['tipo'] ?? ($_GET['tipo'] ?? 'PRESUPUESTO')), $uploaded['originalName']);
        }
        $result = $this->service->validate($uploaded['path'], $template);

        $summary = $result['summary'] ?? [
            'total_rows' => (int) ($result['counts']['total_rows'] ?? 0),
            'importables' => (int) ($result['counts']['importables'] ?? $result['counts']['importable_rows'] ?? 0),
            'skipped_formula_rows' => (int) ($result['counts']['skipped_formula_rows'] ?? 0),
            'imported_formula_rows' => (int) ($result['counts']['imported_formula_rows'] ?? 0),
            'warning_rows' => (int) ($result['counts']['warning_rows'] ?? 0),
            'error_rows' => (int) ($result['counts']['error_rows'] ?? 0),
        ];

        $result['summary'] = $summary;
        $result['details'] = is_array($result['details'] ?? null) ? $result['details'] : [];
        $result['counts'] = array_merge($result['counts'] ?? [], [
            'total_rows' => $summary['total_rows'],
            'importables' => $summary['importables'],
            'importable_rows' => (int) ($result['counts']['importable_rows'] ?? $summary['importables']),
            'skipped_formula_rows' => $summary['skipped_formula_rows'],
            'imported_formula_rows' => $summary['imported_formula_rows'],
            'warning_rows' => $summary['warning_rows'],
            'error_rows' => $summary['error_rows'],
        ]);
        $result['file_name'] = $uploaded['originalName'];
        $result['user'] = $user;

        if (($template['id'] ?? '') === 'ingresos') {
            error_log('[IMPORT_VALIDATE][INGRESOS] summary: ' . json_encode($summary, JSON_UNESCAPED_UNICODE));
            error_log('[IMPORT_VALIDATE][INGRESOS] details length: ' . count($result['details']));
        }

        return [
            'ok' => true,
            'tab' => $template['id'],
            'tipo' => (string) ($post['tipo'] ?? ($_GET['tipo'] ?? 'PRESUPUESTO')),
            'sheet_name' => $result['sheet_name'],
            'template_id' => $result['template_id'],
            'file_name' => $result['file_name'],
            'summary' => $result['summary'],
            'details' => $result['details'],
            'preview' => $result['preview'],
            'counts' => $result['counts'],
            'errors' => $result['errors'],
            'processed_rows' => (int) ($result['processed_rows'] ?? 0),
            'highest_row' => (int) ($result['highest_row'] ?? 0),
            'max_rows' => (int) ($result['max_rows'] ?? 5000),
            'rows_limit_exceeded' => (bool) ($result['rows_limit_exceeded'] ?? false),
            'user' => $result['user'],
        ];
    }

    public function execute(array $post, array $files, string $user): array
    {
        $template = $this->resolveTemplate($post);
        $uploaded = $this->saveUploadedExcel($files);
        if (($template['id'] ?? '') === 'ingresos' && $this->ingresosService instanceof ExcelIngresosImportService) {
            return $this->ingresosService->execute($uploaded['path'], (string) ($post['tipo'] ?? ($_GET['tipo'] ?? 'PRESUPUESTO')), $user, $uploaded['originalName']);
        }
        $result = $this->service->execute($uploaded['path'], $template, $user);
        $result['file_name'] = $uploaded['originalName'];
        $this->appendLog($result);

        return [
            'ok' => true,
            'tab' => $template['id'],
            'tipo' => (string) ($post['tipo'] ?? ($_GET['tipo'] ?? 'PRESUPUESTO')),
            'target_table' => (string) ($result['target_table'] ?? ''),
            'inserted_count' => (int) ($result['counts']['imported_rows'] ?? 0),
            'updated_count' => (int) ($result['counts']['updated_rows'] ?? 0),
            'skipped_count' => (int) ($result['counts']['omitted_rows'] ?? 0),
            'warning_count' => (int) ($result['counts']['warning_rows'] ?? 0),
            'processed_rows' => (int) ($result['processed_rows'] ?? ($result['counts']['processed_rows'] ?? 0)),
            'highest_row' => (int) ($result['highest_row'] ?? ($result['counts']['highest_row'] ?? 0)),
            'max_rows' => (int) ($result['max_rows'] ?? ($result['counts']['max_rows'] ?? 5000)),
            'rows_limit_exceeded' => (bool) ($result['rows_limit_exceeded'] ?? false),
            'counts' => $result['counts'],
            'details' => $result['details'] ?? [],
            'preview' => $result['preview'] ?? [],
            'sheet_name' => $result['sheet_name'],
            'template_id' => $result['template_id'],
            'user' => $result['user'],
            'timestamp' => $result['timestamp'],
            'file_name' => $result['file_name'],
        ];
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
            'imported_formula_rows' => $entry['counts']['imported_formula_rows'] ?? 0,
            'error_rows' => $entry['counts']['error_rows'],
            'errors' => $entry['errors'],
        ];
        file_put_contents($file, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function validateJson(string $user): never
    {
        $this->prepareJsonRequest();
        $this->logImport('VALIDATE START tab=' . (string) ($_GET['tab'] ?? '') . ' tipo=' . (string) ($_GET['tipo'] ?? 'PRESUPUESTO'));
        $this->logImport('GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
        $this->logImport('POST: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
        $this->logImport('FILES: ' . json_encode(array_keys($_FILES), JSON_UNESCAPED_UNICODE));

        if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->logImport('VALIDATE END ok=false status=400 reason=method_not_allowed');
            $this->respondJson(['ok' => false, 'message' => 'Método no permitido. Use POST.'], 400);
        }

        try {
            $response = $this->validate($_POST, $_FILES, $user);
            $this->logImport('VALIDATE END ok=true total_rows=' . (int) ($response['counts']['total_rows'] ?? 0));
            $this->respondJson($response);
        } catch (\RuntimeException $e) {
            $this->logImport('VALIDATE END ok=false status=400 error=' . $e->getMessage());
            $this->respondJson(['ok' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logImport('VALIDATE END ok=false status=500 error=' . $e->getMessage());
            $this->respondJson(['ok' => false, 'message' => 'Error interno al validar archivo.'], 500);
        }
    }

    private function executeJson(string $user): never
    {
        $this->prepareJsonRequest();
        $this->logImport('EXECUTE START tab=' . (string) ($_GET['tab'] ?? '') . ' tipo=' . (string) ($_GET['tipo'] ?? 'PRESUPUESTO'));
        $this->logImport('GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
        $this->logImport('POST: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
        $this->logImport('FILES: ' . json_encode(array_keys($_FILES), JSON_UNESCAPED_UNICODE));

        if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->logImport('EXECUTE END ok=false status=400 reason=method_not_allowed');
            $this->respondJson(['ok' => false, 'message' => 'Método no permitido. Use POST.'], 400);
        }

        if ($_FILES === []) {
            $this->logImport('EXECUTE END ok=false status=400 reason=files_empty');
            $this->respondJson([
                'ok' => false,
                'message' => 'No se recibió archivo (multipart). Revisar nombre del input y FormData.',
            ], 400);
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            $this->logImport('EXECUTE END ok=false status=400 reason=file_field_missing available=' . json_encode(array_keys($_FILES), JSON_UNESCAPED_UNICODE));
            $this->respondJson([
                'ok' => false,
                'message' => 'No se encontró el campo "file" en la subida.',
            ], 400);
        }

        $fileError = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError !== UPLOAD_ERR_OK) {
            $this->logImport('EXECUTE END ok=false status=400 reason=upload_error code=' . $fileError);
            $this->respondJson([
                'ok' => false,
                'message' => 'Error al subir archivo.',
                'upload_error_code' => $fileError,
            ], 400);
        }

        $this->logImport('UPLOAD META name=' . (string) ($_FILES['file']['name'] ?? '') . ' size=' . (int) ($_FILES['file']['size'] ?? 0) . ' tmp=' . (string) ($_FILES['file']['tmp_name'] ?? ''));

        try {
            $response = $this->execute($_POST, $_FILES, $user);
            $inserted = (int) ($response['inserted_count'] ?? 0);
            $updated = (int) ($response['updated_count'] ?? 0);
            $skipped = (int) ($response['skipped_count'] ?? 0);
            $warnings = (int) ($response['warning_count'] ?? 0);
            $processedRows = (int) ($response['processed_rows'] ?? 0);
            $highestRow = (int) ($response['highest_row'] ?? 0);
            $maxRows = (int) ($response['max_rows'] ?? 0);

            if (($response['rows_limit_exceeded'] ?? false) === true) {
                $this->logImport('EXECUTE END ok=false status=400 reason=rows_limit highest=' . $highestRow . ' max=' . $maxRows);
                $this->respondJson([
                    'ok' => false,
                    'message' => 'Demasiadas filas, revisar plantilla',
                    'inserted_count' => $inserted,
                    'updated_count' => $updated,
                    'skipped_count' => $skipped,
                    'warning_count' => $warnings,
                    'processed_rows' => $processedRows,
                    'highest_row' => $highestRow,
                    'max_rows' => $maxRows,
                ], 400);
            }

            if (($inserted + $updated) === 0 && $skipped <= 0) {
                $this->logImport('EXECUTE END ok=false status=400 inserted=0 updated=0 skipped=0 warnings=' . $warnings . ' processed=' . $processedRows);
                $this->respondJson(array_merge($response, [
                    'ok' => false,
                    'message' => 'No se procesaron filas válidas en el archivo.',
                ]), 400);
            }

            $response['ok'] = true;
            $this->logImport('EXECUTE END ok=true inserted=' . $inserted . ' updated=' . $updated . ' skipped=' . $skipped . ' warnings=' . $warnings . ' highest=' . $highestRow . ' processed=' . $processedRows);
            $this->respondJson($response);
        } catch (\RuntimeException $e) {
            $this->logImport('EXECUTE END ok=false status=500 error=' . $e->getMessage());
            $payload = ['ok' => false, 'message' => $e->getMessage()];
            if ($this->isLocalDebug()) {
                $payload['debug'] = $e->getTraceAsString();
            }
            $this->respondJson($payload, 500);
        } catch (\Throwable $e) {
            $this->logImport('EXECUTE END ok=false status=500 error=' . $e->getMessage());
            $payload = ['ok' => false, 'message' => $e->getMessage()];
            if ($this->isLocalDebug()) {
                $payload['debug'] = $e->getTraceAsString();
            }
            $this->respondJson($payload, 500);
        }
    }

    private function isLocalDebug(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');

        return str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_contains($serverName, 'localhost') || str_contains($serverName, '127.0.0.1');
    }

    private function respondJson(array $payload, int $status = 200): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function prepareJsonRequest(): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);
    }

    private function logImport(string $msg): void
    {
        $file = dirname(__DIR__, 2) . self::IMPORT_LOG_FILE;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents($file, $line, FILE_APPEND);
    }

    private function resolveTemplate(array $post): array
    {
        $templateRef = trim((string) ($post['template_id'] ?? $post['sheet_name'] ?? $_GET['tab'] ?? ''));
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
        $fileRef = isset($files['excel']) && is_array($files['excel']) ? 'excel' : (isset($files['file']) && is_array($files['file']) ? 'file' : null);
        if ($fileRef === null) {
            throw new \RuntimeException('Debes seleccionar un archivo Excel antes de continuar.');
        }

        $file = $files[$fileRef];
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
