<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../vendor/autoload.php';

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
