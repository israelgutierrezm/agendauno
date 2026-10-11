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
| 7 | Hecho: familias y responsables como módulo que enciende el superadministrador (apagado por omisión): el responsable con cuenta paga y reserva por sus participantes sin mezclar créditos, con autorización versionada que se puede retirar; avisos con el nombre del participante (ADR 0123, reemplaza al 0059). La redacción legal requiere revisión profesional |
| 8 | Hecho: seguimiento diario por señales (primera visita, plan por vencer o vencido, días sin asistir, compró y no reservó) sin repetir ni insistir, con mensaje sugerido, WhatsApp y resultado; «no desea contacto» lo saca de los comerciales; visitas a la página sin datos personales y embudo del negocio con cantidad y denominador (ADR 0122). El embudo de la plataforma (C08) queda pendiente |

## 6 Segunda fase: lo que quedaba del plan (11 de octubre de 2026)

Después de los 8 bloques, el dueño pidió continuar con todo lo pendiente. Siete revisiones
del código contra cada punto (M06, M07, M08, M10, M11/M12, C01/C02, C07/B08) dieron este
orden, del riesgo al alcance. Una rama y un PR por bloque, como antes.

| Bloque | Contenido | Estado |
|---|---|---|
| 9 | M06 sucursales y cobertura: reprogramar no lleva un plan a otra sede ni fuera de su vigencia; el motivo nombra las sedes; «Todas las sedes incluidas» / «Solo en …»; tarjetas de sede con zona, dirección, foto y cómo llegar; la sede elegida en el calendario filtra los planes; la app dice dónde será la cita | Hecho |
| 10 | M08 acciones del equipo: cada indicador y pendiente del inicio abre su listado ya filtrado (renovaciones por estado y ventana, cobros en su sección, solicitudes nuevas, seguimientos); solicitudes de acceso y seguimientos abiertos en el inicio; cobrar una reserva pendiente desde la sesión (agenda y recepción); con una sola persona que atiende, sin filtro ni selector de equipo. La sustitución ya existía en la sesión | Hecho |
| 11 | M07 cobros comprensibles: en producción una pasarela solo está «lista» si puede confirmar los pagos (secreto del webhook); llaves de prueba con modo real (o al revés) se rechazan al guardar; el modo de prueba se avisa al negocio y al cliente; el cliente ve si ya inició un pago (OXXO) antes de volver a pagar; la deuda de renovación no se duplica con dos cobros simultáneos; las devoluciones van a la bitácora sin el texto técnico de la pasarela; el motivo al cliente de un cargo no intentado es en sus palabras; un rechazo fuera de orden no deshace un pago aprobado (prueba) | Hecho |
| 12 | C02 y C08: se anota la primera vez que el negocio publica y su primera operación real (primera reserva o pago de un cliente facturable; una persona de prueba no cuenta); los demos se marcan y no cuentan; visitas anónimas a cada landing; embudo de la plataforma en el súper admin por producto (visitas → registros → activaron → publicaron → primera operación → primer pago, con denominador y días medianos); QR para compartir la página en el asistente. El aviso del primer cobro con monto queda pendiente: el texto de WhatsApp es una plantilla fija aprobada por Meta | Hecho |
| 13 | B08 campañas: una difusión se pausa, se reanuda o se cancela sin tocar los avisos de operación; la misma campaña no se repite en 24 horas; al enviar se revisa la baja de promociones (aunque fuera después de encolarla); los correos de campaña traen un enlace firmado para darse de baja (y `List-Unsubscribe`); quien renueva sale solo del seguimiento de su vencimiento | Hecho |
| 14 | M10 Wellhub y TotalPass: una visita es aprobada, rechazada o «sin verificar» (sin conexión, error del proveedor o credenciales rechazadas) y esta nunca cuenta como aprobada; se reintenta con el mismo código o se registra como excepción con motivo y evidencia, con un permiso aparte; queda quién registró y quién autorizó; la visita del día no cuenta dos veces; del token de TotalPass solo la huella; cambios de credenciales en la bitácora sin valores; alerta a la plataforma; el alcance («valida el check-in, no sincroniza reservas, membresías ni pagos») se dice en la web (ADR 0124). Falta la prueba con credenciales reales de cada proveedor | Hecho |
| 15 | C07 migración guiada: cada fila de alumnos, instructores y saldos queda lista, ya estaba (no se repite), en conflicto o con errores; se cargan solo las listas y el resto se corrige y se vuelve a subir; alumnos con celular, nacimiento y género; las invitaciones a instructores salen solo si se confirman; saldos de apertura al corte con evidencia (plan y créditos sin venta ni pago, «Saldo de apertura» en el estado de cuenta, sin duplicar al repetir el archivo); cada carga en la bitácora con su resumen y duración (ADR 0125). Las reservas futuras no se copian: se reservan aquí tras el corte | Hecho |
| 16 | M11 y M12: un enlace a una pantalla privada sin sesión vuelve a ella al entrar; las respuestas con credenciales no se guardan en caché y la API no se indexa; al salir no queda nada de la sesión en la pestaña; la dirección canónica de sucursales y de agendar es la del subdominio del negocio, con su marca (clases → AgendaUno, citas → TurnoUno). **Por decidir con el dueño:** el HTML inicial de cada negocio (hoy genérico y `noindex` para buscadores y vistas previas al compartir), con su sitemap y robots por subdominio, porque requiere cambiar nginx | En parte |
| 17 | C01 landings: preguntas de objeción en cada landing (traer a tus alumnos o clientes desde otro sistema, cuánto tarda dejarlo listo y, en clases, el cobro en línea con tu propia pasarela), con respuestas que coinciden con el producto; la página de ejemplo y las imágenes del inicio dicen que son de ejemplo; el registro dice los días de prueba y que no pide tarjeta, del mismo dato que la landing. La pregunta de soporte y el pie de las landings dan soporte@agendauno.mx / soporte@turnouno.mx y el WhatsApp provisional 555555555, configurables al compilar (`VITE_SOPORTE_*`) | Hecho |
| 18 | Familias: sin celular propio, el seguimiento le escribe al responsable con un mensaje para él (la tarea trae a quién y WhatsApp abre su número). Por decidir: el pago automático de un participante con la tarjeta del responsable y WhatsApp a responsables (plantillas de Meta) | En parte |

