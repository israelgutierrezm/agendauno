# ADR 0067 — Más datos del cliente al agendar

Estado: Aceptado (2026-09-29). Reemplazado en parte por el ADR 0103 (2026-10-07): la
lada inicial sale del país del negocio (no `+52`) y el celular también puede llegar con
«+».

## Contexto

Al agendar en línea solo se pedían nombre, celular y correo. Los negocios necesitan
más:

- los apellidos, para distinguir a sus clientes;
- la lada, porque hay clientes de otros países y los avisos por WhatsApp o SMS
  necesitan el número completo;
- por qué canal los conocen, para saber qué les trae clientes;
- una nota con lo que el cliente quiere que sepan: alergias, preferencias, si es su
  primera vez.

## Decisión

- **Página pública** (`POST /citas`):
  - `apellidos`: el primero es el paterno y el resto el materno;
  - `lada` (`+52` por omisión en la pantalla): el celular se guarda con ella
    (`+1 555 123 4567`);
  - `como_nos_conocio`: enum `OrigenCliente` (instagram, facebook, tiktok, google,
    recomendación, pasó por el local, otro);
  - `nota`, de hasta 500 caracteres.
- **Ficha nueva**: estos datos solo se guardan al crear la ficha de un cliente nuevo.
  Si el correo ya es de alguien, su ficha no cambia; es la misma regla de siempre,
  porque escribir un correo no demuestra que sea suyo. La nota sí llega a la cita.
- **Nota en la cita**: `reservas.nota_cliente`.
  - Desde la cuenta (`POST /mi/citas`) también se puede dejar.
  - El negocio la ve en el detalle de la cita («Nota del cliente»).
- **Cómo nos conoció**: `personas.como_nos_conocio`, visible en el panel del cliente
  («Nos conoció por») y en la lista de miembros.

## Consecuencias

- El negocio sabe qué canal le trae clientes: Reportes → Clientes muestra «Cómo nos
  conocieron» sobre las altas de los últimos meses (`origenes` y
  `origenes_sin_dato` en `/reportes/cohortes`).
- El celular con lada queda listo para avisos por WhatsApp o SMS.
- «Agendar para otra persona» se resolvió aparte, sin volver a familias (ADR 0068).
