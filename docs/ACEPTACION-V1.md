# Aceptación de la V1

Lista de lo que debe cumplirse antes de abrir AgendaUno y TurnoUno a clientes que
pagan. Cada punto dice cómo se demuestra y quién lo hace. Casi todo es manual y lo
hace el dueño: pilotos, pasarelas y avisos reales, el servidor, respaldos, legales y
apps firmadas. Lo automático corre en el CI.

Documentos de apoyo:

- `docs/VERIFICACION-V1.md`: pagos y avisos de punta a punta, caso por caso.
- `docs/DESPLIEGUE.md`: servidor, despliegue, respaldos y restauración.
- `docs/MOBILE.md`: apps, firma y lo pendiente para publicar.
- `docs/PILOTS.md`: qué probar en cada tipo de negocio.

Estado al 2026-10-11: el punto 1 lo cubre el CI en cada rama. Los puntos 2 a 10 están
**pendientes** (los hace el dueño).

| # | Criterio | Cómo se demuestra | Quién |
|---|---|---|---|
| 1 | Pruebas completas en verde, con concurrencia en MySQL | CI de `main` en verde | CI |
| 2 | Dos pilotos reales operando | Un negocio de clases y uno de citas, dos semanas sin incidencias abiertas | Dueño |
| 3 | Cada rol en escritorio, móvil y temas | Recorridos de abajo, en claro y oscuro | Dueño |
| 4 | Pasarelas | `VERIFICACION-V1.md`, casos 1–8 y 12, con llaves de prueba y una compra real pequeña | Dueño |
| 5 | Suscripción del SaaS | `VERIFICACION-V1.md`, caso 10 | Dueño |
| 6 | Avisos reales | `VERIFICACION-V1.md`, caso 9: correo, WhatsApp y push | Dueño |
| 7 | Respaldos y restauración | Respaldo fuera del servidor y un simulacro de restauración | Dueño |
| 8 | Servidor de producción | Primera instalación, verificación limpia y un despliegue que vuelve solo | Dueño |
| 9 | Información legal | Aviso de privacidad y términos publicados, con los datos del responsable | Dueño |
| 10 | Apps móviles firmadas | AAB firmado de las dos apps; iOS compilado y probado en una Mac | Dueño |

## 1. Pruebas completas

- [ ] El CI de `main` (`.github/workflows/ci.yml`) está en verde:
  - API con MySQL (READ COMMITTED, ADR 0052) y `agendauno:verificar-concurrencia`,
    que prueba los bloqueos con procesos concurrentes reales;
  - web: tipos, lint, pruebas y los builds de la app y de las dos landings;
  - móvil: análisis, pruebas de las dos apps y los APK de ambos sabores.
- [ ] `agendauno:verificar-concurrencia` también en el servidor de producción, antes
  de abrir (ver `DESPLIEGUE.md`).

## 2. Dos pilotos

- [ ] **Clases (AgendaUno).** Un estudio real con su agenda, planes, cobros y
  asistencia durante dos semanas.
- [ ] **Citas (TurnoUno).** Un negocio de citas real con su equipo, servicios,
  agenda pública y cobros durante dos semanas.
- [ ] En ambos: ninguna incidencia de cobro sin resolver y ningún error abierto en el
  monitoreo (superadmin, pestaña «Errores»).

## 3. Cada rol, en cada pantalla

Con los usuarios de prueba de cada demo (ver `docs/DEMOS.md`), en escritorio y en un
teléfono, en tema claro y oscuro:

- [ ] **Propietario y administrador:** registro y activación, configuración inicial,
  planes, agenda, ventas, cobranza, reportes, equipo y roles.
- [ ] **Recepción:** caja, cobrar, reservar o agendar por alguien, pase de lista.
- [ ] **Instructor o profesional:** su agenda, pase de lista, lista de espera.
- [ ] **Alumno o cliente:** entrar, reservar o agendar, pagar, pago automático, su
  historial y documentos; también desde la app.
- [ ] **Superadmin:** negocios, cobro del SaaS, errores, alertas y documentos legales.
- [ ] En TurnoUno nada habla de membresías ni de clases; en AgendaUno, nada de citas.

## 4. Pasarelas

- [ ] Stripe, Mercado Pago y OpenPay: `VERIFICACION-V1.md`, casos 1 a 8.
- [ ] Pago automático seguro: caso 12 (cancelación confirmada, precio contratado e
  importes distintos).
