# ADR 0229 — Reglas de navegación y acciones del panel

Estado: Aceptado (2026-10-26). Amplía el contrato de interfaz del panel (ADR 0203).

## Contexto

La revisión de interfaz para la V1 encontró que el ADR 0203 fija las piezas (pestañas,
botones, pie fijo), pero no dice con la misma claridad cuándo usar cada una. Había casos
sueltos: un botón con borde para ir a otra vista del área, un alta cuyo error salía en
un aviso flotante que desaparece, e interruptores o botones deshabilitados sin decir
por qué.

La misma revisión pidió tres arreglos del teléfono que siguen estas reglas:

- En «Nueva clase», las tres formas de cargar horarios no cabían a lo ancho. El
  segmentado escondía la tercera en un desplazamiento horizontal sin barra.
- La tira de días de la agenda solo se centraba en el día elegido al entrar. Al volver
  de la vista de mes o al girar el teléfono, el día podía quedar fuera de la vista.
- El menú lateral cerrado seguía en el orden del teclado y para los lectores de
  pantalla, aunque estuviera fuera de la pantalla.

## Decisión

### Las reglas

1. **La navegación del área va debajo del encabezado.** Es la fila de `PestanasArea`,
   que sale de `lib/menu.ts`. Ni las acciones del encabezado ni el contenido repiten las
   vistas del área. Si hace falta un atajo a otra vista, es un enlace (regla 4).
2. **Pestañas internas solo para un segundo nivel real.** `PestanasSeccion` +
   `usePestanaEnUrl` (`?pestana=`) se usan solo cuando la pantalla tiene secciones
   distintas. Un filtro o un orden no es una pestaña: va con `useFiltrosEnUrl` junto a
   lo que filtra. Con una sola opción no hay pestañas. Un diálogo usa `.tu-pestanas` en
   memoria.
3. **Un botón primario para la acción principal.** Cada pantalla o zona tiene un solo
   `tu-btn-primario`: crear, guardar, cobrar o confirmar. Lo demás va como secundario o
   discreto (ADR 0203). En el teléfono, la principal se queda a la vista.
4. **Enlaces para la navegación secundaria.** Ir a otra pantalla o a otra vista es
   `RouterLink` con `tu-enlace`, como «Reglas de reserva» en la Agenda. Un botón con
   borde se reserva para una acción. Las excepciones son la llamada a la acción de un
   estado vacío o de un aviso (`EstadoVacio`, `AvisoTono #acciones`), donde llevarte a
   resolverlo es la acción.
5. **Los errores van junto al formulario y conservan lo que se escribió.** Lo que
   impide guardar se dice dentro del formulario o del diálogo, en un `AvisoTono` con
   `tono="peligro"` y `role="alert"`, o junto al campo. Los campos no se vacían ni el
   diálogo se cierra, así que se corrige y se reintenta. El aviso flotante (`toast`) es
   para lo que pasa fuera de un formulario, o para lo que falla después de guardar
   cuando el formulario ya se cerró.
6. **Un estado deshabilitado explica por qué.** Si el motivo no es obvio, como falta de
   permiso, falta un dato previo o no hay nada que exportar, el control lleva un `title`
   con el motivo y, de preferencia, un texto visible con `aria-describedby`. «Guardando»
   ya se explica con `aria-busy` y el aro del botón (ADR 0203).

### En el teléfono

- **Opciones que no caben a lo ancho**: `tu-segmentado-apilado` (en `style.css`) junto a
  `tu-segmentado`. Por debajo de 640 px, las opciones van una debajo de otra, a todo el
  ancho y sin desplazamiento horizontal. En pantallas más anchas, el segmentado se ve
  igual que siempre. No se cambió `tu-segmentado` para todos: los cortos (Semana | Día)
  caben y se ven mejor en una fila. Se usa en «Nueva clase».
- **Tiras con desplazamiento a lo ancho**: `centrarEnTira()` (en `lib/pantalla.ts`) deja
  el elemento activo al centro y solo mueve la tira. `scrollIntoView` también movería la
  página, así que no se usa. Con «reducir movimiento» no hay animación. La agenda la
  llama al entrar, al volver de la vista de mes, al cambiar de día o de semana y al
  cambiar el tamaño de la ventana. Solo anima al cambiar de día o de semana.
- **Menú lateral (cajón)**: en el teléfono, cerrado lleva `inert`, así que ni Tab ni un
  lector de pantalla llegan a él. Abierto usa `useFocoPanel`, como los paneles:
  - el foco entra en el cajón;
  - Tab no sale de él;
  - Escape lo cierra;
  - al cerrarse, el foco vuelve al botón del menú, que lleva `aria-expanded`.

  En escritorio el lateral nunca es `inert`. Si la ventana crece a escritorio con el
  cajón abierto, se da por cerrado.

