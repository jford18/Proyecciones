<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class PresupuestoIngresosRepository
{
    private ?array $importLogColumns = null;
    private ?array $presupuestoIngresosColumns = null;
    private ?array $presupuestoCostosColumns = null;
    private ?array $presupuestoOtrosIngresosColumns = null;
    private ?array $presupuestoOtrosEgresosColumns = null;
    private ?array $presupuestoGastosOperacionalesColumns = null;
    private ?array $presupuestoGastosFinancierosColumns = null;
    private ?array $presupuestoProduccionColumns = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function upsertIngresosRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        return $this->upsertRowsByTab('ingresos', $tipo, $sheetName, $fileName, $usuario, $rows);
    }

    public function upsertCostosRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        return $this->upsertRowsByTab('costos', $tipo, $sheetName, $fileName, $usuario, $rows);
    }

    public function upsertOtrosIngresosRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        return $this->upsertRowsByTab('otros_ingresos', $tipo, $sheetName, $fileName, $usuario, $rows);
    }

    public function upsertOtrosEgresosRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        if ($rows === []) {
            return ['inserted_count' => 0, 'updated_count' => 0];
        }

        $inserted = 0;
        $updated = 0;

        $sql = 'INSERT INTO PRESUPUESTO_OTROS_EGRESOS (
            TIPO,
            ANIO,
            PERIODO,
            CODIGO,
            NOMBRE_CUENTA,
            ENE,
            FEB,
            MAR,
            ABR,
            MAY,
            JUN,
            JUL,
            AGO,
            SEP,
            OCT,
            NOV,
            DIC,
            TOTAL_RECALCULADO,
            ARCHIVO_NOMBRE,
            HOJA_NOMBRE,
            USUARIO_CARGA
        ) VALUES (
            :TIPO,
            :ANIO,
            :PERIODO,
            :CODIGO,
            :NOMBRE_CUENTA,
            :ENE,
            :FEB,
            :MAR,
            :ABR,
            :MAY,
            :JUN,
            :JUL,
            :AGO,
            :SEP,
            :OCT,
            :NOV,
            :DIC,
            :TOTAL_RECALCULADO,
            :ARCHIVO_NOMBRE,
            :HOJA_NOMBRE,
            :USUARIO_CARGA
        )
        ON DUPLICATE KEY UPDATE
            NOMBRE_CUENTA = VALUES(NOMBRE_CUENTA),
            ENE = VALUES(ENE),
            FEB = VALUES(FEB),
            MAR = VALUES(MAR),
            ABR = VALUES(ABR),
            MAY = VALUES(MAY),
            JUN = VALUES(JUN),
            JUL = VALUES(JUL),
            AGO = VALUES(AGO),
            SEP = VALUES(SEP),
            OCT = VALUES(OCT),
            NOV = VALUES(NOV),
            DIC = VALUES(DIC),
            TOTAL_RECALCULADO = VALUES(TOTAL_RECALCULADO),
            ARCHIVO_NOMBRE = VALUES(ARCHIVO_NOMBRE),
            HOJA_NOMBRE = VALUES(HOJA_NOMBRE),
            USUARIO_CARGA = VALUES(USUARIO_CARGA)';

        $upsertStmt = $this->pdo->prepare($sql);
        $existsStmt = $this->pdo->prepare('SELECT 1 FROM PRESUPUESTO_OTROS_EGRESOS WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1');

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $codigo = (string) ($row['codigo'] ?? '');
                $anio = (int) ($row['anio'] ?? 0);
                $periodo = (string) ($row['periodo'] ?? $anio);

                $params = [
                    'TIPO' => $tipo,
                    'ANIO' => $anio,
                    'PERIODO' => $periodo,
                    'CODIGO' => $codigo,
                    'NOMBRE_CUENTA' => (string) ($row['nombre_cuenta'] ?? $row['nombre'] ?? ''),
                    'ENE' => $row['ene'] ?? null,
                    'FEB' => $row['feb'] ?? null,
                    'MAR' => $row['mar'] ?? null,
                    'ABR' => $row['abr'] ?? null,
                    'MAY' => $row['may'] ?? null,
                    'JUN' => $row['jun'] ?? null,
                    'JUL' => $row['jul'] ?? null,
                    'AGO' => $row['ago'] ?? null,
                    'SEP' => $row['sep'] ?? null,
                    'OCT' => $row['oct'] ?? null,
                    'NOV' => $row['nov'] ?? null,
                    'DIC' => $row['dic'] ?? null,
                    'TOTAL_RECALCULADO' => (float) ($row['total_recalculado'] ?? $row['total'] ?? 0),
                    'ARCHIVO_NOMBRE' => $fileName,
                    'HOJA_NOMBRE' => $sheetName,
                    'USUARIO_CARGA' => $usuario,
                ];

                $existsStmt->execute([
                    'tipo' => $tipo,
                    'anio' => $anio,
                    'codigo' => $codigo,
                ]);
                $alreadyExists = $existsStmt->fetchColumn() !== false;

                $upsertStmt->execute($params);
                if ($alreadyExists) {
                    $updated++;
                } else {
                    $inserted++;
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

    public function upsertGastosOperacionalesRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        return $this->upsertRowsByTab('gastos_operacionales', $tipo, $sheetName, $fileName, $usuario, $rows);
    }

    public function upsertGastosFinancierosRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        return $this->upsertRowsByTab('gastos_financieros', $tipo, $sheetName, $fileName, $usuario, $rows);
    }

    public function upsertProduccionRows(string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        if ($rows === []) {
            return ['inserted_count' => 0, 'updated_count' => 0];
        }

        $inserted = 0;
        $updated = 0;

        $sql = 'INSERT INTO PRESUPUESTO_PRODUCCION (
            TIPO,
            ANIO,
            PERIODO,
            CODIGO,
            NOMBRE_CUENTA,
            ENE,
            FEB,
            MAR,
            ABR,
            MAY,
            JUN,
            JUL,
            AGO,
            SEP,
            OCT,
            NOV,
            DIC,
            TOTAL_RECALCULADO,
            ARCHIVO_NOMBRE,
            HOJA_NOMBRE,
            USUARIO_CARGA,
            FECHA_CARGA
        ) VALUES (
            :TIPO,
            :ANIO,
            :PERIODO,
            :CODIGO,
            :NOMBRE_CUENTA,
            :ENE,
            :FEB,
            :MAR,
            :ABR,
            :MAY,
            :JUN,
            :JUL,
            :AGO,
            :SEP,
            :OCT,
            :NOV,
            :DIC,
            :TOTAL_RECALCULADO,
            :ARCHIVO_NOMBRE,
            :HOJA_NOMBRE,
            :USUARIO_CARGA,
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            NOMBRE_CUENTA = VALUES(NOMBRE_CUENTA),
            ENE = VALUES(ENE),
            FEB = VALUES(FEB),
            MAR = VALUES(MAR),
            ABR = VALUES(ABR),
            MAY = VALUES(MAY),
            JUN = VALUES(JUN),
            JUL = VALUES(JUL),
            AGO = VALUES(AGO),
            SEP = VALUES(SEP),
            OCT = VALUES(OCT),
            NOV = VALUES(NOV),
            DIC = VALUES(DIC),
            TOTAL_RECALCULADO = VALUES(TOTAL_RECALCULADO),
            ARCHIVO_NOMBRE = VALUES(ARCHIVO_NOMBRE),
            HOJA_NOMBRE = VALUES(HOJA_NOMBRE),
            USUARIO_CARGA = VALUES(USUARIO_CARGA),
            FECHA_CARGA = CURRENT_TIMESTAMP';

        $upsertStmt = $this->pdo->prepare($sql);
        $existsStmt = $this->pdo->prepare('SELECT 1 FROM PRESUPUESTO_PRODUCCION WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1');

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $params = [
                    'TIPO' => $tipo,
                    'ANIO' => (int) ($row['anio'] ?? 0),
                    'PERIODO' => (int) ($row['periodo'] ?? $row['anio'] ?? 0),
                    'CODIGO' => (string) ($row['codigo'] ?? ''),
                    'NOMBRE_CUENTA' => (string) ($row['nombre_cuenta'] ?? ''),
                    'ENE' => $row['ene'] ?? null,
                    'FEB' => $row['feb'] ?? null,
                    'MAR' => $row['mar'] ?? null,
                    'ABR' => $row['abr'] ?? null,
                    'MAY' => $row['may'] ?? null,
                    'JUN' => $row['jun'] ?? null,
                    'JUL' => $row['jul'] ?? null,
                    'AGO' => $row['ago'] ?? null,
                    'SEP' => $row['sep'] ?? null,
                    'OCT' => $row['oct'] ?? null,
                    'NOV' => $row['nov'] ?? null,
                    'DIC' => $row['dic'] ?? null,
                    'TOTAL_RECALCULADO' => (float) ($row['total_recalculado'] ?? 0),
                    'ARCHIVO_NOMBRE' => $fileName,
                    'HOJA_NOMBRE' => $sheetName,
                    'USUARIO_CARGA' => $usuario,
                ];

                $existsStmt->execute([
                    'tipo' => $tipo,
                    'anio' => (int) ($row['anio'] ?? 0),
                    'codigo' => (string) ($row['codigo'] ?? ''),
                ]);
                $alreadyExists = $existsStmt->fetchColumn() !== false;

                $upsertStmt->execute($params);
                if ($alreadyExists) {
                    $updated++;
                } else {
                    $inserted++;
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

    public function upsertRowsByTab(string $tab, string $tipo, string $sheetName, string $fileName, string $usuario, array $rows): array
    {
        if ($rows === []) {
            return ['inserted_count' => 0, 'updated_count' => 0];
        }

        $table = $this->tableByTab($tab);

        $inserted = 0;
        $updated = 0;

        $columns = $this->getPresupuestoColumnsByTab($tab);
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
        $upsertStmt = $this->pdo->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$valuesSql}){$updateSql}");
        $existsStmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1");

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $codigo = (string) ($row['codigo'] ?? '');

                $payload = [
                    'tipo' => $tipo,
                    'anio' => (int) ($row['anio'] ?? 0),
                    'codigo' => $codigo,
                    'nombre_cuenta' => (string) ($row['nombre_cuenta'] ?? $row['nombre'] ?? ''),
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

                $existsStmt->execute([
                    'tipo' => $tipo,
                    'anio' => (int) ($row['anio'] ?? 0),
                    'codigo' => $codigo,
                ]);
                $alreadyExists = $existsStmt->fetchColumn() !== false;

                $upsertStmt->execute($payload);
                if ($alreadyExists) {
                    $updated++;
                } else {
                    $inserted++;
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
        $archivoNombre = (string) ($payload['archivo_nombre'] ?? $fileName);

        $stmt->execute([
            'tab' => (string) ($payload['tab'] ?? 'ingresos'),
            'tipo' => (string) ($payload['tipo'] ?? ''),
            'hoja_nombre' => $sheetName,
            'sheet_name' => $sheetName,
            'archivo_nombre' => $archivoNombre,
            'file_name' => $fileName,
            'total_rows' => (int) ($payload['counts']['total_rows'] ?? 0),
            'inserted_count' => (int) ($payload['inserted_count'] ?? 0),
            'updated_count' => (int) ($payload['updated_count'] ?? 0),
            'warning_count' => (int) ($payload['warning_count'] ?? 0),
            'error_count' => (int) ($payload['error_count'] ?? 0),
            'json_path' => $payload['json_path'] ?? null,
            'usuario_carga' => (string) ($payload['usuario'] ?? 'local-user'),
        ]);
    }

    public function findFirstAnioByTipo(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('ingresos', $tipo);
    }

    public function findFirstAnioByTipoCostos(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('costos', $tipo);
    }

    public function findFirstAnioByTipoOtrosIngresos(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('otros_ingresos', $tipo);
    }

    public function findFirstAnioByTipoGastosOperacionales(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('gastos_operacionales', $tipo);
    }

    public function findFirstAnioByTipoOtrosEgresos(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('otros_egresos', $tipo);
    }

    public function findFirstAnioByTipoGastosFinancieros(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('gastos_financieros', $tipo);
    }

    public function findFirstAnioByTipoProduccion(string $tipo): ?int
    {
        return $this->findFirstAnioByTipoByTab('produccion', $tipo);
    }

    public function findFirstAnioByTipoByTab(string $tab, string $tipo): ?int
    {
        $table = $this->tableByTab($tab);
        $stmt = $this->pdo->prepare("SELECT ANIO FROM {$table} WHERE TIPO = :tipo ORDER BY ANIO DESC LIMIT 1");
        $stmt->execute(['tipo' => $tipo]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function fetchIngresosRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('ingresos', $tipo, $anio);
    }

    public function fetchCostosRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('costos', $tipo, $anio);
    }

    public function fetchOtrosIngresosRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('otros_ingresos', $tipo, $anio);
    }

    public function fetchGastosOperacionalesRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('gastos_operacionales', $tipo, $anio);
    }

    public function fetchOtrosEgresosRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('otros_egresos', $tipo, $anio);
    }

    public function fetchGastosFinancierosRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('gastos_financieros', $tipo, $anio);
    }

    public function fetchProduccionRowsForGrid(string $tipo, int $anio): array
    {
        return $this->fetchRowsForGridByTab('produccion', $tipo, $anio);
    }

    public function fetchRowsForGridByTab(string $tab, string $tipo, int $anio): array
    {
        $table = $this->tableByTab($tab);
        $columns = $this->getPresupuestoColumnsByTab($tab);
        $totalColumn = isset($columns['TOTAL_RECALCULADO']) ? 'TOTAL_RECALCULADO' : 'TOTAL';
        $stmt = $this->pdo->prepare(
            'SELECT
                ANIO AS PERIODO,
                CODIGO,
                NOMBRE_CUENTA,
                ENE,
                FEB,
                MAR,
                ABR,
                MAY,
                JUN,
                JUL,
                AGO,
                SEP,
                OCT,
                NOV,
                DIC,
                ' . $totalColumn . ' AS TOTAL,
                ' . $totalColumn . ' AS TOTAL_RECALCULADO
            FROM ' . $table . '
            WHERE TIPO = :tipo AND ANIO = :anio
            ORDER BY LENGTH(CODIGO), CODIGO'
        );
        $stmt->execute(['tipo' => $tipo, 'anio' => $anio]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findLatestImportLogByTabTipo(string $tab, string $tipo): ?array
    {
        $columns = $this->getImportLogColumns();
        if (!isset($columns['TAB']) || !isset($columns['TIPO'])) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM IMPORT_LOG WHERE TAB = :tab AND TIPO = :tipo ORDER BY ID DESC LIMIT 1');
        $stmt->execute([
            'tab' => strtolower(trim($tab)),
            'tipo' => trim($tipo),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
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
        return $this->getPresupuestoColumnsByTab('ingresos');
    }

    private function getPresupuestoCostosColumns(): array
    {
        return $this->getPresupuestoColumnsByTab('costos');
    }

    private function getPresupuestoColumnsByTab(string $tab): array
    {
        if ($tab === 'ingresos' && $this->presupuestoIngresosColumns !== null) {
            return $this->presupuestoIngresosColumns;
        }
        if ($tab === 'costos' && $this->presupuestoCostosColumns !== null) {
            return $this->presupuestoCostosColumns;
        }
        if ($tab === 'otros_ingresos' && $this->presupuestoOtrosIngresosColumns !== null) {
            return $this->presupuestoOtrosIngresosColumns;
        }
        if ($tab === 'otros_egresos' && $this->presupuestoOtrosEgresosColumns !== null) {
            return $this->presupuestoOtrosEgresosColumns;
        }
        if ($tab === 'gastos_operacionales' && $this->presupuestoGastosOperacionalesColumns !== null) {
            return $this->presupuestoGastosOperacionalesColumns;
        }
        if ($tab === 'gastos_financieros' && $this->presupuestoGastosFinancierosColumns !== null) {
            return $this->presupuestoGastosFinancierosColumns;
        }
        if ($tab === 'produccion' && $this->presupuestoProduccionColumns !== null) {
            return $this->presupuestoProduccionColumns;
        }

        $table = $this->tableByTab($tab);
        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
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
                throw new \RuntimeException($table . ' no tiene columna requerida: ' . $column);
            }
        }

        if ($tab === 'costos') {
            $this->presupuestoCostosColumns = $columns;
            return $this->presupuestoCostosColumns;
        }

        if ($tab === 'gastos_operacionales') {
            $this->presupuestoGastosOperacionalesColumns = $columns;
            return $this->presupuestoGastosOperacionalesColumns;
        }

        if ($tab === 'otros_ingresos') {
            $this->presupuestoOtrosIngresosColumns = $columns;
            return $this->presupuestoOtrosIngresosColumns;
        }

        if ($tab === 'otros_egresos') {
            $this->presupuestoOtrosEgresosColumns = $columns;
            return $this->presupuestoOtrosEgresosColumns;
        }

        if ($tab === 'gastos_financieros') {
            $this->presupuestoGastosFinancierosColumns = $columns;
            return $this->presupuestoGastosFinancierosColumns;
        }

        if ($tab === 'produccion') {
            $this->presupuestoProduccionColumns = $columns;
            return $this->presupuestoProduccionColumns;
        }

        $this->presupuestoIngresosColumns = $columns;
        return $this->presupuestoIngresosColumns;
    }

    private function tableByTab(string $tab): string
    {
        return match (strtolower($tab)) {
            'costos' => 'PRESUPUESTO_COSTOS',
            'otros_ingresos' => 'PRESUPUESTO_OTROS_INGRESOS',
            'otros_egresos' => 'PRESUPUESTO_OTROS_EGRESOS',
            'gastos_operacionales' => 'PRESUPUESTO_GASTOS_OPERACIONALES',
            'gastos_financieros' => 'PRESUPUESTO_GASTOS_FINANCIEROS',
            'produccion' => 'PRESUPUESTO_PRODUCCION',
            default => 'PRESUPUESTO_INGRESOS',
        };
    }
}
