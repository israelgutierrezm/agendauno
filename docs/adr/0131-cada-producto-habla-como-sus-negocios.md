# ADR 0131 — Cada producto habla como sus negocios

Estado: Aceptado (2026-10-11). Pedido del dueño: «Ahora que están separadas, revisa
la congruencia con sus tipos de negocio».

## Contexto

La base ya estaba separada: la modalidad decide el producto (ADR 0104, 0108), cada
dominio registra solo sus giros y la API niega las rutas de la otra modalidad. La
revisión encontró que la marca y el vocabulario no siempre seguían esa separación.

Lo que veían dueños y clientes de TurnoUno:
- Los avisos al dueño decían «Gracias por usar AgendaUno».
- El recibo, el cargo en Stripe y la factura de la renta decían «Suscripción
  AgendaUno».
- La plantilla del aviso de privacidad que firma el cliente nombraba a AgendaUno.
- Los términos y el aviso de privacidad de la plataforma volvían a «AgendaUno».
- Las pantallas de entrar y de registro mostraban fotos y ejemplos del otro producto.
- El directorio mezclaba estudios y barberías.
- Los correos y WhatsApp al cliente de un negocio de citas decían «tu lugar está
  confirmado» y «paga en el estudio».

En el panel, cada negocio veía cosas de la otra modalidad:
- «Cómo se llaman las cosas» dejaba a una barbería elegir «Clase» o «Alumno».
- «Límites y tiempos» le mostraba la lista de espera y las clases recurrentes.
- El editor de roles ofrecía los check-ins de Wellhub a un negocio de citas.
- El catálogo ofrecía lugares numerados en citas y, en clases, hablaba de «Pago al
  agendar (cita)».

## Decisión

**Marca del negocio, no de la plataforma:**
- Avisos al dueño:
  - las plantillas usan `{{marca}}`, que se llena con AgendaUno o TurnoUno;
  - en WhatsApp es un valor más de la plantilla de Meta.
- Recibo, cargo en la pasarela y factura de la renta:
  - `ReciboRentaPdf::concepto()` usa la marca del negocio;
  - el encabezado del PDF también.
- Aviso de privacidad del negocio (plantilla):
  - pasa por `conMarca` con el producto del negocio;
  - dice «bonos» en citas.
- Términos, aviso de privacidad y su contacto: la marca y el correo de soporte del
  dominio.
- Entrar, registro y directorio:
  - fotos y ejemplos del producto del dominio;
  - los textos de cada producto: clases o citas.

**Avisos al cliente en el idioma de su negocio:**
- Correo y push (`PlantillasPorModalidad`): en citas, «tu cita está confirmada»,
  «liberar tu horario» y «agendar».
  - Solo cambian los textos por omisión que el negocio no editó; lo que escribió el
    negocio no se toca.
  - Se aplica:
    - a los negocios de citas existentes, con la migración 000127;
    - a los nuevos, al aprovisionarlos;
    - al cambiar de modalidad, en las dos direcciones.
- WhatsApp:
  - plantillas gemelas `turnouno_*` para los avisos de citas;
  - `PlantillasWhatsApp::para($evento, $modalidad)` elige la que toca;
  - el superadmin las lista con su producto para registrarlas en Meta.
- «Paga en el estudio» pasa a «paga en el negocio».
- En los mensajes de la API, «este estudio» pasa a «este negocio».

**El panel muestra solo lo de su modalidad:**
- Terminología:
  - cada modalidad tiene sus opciones (`TerminologiaNegocio::POR_MODALIDAD`);
  - la API valida contra ellas;
  - lo que un negocio ya tenía guardado se sigue mostrando.
- «Límites y tiempos» (`DefinicionParametro::$modalidad`):
  - los de una sola modalidad no se listan ni se guardan en la otra;
  - de clases: apertura y cierre de reservas, lista de espera, clases recurrentes,
    acceso, aviso de renovación y gracia del pago automático;
  - de citas: los `citas.*`.
- Roles (`CatalogoDePermisosTenant::catalogoPara`):
  - en citas no aparecen los check-ins ni las cuotas;
  - en clases no aparecen los presupuestos.
- Catálogo:
  - los lugares numerados solo en clases;
  - en clases dice «Pago por clase», «Precio por clase» y «Clase con pago».
- Textos por modalidad:
  - Reportes: rentabilidad por servicio y clientes atendidos;
  - días de cierre;
  - retención: última visita;
  - «Quién cuenta» en la renta;
  - la foto por omisión de un negocio general de clases.
- La página pública del negocio usa su propia terminología: sus pacientes, sus
  coaches, sus lecciones.
- Un enlace de registro con un giro o una modalidad del otro producto lleva al
  registro de ese producto. Antes el giro se ignoraba sin avisar.

## Consecuencias

- Un dueño o un cliente de TurnoUno no ve AgendaUno ni el lenguaje de clases, y al
  revés.
- **Acción del dueño en Meta:** registrar las plantillas `turnouno_*` y volver a
  registrar los avisos al dueño, que ahora llevan `{{marca}}`. El superadmin muestra
  el texto exacto.
- Fuera de este ADR:
  - los temas de color con nombre de AgendaUno: falta decidir la paleta de TurnoUno;
  - la landing y los precios: el dueño los está ajustando aparte.
