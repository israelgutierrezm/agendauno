# ADR 0142 — Atribución de campañas y regreso tras entrar

Estado: Aceptado (2026-10-12). Primer bloque del pedido «Integración de Google
Analytics 4 y Meta en AgendaUno y TurnoUno» (dos ámbitos: la promoción del SaaS y la de
cada negocio). Los destinos externos, el consentimiento y las conversiones van en el
ADR 0143; el reporte por campaña, en el ADR 0144.

## Contexto

- `lib/analytics.ts` guardaba los UTM en `sessionStorage` con la primera lectura de la
  pestaña, aunque estuviera vacía: una campaña que llegaba después en la misma pestaña
  no contaba.
- La captura era perezosa (al primer evento). Las redirecciones del router (la raíz
  del subdominio a su sucursal, el otro producto) llegaban antes y perdían la query.
- No había separación por ámbito: la campaña de un negocio se mezclaba con la de la
  plataforma y con la de otro negocio de la misma pestaña.
- Nada llegaba al servidor. El registro del negocio no guardaba origen. El prospecto
  guardaba `fuente` como texto, armado solo con la dirección actual.
- Quien elegía una clase o un plan en la página del negocio y luego tocaba «Ya tengo
  cuenta» entraba a su inicio sin lo que había elegido.

## Decisión

**Ámbitos.** Cada producto (`plataforma:agendauno`, `plataforma:turnouno`) y cada
negocio (`negocio:{slug}`) guardan lo suyo. El ámbito sale de la ruta:

- el negocio, por su slug en la ruta, su subdominio o el `?estudio=` de desarrollo;
- el producto, en sus páginas comerciales y su registro;
- en el panel y el superadmin no hay ámbito.

**Toques (reglas propias, no las de Google ni Meta).**

- Un toque es una llegada con campaña: `utm_source` o `utm_campaign`, un clic de
  anuncio (`gclid`, `fbclid`) o un enlace desde otro sitio. Otro sitio no es el propio
  ni una pasarela de pago.
- Entrar directo o navegar dentro no es un toque.
- Primer origen: el primer toque del ámbito. Último toque: el más reciente.
- Volver a la misma campaña (recargar) no cambia su fecha.
- Se capturan en un `beforeEach` del router, antes de cualquier redirección y en cada
  navegación. La página de la que se viene solo cuenta en la primera navegación de la
  carga.
- Se descartan los valores que parecen datos personales: un correo, un teléfono, un
  número de 7 o más dígitos o caracteres fuera de lo común. Lo mismo hace el servidor.
- Los identificadores de clic (`gclid`, `fbclid`) se guardan tal como llegan, pero no
  salen del navegador sin la autorización de publicidad (ADR 0143).

**Ventanas configurables.**

- Parámetros por negocio, con un valor de plataforma que aplica al registro de
  negocios:
  - `medicion.dias_ultimo_toque` (30 días; 1 a 90): cuánto tiempo cuenta una campaña;
  - `medicion.dias_primer_toque` (90 días; 1 a 365): cuánto se recuerda el primer
    origen.
- El navegador guarda como máximo 90 y 365 días (higiene). La ventana que vale la
  aplica el servidor al recibir el registro.

**Guardado con el registro, no solo en el navegador.** El navegador manda
`atribucion: {primer_toque, ultimo_toque}` con:

- el registro del negocio (`POST /registro`), que va a `contextos_medicion_plataforma`
  del control plane, uno por negocio y con su producto;
- la solicitud de captación, que va a `contextos_medicion` del negocio y la apunta
  `prospectos.contexto_medicion_id`. Un reenvío conserva la campaña de la primera
  solicitud;
- la cita sin cuenta (`POST /citas`), que guarda la persona y la apunta la orden;
- la compra desde su cuenta (`POST /mi/ordenes`), que guarda la persona y la apunta la
  orden.

En el servidor (`Medicion\Atribucion`):

- se valida otra vez forma, largo, contenido y fecha. Un toque del futuro o fuera de su
  ventana no cuenta;
- se acredita la campaña del último toque vigente (`fuente`, `medio`, `campana`,
  `referente`). Sin él, el registro queda **sin atribución**, aunque haya primer
  origen;
- nada de esto impide registrarse, comprar ni agendar: si falla, se reporta y el
  registro sigue sin atribución.

El detalle del prospecto muestra la campaña y su primer origen, o «Sin atribución de
campaña». Si no se escribió una `fuente`, se toma la de la campaña.

**Lo elegido sobrevive al entrar.**

- «Ya tengo cuenta» en la página del negocio y en el widget de planes manda `volver`
  con el plan o la clase que eligió (`/mi-cuenta?plan=…` o `?clase=…`).
- `GET /mi/interes?plan=&clase=` lo muestra en su inicio, con las mismas reglas que
  el interés del prospecto (ADR 0137). Solo cuenta lo de ese negocio que sigue
  público: una clase programada y futura, o un plan sin archivar.
- La cita conserva su selección como antes (en la pestaña).

**Destinos seguros.** `volverSeguro` (web) acepta solo una ruta de la app que exista,
sin `//`, `/\`, esquemas, caracteres de control ni más de 500 caracteres. Al entrar a un
negocio, también rechaza las rutas de otro negocio (`/estudio/otro`, `?estudio=otro`).
Lo usan la pantalla de entrar y el router.

## Consecuencias

- Migraciones:
  - tenant 000133: `contextos_medicion`, más `prospectos.contexto_medicion_id` y
    `ordenes.contexto_medicion_id`;
  - control plane: `2026_10_12_000100` (`contextos_medicion_plataforma`).
- Sin consentimiento todavía, la atribución vive solo en la pestaña
  (`sessionStorage`) y no lleva identificadores de clic. El ADR 0143 la persiste con la
  autorización de analítica.
- No es la atribución de Google ni la de Meta. Cada proveedor aplica sus propias
  reglas y ventanas, y los números no tienen por qué coincidir.
- Una persona que llega por campaña y compra en el mostrador sin solicitud ni compra
  en línea queda sin atribución: no hay evidencia de su campaña.
