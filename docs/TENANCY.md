# Multi-tenancy

## Definición

Tenant = negocio (`Estudio`): el cliente del SaaS y la frontera de seguridad y de
cobro. Las sucursales no son tenants; viven dentro del negocio.

```
Plataforma (control plane)
├── Negocio: Pole House        → base tenant_pole_house_<azar>
│   ├── Sucursal Roma
│   └── Sucursal Condesa
└── Negocio: Barbería Norte    → base tenant_barberia_norte_<azar>
    └── Sucursal Centro
```

## Persistencia: una base de datos por negocio

- **Control plane** (conexión por defecto): `estudios`, tarifas y cargos del SaaS,
  facturas de la plataforma, alertas, documentos legales. No guarda usuarios ni
  operación de los negocios.
- **Data plane**: una base física por negocio. MySQL `tenant_*` en producción
  (`TENANT_DB_DRIVER=mysql`); un archivo SQLite en `storage/tenants` en desarrollo y
  pruebas. Contiene todo lo del negocio: usuarios (correo único por negocio), roles,
  personas, catálogo, agenda, membresías, créditos, reservas, órdenes, pagos,
  bitácora y outbox.

Las tablas del negocio no llevan `tenant_id`: la base misma es la frontera. Ver
ADR 0058 (reemplaza a los ADR 0002 y 0007) y `docs/CONTROL_PLANE.md`.

## Cómo se elige la base

1. `ResolverEstudio` (`estudio.resolver`) lee el slug de la ruta
   (`/api/v1/app/{slug}/…`) o del subdominio (`{slug}.agendauno.mx`), busca el
   negocio en el control plane y responde 404 si no existe o no está disponible.
2. `GestorDeConexionTenant` apunta la conexión `tenant` a la base de ese negocio.
   `ejecutarEn($estudio, fn)` corre algo en otro negocio y restaura SIEMPRE la
   conexión anterior (`finally`).
3. `AutenticarTenant` (`estudio.auth`) valida el bearer contra la base del negocio:
   un token de un negocio no existe en otro.

Los modelos del negocio usan la conexión `tenant`; los del control plane, la de
por defecto.

## Controles de aislamiento

- El negocio nunca se toma del cuerpo de la petición: sale de la ruta o del dominio.
- Trabajos en cola, eventos del outbox y comandos llevan el negocio en su carga y
  entran con `ejecutarEn()`.
- Caché, locks, colas, archivos y logs llevan el prefijo del negocio.
- La autorización revisa permiso, rol activo y alcance (sucursal, profesional).
- Bitácora (`auditorias`) de las operaciones sensibles.
- Pruebas de aislamiento: mismo correo en dos negocios = dos cuentas; un token de A
  no entra a B; sin fuga de conexión entre negocios.

## Esquema y migraciones

- Control plane: `database/migrations` (`php artisan migrate`).
- Negocios: `database/migrations/tenant`. Un negocio nuevo nace migrado al
  aprovisionarse; los existentes se actualizan con
  `php artisan agendauno:migrar-estudios --force --isolated`.
- `estudios.version_migraciones` guarda la última migración de cada negocio.
- MySQL corre en READ COMMITTED (ADR 0052). `agendauno:verificar-concurrencia`
  prueba con procesos concurrentes reales: último lugar, mismo profesional y
  horario, cancelaciones, reprogramaciones y migraciones de varios negocios.

## Respaldos

Cada base se respalda y se restaura por separado (`agendauno:respaldar-estudios`,
`agendauno:restaurar-estudio`, simulacro con `agendauno:simulacro-restauracion`).
Ver `docs/DESPLIEGUE.md`.
