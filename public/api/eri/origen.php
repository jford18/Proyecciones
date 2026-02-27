<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../vendor/autoload.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeRealValue(mixed $raw): ?float
{
    if ($raw === null || $raw === '') {
        return null;
    }

    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }

    if (!is_string($raw)) {
        throw new InvalidArgumentException('valor_real debe ser numérico, texto numérico o null.');
    }

    $text = trim($raw);
    if ($text === '') {
        return null;
    }

    $text = str_replace([' ', "\t", "\n", "\r"], '', $text);
    $hasComma = str_contains($text, ',');
    $hasDot = str_contains($text, '.');

    if ($hasComma && $hasDot) {
        if (strrpos($text, ',') > strrpos($text, '.')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }
    } elseif ($hasComma) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } else {
        $parts = explode('.', $text);
        if (count($parts) > 2) {
            $decimal = array_pop($parts);
            $text = implode('', $parts) . '.' . $decimal;
        }
    }

    if (!is_numeric($text)) {
        throw new InvalidArgumentException('valor_real debe ser numérico, texto numérico o null.');
    }

    return (float) $text;
}

function handleEriReal(PDO $pdo): never
{
    $op = strtoupper(trim((string) ($_GET['op'] ?? '')));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($op === 'LIST') {
        $anio = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
        $tipo = strtoupper(trim((string) ($_GET['tipo'] ?? 'REAL')));
        if ($anio === false || $anio === null || $anio < 1900 || $anio > 3000) {
            jsonResponse(['ok' => false, 'error' => 'anio es requerido y debe ser válido.'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT CODIGO, ENERO, FEBRERO, MARZO, ABRIL, MAYO, JUNIO, JULIO, AGOSTO, SEPTIEMBRE, OCTUBRE, NOVIEMBRE, DICIEMBRE
             FROM EEFF_REALES_ERI_IMPORT
             WHERE ANIO = :anio AND UPPER(COALESCE(TIPO, "")) = :tipo
             ORDER BY CODIGO'
        );
        $stmt->execute(['anio' => $anio, 'tipo' => $tipo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $data = [];
        $months = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];
        foreach ($rows as $row) {
            $codigo = (string) ($row['CODIGO'] ?? '');
            if ($codigo === '') {
                continue;
            }
            foreach ($months as $mes => $column) {
                $data[] = [
                    'codigo' => $codigo,
                    'mes' => $mes,
                    'valor_real' => isset($row[$column]) ? (float) $row[$column] : 0.0,
                ];
            }
        }

        jsonResponse(['ok' => true, 'data' => $data]);
    }

    if ($op === 'UPSERT') {
        if ($method !== 'POST') {
            jsonResponse(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }

        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);
        if (!is_array($payload)) {
            jsonResponse(['ok' => false, 'error' => 'JSON inválido.'], 400);
        }

        $anio = filter_var($payload['anio'] ?? null, FILTER_VALIDATE_INT);
        $tipo = strtoupper(trim((string) ($payload['tipo'] ?? 'REAL')));
        $mes = filter_var($payload['mes'] ?? null, FILTER_VALIDATE_INT);
        $codigo = trim((string) ($payload['codigo'] ?? ''));
        $descripcion = trim((string) ($payload['descripcion'] ?? ''));

        if ($anio === false || $anio === null || $anio < 1900 || $anio > 3000) {
            jsonResponse(['ok' => false, 'error' => 'anio es requerido y debe ser válido.'], 422);
        }
        if ($codigo === '') {
            jsonResponse(['ok' => false, 'error' => 'codigo es requerido.'], 422);
        }
        if ($mes === false || $mes === null || $mes < 1 || $mes > 12) {
            jsonResponse(['ok' => false, 'error' => 'mes debe estar entre 1 y 12.'], 422);
        }

        try {
            $valorReal = normalizeRealValue($payload['valor'] ?? null);
        } catch (InvalidArgumentException $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $monthColumns = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];
        $monthColumn = $monthColumns[$mes] ?? null;
        if ($monthColumn === null) {
            jsonResponse(['ok' => false, 'error' => 'mes debe estar entre 1 y 12.'], 422);
        }

        $checkStmt = $pdo->prepare('SELECT 1 FROM EEFF_REALES_ERI_IMPORT WHERE ANIO = :anio AND UPPER(COALESCE(TIPO, "")) = :tipo AND CODIGO = :codigo LIMIT 1');
        $checkStmt->execute(['anio' => $anio, 'tipo' => $tipo, 'codigo' => $codigo]);
        $exists = $checkStmt->fetchColumn() !== false;

        if ($exists) {
            $sql = sprintf('UPDATE EEFF_REALES_ERI_IMPORT SET %s = :valor, TOTAL = COALESCE(ENERO,0)+COALESCE(FEBRERO,0)+COALESCE(MARZO,0)+COALESCE(ABRIL,0)+COALESCE(MAYO,0)+COALESCE(JUNIO,0)+COALESCE(JULIO,0)+COALESCE(AGOSTO,0)+COALESCE(SEPTIEMBRE,0)+COALESCE(OCTUBRE,0)+COALESCE(NOVIEMBRE,0)+COALESCE(DICIEMBRE,0) WHERE ANIO = :anio AND UPPER(COALESCE(TIPO, "")) = :tipo AND CODIGO = :codigo', $monthColumn);
            $stmt = $pdo->prepare($sql);
            if ($valorReal === null) {
                $stmt->bindValue(':valor', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':valor', $valorReal);
            }
            $stmt->bindValue(':anio', (int) $anio, PDO::PARAM_INT);
            $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
            $stmt->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $stmt->execute();
        } else {
            $insertColumns = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
            $insertData = array_fill_keys($insertColumns, 0.0);
            $insertData[$monthColumn] = $valorReal;

            $sql = 'INSERT INTO EEFF_REALES_ERI_IMPORT (
                        ANIO, TIPO, CODIGO, DESCRIPCION,
                        ENERO, FEBRERO, MARZO, ABRIL, MAYO, JUNIO, JULIO, AGOSTO, SEPTIEMBRE, OCTUBRE, NOVIEMBRE, DICIEMBRE,
                        TOTAL, ORIGEN_ARCHIVO, ORIGEN_HOJA, ORIGEN_FILA
                    ) VALUES (
                        :anio, :tipo, :codigo, :descripcion,
                        :ENERO, :FEBRERO, :MARZO, :ABRIL, :MAYO, :JUNIO, :JULIO, :AGOSTO, :SEPTIEMBRE, :OCTUBRE, :NOVIEMBRE, :DICIEMBRE,
                        :total, :origen_archivo, :origen_hoja, :origen_fila
                    )';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':anio', (int) $anio, PDO::PARAM_INT);
            $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
            $stmt->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $stmt->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);
            foreach ($insertColumns as $column) {
                $value = $insertData[$column];
                if ($value === null) {
                    $stmt->bindValue(':' . $column, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':' . $column, (float) $value);
                }
            }
            $total = 0.0;
            foreach ($insertColumns as $column) {
                $total += (float) ($insertData[$column] ?? 0.0);
            }
            $stmt->bindValue(':total', $total);
            $stmt->bindValue(':origen_archivo', 'MANUAL', PDO::PARAM_STR);
            $stmt->bindValue(':origen_hoja', 'ERI_UI', PDO::PARAM_STR);
            $stmt->bindValue(':origen_fila', 0, PDO::PARAM_INT);
            $stmt->execute();
        }

        error_log(sprintf('[ERI][REAL_SAVE] anio=%d codigo=%s mes=%d valor=%s', (int) $anio, $codigo, (int) $mes, (string) ($valorReal ?? 'null')));
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'error' => 'Operación ERI_REAL no soportada.'], 400);
}

