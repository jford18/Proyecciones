<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class PresupuestoIngresosRepository
{
    private ?array $importLogColumns = null;
    private ?array $presupuestoIngresosColumns = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function upsertIngresosRows(string $tipo, int $anio, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        if ($rows === []) {
            return ['inserted_count' => 0, 'updated_count' => 0];
        }

        $inserted = 0;
        $updated = 0;
        $existenceCache = [];

        $columns = $this->getPresupuestoIngresosColumns();
        $existsStmt = $this->pdo->prepare('SELECT 1 FROM PRESUPUESTO_INGRESOS WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1');

        $insertColumns = [
            'TIPO' => 'tipo',
            'ANIO' => 'anio',
            'CODIGO' => 'codigo',
            'NOMBRE_CUENTA' => 'nombre_cuenta',
            'ENE' => 'ene',
            'FEB' => 'feb',
            'MAR' => 'mar',
            'ABR' => 'abr',
            'MAY' => 'may',
            'JUN' => 'jun',
            'JUL' => 'jul',
            'AGO' => 'ago',
            'SEP' => 'sep',
            'OCT' => 'oct',
            'NOV' => 'nov',
            'DIC' => 'dic',
            'TOTAL' => 'total',
            'ARCHIVO_NOMBRE' => 'archivo_nombre',
            'HOJA_NOMBRE' => 'hoja_nombre',
            'USUARIO_CARGA' => 'usuario_carga',
        ];

        $insertColumns = array_filter(
            $insertColumns,
            static fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );

        $updateColumns = [
            'NOMBRE_CUENTA',
            'ENE',
            'FEB',
            'MAR',
            'ABR',
            'MAY',
            'JUN',
            'JUL',
            'AGO',
            'SEP',
            'OCT',
            'NOV',
            'DIC',
            'TOTAL',
            'ARCHIVO_NOMBRE',
            'HOJA_NOMBRE',
            'USUARIO_CARGA',
        ];

        $updateSqlParts = [];
        foreach ($updateColumns as $column) {
            if (isset($columns[$column]) && isset($insertColumns[$column])) {
                $updateSqlParts[] = $column . ' = VALUES(' . $column . ')';
            }
        }
        if (isset($columns['FECHA_ACTUALIZA'])) {
            $updateSqlParts[] = 'FECHA_ACTUALIZA = CURRENT_TIMESTAMP';
        }

        $columnSql = implode(', ', array_keys($insertColumns));
        $valuesSql = implode(', ', array_map(static fn (string $param): string => ':' . $param, array_values($insertColumns)));
        $updateSql = $updateSqlParts === [] ? '' : ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateSqlParts);
        $upsertStmt = $this->pdo->prepare("INSERT INTO PRESUPUESTO_INGRESOS ({$columnSql}) VALUES ({$valuesSql}){$updateSql}");

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $codigo = (string) ($row['codigo'] ?? '');
                $cacheKey = $tipo . '|' . $anio . '|' . $codigo;

                if (!array_key_exists($cacheKey, $existenceCache)) {
                    $existsStmt->execute([
                        'tipo' => $tipo,
                        'anio' => $anio,
                        'codigo' => $codigo,
                    ]);
                    $existenceCache[$cacheKey] = $existsStmt->fetchColumn() !== false;
                }

                $payload = [
                    'tipo' => $tipo,
                    'anio' => $anio,
                    'codigo' => $codigo,
                    'nombre_cuenta' => (string) ($row['nombre'] ?? ''),
                    'ene' => (float) ($row['ene'] ?? 0),
                    'feb' => (float) ($row['feb'] ?? 0),
                    'mar' => (float) ($row['mar'] ?? 0),
                    'abr' => (float) ($row['abr'] ?? 0),
                    'may' => (float) ($row['may'] ?? 0),
                    'jun' => (float) ($row['jun'] ?? 0),
                    'jul' => (float) ($row['jul'] ?? 0),
                    'ago' => (float) ($row['ago'] ?? 0),
                    'sep' => (float) ($row['sep'] ?? 0),
                    'oct' => (float) ($row['oct'] ?? 0),
                    'nov' => (float) ($row['nov'] ?? 0),
                    'dic' => (float) ($row['dic'] ?? 0),
                    'total' => (float) ($row['total'] ?? 0),
                    'archivo_nombre' => $fileName,
                    'hoja_nombre' => $sheetName,
                    'usuario_carga' => $usuario,
                ];

                $upsertStmt->execute($payload);

                if ($existenceCache[$cacheKey] === true) {
                    $updated++;
                } else {
                    $inserted++;
                    $existenceCache[$cacheKey] = true;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['inserted_count' => $inserted, 'updated_count' => $updated];
    }

    public function insertImportLog(array $payload): void
    {
        $columns = $this->getImportLogColumns();

        $insertColumns = [
            'ARCHIVO_NOMBRE' => 'archivo_nombre',
            'HOJA_NOMBRE' => 'hoja_nombre',
            'FILE_NAME' => 'file_name',
            'SHEET_NAME' => 'sheet_name',
            'TAB' => 'tab',
            'TIPO' => 'tipo',
            'TOTAL_ROWS' => 'total_rows',
            'INSERTED_COUNT' => 'inserted_count',
            'UPDATED_COUNT' => 'updated_count',
            'WARNING_COUNT' => 'warning_count',
            'ERROR_COUNT' => 'error_count',
            'JSON_PATH' => 'json_path',
            'USUARIO_CARGA' => 'usuario_carga',
        ];

        $insertColumns = array_filter(
            $insertColumns,
            static fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );

        if ($insertColumns === []) {
            return;
        }

        $columnSql = implode(', ', array_keys($insertColumns));
        $valuesSql = implode(', ', array_map(static fn (string $param): string => ':' . $param, array_values($insertColumns)));
        $stmt = $this->pdo->prepare("INSERT INTO IMPORT_LOG ({$columnSql}) VALUES ({$valuesSql})");

        $sheetName = (string) ($payload['sheet_name'] ?? '');
        $fileName = (string) ($payload['file_name'] ?? '');

        $stmt->execute([
            'tab' => (string) ($payload['tab'] ?? 'ingresos'),
            'tipo' => (string) ($payload['tipo'] ?? ''),
            'hoja_nombre' => $sheetName,
            'sheet_name' => $sheetName,
            'archivo_nombre' => $fileName,
            'file_name' => $fileName,
            'total_rows' => (int) ($payload['counts']['total_rows'] ?? 0),
            'inserted_count' => (int) ($payload['inserted_count'] ?? 0),
            'updated_count' => (int) ($payload['updated_count'] ?? 0),
            'warning_count' => (int) ($payload['warning_count'] ?? 0),
            'error_count' => (int) ($payload['error_count'] ?? 0),
            'json_path' => (string) ($payload['json_path'] ?? ''),
            'usuario_carga' => (string) ($payload['usuario'] ?? 'local-user'),
        ]);
    }

    private function getImportLogColumns(): array
    {
        if ($this->importLogColumns !== null) {
            return $this->importLogColumns;
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM IMPORT_LOG');
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];
        foreach ($rows as $row) {
            $name = strtoupper((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $this->importLogColumns = $columns;

        return $this->importLogColumns;
    }

    private function getPresupuestoIngresosColumns(): array
    {
        if ($this->presupuestoIngresosColumns !== null) {
            return $this->presupuestoIngresosColumns;
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM PRESUPUESTO_INGRESOS');
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];
        foreach ($rows as $row) {
            $name = strtoupper((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $required = ['TIPO', 'ANIO', 'CODIGO'];
        foreach ($required as $column) {
            if (!isset($columns[$column])) {
                throw new \RuntimeException('PRESUPUESTO_INGRESOS no tiene columna requerida: ' . $column);
            }
        }

        $this->presupuestoIngresosColumns = $columns;

        return $this->presupuestoIngresosColumns;
    }
}
