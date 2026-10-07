# Control plane y multi-tenancy por base de datos

Estado: **implementado**. Todo el sistema corre así: el esquema compartido con
`tenant_id` se retiró (ADR 0058). Resumen del modelo en `docs/TENANCY.md`.

## Dos planos

- **Control plane (central).** Conexión por defecto. Tabla `estudios`: registro
  de cada tenant SaaS con slug, estado de ciclo de vida, publicación en
  directorio, prueba, perfil y terminología, `modalidad` (clases o citas, guardada y
  excluyente, ADR 0104), modalidad de cobro del SaaS, estado de
  facturación, contacto del propietario y **configuración de su BD de tenant**
  (`db_driver`, `db_database`, `version_migraciones`). Junto a ella: `tarifas_saas`
  (versionadas), `mediciones_uso`, `cargos_renta`, `facturas_plataforma`,
  `configuraciones_pasarela_plataforma`, `alertas_plataforma` y los documentos
  legales. No contiene usuarios, alumnos ni datos operativos.
- **Data plane (por tenant).** Una BD física por estudio (SQLite por tenant en
  dev/test; MySQL por tenant en producción con `TENANT_DB_DRIVER=mysql`). Contiene
  la identidad tenant-local (`users`, email único **por tenant**), los roles y toda
  la operación del negocio.

## Piezas

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
  del tenant; un token de un estudio no existe ni valida en otro. Cada token lleva
  su rol activo (ADR 0055).
- Middleware `ResolverEstudio` (resuelve por slug antes de autenticar, activa la
  conexión, falla 404 seguro) y `AutenticarTenant` (Bearer contra la BD del
  tenant).

## Migraciones de los estudios (despliegue)

Un estudio nuevo nace con su esquema al día (lo migra el aprovisionamiento). Los
estudios **existentes** reciben las migraciones nuevas de `database/migrations/tenant`
con:

```bash
php artisan migrate --force                              # control plane
php artisan agendauno:migrar-estudios --force --isolated  # BD de cada estudio
```

- Recorre todos los estudios (`--estudio=slug` para uno solo); se salta los que se
  están aprovisionando y los que no tienen BD.
- Un estudio que falla no frena a los demás: se reporta (`report()`) y el comando
  termina con código de error para que el pipeline lo note. Es idempotente, así que
  basta con volver a correrlo.
- `estudios.version_migraciones` guarda la última migración aplicada a cada estudio
  (la anota `GestorDeConexionTenant::migrar()`, que usan también el
  aprovisionamiento, el demo y la migración legacy).

## Respaldos por estudio

Cada estudio tiene su propia base, así que se respalda y se restaura por separado:

```bash
php artisan agendauno:respaldar-estudios                  # todos (diario, 03:15)
php artisan agendauno:respaldar-estudios --estudio=slug   # uno solo
php artisan agendauno:restaurar-estudio slug --listar     # sus respaldos
php artisan agendauno:restaurar-estudio slug --force      # vuelve al más reciente
```

- SQLite: `VACUUM INTO` (copia consistente con la base en uso). MySQL:
  `mysqldump --single-transaction` (necesita los binarios `mysqldump`/`mysql` del
  servidor; rutas en `RESPALDOS_MYSQLDUMP` / `RESPALDOS_MYSQL`).
- Se guardan comprimidos en `RESPALDOS_DISCO` (usar un disco S3, fuera del
  servidor) bajo `respaldos/{slug}/` y se conservan `RESPALDOS_DIAS` días (14).
- Restaurar reemplaza TODOS los datos del estudio: hacerlo con el estudio fuera
  de servicio. Un estudio que falla al respaldar no frena a los demás.

## Rutas

- `POST /api/v1/registro` — alta pública de estudio (self-service).
- `GET  /api/v1/registro/slug?slug=` — disponibilidad de slug.
- `GET  /api/v1/directorio` — directorio público (solo publicados/no privados).
- `POST /api/v1/app/{estudio}/login` · `/activar` — auth tenant-local.
- `POST /api/v1/app/{estudio}/auth/google` — entrada con Google del negocio.
- `GET  /api/v1/app/{estudio}/yo` · `PUT /yo/rol-activo` · `POST /logout` — sesión
  tenant-local.
- `/api/v1/plataforma/*` — superadmin (token `PLATFORM_ADMIN_TOKEN`).

Las mismas rutas del negocio responden también por subdominio
(`{slug}.agendauno.mx/api/v1/…`).

## Recorrido probado

`registro → provisioning (BD por tenant) → activación → login tenant-local → yo`,
con pruebas de aislamiento: mismo correo en dos tenants = cuentas distintas;
cambio de contraseña independiente; token de A no autentica en B; provisioning
idempotente; slug único ante concurrencia; sin fuga de conexión entre tenants.
