# ADR 0058 — Una base de datos por negocio

Estado: Aceptado. Registra la decisión tomada en el rediseño del 2026-09-15 y
completada al borrar el esquema compartido el 2026-09-23. Reemplaza a los ADR 0002
(base compartida con `tenant_id`) y 0007 (mecánica de aislamiento con
`TenantContext`), y a la parte de Spatie del ADR 0006.

## Contexto

El MVP arrancó con base, esquema y tablas compartidas, con `tenant_id` en cada fila
y un `TenantContext` que filtraba cada consulta. Tres cosas empujaron a cambiar:

- Un filtro olvidado basta para mostrar datos de otro negocio. La defensa dependía de
  cada consulta, de cada job y de cada caché.
- Cada negocio necesita su propia identidad: el mismo correo puede ser alumna en un
  estudio y dueña en otro, con contraseñas y recuperación independientes.
- Respaldar, restaurar, exportar (ARCO) o dar de baja un negocio debe hacerse sin
  tocar a los demás.

## Decisión

- **Control plane**: una base con `estudios` (slug, estado, prueba, perfil,
  modalidad, conexión de su base, `version_migraciones`), tarifas y cargos del SaaS,
  facturas, alertas y documentos legales. No guarda usuarios ni operación.
- **Data plane**: una base por negocio (MySQL `tenant_*` en producción, SQLite en
  desarrollo y pruebas) con todo lo del negocio, incluidos usuarios, tokens y roles.
  Las tablas no llevan `tenant_id`.
- **Resolución**: `ResolverEstudio` toma el slug de la ruta (`/api/v1/app/{slug}`) o
  del subdominio (`{slug}.agendauno.mx`) y `GestorDeConexionTenant` apunta la
  conexión `tenant` a esa base. `ejecutarEn()` corre algo en otro negocio y restaura
  siempre la conexión anterior.
- **Identidad por negocio**: tokens propios guardados como hash en la base del
  negocio; un token de A no existe en B. Sin Sanctum ni login global.
- **Autorización por negocio**: roles y permisos propios del sistema (ADR 0055,
  0057), sin Spatie, que guarda su caché de forma global.
- **Migraciones**: `database/migrations/tenant`, aplicadas al aprovisionar y con
  `agendauno:migrar-estudios` al desplegar; un negocio que falla no frena a los
  demás.
- **Aislamiento de lo demás**: caché, locks, colas, archivos y logs con prefijo del
  negocio; los trabajos llevan el negocio en su carga.
- **Respaldos por negocio** y de la plataforma, con simulacro de restauración.

## Consecuencias

- Un error de consulta ya no puede cruzar negocios: la conexión solo ve una base.
- Crear un negocio crea una base (aprovisionamiento idempotente y reanudable).
- Las migraciones corren N veces; el despliegue las aplica con punto de corte y
  verifica que todas quedaron al día (ADR 0054).
- Reportes de toda la plataforma recorren los negocios (medición de activos para el
  cobro del SaaS) en lugar de una sola consulta.
- Hoy todas las bases viven en el mismo servidor MySQL (`estudios` guarda driver y
  nombre de la base). Mover un negocio grande a otro servidor requiere agregar el
  host a su conexión; el resto del código no cambia.
