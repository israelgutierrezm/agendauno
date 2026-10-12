# ADR 0135 — El despliegue no deja abierta una versión que falla

Estado: Aceptado (2026-10-11). Pedido del dueño, «bloqueos antes de publicar», punto C.
Decisión del dueño: si una comprobación crítica falla tras el cambio, «volver a la
versión anterior».

## Contexto

`actualizar.sh` (ADR 0051, 0112) ya abría la versión nueva solo si atendía, pero:
- confirmaba que la cola procesa **después** de abrir. Si no procesaba, la dejaba
  abierta (correos, avisos y cobros detenidos) y solo sugería `./volver.sh`;
- corría la verificación completa también después de abrir;
- si la versión nueva no quedaba lista en 5 minutos, la dejaba en mantenimiento: el
  servicio caído hasta que alguien entrara al servidor.

En mantenimiento, el worker de siempre no toma trabajos, así que la cola no se podía
probar antes de abrir.

## Decisión

**Comprobaciones críticas, antes de abrir** (la versión nueva sigue en mantenimiento):
1. Que atienda: lo de siempre (`verificar-produccion --disponibilidad`), con las
   señales del programador y del worker de la versión nueva y una petición real por
   nginx.
2. Que su cola procese: `agendauno:probar-cola`.
   - Manda un trabajo de prueba a la cola `despliegue` y lo procesa en el contenedor de
     la API, con un worker de un solo uso (`queue:work --once --force`).
   - Prueba Redis, la serialización y el código nuevo. No toca la cola de siempre.

**Comprobación crítica, después de abrir:** el worker de siempre procesa el latido del
minuto (hasta 3 minutos). Es lo único que no se puede probar en mantenimiento.

**Si una comprobación crítica falla, antes o después de abrir:**
- se avisa al correo de alertas de la plataforma (`agendauno:avisar-despliegue`, que
  registra la alerta y la manda en el acto);
- se vuelve sola a la versión anterior con `./volver.sh`, que ya funcionaba. La base no
  se revierte: las migraciones son compatibles con la versión anterior;
- queda el motivo en `.historial-versiones` y el script sale con error. El flujo
  «Desplegar» de GitHub falla y también avisa;
- en una primera instalación no hay a cuál volver: se queda en mantenimiento, con aviso.

**La verificación completa** corre antes de abrir y solo informa: lo que mira (correo,
respaldos, legales, pasarelas) es de la instalación, no de la versión. Volver por eso
no lo arreglaría y dejaría sin desplegar. Si falta algo, la versión queda abierta, se
avisa y el script sale con código 2, sin «Listo».

## Consecuencias

- Una versión que no atiende o cuya cola no procesa no se queda abierta ni en
  mantenimiento: en minutos vuelve la anterior.
- El equipo se entera por correo, aunque nadie esté mirando la consola.
- Las landings, que `volver.sh` no regresa, se quedan en la versión nueva. No tienen
  estado.
