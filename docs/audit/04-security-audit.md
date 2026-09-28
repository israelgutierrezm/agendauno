# Auditoría de seguridad

## Alcance y modelo de amenaza

Actores considerados:

- visitante no autenticado;
- miembro autenticado y tutor;
- instructor/recepcionista con permisos limitados;
- propietario o cuenta interna comprometida;
- tenant vecino en el esquema compartido;
- atacante con capacidad de repetir o fabricar requests/webhooks;
- fallo o estado ambiguo de un proveedor de pago.

Activos críticos: datos personales, roster/asistencia, credenciales/tokens, configuración de pasarelas, dinero, órdenes, acuerdos, derechos, ledger de créditos y aislamiento entre tenants.

## Hallazgos

### SEC-01 — P0 — Compra gratuita desde autoservicio

**Escenario:** un miembro consulta las pasarelas, selecciona `manual` o `simulada` y llama a pagar su propia orden. El registro devuelve ambas como disponibles, el request las acepta y las dos implementaciones responden aprobado sin validación de staff ni dinero. `AprobarPago` concede los derechos.

**Evidencia:**

- `apps/api/app/Modules/Pagos/Pasarelas/RegistroDePasarelas.php:23-30,50-59`
- `apps/api/app/Modules/Portal/Http/Controllers/CompraController.php:97-111`
- `apps/api/app/Modules/Pagos/Pasarelas/PasarelaManual.php:20-22`
- `apps/api/app/Modules/Pagos/Pasarelas/PasarelaSimulada.php:22-24`
- `apps/api/tests/Feature/Portal/PortalTest.php` acepta explícitamente pago manual del miembro.

**Impacto:** fraude directo, pérdida de ingresos y creación de derechos sin contraprestación.

**Remediación:** separar catálogos por canal. `manual` debe ser un caso administrativo con permiso, caja, actor, referencia y aprobación; `simulada` solo debe registrarse en entorno de test/local mediante *service provider* condicionado. El portal debe usar una allowlist de pasarelas públicas activas, nunca `disponibles()` genérico. Agregar prueba negativa en producción.

### SEC-02 — P0 — Credenciales demo conocidas en el seeder principal

`DatabaseSeeder` siempre crea `owner@agendauno.test` con contraseña `password` y llama a `PilotosSeeder`, que declara la misma contraseña para todas sus cuentas.

**Evidencia:** `DatabaseSeeder.php:17-29`; `PilotosSeeder.php:27-31,54-58`.

**Impacto:** un despliegue que ejecute `php artisan migrate --seed` queda con propietarios predecibles. Al ser owner, el impacto es control total del tenant demo y posible exposición de datos si el entorno fue reutilizado.

**Remediación:** el seeder principal de producción solo debe sembrar permisos/datos canónicos. Mover demos a un comando/seeder explícito que aborte si `app()->environment('production')`; generar contraseñas aleatorias de un solo uso y forzar cambio. Añadir un *deployment check* que falle si existen dominios `.test` o cuentas demo.

### SEC-03 — P1 — Autenticación sin throttling y tokens sin expiración

Las rutas públicas de login y token no tienen `throttle`; `config/sanctum.php:55` establece `expiration => null`. Los tokens se emiten sin una política visible de abilities, expiración o dispositivo.

**Impacto:** fuerza bruta, *credential stuffing* y permanencia indefinida de tokens robados.

**Remediación:** rate limit combinado por IP+identidad, backoff, logging y alertas; expiración configurable, abilities mínimas, rotación/revocación por dispositivo, última actividad y limpieza. Para web conservar cookies HttpOnly/SameSite con CSRF; para móvil usar almacenamiento seguro.

### SEC-04 — P1 — Autorización de sucursal no aplicada

`ControlDeAcceso::permiteEnSucursal()` implementa el modelo correcto (`:30-42`), pero los controladores inspeccionados usan solo `Gate::authorize`, por ejemplo `SesionController.php:25-27,52-54` y `AsistenciaController.php:20-25`.

**Impacto:** los roles asignados solo a sucursal pueden no obtener acceso legítimo, mientras un rol tenant-wide puede consultar/alterar cualquier sucursal. La semántica documentada y la aplicada divergen.

**Remediación:** Policies por recurso con contexto de sucursal, autorizadas mediante `Gate::authorize('view', $sesion)`/`manage`; tests HTTP para cada matriz rol×sucursal×acción.

### SEC-05 — P1 — Instructor con lectura/modificación demasiado amplia

