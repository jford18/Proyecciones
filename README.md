# Proyecciones (PHP puro)

## Importación Excel (pestañas amarillas)
La pantalla `/?r=import-excel` ahora trabaja pestaña por pestaña con estas 7 plantillas oficiales:
1. Ingresos (`1.- Ingresos`)
2. Costos (`2.- Costos`)
3. Gastos operacionales (`3.- Gastos operacionales`)
4. Gastos financieros (`4.- Gastos financieros`)
5. Otros ingresos (`5.- Otros ingresos`)
6. Otros egresos (`6.- Otros egresos`)
7. Produccion (`7.-Produccion`)

## Columnas esperadas (fila 1 exacta)
`PERIODO | CODIGO | NOMBRE DE LA CUENTA | Enero | Febrero | Marzo | Abril | Mayo | Junio | Julio | Agosto | Septiembre | Octubre | Noviembre | Diciembre | Total`

## Regla de exclusión por fórmula
Una fila se omite si **cualquier** mes (Enero..Diciembre) contiene texto iniciando con `=`.
Además solo se importan filas con:
- `CODIGO` no vacío
- al menos un mes con valor numérico

El `Total` siempre se recalcula en backend como suma de Enero..Diciembre.

## Endpoints
- `GET /import/templates`
- `POST /import/validate` (archivo + `template_id` o `sheet_name`)
- `POST /import/execute` (archivo + `template_id` o `sheet_name`)
- `GET /import/logs`

## Probar en local
- `composer install`
- Configura DB en `src/config/config.php`
- `php -S localhost:8000 -t public`
- UI: `http://localhost:8000/?r=import-excel`
- API (ejemplo):
  - `curl http://localhost:8000/import/templates`