function resolverTabOrigen(string $codigo): string
{
    $prefijo = substr($codigo, 0, 1);

    return match ($prefijo) {
        '4' => 'INGRESOS',
        '5' => 'COSTOS',
        '6' => 'GASTOS_OPERACIONALES',
        '7' => 'GASTOS_FINANCIEROS',
        '8' => 'OTROS_INGRESOS',
        '9' => 'OTROS_EGRESOS',
        default => 'DESCONOCIDO',
    };
}

function monthColumn(int $mes): string
{
    $map = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];
    if (!isset($map[$mes])) {
        throw new InvalidArgumentException('Mes inválido.');
    }

    return $map[$mes];
}

function monthName(int $mes): string
{
    $map = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];

    return $map[$mes] ?? '';
}

function tabMeta(string $tab): array
{
    return match ($tab) {
        'INGRESOS' => ['table' => 'PRESUPUESTO_INGRESOS', 'sheet' => '1.- Ingresos', 'id' => 'ingresos', 'sign' => 1],
        'COSTOS' => ['table' => 'PRESUPUESTO_COSTOS', 'sheet' => '2.- Costos', 'id' => 'costos', 'sign' => -1],
        'GASTOS_OPERACIONALES' => ['table' => 'PRESUPUESTO_GASTOS_OPERACIONALES', 'sheet' => '3.- Gastos operacionales', 'id' => 'gastos_operacionales', 'sign' => -1],
        'GASTOS_FINANCIEROS' => ['table' => 'PRESUPUESTO_GASTOS_FINANCIEROS', 'sheet' => '4.- Gastos financieros', 'id' => 'gastos_financieros', 'sign' => -1],
        'OTROS_INGRESOS' => ['table' => 'PRESUPUESTO_OTROS_INGRESOS', 'sheet' => '5.- Otros ingresos', 'id' => 'otros_ingresos', 'sign' => 1],
        'OTROS_EGRESOS' => ['table' => 'PRESUPUESTO_OTROS_EGRESOS', 'sheet' => '6.- Otros egresos', 'id' => 'otros_egresos', 'sign' => -1],
        default => ['table' => null, 'sheet' => 'N/D', 'id' => 'desconocido', 'sign' => 1],
    };
}

