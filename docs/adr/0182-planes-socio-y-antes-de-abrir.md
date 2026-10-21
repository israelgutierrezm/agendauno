# ADR 0182 — Planes «socio» y ajustes antes de abrir

Estado: Aceptado (2026-10-20).

## Contexto

Comparamos el sistema de clases con tres competidores y con estudios reales que los usan.
Salieron dos grupos de pendientes:

- Los planes «socio» de los estudios boutique hacen tres cosas que no teníamos: ponen un
  tope de reservas, dejan reservar antes que los demás e incluyen invitados al mes.
- Hay errores de catálogo que los estudios no notan: un paquete grande más caro por clase
  que uno chico, un «ilimitado» con clases contadas o una política escrita pero apagada.
  Además, los estudios empiezan con el catálogo en blanco y la landing muestra precios en
  dólares a negocios de México.

## Decisión

### Topes, acceso anticipado e invitados (por plan)

- Cada plan puede tener **máximo de reservas por día y por semana** (de lunes a domingo, en
  la zona de la sede), **horas de acceso anticipado** e **invitados al mes**
  (`productos_comerciales`, migración de negocio 000175). Todo es opcional.
- **La venta congela las cuatro reglas en el derecho**, igual que el ciclo y las clases
  que incluye. Editar el plan solo cambia las ventas siguientes.
- **El tope** cuenta las reservas de ese plan que ocupan lugar o están en la lista de
  espera, en otras clases del mismo día o semana. Un plan que llegó a su tope no se usa;
  si todos los planes de la persona llegaron, la respuesta es 422 `BOOKING_LIMIT_REACHED`
  con el motivo. Se revisa al elegir el plan y otra vez con el derecho bloqueado, para que
  dos reservas al mismo tiempo no pasen las dos. «Antes de reservar» lo avisa con el motivo
  `tope`.
- **El acceso anticipado** adelanta la apertura de las reservas
  (`reservas.dias_apertura`) las horas del plan que cubre la clase (si son varios, el
  mayor). Solo aplica cuando reserva el propio cliente; el equipo reserva como siempre.
- **El invitado no es una persona del negocio.** Ocupa un lugar en la clase a nombre de
  quien lo invita, como los lugares de Wellhub y TotalPass (`invitados_sesion`). No
  consume créditos y gasta un pase del mes de la clase. Solo se guarda su nombre; el
  evento `invitado.registrado` no lo lleva. Se permite un invitado por cliente en cada
  clase. Cancelar antes de que empiece la clase devuelve el pase y el lugar. El equipo lo
  ve en el pase de lista y marca si llegó.
- `CupoDeSesionTenant` sigue siendo la única regla de cupo y ahora suma a los invitados.

### Antes de abrir

- **Catálogo de arranque por giro.** La configuración inicial propone la clase muestra
  (producto de primera vez), la clase suelta, los paquetes típicos del giro (más grandes y
  más baratos por clase) y la mensualidad. Los precios son de referencia de estudios en
  México y el dueño los ajusta. Se agrega el giro **cycling** (modalidad de clases).
- **Avisos de coherencia del catálogo** (`CoherenciaCatalogoTenant`): un paquete que sale
  más caro por clase que uno más chico del mismo alcance; un nombre que dice «ilimitado»
  en un plan con clases contadas; un nombre o una descripción con un número de clases
  distinto al que da; y una descripción que habla de cancelaciones cuando las reglas no
  penalizan nada. No bloquean nada. Se muestran al guardar un plan (`meta.avisos`) y en la
  lista de planes (`GET /productos/coherencia`). Las comparaciones por clase se hacen en
  enteros.
- **Precios en pesos con IVA en la landing.** `GET /precios` trae el último FIX conocido
  (`tipo_cambio`). La landing y la calculadora muestran el equivalente en pesos con IVA y
  la fecha del tipo de cambio; el cargo sigue usando el FIX del día en que se emite. Sin
  tipo de cambio, no se inventa uno.
- **WhatsApp solo donde está activo.** Comunicaciones ya no ofrece WhatsApp en los filtros
  de un negocio que no lo tiene.

## Consecuencias

- La web (editor de planes, «antes de reservar», pase de lista) y la app (invitar desde el
  detalle de la clase) usan las reglas nuevas.
- Los planes vendidos antes de esta versión no tienen topes, acceso anticipado ni
  invitados, aunque su plan los tenga después.
