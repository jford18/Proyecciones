<?php

declare(strict_types=1);

return [
    'GASTOS' => [
        'codigo' => [
            '5101' => 'EGRESOS_OPERACION',
            '5201' => 'EGRESOS_OPERACION',
            '5301' => 'EGRESOS_ADMIN',
        ],
        'concepto' => [
            'arriendos' => 'EGRESOS_OPERACION',
            'servicios publicos' => 'EGRESOS_OPERACION',
        ],
    ],
    'NOMINA' => [
        'codigo' => [],
        'concepto' => [
            'salario' => 'EGRESOS_NOMINA',
            'seguridad social' => 'EGRESOS_NOMINA',
        ],
    ],
    'COBRANZA' => [
        'codigo' => [
            '4101' => 'INGRESOS_OPERACION',
        ],
        'concepto' => [
            'cobro cartera' => 'INGRESOS_OPERACION',
        ],
    ],
    'ACTIVOS' => [
        'codigo' => [
            '6101' => 'INVERSION_ACTIVOS',
        ],
        'concepto' => [
            'compra activo fijo' => 'INVERSION_ACTIVOS',
        ],
    ],
    'pg_accounts' => [
        'INGRESOS_OPERACION' => ['linea' => 'Ingresos de operación', 'seccion' => 'OPERACION', 'orden' => 10],
        'EGRESOS_OPERACION' => ['linea' => 'Egresos de operación', 'seccion' => 'OPERACION', 'orden' => 20],
        'EGRESOS_ADMIN' => ['linea' => 'Egresos administrativos', 'seccion' => 'OPERACION', 'orden' => 30],
        'EGRESOS_NOMINA' => ['linea' => 'Nómina', 'seccion' => 'OPERACION', 'orden' => 40],
        'INVERSION_ACTIVOS' => ['linea' => 'Inversión en activos', 'seccion' => 'INVERSION', 'orden' => 50],
    ],
];
