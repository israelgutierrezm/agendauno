# ADR 0165 — Riesgos R07–R14: stock, nómina, horas, embudo, retención, conexiones e importación

Estado: Aceptado (2026-10-19). Corrige los riesgos que encontró el dueño leyendo el
código el 2026-10-18 (los fallos R01–R06 están en el ADR 0164). Mejora lo existente;
no agrega módulos.

## R07. Stock de la sucursal de la cita

El cobro de la cita ofrece y limita cada producto con las existencias de la sucursal
de la cita (`existencias` del inventario). Ya no usa el total de todas las sedes:

- muestra «N aquí» junto a cada producto;
- vuelve a leer el stock si el cobro falla.

El traslado entre almacenes queda fuera: no se presenta stock de otra sede como
disponible.

## R08. Cambio de grupo

Está en el ADR 0164.

## R09. Nómina: diferencias con los ajustes

Las diferencias de un corte pagado traen:

- el importe del corte (`en_corte_minor`) y los ajustes ya registrados
  (`ajustes_minor`);
- lo reconocido (`reconocido_minor`) y lo calculado hoy;
- lo que falta (`diferencia_minor`, 0 si ya se atendió) y, aparte, la diferencia
  contra el corte original (`diferencia_original_minor`).

La web ya no ofrece «Ajustar» por lo que ya se compensó.

## R10. Horas en la zona de la sucursal

La agenda (`/sesiones`) y el inicio del día (`/inicio/hoy`) mandan la hora de la
sucursal ya calculada (`inicia_local`, `termina_local`) y su desfase
(`utc_offset_min`).

La app muestra y agrupa por día con esa hora. Si el teléfono está en otra zona, lo
dice («Hora de {sucursal}»). El instante real se sigue usando para ordenar y comparar
con «ahora».

No se agregó un paquete de zonas horarias.

## R11. Embudo de un negocio de citas

En citas, `GET /reportes/embudo` suma `citas`, cada paso con su base:

1. agendadas → confirmadas;
2. cita → asistencia;
3. asistencia → cobrada;
4. clientes atendidos → con una siguiente cita.

Los pasos de planes y renovaciones no se muestran en citas. Las solicitudes con
visita medida y los otros canales siguen como estaban.

## R12. Retención por la fecha de la visita

La última visita es la fecha de la sesión, no la de registro de la asistencia. Ahora
`sin_asistir` y `debe_regresar` usan el mismo criterio.

Hay un segmento nuevo, `sin_iniciar` («Compró y no ha iniciado»):

- plan en vigor desde hace `seguimiento.dias_sin_reservar` días;
- ninguna asistencia y nada reservado.

Se toma con el motivo de seguimiento que ya existía.

En clases, el saldo por vencer se dice en créditos: no todas las clases consumen lo
mismo.

## R13. «Comprobada» con la configuración vigente

Pasarelas e integraciones guardan cuándo cambió lo que las define (`configurada_en`):
sus llaves, o el modo en las pasarelas. No se guarda ninguna llave ni su huella.

Solo la evidencia posterior las comprueba: un pago aprobado, un check-in validado o un
aviso recibido. La anterior sale como `ultimo_exito_anterior_en`, solo historial.
Guardar sin cambiar nada, o solo apagar y encender, no la invalida.

## R14. Importar citas

- **Cliente:** se reconoce por su número en el otro sistema (`cliente_ref`, guardado en
  `personas.referencia_externa`), por su correo o por su celular.
  - Sin ninguno, la fila no se importa: no se deduplica por nombre.
  - Si apuntan a fichas distintas, es un dato ambiguo.
- **Cita:** se compara también la sucursal y el profesional.
  - Una idéntica no se duplica.
  - La misma cita con otros datos se decide en la agenda. Se identifica por su
    `referencia` (`reservas.referencia_externa`) o por la persona y el servicio a esa
    hora.
  - Una referencia repetida en el archivo es un error.
- **Lo que se cobra:** antes de confirmar se dice cuántas citas quedan por cobrar y
  cuánto.
  - Lo ya pagado en el otro sistema (`pagado`: un anticipo o todo) se descuenta de la
    orden y no se registra como ingreso nuevo.
  - Si se pagó todo, no queda nada por cobrar.

## Consecuencias

- Migraciones `000155` (`configurada_en`) y `000156` (referencias externas).
- Lo pendiente del inicio de la agenda en la app (abrir en el hoy de la sucursal antes
  de cargar sesiones) se resuelve con la zona del negocio de la sesión, cuando haga
  falta.
