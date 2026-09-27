/**
 * Textos de los ajustes del panel (revisión del admin): reportes, horarios,
 * renovaciones, recepción y comunicación. Aparte de es-MX.ts (la landing en curso).
 */
export default {
  confirmar: {
    titulo: "Confirmar",
    aceptar: "Confirmar",
    cancelar: "Cancelar",
  },
  // Inicio del negocio: el día de hoy.
  hoy: {
    titulo: "Hoy",
    indicadores: {
      sesiones: "Clases",
      esperados: "Se esperan",
      llegaron: "Llegaron",
      sinMarcar: "Por pasar lista",
    },
    agenda: "Agenda de hoy",
    verAgenda: "Ver agenda →",
    sinSesiones: "No hay clases hoy.",
    cupo: "{n} de {total}",
    pasarLista:
      "Falta pasar lista a 1 persona | Falta pasar lista a {n} personas",
    momento: {
      proxima: "Próxima",
      en_curso: "En curso",
      termino: "Terminó",
      cancelada: "Cancelada",
    },
    pendientes: "Pendientes",
    alDia: "Todo al día: nada por cobrar ni renovaciones por atender.",
    porCobrar: "1 orden por cobrar | {n} órdenes por cobrar",
    enMora: "1 cuenta en mora | {n} cuentas en mora",
    porVencer:
      "1 membresía vence en {dias} días o menos | {n} membresías vencen en {dias} días o menos",
    vencidas:
      "1 membresía vencida por recuperar | {n} membresías vencidas por recuperar",
  },
  // Columnas de la lista de alumnos o clientes: un dato por columna.
  clientes: {
    registro: "Registro",
    app: "App",
    conApp: "Con acceso",
    sinApp: "Sin cuenta",
    plan: "Plan vigente",
    saldo: "Saldo",
    proxima: "Próxima reserva",
    estado: "Estado",
  },
  // Grupos y rótulos del menú lateral que no son el título de una pantalla.
  menu: {
    directorio: "Directorio",
    equipo: "Equipo",
    marketing: "Marketing",
    negocio: "Datos del negocio",
  },
  sedes: {
    ubicacion: "Ubicación (para el clima de tus alumnos)",
    ubicacionPh: "19.4194, -99.1617",
    ubicacionAyuda:
      "Pega las coordenadas desde Google Maps (clic derecho sobre el lugar y copia los números) o usa tu ubicación si estás en la sucursal.",
    usarMiUbicacion: "Usar mi ubicación actual",
    ubicando: "Ubicando…",
    verMapa: "Ver en el mapa →",
    invalida:
      "Escribe la latitud y la longitud separadas por una coma (p. ej. 19.4194, -99.1617).",
    sinPermiso: "No pudimos obtener tu ubicación desde este dispositivo.",
  },
  reportes: {
    sinSucursal: "Sin sucursal asignada",
    total: "Total",
    estadoActual: "Estado actual: no depende del periodo elegido.",
    periodo: "Cifras del {desde} al {hasta}.",
    sinClientes:
      "Aún no hay altas suficientes para medir conversión y permanencia.",
    pestanas: {
      resumen: "Resumen",
      ingresos: "Ingresos",
      ocupacion: "Ocupación",
      clientes: "Clientes",
      equipo: "Equipo y sucursales",
    },
  },
  horarios: {
    noSeCargo:
      "No se pudo cargar el horario de esta persona. Reintenta antes de editarlo.",
    descartar:
      "Tienes cambios sin guardar en este horario. ¿Descartarlos y cambiar de selección?",
  },
  renovaciones: {
    titulo: "Renovaciones",
    subtitulo:
      "Membresías y paquetes por vencer o recién vencidos, para contactar a tiempo.",
    vacio: "No hay membresías por vencer ni recién vencidas.",
    errorCarga:
      "No pudimos consultar las renovaciones. Intenta de nuevo en un momento.",
  },
  recepcion: {
    sinActividad: "No hay clases ni citas programadas este día.",
    sinActividadFiltros:
      "No hay clases ni citas este día en la sucursal elegida. Prueba con todas las sucursales.",
    irAgenda: "Ir a la agenda →",
  },
  comunicacion: {
    revisar: "Revisar y enviar",
    confirmarTitulo: "Confirmar envío",
    destinatarios: "Se enviará a 1 persona | Se enviará a {n} personas",
    canal: "Canal: {canal}",
    vistaPrevia: "Vista previa (con datos de ejemplo)",
    enviar: "Enviar ahora",
    cancelar: "Cancelar",
    sinDestinatarios: "Nadie cumple con este segmento: no hay a quién enviar.",
  },
};
