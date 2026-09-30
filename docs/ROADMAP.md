# Roadmap

## Hecho

- **Base**: monolito modular, una base por negocio, identidad por negocio, RBAC con
  alcance, bitácora, outbox.
- **Núcleo comercial**: catálogo, membresías, derechos, ledger de créditos, órdenes,
  pagos y reembolsos idempotentes, tres pasarelas, pago automático, conciliación
  de cobros y de la tarjeta del pago automático (ADR 0053, 0076).
- **Agenda y reservas**: clases y citas, series, recursos, bloqueos, lista de
  espera, reprogramar, cancelaciones, pase de lista, QR.
- **Fase 1 (operación)** y **fase 2 (agenda cotidiana)** cerradas (ADR 0035–0046).
- **Plataforma**: registro, onboarding, directorio, subdominios, superadmin, cobro
  del SaaS, documentos legales, respaldos, alertas, despliegue con Docker.
- **Perfil público** (ADR 0061): portada, descripción y redes del negocio y de cada
  sede, horario, fotos del equipo, servicios por categoría, horario semanal de clases
  y página de enlaces para Instagram.
- **Cualquier profesional disponible** (ADR 0062): al agendar sin elegir a nadie,
  se asigna a quien está libre y con menos trabajo ese día (web y app). En la
  página pública se elige primero la hora y luego con quién.
- **Paquetes de servicios** (ADR 0063): un servicio incluye otros del catálogo
  (p. ej. limpieza, flúor y diagnóstico) con un solo precio y una sola cita.
- **Agendar por pasos** (ADR 0064): sucursal, servicio, fecha y hora y
  confirmación; la sede con foto y enlace de Google Maps para no llegar a otra.
- **Cobro al agendar** (ADR 0065): el negocio decide si pide el pago en línea para
  confirmar; sin pasarela, la cita queda confirmada. Correo obligatorio con aviso al
  apartar, calendario solo con días de atención y cliente con cuenta sin reescribir
  datos.
- **Foto por servicio** (ADR 0066): se sube en Catálogo y se ve al agendar y en la
  página del negocio.
- **Datos del cliente al agendar** (ADR 0067): apellidos, lada, cómo nos conoció y
  nota para el negocio.
- **Agendar para otra persona** (ADR 0068): la cita es de quien agenda y guarda
  quién asiste.
- **WhatsApp con Meta Cloud API** (ADR 0069, 0070, 0074): el superadministrador lo
  enciende por separado para los dueños y para los negocios con sus clientes; el
  cliente acepta al agendar o en recepción; el dueño verifica su número con un
  código; el webhook de estados marca entregado, leído o fallido.
- **Avisos de la plataforma al dueño** (ADR 0071, 0072): prueba por terminar y
  renta lista, vencida y pagada, por correo y WhatsApp; el dueño los ve y decide
  en su panel, donde también cambia su WhatsApp con un código (ADR 0075); alerta de
  renta vencida al correo del superadministrador.
- **Analítica**: ingresos, tendencias, cohortes, rentabilidad por clase, demanda por
  horario y agenda del equipo por profesional, con ocupación por agenda en negocios
  de citas y una sola regla de quién cobra cada sesión (ADR 0081).
- **Operación**: monitoreo de errores propio de la API, la web y la app (ADR 0080) y
  limpieza diaria de registros técnicos (ADR 0079).
- **Suspensión automática por renta** (ADR 0073): con días de gracia y aviso previo;
  suspendido, el dueño solo entra a pagar y se reactiva al pagar.
- **Experiencia**: web única (sitio, panel, portal, superadmin) y app Flutter;
  roles propios y rol activo por sesión (ADR 0055, 0057), también de quien imparte
  (ADR 0078), con «eliminar» aparte de «gestionar» (ADR 0077); nombre AgendaUno
  (ADR 0056).

## Antes de abrir

- Pruebas de punta a punta con llaves de prueba de cada pasarela
  (`docs/VERIFICACION-V1.md`).
- Instalar en un servidor real y correr `actualizar.sh` / `volver.sh`.
- WhatsApp (si se enciende): cuenta de WhatsApp Business en Meta, token permanente
  y las plantillas de Configuración → «WhatsApp» aprobadas, de cada uso que se
  encienda (ADR 0069, 0070 y 0071). En la app de Meta, el webhook con la dirección,
  el token de verificación y el App Secret de «Webhook de estados», suscrito a
  `messages` (ADR 0074).
- Proyecto de Firebase para push; llave de subida de Android (la firma ya lee `android/key.properties`, ver `docs/MOBILE.md`) y publicación de la app.

## Después

- WhatsApp: si conviene, número propio por negocio o cobrarlo como extra del plan
  (ADR 0069); atender las respuestas de los clientes (ADR 0074).
- Despliegue automático desde el CI.
- Marketplace a partir del directorio.
- Opciones empresariales: marca blanca, OAuth para terceros.
