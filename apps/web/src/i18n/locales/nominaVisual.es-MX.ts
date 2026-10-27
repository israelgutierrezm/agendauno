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
    porcentaje_venta:
      "El porcentaje de lo que se cobra por cada {sesion} suya (sin la propina).",
  },
  // Comisión en porcentaje y propinas (ADR 0219).
  porcentaje: "Porcentaje",
  porcentajeVenta: "Porcentaje de lo cobrado",
  porcentajeDe: "{n} % de lo cobrado",
  propinas: "Propinas",
  conPropinas: "+ {monto} de propinas",
  periodoAyuda: "Selecciona el rango de fechas para calcular la nómina.",
  resumen: "Resumen del periodo",
  resumenAyuda:
    "Devengado: lo ya impartido, con la tarifa vigente ese día. Por venir: lo que falta del periodo, solo como proyección.",
  periodoDe: "Periodo: {desde} – {hasta}",
  exportar: "Exportar CSV",
  // «Exportar» deshabilitado dice por qué (ADR 0229).
  exportarSinEsquema:
    "Nadie del equipo tiene esquema de pago: define uno para exportar la nómina.",
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
  errorCalculo: "No se pudo calcular el periodo.",
  // Cortes: cerrar un periodo, registrar su pago y ajustar lo que cambie después.
  cortes: {
    titulo: "Periodos cerrados",
    ayuda:
      "Al cerrar un periodo, lo devengado queda fijo. Después registra el pago con su referencia; lo que cambie luego se ajusta aparte.",
    cerrar: "Cerrar este periodo",
    cerrando: "Cerrando…",
    soloTerminado:
      "Solo se cierra un periodo que ya terminó (hasta ayer o antes).",
    yaCerrado: "Este periodo se encima con uno ya cerrado.",
    confirmarCerrar:
      "Cerrar la nómina del {desde} al {hasta} por {total}. Lo devengado queda fijo; podrás reabrirla mientras no registres el pago.",
    cerrado: "Periodo cerrado.",
    cargando: "Cargando periodos…",
    errorCarga: "No se pudieron cargar los periodos cerrados.",
    vacio: "Aún no cierras ningún periodo.",
    colPeriodo: "Periodo",
    colEstado: "Estado",
    colTotal: "Total",
    colCerrado: "Cerrado por",
    colPago: "Pago",
    ver: "Ver",
    estados: { cerrado: "Cerrado, sin pagar", pagado: "Pagado" },
    conAjustes: "{total} con ajustes",
    del: "Del {desde} al {hasta}",
    cerradoPor: "Lo cerró {nombre} el {fecha}",
    pagadoPor: "{nombre} registró el pago el {fecha}",
    referencia: "Referencia",
    lineas: "Lo que quedó por persona",
    ajustes: "Ajustes después del pago",
    sinAjustes: "Sin ajustes.",
    diferencias: "Desde el cierre cambió lo calculado",
    diferenciaDe: "{nombre}: {antes} en el corte, {hoy} hoy ({diferencia})",
    // Con ajustes ya registrados (R09): lo que falta, no la diferencia de siempre.
    diferenciaConAjustes:
      "{nombre}: {antes} en el corte y {ajustes} en ajustes, {hoy} hoy (falta {diferencia})",
    diferenciaAtendida:
      "{nombre}: {antes} en el corte y {ajustes} en ajustes = {hoy} hoy. Ya se atendió.",
    diferenciasAtendidas: "Lo que cambió desde el cierre ya se ajustó",
    usarDiferencia: "Ajustar",
    pago: {
      titulo: "Registrar el pago",
      referencia: "Referencia del pago",
      ayuda:
        "La transferencia, el número de cheque o lo que identifique el pago.",
      guardar: "Registrar pago",
      hecho: "Pago registrado.",
    },
    reabrir: "Reabrir periodo",
    confirmarReabrir:
      "¿Reabrir la nómina del {desde} al {hasta}? Vuelve a calcularse al momento; ciérrala otra vez cuando esté lista.",
    reabierto: "Periodo reabierto.",
    ajuste: {
      titulo: "Agregar un ajuste",
      persona: "Persona",
      sentido: "Tipo",
      aFavor: "A su favor (se le debe)",
      enContra: "En su contra (se le pagó de más)",
      monto: "Importe",
      motivo: "Motivo",
      compensa:
        "Corrige una diferencia del cálculo (se descuenta de lo que falta; un bono, no)",
      guardar: "Agregar ajuste",
      hecho: "Ajuste agregado.",
    },
    errorDetalle: "No se pudo cargar el periodo.",
  },
};
