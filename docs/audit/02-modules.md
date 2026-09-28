# Mapa de módulos

## Inventario funcional

| Módulo | Responsabilidad actual | Entidades principales | Estado | Riesgo/deuda dominante |
|---|---|---|---|---|
| `Tenancy` | Contexto, pertenencias, scope y propagación a jobs | Tenant, tenant_user | Funcional | Tenant suspendido no se bloquea; team de permisos no se limpia al terminar jobs |
| `Autorizacion` | Catálogo de permisos, roles por tenant y acceso por sucursal | roles, permissions, asignaciones | Parcial | `ControlDeAcceso::permiteEnSucursal` no está conectado a endpoints |
| `Organizaciones` | Organización, marca, sucursal y asignación de staff | Organización, Marca, Sucursal, AsignaciónPersonal | Funcional básico | Creaciones compuestas sin transacción; listados sin paginar |
| `Personas` | Persona, perfiles, hogares, tutelas | Persona, Perfil, Hogar, Tutela | Funcional básico | Portal no permite operar por dependiente; creación compuesta puede quedar parcial |
| `Catalogo` | Programas, actividades, niveles y ofertas | Programa, Actividad, Nivel, Oferta | Funcional básico | Sin publicación/archivo/versionado; relaciones tenant solo en aplicación |
| `Recursos` | Instalaciones y recursos físicos | Instalación, Recurso | Funcional básico | No se valida disponibilidad, capacidad ni sucursal al agendar |
| `Agenda` | Plantillas, reglas, sesiones y staff de sesión | PlantillaHorario, ReglaRecurrencia, Sesión, AsignaciónSesión | Parcial | Sin detección de solapes; cancelación incompleta; staff no validado |
| `Membresias` | Productos, acuerdos, derechos, ciclos y restricciones | ProductoComercial, Acuerdo, Derecho | Funcional con riesgos | Concesión directa sin venta/auditoría; renovación de acuerdos cancelados; catálogo sin estado publicable |
| `Creditos` | Ledger, saldos, holds, top-ups, consumo | MovimientoCredito, RetenciónCredito | Funcional aislado | Mutaciones demasiado amplias; saldo N+1; falta referencia idempotente de negocio |
| `Ordenes` | Órdenes y líneas con precio congelado | Orden, LíneaOrden | Funcional básico | No valida moneda homogénea ni limita tamaño; sin idempotencia al crear |
| `Pagos` | Intentos, pasarelas, webhooks, ventanilla y reembolso | Pago, ConfiguraciónPasarela | Alto riesgo | Bypass gratuito, red en transacción, reembolso solo interno, conciliación ausente |
| `Reservas` | Reserva, cupo, hold, cancelación y lista de espera | Reserva | Funcional con riesgos | Hold no se liquida al asistir; FIFO se atasca; idempotencia débil |
| `Asistencia` | Check-in presente/ausente | Asistencia | Parcial | No verifica instructor/sucursal/horario ni liquida crédito |
| `Portal` | Autoservicio de perfil, agenda, compra y reserva | Orquestación de módulos | Parcial | Solo persona propia, pasarelas inseguras, endpoints sin paginar |
| `Health`/infra | Salud de DB/cache/Redis y correlación | — | Funcional | Endpoint revela ambiente/clase de excepción y genera carga activa |

## Superficies de entrada

La API pública está versionada bajo `/api/v1` en `apps/api/routes/api.php`.

- Públicas: health, login por cookie, emisión de token y webhooks.
- Autenticadas y tenant-scoped: organizaciones, personas, catálogo, recursos, membresías, créditos, agenda, reservas, asistencia, órdenes, pagos y portal.
- Scheduler: `agendauno:generar-ciclos` diariamente a las 00:15.
- Colas: hay propagación de tenant preparada, pero no se encontraron jobs de dominio para pagos, avisos o mantenimiento.

## Mapa de casos de uso principales

### Administración

- Configurar organización/sucursal/marca.
- Registrar miembros, perfiles y dependientes.
- Crear catálogo, productos y restricciones.
- Crear plantillas/sesiones y asignar instructores.
- Crear reservas, consultar roster y marcar asistencia.
- Crear órdenes, cobrar y revisar comprobantes.
- Consultar/agregar/retener/consumir créditos.

