# ADR 0073 — Suspensión automática por renta vencida

Estado: Aceptado (2026-09-29).

## Contexto

Con el ADR 0072, el superadministrador ve las rentas vencidas y suspende a mano.
Hacía falta que eso pasara solo, con días de gracia. Pero suspender cerraba todo el
negocio (todas sus rutas respondían 404). Con eso, un negocio suspendido por no
pagar ni siquiera podía entrar a pagar.

## Decisión

- **Días de gracia:** parámetro de plataforma `renta.dias_gracia_suspension`, 15 por
  omisión (0 = nunca).
  - `agendauno:suspender-por-renta` (diario, 09:30 de CDMX) suspende a los
    negocios con una renta sin pagar que venció hace esos días o más.
  - La suspensión queda marcada (`suspendido_por = renta`, `suspendido_en`), y el
    superadministrador recibe la alerta «Suspensión por renta».
- **Avisos al dueño** (ADR 0071), por correo y, si lo aceptó, por WhatsApp:
  - `suspension_proxima`: unos días antes, con la fecha y el enlace para pagar. Son
    3 por omisión, con el parámetro `renta.dias_aviso_suspension`.
  - `cuenta_suspendida`: al suspenderse, con el enlace para pagar y reactivarlo.
  - Plantillas de Utilidad `agendauno_suspension_proxima` y
    `agendauno_cuenta_suspendida`.
- **Suspendido por renta, solo se abre lo necesario para pagar**
  (`ResolverEstudio`):
  - entrar y salir;
  - la marca;
  - recuperar la contraseña;
  - `yo` y el cambio de rol;
  - la apariencia;
  - la renta: pagarla, su factura y quién cuenta;
  - los avisos de AgendaUno.
  - Lo demás responde 404: página pública, clientes, agenda y resto del panel.
  - Solo puede entrar quien tiene un rol con `facturacion.ver`, y entra con ese
    rol. A los demás se les dice que el negocio está suspendido por ahora.
  - En la web, el menú y la navegación llevan solo a «Renta», con un aviso de qué
    pasa y cómo reactivarlo.
- **Se reactiva solo al pagar:** en cuanto la pasarela confirma el pago
  (`ConfirmarCargoRenta` o el cobro inmediato), si ya no debe rentas fuera de la
  gracia, vuelve a prueba o a activo. El proceso diario también reactiva por si
  acaso.
- **La suspensión del superadministrador es distinta** (`suspendido_por =
  plataforma`): cierra todo y no se quita sola.
  - Si el superadministrador reactiva a mano un negocio suspendido por renta, no
    se vuelve a suspender solo durante otros días de gracia (`sin_suspension_hasta`).
- **Superadministrador:** en la ficha y en Cobros → Vencidos se ve «Suspendido por
  renta; se reactiva al pagar».

## Consecuencias

- La cobranza ya no depende de que alguien suspenda a mano, y el dueño siempre
  puede entrar a pagar y reactivarse solo.
- Los clientes del negocio no pueden agendar mientras dure la suspensión. Los pagos
  de clientes que lleguen por webhook mientras tanto los recupera la conciliación
  al reactivarse.
- Cambiar los días de gracia aplica desde el siguiente proceso diario.
