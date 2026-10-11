# Complemento al plan de mejoras: lo que hacen Fitune y Buq con sus estudios de clases

Fecha: 10 de octubre de 2026. Complementa `docs/PLAN-MEJORAS-CAPTACION.md` (v1.2), que es la
guía de ejecución. Aquí van lo observado en los recorridos públicos, lo que eso implica
para AgendaUno y el orden en que se ejecuta. AgendaUno va primero; TurnoUno se actualiza
después, si hace falta.

Revisado en los sitios públicos de Fluō Movimiento (Fitune) y de HYX (Buq), y en la página
comercial del sitio web de Buq. No se probaron sus paneles internos ni se compró nada. Una
función observada no prueba que funcione bien en todos sus clientes.

## 1 Lo observado

### Fitune (Fluō Movimiento)

- **Una web app por estudio, con barra inferior.** Las pestañas son Inicio, Horario, Eventos,
  Precios y Menú (la cuenta del alumno). Se siente como una app instalada.
- **Inicio** con portada, frase del estudio, botones «Elige tu clase» y contacto
  (Instagram, WhatsApp).
- **Nuestras clases**: cada disciplina explica en qué consiste, el nivel previo que pide y
  qué llevar (rodilleras, tacones, tenis). Es la orientación para quien llega por primera vez.
- **Horario por día.**
  - Tira de la semana, con la fecha en la URL (`?date=`) y la zona horaria visible.
  - Cada clase muestra hora, duración, actividad, instructor, lugar y el botón «Reservar».
  - Filtros por lugar, actividad, tipo de lugar (salón) y entrenador.
  - Si el día está vacío: «No hay clases para este día» y «Ver día siguiente».
- **Precios por categoría**: Paquetes, Clases (sueltas y «clase muestra» para la primera vez)
  y recurrentes «/ mes».
  - Paquetes por salón: el mismo número de clases cuesta distinto en un salón que en otro.
  - La «Inscripción» aparece como un producto más.
  - Cada tarjeta tiene precio, una línea de qué incluye y «Seleccionar».
- **Eventos** (talleres) aparte del horario regular.

### Buq (HYX)

- **Sitio de marca con varias páginas**: Inicio, Nosotros, Servicios, Paquetes, Coaches,
  Contacto y Reservar. Usa los textos del estudio (historia, disciplinas, equipo y estaciones
  de la competencia).
- **Reservar** tiene vista de día (agrupada en mañana, tarde y noche) y de semana, y filtros.
  Cada clase muestra hora, duración, actividad, coach, sede y lugares libres sobre el cupo
  («11/15 lugares»).
- **Al tocar una clase sin sesión** abre «Casi listo · Inicia sesión para reservar». Conserva
  a la vista la clase elegida, con día y hora, y ofrece entrar o crear cuenta.
- **Paquetes**:
  - un producto «Destacado» (precio de socio fundador, con cupo de miembros limitado);
  - periodo explícito («/ 30 días», «/ 90 días», «/ 1 día»), inscripción aparte y «Comprar»;
  - al final, «¿Tienes dudas? Ir a contacto».
- **Contacto con formulario propio** (nombre, correo, teléfono y mensaje), además de correo,
  ubicación y redes.
- **Coaches** con su ficha («Conocer a …»).
- **El sitio se vende aparte**, hecho por ellos: unos 900 MXN al mes más instalación, con
  dominio, SEO técnico, botón de reserva y formularios embebibles, Analytics y Meta Pixel.
- **Sus páginas llegan vacías sin JavaScript**: el HTML del servidor no trae el contenido del
  estudio.

## 2 Qué implica para AgendaUno

| Hallazgo | Respuesta en AgendaUno | Punto del plan |
|---|---|---|
| Los dos muestran lugares y horario por fecha real | Calendario público por día y semana con la **misma** regla de cupo que el motor (hoy la página resta solo confirmadas) | B02 (P0), M02 |
| Filtros por sede, actividad, salón e instructor | Los mismos, solo si hay datos (sin un filtro de nivel vacío) | M02 |
| «Casi listo» conserva la clase al pedir acceso | Con registro cerrado: la clase elegida viaja a **Prospectos** y a la invitación; quien ya es alumno entra y reserva esa clase | M02, M04, B01 |
| Formulario de contacto propio (Buq) y WhatsApp (Fitune) | **Prospectos**: CRM del negocio, separado de Miembros, que el negocio activa o desactiva, con formularios que se comparten por enlace o QR o se **embeben** en otro sitio | B01, C04 |
| Descripción, requisitos y qué llevar por disciplina | Descripción visible de cada actividad, con «Es mi primera vez» | C03, M01 |
| Precios por categoría, periodo explícito, clase muestra, inscripción | Planes agrupados (clase suelta, paquete, membresía) con vigencia legible, cobertura por sede y producto destacado; clase de prueba con reglas reales | M03, B03 |
| Inscripción y anualidad como cargos | Cuotas y contratos como conceptos separados, no como un producto que da clases | B04 |
| Paquetes por salón | Cobertura por actividad y sede (ya existe); por salón se resuelve con actividad o recurso, no con otro inventario | M03, M06 |
| Ficha del coach | Sección de equipo con foto y semblanza del instructor | M01 |
| Eventos y talleres | Ya existe el tipo de producto «taller»; mostrarlo aparte del horario regular | M01 (P2) |
| El sitio de Buq se cobra aparte y lo hacen ellos | Nuestro sitio se edita solo, sin costo de instalación; la base no exige configurarlo | M01, sección 0 |
| Sus páginas no traen contenido sin JavaScript | Ventaja posible: página del negocio con HTML útil para buscadores (como ya hacen nuestras landings) | M12 |
| Analytics y Meta Pixel por sitio | Medición propia del embudo del negocio (visitas, solicitudes, conversiones) sin datos personales; píxeles de terceros solo con aviso y decisión explícita | C08 |

