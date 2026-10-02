// Configuración inicial por tipo de negocio (views/OnboardingView.vue, ADR 0088).
export default {
  titulo: "Configura tu negocio",
  subtitulo:
    "Lo justo para empezar a recibir reservas. Lo demás, cuando lo necesites.",
  paso: "Paso {n} de {total}",
  hecho: "Hecho",
  omitir: "Omitir por ahora",
  anterior: "Anterior",
  siguiente: "Guardar y continuar",
  continuar: "Continuar",
  terminar: "Publicar y terminar",
  guardarTerminar: "Guardar y terminar",
  completo: "Tu negocio ya está listo para recibir reservas.",
  irPanel: "Ir a mi panel",
  pasos: {
    negocio: "Tu negocio",
    servicios: "Servicios",
    equipo: "Quién atiende",
    clases: "Clases",
    horario: "Horario",
    planes: "Planes y precios",
    reglas: "Reglas",
    publicacion: "Publicar",
  },
  desc: {
    negocio: "Dónde atiendes y tu logo, para tu página y tus avisos.",
    servicios:
      "Lo que ofreces, cuánto dura y cuánto cuesta. Tus clientes lo verán al agendar.",
    equipo:
      "Quién atiende las citas y en qué horario. Con esto se calculan los espacios libres.",
    clases: "Las clases que das, cuánto duran y cuántas personas caben.",
    horario: "Qué días y a qué hora se da cada clase.",
    planes: "Cómo te pagan tus alumnos: por clase, por paquete o por mes.",
    reglas:
      "Hasta cuándo se puede cancelar y qué pasa si alguien no llega. Puedes aceptarlas como están.",
    publicacion:
      "Revisa cómo va tu negocio, mira tu página como la verán tus clientes y publícala.",
  },
  negocio: {
    logo: "Logo",
    opcional: "(opcional)",
    sucursal: "Nombre de tu sucursal",
    sucursalAyuda:
      "Por ejemplo, la colonia: «Roma Norte». Si tienes más, agrégalas después.",
    direccion: "Dirección",
    zona: "Zona horaria",
  },
  catalogo: {
    yaTienes: "Ya tienes",
    editarEnCatalogo: "Editar en Catálogo",
    nombre: "Nombre",
    duracion: "Duración",
    precio: "Precio",
    cupo: "Lugares",
    minutos: "{n} min",
    lugares: "{n} lugares",
    quitar: "Quitar",
    agregarServicio: "Agregar otro servicio",
    agregarClase: "Agregar otra clase",
    sugerencia:
      "Empezamos con lo más común en tu giro; cambia nombres, tiempos y precios.",
  },
  equipo: {
    quien: "¿Quién atiende?",
    yo: "Yo atiendo",
    yoAyuda: "Apareces como {profesional} al agendar.",
    yaAtienden: "Ya atienden",
    otro: "Agregar a alguien más",
    nombre: "Nombre",
    correo: "Correo",
    correoAyuda: "Le enviamos una invitación para que entre con su cuenta.",
    cuando: "¿Cuándo atienden?",
    dias: "Días",
    abre: "Desde",
    cierra: "Hasta",
    ajustaDespues:
      "Es el mismo para todos; ajusta el de cada quien (y sus descansos) en Horarios.",
  },
  horario: {
    yaProgramadas: "Ya programadas",
    dias: "Días",
    hora: "Hora",
    quien: "La da",
    sinAsignar: "Por asignar",
    ayuda:
      "Se repiten cada semana; cambia una fecha o agrega más en la Agenda.",
    sinClases: "Primero agrega tus clases en el paso anterior.",
  },
  planes: {
    yaTienes: "Ya tienes",
    incluir: "Ofrecer",
    clases: "Clases",
    vigencia: {
      suelta: "Una clase, vale 30 días",
      paquete: "Vale un mes",
      mensualidad: "Clases ilimitadas durante un mes",
    },
    ayuda:
      "Agrega paquetes, promociones o planes por clase en Ventas → Planes.",
  },
  reglas: {
    horas: "Se puede cancelar sin costo hasta",
    horasAntes: "horas antes",
    tarde: "Cobrar si cancela después",
    tardeAyuda:
      "Si cancela con menos de {n} horas de anticipación, se cobra (o se descuenta de su plan).",
    noLlega: "Cobrar si no llega",
    noLlegaAyuda: "Si no se presenta, se cobra (o se descuenta de su plan).",
    aceptar: "Aceptar reglas",
    despues: "Los detalles (tolerancias y reglas por clase) están en",
    irReglas: "Reglas de la agenda",
  },
  estados: {
    configurado: "Configurado",
    configuradoSi: "Todos los pasos están completos.",
    faltan: "Falta un paso. | Faltan {n} pasos.",
    publicado: "Página publicada",
    publicadoSi: "Tus clientes pueden abrir tu página y reservar.",
    publicadoNo: "Tu página está cerrada.",
    reservable: "Recibe reservas",
    primera: "Primera fecha disponible: {fecha} · {que} · {sucursal}.",
    resolver: "Ir a {paso}",
    motivo: {
      sin_servicios: "Aún no hay un servicio con precio para agendar.",
      sin_horario: "Aún no hay un horario de atención.",
      sin_huecos:
        "No hay horas libres en los próximos 14 días: revisa los horarios de atención.",
      sin_clases:
        "No hay clases con lugar en los próximos 14 días: programa tu horario.",
      sin_planes: "Hay clases, pero ningún plan con que reservarlas.",
      sin_publicar: "Hay fechas, pero tu página está cerrada.",
    },
  },
  publicacion: {
    tuPagina: "Tu página para reservar",
    vistaPrevia: "Ver como cliente",
    como: "¿Cómo la publicas?",
    vis: {
      publica: "En el directorio y con tu enlace",
      publicaAyuda:
        "Te encuentran quienes buscan un negocio como el tuyo cerca.",
      enlace: "Solo con tu enlace",
      enlaceAyuda:
        "Tu página funciona y reciben reservas, pero no apareces en búsquedas.",
      cerrada: "Cerrada por ahora",
      cerradaAyuda: "Nadie puede abrir tu página ni reservar en línea.",
    },
    copiar: "Copiar enlace",
    copiado: "Enlace copiado.",
    despues: "Cuando lo necesites",
    tareas: {
      pasarelas: "Cobrar en línea al reservar",
      usuarios: "Invitar a recepción o administración",
      pos: "Vender productos de mostrador",
    },
  },
  diasCortos: {
    1: "Lun",
    2: "Mar",
    3: "Mié",
    4: "Jue",
    5: "Vie",
    6: "Sáb",
    7: "Dom",
  },
};
