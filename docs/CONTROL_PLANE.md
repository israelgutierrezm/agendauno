# Control plane y multi-tenancy por base de datos

Estado: **Fase 1 (expand) implementada**. Coexiste con el esquema compartido
actual mientras se migra el plano de datos operativo (fases siguientes).

## Dos planos

- **Control plane (central).** Conexión por defecto. Tabla `estudios`: registro
  de cada tenant SaaS con slug, estado de ciclo de vida, publicación en
  directorio, trial, plan/precio por alumno, estado de facturación, contacto del
  propietario y **configuración de su BD de tenant** (`db_driver`, `db_database`).
  No contiene usuarios/alumnos ni datos operativos.
- **Data plane (por tenant).** Una BD física por estudio (SQLite por tenant en
  dev/test; MySQL por tenant en producción con `TENANT_DB_DRIVER=mysql`). Contiene
  la identidad tenant-local (`users`, email único **por tenant**) y —en fases
  siguientes— el resto de la operación.

## Piezas (Fase 1)

- `Modules\Tenancy\Models\Estudio` — registro central (enums `EstadoEstudio`,
  `EstadoFacturacion`).
- `Modules\Tenancy\Database\GestorDeConexionTenant` — apunta la conexión `tenant`
  a la BD del estudio; `ejecutarEn()` limpia SIEMPRE en `finally` (no filtra la
  conexión de un tenant a otro); `aprovisionarBaseDeDatos()` crea la BD y corre
  `database/migrations/tenant`.
- `Application\RegistrarEstudio` — reserva el slug (índice único, seguro ante
  concurrencia → `SLUG_TAKEN`).
- `Application\AprovisionarEstudio` — idempotente/reanudable: crea BD, migra, crea
  el propietario tenant-local (sin contraseña) e inicia el trial (`trialing`).
- `Application\ActivacionPropietario` — token de un solo uso; el propietario fija
  su contraseña al activar.
- `Application\AutenticacionTenant` — tokens de acceso guardados (hash) en la BD
  del tenant; un token de un estudio no existe ni valida en otro.
- Middleware `ResolverEstudio` (resuelve por slug antes de autenticar, activa la
  conexión, falla 404 seguro) y `AutenticarTenant` (Bearer contra la BD del
  tenant).

## Migraciones de los estudios (despliegue)

Un estudio nuevo nace con su esquema al día (lo migra el aprovisionamiento). Los
estudios **existentes** reciben las migraciones nuevas de `database/migrations/tenant`
con:

```bash
php artisan migrate --force                              # control plane
php artisan turnouno:migrar-estudios --force --isolated  # BD de cada estudio
```

- Recorre todos los estudios (`--estudio=slug` para uno solo); se salta los que se
  están aprovisionando y los que no tienen BD.
- Un estudio que falla no frena a los demás: se reporta (`report()`) y el comando
  termina con código de error para que el pipeline lo note. Es idempotente, así que
  basta con volver a correrlo.
- `estudios.version_migraciones` guarda la última migración aplicada a cada estudio
  (la anota `GestorDeConexionTenant::migrar()`, que usan también el
  aprovisionamiento, el demo y la migración legacy).

## Rutas

- `POST /api/v1/registro` — alta pública de estudio (self-service).
- `GET  /api/v1/registro/slug?slug=` — disponibilidad de slug.
- `GET  /api/v1/directorio` — directorio público (solo publicados/no privados).
- `POST /api/v1/app/{estudio}/login` · `/activar` — auth tenant-local.
- `GET  /api/v1/app/{estudio}/yo` · `POST /logout` — sesión tenant-local.

En producción el tenant se resolverá también por `{slug}.turnouno.com`; hoy se
usa `…/app/{slug}` como alternativa configurable.

## Recorrido probado

`registro → provisioning (BD por tenant) → activación → login tenant-local → yo`,
con pruebas de aislamiento: mismo correo en dos tenants = cuentas distintas;
cambio de contraseña independiente; token de A no autentica en B; provisioning
idempotente; slug único ante concurrencia; sin fuga de conexión entre tenants.

## Pendiente (fases siguientes)

Migrar el plano de datos operativo a la BD del tenant (organizaciones, personas,
catálogo, agenda, membresías, reservas, pagos, autorización con spatie
tenant-local, roles/permisos), resolución por subdominio, onboarding, medición de
alumnos activos + facturación SaaS, Google SSO, formularios dinámicos y módulo de
documentos para miembros/instructores, aislamiento de cache/colas/storage/logs
por tenant, y la migración de datos existentes (expand-migrate-verify-cutover).
Ver el roadmap en el reporte de la Fase 1.
