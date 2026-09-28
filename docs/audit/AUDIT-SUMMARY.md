# AUDIT-SUMMARY — AgendaUno

**Corte:** 2026-09-15 · **rama:** `main` · **alcance:** `github.com/israelgutierrezm/agendauno`  
**Veredicto:** **NO-GO para producción financiera**; apto únicamente para desarrollo/demo controlada hasta cerrar P0.

## Una frase

AgendaUno tiene un buen esqueleto de monolito modular y una suite saludable, pero hoy un miembro puede autoconcederse una compra con pago manual/simulado, la asistencia no consume créditos y el reembolso no devuelve dinero al proveedor.

## Qué se revisó

- Arquitectura, rutas, middleware, modelos, migraciones y servicios de aplicación.
- Tenancy, roles/permisos, organizaciones, personas/familia, catálogo, recursos, agenda, membresías, créditos, reservas, asistencia, órdenes, pagos y portal.
- Admin Vue, portal Vue y estado real de Flutter.
- CI, tests, lint, análisis estático, builds y advisories de dependencias.
- Documentación/ADR frente a implementación.

## Resultado automatizado

| Verificación | Resultado |
|---|---|
| API Pest | 127 passed, 609 assertions, 154.97 s |
| API Pint | passed |
| API PHPStan | 213/213 archivos, 0 errores |
| Admin Vitest | 3 archivos, 6 tests, passed |
| Portal Vitest | 1 archivo, 2 tests, passed |
| Admin/portal ESLint | passed |
| Admin/portal production build | passed |
| Flutter Analyze | 0 issues |
| Flutter tests | 2 passed |
| Composer audit | 0 advisories conocidos |
| npm audit admin/portal | 0 vulnerabilities conocidas |

Un pipeline verde no invalida los hallazgos: varias invariantes no están probadas y al menos dos pruebas actuales consideran correcto un comportamiento incorrecto de negocio.

## Top 20 consolidado

| # | ID | Sev. | Hallazgo | Acción esencial |
|---:|---|---:|---|---|
| 1 | F-01 | P0 | `manual`/`simulada` permiten compra gratuita del miembro | Separar canal/entorno y negar en portal |
| 2 | F-02 | P0 | Asistencia no consume hold | State machine y liquidación idempotente |
| 3 | F-03 | P0 | Refund solo interno | Refund PSP + saga/conciliación |
| 4 | SEC-02 | P0 | Seeder principal crea owners con `password` | Separar demo y abortar en prod |
| 5 | F-04 | P1 | Red PSP bajo lock, sin idempotencia extremo a extremo | Transacciones cortas + outbox/idempotency |
| 6 | F-05 | P1 | Intentos múltiples/eventos fuera de orden no reconciliados | Máquina de estados e inbox/conciliador |
| 7 | F-06 | P1 | Cancelar sesión no cancela reservas/holds | Operación agregada transaccional |
| 8 | F-07 | P1 | Scope por sucursal no aplicado | Policies contextuales |
| 9 | F-09 | P1 | Instructor accede fuera de sesiones asignadas | Policy por asignación y roster mínimo |
| 10 | SEC-03 | P1 | Login/token sin throttle; token no expira | Rate limit, expiry, abilities, dispositivos |
| 11 | F-08 | P1 | No hay conflictos/coherencia de recursos/staff | Motor de disponibilidad |
| 12 | F-10 | P1 | Idempotencia global, sin payload y con carrera | Registro scoped + request hash |
| 13 | F-11/12 | P1 | Waitlist se atasca y usa derecho/costo obsoleto | Revalidar y recorrer candidatos |
| 14 | F-13 | P1 | Ciclos cargan todo y renuevan acuerdos cancelados | Chunk/jobs/filtros/índice |
| 15 | F-14/15 | P1 | Ajustes de valor amplios y sin audit trail | Permisos finos + actor/motivo/audit |
| 16 | F-16 | P1 | Endpoints sin paginar | Cursor pagination y límites |
| 17 | F-17 | P1 | N+1 del ledger | `withSum`/proyección reconciliada |
| 18 | DB-01 | P1 | FKs no aseguran tenant común | Composite FKs prioritarias |
| 19 | F-18 | P1 | Orden mezcla monedas y no limita tamaño | Validaciones/límites server-side |
| 20 | F-19/20 | P2 | Familia, selector multi-tenant y móvil incompletos | Completar journeys tras fundaciones |

Detalles, impacto, evidencia y criterios de aceptación están en [auditoría funcional](03-functional-audit.md) y [seguridad](04-security-audit.md).

## Top 10 de acciones

