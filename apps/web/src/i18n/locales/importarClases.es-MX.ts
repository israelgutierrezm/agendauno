export default {
  titulo: "Importar clases",
  subtitulo:
    "Carga fechas concretas o arma una programación semanal. Revisa todo antes de añadirlo a la agenda.",
  volver: "Volver a la agenda",
  fechas: "Fechas específicas",
  fechasAyuda:
    "Una fila por sesión. Ideal para un mes con sustituciones, clases especiales o suspensiones.",
  semanal: "Programación semanal",
  semanalAyuda:
    "Una fila por clase, día y horario. Se repite entre las fechas que indiques y queda como serie editable.",
  preparar: "1. Prepara tu archivo",
  plantilla: "Descargar layout CSV",
  catalogos: "Ver nombres e identificadores disponibles",
  catalogoAyuda:
    "Copia el nombre exacto o el identificador. Si dos registros tienen el mismo nombre, usa su identificador. No se crean clases ni instructores nuevos al importar.",
  formato:
    "Abre la plantilla en Excel o en tu hoja de cálculo y guárdala como CSV UTF-8 (coma o punto y coma). La descarga contiene encabezados; agrega tus filas.",
  reglas:
    "Usa fechas AAAA-MM-DD y horas de 24 h (09:00, 18:30). El fin debe ser posterior al inicio, el mismo día. Cada sucursal usa su zona horaria configurada.",
  referencia:
    "Referencia: una clave única por fila (por ejemplo OCT-001). Consérvala al volver a subir el archivo: lo ya importado se omite y nunca se sobrescribe.",
  opcionales:
    "Instructor y sala pueden quedar vacíos. Si omites el cupo, se toma el configurado en la clase. No se importan precios ni créditos: se conservan las reglas del catálogo.",
  reglaFechas:
    "Estado opcional: programada o suspendida. Una sesión suspendida se crea cancelada, sin aceptar reservas.",
  reglaSemanal:
    "Día: lunes a domingo (también 1 a 7). Una fila por día; desde y hasta son inclusivos, con un máximo de un año. Los cierres configurados se excluyen y se muestran en la revisión.",
  limite:
    "Hasta {n} sesiones por carga, incluyendo las generadas por repetición.",
  ejemplo: "Ejemplo de una fila",
  subir: "2. Sube y revisa",
  elegir: "Seleccionar archivo CSV",
  revisar: "Revisar archivo",
  analizando: "Revisando fechas, catálogos y disponibilidad…",
  sinCatalogo:
    "Necesitas al menos una clase y una sucursal configuradas antes de importar.",
  resultado: "3. Confirma la programación",
  filas: "Filas leídas",
  sesiones: "Sesiones nuevas",
  errores: "Filas con errores",
  omitidas: "Ya importadas",
  corrige:
    "No se creará nada mientras haya errores. Corrige el archivo y vuelve a revisarlo.",
  seguro:
    "La importación es completa o no se aplica: no modifica sesiones existentes, reservas ni pagos. La disponibilidad se comprueba de nuevo al confirmar.",
  fila: "Fila",
  clase: "Clase",
  sucursal: "Sucursal",
  instructor: "Instructor",
  sala: "Sala",
  horario: "Fechas y horario",
  revision: "Revisión",
  lista: "Lista para importar",
  omitida: "Se conserva la existente",
  sinInstructor: "Sin instructor",
  fechasGeneradas: "Ver {n} fechas",
  confirmar: "Importar {n} sesiones",
  importando: "Importando…",
  creada: "Se importaron {n} sesiones",
  lote: "Lote: {id}",
  otra: "Importar otro archivo",
  sinNuevas: "Todas las filas ya están importadas. No se crearán duplicados.",
  cambios:
    "La agenda cambió o hay filas que revisar. No se importó ninguna sesión. Revisa el resultado y vuelve a validar el archivo.",
  id: "Identificador",
  nombre: "Nombre",
  cancelada: "Suspendida",
};
