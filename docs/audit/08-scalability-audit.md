# Auditoría de escalabilidad

## Diagnóstico

AgendaUno puede soportar pilotos pequeños con la arquitectura actual, siempre que se cierren los defectos funcionales. El límite no será inicialmente PHP o Vue, sino las consultas sin límite, el ledger leído por sumas repetidas, los efectos externos sin colas/conciliación y la ausencia de controles operativos por tenant.

No se recomienda migrar a microservicios. El camino de menor riesgo es un **monolito modular horizontalmente escalable**, MySQL bien indexado, Redis, workers y outbox.

## Riesgos al crecer

| ID | Riesgo | Umbral/síntoma probable | Mitigación |
|---|---|---|---|
| SC-01 | Endpoints `get()` sin límite | Decenas de miles de filas por tenant; memory/timeouts | Cursor pagination, filtros y límites |
| SC-02 | Saldo N+1 | Cientos de derechos por perfil/backoffice | Agregados en query/proyección reconciliada |
| SC-03 | Job global de ciclos | Miles de derechos venciendo el mismo día | Chunk/jobs por tenant, índice, rate control |
| SC-04 | Llamadas PSP bajo lock | Latencia externa, pool DB agotado, deadlocks | Orquestación asíncrona e idempotente |
| SC-05 | Shared schema sin límites por tenant | Tenant ruidoso monopoliza CPU/colas/storage | Quotas, rate limit, fairness de workers |
| SC-06 | Sin observabilidad de negocio | Fallos silenciosos de fulfillment/holds/webhooks | Métricas, audit, reconciliadores, alertas |
| SC-07 | Almacenamiento local privado | Varias instancias no comparten comprobantes | S3 compatible, URLs firmadas, AV |
| SC-08 | Reporting sobre OLTP | Dashboards/exportaciones degradan reservas | Proyecciones, réplica o warehouse |
| SC-09 | Contexto tenant residual en workers | Jobs sin tenant tras jobs tenant-scoped | Limpiar contexto/team en `finally` |
| SC-10 | Sin lifecycle/planes/feature flags | No se puede suspender, limitar o migrar tenants | Control plane SaaS |

## Multi-tenancy

### Lo que escala bien

- Una sola migración/esquema y pool de conexiones.
- PK internas compactas y ULID públicos.
- `tenant_id` consistente en entidades.
- Scope automático y propagación a jobs.

### Lo que falta

- Verificación DB de relaciones dentro del tenant.
- `tenant.status` aplicado a requests/jobs.
- rate limits y cuotas por tenant/plan.
- cache/storage/exportaciones con namespace.
- métricas de uso/costo por tenant.
- exportación, anonimización y borrado selectivo.
- estrategia para “noisy neighbor” y tenants grandes.

### Estrategia evolutiva

1. Shared-schema reforzado e índices tenant-aware.
2. Fair queues y límites por tenant.
3. Réplicas/proyecciones para lecturas.
4. Solo para outliers contractuales, capacidad de ubicar un tenant en shard dedicado detrás de un `TenantConnectionResolver`.

No implementar sharding antes de tener datos de volumen y tooling de migración.

## Consistencia y eventos

Los efectos externos (PSP, email, push, AV, exports) requieren entrega durable. Añadir una tabla outbox escrita en la misma transacción del cambio de negocio:

```text
outbox_events
id/ulid, tenant_id, aggregate_type, aggregate_id,
event_type, payload_version, payload_json, occurred_at,
available_at, attempts, processed_at, last_error
```

Un worker publica/procesa con semántica *at least once*. Cada consumidor implementa inbox/idempotencia. No prometer exactly-once físico; garantizar efectos de negocio idempotentes.

Eventos iniciales:

- `PaymentAttemptCreated`, `PaymentApproved`, `RefundRequested/Succeeded`;
- `OrderFulfillmentRequested/Completed`;
- `ReservationConfirmed/Cancelled/Waitlisted/Promoted`;
- `AttendanceRecorded`, `SessionClosed/Cancelled`;
- `EntitlementCycleDue/Advanced`.

## Escalado de reservas

El lock de fila de sesión es simple y correcto. Escala por sesiones distintas; una sesión extremadamente popular serializará sus reservas, que es la invariante natural.

Mejoras:

