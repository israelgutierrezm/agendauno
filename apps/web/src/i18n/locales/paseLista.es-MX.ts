// Pase de lista de una clase en su propia pantalla (ADR 0101).
export default {
  titulo: "Pase de lista",
  pasarLista: "Pasar lista",
  volver: "Volver",
  buscar: "Buscar en la lista",
  filtrar: "Mostrar",
  sinCoincidencias: "Nadie en la lista coincide con la búsqueda.",
  nadieEn: "Nadie en «{filtro}».",
  // Cómo va cada quien (el mismo nombre en la lista, el detalle y la Agenda).
  estados: {
    por_marcar: "Por marcar",
    llego: "Llegó",
    tarde: "Llegó tarde",
    no_vino: "No vino",
    no_se_presento: "No se presentó (automático)",
    ofrecida: "Lugar ofrecido, sin aceptar",
    pendiente_pago: "Pago pendiente",
  },
  acciones: {
    llego: "Llegó",
    tarde: "Tarde",
    noVino: "No vino",
    cambiar: "Cambiar",
    dejar: "Dejar como estaba",
    aceptar: "Aceptar el lugar",
  },
  filtros: {
    todos: "Todos",
    por_marcar: "Por marcar",
    llegaron: "Llegaron",
    no_vinieron: "No vinieron",
  },
  resumen: {
    llegaron: "de {total} llegaron",
    avance: "Avance del pase de lista",
    libres: "Lugares libres",
    llena: "Llena",
  },
  // Cómo va la lista, en una línea arriba.
  estado: {
    cancelada: "Esta clase se canceló.",
    abre: "La lista se abre a las {hora}.",
    vacia: "Nadie ha reservado esta clase.",
    completa: "Lista completa: todos tienen registro.",
    enCurso:
      "Ya empezó y falta 1 por marcar. | Ya empezó y faltan {n} por marcar.",
    abierta: "Lista abierta. La clase empieza a las {hora}.",
  },
  terminar: {
    ayuda:
      "Falta 1 por marcar: al terminar, quedará como «no vino». | Faltan {n} por marcar: al terminar, quedarán como «no vino».",
  },
  espera: {
    ayuda: "Si se libera un lugar, se ofrece en este orden.",
  },
  // Invitados de los clientes con su pase del plan (ADR 0182).
  invitados: {
    titulo: "Invitados",
    ayuda:
      "Vienen con el pase de invitado de un cliente: ocupan lugar y no son clientes.",
    de: "invitado de {nombre}",
    estados: {
      reservado: "Por marcar",
      asistio: "Llegó",
      no_asistio: "No vino",
    },
  },
  // Quien reservó desde la app de Wellhub, TotalPass o Fitpass (ADR 0202).
  plataforma: {
    titulo: "Reservas de plataformas",
    ayuda:
      "Reservaron desde la app de su plataforma: ocupan lugar y no son clientes del negocio.",
    sinNombre: "Visitante",
    enSuApp: "Hace check-in en su app",
    cancelar: "Cancelar reserva",
    capturarFitpass: "Capturar reservas de Fitpass",
    reservasFitpass: "Reservas de Fitpass Admin",
    // «@» se escribe así en vue-i18n.
    reservasFitpassEjemplo: "48211, Ana Ruiz, ana{'@'}correo.mx",
    reservasFitpassAyuda:
      "Una por renglón: el ID de la reservación, el nombre y, si lo tienes, el correo. Puedes pegar la tabla de Fitpass Admin.",
    guardarFitpass: "Guardar reservas",
    identificacion:
      "Fitpass pide revisar la identificación oficial de quien llega. ¿Ya la revisaste?",
    identificacionSi: "Sí, la revisé",
    cancelarConfirmar:
      "La plataforma le avisará que su reserva se canceló y el lugar queda libre.",
    proveedores: {
      wellhub: "Wellhub",
      totalpass: "TotalPass",
      fitpass: "Fitpass",
    },
  },
  // Quien llegó sin reservar.
  agregar: {
    boton: "Agregar alumno",
    corto: "Agregar",
    quien: "Alumno que llegó sin reservar",
    agregar: "Agregar a la clase",
    aEspera: "Agregar a la lista de espera",
    llena: "La clase está llena: entrará a la lista de espera.",
    agregado: "{nombre} se agregó a la clase.",
    agregadoEspera: "{nombre} quedó en la lista de espera.",
  },
};