### Casos corregidos ahora

- **Recepción** (`FrontDeskView`): «Abrir agenda» era un botón con borde en el
  encabezado y repetía la vista Calendario del área. Ahora es un enlace (reglas 1 y 4).
- **Prospectos › Agregar prospecto** (`ProspectosView`): «Escribe su correo o su
  teléfono» y el rechazo del servidor salían en un aviso flotante. Ahora van dentro del
  formulario y lo escrito se queda. Si falla algo después de agregarlo, con el panel ya
  cerrado, sigue el aviso flotante (regla 5).
- **Formularios de captación › Recibir prospectos** (`FormulariosCaptacionView`): el
  interruptor se veía apagado sin decir por qué a quien no tiene `estudio.gestionar`.
  Ahora lo dice en un texto (con `aria-describedby`) y en su `title` (regla 6).
- **Nómina › Exportar CSV** (`NominaView`): ahora dice en su `title` que nadie del
  equipo tiene esquema de pago (regla 6).

### Pendientes (encontrados con búsquedas, sin rediseñar)

Cada uno se revisa uno por uno. Algunos pueden tener ya el error visible por otro lado.

- **Regla 5**: formularios cuyo envío reporta el error solo con `toast.error`:
  - Vistas:
    - `BandejaView` (`enviar`, `elegirPagina`);
    - `FichaMiembroView` (`pausar`, `quitarCreditos`, `recargar`);
    - `FormulariosCaptacionView` (`guardar`);
    - `MiPerfilView` (`guardarDatos`, `pedirCambioCorreo`, `cambiarClave`);
    - `PresupuestosView` (`guardarAjustes`);
    - `PrivacidadView` (`rechazar`);
    - `ProspectosView` (`darAcceso`);
    - `RegionNegocioView` (`guardar`);
    - `ReglasAgendaView` (`guardarPolitica`, `agregarDia`);
    - `VideosView` (`guardar`);
    - `PlataformaView` (`guardarLlave`, `guardarCorreoAlertas`, `guardarLegales`,
      `guardarPaisesBienestar`).
  - Componentes:
    - `CorregirCobro` (`corregirMetodo`, `anular`);
    - `DatosFacturacionCliente`;
    - `ExpedientePersona` (`subir`);
    - `HistorialReservas` (`enviarResena`);
    - `ListaFormularios`;
    - `ModalMiembro` (`guardarNota`);
    - `PanelNuevaCita` (`agendar`);
    - `WhatsAppRenta` (`comprar`).
  - Del superadmin:
    - `AltaNegocioPlataforma`;
    - `CargoManualPlataforma`;
    - `ComercialPlataforma`;
    - `ConciliacionPlataformas`;
    - `ContactoDuenoPlataforma`;
    - `DestinoMedicion`;
    - `EquipoPlataforma`;
    - `HorarioDemosPlataforma`;
    - `PlanEstudioPlataforma`;
    - `TarifasPlataforma`;
    - `WhatsAppPlataforma`.
- **Regla 6**: `TimbresView` deshabilita el paquete a quien no tiene `estudio.gestionar`
  sin decir por qué.
- **Regla 4**: navegación con aspecto de botón:
  - «Administrar acceso» en `FichaInstructorView`;
  - «Ver ficha» en las acciones de una conversación de `BandejaView`.

  Hay que decidir si cuentan como «configurar» (botón secundario, ADR 0203) o como ir a
  otra pantalla (enlace).
- **Reglas 1 a 3**: las búsquedas no encontraron selectores de vista en las acciones del
  encabezado, encabezados con dos primarios ni pestañas de un solo nivel fuera de
  `PestanasSeccion`. Las pestañas del detalle de una clase (diálogo) y la barra de
  estado de Sitio web (ADR 0167) son excepciones ya decididas.

## Consecuencias

- Una pantalla nueva sigue estas reglas además del ADR 0203. Su revisión las usa como
  lista de comprobación.
- Las pruebas que vigilan estos casos:
  - `src/menuMovil.spec.ts`: cajón `inert` y foco;
  - `src/views/AgendaTelefono.spec.ts`: tira de días y «Nueva clase» apilada;
  - `src/lib/pantalla.spec.ts`: `centrarEnTira`;
  - `ProspectosView.spec.ts`, `FormulariosCaptacionView.spec.ts` y `NominaView.spec.ts`:
    error en el formulario y motivo de lo deshabilitado.

  Los contratos del ADR 0203 (`botones.spec.ts`, `vocabularioPanel.spec.ts`,
  `llavesLiterales.spec.ts`) siguen igual.
- Los pendientes se corrigen al tocar cada pantalla. No se hace un rediseño aparte.
