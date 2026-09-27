/**
 * Portal del alumno o cliente: Inicio con accesos directos, Reservas (lista y
 * calendario), Pagos, Expediente y Configuración. Las palabras "clase" y "alumno" se
 * adaptan solas a la terminología del negocio (ADR 0049).
 */
export default {
  nav: {
    grupo: "Mi cuenta",
    inicio: "Inicio",
    reservas: "Reservas",
    pagos: "Pagos",
    expediente: "Expediente",
    configuracion: "Configuración",
  },
  inicio: {
    titulo: "Inicio",
    proxima: "Tu próxima clase",
    sinProxima: "No tienes reservas próximas.",
    reservar: "Reservar",
    verDetalle: "Ver en mis reservas →",
    atencion: {
      firmar:
        "Tienes 1 documento por firmar | Tienes {n} documentos por firmar",
      lugar:
        "Se liberó un lugar en {clase}: acéptalo antes de que se ofrezca a alguien más.",
      pagar: "Tienes 1 pago pendiente | Tienes {n} pagos pendientes",
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
    vistas: { lista: "Lista", dia: "Día", semana: "Semana", mes: "Mes" },
    hoy: "Hoy",
    anterior: "Anterior",
    siguiente: "Siguiente",
    mias: "Mis reservas",
    disponibles: "Clases disponibles",
    reservada: "Reservada",
    sinNada: "No hay nada en estas fechas.",
    irSiguiente: "Ver lo siguiente →",
    sincronizar: "Ver mis reservas en el calendario del teléfono →",
    agendarCita: "Agendar una cita",
    detalle: "Detalle",
    con: "Con {nombre}",
    lugares: "{libres} de {total} lugares",
    llena: "Llena",
  },
  calendario: {
    agregar: "Agregar a mi calendario",
    google: "Google Calendar",
    ics: "Apple, Outlook y otros (.ics)",
  },
  pagos: {
    titulo: "Pagos",
    creditos: "Mis créditos",
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
