# ADR 0063 — Servicios que incluyen otros (paquetes de servicios)

Estado: Aceptado (2026-09-28).

## Contexto

Clínicas, spas y barberías venden paquetes. «Limpieza dental completa» incluye
limpieza, aplicación de flúor y diagnóstico de caries, con un solo precio y en una
sola cita. Hasta ahora eso solo cabía en la descripción, como texto: no se veía qué
servicios del catálogo incluía ni cuánto costarían por separado.

No es un carrito de varios servicios: no son varias citas ni una cita que suma
duraciones y precios. Es un servicio más.

«Paquete» ya nombra al paquete de clases (créditos, `TipoProducto::Paquete`). Por eso
en el código esto es «servicios que incluye» (`incluidas`), y en pantalla se habla
de un paquete de servicios.

## Decisión

- **Modelo**: tabla `oferta_incluidos` (`oferta_id`, `incluida_id`, `posicion`) en la
  base de cada negocio. `OfertaTenant::incluidas()` las da en orden.
- **Sigue siendo un servicio**: el paquete tiene su precio, su duración, sus márgenes
  y sus espacios.
  - Se agenda, se cobra, se cancela y se reprograma como cualquier servicio.
  - El motor de citas no cambia.
- **Reglas**, las valida el servidor al guardar (`PUT /ofertas/{oferta}` con
  `incluye: [ulid, …]`, en orden; `[]` lo vuelve un servicio simple):
  - un servicio no se incluye a sí mismo;
  - solo se incluyen servicios del mismo negocio;
  - no hay anidamiento: un paquete no incluye paquetes, y lo que ya está en un
    paquete no puede incluir otros;
  - se valida bajo el candado de los servicios involucrados, en orden de id, para que
    dos cambios a la vez no armen uno dentro de otro;
  - máximo 20 servicios incluidos.
- **Qué ve el cliente**:
  - opciones de cita (página pública, cuenta y app) y escaparate traen `incluye`
    (nombres, en orden) y `precio_por_separado_minor`;
  - `precio_por_separado_minor` es la suma de los precios de lo incluido, o null si
    alguno no tiene precio (no hay con qué comparar);
  - la web muestra «Incluye: …» y, solo si el paquete sale más barato, «Por
    separado: $X» tachado;
  - la app muestra «Incluye: …».
- **Catálogo**: al configurar un servicio se marcan los servicios que incluye. Si ya
  está dentro de un paquete, se dice en cuál y no se ofrece la opción.

## Consecuencias

- El negocio arma paquetes con lo que ya tiene en su catálogo; los nombres siguen al
  catálogo si se renombran.
- El cliente ve qué incluye y cuánto ahorra antes de agendar.
- Los reportes cuentan el paquete como un servicio más; no reparten su ingreso entre
  lo incluido.
