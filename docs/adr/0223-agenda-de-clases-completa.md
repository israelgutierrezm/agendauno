# ADR 0223 — La agenda de clases completa para la V1

Estado: Aceptado (2026-10-26). Completa las clases recurrentes (fase 2, ADR 0045), los
grupos y el pase de lista (ADR 0101).

## Contexto

La revisión de clases para la V1 encontró huecos en la agenda: una clase recurrente
«sin fecha final» se creaba hasta el 31 de diciembre y no se podía extender; partir una
serie dejaba a sus grupos apuntando a la vieja; cerrar un día no tocaba lo ya programado
y solo se cancelaba fecha por fecha; el cupo y la sala de una fecha no se podían cambiar;
los «libres» de la web no contaban invitados ni plataformas; nadie podía registrar a quien
llegaba tarde; el sustituto asignado no tenía acceso a la clase que cubría; y tres de los
cinco alcances de las llaves de API no tenían ruta.

## Decisión

- **Sin fecha final**: la serie se guarda con `vigente_hasta = null` y se genera hasta el
  horizonte del negocio (`agenda.dias_a_generar`); la tarea diaria la sigue extendiendo.
  `POST /plantillas-horario/{p}/generar` acepta no mandar `hasta`.
- **Hasta cuándo se repite**: `PUT /plantillas-horario/{p}/fin` (`vigente_hasta` o null)
  solo la alarga; para terminarla antes se cancelan sus fechas.
- **«Cancelar esta y las siguientes»**: `POST /plantillas-horario/{p}/terminar`
  (`desde`, `previsualizar`) deja de repetirla desde ese día y cancela sus fechas
  programadas. Cada una se cancela como si se cancelara sola (`cancelarSesion`): devuelve
  créditos y avisa; la que ya tiene asistencia se conserva y se dice.
- **Cerrar un día**: `GET /excepciones-horario/afectadas?fecha=` dice cuántas clases (o
  citas) y personas hay ese día; `POST /excepciones-horario` con `cancelar_sesiones`
  también las cancela.
- **Grupos**: al partir la serie («esta y las siguientes»), sus grupos pasan a la nueva:
  inscribir, dar de baja y las fechas nuevas siguen funcionando.
- **Cupo y sala de una fecha**: `PUT /sesiones/{s}/cupo` (`capacidad`, `recurso_id`); el
  cupo no baja de lo ya ocupado y la sala debe estar libre. La fecha queda como cambiada a
  mano. «Esta y las siguientes» también acepta `capacidad`.
- **Libres del servidor**: `GET /sesiones/{s}/reservas` trae `meta.libres` con la regla
  única de cupo (invitados, plataformas y lo apartado por canal).
- **Llegada tarde**: el equipo registra a alguien mientras dura la clase; el alumno solo
  reserva antes de que empiece.
- **Sustitutos**: quien cubre la clase (sustituto o asistente asignado) la ve y la opera
  como suya; `DELETE /sesiones/{s}/staff/{usuario}` lo quita. La asignación ya no expone
  ids internos.
- **Llaves de API**: `/integracion/reservas`, `/integracion/derechos` y
  `/integracion/ordenes` cubren los alcances que ya se ofrecían.
- En clases, los avisos automáticos ya no ofrecen «profesional de la cita».

## Consecuencias

- Las series creadas antes con fin el 31 de diciembre se alargan desde la ficha de la
  serie (`fin`).
