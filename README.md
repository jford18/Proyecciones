# Proyecciones (PHP puro)

Flujo tipo Excel en 3 pasos:
1. Importar anexos (GASTOS, NÓMINA, COBRANZA, ACTIVOS)
2. Consolidar PG proyectado (puente)
3. Generar FLUJO final (operación/inversión/financiamiento)

## Ejecutar
- `composer install`
- Configura DB en `src/config/config.php`
- `php -S localhost:8000 -t public`

## Pantallas principales
- `/?r=dashboard`
- `/?r=import-gastos`
- `/?r=import-nomina`
- `/?r=import-cobranza`
- `/?r=import-activos`
- `/?r=consolidar-pg`
- `/?r=generar-flujo`
- `/?r=flujo`
