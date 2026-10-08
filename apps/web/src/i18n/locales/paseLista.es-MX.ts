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
