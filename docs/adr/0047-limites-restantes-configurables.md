# ADR 0047 — Los límites de negocio que quedaban fijos, configurables

Estado: Aceptado (2026-09-26). Completa el ADR 0042 (parámetros configurables).

## Contexto

Tras el ADR 0042 aún quedaban límites de negocio fijos en el código. Además, "por
vencer" no se medía igual en todas partes: 10 días en la ficha del alumno y 14 en el
radar de retención y en las difusiones.

## Decisiones

- **Nuevos parámetros del negocio**, con el valor de plataforma que fija el
  superadmin:

  | Parámetro | Qué fija | Valor inicial |
  |---|---|---|
  | `membresias.dias_aviso_renovacion` | cuántos días antes se avisa la renovación | 3 |
  | `membresias.dias_por_vencer` | desde cuántos días antes una membresía está "por vencer" | 14 |
  | `membresias.dias_vencida_recuperable` | cuántos días después de vencer se sigue buscando al alumno | 14 |
  | `membresias.max_dias_pausa` | cuánto puede durar una pausa | 180 días |
  | `cobranza.dias_pagar_en_tienda` | vigencia de la referencia de OXXO (Mercado Pago y OpenPay) | 3 días |
  | `facturacion.iva_porcentaje` | tasa de IVA de las facturas del negocio | 16 |

  - `membresias.dias_por_vencer` aplica en la ficha, el radar de retención y las
    difusiones. La ficha pasa de 10 a 14 días para medir igual que el resto.
  - `membresias.dias_vencida_recuperable` aplica en el radar de retención y las
    difusiones.
  - La tasa de IVA admite 16 u 8. El 8 es para la región fronteriza.
- **Solo de plataforma** (superadmin):

  | Parámetro | Qué fija | Valor inicial | Rango |
  |---|---|---|---|
  | `acceso.segundos_pase_qr` | vigencia del pase QR de entrada | 180 s | 90–900 s |
  | `importaciones.max_filas` | filas por archivo al importar | 1000 | 100–10 000 |

  El pase no baja de 90 s porque las pantallas lo renuevan cada minuto.
- **Valores de una lista**: un parámetro puede declarar `opciones`. Solo se aceptan
  esos valores, y la pantalla los ofrece en una lista en lugar de un campo numérico.

## Consecuencias

- Siguen en el código solo los valores técnicos: reintentos, tamaños de lote, tiempos
  de espera y la ventana de 23 h que exige la llave de Stripe. También quedan los
  tamaños de las listas en pantalla.
- La prueba gratuita del SaaS (días de trial) está en cambios del usuario sin commit.
  Cuando se integre debe seguir la misma regla.
