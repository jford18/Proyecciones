<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelClientValidationService
{
    private const PREMISAS_SHEET_NAME = 'Premisas';

    public function assertClientMatchesSpreadsheet(Spreadsheet $spreadsheet, string $clienteSeleccionado): void
    {
        $clienteSeleccionado = trim($clienteSeleccionado);
        if ($clienteSeleccionado === '') {
            throw new \RuntimeException('Debe seleccionar un cliente antes de validar o importar.');
        }

        $sheetPremisas = $spreadsheet->getSheetByName(self::PREMISAS_SHEET_NAME);
        if (!$sheetPremisas instanceof Worksheet) {
            throw new \RuntimeException("No se encontró la hoja 'Premisas' en el archivo Excel.");
        }

        $empresaExcel = trim((string) $sheetPremisas->getCell('A1')->getFormattedValue());
        if ($empresaExcel === '') {
            throw new \RuntimeException("No se encontró el nombre de empresa en la hoja 'Premisas' celda A1.");
        }

        if (!$this->validateExcelClientMatch($empresaExcel, $clienteSeleccionado)) {
            throw new \RuntimeException($this->buildMismatchMessage($empresaExcel, $clienteSeleccionado));
        }
    }

    public function validateExcelClientMatch(string $excelEmpresa, string $clienteSeleccionado): bool
    {
        return $this->normalizeName($excelEmpresa) === $this->normalizeName($clienteSeleccionado);
    }

    private function normalizeName(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    public function buildMismatchMessage(string $empresaExcel, string $clienteSeleccionado): string
    {
        return "El archivo Excel pertenece a una empresa diferente.\n\n"
            . "Empresa encontrada en el Excel (Hoja 'Premisas', celda A1):\n"
            . trim($empresaExcel)
            . "\n\n"
            . "Cliente seleccionado en el sistema:\n"
            . trim($clienteSeleccionado)
            . "\n\n"
            . 'Por favor verifique que esté importando el archivo correcto o seleccione el cliente correspondiente antes de continuar.';
    }
}
