/**
 * Portal del alumno o cliente: Inicio con accesos directos, Reservas (lista y
 * calendario), Pagos, Expediente y Configuración. Las palabras "clase" y "alumno" se
 * adaptan solas a la terminología del negocio (ADR 0049).
 */
export default {
  nav: {
    inicio: "Inicio",
    reservas: "Reservas",
    pagos: "Pagos",
    expediente: "Expediente",
    configuracion: "Configuración",
  },
  inicio: {
    saludo: "¡Hola, {nombre}!",
    saludoSinNombre: "¡Hola!",
    resumen: "Aquí tienes un resumen de tu actividad en {estudio}.",
    // La etiqueta de la tarjeta principal va por lo que se reservó: clase o cita; sin
    // reserva, el término general.
    proxima: "Tu próxima clase",
    proximaCita: "Tu próxima cita",
    proximaReserva: "Tu próxima reserva",
    sinReservas: "Nada agendado por ahora",
    sinProximaAyuda: "Elige tu próxima clase y aparta tu lugar.",
    reservar: "Reservar",
    clima: {
      pronostico: "Pronóstico para tu clase en {lugar}",
      pronosticoCita: "Pronóstico para tu cita en {lugar}",
      ahora: "Ahora en {lugar}",
      ahoraCerca: "Ahora cerca de {lugar}",
      lluvia: "{n} % de lluvia",
    },
    verDetalle: "Ver en mis reservas →",
    atencion: {
      firmar:
        "Tienes 1 documento por firmar | Tienes {n} documentos por firmar",
      lugar:
        "Se liberó un lugar en {clase}: acéptalo antes de que se ofrezca a alguien más.",
    },
    tarjetas: {
      reservar: "Reservar",
      disponibles: "1 clase disponible | {n} clases disponibles",
      agendar: "Agenda tu próxima cita",
      reservas: "Mis reservas",
      proximas: "1 próxima | {n} próximas",
      sinReservas: "Sin reservas próximas",
      creditos: "Mis créditos",
      ilimitado: "Ilimitado",
      creditosValor: "1 crédito | {n} créditos",
      sinPaquete: "Sin paquete activo",
      pagos: "Pagos",
      porPagar: "1 por pagar | {n} por pagar",
      alCorriente: "Al corriente",
      pase: "Pase de entrada",
      paseValor: "Muéstralo al llegar",
      expediente: "Expediente",
      expedienteValor: "Documentos y formularios",
      configuracion: "Configuración",
      configuracionValor: "Privacidad y tu cuenta",
    },
  },
  reservas: {
    titulo: "Reservas",
    mias: "Mis reservas",
    disponibles: "Clases disponibles",
    reservada: "Reservada",
    sincronizar: "Ver mis reservas en el calendario del teléfono →",
    agendarCita: "Agendar una cita",
    detalle: "Detalle",
    con: "Con {nombre}",
    lugares: "{libres} de {total} lugares",
    llena: "Llena",
    sucursal: "Sucursal",
    todasSucursales: "Todas las sucursales",
    demasiadas:
      "Hay más clases en estas fechas de las que caben aquí: elige una sucursal o un periodo más corto.",
  },
  // Portal de quien imparte: su Inicio y su calendario (solo lo suyo).
  instructor: {
    nav: { grupo: "Mis clases", inicio: "Inicio", calendario: "Mi calendario" },
    inicio: {
      proxima: "Tu próxima clase",
      proximaCita: "Tu próxima cita",
      proximaGeneral: "Lo próximo en tu agenda",
      resumen: "Aquí tienes tus clases y citas en {estudio}.",
      enCurso: "En curso",
      sinProxima: "No tienes clases asignadas en los próximos días.",
      cupo: "{ocupados} de {capacidad} lugares ocupados",
      inscritos: "Sin inscritos | 1 inscrito | {n} inscritos",
      espera: "{n} en lista de espera",
      con: "Con {nombre}",
      pasarLista: "Pasar lista",
      verCita: "Ver cita",
      verCalendario: "Ver en mi calendario →",
      tarjetas: {
        hoy: "Hoy",
        hoyValor: "Sin clases | 1 clase | {n} clases",
        alumnos: "Alumnos esperados hoy",
        alumnosValor: "Nadie aún | 1 alumno | {n} alumnos",
        semana: "Próximos 7 días",
        semanaValor: "Sin clases | 1 clase | {n} clases",
        calendario: "Mi calendario",
        calendarioValor: "Lista, día, semana y mes",
        agenda: "Agenda",
        agendaValor: "Tu día con horarios y pase de lista",
        perfil: "Mi perfil",
        perfilValor: "Foto, contraseña y calendario del teléfono",
      },
    },
    calendario: {
      titulo: "Mi calendario",
      proximas: "Próximas clases",
      sinClases: "No tienes clases asignadas en los próximos 30 días.",
      sincronizar: "Ver mis clases en el calendario del teléfono →",
    },
  },
  // Calendario personal en lista, día, semana o mes (alumno e instructor).
  periodo: {
    vistas: { lista: "Lista", dia: "Día", semana: "Semana", mes: "Mes" },
    hoy: "Hoy",
    anterior: "Anterior",
    siguiente: "Siguiente",
    sinNada: "No hay nada en estas fechas.",
    irSiguiente: "Ver lo siguiente →",
  },
  calendario: {
    agregar: "Agregar a mi calendario",
    google: "Google Calendar",
    ics: "Apple, Outlook y otros (.ics)",
  },
  pagos: {
    titulo: "Pagos",
    porPagar: "Por pagar",
    historial: "Historial de compras",
    sinHistorial: "Aún no hay compras.",
  },
  expediente: {
    titulo: "Expediente",
    firmar: "Por firmar",
    formularios: "Formularios",
  },
  configuracion: {
    titulo: "Configuración",
    perfil: "Mi perfil",
    perfilAyuda:
      "Tu nombre, foto, correo y contraseña, y la liga para ver tus reservas en el calendario del teléfono.",
    perfilIr: "Ir a mi perfil →",
  },
};
