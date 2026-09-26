# ADR 0041 — La agenda como centro de operación (fase 2, punto 2.6)

Estado: Aceptado (2026-09-26).

## Contexto

La agenda ya tenía casi todo lo del 2.6:

- vistas de semana, día y por profesional;
- botón "Hoy" y navegación por día o semana;
- filtros por sede y profesional;
- ocupación de las clases ("7/8") con barra;
- acciones desde el detalle: llegada, no vino, cobro, cancelación y, desde el 2.1,
  reprogramación.

Faltaban cuatro cosas:

- **Atención y pago mezclados**: una cita apartada en línea salía como "Pago
  pendiente", como si fuera un estado de atención. En la lista del día, una cita se
  describía con estados de clase ("Disponible", "Llena").
- **Sin filtro por servicio o clase.**
- **Vista por profesional en el celular**: solo había la cuadrícula de columnas,
  ilegible en pantallas angostas.

## Decisiones

- **Dos estados por cita**:
  - Atención (`estadoCita`): agendada, llegó, en servicio, completada, no asistió o
    cancelada.
  - Pago (`pagoCita`): falta pagar en línea, por cobrar en caja, pagada, o nada si
    usa su membresía.
  - En la tarjeta y en la lista van como punto y texto de atención, más el pago como
    texto aparte y solo cuando falta ("· Por cobrar"). Siempre con texto, no solo
    color.
  - Los indicadores cuentan lo por cobrar con el estado de pago.
- **Filtro por servicio o clase** junto a sede y profesional; aplica a todas las
  vistas.
- **En el celular, la vista por profesional muestra la lista del día** (hora,
  cliente, servicio, profesional, atención y pago). Las columnas quedan para
  pantallas anchas.

## Consecuencias

- "Agendada" reemplaza "Confirmada" como etiqueta de atención de una cita. La
  confirmación del pago se lee en su estado de pago.
- Arrastrar y soltar para reprogramar sigue fuera de alcance (formularios del 2.1).
