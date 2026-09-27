# ADR 0049 — Terminología por negocio en todas las pantallas

Estado: Aceptado (2026-09-26). Extiende el perfil de negocio (R35).

## Contexto

Cada perfil de giro ya traía su terminología (una barbería: "Cita / Cliente /
Barbero"), pero casi ninguna pantalla la usaba. Unos 130 textos de la web y unos 10
de la app decían "clases", "alumnos" o "instructor". En una barbería eso confunde. El
negocio también debe poder usar otras palabras ("Consulta", "Paciente", "Estilista").

## Decisiones

- **Tres términos con plural**: lo que se reserva (`sesion`), quien lo toma
  (`miembro`) y quien lo imparte (`instructor`).
  - Salen del perfil del giro. El negocio puede elegir otros, solo de listas cerradas
    (`TerminologiaNegocio::OPCIONES`).
  - Todo lo que se reserva es femenino, como "clase": Cita, Sesión, Lección,
    Consulta.
  - Quien imparte es masculino o de género común, como "instructor": Coach, Barbero,
    Estilista…
  - Así, al cambiar la palabra, los artículos del texto siguen cuadrando.
- **Dónde se guarda**: `estudios.terminologia`, en el control plane, con solo lo que
  el negocio eligió.
  - `Estudio::perfilConfig()` combina el perfil con esa elección y agrega los plurales.
  - Así llega en la sesión (`/yo`, login), el escaparate y el registro.
- **Quién la cambia**:
  - el administrador, en Configuración (`/terminologia`, permiso
    `estudio.gestionar`, queda en la bitácora);
  - el superadmin, en la ficha del negocio (`/plataforma/estudios/{slug}/terminologia`).
  - Vacío vuelve a lo del giro.
- **La web adapta sus textos al vuelo** en lugar de reescribirlos uno por uno. Al
  cambiar el negocio en sesión, `aplicarTerminologia` recalcula los mensajes de
  vue-i18n desde los textos base:
  - "clase(s)" pasa al término del negocio;
  - "alumno(s)" y "miembro(s)" también, salvo "miembro del equipo";
  - "alumno" no cambia si el término es femenino (Alumna);
  - "alumna" solo pasa a términos de género común;
  - "instructor(es)" pasa al término del negocio;
  - respeta mayúsculas y plural, no toca `{marcadores}` y colapsa "citas o citas".

  Los mensajes de error de la API pasan por la misma adaptación.
- **No se adaptan** las páginas públicas y comerciales ni el cobro del SaaS: hablan de
  "clases y citas" en general.
- **App móvil**: sus pocos textos usan la terminología de la sesión (con plurales).

## Consecuencias

- Una barbería ve "Citas", "Clientes" y "Barberos" en menús, agenda, recepción,
  reportes y Mi cuenta. Un texto nuevo con "clase" se adapta solo.
- Las plantillas de correo y push ya eran neutras (`{{actividad}}` es el nombre del
  servicio).
- Las reglas son deliberadamente simples. Un texto con otra construcción ("clases
  grupales") se lee en el término del negocio ("citas grupales").
- La app aún muestra los mensajes de error de la API sin adaptar.
