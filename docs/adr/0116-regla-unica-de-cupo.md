# ADR 0116 — Una sola regla de cupo para todos los canales

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, B02 (P0) y parte de B03.

## Contexto

Cada pantalla contaba los lugares libres a su manera:

- La página pública restaba solo las reservas confirmadas.
- El motor de reservas cuenta confirmadas, ofrecidas y pendientes de pago, y además descuenta
  lo que las reglas de capacidad por canal (R20) apartan.
- La agenda del alumno y la del equipo usaban la lista de estados del motor, pero no las
  reglas de canal: una clase se veía con lugar y al reservar respondía `CAPACITY_FULL`.
- Recepción calculaba su ocupación con confirmadas; la puesta en marcha sugería «una clase
  con lugar» igual.

El sitio podía invitar a elegir un lugar que el motor ya consideraba ocupado.

Además, un producto archivado se podía comprar con un enlace viejo: la orden no revisaba
`archivado`.

## Decisión

- `App\Modules\Tenancy\Application\CupoDeSesionTenant` es la regla única:
  - `OCUPAN_LUGAR`: confirmada, ofrecida y pendiente de pago.
  - `disponibles(sesión, canal)`: el cupo menos lo ocupado y lo que otros canales tienen
    apartados y sin usar.
  - `conteos()` y `cargar()`: los mismos números para una lista, en dos o tres consultas.
- La usan:
  - el motor (`ReservasTenant`, bajo el candado de la sesión);
  - el presentador común de sesiones (`clase.libres`, en la agenda del alumno y la del equipo);
  - la página pública (`lugares_libres`);
  - reprogramar desde la cuenta, los bloqueos de agenda, recepción (`ocupados`,
    `ocupacion_pct`) y la puesta en marcha.
- La web usa `clase.libres` del servidor (`libresDeSesion`) en vez de calcular `capacidad −
  ocupados`.
- Lo que un canal ya usó de lo que tenía apartado también cuenta las pendientes de pago,
  igual que el cupo general.
- `OrdenesTenant::crear()` rechaza un producto archivado con `PRODUCT_ARCHIVED` (422), tanto
  en la cuenta como en caja. La renovación de un acuerdo vigente (`DeudaDeRenovacionTenant`)
  sí lo cobra: lo que la persona ya tiene se sigue renovando.

## Consecuencias

- Para la misma sesión en el mismo instante, todos los canales dicen lo mismo (T02). El motor
  sigue bloqueando y revalidando al confirmar: leer el número público no reserva nada.
- Una oferta o un pago vencidos siguen ocupando el lugar hasta que el proceso de cada minuto
  los expira, en todos los canales por igual.
- Los reportes históricos (ocupación del reporte del negocio, demanda) siguen contando
  confirmadas: miden lo que se dio, no lo que queda.