1. **Contener el bypass hoy:** retirar `manual` y `simulada` de todo canal de miembro y del contenedor de producción; revisar operaciones ya creadas.
2. **Cerrar créditos correctamente:** implementar `reserva → hold → asistencia/no-show/cancelación → consumo/liberación` exactamente una vez y reconciliar holds históricos.
3. **Hacer real el reembolso:** no marcarlo completado hasta confirmación del PSP; modelar montos, referencias, fallos y compensación interna.
4. **Blindar despliegue e identidad:** sacar demos del seeder principal, rate-limit login/token, expirar/rotar tokens y añadir MFA para privilegios financieros.
5. **Rehacer la frontera de pagos:** no llamar red dentro de DB transaction; usar idempotencia PSP, outbox/inbox, eventos únicos, validación monto/moneda y conciliador.
6. **Aplicar autorización contextual:** policies tenant+sucursal+sesión; instructor solo en asignaciones propias; pruebas HTTP de la matriz completa.
7. **Completar cancelación/agenda:** cancelar reservas y liberar holds; detectar solapes y validar recurso, capacidad, staff y sucursal.
8. **Reforzar datos:** composite FKs tenant, checks, ledger con referencia única, retención histórica y límites de orden.
9. **Quitar límites de escala inmediatos:** paginar, eliminar N+1 y trocear ciclos por tenant/lote con observabilidad.
10. **Operar como SaaS:** audit log, logs/métricas/trazas, alertas, S3 privado+AV, backups/PITR probados, tenant lifecycle, planes/límites y feature flags.

## Evidencia principal

- Bypass: `apps/api/app/Modules/Pagos/Pasarelas/RegistroDePasarelas.php:23-30,50-59`; `Portal/Http/Controllers/CompraController.php:97-111`; pasarelas manual/simulada.
- Hold sin consumo: `Reservas/Application/CrearReserva.php:96-110`; `Asistencia/Application/MarcarAsistencia.php:19-31`.
- Refund ficticio: `Pagos/Application/ReembolsarPago.php:31-76`.
- Seeder: `apps/api/database/seeders/DatabaseSeeder.php:17-29`; `PilotosSeeder.php:27-31,54-58`.
- PSP/lock: `Pagos/Application/CobrarOrden.php:42-89`.
- Sucursal: `Autorizacion/ControlDeAcceso.php:30-42` frente a `Agenda/Http/Controllers/SesionController.php:25-27,52-54`.
- Cancelación: `Agenda/Application/CancelarSesion.php:10-20`.
- Waitlist: `Reservas/Application/PromoverListaEspera.php:39-67`.
- Ciclos: `app/Console/Commands/GenerarCiclosEntitlement.php:25-41`.
- N+1: `Membresias/Http/Controllers/DerechoController.php:25-36`; `Creditos/LibroMayor.php:25-39`.

## Decisión por horizonte

### Antes de exponer internet/datos reales

- P0 cerrados.
- Login/webhooks/health limitados y endurecidos.
- Seed/config de producción verificados.
- Policies de tenant/sucursal críticas.

### Antes de aceptar dinero real

- Idempotencia extremo a extremo, refund real, inbox/outbox y conciliación.
- Pruebas de timeout, duplicado, concurrencia y eventos fuera de orden.
- Audit trail, alertas y runbook financiero.

### Antes de escalar pilotos

- Paginación, agregados de saldo, job de ciclos por lotes.
- S3 privado/AV, backups/restores y SLO.
- UX responsive/accesible y selector tenant.

### Antes de vender como SaaS maduro

- Lifecycle, planes/límites/metering, billing de tenant, feature flags.
- Portal familiar, notificaciones, políticas configurables y reportes.
- Mobile MVP y booking público solo sobre el core estabilizado.

## Índice de entregables

1. [Resumen ejecutivo](00-executive-summary.md)
2. [Arquitectura](01-architecture.md)
3. [Módulos](02-modules.md)
4. [Auditoría funcional](03-functional-audit.md)
5. [Seguridad](04-security-audit.md)
6. [Base de datos](05-database-audit.md)
7. [Rendimiento](06-performance-audit.md)
8. [Frontend, UX y móvil](07-frontend-ux-audit.md)
9. [Escalabilidad](08-scalability-audit.md)
10. [Deuda técnica](09-technical-debt.md)
11. [Nuevas funciones](10-new-features.md)
12. [Roadmap](11-roadmap.md)

## Alcance y reserva

No se modificó código de producto. No se ejecutó pentest, DAST, carga, revisión de infraestructura cloud ni restore real. Los resultados de advisories son una foto al 2026-09-15. La remediación debe validar estos puntos en un entorno product-like antes del Go.
