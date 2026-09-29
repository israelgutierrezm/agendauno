# ADR 0064 — Agendar por pasos, con foto y mapa de la sede

Estado: Aceptado (2026-09-28).

## Contexto

La página pública para agendar mostraba todo en una sola columna que crecía conforme
se elegía. En negocios con varias sedes se pedía evitar dos errores comunes:

- elegir la sede equivocada, porque las tarjetas solo decían el nombre;
- llegar a otra sede, porque en ningún momento se veía dónde era la cita.

## Decisión

- **Sede**: cada sucursal puede tener una foto (`foto_ruta`) y un enlace de Google
  Maps (`mapa_url`).
  - La foto se sube con `POST /sucursales/{id}/foto` y se quita con `DELETE`, con
    permiso `sucursales.gestionar`.
    - Solo PNG, JPG o WebP de hasta 4 MB; SVG no, por riesgo de XSS.
    - Carpeta por negocio y nombre al azar.
    - Una por sede: la nueva borra la anterior.
  - El enlace se valida con `EnlaceMapa`:
    - se aceptan `maps.app.goo.gl`, `maps.google.com`, `goo.gl/maps` y
      `google.<país>/maps`, se capture o no con `https://`;
    - se guarda con https;
    - se rechazan otros dominios, dominios parecidos, otros esquemas y enlaces con
      usuario.
  - Para llegar (`SucursalTenant::enlaceMapa()`) se usa el enlace del negocio; si no
    hay, uno armado con la dirección o las coordenadas, como antes.
  - Opciones de cita y escaparate traen `direccion`, `foto_url` y `mapa_url`.
- **Panel (Sedes)**: en el perfil público de la sede se sube la foto (el cargador de
  portada pasa a ser `CargadorImagen`, genérico) y se pega el enlace de Google Maps.
  Una sede nueva se guarda antes de subir su foto.
- **Página pública para agendar, por pasos**:
  1. Sucursal: solo si hay más de una. Tarjetas con foto y dirección; si ninguna
     sede tiene foto, van compactas.
  2. Servicio.
  3. Fecha y hora, y con quién.
  4. Confirmación: el resumen con «Cambiar» en cada parte, dónde es la cita (foto,
     dirección, «Cómo llegar») y los datos del cliente.
- **Mapa de pasos arriba**:
  - los pasos hechos se pueden reabrir; los pendientes no se saltan;
  - en celular solo se nombra el paso actual;
  - elegir sede o servicio avanza solo; de la hora se pasa con «Continuar»;
  - al terminar, la confirmación vuelve a decir dónde es la cita.

## Consecuencias

- El cliente reconoce la sede por su foto y confirma la dirección antes de agendar.
- Un enlace de Google Maps mal pegado (o de otro sitio) no llega a la página
  pública.
- No se incrusta un mapa: «Cómo llegar» abre Google Maps. Un mapa incrustado
  necesitaría una llave de la API de Google Maps.
- Mi cuenta (web) y la app siguen con su formulario de una pantalla.
