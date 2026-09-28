# ADR 0056 — El producto se llama AgendaUno

Estado: Aceptado (2026-09-28).

## Contexto

El producto nació como TurnoUno y la marca pública ya es AgendaUno (sitio, logos y
dominio `agendauno.mx`). El nombre viejo seguía en la app móvil («turnouno_mobile»,
`com.turnouno.turnouno_mobile`), en los comandos, en la configuración y en las
cabeceras de los webhooks. Aún no hay producción ni app publicada, así que cambiar
los identificadores ahora no rompe nada.

## Decisión

Todo pasa a AgendaUno:

| Antes | Ahora |
|---|---|
| Comandos `turnouno:*` | `agendauno:*` (programación, scripts del servidor, CI, docs) |
| `config('turnouno.*')`, `config/turnouno.php` | `config('agendauno.*')`, `config/agendauno.php` (las variables de entorno no cambian) |
| Cabeceras `X-TurnoUno-Signature`, `-Event`, `-Delivery` | `X-AgendaUno-*` |
| App: `com.turnouno.turnouno_mobile` / `com.turnouno.turnounoMobile` | `com.agendauno.app` (Android e iOS) |
| App: nombre «turnouno_mobile» | «AgendaUno» |
| Paquete Dart `turnouno_mobile` | `agendauno` |
| Analítica: `turnouno_attribution`, `turnouno_event`, `turnouno:analytics` | `agendauno_*`, `agendauno:analytics` |
| `APP_NAME=TurnoUno`, `APP_TENANT_DOMAIN=turnouno.com` | `AgendaUno`, `agendauno.mx` |

Después (2026-09-28) se completó el cambio:
- el repositorio pasó a `github.com/israelgutierrezm/agendauno` (GitHub redirige la
  URL vieja);
- las bases de datos pasaron a `agendauno` y `agendauno_testing` (y
  `agendauno_verificacion` en CI);
- las auditorías de `docs/audits` pasaron a `agendauno-*.md` y dicen AgendaUno.

Solo queda con el nombre viejo la carpeta local de cada máquina, que no es parte
del repositorio.

## Consecuencias

- Quien tenga comandos anotados con `turnouno:` debe usar `agendauno:`.
- Una integración que ya validara `X-TurnoUno-Signature` debe leer
  `X-AgendaUno-Signature` (no hay ninguna en producción).
- La atribución de campañas guardada en navegadores bajo `turnouno_attribution` se
  pierde una vez (aún no hay tráfico real).
- Renombrar el repositorio en GitHub queda como decisión aparte: cambia la URL de
  `git clone` de `docs/DESPLIEGUE.md` y el remoto de cada copia.