El rol instructor obtiene `miembros.ver`, `reservas.ver`, `asistencia.registrar` y `agenda.ver` a nivel tenant (`CatalogoDePermisos.php:60-65`). El check-in solo exige el permiso y no verifica que el usuario sea instructor de la sesión.

**Impacto:** acceso a roster/datos de miembros y modificación de asistencia en sesiones ajenas.

**Remediación:** reducir permisos globales; policy que exija asignación activa a la sesión/sucursal, salvo rol administrativo. Limitar el presenter del roster al mínimo necesario.

### SEC-06 — P1 — Superficie de webhook y replay insuficientemente endurecida

Las integraciones específicas verifican firma, un control positivo. Persisten estos riesgos:

- el endpoint genérico `/webhooks/pagos/{proveedor}` es público y acepta una confirmación de dominio sin firma específica;
- no hay inbox con `provider_event_id` único;
- no se exige ventana temporal/tolerancia antirreplay donde la firma contiene timestamp;
- en Mercado Pago no se cotejan explícitamente monto, moneda, merchant y metadata con la orden antes de cumplirla;
- los eventos no se conservan para auditoría/conciliación.

**Remediación:** eliminar/deshabilitar el webhook genérico fuera de tests; inbox append-only con hash/payload redacted, event-id único y estado; tolerancia de timestamp; lookup server-to-server; comparación completa con el intento local antes de transición.

### SEC-07 — P1 — Mutaciones de valor con permiso demasiado agregado

`membresias.gestionar` permite crear acuerdos/derechos directamente y el rol recepcionista lo posee (`CatalogoDePermisos.php:53-59`; `AcuerdoController.php:19-37`). Las rutas de top-up, consumo y holds tampoco distinguen permisos financieros finos ni exigen razón/aprobación.

**Impacto:** fraude interno, compensaciones no autorizadas y falta de atribución.

**Remediación:** permisos `benefits.grant`, `credits.adjust`, `credits.consume`, límites por importe/unidades, motivo obligatorio, actor y doble aprobación sobre umbral. Una concesión directa debe ser una orden de cortesía/ajuste auditable.

### SEC-08 — P1 — Estado del tenant no se aplica al acceso

`ResolveTenantContext::isActiveMember()` comprueba solo el pivot activo (`:67-72`), no el estado del tenant. Suspender un tenant no corta sus operaciones.

**Impacto:** incapacidad de contener abuso, impago o incidente de seguridad.

**Remediación:** middleware central de lifecycle con allowlist de operaciones en `suspended/read_only/closing`; invalidar cache/sesiones y detener jobs mutantes.

### SEC-09 — P1 — Idempotencia con alcance y semántica incorrectos

Reservas y pagos buscan solo la clave y devuelven el registro encontrado (`CrearReserva.php:40-45`; `CobrarOrden.php:35-39`). Las migraciones declaran unique global (`create_reservas_table.php:27`; `create_pagos_table.php:26`), no por tenant/actor/operación. La comprobación previa a la transacción deja una carrera.

**Impacto:** una clave reutilizada puede devolver una respuesta de otro request del mismo tenant; colisiones cross-tenant producen denegación/error; requests simultáneos pueden terminar en 500.

**Remediación:** tabla/índice `(tenant_id, operation, idempotency_key)` y `request_hash`; almacenar status+response; conflicto 409 si cambia el payload; insertar-atrapar unique dentro de transacción. La clave PSP debe derivar de este registro.

### SEC-10 — P2 — Integridad tenant depende demasiado de la aplicación

Las tablas hijas contienen `tenant_id` y FKs independientes, pero MySQL no garantiza que el tenant del hijo coincida con el de cada padre. Un bug, import o consola puede crear relaciones cross-tenant que luego se comporten de forma sorprendente bajo scopes.

**Remediación:** composite unique `(tenant_id,id)` en padres y composite FK `(tenant_id,parent_id)` en relaciones sensibles, además de factories/tests de rechazo. Priorizar pagos→orden, líneas→orden/producto/beneficiario, reservas→sesión/persona/derecho y sesiones→sucursal/oferta/recurso.

### SEC-11 — P2 — Carga de comprobantes necesita endurecimiento

Son aciertos el disco privado, la autorización de propietario/staff, MIME limitado y 5 MB. La ruta usa la extensión original del cliente; no hay cuarentena/escaneo, versionado ni auditoría y el namespace usa ID numérico del tenant.

