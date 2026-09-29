# Roadmap

## Hecho

- **Base**: monolito modular, una base por negocio, identidad por negocio, RBAC con
  alcance, bitácora, outbox.
- **Núcleo comercial**: catálogo, membresías, derechos, ledger de créditos, órdenes,
  pagos y reembolsos idempotentes, tres pasarelas, pago automático, conciliación.
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
- **Experiencia**: web única (sitio, panel, portal, superadmin) y app Flutter;
  roles propios y rol activo por sesión (ADR 0055, 0057); nombre AgendaUno
  (ADR 0056).

## Antes de abrir

- Pruebas de punta a punta con llaves de prueba de cada pasarela
  (`docs/VERIFICACION-V1.md`).
- Instalar en un servidor real y correr `actualizar.sh` / `volver.sh`.
- Proyecto de Firebase para push; llave de subida de Android (la firma ya lee `android/key.properties`, ver `docs/MOBILE.md`) y publicación de la app.

## Después

- Conciliar el guardado de tarjeta de Stripe (pago automático) cuando no llega su
  aviso; hoy la conciliación cubre los cobros.
- Reserva pública: selector semanal de horarios y más datos del cliente (apellido,
  lada, cómo se enteró, notas).
- Avisos por WhatsApp (proveedor por decidir) y foto por servicio.
- Separar «editar» y «eliminar» en el catálogo de permisos.
- Roles propios con faceta de instructor.
- Monitoreo de errores más completo y despliegue automático desde el CI.
- Analítica: ocupación, rentabilidad, demanda.
- Marketplace a partir del directorio.
- Opciones empresariales: marca blanca, OAuth para terceros.
