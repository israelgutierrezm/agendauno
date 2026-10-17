# ADR 0154 — Servicios y clases: corregir y archivar con su historial

Estado: Aceptado (2026-10-17).

## Contexto

El catálogo permitía crear un servicio o una clase y ajustar sus atributos (precio,
duración, cómo se reserva, espacios, paquete), pero:

- no se podía corregir su nombre ni el cupo base de una clase;
- no había forma de retirarlo. Lo que se dejaba de ofrecer seguía en la página del
  negocio, en agendar, en las cotizaciones y en las importaciones.

Borrarlo no es opción: citas, clases, reservas, ventas y reportes apuntan a él.

## Decisión

- **Corregir.**
  - `PUT /ofertas/{oferta}` acepta `nombre` y, solo en clases, `capacidad`.
  - El cupo base es para lo que se agende desde ahora: cada fecha guarda el suyo
    (`sesiones.capacidad`), así que lo ya agendado no cambia.
  - Una cita es de una persona: su servicio no lleva cupo.
  - El cambio de nombre o de cupo queda en la bitácora con lo que era antes.
- **Archivar, no borrar** (`ofertas.archivado_en`).
  - Uno archivado ya no se agenda (panel, cuenta del cliente, página pública), no
    entra en series nuevas, cotizaciones, importaciones, paquetes ni plataformas, y no
    aparece en línea.
  - Lo ya agendado sigue en pie. La respuesta dice cuántas fechas futuras quedan.
  - El historial sigue apuntando a él.
  - Se vuelve a ofrecer como estaba. Archivado no se edita: primero se restaura.
- **No se archiva lo que dejaría algo a medias.** Se dice qué lo impide para
  resolverlo antes:
  - un paquete vigente que lo incluye;
  - una clase que se repite y sigue generando fechas con él.
- **Dónde se filtra.** Con un scope explícito (`vigentes()`) en los puntos donde se usa
  para algo nuevo, y no con un scope global. Con un scope global, `$sesion->oferta`
  devolvería `null` en el historial.
  - Los puntos de alta resuelven con `OfertaTenant::paraAgendar()`, que responde 422
    con el nombre («ya no se ofrece») en vez de un «no existe».
- **La agenda pide también lo archivado** (`GET /ofertas?archivados=1`). Así los
  colores de los demás no se recorren y las fechas ya agendadas se siguen nombrando.
  Para agendar algo nuevo solo se ofrece lo vigente.

## Consecuencias

- Todo punto nuevo donde se elija un servicio para agendar o vender debe usar
  `vigentes()` o `paraAgendar()`.
- Las consultas de historial y reportes no filtran por `archivado_en`.
- Un plan que ya incluía un servicio archivado lo conserva. Al editar el plan, el
  servicio archivado no se ofrece para agregarlo.
