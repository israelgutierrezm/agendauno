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

El **perfil** o giro del negocio (`PerfilNegocio`: gimnasio, pilates, pole, natación,
danza, yoga, academia, barbería, estética, salón, spa, salud, general) solo cambia
valores por defecto, terminología y flags. La **modalidad** (`ModalidadServicio`:
clases o citas) es excluyente y está guardada en el negocio (`estudios.modalidad`,
ADR 0104): el giro solo la propone al registrarse y solo el superadmin la cambia,
antes de que haya sesiones o reservas. Decide:

- el tipo de toda sesión del negocio (clases → `clase`, citas → `cita`);
- qué flujos existen: las rutas exclusivas del otro modelo responden 403
  `MODALITY_NOT_AVAILABLE`;
- las `capacidades` que la sesión manda a la web y la app;
- la métrica del cobro del SaaS (alumnos o profesionales activos).

Nunca hay `if ($perfil === …)` en el dominio, y lo que depende del modelo pregunta
por la modalidad del negocio, no por el giro ni por la forma de la oferta.

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
  horario de atención; bloqueos de agenda. Una cita no tiene lista de espera ni una
  segunda reserva activa.
- Un negocio tiene solo una de las dos (ADR 0104). La oferta no guarda su tipo: es el
  de la modalidad del negocio; `politica_reserva` solo dice si se reserva con un
  derecho o pagando.
- Las sesiones se materializan desde las plantillas (ADR 0010); se reprograman,
  cancelan y cambian en serie (ADR 0038, 0040, 0045).

## Comercio y derechos

```
Producto comercial → Orden (comprador) → Pago aprobado
→ Acuerdo (lo comprado) → Derecho (lo que da) → uso (reserva, crédito)
```

- Productos: membresía (ilimitada o limitada por ciclo), paquete de clases, pase,
  clase extra, servicio. En citas, el bono de sesiones y la membresía son los mismos
  productos con el mismo ledger (ADR 0091): el comercio es núcleo, no de Clases.
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
ante concurrencia (ADR 0011, 0033, 0034). La lista de espera (solo en clases)
promueve al liberarse un lugar; una cita que se cancela o se mueve libera el horario
del profesional.

## Identificadores

- PK `BIGINT` interna.
- ULID público en la API.
