# Base de datos y glosario

Fuente del **mapa de tablas** y del **glosario de dominio**. El detalle de columnas
vive en las migraciones (`apps/api/database/migrations` y `…/migrations/tenant`),
que son la verdad; aquí va para qué sirve cada tabla y las reglas que no se ven en
el esquema.

## Convenciones

- **Dominio en español**, framework y estándares técnicos en inglés (`users`,
  `personal_access_tokens`, `ulid`). Sin acentos ni `ñ` en código ni en la base.
- Identificadores: PK `BIGINT UNSIGNED` interna + `ulid` público (ADR 0003). La API
  solo expone el `ulid`.
- Dinero: `*_minor BIGINT` + `moneda CHAR(3)`, nunca `float`.
- Créditos: enteros escalados, 1000 unidades = 1 crédito (ADR 0009).
- Fechas y horas en UTC; la zona de la sucursal se guarda como referencia.
- MySQL InnoDB, `utf8mb4`, READ COMMITTED (ADR 0052).
- Bajas lógicas (`deleted_at` + `eliminado_por`) en lo que tiene historia (ADR 0022).
- Sin `tenant_id`: cada negocio tiene su base (ADR 0058, `docs/TENANCY.md`).

## Control plane (una sola base)

| Tabla | Para qué |
|---|---|
| `estudios` | Cada negocio: slug, estado, prueba, perfil y terminología, modalidad de cobro, contacto (con WhatsApp verificado y aceptación de avisos, ADR 0070), suspensión (por renta o por la plataforma, ADR 0073), perfil público (logo, portada, descripción, redes), base de datos y `version_migraciones` |
| `tarifas_saas` | Tarifas del SaaS por modalidad, versionadas (ADR 0019) |
| `mediciones_uso` | Alumnos o profesionales activos medidos por periodo |
| `cargos_renta` | Renta mensual de cada negocio, inmutable una vez emitida (ADR 0032) |
| `facturas_plataforma` | Facturas de la plataforma al negocio |
| `configuraciones_pasarela_plataforma` | Pasarela con la que la plataforma cobra la renta |
| `configuracion_plataforma` | Parámetros de plataforma (valores por defecto de los límites) y conexión de WhatsApp (cifrada) |
| `verificaciones_whatsapp` | Códigos para que el dueño confirme su WhatsApp al registrarse (solo hashes, ADR 0070); se limpia a los 7 días (ADR 0079) |
| `avisos_duenos` | Avisos de la plataforma al dueño por correo y WhatsApp: prueba por terminar, renta lista, vencida y pagada (uno por tipo, referencia y canal, ADR 0071); entrega y lectura del WhatsApp (ADR 0074) |
| `whatsapp_envios` | Cada WhatsApp enviado: el `wamid` de Meta, el negocio y el mensaje o aviso al que corresponde, y su último estado (ADR 0074); se limpia a los 30 días (ADR 0079) |
| `alertas_plataforma` | Alertas de operación agrupadas (ADR 0051) |
| `documentos_legales`, `aceptaciones_legales` | Aviso de privacidad y términos versionados, y quién los aceptó |
| `users`, `cache`, `jobs`, `failed_jobs`… | Tablas de Laravel |

## Base de cada negocio

**Identidad y acceso**
- `users` — cuentas del negocio (correo único en el negocio), `rol`/`roles`,
  `ultimo_rol`, Google, apariencia, calendario.
- `personal_access_tokens` — tokens propios (solo el hash) con `rol_activo`
  (ADR 0055).
- `roles` — roles propios del negocio (ADR 0057). Los de sistema están en código.
- `llaves_api` — llaves de integración con alcances.
- `verificaciones_registro` — confirmación del registro por correo (ADR 0028).
- `dispositivos_push` — tokens FCM (ADR 0025).

**Personas y expediente**
- `personas` — la persona (alumno, cliente, profesional), separada de su cuenta; guarda
  cómo conoció al negocio (ADR 0067) y cuándo aceptó los avisos por WhatsApp
  (`whatsapp_aceptado_en`, ADR 0069).
- `tipos_documento`, `documentos` — expediente del alumno.
- `formularios`, `campos_formulario`, `respuestas_formulario` — formularios dinámicos.
- `waivers`, `aceptaciones_waiver` — responsivas.
- `solicitudes_privacidad` — derechos ARCO.

**Estructura**
- `organizaciones`, `sucursales` (con ubicación, zona horaria y perfil público: dirección, teléfono, WhatsApp, redes y horario, ADR 0061; foto y enlace de Google Maps, ADR 0064).
- `asignaciones_personal` — rol de una persona del equipo en una sucursal.
- `horarios_atencion` — horario de cada sucursal o profesional.

**Catálogo y recursos**
- `programas`, `actividades`, `niveles`, `ofertas` (clases y servicios; modalidad
  grupal o privada, duración, márgenes, política de reserva, descripción pública y
  foto, ADR 0066).
- `recursos`, `oferta_recursos` — salas, camillas, carriles (ADR 0039).
- `oferta_incluidos` — servicios que incluye un paquete, en orden (ADR 0063).

