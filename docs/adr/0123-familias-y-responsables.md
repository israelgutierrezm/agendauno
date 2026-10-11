# ADR 0123 — Familias y responsables, y módulos por negocio

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, B05 (bloque 7). Reemplaza al
ADR 0059 («Sin familias ni tutores en la V1»). Decisiones del dueño del 2026-10-10.

## Contexto

El ADR 0059 quitó hogares, tutelas y dependientes porque ninguna prioridad de la V1 los
necesitaba. El plan de mejoras los vuelve a pedir para academias infantiles y escuelas de
natación: un adulto que paga y reserva por dos hijos, sin mezclar sus créditos. Las
condiciones son tres:

- una autorización retirada impide nuevos accesos;
- los avisos dicen de quién se trata;
- los datos de cada persona se respetan.

El dueño decidió:

- **Responsable:** un alumno con cuenta. Gestiona a participantes que pueden no tener
  cuenta; cada participante conserva sus planes, créditos y reservas.
- **Avisos:** al responsable y al participante (si tiene correo o app propios).
- **Alcance:** el responsable ve y hace reservas, planes, pagos y documentos de los
  participantes ligados.
- **Autorización:** la aceptación de un texto versionado del negocio (versión, fecha e
  IP). Retirarla impide nuevas reservas y accesos. La redacción requiere revisión legal.
- **Módulo:** se enciende o se apaga por negocio desde el superadministrador, apagado por
  omisión. Habrá otros módulos así.

## Decisión

**Módulos por negocio.**

- `estudios.modulos` (control plane) guarda los módulos encendidos. El catálogo es
  `ModuloNegocio`; por ahora solo `familias`. Apagados por omisión.
- Solo el superadministrador los cambia: `PUT /plataforma/estudios/{estudio}/modulos`
  `{modulo, habilitado}`. La ficha del negocio en la plataforma los lista
  (`modulos[]` con clave, etiqueta, descripción y estado).
- El middleware `modulo:familias` niega sus rutas con 403 `MODULE_NOT_ENABLED`.
- La sesión (`estudio.modulos`) dice cuáles están encendidos. La web y la app solo
  muestran esos.
- Es un concepto distinto de los demás:
  - las funciones del plan (lo que se paga, ADR 0107);
  - las capacidades de la modalidad (ADR 0104);
  - la configuración del negocio.
- Al apagarlo, lo registrado se conserva para cuando se vuelva a encender.

**Vínculo (`tutelas`, `FamiliasTenant`).**

- Liga a un responsable con un participante, con su parentesco: madre o padre, tutor
  legal, otro familiar o persona autorizada.
- Estados: `pendiente` → `activa` → `retirada`.
- Lo crea el negocio desde la ficha (`POST /miembros/{persona}/participantes`), con estas
  condiciones:
  - el responsable tiene cuenta;
  - los dos son alumnos vigentes y distintos;
  - no se forman ciclos;
  - el responsable no pasa de `familias.max_participantes` (parámetro, 6 por omisión).
- El alta bloquea al responsable para respetar el máximo con altas simultáneas.
- Volver a ligar un vínculo retirado lo deja pendiente otra vez.

**Autorización versionada.**

- `configuracion_familias` guarda el texto del negocio. Sin texto propio se usa el base
  (`TextoAutorizacion::BASE`). Cada cambio del texto sube la versión.
- El responsable la acepta en su cuenta (`POST /mi/participantes/{tutela}/autorizar`,
  `acepto` + la `version` que leyó). El equipo también puede registrar que la aceptó en
  el mostrador (`POST /tutelas/{tutela}/autorizar`), viendo el texto.
- Una versión que ya no es la vigente responde 409 `AUTHORIZATION_TEXT_CHANGED` con el
  texto nuevo.
- El vínculo guarda la constancia: versión, huella, el texto mismo, cuándo, por dónde,
  quién e IP. Aceptar otra vez no la cambia.
- La retiran el responsable (`/mi/participantes/{tutela}/retirar`) o el negocio
  (`/tutelas/{tutela}/retirar`).
- Todo pasa por la bitácora: `tutela.creada`, `tutela.autorizada`, `tutela.retirada` y
  `familias.configuracion`.
- El negocio puede apagar familias en su configuración aunque el módulo esté encendido.
  Con el módulo encendido se usan sin más pasos.

**Actuar por un participante (`CuentaEnUsoTenant`).**

- Con el encabezado `X-Participante: <ulid>`, la cuenta actúa por una persona a su cargo
  con la autorización vigente. Es el único punto que lo decide.
- Lo consultan:
  - la cuenta: perfil y pase;
  - las reservas: agenda, reservar, citas, cancelar, reprogramar, lugares y aceptar un
    lugar;
  - los planes: planes, movimientos e historial;
  - los pagos: productos, órdenes, comprar y pagar;
  - los documentos: documentos, documentos por firmar y formularios.
- Un encabezado que no corresponde responde 403 `GUARDIAN_NOT_AUTHORIZED`, sin volver en
  silencio a la cuenta propia. Responde así si la persona no está a su cargo, si la
  autorización está pendiente o retirada, o si el módulo o la función están apagados.
- No lo consultan, y siempre son del titular:
  - la privacidad y la exportación de datos;
  - los dispositivos;
  - el pago automático (el de una persona a cargo se activa en el negocio);
  - las reseñas.
- **Comprar por el participante.** El responsable es el comprador y quien acepta las
  condiciones; el participante es el beneficiario de cada línea. Las reglas de la clase de
  prueba, la inscripción y los derechos se aplican al participante.
- **Las órdenes.**
  - En la cuenta del participante aparecen las suyas y las compradas para él.
  - En la del responsable, las que pagó.
  - Cada línea dice `para` quién es.

**Avisos.**

- Los de una persona también se generan para sus responsables con autorización vigente,
  por correo y push a su usuario. El asunto lleva «Sobre {nombre}:» al frente.
- La bandeja y WhatsApp siguen siendo de la persona misma.
- La llave de envío por usuario evita duplicados.

**Web y app.**

- Una barra «Cuenta de» en el portal permite pasar a la cuenta de una persona autorizada.
  Mientras dure, dice de quién es; la vista se monta de nuevo y el portal se vacía al
  cambiar.
- Inicio muestra «Personas a tu cargo»: autorizar leyendo el texto, ver su cuenta o
  retirar.
- La ficha del alumno tiene la sección «Familia».
- Alumnos tiene el botón «Familias» para encender o apagar la función y redactar el texto
  (permiso `estudio.gestionar`).
- La ficha del negocio en la plataforma tiene «Módulos».

## Consecuencias

- Un adulto paga y reserva por dos participantes sin mezclar créditos. Retirar la
  autorización o apagar el módulo corta el acceso al momento; lo hecho se conserva.
- Las exportaciones y solicitudes de datos siguen siendo de cada persona: el
  encabezado no aplica a la privacidad.
- WhatsApp a responsables y el pago automático de un participante quedan fuera por ahora.
- La app móvil hace lo mismo: el selector «Cuenta de» manda `X-Participante` solo a
  `/mi/…` y vuelve a la cuenta propia si el servidor ya no la autoriza o cambia la
  sesión. Inicio muestra «Personas a tu cargo» con la autorización por aceptar.
- La redacción del texto base y su validez con menores requieren revisión legal del
  negocio.
- Nuevos módulos se agregan al catálogo `ModuloNegocio` con su middleware en las rutas.
