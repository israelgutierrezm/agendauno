export interface Solucion {
  slug: string;
  nombre: string;
  titulo: string;
  descripcion: string;
  encabezado: string;
  resumen: string;
  imagen: string;
  alt: string;
  modo: "clases" | "citas";
  ejemplo: string;
  beneficios: { titulo: string; texto: string }[];
  preguntas: { pregunta: string; respuesta: string }[];
}

export const soluciones: readonly Solucion[] = [
  {
    slug: "crossfit-hyrox",
    nombre: "CrossFit / HYROX",
    titulo: "Software para centros de CrossFit y HYROX | AgendaUno",
    descripcion:
      "Organiza clases de CrossFit y HYROX, cupos, coaches, membresías y asistencia. Comparte tu agenda de reservas y prueba AgendaUno 30 días gratis.",
    encabezado: "Más tiempo para entrenar a tu comunidad.",
    resumen:
      "Gestiona las clases de tu box o centro de entrenamiento con horarios, coaches y cupos claros. Tus alumnos reservan desde tu enlace y tu equipo consulta quién asistirá a cada sesión.",
    imagen: "crossfit-hyrox-v1.webp",
    alt: "Atleta empujando un trineo en un centro de entrenamiento funcional",
    modo: "clases",
    ejemplo: "Entrenamiento funcional",
    beneficios: [
      {
        titulo: "Cupos según tu espacio",
        texto:
          "Define la capacidad de cada clase y consulta las reservas antes de empezar. Organiza la lista de espera cuando se llenan los lugares.",
      },
      {
        titulo: "Una semana clara para tus coaches",
        texto:
          "Programa sesiones recurrentes y asigna a la persona que imparte cada horario desde tu agenda.",
      },
      {
        titulo: "Paquetes y membresías a la mano",
        texto:
          "Consulta clases disponibles, vigencias y asistencias de tus alumnos sin llevar el control entre mensajes.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Sirve para un box de CrossFit o un centro de HYROX?",
        respuesta:
          "Sí, para organizar sesiones con horario, coach y cupo. Configura los nombres de tus clases y los planes que ofreces a tus alumnos.",
      },
      {
        pregunta:
          "¿Incluye programación de entrenamientos o resultados deportivos?",
        respuesta:
          "AgendaUno se enfoca en reservas y operación del negocio. No sustituye una herramienta de programación de WOD, seguimiento de marcas deportivas o gestión de competencias, ni implica afiliación con estas marcas.",
      },
    ],
  },
  {
    slug: "nutriologos",
    nombre: "Nutriólogos",
    titulo: "Agenda de citas para nutriólogos | AgendaUno",
    descripcion:
      "Organiza consultas de nutrición, citas de seguimiento, disponibilidad y cobros por profesional. Prueba AgendaUno gratis durante 30 días, sin tarjeta.",
    encabezado: "Más tiempo para tus pacientes. Menos mensajes para agendar.",
    resumen:
      "Ya atiendas de forma independiente o en un consultorio con varios nutriólogos, organiza tus consultas y horarios desde una misma agenda. Comparte tu enlace para facilitar la próxima reserva.",
    imagen: "nutriologos-v3.webp",
    alt: "Nutriólogo conversando con una paciente durante una consulta de nutrición",
    modo: "citas",
    ejemplo: "Consulta de nutrición",
    beneficios: [
      {
        titulo: "Primera consulta y seguimiento",
        texto:
          "Crea servicios con distinta duración y precio para que cada consulta tenga el tiempo que necesita.",
      },
      {
        titulo: "Disponibilidad por nutriólogo",
        texto:
          "Organiza los horarios de atención y las citas de cada profesional sin mezclar sus agendas.",
      },
      {
        titulo: "Reservas y cobros en un lugar",
        texto:
          "Consulta tus próximas citas, registra pagos y comparte tu enlace para que el paciente elija un horario disponible.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Puedo usarlo si atiendo por mi cuenta?",
        respuesta:
          "Sí. Configura un profesional, tus tipos de consulta y horarios. Puedes incorporar más profesionales cuando tu consultorio crezca.",
      },
      {
        pregunta: "¿Crea dietas o sustituye un expediente clínico?",
        respuesta:
          "No. AgendaUno organiza citas, disponibilidad y cobros. No sustituye un expediente clínico, un sistema médico especializado ni una herramienta para crear planes alimenticios.",
      },
    ],
  },
  {
    slug: "pilates",
    nombre: "Pilates",
    titulo: "Software para estudios de Pilates | AgendaUno",
    descripcion:
      "Organiza clases de Pilates, cupos, membresías y reservas en una agenda visual. Prueba AgendaUno gratis durante 30 días, sin tarjeta.",
    encabezado:
      "Más atención a tus alumnos. Menos tiempo coordinando horarios.",
    resumen:
      "Una agenda para tu estudio de Pilates: organiza grupos, consulta lugares disponibles y lleva el control de membresías sin perder de vista tu siguiente clase.",
    imagen: "pilates-v1.jpg",
    alt: "Alumna practicando Pilates en reformer",
    modo: "clases",
    ejemplo: "Pilates Reformer",
    beneficios: [
      {
        titulo: "Cada reformer, un lugar disponible",
        texto:
          "Define el cupo de cada sesión según los equipos o espacios de tu estudio. Consulta reservas y asistencia desde la agenda.",
      },
      {
        titulo: "Membresías sin perder la cuenta",
        texto:
          "Organiza tus paquetes, créditos y vigencias para consultar qué puede reservar cada alumno.",
      },
      {
        titulo: "Horarios claros para tu equipo",
        texto:
          "Asigna instructores y organiza las clases de la semana. Tus alumnos consultan los horarios en el enlace de tu negocio.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Puedo organizar Pilates mat y reformer?",
        respuesta:
          "Sí. Crea clases distintas y configura horarios, instructor y cupo para cada sesión según el espacio disponible.",
      },
      {
        pregunta: "¿Cómo funciona la suscripción para un estudio?",
        respuesta:
          "La modalidad de clases se cobra por alumno activo al mes. Consulta la definición de alumno activo y las condiciones vigentes en la sección de precios antes de contratar.",
      },
    ],
  },
  {
    slug: "pole-dance",
    nombre: "Pole dance",
    titulo: "Software para academias de Pole dance | AgendaUno",
    descripcion:
      "Gestiona horarios de Pole dance, cupos por clase, alumnos y paquetes. Centraliza tus reservas con AgendaUno y prueba 30 días gratis.",
    encabezado: "Llena tu agenda de clases, no de conversaciones pendientes.",
    resumen:
      "Organiza los niveles, instructoras y horarios de tu academia de Pole dance en un solo lugar. Tus alumnos eligen su próxima clase y tú mantienes el control de los cupos.",
    imagen: "pole-v1.jpg",
    alt: "Alumna durante una clase de Pole dance",
    modo: "clases",
    ejemplo: "Pole dance básico",
    beneficios: [
      {
        titulo: "Cupos según tu espacio",
        texto:
          "Configura la capacidad de cada sesión según las barras y la dinámica de tu academia, y consulta quién reservó.",
      },
      {
        titulo: "Una agenda para cada nivel",
        texto:
          "Distingue las clases por nombre, horario e instructor para que la oferta de tu academia sea fácil de consultar.",
      },
      {
        titulo: "Paquetes y asistencias a la mano",
        texto:
          "Consulta créditos, vigencias y asistencia sin reconstruir el historial de cada alumno entre mensajes.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Puedo ofrecer clases de distintos niveles?",
        respuesta:
          "Sí. Puedes crear y nombrar clases para cada nivel y asignar sus horarios, instructor y capacidad. El nombre del nivel no sustituye una validación técnica del alumno.",
      },
      {
        pregunta: "¿Mis alumnos necesitan buscar mi academia en un directorio?",
        respuesta:
          "No. Comparte el enlace directo de tu negocio para que consulten tu oferta y accedan a sus reservas.",
      },
    ],
  },
  {
    slug: "academias",
    nombre: "Academias",
    titulo: "Software de reservas para academias | AgendaUno",
    descripcion:
      "Organiza clases, instructores, alumnos, membresías y asistencias de tu academia. Descubre la agenda visual de AgendaUno y prueba gratis.",
    encabezado: "Tu academia en movimiento. Tu operación en orden.",
    resumen:
      "De danza y yoga a actividades acuáticas: reúne los horarios, los grupos y las reservas de tu academia en una agenda que tu equipo pueda consultar cada día.",
    imagen: "academias-v1.jpg",
    alt: "Actividad grupal en una academia",
    modo: "clases",
    ejemplo: "Clase de academia",
    beneficios: [
      {
        titulo: "Una semana fácil de organizar",
        texto:
          "Visualiza tus sesiones por horario y asigna al instructor que impartirá cada clase.",
      },
      {
        titulo: "Alumnos y membresías conectados",
        texto:
          "Gestiona paquetes, créditos y vigencias junto con la información de tus alumnos.",
      },
      {
        titulo: "Recepción con información clara",
        texto:
          "Consulta las reservas y registra asistencia para saber quién llegó y cómo se están ocupando tus clases.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Sirve para danza, yoga o actividades acuáticas?",
        respuesta:
          "Sí, cuando tu operación se organiza por clases con horario, instructor y cupo. Configura los nombres y capacidades según tu actividad.",
      },
      {
        pregunta: "¿Por dónde empiezo?",
        respuesta:
          "Crea tu negocio, activa tu cuenta y configura una primera clase con su horario e instructor. Después puedes compartir el enlace con tus alumnos.",
      },
    ],
  },
  {
    slug: "barberias",
    nombre: "Barberías y estéticas",
    titulo: "Agenda de citas para barberías y estéticas | AgendaUno",
    descripcion:
      "Organiza citas por barbero o estilista, servicios y disponibilidad. Comparte tu enlace de reservas y prueba la agenda de AgendaUno gratis.",
    encabezado: "Que cada cliente encuentre su horario y a su profesional.",
    resumen:
      "Dale a tu barbería o estética una agenda por profesional. Organiza servicios y disponibilidad, y comparte un enlace para que tus clientes encuentren su próxima cita.",
    imagen: "barberia-v1.jpg",
    alt: "Barbero atendiendo a un cliente",
    modo: "citas",
    ejemplo: "Corte de cabello",
    beneficios: [
      {
        titulo: "Una agenda por profesional",
        texto:
          "Consulta las citas de cada integrante del equipo y organiza su disponibilidad desde una vista común.",
      },
      {
        titulo: "Servicios con su propio tiempo",
        texto:
          "Define servicios, precios y duración para que cada reserva corresponda al tiempo que requiere la atención.",
      },
      {
        titulo: "Tu enlace, listo para compartir",
        texto:
          "Lleva a tus clientes desde tus redes o mensajes a la página de tu negocio para elegir servicio, profesional y horario.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Un cliente puede elegir a su barbero o estilista?",
        respuesta:
          "Sí. El flujo de citas permite seleccionar un servicio, un profesional y un horario disponible según la configuración del negocio.",
      },
      {
        pregunta: "¿La suscripción se cobra por profesional?",
        respuesta:
          "El esquema comercial por profesional está en preparación. Puedes probar la agenda; revisa las condiciones disponibles antes de contratar. No anunciamos una tarifa todavía no publicada.",
      },
    ],
  },
  {
    slug: "spas",
    nombre: "Spas y wellness",
    titulo: "Software de citas para spas y wellness | AgendaUno",
    descripcion:
      "Gestiona citas, servicios y horarios por profesional en tu spa o negocio wellness. Prueba AgendaUno gratis durante 30 días, sin tarjeta.",
    encabezado:
      "Una experiencia de bienestar empieza con una reserva sencilla.",
    resumen:
      "Organiza los servicios y las citas de tu spa o centro wellness. Tu equipo consulta su agenda y tus clientes encuentran un horario para volver a cuidarse.",
    imagen: "spa-v1.webp",
    alt: "Sesión de bienestar en un spa",
    modo: "citas",
    ejemplo: "Sesión de bienestar",
    beneficios: [
      {
        titulo: "Duración y precio claros",
        texto:
          "Presenta tus servicios con su duración y precio para ayudar al cliente a elegir la atención que busca.",
      },
      {
        titulo: "El tiempo de tu equipo, organizado",
        texto:
          "Asigna profesionales y revisa su disponibilidad para distribuir las citas de tu día.",
      },
      {
        titulo: "Reservas desde tu propio enlace",
        texto:
          "Comparte tu página de servicios en redes sociales o mensajes y concentra las reservas en una agenda.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Puedo crear servicios con distinta duración?",
        respuesta:
          "Sí. Configura la duración de cada servicio y los profesionales que lo ofrecen para organizar los horarios disponibles.",
      },
      {
        pregunta: "¿La agenda contempla cabinas y equipamiento?",
        respuesta:
          "Esta propuesta se centra en citas por profesional. Antes de adoptar el sistema, valida las necesidades de cabinas, equipamiento o atención simultánea de tu spa durante la prueba.",
      },
    ],
  },
  {
    slug: "terapeutas",
    nombre: "Terapeutas y consultorios",
    titulo: "Agenda de citas para terapeutas y consultorios | AgendaUno",
    descripcion:
      "Organiza citas por profesional para terapeutas, psicólogos, nutriólogos y consultorios. Conoce AgendaUno y prueba tu flujo de reservas.",
    encabezado: "Más tiempo para atender. Una agenda más fácil de coordinar.",
    resumen:
      "Organiza horarios, servicios y citas para terapeutas, psicólogos, nutriólogos y otros profesionales que atienden con reserva. Comparte tu enlace para facilitar la elección de un horario.",
    imagen: "terapeutas-v1.webp",
    alt: "Profesional de terapia durante una sesión de atención",
    modo: "citas",
    ejemplo: "Sesión de terapia",
    beneficios: [
      {
        titulo: "Disponibilidad por profesional",
        texto:
          "Organiza el tiempo de atención de cada integrante del consultorio y revisa sus próximas citas.",
      },
      {
        titulo: "Servicios y duración definidos",
        texto:
          "Configura el tipo de atención y el tiempo de cada cita para ordenar la jornada.",
      },
      {
        titulo: "Un acceso directo a tu agenda",
        texto:
          "Comparte la página de tu negocio con tus clientes para consultar servicios y reservar sin buscarte en un directorio.",
      },
    ],
    preguntas: [
      {
        pregunta: "¿Sustituye un expediente clínico o software médico?",
        respuesta:
          "No. Esta solución se presenta como agenda y gestión operativa, no como expediente clínico ni garantía de cumplimiento sanitario. Valida por separado los requisitos de tu práctica y el tratamiento de datos sensibles.",
      },
      {
        pregunta: "¿Puedo usarlo si atiendo sin un equipo?",
        respuesta:
          "Puedes organizar la disponibilidad de un profesional. Prueba el flujo con tus servicios y consulta las condiciones comerciales vigentes antes de contratar.",
      },
    ],
  },
];

export function rutaSolucion(slug: string): string {
  return `/software-para-${slug}`;
}