### Miembro

- Iniciar sesión y consultar perfil/derechos.
- Ver agenda y reservar/cancelar.
- Ver productos, crear orden, seleccionar pasarela y pagar.
- Ver historial de compras y subir comprobante de ventanilla.

### Móvil

- Solo consulta `/health`; no implementa casos de negocio.

## Dependencias críticas por caso de uso

| Caso de uso | Módulos involucrados | Invariantes esperadas | Estado observado |
|---|---|---|---|
| Crear reserva | Agenda, Personas, Membresías, Créditos, Reservas | tenant común, sesión futura, derecho vigente, cupo, hold único | Casi completo; idempotencia/relaciones tenant y lifecycle posterior incompletos |
| Cancelar reserva | Reservas, Créditos, Agenda | idempotente; liberar o consumir según política; promover siguiente | Implementado con regla fija de 6 h; promoción puede atascarse |
| Marcar asistencia | Asistencia, Reservas, Créditos, Agenda | actor autorizado, sesión asignada, liquidación exactamente una vez | Solo guarda asistencia |
| Cancelar sesión | Agenda, Reservas, Créditos, Notificaciones | cancelar reservas, liberar holds, avisar, auditar | Solo cambia `sesiones.estado` |
| Comprar | Portal, Órdenes, Pagos, Membresías | producto publicable, moneda consistente, idempotencia, pago real | Permite manual/simulada desde portal |
| Aprobar pago | Pagos, Órdenes, Membresías, Créditos | evento único, monto/moneda/referencia válidos, fulfillment único | Parcial; falta ledger de eventos y conciliación |
| Reembolsar | Pagos, PSP, Membresías, Créditos, Auditoría | devolución externa confirmada, compensación interna, carrera protegida | Solo compensación interna |
| Renovar ciclo | Membresías, Créditos, Tenancy | acuerdo activo, lote acotado, reintento, aislamiento de fallos | Carga total, N+1 y no verifica acuerdo activo |

## Módulos ausentes o implícitos

Estas capacidades hoy aparecen como comentarios, configuración o necesidades transversales, pero no como módulos operativos:

- `Audit`: actor, acción, objeto, before/after, motivo, IP y correlation ID.
- `Outbox/Inbox`: entrega exactamente-una-vez lógica para efectos y webhooks.
- `Notifications`: email, SMS, push, preferencias, plantillas y reintentos.
- `BillingPlatform`: planes de AgendaUno, límites, trial y facturación del tenant.
- `TenantLifecycle`: alta, suspensión, exportación, cierre y retención.
- `Reporting`: proyecciones y exportaciones, separadas de consultas OLTP.
- `Identity`: invitaciones, recuperación, MFA, dispositivos/tokens y SSO futuro.
- `Reconciliation`: conciliación de intentos, webhooks, devoluciones y estados PSP.

## Acoplamientos a vigilar

- `Pagos\AprobarPago` conoce y crea acuerdos/derechos; debe permanecer como orquestador, no trasladarse al controlador.
- `Portal` repite presenters y reglas de otros módulos. Conviene reutilizar servicios/DTO, manteniendo políticas específicas del actor.
- El acceso a sucursal se decide fuera de políticas Laravel; unificarlo evitará que nuevas rutas omitan el scope.
- El ledger acepta descripciones libres pero no una referencia estructurada única al origen (`reserva`, `orden`, `ciclo`). Esto complica reconciliación.
- Las SPA duplican cliente Axios, tipos de `/me`, auth y correlation ID. Un paquete interno compartido reduciría deriva.

## Evaluación de completitud

**Listos para piloto controlado:** organizaciones, personas, catálogo, recursos básicos, creación de sesiones, lectura de derechos y aislamiento tenant nominal.

**Necesitan corrección antes de uso real:** pagos, reembolsos, ciclo completo de créditos/reservas/asistencia, autorización por sucursal, identidad y operación multi-tenant en clientes.

**No implementados como producto:** aplicación móvil, notificaciones, analítica, facturación SaaS, auditoría operativa, conciliación y administración del ciclo de vida del tenant.
