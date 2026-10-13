# Dirección visual del sistema y landings

## Alcance y criterio

La referencia proporcionada se usa como dirección visual, no como una copia de otro producto: paneles amplios, superficies suaves, tipografía legible, acentos contenidos y datos con jerarquía. Se conserva el layout actualizado del usuario y las funciones existentes.

No se modificaron endpoints, reglas de negocio, autorizaciones, precios, textos comerciales ni metadatos SEO. No se agregaron gráficas con datos inventados al panel. Esta revisión es de la aplicación web y sus landings; no modifica las aplicaciones Flutter.

## Cambios implementados

### Inicio administrativo

- El bloque de la siguiente clase/cita tiene fondo suave, esquinas jerarquizadas y fotografía enmarcada. Conserva información, clima y accesos.
- Resumen circular con datos existentes: en clases, reservas esperadas respecto al cupo; en citas, citas por atender respecto al total del día. No es una tendencia, una puntuación ni un objetivo de ventas. Se muestra sólo si existe un denominador válido.
- Indicadores amplios como tarjetas individuales. Los que requieren atención mantienen el aviso tanto en el texto como en su acento suave; no dependen únicamente del color.
- La agenda del día se muestra en páginas de seis registros. Los filtros y la búsqueda reinician la página; todos los registros siguen disponibles. Se conserva el enlace a cada reserva y a la agenda completa.
- Los accesos operativos tienen más espacio para el texto, ilustraciones que no ocupan su zona y flechas menos dominantes. A anchos intermedios se muestran en dos columnas.

### Componentes compartidos

- Superficies privadas: radios consistentes, bordes discretos y sombra leve vinculada al tema; no se impone una paleta distinta al usuario.
- Tablas: cabecera más distinguible y mayor separación de filas. El foco dentro de una fila resalta su contexto.
- Indicadores: variante amplia visual y variante compacta conservada para pantallas operativas que necesitan espacio para el calendario.
- Tarjeta principal: misma dirección visual en el inicio del dueño, cliente/miembro y profesional.
- Estados vacíos: icono en una placa suave, manteniendo instrucciones y acciones reales.
- Movimiento reducido: las tarjetas operativas no se desplazan al pasar el cursor cuando el usuario solicita reducir movimiento.

### AgendaUno y TurnoUno

El último acabado editorial se **revirtió a petición del usuario**: se eliminó la hoja de estilo específica de las landings y su importación, y se restauró el fondo anterior del collage. Ya no se aplican los nuevos marcos del hero/cierre, las modificaciones de las tarjetas de funciones/pasos ni el acabado translúcido de navegación de esa intervención.

Se preservan los cambios previos del usuario en contenido, precios, navegación comercial y SEO; AgendaUno conserva el CTA rosa y TurnoUno el azul/verde. No se revirtió la administración ni se restauraron archivos completos desde Git.

### Agenda pastel y tema activo

- Paleta común más suave para servicios y clases, con asignación estable por catálogo. Los estados de atención y pago siguen separados del color del servicio.
- Fondo pastel mezclado con la superficie del tema en las vistas por profesional, semana, mes y listado móvil. En oscuro se reduce su proporción para conservar una superficie oscura con texto legible.
- El mes conserva el acento del tema para reservas propias; selección y foco tienen un contorno explícito. No se modifican cupos, horarios, filtros ni acciones de reserva.
- En indicadores y accesos operativos, el acento general usa el primario del tema. Éxito y advertencia usan sus variables semánticas; los otros colores siguen identificando categorías sobre superficies y textos del tema.
- Los controles de fecha, lugar y hora actual de clases usan el contraste del primario del tema en vez de asumir texto blanco; el contorno de clase en curso usa la variable semántica de error.
- Comprobación adicional mediante computer-use: agenda de citas en claro y Medianoche (profesional, semana y mes), listado móvil claro a 390 px sin desbordamiento de página; panel en Esmeralda y Medianoche. Se restauró el tema predeterminado al terminar. Esta comprobación no certifica todas las combinaciones de temas personalizados ni todos los flujos de ambas modalidades.

## Comprobación real

Se usó computer-use para observar pantallas reales, sin realizar reservas, compras, borrados, publicaciones ni cambios de permisos. Se alternaron los roles disponibles de la cuenta de demostración para revisar cada contexto y se restauró el rol de dueño. El tema Medianoche también se restauró al predeterminado después de comprobarlo.

| Contexto | Pantallas comprobadas |
| --- | --- |
| Administración, escritorio | Inicio, agenda, clientes, cobranza, equipo, reportes, configuración, planes, requisitos, fichas, sucursales, nómina, lealtad. |
| Administración, teléfono a 390 px | Inicio, agenda, clientes, cobranza, reportes, configuración y fichas. Inicio también a 768 px: resumen compacto sin desbordamiento. |
| Cliente, teléfono a 390 px | Inicio, citas, pagos, expediente y perfil. |
| Profesional, teléfono a 390 px | Inicio, calendario, tareas, clientes y reseñas. |
| Comercial | Hero de AgendaUno y de TurnoUno; TurnoUno también en teléfono. |
| Tema oscuro | Inicio administrativo en Medianoche. |

No se detectó desbordamiento horizontal de página en las pantallas medidas. Una tabla/calendario puede conservar su desplazamiento interno intencional. Esto no significa que se hayan ejecutado todos los procesos de cada pantalla ni todas las combinaciones de temas, permisos y datos.

Pruebas seleccionadas: 139 aprobadas en 11 archivos, incluyendo la nueva prueba del paginado/filtro del inicio. Tipado y lint aprobados. Compilación de la aplicación y ambas landings aprobada; validación de SEO para siete páginas por marca aprobada, incluyendo HTML legible sin JavaScript. No se publicó la aplicación.

Después de revertir el acabado comercial y ajustar los pasteles: 97 pruebas seleccionadas aprobadas en 12 archivos (agenda, indicadores, resumen, tema y componentes comerciales). Tipado y lint aprobados; compilación de la aplicación y ambas landings aprobada. Se repitió la validación de SEO para las siete páginas de cada marca, sin incidencias. Captura actual: `agenda-pastel.jpg` en la carpeta de evidencias del chat; las capturas anteriores con marco editorial comercial ya no representan el diseño vigente de las landings.

## Oportunidades siguientes

1. **Nómina y canjes en móvil:** convertir sus tablas específicas en fichas donde no se necesite comparar columnas. El acabado compartido no reemplaza automáticamente cada tabla especializada.
2. **Reportes:** priorizar qué requiere una acción antes de añadir más gráficas; cada visual debe indicar periodo, unidad y origen de la cifra.
3. **Agenda:** conservar controles compactos. No trasladar las tarjetas grandes del inicio a la barra superior del calendario.
4. **Formularios largos:** seguir separando altas cortas en modal y editores extensos en página. El nuevo diseño no sustituye validación, prevención de pérdida de borradores ni estados de error.
5. **Cobertura pendiente:** revisar variantes opcionales (familias, grupos, roles personalizados), datos extremadamente largos y temas personalizados. No considerar esta intervención una certificación completa de funcionamiento ni de producción.
