# ADR 0198 — Reserva con Google (retirada)

Estado: Retirada (2026-10-22).

La integración «redirect» con el Actions Center de Google se construyó el 2026-10-20 y
se retiró por decisión del dueño antes de usarse: hoy casi no se ocupa y añadía
complejidad a la programación, al entendimiento del producto y a la capacitación.

Se quitó por completo: el código, las rutas, el comando y su programación, la columna
`estudios.en_reserva_google_desde` (la quita la migración
`2026_10_22_000411_retirar_reserva_con_google`), la configuración y sus variables de
entorno, la pantalla del negocio, la captura del token de Google en la web, sus pruebas
y su documentación. La parte comercial no la menciona.