- transacción corta y sin red;
- índice `(sesion_id,estado)` ya alineado;
- timeout/retry de deadlock idempotente;
- idempotency record antes de ejecutar;
- no cachear cupo como autoridad;
- para picos excepcionales, cola/admission control por sesión, manteniendo respuesta clara.

No sustituir el lock por un contador Redis sin protocolo de reconciliación: introduciría sobreventa ante fallos.

## Escalado de pagos

Separar cuatro responsabilidades:

1. **Intent:** registro local inmutable/idempotente.
2. **Provider command:** llamada con idempotency key, timeout y retry seguro.
3. **Provider event inbox:** firma, replay protection y evento único.
4. **Fulfillment/reconciliation:** transición y derechos exactamente una vez lógicamente.

Workers por proveedor permiten aislar degradaciones. Definir circuit breaker y fallback explícito; nunca caer silenciosamente a una simulación. Un reconciliador consulta intentos `pending` envejecidos y compara con PSP.

## Escalado del ledger

Fases:

- **Ahora:** ledger append-only + `withSum`.
- **Volumen medio:** tabla `credit_balances` por derecho, actualizada en la misma transacción con lock/version; reconciliación diaria.
- **Reporting:** exportar eventos/proyecciones a warehouse; no sumar millones de movimientos por pantalla.

Particionar físicamente solo si las métricas de tabla/retención lo justifican. Un índice por `derecho_id` y PK monotónica son suficientes inicialmente.

## Infraestructura objetivo

### Fase piloto pagado

- 2+ instancias stateless de API detrás de balanceador.
- MySQL administrado con backups/PITR.
- Redis administrado para cache/queue.
- workers separados por criticidad.
- S3 privado para comprobantes.
- CDN para SPA y assets.
- secret manager, logs/metrics/traces y alerting.

### Fase crecimiento

- autoscaling API/workers por latencia/backlog;
- réplica de lectura/proyecciones;
- WAF/rate limiting por actor+tenant;
- warehouse/BI;
- despliegue canary/blue-green y migraciones expand-contract.

## Objetivos de capacidad a validar

Diseñar una prueba con, al menos:

- 1,000 tenants, 1 M personas, 10 M reservas, 50 M movimientos;
- pico de 500 reservas/s distribuidas y 100/s sobre una sesión caliente;
- ráfaga de 1,000 webhooks/min con duplicados/fuera de orden;
- 100 k derechos vencidos en cierre mensual;
- exportación grande concurrente sin afectar p95 de operación.

Las cifras no son pronóstico; son una envolvente inicial para descubrir cuellos.

## Resiliencia

- Timeout y presupuesto de reintentos por dependencia.
- Idempotencia obligatoria antes de retry.
- Backoff exponencial con jitter y DLQ.
- Reconciliadores para pagos, fulfillment, holds y ciclos.
- Readiness que retire instancias degradadas sin exponer detalles.
- Chaos tests: PSP lento/ambiguo, Redis caído, DB deadlock, webhook duplicado, storage indisponible.
- Runbooks con dueño, métricas y acciones.

## Recuperación y despliegue

- Migraciones backward-compatible y expand-contract.
- Backups cifrados, PITR y restauración probada.
- Objetivos iniciales RPO/RTO acordados con negocio.
- No ejecutar seeders demo en despliegues.
- Feature flags por tenant para funciones riesgosas.
- Rollback de aplicación sin rollback destructivo de esquema.
- Reconciliación post-despliegue de órdenes/pagos/holds.

## Indicadores de escalabilidad

- p50/p95/p99 y error rate por ruta/tenant.
- queries/request, rows examined, lock wait/deadlocks.
- backlog/edad/retries/DLQ por cola.
- intentos `pending` por edad y discrepancias PSP.
- holds activos de sesiones pasadas.
- duración y backlog de ciclos.
- bytes/filas por tenant y top noisy tenants.
- tiempo de build/deploy/migration/restore.

## Señales para considerar extracción de servicio

Solo extraer cuando al menos una se cumpla: necesidad de escalar/aislar independientemente, equipo propietario distinto, tecnología obligatoria distinta o dominio con ciclo de despliegue/SLA separado. Notificaciones y reporting serían candidatos. Pagos podría aislarse más adelante, después de estabilizar contratos/outbox; extraerlo ahora aumentaría el riesgo.
