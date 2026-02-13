# Importador de Anexos (PHP puro + MySQL)

Aplicación sin framework para importar hojas Excel de **GASTOS** y **NOMINA** hacia `ANEXO_DETALLE`.

## Requisitos
- PHP 8.1+
- MySQL
- Composer

## Instalación
1. Instalar dependencias:
   ```bash
   composer install
   ```
2. Configurar conexión en `src/config/config.php`.
3. Levantar servidor:
   ```bash
   php -S localhost:8000 -t public
   ```
4. Abrir `http://localhost:8000`.

## Endpoints
- `POST /?r=upload-excel` sube archivo excel a `public/uploads`.
- `POST /?r=import-gastos` recibe `proyectoId` y `path`.
- `POST /?r=import-nomina` recibe `proyectoId` y `path`.
- `GET /?r=anexos` lista anexos con filtros `proyectoId`, `tipoAnexo`, `tipo`, `mes`.

## Reglas implementadas
### GASTOS
- Busca hoja `GASTOS`.
- Lee año desde `A3` (texto tipo: `Desde el 01/01/2025 hasta el 31/12/2025`).
- Detecta meses en fila 5 (`Enero..Octubre` usualmente), ignora `Acumulado`.
- Inserta filas por mes con valor distinto de cero.
- `TIPO_ANEXO=GASTOS`, `TIPO=PRESUPUESTO`.

### NOMINA
- Busca hoja `NOMINA`.
- Lee mes/año desde `A2` (`Rol de Pago - Noviembre 2025`).
- Suma columnas de valores por concepto (sin detallar por empleado).
- Inserta una fila por concepto total.
- `TIPO_ANEXO=NOMINA`, `TIPO=REAL`, `DESCRIPCION=NOMINA TOTAL`.

### Mapeo automático opcional
- Si existe `ANEXO_MAPEO`, intenta resolver `FLUJO_LINEA_ID` por:
  1. (`PROYECTO_ID`, `TIPO_ANEXO`, `CODIGO`)
  2. (`PROYECTO_ID`, `TIPO_ANEXO`, `CONCEPTO`)
- Si no encuentra mapeo, deja `FLUJO_LINEA_ID = NULL`.

### Log
- Inserta en `ANEXO_IMPORT_LOG`:
  `PROYECTO_ID`, `ARCHIVO`, `HOJA`, `REGISTROS_INSERTADOS`, `MENSAJE`.

## Estructura
- `public/` UI básica, ruteo simple y assets.
- `src/db/Db.php` conexión PDO.
- `src/controllers/` controladores de importación/listado.
- `src/services/` parser de Excel y mapeo.
- `src/repositories/` acceso a `ANEXO_DETALLE`, `ANEXO_IMPORT_LOG`, `FLUJO_LINEA`.

> Nota: Este repo **no incluye SQL** de creación de tablas por solicitud.
