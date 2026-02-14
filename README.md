# Importador de Anexos (PHP puro + MySQL)

Aplicación sin framework para importar hojas Excel de **GASTOS** y **NOMINA** hacia `ANEXO_DETALLE`, con carga de archivo embebida en cada pantalla de importación.

## Requisitos
- PHP 8.1+
- MySQL
- Composer

## Instalación
1. `composer install`
2. Configurar conexión en `src/config/config.php`.
3. `php -S localhost:8000 -t public`
4. Abrir `http://localhost:8000`.

## Navegación principal
- `/?r=dashboard`
- `/?r=import-gastos`
- `/?r=import-nomina`
- `/?r=anexos`
- `/?r=history-imports`
- `/?r=config`

## Comportamiento clave
- **Proyecto activo** en `$_SESSION['active_project_id']`.
- Carga de Excel en cada importación, sin archivo global activo.
- Subidas en `public/uploads/gastos/` y `public/uploads/nomina/`.
- Validación de extensión `.xlsx`, MIME y tamaño máximo de 10 MB.
- Importación automática al subir, con mensajes de insertados/warnings.

## Endpoints de acciones
- `POST /?r=import-gastos` sube y procesa hoja GASTOS.
- `POST /?r=import-nomina` sube y procesa hoja NOMINA.
- `GET /?r=anexos` lista anexos con filtros y paginación.
- `GET /?r=history-imports` muestra historial de importaciones del proyecto activo.