## 3 Decisiones del dueño (10 de octubre de 2026)

- **Admisión.** Se conserva el registro cerrado (ADR 0093). Cualquiera puede dejar sus datos y
  llega a **Prospectos**, un apartado propio, separado de Miembros. El negocio activa o
  desactiva esa captación. Un prospecto no reserva hasta que el negocio lo convierte en
  miembro (con la invitación existente).
- **Alcance especializado que entra:** estaciones numeradas (B06), contratos y cuotas (B04) y
  familias y tutores (B05; revierte el ADR 0059). La redacción legal de contratos y del
  tratamiento de datos de menores requiere revisión profesional. **Dominio propio (B07) queda
  fuera** por ahora.
- **Integración:** una rama y un PR por bloque. Pasa a main con el CI en verde.
- **No se quita nada:** solo se mejora o se agrega.

Antecedente: el repositorio tuvo tablas `prospectos` y se eliminaron el 2026-09-16 por decisión
del dueño (migración `2026_09_16_000038_drop_crm_referidos`). Esta decisión las vuelve a pedir,
ahora como captación separada de Miembros y con formularios embebibles.

## 4 Orden de ejecución (AgendaUno primero)

| Bloque | Contenido | Rama |
|---|---|---|
| 1 | B02 cupo único (página pública = motor = agenda del alumno); no vender planes archivados | `mejoras/bloque-1-cupo-y-planes` |
| 2 | B01 y C04: Prospectos (CRM), captación activable, formularios embebibles, conversión a miembro con contexto | `mejoras/bloque-2-prospectos` |
| 3 | M02, M03/B03, C03 y M01: calendario público, planes comprensibles, primera visita, secciones del sitio | `mejoras/bloque-3-sitio-publico` |
| 4 | M05 y C05: consecuencias antes de reservar y cancelar; acompañar compra, reserva y asistencia | `mejoras/bloque-4-reserva-clara` |
| 5 | B06 estaciones numeradas | `mejoras/bloque-5-estaciones` |
| 6 | B04 contratos y cuotas | `mejoras/bloque-6-contratos` |
| 7 | B05 familias y tutores | `mejoras/bloque-7-familias` |
| 8 | C08, M09 y C06: medición del embudo del negocio y seguimiento | `mejoras/bloque-8-medicion` |

Cada bloque cierra con pruebas (las T01 a T18 que le apliquen), la revisión en el navegador
y su documentación. El estado vive en la sección 5.

## 5 Estado

| Bloque | Estado |
|---|---|
| 1 | Hecho: cupo único (ADR 0116) y planes archivados fuera de venta; PR #10 |
| 2 | Hecho: Prospectos, captación activable y formularios embebibles (ADR 0117); PR #11 |
| 3 | Hecho: calendario público por fecha; planes agrupados con destacado, fecha exacta y clase de prueba solo para quien nunca vino; clases para principiantes con qué saber y qué llevar; semblanza del equipo (ADR 0118) |
| 4 | Hecho: antes de reservar (plan, consumo, sede, límite de cancelación, motivo y acción), confirmación con la reserva real, estados con palabras en el calendario, plazo para aceptar un lugar, cancelación que no promete un crédito vencido y «Reserva una clase de tu plan» tras pagar (ADR 0119). El seguimiento de quien compró y no reservó pasa al bloque 8 |
| 5 | Hecho: el alumno ve los lugares libres (sin datos de nadie), elige el suyo al reservar o después y dos personas no se quedan con el mismo; accesible y sin depender del color (ADR 0120) |
| 6 | Hecho: inscripción (alta y reingreso) y anualidad (aniversario o fecha fija) como cuotas que no dan clases; permanencia con condiciones aceptadas y congeladas; baja con simulación, penalización pactada y adeudo aparte; exentar con permiso (ADR 0121). La redacción legal requiere revisión profesional |
| 7 | Pendiente |
| 8 | Hecho: seguimiento diario por señales (primera visita, plan por vencer o vencido, días sin asistir, compró y no reservó) sin repetir ni insistir, con mensaje sugerido, WhatsApp y resultado; «no desea contacto» lo saca de los comerciales; visitas a la página sin datos personales y embudo del negocio con cantidad y denominador (ADR 0122). El embudo de la plataforma (C08) queda pendiente |
