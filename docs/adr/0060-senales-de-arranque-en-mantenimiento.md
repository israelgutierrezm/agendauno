# ADR 0060 — Publicar en mantenimiento con señales que no reactivan la operación

Estado: Aceptado (2026-09-28). Corrige el ADR 0054 (actualizar con corte y abrir solo si
atiende).

## Contexto

`actualizar.sh` y `volver.sh` ponen mantenimiento, respaldan, migran, levantan la
versión nueva y solo la abren si atiende. «Atiende» incluía que el programador y la cola
de la versión nueva hubieran latido. Pero en mantenimiento:

- el programador salta toda tarea que no esté marcada `evenInMaintenanceMode()`,
  incluido su latido;
- el worker no toma trabajos (`queue:work` sin `--force`): ni siquiera dispara
  `Looping`, así que el trabajo del latido de la cola nunca corre.

Se esperaba una señal que no podía llegar: la publicación se quedaba en mantenimiento
cinco minutos y salía con error, al actualizar y al volver. Correr la cola completa en
mantenimiento (`--force`) no es opción: reactivaría correos, cobros y avisos antes de
abrir.

## Decisión

- **Programador**: solo `agendauno:latido` corre en mantenimiento
  (`evenInMaintenanceMode()`). Marca el latido del programador y, en mantenimiento, no
  encola el de la cola. Una prueba exige que ninguna otra tarea corra en mantenimiento.
- **Cola**: el worker deja una señal de arranque al empezar (`WorkerStarting`, que sí
  ocurre en mantenimiento) después de comprobar que alcanza su conexión de cola.
- **Versión**: cada contenedor recibe `APP_VERSION` (el commit de la imagen) y cada
  señal la lleva. `LatidoOperacion::enMarcha()` solo cuenta las de la versión en marcha:
  - programador: latió hace poco, con esta versión;
  - cola en mantenimiento: su worker arrancó con esta versión;
  - cola abierta: procesó hace poco el latido, con esta versión.

  La usan la disponibilidad, el chequeo `/health?estricto=1`, `agendauno:latido
  --verificar` (chequeos de Docker) y la verificación de producción.
- **Tras abrir**, los scripts confirman que la cola procesa: el latido del minuto pasa
  por ella en hasta 3 minutos. Si no, la versión queda abierta y el script sale con
  código 2 y el camino para volver.
- Los scripts borran las señales antes de levantar la versión nueva, para que no cuente
  una de un intento anterior con la misma versión.

## Consecuencias

- La publicación se abre con el programador nuevo probado y el worker nuevo arrancado.
  El procesamiento real de la cola se confirma un minuto después de abrir, sin
  reactivar nada antes.
- Durante un mantenimiento manual largo, el chequeo de Docker del worker sigue sano si
  arrancó con la versión en marcha.
- Falta probar la actualización y la reversión completas con Docker: en la máquina de
  desarrollo no arranca Docker Desktop. Se hace en el primer servidor real (staging),
  antes de abrir.
