# Modelo de dominio

Nombres del código entre paréntesis. Mapa de tablas en `docs/DATABASE.md`.

## Negocio y estructura

```
Plataforma
→ Negocio (Estudio)            frontera de seguridad y de cobro; su propia base
  → Organización / marca
    → Sucursal                 zona horaria, horario de atención
      → Recurso                sala, camilla, carril, silla
```

El **perfil** del negocio (`PerfilNegocio`: gimnasio, pilates, pole, natación,
danza, yoga, academia, barbería, estética, salón, spa, salud, general) solo cambia
valores por defecto, terminología y flags. La **modalidad** (`ModalidadServicio`:
clases o citas) decide cómo se cobra el SaaS y qué se ve primero. Nunca hay
`if ($perfil === …)` en el dominio.

## Identidad y personas

- **Usuario** (`Usuario`) — la cuenta con la que se entra al negocio; su correo es
  único dentro del negocio.
- **Persona** (`PersonaTenant`) — el ser humano: alumno, cliente o profesional. Puede
  existir sin cuenta (lo da de alta recepción).
- **Rol** — lo que la cuenta puede hacer: de sistema o propio del negocio. Una cuenta
  puede tener varios roles y usa uno a la vez (rol activo).
- Miembro ≠ instructor ≠ usuario: una misma persona puede ser alumna e instructora.

No hay familias ni tutores (ADR 0059).

## Catálogo y agenda

```
Programa → Actividad → Oferta (clase grupal o servicio privado)
→ Plantilla de horario (recurrente) → Sesión (clase o cita con fecha)
```

- **Clases**: sesiones grupales con capacidad, instructor(es), lista de espera.
- **Citas**: sesiones privadas con un profesional, duración y márgenes, recursos y
  horario de atención; bloqueos de agenda.
- Las sesiones se materializan desde las plantillas (ADR 0010); se reprograman,
  cancelan y cambian en serie (ADR 0038, 0040, 0045).

## Comercio y derechos

```
Producto comercial → Orden (comprador) → Pago aprobado
→ Acuerdo (lo comprado) → Derecho (lo que da) → uso (reserva, crédito)
```

- Productos: membresía (ilimitada o limitada por ciclo), paquete de clases, pase,
  clase extra, servicio.
- Comprador ≠ beneficiario: una línea de orden puede beneficiar a otra persona.
- Los créditos se mueven en un ledger con holds; el saldo se suma, nunca se guarda
  (ADR 0009, 0011).
- El pago aprobado concede los derechos en la misma transacción (fulfillment,
  ADR 0012); el reembolso los revierte (ADR 0013, 0029).
- Pasarelas intercambiables: Stripe, Mercado Pago, OpenPay, efectivo, depósito
  (ADR 0014–0016, 0021); cobro automático (ADR 0020).

## Reservar

Una reserva valida elegibilidad, ventana de reserva, derecho vigente, capacidad,
recursos y reglas de cancelación, con bloqueo del registro padre para ser segura
ante concurrencia (ADR 0011, 0033, 0034). La lista de espera promueve al liberarse
un lugar.

## Identificadores

- PK `BIGINT` interna.
- ULID público en la API.
