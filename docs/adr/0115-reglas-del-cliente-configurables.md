# ADR 0115 — Reglas del cliente que decide cada negocio

Estado: Aceptado (2026-10-10). Amplía el ADR 0042 (parámetros configurables).

## Contexto

Una auditoría de configurabilidad encontró reglas del cliente fijas en el código que
cada negocio quiere decidir:

- una clase se podía reservar desde que se programaba y hasta que empezaba;
- una cita se podía agendar con dos minutos de anticipación o meses después (el límite
  de 60 días vivía solo en la app);
- cualquiera agendaba sin cuenta en la página de un negocio de citas;
- toda reseña se publicaba sola;
- el cliente siempre podía cancelar tarde desde su cuenta, solo con el cobro.

Además, un servicio sin duración propia se agendaba con 60 minutos desde la web y la
app, aunque el negocio hubiera fijado otra duración por omisión.

## Decisión

Parámetros del negocio (con valor de plataforma y respaldo en el código, ADR 0042):

| Parámetro | Por omisión | Qué decide |
|---|---|---|
| `reservas.dias_apertura` | 0 (en cuanto se programa) | Desde cuántos días antes reserva el cliente una clase |
| `reservas.minutos_cierre` | 0 (hasta que empieza) | Cuántos minutos antes cierran las reservas de la clase |
| `citas.minutos_anticipacion_minima` | 0 | Anticipación mínima para agendar una cita en línea |
| `citas.dias_maximos_adelante` | 60 | Hasta cuántos días adelante se agenda en línea |
| `citas.agendar_sin_cuenta` | sí | Si la página del negocio recibe citas de quien no tiene cuenta |
| `resenas.publicar_sin_revisar` | sí | Si una reseña nueva se publica sola o espera la aprobación |
| `cancelacion.cliente_cancela_tarde` | sí | Si, pasado el límite sin costo, el cliente aún cancela él mismo |

Los valores por omisión conservan el comportamiento anterior.

- **Solo el cliente.** El negocio reserva, agenda y cancela sin estos límites desde el
  panel. `VentanaDeReservaTenant` concentra las ventanas. `CalcularDisponibilidadTenant::paraCliente()`
  filtra los horarios y los días que ve el cliente (página pública, cuenta y
  reprogramar), y `AgendarCitaTenant` las exige al agendar si no es el negocio.
- **Errores claros:**
  - fuera de la ventana, `BOOKING_NOT_OPEN` (422), con la fecha en que abre o el límite;
  - sin cuenta, `ACCOUNT_REQUIRED` (403);
  - cancelar tarde sin permiso, `CANCELLATION_CLOSED` (422). La vista previa ya lo
    marca como no cancelable.
- **`/citas/opciones` trae `reglas`** (anticipación, horizonte y si se agenda sin
  cuenta) para que la web y la app armen su calendario y su formulario. Cada servicio
  trae su duración efectiva: la propia o `citas.duracion_defecto`.

## Consecuencias

- Las reglas se cambian en Configuración → Límites y tiempos, sin desplegar.
- La app móvil sigue con su calendario de 62 días; el API filtra lo que queda fuera del
  horizonte (los días aparecen sin horarios).

## Pendiente (de la misma auditoría)

- Costo en créditos por clase (Reformer = 2 créditos): requiere diseño.
- Intervalo entre horarios de citas por negocio.
- Topes de reservas activas por persona.
- Ciclos semanales y cobros trimestrales o anuales en membresías.
- Funciones del giro (grupos, pase de entrada) elegibles por el negocio.
- Textos de los correos por modalidad, con la marca del negocio.
