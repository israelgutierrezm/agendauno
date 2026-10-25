// Listados con el patrón de Nómina y Horarios: indicadores, filtros, lista y lateral.
export default {
  resenas: {
    subtitulo:
      "Lo que opinan tus alumnos de cada servicio y de cada profesional.",
    subtituloClases:
      "Lo que opinan tus {miembros} de cada clase y de cada {instructor}.",
    promedio: "Calificación promedio",
    total: "Reseñas",
    cinco: "Con 5 estrellas",
    conComentario: "Con comentario",
    calificacion: "Calificación",
    filtros: {
      todas: "Todas",
      5: "5 estrellas",
      4: "4 estrellas",
      3: "3 o menos",
    },
    profesional: "Profesional",
    todosProfesionales: "Todos",
    buscar: "Buscar alumno o comentario",
    oculta: "Oculta del público",
    sinProfesionales: "Aún no hay reseñas de un instructor.",
    deCinco: "{n} de 5",
  },
  catalogo: {
    subtitulo: "Lo que ofreces: duración, precio o lugares, y cómo se reserva.",
    subtituloCitas: "Lo que ofreces: duración, precio y cómo se agenda.",
    nuevoServicio: "Nuevo servicio",
    nuevaClase: "Nueva clase",
    servicio: "Servicio",
    servicioClases: "Clase",
    buscar: "Buscar servicio o actividad",
    buscarClases: "Buscar clase o actividad",
    servicioGuardado: "«{nombre}» quedó guardado.",
    altaGuardada: "Se agregó 1 al catálogo. | Se agregaron {n} al catálogo.",
    sinResultados: "Ningún servicio coincide con la búsqueda.",
    sinResultadosClases: "Ninguna clase coincide con la búsqueda.",
    duracion: "Duración",
    precio: "Precio",
    cupo: "Lugares",
    reserva: "Se reserva",
    nombre: "Nombre",
    minutos: "{n} min",
    lugares: "{n} lugares",
    sinDescripcion: "Sin descripción para tu página",
    altaAyuda:
      "Uno por línea: nombre, cuánto dura y su precio (o cuántos lugares tiene). Lo demás, al configurarlo.",
    quitar: "Quitar",
    otraFila: "Agregar otro",
    faltaPrecio: "Escribe su precio (mayor a 0).",
    cupoInvalido: "Escribe cuántos lugares tiene (un número entero, 1 o más).",
    filasIncompletas:
      "Completa o quita las filas marcadas: así como están no se pueden guardar.",
    deTotal: "{n} de {total}",
    // Corregir y archivar (ADR 0154).
    nombreRequerido: "Escribe su nombre.",
    cupoBase: "Lugares por clase",
    cupoBaseAyuda:
      "Para lo que agendes desde ahora; las fechas ya agendadas conservan los suyos.",
    cupoBaseInvalido: "Un número entero de 1 a {max}, o déjalo vacío.",
    enCatalogo: "En catálogo ({n})",
    archivados: "Archivados ({n})",
    archivar: "Archivar del catálogo",
    confirmarArchivar:
      "¿Archivar «{nombre}»? Ya no se podrá agendar, vender ni aparecerá en tu página. Lo ya agendado sigue y su historial se conserva.",
    archivadoListo: "Se archivó «{nombre}».",
    archivadoConFechas:
      "Se archivó «{nombre}». Sigue en pie 1 fecha ya agendada. | Se archivó «{nombre}». Siguen en pie {n} fechas ya agendadas.",
    restaurar: "Volver a ofrecer",
    restaurado: "«{nombre}» se ofrece de nuevo.",
    // <summary> de las opciones avanzadas del editor (`tu-avanzado`), con cuántas hay.
    masCondiciones:
      "Más condiciones | Más condiciones · 1 activa | Más condiciones · {n} activas",
    kpi: {
      servicios: "Servicios",
      clases: "Clases",
      precioPromedio: "Precio promedio",
      cupoPromedio: "Lugares por clase",
      conFoto: "Con foto",
      conDescripcion: "Con descripción",
    },
  },
};
