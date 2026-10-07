# ADR 0088 — Configuración inicial por tipo de negocio

Estado: Aceptado (2026-10-02). Reemplaza los pasos fijos del asistente de onboarding.

## Contexto

El asistente de configuración daba los mismos nueve pasos a todos los negocios:
marca, sucursal, actividades, horarios, productos, políticas, pasarela, personal y
publicación. Aunque varios se podían omitir, obligaban al dueño a decidir sobre cosas
que todavía no necesitaba.

Además, el alta de un servicio pedía la estructura interna del catálogo: programa →
actividad → oferta, modalidad y cupo. No pedía directamente duración y precio. Un
barbero quiere escribir «Corte de cabello · 30 minutos · $250»; la estructura puede
existir por dentro, pero entenderla no es su trabajo.

## Decisión

### Pasos según cómo trabaja el negocio (su modalidad)

La modalidad es la guardada del negocio, solo clases o solo citas (ADR 0104); el giro
solo la propone al registrarse.

- **Citas** (barbería, salón, estética, spa, salud):
  tu negocio → servicios → quién atiende y cuándo → publicar.
- **Clases** (pole, pilates, yoga, danza, natación, gimnasio):
  tu negocio → clases → horario → planes y precios → publicar.

Cobro en línea, equipo administrativo, productos de mostrador y reglas de cancelación
salen del asistente. Quedan como «cuando lo necesites» en el último paso y en la lista
de pendientes del panel (`/onboarding/quickstart`).

### Un servicio o una clase en una línea

`POST /onboarding/catalogo` (`AltaRapidaCatalogoTenant`) recibe filas con nombre y
duración, más:

- **precio**, en citas: se crea una cita de pago individual;
- **cupo**, en clases: se crea una clase grupal que se toma con un plan.

El programa y la actividad se crean por dentro con un solo grupo («Servicios» o
«Clases»). El dueño puede reorganizarlos después en Catálogo. Si aún no hay política
de cancelación, se deja una razonable (sin costo hasta 6 horas antes), que se ajusta
en Reglas de reserva.

### Sugerencias del giro

`SugerenciasPerfil` da los servicios, clases y planes con los que suele empezar cada
perfil (por ejemplo, barbería: corte de cabello 30 min $250, arreglo de barba 30 min
$180, corte y barba 60 min $380). Son valores iniciales que el dueño ajusta. Son
configuración del perfil, no lógica por giro.

### Quién atiende (citas) y horario (clases)

Se usan los servicios que ya existen:

- **«Yo atiendo»:** agrega al dueño el rol de profesional (con la terminología del
  giro: barbero, terapeuta…).
- **Las demás personas:** se invitan con el rol de profesional.
- **Horario de atención:** el mismo para todos al empezar; se ajusta por persona en
  Horarios.
- **Clases:** cada una toma sus días y su hora como clase recurrente
  (`plantillas-horario`), y se generan las próximas cuatro semanas.

### El avance sale de los datos reales

Un paso está hecho cuando existen sus datos: sucursal, catálogo, horario de atención
(o clases programadas) y planes. Solo «publicar» se anota aparte. Un «siguiente» sin
datos no da el paso por hecho (422 con lo que falta). Un negocio que ya había
completado el asistente anterior sigue completo.

## Consecuencias

- El dueño de una barbería termina en cuatro pasos y nunca ve «programa», «actividad»
  ni «modalidad».
- El paso de pasarela desaparece del asistente. Cobrar en línea es opcional y se
  configura desde Pasarelas cuando el negocio lo decida.
- Las claves de pasos anteriores (`marca`, `sucursal`, `actividades`, …) ya no se
  aceptan. El progreso de los negocios que estaban a medias se recalcula con sus datos.