- [ ] Una compra real pequeña con llaves de producción en cada pasarela que se vaya a
  usar, y su reembolso.

## 5. Suscripción del SaaS

- [ ] `VERIFICACION-V1.md`, caso 10: alta del negocio, cobro mensual, domiciliación,
  timbres y aviso perdido.

## 6. Avisos reales

- [ ] Correo: `agendauno:probar-correo` y los transaccionales del caso 9.
- [ ] WhatsApp: plantillas aprobadas por Meta (también las `turnouno_*` y las del
  dueño con `{{marca}}`).
- [ ] Push: proyecto de Firebase creado, credenciales en el servidor y un aviso
  recibido en Android y en iPhone (llave APNs).

## 7. Respaldos y restauración

- [ ] `agendauno:respaldar-plataforma` y `agendauno:respaldar-estudios` corren solos y
  dejan sus archivos fuera del servidor (S3 compatible).
- [ ] `agendauno:simulacro-restauracion` sale bien en producción.
- [ ] Una restauración a mano de un negocio de prueba, siguiendo
  `DESPLIEGUE.md` → «Restaurar».

## 8. Servidor de producción

- [ ] Primera instalación según `DESPLIEGUE.md`, detrás del Traefik existente, con
  certificados comodín.
- [ ] `agendauno:verificar-produccion` sin faltantes.
- [ ] Un despliegue normal termina en «Listo».
- [ ] **Un despliegue que falla vuelve solo** (ADR 0135). Prueba en staging: publica
  una versión cuya cola no procese, por ejemplo con Redis detenido para la versión
  nueva. Debe pasar todo esto:
  - `actualizar.sh` dice que su cola no procesó y vuelve sola a la anterior;
  - llega la alerta «despliegue» al correo de la plataforma;
  - queda anotado en `.historial-versiones`;
  - la versión anterior atiende sin intervención.
- [ ] `./volver.sh` a mano funciona.

## 9. Información legal

- [ ] Datos del responsable capturados; aviso de privacidad y términos publicados en
  el superadmin (versionados).
- [ ] Cada negocio publica su aviso de privacidad antes de captar prospectos.
- [ ] Derechos ARCO: una solicitud de prueba de un alumno llega y se atiende.

## 10. Apps móviles

- [ ] Llave de subida creada; `apps-moviles.yml` genera el AAB firmado de AgendaUno
  y de TurnoUno (no se sube a las tiendas sin autorización).
- [ ] iOS: los dos esquemas compilan y se prueban en una Mac, con firma, push e
  íconos definitivos (`MOBILE.md` → «Pendiente para publicar»).

## Casos de la revisión previa a la V1

Verificar a mano, además de sus pruebas automáticas:

- [ ] **Pago automático (ADR 0134):** `VERIFICACION-V1.md`, caso 12.
- [ ] **GA4 y Meta del SaaS y de un negocio (ADR 0143):** `VERIFICACION-V1.md`,
  casos 13 y 14, con credenciales reales. Las pruebas automáticas usan proveedores simulados: la
  validación real con Google y Meta queda pendiente hasta hacerla aquí.
- [ ] **Atribución (ADR 0142):** llega con `?utm_campaign=…` a la página de un negocio
  y deja una solicitud; el prospecto dice esa campaña. Elige un plan, toca «Ya tengo
  cuenta» y, al entrar, su cuenta muestra ese plan.
- [ ] **Despliegue (ADR 0135):** punto 8 de arriba.
- [ ] **Cada producto, un sitio aparte (ADR 0136):**
  - en la entrada de TurnoUno, «Tus negocios recientes» y la búsqueda solo muestran
    negocios de citas, y en AgendaUno solo de clases;
  - la dirección de un negocio del otro producto dice dónde entrar.
- [ ] **Permanencia por porcentaje (ADR 0137):** un plan con «meses restantes» al 50 %.
  La simulación de baja muestra meses × mensualidad × 50 %, y cambiar el plan después
  no cambia la membresía ya vendida.
- [ ] **Clase de prueba (ADR 0137):**
  - no se compra dos veces en la misma orden;
  - junto con un plan, se conceden las dos;
  - pagada dos veces casi a la vez, la segunda no se concede y aparece en «Por
    conciliar» para devolverla.
- [ ] **Prospecto que se convierte (ADR 0137):** al entrar a su cuenta ve la clase o el
  plan que pidió, hasta que lo reserva o lo compra (o pasan 30 días).