function findLatestImportLog(PDO $pdo, string $tabId, string $tipo): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM IMPORT_LOG WHERE TAB = :tab AND TIPO = :tipo ORDER BY ID DESC LIMIT 1');
    $stmt->execute(['tab' => strtolower($tabId), 'tipo' => $tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function toFloat(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

$config = require __DIR__ . '/../../../src/config/config.php';
$pdo = Db::pdo($config);

$mod = strtoupper(trim((string) ($_GET['mod'] ?? '')));
if ($mod === 'ERI_REAL') {
    try {
        handleEriReal($pdo);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$anio = (int) ($_GET['anio'] ?? date('Y'));
$codigo = trim((string) ($_GET['codigo'] ?? ''));
$mes = (int) ($_GET['mes'] ?? 0);
$tipo = strtoupper(trim((string) ($_GET['tipo'] ?? 'PRESUPUESTO')));

if ($codigo === '' || $mes < 1 || $mes > 12) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Parámetros inválidos: anio, codigo y mes son obligatorios.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tab = resolverTabOrigen($codigo);
$meta = tabMeta($tab);

if ($meta['table'] === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'No se pudo determinar el origen del código solicitado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mesCol = monthColumn($mes);
$mesNombre = monthName($mes);

try {
    $stmt = $pdo->prepare("SELECT CODIGO, COALESCE(NOMBRE_CUENTA, '') AS NOMBRE_CUENTA, {$mesCol} AS MES_VALOR FROM {$meta['table']} WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO = :codigo LIMIT 1");
    $stmt->execute(['tipo' => $tipo, 'anio' => $anio, 'codigo' => $codigo]);
    $direct = $stmt->fetch(PDO::FETCH_ASSOC);

    $latestImport = findLatestImportLog($pdo, (string) $meta['id'], $tipo);
    $archivo = (string) ($latestImport['ARCHIVO_NOMBRE'] ?? $latestImport['FILE_NAME'] ?? 'Plantilla.xlsx');
    $jsonPath = (string) ($latestImport['JSON_PATH'] ?? ('var/import_store/' . $meta['id'] . '.json'));

    $formula = ['tipo' => 'DIRECTO', 'explicacion' => ''];
    $descripcion = (string) ($direct['NOMBRE_CUENTA'] ?? '');
    $valorOriginal = toFloat($direct['MES_VALOR'] ?? 0);
    $valorEri = $valorOriginal * (int) $meta['sign'];

    if ($direct === false) {
        $childStmt = $pdo->prepare("SELECT CODIGO, COALESCE(NOMBRE_CUENTA, '') AS NOMBRE_CUENTA, {$mesCol} AS MES_VALOR FROM {$meta['table']} WHERE TIPO = :tipo AND ANIO = :anio AND CODIGO LIKE :prefijo ORDER BY CODIGO");
        $childStmt->execute(['tipo' => $tipo, 'anio' => $anio, 'prefijo' => $codigo . '%']);
        $children = $childStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $components = [];
        $sumOriginal = 0.0;
        foreach ($children as $child) {
            $childCode = (string) ($child['CODIGO'] ?? '');
            if (strlen($childCode) !== 7 || $childCode === $codigo) {
                continue;
            }
            $childValue = toFloat($child['MES_VALOR'] ?? 0);
            $sumOriginal += $childValue;
            $components[] = ['codigo' => $childCode, 'valor' => $childValue * (int) $meta['sign']];
            if ($descripcion === '') {
                $descripcion = (string) ($child['NOMBRE_CUENTA'] ?? '');
            }
        }

        $valorOriginal = $sumOriginal;
        $valorEri = $sumOriginal * (int) $meta['sign'];
        $formula = [
            'tipo' => 'SUMA',
            'componentes' => $components,
            'explicacion' => 'El valor corresponde a un subtotal y se obtiene sumando las cuentas hijas del prefijo ' . $codigo . ' para el mes ' . $mesNombre . '.',
        ];
    } else {
        $formula['explicacion'] = 'El valor se toma directamente de la hoja ' . $meta['sheet'] . ', cuenta ' . $codigo . ' para el mes ' . $mesNombre . '.';
    }

    $response = [
        'ok' => true,
        'anio' => $anio,
        'codigo' => $codigo,
        'mes' => $mes,
        'valor_eri' => $valorEri,
        'descripcion' => $descripcion,
        'origen' => [
            'tab' => $tab,
            'hoja_excel' => $meta['sheet'],
            'prefijo_detectado' => substr($codigo, 0, 1),
        ],
        'detalle' => [
            'valor_original' => $valorOriginal,
            'archivo' => $archivo,
            'hoja' => $meta['sheet'],
            'mes_nombre' => $mesNombre,
            'json_path' => $jsonPath,
            'ver_excel_url' => '/?r=import-excel&action=view_excel&tab=' . $meta['id'] . '&tipo=' . urlencode($tipo) . '&anio=' . $anio,
        ],
        'formula' => $formula,
    ];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
