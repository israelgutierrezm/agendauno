// Nómina (views/NominaView.vue): esquemas, periodo, indicadores y resumen.
export default {
  subtitulo:
    "Define cómo se le paga a cada quien del equipo y calcula lo que le toca en un periodo.",
  esquemasAyuda: "Define cómo se le paga a cada quien del equipo.",
  quien: "Miembro del equipo",
  elegir: "Elige a alguien",
  tipo: "Tipo de esquema",
  porSesion: "Por {sesion}",
  ayudaMonto: {
    por_clase: "Lo que se le paga por cada {sesion} que atiende.",
    por_asistente: "Lo que se le paga por cada persona que llega.",
    por_hora: "Lo que se le paga por cada hora agendada.",
  },
  periodoAyuda: "Selecciona el rango de fechas para calcular la nómina.",
  resumen: "Resumen del periodo",
  resumenAyuda:
    "Devengado: lo ya impartido, con la tarifa vigente ese día. Por venir: lo que falta del periodo, solo como proyección.",
  periodoDe: "Periodo: {desde} – {hasta}",
  exportar: "Exportar CSV",
  colMiembro: "Integrante",
  colEsquema: "Esquema",
  colCantidad: "Cantidad",
  colTotal: "Devengado",
  colPorVenir: "Por venir",
  tarifaDesde: "Tarifa nueva desde el {fecha}",
  esquemaGuardado: "Esquema de pago de {nombre} guardado.",
  cambioDesdeHoy:
    "Un cambio vale desde hoy: lo ya trabajado se sigue pagando con la tarifa de entonces.",
  colEstado: "Estado",
  calculado: "Calculado",
  sinEsquema: "Sin esquema",
  editarEsquema: "Editar esquema",
  definirEsquema: "Definir esquema",
  unidades: {
    asistentes: "1 asistente | {n} asistentes",
    horas: "1 hora | {n} horas",
  },
  kpi: {
    conEsquema: "Con esquema de pago",
    conEsquemaValor: "{n} de {total}",
    sinEsquema: "Sin esquema (no entran en la nómina)",
    total: "Devengado a la fecha",
    alCierre: "Proyección al cierre del periodo",
  },
  vacio: "Aún no hay nadie del equipo con acceso al panel.",
  sinCalcular: "Calcula el periodo para ver lo que le toca a cada quien.",
};
