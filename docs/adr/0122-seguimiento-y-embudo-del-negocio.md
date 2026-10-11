# ADR 0122 — Seguimiento útil y embudo del negocio

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, M09, C06, C05 y C08
(bloque 8).

## Contexto

El negocio tenía tareas con reglas por evento, pero nada que le dijera a quién buscar ni
por qué:

- quien vino por primera vez;
- quien tiene el plan por vencer o ya vencido;
- quien dejó de venir;
- quien pagó y no ha reservado (pendiente del bloque 4).

Tampoco veía su recorrido de captación: de la visita a su página a la solicitud, la
compra, la primera asistencia y la renovación. Un porcentaje sin su denominador engaña, y
medir no debe guardar datos de quien solo mira la página.

## Decisión

**Seguimiento (`SeguimientoTenant`, `agendauno:generar-seguimientos` a diario 08:00 de
CDMX).** Crea una tarea por cada señal verificable, con su motivo (`tareas.motivo`), un
mensaje sugerido y la persona:

- `primera_asistencia`: asistió por primera vez.
- `plan_por_vencer`: su plan entra en los días de aviso (`membresias.dias_por_vencer`),
  salvo que pague solo (cobro automático activo o pendiente).
- `plan_vencido`: acaba de vencer su plan.
- `sin_asistir`: con plan en vigor, cumple `seguimiento.dias_sin_asistir` días sin venir.
- `compro_sin_reservar`: pagó un plan (no una renovación ni una cuota) hace
  `seguimiento.dias_sin_reservar` días y no ha reservado.

Cada señal se toma al aparecer, con 3 días de margen por si el proceso no corrió; no se
convierte en un atraso que inunde la lista. Todo se cuenta en días del negocio (su zona
horaria). Los textos usan las palabras de su modalidad: clase o cita.

**No se repite ni se insiste.**

- No se crea si ya hay una tarea abierta por el mismo motivo con esa persona.
- Tampoco si se creó una en los últimos `seguimiento.dias_entre_contactos` días.
- Solo miembros no archivados.
- Los motivos comerciales (plan por vencer, vencido y sin asistir) excluyen a quien no
  desea promociones.
- Los de servicio (primera visita, ayudar a reservar lo que pagó) sí le llegan.
- Todo es parametrizable por negocio en el grupo «Seguimiento», empezando por
  `seguimiento.activo`.

**Resultado (`POST /tareas/{tarea}/resultado`).** El equipo registra con qué terminó:

- `contactado`, `renovo`, `no_respondio`, `no_desea_contacto` o `requiere_atencion`;
- una nota opcional, que se agrega al detalle.

La tarea se completa y guarda cuándo se contactó. Se mide qué pasó después sin atribuirle
la causa automáticamente.

«No desea contacto» apaga `recibe_promociones` de la persona, con constancia en la
bitácora (`persona.sin_promociones`). Reabrir la tarea borra el resultado.

En la web, el seguimiento muestra el mensaje para copiarlo o abrir WhatsApp con él, el
enlace a la ficha y el filtro «Ver solo seguimiento». El mensaje se edita antes de
enviarlo: el sistema no escribe por su cuenta.

**Visitas sin datos personales (`VisitasPaginaTenant`).**

- `GET /escaparate` cuenta una visita por visitante y día.
- Al visitante se le reconoce por una huella pasajera: hash de negocio, día, IP y
  navegador. Vive solo en la caché hasta el día siguiente.
- En la base del negocio queda únicamente el número del día (`visitas_pagina`).
- Si contar falla, la página se muestra igual.

**Embudo (`GET /reportes/embudo?desde&hasta`, `EmbudoNegocioTenant`).** Por omisión cubre
los últimos 30 días; el máximo es un año. Pide `facturacion.ver` y está en el plan
`reportes_avanzados`, como las cohortes. Cada paso da la cantidad y su base:

- visitas → solicitudes por formulario;
- solicitudes → personas que ya pagaron un plan después;
- compradores nuevos del periodo → los que ya asistieron;
- renovaciones del periodo → las pagadas;
- tiempo de respuesta: la mediana en horas de la solicitud al primer contacto, y cuántas
  siguen sin atender.

Sin base, la web muestra «—», nunca un 0 % engañoso.

## Consecuencias

- El equipo recibe una lista corta y accionable cada día, en lugar de revisar reportes
  para saber a quién llamar.
- Quien pidió no ser contactado deja de recibir seguimientos comerciales desde el mismo
  lugar donde lo dijo.
- Las visitas empiezan a contarse desde esta versión: periodos anteriores muestran 0
  visitas.
- Un embudo de la plataforma (del sitio de AgendaUno al negocio que paga) es otro
  alcance de C08 y queda pendiente.
- Los píxeles de terceros (Meta, Analytics) siguen fuera: solo con aviso y decisión
  explícita del negocio.
