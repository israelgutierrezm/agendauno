# Auditoría integral de AgendaUno — resumen ejecutivo

**Fecha de corte:** 2026-09-15  
**Repositorio auditado:** `github.com/israelgutierrezm/agendauno` (`main`)  
**Alcance:** arquitectura, backend, aplicaciones web, móvil, seguridad, datos, rendimiento, escalabilidad, deuda técnica y producto.  
**Naturaleza del trabajo:** revisión estática y dinámica; no se modificó código de producto.

## Dictamen

AgendaUno tiene una base de ingeniería superior a la de un prototipo típico: monorepo claro, API Laravel organizada como monolito modular, aislamiento multi-tenant mediante contexto y *global scope*, identificadores ULID, permisos por tenant, ledger de créditos, bloqueos transaccionales para cupo, contratos de error estables y CI para las cuatro aplicaciones. Las verificaciones locales fueron satisfactorias: 127 pruebas de API (609 aserciones), 8 pruebas web, 2 pruebas móviles, Pint, PHPStan, ESLint, ambos builds web y Flutter Analyze pasaron. Composer y npm no reportaron avisos de seguridad conocidos en la fecha de corte.

Sin embargo, **el sistema no debe pasar a producción cobrando dinero real ni operando créditos hasta resolver los P0**. Tres defectos cambian directamente dinero o derechos:

1. El portal del miembro expone `manual` y `simulada`; ambas aprueban inmediatamente, por lo que un miembro puede obtener una membresía sin pagar.
2. La asistencia no confirma ni consume la retención de crédito; los créditos quedan retenidos indefinidamente y una asistencia efectiva no se asienta en el ledger.
3. “Reembolsar” solo cambia el estado interno y revierte derechos; no ejecuta una devolución en Stripe, OpenPay o Mercado Pago.

El cuarto P0 es de entrega: los seeders generales crean cuentas conocidas con contraseña `password` sin protección por entorno. Ejecutar `migrate --seed` en producción introduciría credenciales triviales.

## Semáforo por dimensión

| Dimensión | Evaluación | Lectura ejecutiva |
|---|---:|---|
| Arquitectura base | B | El monolito modular es apropiado; los límites existen, aunque los procesos cruzados todavía están acoplados sin eventos/outbox. |
| Calidad automatizada | B | CI y herramientas están bien instaladas; la cobertura funcional es amplia, pero no prueba varias invariantes críticas. |
| Seguridad | D | Buen aislamiento tenant nominal, pero existen bypass de pago, seeders peligrosos, autorización de sucursal no aplicada y autenticación sin límites. |
| Pagos/contabilidad | D | Hay ledger y adaptadores, pero falta una máquina de estados robusta, idempotencia extremo a extremo, reembolso externo y conciliación. |
| Reservas/agenda | C- | La capacidad se serializa correctamente; fallan la liquidación del crédito, cancelación masiva, conflictos de recursos y lista de espera. |
| Base de datos | C | Modelo legible y FKs abundantes; faltan invariantes tenant compuestas, checks, retención histórica y algunos índices selectivos. |
| Rendimiento | C- | Adecuado para piloto pequeño; endpoints sin paginar, N+1 del ledger y jobs `get()` completos no escalan. |
| Frontend/UX | C- | Flujos principales existen; navegación móvil, accesibilidad, confirmaciones y manejo uniforme de errores están incompletos. |
| Móvil | E | Es únicamente una pantalla de salud; no es todavía un producto operativo. |
| SaaS/escalabilidad | C- | Shared-schema bien encaminado; faltan ciclo de vida del tenant, planes/límites, auditoría, observabilidad y operación. |

Escala: A = listo y sólido; B = utilizable con ajustes; C = funcional con riesgos; D = bloqueo serio; E = esencialmente no implementado.

## Riesgos que bloquean un lanzamiento

