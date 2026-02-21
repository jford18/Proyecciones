<?php

declare(strict_types=1);

namespace App\services;

class ImportTemplateCatalog
{
    public const FIXED_HEADER = [
        'PERIODO',
        'CODIGO',
        'NOMBRE DE LA CUENTA',
        'Enero',
        'Febrero',
        'Marzo',
        'Abril',
        'Mayo',
        'Junio',
        'Julio',
        'Agosto',
        'Septiembre',
        'Octubre',
        'Noviembre',
        'Diciembre',
        'Total',
    ];

    public function templates(): array
    {
        return [
            ['id' => 'ingresos', 'label' => 'Ingresos', 'sheet_name' => '1.- Ingresos', 'columns' => self::FIXED_HEADER],
            ['id' => 'costos', 'label' => 'Costos', 'sheet_name' => '2.- Costos', 'columns' => self::FIXED_HEADER],
            ['id' => 'gastos_operacionales', 'label' => 'Gastos operacionales', 'sheet_name' => '3.- Gastos operacionales', 'columns' => self::FIXED_HEADER],
            ['id' => 'gastos_financieros', 'label' => 'Gastos financieros', 'sheet_name' => '4.- Gastos financieros', 'columns' => self::FIXED_HEADER],
            ['id' => 'otros_ingresos', 'label' => 'Otros ingresos', 'sheet_name' => '5.- Otros ingresos', 'columns' => self::FIXED_HEADER],
            ['id' => 'otros_egresos', 'label' => 'Otros egresos', 'sheet_name' => '6.- Otros egresos', 'columns' => self::FIXED_HEADER],
            ['id' => 'produccion', 'label' => 'Produccion', 'sheet_name' => '7.-Produccion', 'columns' => self::FIXED_HEADER],
        ];
    }

    public function findByIdOrSheet(string $idOrSheet): ?array
    {
        $needle = trim($idOrSheet);
        foreach ($this->templates() as $template) {
            if ($template['id'] === $needle || mb_strtolower($template['sheet_name']) === mb_strtolower($needle)) {
                return $template;
            }
        }

        return null;
    }
}

