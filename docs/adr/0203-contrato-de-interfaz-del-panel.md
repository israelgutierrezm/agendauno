# ADR 0203 — Contrato de interfaz del panel

Estado: Aceptado (2026-10-23).

## Contexto

Una revisión del panel encontró varios patrones para lo mismo:

- Las pestañas vivían en lugares distintos: debajo del encabezado, dentro del contenido
  o junto a las acciones del encabezado. Casi todas guardaban la selección solo en
  memoria; en Reportes, recargar volvía a «Resumen».
- Acciones como Editar, Quitar o «Dividir el pago» se veían como enlace en unas
  pantallas y como botón con borde en otras. Había varias familias de botones con
  alturas y focos distintos.
- En el teléfono, listados como Planes pedían desplazar a lo ancho y escondían la
  acción.
- Editores largos dejaban Guardar y Cancelar al final de lo que se desplaza.
- Algunas pantallas tenían ícono en el título y otras no. Había títulos repetidos, como
  en Corte de caja.
- El mismo público se llamaba alumnos, clientes o «personas con perfil de miembro».
  Había mensajes de cobro escritos para el cliente que se mostraban al administrador.

## Decisión

Un contrato por tema, con piezas compartidas y pruebas que lo vigilan.

### Navegación

- La navegación principal de un área es la fila de `PestanasArea`. Sale de
  `lib/menu.ts`: cada vista es su ruta más `?vista=`. Reportes pasó ahí sus secciones.
- El segundo nivel real es `PestanasSeccion` + `usePestanaEnUrl`, con `?pestana=` en la
  URL. Va justo bajo el encabezado, con la carga en un `watch` inmediato. La clave
  `vista` es solo del menú, y `ubicacion()` tolera un `?vista` ajeno.
- Los filtros van junto a lo que filtran y quedan en la URL con `useFiltrosEnUrl`.
- Nunca va un selector de vista en las acciones del encabezado.
- Configuración conserva su navegación lateral.
- Sitio web conserva su barra de estado arriba (ADR 0167).

### Botones

- `tu-btn` tiene una sola altura, letra, foco y estado deshabilitado. Al guardar
  muestra un aro con `aria-busy`.
- Hay cinco variantes:

  | Variante | Para qué |
  | --- | --- |
  | primario | guardar, crear, confirmar, cobrar |
  | secundario (con borde) | editar, configurar, vista previa, dividir el pago y Cancelar de un formulario |
  | discreto | lo auxiliar |
  | peligro | eliminar, cancelar una reserva, revocar, archivar, quitar algo guardado |
  | sólido de peligro | la confirmación final de una acción de peligro |

- `tu-btn-chico` se usa en filas y avisos.
- `tu-enlace` solo sirve para ir a otra pantalla, abrir información o un botón dentro
  de una frase.
- Se retiraron `fantasma`, `contorno`, `suave`, `mínimo` e `ícono`, y el `text-sm`
  encima de un botón.

### Listados en el teléfono

- `tu-tabla-adaptable` convierte la tabla en fichas por debajo de 640 px. La primera
  celda es el título, cada celda lleva `data-etiqueta` y `tu-celda-acciones` deja la
  acción siempre a la vista.
- Las tablas comparativas siguen con desplazamiento: reportes, conteos de caja y el
  libro de timbres.

### Formularios

- Guardar y Cancelar van en el pie fijo del diálogo o del panel. El botón es `submit` y
  lleva el `form=` de su formulario.
- Un formulario en página lleva `tu-pie-acciones`.
- Lo avanzado va plegado en `tu-avanzado`.
- Se conserva la advertencia de cambios sin guardar.
- `EstadoEditor` dice si el editor es `valido`.

### Títulos

- `EncabezadoSeccion` es el único h1 y no lleva ícono. El subtítulo va en su prop.
- Un componente dentro de otra pantalla no repite su título.
- Las secciones usan `tu-titulo-seccion`.

### Vocabulario

- En el panel, el público se escribe «alumno»: el adaptador de terminología lo
  convierte al término del negocio.
- Cada mensaje le habla a quien lo lee.
- Las fechas de cobro usan `EstadoVencimiento`: próxima, hoy, vencida, en mora,
  suspendida o en pausa.

### Cobranza

- El motivo de mora se guarda con clave (`MotivoFalloCobro`, migración de negocio
  000196). El aviso al alumno sigue con su frase y el panel muestra un texto para el
  administrador.
- Las renovaciones traen `situacion` y `dias`, con filtro y resumen.
- Por cobrar dice cuándo vence una renovación.

### Pruebas que vigilan el contrato

- `src/botones.spec.ts`: variantes retiradas, tamaño encima de un botón y enlaces que
  cambian datos.
- `src/i18n/vocabularioPanel.spec.ts`: «cliente» o «persona con perfil» para el
  público del panel, con una lista blanca explícita.
- `src/i18n/llavesLiterales.spec.ts`: toda llave literal de i18n existe.

## Consecuencias

- Recargar o compartir un enlace abre la misma pestaña y los mismos filtros.
- Una pantalla nueva sigue el contrato o falla una prueba.
- Decidido después por el dueño:
  - los cierres de caja son una entrada aparte del área de Cobros (ADR 0175, ajuste del
    2026-10-23);
  - la renta del SaaS usa la palabra del negocio;
  - Configuración conserva su encabezado compacto;
  - «Reabrir periodo» de nómina es una acción de peligro.
- Queda abierto: si el superadmin agrupa sus 12 pestañas en una navegación lateral.
