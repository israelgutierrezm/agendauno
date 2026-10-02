# ADR 0090 — Un solo criterio de «listo para operar»

Estado: Aceptado (2026-10-02). Ajusta el ADR 0088.

## Contexto

El panel y el asistente de configuración no decían lo mismo:

- «Pon tu negocio en marcha», en el Inicio, pedía como requisito la política de
  cancelación. El asistente, en cambio, la dejaba para «cuando lo necesites».
- Las tareas del panel tenían otros nombres y otros cortes que los pasos del asistente
  (sucursal, oferta, horarios, política, productos). Además, «Ir» llevaba al primer
  paso pendiente, no al que se eligió.
- «Listo» significaba solo que existían ciertos datos. No decía si la página estaba
  publicada ni si un cliente podía reservar de verdad.
- «Solo con enlace» prometía que la página seguía funcionando, pero el API la cerraba
  igual que si no estuviera publicada.

## Decisión

### Los mismos pasos en los dos lugares

`PuestaEnMarchaTenant` define los pasos según la modalidad del negocio, decide cuándo
está hecho cada uno y calcula el estado:

- Citas: tu negocio → servicios → quién atiende → reglas → publicar.
- Clases: tu negocio → clases → horario → planes → reglas → publicar.

`GET /onboarding` y `GET /onboarding/quickstart` usan ese mismo servicio. En el
panel, cada paso pendiente lleva al asistente justo en ese paso
(`/onboarding?paso=reglas`).

Lo opcional va aparte: cobrar en línea y registrar al primer cliente.

### Las reglas son un paso

Las reglas de cancelación y de inasistencia nacen con valores razonables. Por eso no
basta con que existan: el dueño las acepta tal cual o las ajusta en el paso «Reglas».
Ese paso guarda la política y se anota como revisado.

### Tres estados, porque no siempre coinciden

- **Configurado**: todos los pasos de configuración están hechos (todo menos
  publicar).
- **Publicado**: la página pública y la reserva en línea están abiertas
  (`Estudio::paginaPublica()`: publicada y operativa).
- **Recibe reservas**: está publicado y hay una fecha que un cliente puede reservar en
  los próximos 14 días:
  - en citas, la primera hora libre del servicio más corto, calculada igual que en la
    página de agendar;
  - en clases, la próxima clase con lugar, siempre que haya cómo entrar (un plan a la
    venta o una clase que se paga suelta).

Si no recibe reservas, se dice por qué: sin servicios, sin horario, sin huecos, sin
clases, sin planes o sin publicar. También se ofrece ir al paso que lo resuelve.

**Listo para operar** significa estar configurado, haber revisado la publicación
(vista previa) y recibir reservas.

### Cómo se publica: tres opciones claras

- En el directorio y con tu enlace (`publicado`).
- Solo con tu enlace (`publicado` + `privado`). La página funciona y recibe reservas,
  pero no aparece en búsquedas.
- Cerrada por ahora (sin `publicado`). Nadie puede abrir la página ni reservar en
  línea.

El escaparate, el registro público y las citas públicas ahora piden
`paginaPublica()`; antes pedían `enDirectorio()`. El directorio sigue listando solo
los negocios publicados que no son privados.

## Consecuencias

- El panel y el asistente ya no se contradicen: mismos nombres, mismo «hecho» y el
  mismo «listo».
- Un negocio «solo con enlace» funciona como se promete.
- Calcular «recibe reservas» cuesta unas consultas más en el Inicio del dueño. La
  búsqueda está acotada a 14 días y se detiene en la primera fecha encontrada.
- Las demos ya traen las reglas revisadas y la publicación hecha.