**Agenda**
- `plantillas_horario`, `excepciones_horario` — clases recurrentes y sus cambios.
- `sesiones` — cada clase o cita con fecha, en UTC; se materializan desde las
  plantillas (ADR 0010).
- `asignaciones_sesion` — profesionales de una sesión.
- `bloqueos_agenda` — días u horas sin servicio (ADR 0037).
- `grupos`, `inscripciones_grupo` — grupos fijos.
- `reglas_capacidad_canal` — lugares por canal.

**Membresías y créditos**
- `productos_comerciales`, `producto_ofertas` — lo que se vende (membresía, paquete,
  pase, extra) y a qué clases aplica.
- `acuerdos` — lo que una persona compró; `pausas_acuerdo`.
- `derechos`, `derecho_ofertas` — lo que el acuerdo le da (ciclo, rollover,
  restricciones; ADR 0017).
- `movimientos_credito` — ledger: el saldo se suma, nunca se guarda.
- `retenciones_credito` — holds. Disponible = saldo − retenciones activas.

**Reservas y asistencia**
- `reservas` — con lista de espera, reprogramación e idempotencia (ADR 0011, 0038) y la
  nota del cliente para el negocio (ADR 0067) y quién asiste si es para otra persona
  (ADR 0068).
- `asistencias`, `checkins`, `accesos` — pase de lista, llegada y QR de acceso.
- `politicas_cancelacion` — reglas de cancelación (ADR 0034).
- `resenas` — reseñas de una clase o cita.

**Órdenes y pagos**
- `ordenes`, `lineas_orden` — comprador y beneficiario pueden ser distintos.
- `pagos` — intentos de cobro, idempotentes; conciliación (ADR 0053).
- `reembolsos` (ADR 0029), `incidencias_cobro` (ADR 0030).
- `configuraciones_pasarela` — llaves cifradas por pasarela (ADR 0014, 0021).
- `clientes_pasarela`, `domiciliaciones`, `procesos_dunning` — cobro automático
  (ADR 0020); `sesiones_tarjeta` — sesiones de Stripe para autorizar la tarjeta, por
  conciliar si su aviso no llega (ADR 0076); se limpia a los 30 días (ADR 0079).
- `datos_fiscales`, `facturas` — facturación del negocio a sus clientes.
- `promociones` — descuentos.

**Punto de venta y lealtad**
- `articulos`, `movimientos_inventario`, `ventas_pos`, `lineas_venta_pos`.
- `programa_lealtad`, `recompensas_lealtad`, `movimientos_puntos`, `canjes_lealtad`.

**Equipo**
- `esquemas_pago` — pago a profesionales (nómina y comisiones).
- `tareas`, `reglas_automatizacion`.

**Comunicaciones e integraciones**
- `plantillas_mensaje`, `mensajes`, `difusiones` — correos, recordatorios y avisos
  (interno, correo, push y WhatsApp; `mensajes.parametros` guarda la plantilla de Meta
  y sus valores, ADR 0069; `entregado_en` y `leido_en` los avisa Meta, ADR 0074).
- `eventos_outbox` — outbox transaccional (ADR 0004).
- `webhooks_salientes`, `entregas_webhook`, `integraciones`.

**Configuración y bitácora**
- `parametros_negocio` — límites configurables del negocio (ADR 0042, 0047).
- `auditorias` — bitácora: quién hizo qué y quién movió el dinero (ADR 0023).

## Glosario

| Concepto | Término | Modelo | Tabla |
|---|---|---|---|
| Tenant | Estudio / negocio | `Estudio` | `estudios` (control plane) |
| User | Usuario | `Usuario` | `users` |
| Role | Rol | `RolTenant` (propios) | `roles` |
| Person | Persona | `PersonaTenant` | `personas` |
| Branch | Sucursal | `SucursalTenant` | `sucursales` |
| Offering (class or service) | Oferta | `OfertaTenant` | `ofertas` |
| Session (class or appointment) | Sesión | `SesionTenant` | `sesiones` |
| Commercial product | Producto comercial | `ProductoTenant` | `productos_comerciales` |
| Agreement (purchase) | Acuerdo | `AcuerdoTenant` | `acuerdos` |
| Entitlement | Derecho | `DerechoTenant` | `derechos` |
| Credit ledger | Movimiento de crédito | `MovimientoCreditoTenant` | `movimientos_credito` |
| Hold | Retención | `RetencionCreditoTenant` | `retenciones_credito` |
| Booking | Reserva | `ReservaTenant` | `reservas` |
| Attendance | Asistencia | `AsistenciaTenant` | `asistencias` |
| Order / line | Orden / línea | `OrdenTenant` | `ordenes`, `lineas_orden` |
| Payment | Pago | `PagoTenant` | `pagos` |
| Audit log | Bitácora | `AuditoriaTenant` | `auditorias` |

«Membresía» es un producto que se vende; no confundir con el vínculo de una cuenta
con el negocio.
