<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class PresupuestoIngresosRepository
{
    private ?array $importLogColumns = null;

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

        $existsStmt = $this->pdo->prepare('SELECT 1 FROM PRESUPUESTO_INGRESOS WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1');
        $upsertStmt = $this->pdo->prepare('INSERT INTO PRESUPUESTO_INGRESOS (
            TIPO, ANIO, CODIGO, NOMBRE_CUENTA,
            ENE, FEB, MAR, ABR, MAY, JUN, JUL, AGO, SEP, OCT, NOV, DIC,
            TOTAL, ARCHIVO_NOMBRE, HOJA_NOMBRE, USUARIO_CARGA
        ) VALUES (
            :tipo, :anio, :codigo, :nombre_cuenta,
            :ene, :feb, :mar, :abr, :may, :jun, :jul, :ago, :sep, :oct, :nov, :dic,
            :total, :archivo_nombre, :hoja_nombre, :usuario_carga
        ) ON DUPLICATE KEY UPDATE
            NOMBRE_CUENTA = VALUES(NOMBRE_CUENTA),
            ENE = VALUES(ENE), FEB = VALUES(FEB), MAR = VALUES(MAR), ABR = VALUES(ABR),
            MAY = VALUES(MAY), JUN = VALUES(JUN), JUL = VALUES(JUL), AGO = VALUES(AGO),
            SEP = VALUES(SEP), OCT = VALUES(OCT), NOV = VALUES(NOV), DIC = VALUES(DIC),
            TOTAL = VALUES(TOTAL),
            ARCHIVO_NOMBRE = VALUES(ARCHIVO_NOMBRE),
            HOJA_NOMBRE = VALUES(HOJA_NOMBRE),
            USUARIO_CARGA = VALUES(USUARIO_CARGA),
            FECHA_ACTUALIZA = CURRENT_TIMESTAMP');

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

                $upsertStmt->execute([
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
                ]);

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
            'TAB' => 'tab',
            'TIPO' => 'tipo',
            'ANIO' => 'anio',
            'INSERTED_COUNT' => 'inserted_count',
            'UPDATED_COUNT' => 'updated_count',
            'SKIPPED_COUNT' => 'skipped_count',
            'WARNING_COUNT' => 'warning_count',
            'ERROR_COUNT' => 'error_count',
            'JSON_PATH' => 'json_path',
            'DETAILS_JSON' => 'details_json',
            'COUNTS_JSON' => 'counts_json',
            'USUARIO' => 'usuario',
            'HOJA_NOMBRE' => 'hoja_nombre',
            'ARCHIVO_NOMBRE' => 'archivo_nombre',
        ];

        if (isset($columns['SHEET_NAME'])) {
            $insertColumns['SHEET_NAME'] = 'sheet_name';
        }
        if (isset($columns['FILE_NAME'])) {
            $insertColumns['FILE_NAME'] = 'file_name';
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
            'anio' => (int) ($payload['anio'] ?? 0),
            'inserted_count' => (int) ($payload['inserted_count'] ?? 0),
            'updated_count' => (int) ($payload['updated_count'] ?? 0),
            'skipped_count' => (int) ($payload['skipped_count'] ?? 0),
            'warning_count' => (int) ($payload['warning_count'] ?? 0),
            'error_count' => (int) ($payload['error_count'] ?? 0),
            'json_path' => (string) ($payload['json_path'] ?? ''),
            'details_json' => json_encode($payload['details'] ?? [], JSON_UNESCAPED_UNICODE),
            'counts_json' => json_encode($payload['counts'] ?? [], JSON_UNESCAPED_UNICODE),
            'usuario' => (string) ($payload['usuario'] ?? 'local-user'),
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

        if (!isset($columns['HOJA_NOMBRE'])) {
            throw new \RuntimeException('IMPORT_LOG no tiene columna HOJA_NOMBRE.');
        }
        if (!isset($columns['ARCHIVO_NOMBRE'])) {
            throw new \RuntimeException('IMPORT_LOG no tiene columna ARCHIVO_NOMBRE.');
        }

        $this->importLogColumns = $columns;

        return $this->importLogColumns;
    }
}