**Remediación:** derivar extensión de MIME validado, renombrar aleatoriamente, escanear, almacenar metadatos/hash, no servir inline contenido activo, usar URL firmada corta y namespace no enumerable. Conservar versiones si un usuario reemplaza un comprobante.

### SEC-12 — P2 — Health e infraestructura revelan más de lo necesario

El health público expone ambiente, versión, estado por dependencia y clase de excepción. Además ejecuta probes activos de cache/Redis/DB en cada request sin rate limit.

**Remediación:** `/live` mínimo sin detalles y `/ready` restringido a red/monitor; no exponer clases ni ambiente. Cachear brevemente y limitar frecuencia.

### SEC-13 — P2 — Faltan cabeceras y política de navegador verificable

No se observó middleware propio para CSP, `frame-ancestors`, HSTS, `nosniff`, Referrer-Policy y Permissions-Policy. Vue escapa interpolaciones por defecto y no se encontró `v-html`, lo cual reduce XSS, pero no sustituye defensa del navegador.

**Remediación:** establecer headers en CDN/reverse proxy y pruebas automatizadas; CSP por aplicación con orígenes PSP explícitos, sin `unsafe-eval` en producción.

### SEC-14 — P2 — Auditoría de seguridad inexistente

Correlation ID ayuda a seguir una solicitud, pero no existe registro inmutable de quién otorgó créditos, aprobó comprobantes, cambió pasarela, reembolsó o marcó asistencia.

**Remediación:** audit log append-only, redacción de secretos, retención, exportación por tenant y alertas para eventos de alto riesgo. No almacenar CVV, tokens completos ni payloads sin filtrado.

## Controles positivos observados

- Aislamiento por `BelongsToTenant` y tenant requerido en rutas de dominio.
- Validación de pertenencia del header antes de instalar contexto.
- ULID como identificador de ruta.
- Form Requests y reglas explícitas; no se observó SQL raw construido con entrada.
- Vue usa interpolación; no se observó `v-html`/inyección HTML directa.
- Cookies Sanctum con XSRF configurado en clientes web.
- Credenciales de gateway con cast cifrado y respuestas públicas filtradas.
- Webhooks específicos con verificación criptográfica.
- Comprobantes en almacenamiento privado con validación de tipo/tamaño.
- Composer/npm audit: 0 avisos conocidos a 2026-09-15.

## Secretos y configuración

Los archivos `.env` locales no están versionados; se revisó el inventario Git sin reproducir valores. Esto es correcto. Aun así faltan controles operativos visibles:

- gestor de secretos por entorno y rotación documentada;
- procedimiento para rotar `APP_KEY` sin perder credenciales cifradas;
- validación de variables obligatorias al arrancar en producción;
- separación inequívoca de llaves sandbox/live;
- protección de dumps, logs, payloads y respaldos;
- secret scanning en CI.

## Matriz de riesgo resumida

| Área | Probabilidad | Impacto | Riesgo residual actual |
|---|---:|---:|---:|
| Fraude de autoservicio | Muy alta | Crítico | Crítico |
| Credenciales demo | Media | Crítico | Crítico |
| Autorización por sucursal/instructor | Alta | Alto | Alto |
| Doble cobro/estado ambiguo | Media | Crítico | Alto |
| Brute force/token robado | Alta | Alto | Alto |
| Cruce tenant por bug/import | Baja-media | Crítico | Alto |
| Upload malicioso | Media | Medio-alto | Medio |
| XSS/SQLi directo | Baja según revisión estática | Alto | Bajo-medio; falta DAST |

## Verificación requerida tras remediar

- Pentest autenticado con usuarios miembro, instructor, sucursal y owner.
- Tests de aislamiento generativos para todas las FK tenant-sensitive.
- Tests de concurrencia e idempotencia en reserva/pago/reembolso.
- DAST contra entorno product-like con CSP/headers.
- Simulación de replays, firmas inválidas, webhooks fuera de orden y montos distintos.
- Secret scan e imagen/dependency scan en CI.
- Ejercicio de revocación de token, suspensión de tenant y respuesta a fraude.

## Limitaciones

Esta revisión no prueba la postura del hosting, firewall, TLS, CDN, dispositivos ni cuentas de PSP. No reemplaza un pentest ni una evaluación de cumplimiento legal/PCI. El sistema evita manejar PAN/CVV directamente en el código observado; debe conservarse la tokenización del proveedor para mantener ese alcance reducido.