| ID | Severidad | Riesgo | Evidencia principal |
|---|---|---|---|
| F-01 | P0 | Compra gratuita desde el portal con pasarela manual/simulada | `RegistroDePasarelas.php:23-30,50-59`; `CompraController.php:97-111`; `PasarelaManual.php:20-22`; `PasarelaSimulada.php:22-24` |
| F-02 | P0 | Asistencia no consume crédito y deja holds activos | `CrearReserva.php:96-110`; `MarcarAsistencia.php:19-31` |
| F-03 | P0 | Reembolso interno sin devolución bancaria | `ReembolsarPago.php:31-76` |
| S-02 | P0 | Seeders crean usuarios con contraseña conocida | `DatabaseSeeder.php:19-29`; `PilotosSeeder.php:27-31,54-58` |
| F-04 | P1 | Llamada al proveedor dentro de transacción y sin idempotencia del proveedor | `CobrarOrden.php:42-89`; `CompraController.php:111` |
| S-04 | P1 | El control por sucursal existe pero los endpoints usan solo `Gate` tenant-wide | `ControlDeAcceso.php:30-42`; `SesionController.php:25-27,52-54`; `AsistenciaController.php:20-25` |
| F-06 | P1 | Cancelar sesión no cancela reservas ni libera créditos | `CancelarSesion.php:10-20` |
| F-08 | P1 | No se detectan solapes ni incoherencia recurso/sucursal/capacidad | `CrearSesionUnica.php:24-42`; `SesionController.php:56-71,93-99` |

## Recomendación de salida

**No-Go** para producción financiera en el estado actual. Sí es razonable continuar como entorno de desarrollo o demostración controlada, sin credenciales reales, sin datos personales reales y sin exposición pública.

El criterio mínimo para cambiar a **Go condicionado** es:

- cerrar los cuatro P0 con pruebas de regresión negativas y de concurrencia;
- implementar idempotencia de checkout y proveedor, webhooks registrados/antirreplay y conciliación;
- aplicar autorización por sucursal y limitar instructor a sesiones asignadas;
- añadir rate limiting, expiración/rotación de tokens y eliminar seeders demo del camino productivo;
- cerrar el ciclo reserva → asistencia/no-show → consumo/liberación de crédito;
- paginar endpoints de mayor cardinalidad y corregir los N+1 más visibles;
- disponer de logs estructurados, métricas, alertas, backups y un runbook de incidentes.

## Fortalezas que conviene preservar

- Monolito modular antes que microservicios prematuros.
- `BelongsToTenant` como defensa por defecto y validación de pertenencia para `X-Tenant-ID`.
- ULID en rutas públicas, evitando IDs secuenciales.
- Ledger inmutable para créditos y snapshots de precio en líneas de orden.
- `lockForUpdate` sobre sesión al reservar, protegiendo capacidad concurrente.
- Credenciales de pasarela cifradas y respuestas públicas sin secretos.
- Verificación criptográfica específica de Stripe, OpenPay y Mercado Pago.
- Contrato uniforme de errores y correlación de solicitudes.
- CI con MySQL y Redis reales para la API.

## Contenido de esta entrega

- [Arquitectura](01-architecture.md)
- [Mapa de módulos](02-modules.md)
- [Auditoría funcional y top 20](03-functional-audit.md)
- [Seguridad](04-security-audit.md)
- [Base de datos](05-database-audit.md)
- [Rendimiento](06-performance-audit.md)
- [Frontend, UX y móvil](07-frontend-ux-audit.md)
- [Escalabilidad](08-scalability-audit.md)
- [Deuda técnica](09-technical-debt.md)
- [Nuevas funciones](10-new-features.md)
- [Roadmap](11-roadmap.md)
- [Resumen consolidado y top 10](AUDIT-SUMMARY.md)

## Límites de la auditoría

No se realizó pentest activo contra un despliegue, prueba de carga, análisis DAST, verificación de configuración cloud/DNS/WAF, revisión de estados de cuenta de pasarelas ni restauración real de backups. Los hallazgos de rendimiento son inferidos de consultas y flujos; deben validarse con telemetría y datos de producción representativos. No se reprodujeron ni copiaron valores de `.env`.
