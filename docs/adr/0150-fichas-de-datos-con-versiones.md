# ADR 0150 — Fichas de datos editables con versiones

Estado: Aceptado (2026-10-13).

Pedido: el ciclo de edición de las fichas de datos estaba incompleto. Solo se podía crear
una ficha y agregarle preguntas. Hacía falta:

- editar el nombre y las preguntas;
- reordenarlas;
- verla como la llena la persona;
- archivarla;
- conservar las versiones cuando ya hay respuestas.

## Contexto

Una ficha de datos (`formularios`) pide información a miembros o al equipo:
cuestionario de salud, contacto de emergencia, tallas. Cada persona tiene una respuesta
(`respuestas_formulario.valores`, un mapa `id de la pregunta → valor`).

Si se edita una ficha que ya tiene respuestas, hay dos riesgos:

- Quitar una pregunta borraba la definición con que se leen sus valores. Lo respondido
  quedaba sin etiqueta.
- Cambiar el tipo de una pregunta dejaba valores viejos que ya no encajan. Por ejemplo,
  una opción «M» en una pregunta que ahora es una fecha.

La captación (ADR 0117) ya resolvió algo parecido: cada envío guarda la etiqueta de
entonces.

## Decisión

**Se guarda la ficha completa.** `PUT /formularios/{id}` recibe el nombre, la
descripción, a quién aplica y las preguntas en orden:

- las que traen `id` se actualizan;
- las nuevas se crean;
- las que faltan se quitan.

Reordenar es mandar otro orden. Una pregunta de opción necesita al menos dos opciones, y
el error se marca en su campo (`campos.N.opciones`). La ficha se bloquea durante el
guardado, así que dos ediciones a la vez no se pisan la versión.

**Con respuestas, nada respondido se pierde.**

- Una pregunta quitada se archiva (`campos_formulario.archivado_en`) en vez de
  borrarse. Sin respuestas, sí se borra.
- Cambiar el tipo de una pregunta crea otra con otro `id`. La anterior queda archivada
  con sus valores.
- Cualquier cambio a las preguntas sube `formularios.version`: etiqueta, tipo,
  obligatoria, opciones, orden, altas o bajas. Guardar sin cambios no la sube.
- Cada respuesta guarda su `version` y las preguntas con que se contestó
  (`respuestas_formulario.campos`: `id`, etiqueta, tipo y opciones). Así se lee igual
  aunque la ficha cambie después. Las respuestas anteriores a este ADR no las tienen y se
  leen con las preguntas vigentes.

**Archivar en vez de borrar.**

- `POST /formularios/{id}/archivar` deja la ficha fuera de lo que se pide: nadie la ve
  ni la responde, y responderla da 422. Sus respuestas se conservan.
- `POST /formularios/{id}/restaurar` la vuelve a pedir.
- Las archivadas se listan con `?archivados=1`, solo para quien diseña fichas.
- Una ficha archivada no se edita (409) hasta restaurarla.

**Vista previa.** Es solo de la web: muestra las preguntas vigentes como las ve quien
llena la ficha.

## Consecuencias

- La relación `Formulario::campos()` trae solo las preguntas vigentes. Responder, el
  expediente y «Mi cuenta» validan y muestran con ellas.
- En la revisión de respuestas, cada respuesta se muestra con sus propias preguntas y se
  marca «con la versión N» si es de una versión anterior.
- Agregar una pregunta suelta (`POST /formularios/{id}/campos`) también sube la versión
  si la ficha ya tiene respuestas.
