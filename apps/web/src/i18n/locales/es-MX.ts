export default {
  marca: "AgendaUno",
  pantallaCompleta: {
    ver: "Ver en pantalla completa",
    salir: "Salir de pantalla completa",
    noDisponible: "Este navegador no permite activar la pantalla completa.",
    agendaAlternativa:
      "Agenda ampliada dentro de la ventana. Este navegador no permite activar la pantalla completa.",
  },
  accesos: {
    etiqueta: "Accesos rápidos",
    titulo: "Tu operación, a mano",
    subtitulo:
      "Accede rápidamente a las herramientas más importantes de tu negocio.",
    agendaClases: "Consulta clases, horarios y lugares disponibles.",
    agendaCitas: "Consulta citas y la agenda de tu equipo.",
    miembros: "Encuentra una ficha y consulta su información.",
    recepcion: "Revisa llegadas y asistencias del día.",
    horarios: "Consulta la disponibilidad de cada profesional.",
    ventas: "Consulta ventas, pagos y saldos pendientes.",
    cobrarTitulo: "Cobrar",
    cobranza: "Lo pendiente de pago: registra el cobro de cada cita.",
  },
  // «Actualizado hace…» y su botón (components/ActualizadoHace.vue).
  actualizado: {
    recien: "Actualizado hace un momento",
    minutos: "Actualizado hace {n} min",
    horas: "Actualizado hace {n} h",
    boton: "Actualizar",
    actualizando: "Actualizando…",
    // Falló la actualización en segundo plano: lo que se ve puede no estar al día.
    fallo: "No se pudo actualizar: lo que ves es de antes ({hace})",
  },
  comun: {
    // Elegir varias de una lista que crece (SelectorOpciones).
    selector: {
      buscar: "Buscar",
      elegidas: "{n} de {total} elegidas",
      elegirTodas: "Elegir todas",
      quitarTodas: "Quitar todas",
      sinGrupo: "Otras",
      sinCoincidencias: "Nada coincide con la búsqueda.",
    },
    cambiosSinGuardar:
      "Tienes cambios sin guardar. Si sales ahora, se pierden.",
    salirSinGuardar: "Salir sin guardar",
    volverA: "Volver a {destino}",
    cargando: "Cargando…",
    cancelar: "Cancelar",
    guardar: "Guardar",
    guardando: "Guardando…",
    cerrar: "Cerrar",
    detalle: "Detalle",
    eliminar: "Eliminar",
    reintentar: "Reintentar",
    revisaCampos: "Revisa los campos marcados.",
    // La petición no tuvo respuesta (sin red, servidor caído o tiempo agotado).
    sinRespuesta:
      "No hubo respuesta del servidor. Revisa tu conexión e inténtalo de nuevo.",
    versionNueva:
      "Hay una versión nueva de AgendaUno. Recarga la página para continuar.",
    verPassword: "Mostrar contraseña",
    ocultarPassword: "Ocultar contraseña",
  },
  tabla: {
    buscar: "Buscar…",
    anterior: "Anterior",
    siguiente: "Siguiente",
    mostrando: "{desde}–{hasta} de {total}",
    filtros: "Filtros",
    limpiar: "Limpiar",
    vista: "Vista del listado",
    paginacion: "Páginas del listado",
    pagina: "Página {n}",
    sinCoincidencias: "No encontramos coincidencias",
    ajustarBusqueda:
      "Prueba con otro nombre o elimina los filtros para ver todos los registros.",
    restablecer: "Limpiar búsqueda y filtros",
    sinRegistros: "Todavía no hay registros.",
    porPagina: "{n} por página",
    filasPorPagina: "Filas por página",
    columnas: "Columnas",
    columnasAyuda: "Qué columnas ver",
    exportar: "Exportar",
    exportando: "Exportando…",
    ordenarPor: "Ordenar por {columna}",
  },
  nav: {
    menu: "Menu",
    probar: "Probar gratis",
    probarCorto: "Probar",
    // Producto que aún no recibe registros (ADR 0108).
    avisarme: "Quiero que me avisen",
    avisarmeCorto: "Avísenme",
    entrar: "Iniciar sesión",
    entrarCorto: "Entrar",
    irAMiPanel: "Ir a mi panel",
    miPanel: "Mi panel",
    marketing: "Información de AgendaUno",
    // Menú comercial: Clases · Citas · Precios (como ETIQUETA_MENU de modalidades).
    clases: "Clases",
    citas: "Citas",
    precios: "Precios",
    // Menú de la portada de cada producto (ADR 0108).
    funciones: "Funciones",
    preguntas: "Preguntas",
    encontrarNegocio: "¿Buscas reservar? Encuentra tu negocio",
    reservasPor: "Reservas en línea con AgendaUno",
    // La leyenda fija de la plataforma en las páginas de cada negocio (ADR 0167).
    hechoCon: "Hecho con {marca}",
    panel: "Panel",
    miCuenta: "Mi cuenta",
    miembros: "Miembros",
    instructores: "Instructores",
    usuarios: "Accesos y permisos",
    agenda: "Agenda",
    horarios: "Horarios de atención",
    oportunidades: "Oportunidades",
    tareas: "Tareas",
    recepcion: "Recepción",
    cursos: "Grupos",
    recursos: "Recursos",
    nomina: "Nómina",
    reportes: "Reportes",
    promociones: "Promociones",
    comunicaciones: "Comunicación",
    lealtad: "Lealtad",
    facturas: "Facturas",
    cobranza: "Cobranza",
    renta: "Suscripción",
    padron: "Padrón",
    importar: "Importar",
    // Último tramo de la ruta de ubicación en una pantalla de paso.
    paso: { ficha: "Ficha" },
    datosFiscales: "Datos fiscales",
    documentos: "Documentos",
    formularios: "Ficha de datos",
    pasarelas: "Pagos",
    integraciones: "Integraciones",
    configuracion: "Configuración",
    sedes: "Sucursales",
    contraer: "Contraer",
    expandir: "Expandir",
    grupos: {
      operacion: "Operación",
      suscripcion: "Mi suscripción",
      ventas: "Ventas",
      operacionDiaria: "Operación diaria",
      gestion: "Gestión",
      captacion: "Captación",
      configuracion: "Configuración",
    },
    // Áreas del lateral (una por tema) y sus vistas en pestañas.
    areas: {
      inicio: "Inicio",
      configuracion: "Configuración del negocio",
      // Quien todavía no es cliente (ADR 0117), aparte de Miembros.
      captacion: "Captación",
      // Captación (ADR 0167): su sitio web y sus campañas.
      sitio: "Sitio web",
      campanas: "Campañas",
      // La bandeja de WhatsApp e Instagram (ADR 0200).
      mensajes: "Mensajes",
    },
    vistas: {
      resumen: "Resumen",
      calendario: "Calendario",
      lugares: "Lugares disponibles",
      expedientes: "Expedientes",
      respuestas: "Respuestas de fichas de datos",
      porCobrar: "Por cobrar",
      movimientos: "Movimientos",
      conciliacion: "Conciliación",
      // El reporte de movimientos de dinero; la caja del turno es «cajaTurno» (ADR 0175).
      caja: "Corte de caja",
      cajaTurno: "Caja",
      disponibilidad: "Disponibilidad",
      rendimiento: "Rendimiento",
      membresias: "Membresías y paquetes",
      paquetesCitas: "Bonos",
      presupuestos: "Presupuestos",
      suscripcion: "Pagos de tu suscripción",
      usoFacturable: "Uso facturable",
      prospectos: "Prospectos",
      formulariosCaptacion: "Formularios de captación",
    },
  },
  // Campañas en Captación (ADR 0167): el reporte por campaña con su periodo.
  campanasVista: {
    descripcion:
      "De dónde llegaron tus visitas, solicitudes y ventas, por campaña.",
    desde: "Desde",
    hasta: "Hasta",
    periodoInvalido: "La fecha de inicio va antes de la de fin.",
  },
  // Configuración del negocio: portada, categorías y opciones.
  configNegocio: {
    subtitulo: "Organiza cómo funciona tu negocio.",
    buscar: "Buscar ajuste",
    sinResultados: "No encontramos ese ajuste.",
    secciones: "Secciones de configuración",
    ubicacion: "Ubicación",
    categorias: {
      negocio: "Negocio",
      servicios: "Servicios y catálogos",
      serviciosClases: "Clases y catálogos",
      agenda: "Agenda y reservas",
      pagos: "Pagos e integraciones",
      accesos: "Accesos y permisos",
      documentos: "Documentos y privacidad",
    },
    descripciones: {
      negocio:
        "El logo del negocio, sus sucursales y tabletas de llegada, datos fiscales y cómo se llaman las cosas.",
      servicios:
        "Qué se ofrece y a qué precio: servicios, combos y bonos, y los espacios que usan.",
      serviciosClases:
        "Qué se ofrece y a qué precio: clases, planes y paquetes, y los espacios que usan.",
      agenda:
        "Reglas para reservar y cancelar, días de cierre y la programación recurrente.",
      pagos:
        "Cómo cobra el negocio en línea y sus conexiones con otros sistemas.",
      accesos: "Quién entra al panel y con qué permisos.",
      documentos:
        "Requisitos y consentimientos, fichas de datos, solicitudes de privacidad y bitácora.",
    },
    opciones: {
      // Aquí solo se cambia el logo (el nombre y el contacto no se editan desde el
      // panel): se llama por lo que es.
      datos: "Logo del negocio",
      pagina: "Página pública",
      sitio: "Sitio web",
      sucursales: "Sucursales",
      region: "País, moneda y zona horaria",
      fiscales: "Datos fiscales",
      terminologia: "Terminología",
      planes: "Planes y paquetes",
      videos: "Videos para tus alumnos",
      planesCitas: "Bonos",
      recursos: "Espacios y recursos",
      politicas: "Políticas de reserva y cancelación",
      cierres: "Días de cierre",
      programacion: "Programación recurrente",
      parametros: "Límites y tiempos",
      pasarelas: "Pasarelas de pago",
      usuarios: "Usuarios con acceso",
      requisitos: "Requisitos documentales",
      consentimientos: "Consentimientos",
    },
  },
  tema: {
    claro: "Tema claro",
    oscuro: "Tema oscuro",
    apariencia: "Apariencia",
  },
  landing: {
    // Los cobros en línea y la facturación (CFDI) solo operan en México (ADR 0099):
    // lo que los menciona lleva «*» y esta nota en su sección.
    soloMexico: "* Solo para negocios en México (cobros en pesos).",
    // Un producto cuyo registro cerró el superadmin (ADR 0108, prelanzamiento).
    // «AgendaUno» se lee con la marca del producto. Mientras, nada
    // ofrece prueba gratis, registrarse ni contratar: todo lleva a la lista de
    // interesados.
    prelanzamiento: {
      cta: "Quiero que me avisen",
      proximamente: "Próximamente",
      pieHero: "Te avisamos en cuanto abra.",
      // SellosConfianza: sin prueba ni permanencia, «Abre pronto».
      sello: "Abre pronto",
      sellosTitulo: "Lanzamiento y operación",
      demoPie: "Abre pronto. Déjanos tus datos y te avisamos.",
      pasosTitulo: "Así funcionará, en tres pasos",
      finalTitulo: "AgendaUno abre pronto.",
      finalSubtitulo:
        "Déjanos tus datos y te avisamos en cuanto puedas registrar tu negocio.",
    },
    // Lo que /clases y /citas (ModalidadView) dicen igual; lo propio de cada una va en
    // `landing.clases` y `landing.citas`.
    modalidad: {
      probar: "Probar AgendaUno gratis",
      verAgenda: "Ver la agenda de ejemplo",
      demoEtiqueta: "AgendaUno en acción",
      // Las demos se identifican como demos (plan de mejoras C01).
      demoPaginaNota: "Página de ejemplo con datos ficticios.",
      heroNota: "Imágenes y avisos de ejemplo.",
      demoPie: "{dias} días para probarlo. Sin tarjeta.",
      pasosTitulo: "Empieza en solo tres pasos",
      recorridoEtiqueta: "De principio a fin",
      recorridoAdemas: "Y en recepción, también:",
      casosTitulo: "Negocios que ya trabajan así",
      casosSubtitulo:
        "Lo que tenían, lo que resolvieron y lo que cambió, contado por ellos.",
      ejemplo: "Ejemplo",
      precioEtiqueta: "Tu suscripción a AgendaUno",
      faqTitulo: "Preguntas frecuentes",
    },
    // Página por giro (/software-para-*, SolucionView): lo fijo de la página; lo de
    // cada giro va en marketing/soluciones.ts. La marca de su producto llega en
    // {marca} (la página de un giro de citas habla con la de citas).
    solucion: {
      miga: "Ruta de navegación",
      // {nombre}: el del giro a media frase (`nombreEnFrase`).
      etiqueta: {
        clases: "Software de reservas para {nombre}",
        citas: "Agenda de citas para {nombre}",
      },
      probar: "Probar gratis",
      confianza: "{dias} días para probarlo · Sin tarjeta",
      verAgenda: "Ver la agenda en acción",
      ejemplo: { clases: "Ejemplo de clase", citas: "Ejemplo de cita" },
      ejemploPie: "Tu día, a la vista.",
      beneficiosEtiqueta: "Menos pendientes, más claridad",
      beneficiosTitulo: "Lo que tu negocio necesita para organizar su día.",
      // Clases: sin autorregistro (ADR 0093), las cuentas las da el negocio. Citas: el
      // cliente agenda desde la página sin cuenta.
      empezar: {
        etiqueta: "Del registro a tu primera reserva",
        titulo: "Pruébalo con la forma en que trabajas.",
        clases:
          "Crea tu negocio, activa tu cuenta y configura una clase con horario, instructor y cupo. Después da de alta a tus alumnos, invítalos a su cuenta y comparte tu enlace con tus horarios.",
        citas:
          "Crea tu negocio, activa tu cuenta y configura un servicio con duración, profesional y disponibilidad. Después comparte tu enlace para que tus clientes elijan servicio, profesional y horario.",
      },
      precio: {
        titulo: "Una prueba con tu operación real",
        // {modalidad}: «Clases con cupo» o «Citas 1 a 1».
        clases:
          "En {modalidad}, la suscripción se cobra por rango de alumnos activos al mes.",
        citas:
          "En {modalidad}, la suscripción se cobra con el plan que elijas, por los profesionales que contratas.",
        tarifas:
          "Consulta las tarifas vigentes antes de contratar; los importes son en dólares, más impuestos.",
        verPrecios: "Conocer precios y condiciones",
        todo: "Todo lo que incluye {marca}",
      },
      preguntas: "Antes de empezar",
      cierre: {
        titulo: "Tu próxima reserva empieza con una agenda más clara.",
        texto:
          "Configura tu negocio y comprueba si {marca} encaja con tu operación.",
        probar: "Probar gratis durante {dias} días",
      },
      otras: "Otras formas de trabajar con {marca}",
      // Producto que aún no recibe registros (ADR 0108): cómo funcionará y la lista
      // de interesados, sin prueba gratis ni registro.
      prelanzamiento: {
        etiqueta: "Así funcionará",
        titulo: "Pensado para la forma en que trabajas.",
        clases:
          "Cuando abra, registras tu negocio y configuras una clase con horario, instructor y cupo. Después das de alta a tus alumnos, los invitas a su cuenta y compartes tu enlace con tus horarios.",
        citas:
          "Cuando abra, registras tu negocio y configuras un servicio con duración, profesional y disponibilidad. Después compartes tu enlace para que tus clientes elijan servicio, profesional y horario.",
        precioTitulo: "Tu suscripción a {marca}",
        tarifas:
          "Consulta las tarifas vigentes; los importes son en dólares, más impuestos.",
        cierreTitulo: "{marca} abre pronto.",
        cierreTexto:
          "Déjanos tus datos y te avisamos en cuanto puedas registrar tu negocio.",
      },
    },
    // Landing de cada modalidad (/clases, /citas; ModalidadView). Las funciones son
    // las de MODALIDADES[modo].funciones (marketing/modalidades.ts), en ese orden, y
    // solo dicen lo que el producto hace hoy. FuncionesLanding las lee con su `modo`.
    // Lo demás de cada página va dentro de estos mismos bloques.
    clases: {
      funciones: {
        cupos: {
          titulo: "Cupos por clase",
          descripcion:
            "Define cuántos lugares tiene cada clase y consulta cuántos quedan antes de que empiece.",
          detalle:
            "Programa clases recurrentes con su instructor, su sala y su cupo. Tus alumnos con cuenta apartan su lugar y tu equipo sabe quién asistirá a cada horario.",
        },
        listaEspera: {
          titulo: "Lista de espera",
          descripcion:
            "Cuando una clase se llena, tus alumnos se anotan en la lista de espera.",
          detalle:
            "Si alguien cancela, el lugar se ofrece a quien sigue en la lista, con un tiempo para aceptarlo que tú configuras.",
        },
        membresias: {
          titulo: "Membresías y créditos",
          descripcion:
            "Vende paquetes de clases y membresías. Cada reserva usa un crédito del alumno.",
          detalle:
            "Consulta créditos disponibles, vigencias y renovaciones desde la ficha de cada alumno. Si cancela a tiempo, el crédito regresa según tu política de cancelación.",
        },
        asistencia: {
          titulo: "Pase de lista con retardos",
          descripcion:
            "Marca quién llegó, quién llegó tarde y quién no vino, desde recepción o desde la lista de quien imparte la clase.",
          detalle:
            "La lista se abre unos minutos antes de la clase. Quien siga sin registro al terminar queda como inasistencia y se aplica tu política de faltas.",
        },
        paseQr: {
          titulo: "Pase QR de entrada en gimnasios",
          descripcion:
            "En gimnasios, CrossFit y HYROX, cada miembro tiene un pase con código QR para registrar su entrada en recepción.",
          detalle:
            "Recepción escanea el pase y ve si la persona tiene una membresía de acceso libre o una reserva para esa hora. En los estudios de clases la entrada es la clase misma: basta con el pase de lista.",
        },
        // Kiosco de llegada (ADR 0183): en iPad, Safari no lee códigos con la cámara.
        kiosco: {
          titulo: "Kiosco de llegada",
          descripcion:
            "Una tableta en recepción donde tus alumnos escanean su pase al llegar, sin que alguien del equipo tenga la sesión abierta.",
          detalle:
            "Si tiene clase a esa hora, queda su asistencia, con retardo si ya empezó; si llega antes de que abra la lista, la pantalla le dice desde qué hora. Solo muestra su primer nombre y su clase. Sirve cualquier tableta con navegador: con su cámara o, en iPad, con un lector de códigos.",
        },
        // Videos y clases en línea (ADR 0184): enlaces, no se aloja video.
        videos: {
          titulo: "Videos y clases en línea",
          descripcion:
            "Comparte tus videos de YouTube o Vimeo con los planes que elijas, y el enlace de tu clase en línea solo con quien reservó.",
          detalle:
            "Tus alumnos ven los videos de su plan en su cuenta y en la app; de los demás ven el título y qué plan los incluye, para animarlos a cambiar. No subes archivos: pones el enlace de tu YouTube, Vimeo o Zoom.",
        },
        // Productos al reservar (ADR 0194): se apartan y se pagan en el mostrador.
        apartados: {
          titulo: "Productos apartados al reservar",
          descripcion:
            "Al reservar, tus alumnos apartan agua, calcetines antiderrapantes o lo que ofrezcas, y lo pagan al recogerlo en el mostrador.",
          detalle:
            "Nunca se aparta más de lo que hay en la sede de la clase. Recepción ve lo apartado en la lista de la clase y lo cobra en el mostrador; el inventario se mueve con la venta, no al apartar.",
        },
        // Google Calendar y Outlook (ADR 0199): un calendario aparte, sin datos de
        // alumnos; de su calendario solo se lee si está ocupado.
        calendarios: {
          titulo: "Google Calendar y Outlook",
          descripcion:
            "Cada instructor ve sus clases en su Google Calendar u Outlook, en un calendario aparte con el nombre de tu estudio.",
          detalle:
            "Lo que tiene ocupado en su calendario se marca en su agenda de AgendaUno, al momento. De su calendario solo se lee si está ocupado, no qué tiene, y ningún dato de tus alumnos sale de aquí.",
        },
      },
      // «Lo nuevo» (NovedadesLanding): claves de MODALIDADES.clases.novedades, cada
      // una con tres puntos. Wellhub y TotalPass, en los países que habilita la
      // plataforma (México por omisión); Fitpass, solo en México («*», ADR 0202). Fitpass
      // no tiene API: sus reservas las registra recepción (ADR 0197).
      novedades: {
        etiqueta: "Lo nuevo en AgendaUno",
        titulo: "Más reservas desde más lugares, en tu misma agenda.",
        enfasis: "desde más lugares",
        subtitulo:
          "Las apps de bienestar, tu propia web, tu WhatsApp y tu Instagram ya llegan al mismo lugar, y tus alumnos entran con el pase de su teléfono.",
        cta: "Quiero esto en mi estudio",
        items: {
          plataformas: {
            titulo: "Wellhub, TotalPass y Fitpass*, en tu misma agenda",
            texto:
              "Quien reserva desde la app de Wellhub o TotalPass ocupa su lugar en tu clase, sin sobrecupo, y su check-in se valida solo. Con Fitpass apartas sus lugares y recepción registra sus reservas.",
            puntos: {
              p1: "Sus visitantes no cuentan como alumnos activos",
              p2: "Cada sede, como su propio gimnasio",
              p3: "Concilias sus pagos con su reporte (Excel o CSV)",
            },
          },
          wallet: {
            titulo: "Su pase de entrada en Apple Wallet y Google Wallet",
            texto:
              "Tus alumnos guardan su pase en la cartera del teléfono y ahí ven su plan, hasta cuándo vale y su saldo. Cuando compran o usan un crédito, se actualiza solo.",
            puntos: {
              p1: "Recepción o el kiosco de llegada lo escanean",
              p2: "Además del QR de la app",
              p3: "Tú decides si lo ofreces",
            },
          },
          widgets: {
            titulo: "Tus horarios y tus planes, dentro de tu propia web",
            texto:
              "Pega tu calendario de clases y tus planes en tu sitio. Quien ya es tu alumno reserva o compra con su cuenta; quien todavía no, te deja sus datos ahí mismo.",
            puntos: {
              p1: "Con tu color, en tema claro u oscuro",
              p2: "Se ajusta solo al ancho de tu web",
              p3: "Los interesados llegan a tus prospectos",
            },
          },
          app: {
            titulo: "Tu estudio, en el celular de tu equipo y de tus alumnos",
            texto:
              "Tu equipo escanea el pase con la cámara, pasa lista y lleva la caja desde la app. Tus alumnos reservan, pagan y ven sus videos y sus documentos.",
            puntos: {
              p1: "Con lugares numerados, cada quien elige o cambia el suyo",
              // El widget de inicio solo está en Android (en iPhone falta compilarlo: ios/WIDGET.md).
              p2: "En Android, su próxima clase en la pantalla de inicio",
              p3: "Tu página se instala como app, con tu logo",
            },
          },
          bandeja: {
            titulo: "WhatsApp e Instagram, en una sola bandeja",
            texto:
              "Lee y contesta los mensajes de tu WhatsApp Business y de tu Instagram desde el panel o la app, con todo tu equipo, y liga cada conversación con la ficha del alumno.",
            puntos: {
              p1: "Aviso en el celular cuando alguien escribe",
              p2: "Fotos y PDF dentro de la conversación",
              p3: "Fuera de las 24 horas, con tus plantillas de WhatsApp",
            },
          },
          caja: {
            titulo: "Una caja que cuadra al final del día",
            texto:
              "Abre con fondo, cobra en efectivo y con tarjeta en un mismo cobro y cierra con conteo a ciegas. Si algo no cuadra, queda el motivo para que lo revise quien administra.",
            puntos: {
              p1: "Una caja por sucursal, con sus turnos",
              p2: "Retiros e ingresos de efectivo con su motivo",
              p3: "Lo cerrado no cambia: los ajustes van a la caja abierta",
            },
          },
        },
      },
      // ModalidadView /clases: claves de MODALIDADES.clases (pasos, operacion,
      // pagina, preguntas). Sin autorregistro de alumnos ni pago por cuenta propia
      // desde la página (ADR 0093); el QR es pase de entrada.
      hero: {
        etiqueta: "Software para estudios, academias y gimnasios",
        titulo: "Tus clases, llenas y en orden.",
        enfasis: "llenas",
        subtitulo:
          "Gestiona reservas, clases, membresías y cobros desde una sola agenda. Tu equipo tiene el control; tus alumnos eligen su próxima clase.",
        avisoTitulo: "Lugar reservado",
        avisoDetalle: "Pole dance · Hoy 18:00 · 2 lugares libres",
      },
      confianza: { cobro: "Precio por alumnos activos" },
      producto: {
        titulo: "Tu semana de clases, de un vistazo.",
        enfasis: "de un vistazo",
        subtitulo:
          "Explora una agenda de ejemplo, sin registrarte: el cupo de cada clase y cuántos lugares quedan.",
        cta: "Quiero organizar mis clases",
      },
      seccion: {
        titulo: "Lo que tu estudio necesita para crecer.",
        enfasis: "crecer",
        subtitulo:
          "Hecho para clases con cupo: cada lugar, cada crédito y cada asistencia cuentan.",
      },
      comoFunciona: {
        subtitulo:
          "Tú armas tu semana. Tus alumnos reservan su lugar. Tú pasas lista.",
      },
      recorrido: {
        titulo: "De la reserva al corte del día, en un solo lugar.",
        subtitulo:
          "Sigue una misma clase: tu alumna reserva, tu estudio la recibe, se registra el pago y ves el resultado.",
      },
      pasos: {
        preparar: {
          titulo: "Arma tu semana",
          texto:
            "Crea tus clases con su cupo, instructor y horario, y repítelas cada semana. Agrega tus membresías y paquetes de créditos.",
          ejemplo: "Pilates Reformer",
          linea: "Lunes · 18:00 · Andrea",
          estado: "8 lugares por clase",
        },
        invitar: {
          titulo: "Invita a tus alumnos",
          texto:
            "Da de alta a tus alumnos, uno por uno o con un archivo, e invítalos a su cuenta. Con su membresía o sus créditos reservan su lugar desde tu página.",
          ejemplo: "Tu página de clases",
          linea: "tuestudio.agendauno.mx",
          estado: "Ya soy alumno · Entrar",
        },
        operar: {
          titulo: "Pasa lista y cobra",
          texto:
            "Pasa lista con llegó, retardo o no vino, y registra los pagos en recepción. Si conectas una pasarela, tus alumnos también pagan en línea desde su cuenta*.",
          ejemplo: "Lista de hoy",
          linea: "Pilates · 18:00 · 7 de 8",
          estado: "Sofía · Retardo",
        },
      },
      operacion: {
        beneficios: {
          lista: "Pase de lista: llegó, retardo o no vino",
          paseQr: "Pase QR de entrada en gimnasios y boxes",
          ventas: "Venta de membresías, paquetes y productos del mostrador",
        },
        enlace: "Empieza con tu estudio",
      },
      pagina: {
        etiqueta: "Tu página pública",
        titulo: "Tu horario de clases, listo para compartir.",
        subtitulo:
          "Una página con tus clases, lugares libres, precios, instructores y sucursales. Tus alumnos entran a reservar; quien es nuevo te pide acceso.",
        beneficios: {
          compartir: "Compártela por Instagram, WhatsApp o un código QR",
          lugares:
            "Tus alumnos ven los lugares libres y reservan con su membresía o sus créditos",
          app: "Tus alumnos la instalan como app, con tu logo",
          dominio: "Con SEO y, si quieres, en tu propio dominio",
          directorio: "Tú decides si también apareces en el directorio público",
        },
        // Ejemplo estático (DemoPaginaPublicaClases); los datos ficticios (estudio,
        // clases y horas) van en el componente.
        demo: {
          abierta: "Agenda abierta",
          semana: "Esta semana",
          lugares: "{n} lugar | {n} lugares",
          lleno: "Lleno",
        },
      },
      precio: {
        titulo: "Un plan que crece con tu estudio.",
        subtitulo:
          "Todas las herramientas desde el primer plan. Tu tarifa mensual se adapta a los alumnos activos de tu estudio.",
      },
      giros: {
        etiqueta: "Para quién es",
        titulo: "Estudios, academias y gimnasios.",
        subtitulo:
          "Pilates, Pole dance, yoga, danza, natación, CrossFit, HYROX y artes marciales: si das clases con cupo, organiza cada lugar.",
        cta: "Empieza con tu estudio",
        pie: "Tus clases. Tus alumnos. Tu próximo lugar reservado.",
        soluciones: "Conoce AgendaUno para tu tipo de estudio",
      },
      faq: {
        alumnos: {
          q: "¿Mis alumnos pueden registrarse solos?",
          a: "No. Tú das de alta a tus alumnos, uno por uno, con un archivo o en recepción, y los invitas a activar su cuenta. En tu página, quien es nuevo te pide acceso y quien ya es alumno entra a reservar.",
        },
        creditos: {
          q: "¿Cómo funcionan las membresías y los créditos?",
          a: "Vendes membresías con vigencia y paquetes de créditos. Cada reserva usa la membresía o un crédito del alumno y, si cancela a tiempo, el crédito regresa según tu política de cancelación.",
        },
        listaEspera: {
          q: "¿Qué pasa cuando una clase se llena?",
          a: "Tus alumnos se anotan en la lista de espera. Si alguien cancela, el lugar se ofrece a quien sigue en la lista, con un tiempo para aceptarlo.",
        },
        asistencia: {
          q: "¿Cómo paso lista?",
          a: "Desde recepción, la agenda o el celular de quien imparte la clase: llegó, retardo o no vino. Al terminar la clase, quien siga sin registro queda como inasistencia y se aplica tu política de faltas.",
        },
        paseQr: {
          q: "¿Para qué sirve el código QR?",
          a: "En gimnasios, CrossFit y HYROX es el pase de entrada de cada miembro: en recepción lo escaneas y ves si puede entrar. En los estudios de clases (pilates, pole, yoga, danza) no hace falta: la asistencia se registra en el pase de lista o, si pones un kiosco de llegada, cuando el alumno escanea su pase.",
        },
        // Pase en Apple y Google Wallet (ADR 0195): se actualiza solo y el negocio
        // decide si lo ofrece.
        wallet: {
          q: "¿Mis alumnos pueden llevar su pase en Apple Wallet o Google Wallet?",
          a: "Sí. Desde su cuenta o la app guardan su pase de entrada en la cartera de su teléfono. Muestra su plan, hasta cuándo vale y su saldo, y se actualiza solo cuando cambian. Recepción y el kiosco de llegada lo escanean igual que el QR de la app. Si pierde su teléfono, lo renueva y el anterior deja de servir; y si prefieres no ofrecerlo, lo apagas.",
        },
        // Wellhub, TotalPass y Fitpass (ADR 0148, 0149, 0197 y 0202), sin «*»: esta
        // sección no lleva la nota de México y lo dice con palabras.
        plataformas: {
          q: "¿Puedo recibir usuarios de Wellhub, TotalPass o Fitpass?",
          a: "Sí. Con Wellhub y TotalPass publicas tus clases en su app: sus reservas ocupan su lugar en tu misma agenda, sin sobrecupo, y el check-in se valida solo. Fitpass no tiene una conexión abierta: apartas sus lugares, recepción registra sus reservas y ves qué asistencias faltan por marcar en su portal. Cada sede puede ser su propio gimnasio y concilias lo que te pagan con su reporte en Excel o CSV. Sus visitantes no cuentan como tus alumnos activos. Wellhub y TotalPass están en los países donde AgendaUno los ofrece; Fitpass, solo en México.",
        },
        // Widgets para la web del negocio (ADR 0141): reservar o comprar con su cuenta.
        web: {
          q: "¿Mis alumnos pueden reservar desde mi propia página web?",
          a: "Sí. Desde Captación copias el código de tu calendario de clases o de tus planes y lo pegas en tu web. Quien ya es tu alumno reserva o compra con su cuenta; quien todavía no, te deja sus datos y llega a tus prospectos. Tú eliges el color, el tema y las webs donde se muestra.",
        },
        sucursales: {
          q: "¿Sirve para varias sucursales?",
          a: "Sí. Cada sucursal tiene sus clases, sus horarios, sus salas y su equipo, y tú ves todo desde el mismo panel.",
        },
        migracion: {
          q: "¿Puedo traer a mis alumnos desde otro sistema?",
          a: "Sí. Importas a tus alumnos, instructores y clases con archivos CSV (los sacas de Excel o de tu sistema actual). Antes de guardar revisamos cada archivo y te decimos qué filas están listas, cuáles ya estaban y cuáles hay que corregir. Los planes y créditos que trae cada alumno se cargan a la fecha de corte que elijas, sin registrar ventas, y sus reservas futuras se importan después, confirmadas o en lista de espera, sin descontar créditos dos veces.",
        },
        preparacion: {
          q: "¿Cuánto tarda dejarlo listo?",
          a: "Depende de cuánto cargues. El asistente de inicio te lleva paso a paso: tu sucursal, tus clases y horarios, tus planes y tu equipo. Con lo básico ya puedes publicar tu página y compartir tu enlace; lo demás lo completas mientras operas.",
        },
        // El correo y el WhatsApp llegan como parámetros (lib/producto: soporte).
        soporte: {
          q: "¿Quién me ayuda si tengo dudas?",
          a: "Nosotros. Escríbenos a {correo} o por WhatsApp al {whatsapp}: te ayudamos a configurar tu negocio, a traer tus datos de otro sistema y con cualquier duda de AgendaUno.",
          // Sin WhatsApp de soporte configurado, solo el correo.
          aSinWhatsapp:
            "Nosotros. Escríbenos a {correo}: te ayudamos a configurar tu negocio, a traer tus datos de otro sistema y con cualquier duda de AgendaUno.",
        },
        permanencia: {
          q: "¿Hay plazo forzoso o contrato anual?",
          a: "No. Pagas cada mes por los alumnos activos que tuviste y puedes dejar de usarlo cuando quieras. No hay costo de instalación.",
        },
        comisiones: {
          q: "¿Cobran comisión por mis ventas o pagos en línea?",
          a: "No. AgendaUno no cobra comisión por tus ventas ni por tus pagos en línea, en ningún plan. La pasarela que conectes (Stripe, Mercado Pago u OpenPay) cobra su propia comisión, directo en tu cuenta.",
        },
        datos: {
          q: "¿Qué pasa con mis datos si me voy?",
          a: "Son tuyos. Descargas a tus alumnos en un archivo cuando quieras, desde Miembros. No usamos tus datos para nada que no sea darte el servicio.",
        },
        facturacion: {
          q: "¿Puedo facturar a mis alumnos?",
          a: "Sí, en México: emites el CFDI de cada venta desde el sistema, lo cancelas o lo sustituyes y haces notas de crédito. Cada factura usa un timbre de tus paquetes.",
        },
        pagos: {
          q: "¿Mis alumnos pueden pagar en línea?",
          a: "Sí, si conectas tu propia pasarela (Stripe, Mercado Pago u OpenPay, en México): tus alumnos compran y pagan su plan desde su cuenta y el dinero llega a tu cuenta de la pasarela. También registras en recepción los pagos en efectivo o por transferencia.",
        },
        // Sin nombrar la otra modalidad ni el otro producto: esta página habla solo de
        // clases. Cada modalidad es un producto (ADR 0108): cambiarla cambia el
        // producto, y solo la cambia el superadmin antes de operar (ADR 0104).
        modalidad: {
          q: "¿Puedo cambiar de modalidad después?",
          a: "AgendaUno es para negocios que dan clases con cupo; la atención uno a uno tiene su propio producto. Si tu negocio quedó en el que no le corresponde, solo el equipo de AgendaUno puede pasarlo al otro, y solo antes de que empieces a operar: mientras no tengas clases ni reservas. Si haces las dos cosas, registra un negocio en cada producto, cada uno con su suscripción.",
        },
        precio: {
          q: "¿Cómo se calcula el precio de AgendaUno?",
          a: "Por rangos de alumnos activos en el mes: cuenta quien tuvo una reserva confirmada en una clase no cancelada o una compra pagada. No cuenta solo por estar registrado. Los precios están en dólares, más impuestos; en México se cobran en pesos al tipo de cambio FIX del Banco de México vigente el día en que se emite el cargo, más IVA.",
        },
      },
      final: {
        titulo: "Prueba AgendaUno con tu próxima clase.",
        subtitulo:
          "Crea una clase, invita a tus alumnos y recibe tu primera reserva. Tienes {dias} días para probarlo, sin tarjeta.",
        otra: "¿Atiendes con cita?",
        // El otro producto (ADR 0108): su nombre llega en {otro}.
        otraEnlace: "Conoce {otro}, para negocios con citas",
      },
    },
    citas: {
      funciones: {
        agendaProfesional: {
          titulo: "Agenda por profesional",
          // Varios profesionales (equipo) y los espacios (recursos) abren en Premium
          // (FuncionesPlan, lib/suscripcion NIVEL_POR_OMISION).
          descripcion:
            "Cada profesional con su horario de atención, sus servicios y sus citas, en una sola vista. Con equipo, en los planes Premium y Pro.",
          detalle:
            "Define la duración, el precio y los tiempos de preparación de cada servicio y, en Premium y Pro, los espacios que usa, como cabinas o sillones. Solo se ofrecen horas con profesional y espacio libres.",
        },
        cualquierProfesional: {
          titulo: "Cualquier profesional disponible",
          descripcion:
            "Tu cliente elige la hora sin tener que elegir a quién; la cita va con quien esté libre.",
          detalle:
            "Si prefiere a alguien en particular, lo elige. Si no, ve los horarios libres de todo tu equipo y la cita se asigna al profesional con menos citas ese día.",
        },
        paquetes: {
          titulo: "Paquetes de servicios",
          descripcion:
            "Arma un servicio que incluye otros, con su propio precio y duración, en una sola cita. En los planes Premium y Pro.",
          detalle:
            "Tus clientes ven qué incluye y, si sale más barato, cuánto costaría por separado. Se agenda, se cobra y se reprograma como cualquier servicio.",
        },
        cobroAlAgendar: {
          titulo: "Cobro en línea al agendar*",
          descripcion:
            "Con una pasarela conectada, tu cliente paga su cita al agendarla o la paga después en tu negocio.",
          detalle:
            "Tú decides si el pago en línea es obligatorio: la cita se aparta hasta pagarla y se libera si no se paga a tiempo. Si no lo es, la cita queda confirmada y se cobra en recepción. También puedes pedir solo un anticipo y cobrar el resto al terminar.",
        },
        recordatorios: {
          titulo: "Recordatorios por correo",
          descripcion:
            "Tus clientes reciben un correo antes de su cita: 24 y 2 horas antes, o con la anticipación que tú configures.",
          detalle:
            "También reciben la confirmación al agendar. Tú editas el texto de cada correo desde Comunicación.",
        },
        // Google Calendar y Outlook (ADR 0199): un calendario aparte, sin datos de
        // clientes; lo ocupado allá se vuelve bloqueo aquí.
        calendarios: {
          titulo: "Google Calendar y Outlook, sin choques",
          descripcion:
            "Cada profesional conecta su Google Calendar u Outlook: sus citas aparecen allá y lo que tiene ocupado allá ya no se ofrece aquí.",
          detalle:
            "Las citas van a un calendario aparte con el nombre de tu negocio, sin datos de tus clientes. De su calendario personal solo se lee si está ocupado, no qué tiene, y los cambios llegan al momento.",
        },
        // Caja (ADR 0175) y cobro mixto (ADR 0177).
        caja: {
          titulo: "Caja con arqueo y cobro mixto",
          descripcion:
            "Cobra una cita con efectivo y tarjeta a la vez, con sus productos y la propina, y cierra cada turno con conteo a ciegas.",
          detalle:
            "Cada sucursal tiene su caja con fondo, y retiros e ingresos con su motivo. Si al contar no cuadra, queda el motivo y lo revisa quien administra; lo ya cerrado no cambia.",
        },
        // Nómina (ADR 0153); «Comisiones y nómina» abre en Premium (ADR 0107).
        comisiones: {
          titulo: "Comisiones y nómina",
          descripcion:
            "Calcula lo que le toca a cada profesional con su esquema de pago: lo ya atendido y lo que viene en el periodo. En los planes Premium y Pro.",
          detalle:
            "Cada tarifa guarda su historial: cambiarla no altera lo que ya se trabajó. Los periodos usan los días y la zona horaria de tu negocio.",
        },
        // Mostrador e inventario (ADR 0139); abre en Premium (ADR 0107).
        inventario: {
          titulo: "Punto de venta e inventario",
          descripcion:
            "Vende productos en el mostrador o junto con la cita, con existencias por sucursal. En los planes Premium y Pro.",
          detalle:
            "Cada venta descuenta su inventario y entra a la caja de su sucursal con su forma de pago, aunque se pague con efectivo y tarjeta a la vez.",
        },
      },
      // «Lo nuevo» (NovedadesLanding): claves de MODALIDADES.citas.novedades, cada una
      // con tres puntos. Anticipo (ADR 0161), presupuestos en línea (ADR 0127),
      // facturas automáticas con CFDI de egreso (ADR 0163; facturación abre en Pro),
      // widget «Agendar cita» (ADR 0141), bandeja (ADR 0200; «mensajes» abre en Pro) y
      // las apps. Cobrar en línea y facturar, solo en México («*»).
      novedades: {
        etiqueta: "Lo nuevo en AgendaUno",
        titulo: "Menos plantones, más citas pagadas y menos trabajo a mano.",
        enfasis: "más citas pagadas",
        subtitulo:
          "Lo último para que tus clientes aparten, paguen y vuelvan, mientras tu equipo atiende todo desde un solo lugar.",
        cta: "Quiero esto en mi negocio",
        items: {
          anticipo: {
            titulo: "Un anticipo al agendar, para que no te dejen plantado*",
            texto:
              "Pide un monto fijo o un porcentaje del servicio para apartar la hora. Tu cliente elige pagar el anticipo o todo, y el resto se cobra al terminar.",
            puntos: {
              p1: "Si cancela a tiempo, se le devuelve solo",
              p2: "Si cancela tarde o no llega, lo conservas",
              p3: "Tu equipo ve lo pagado y lo que falta",
            },
          },
          presupuestos: {
            titulo: "Presupuestos que tu cliente acepta y paga en línea*",
            texto:
              "Mándalo por correo con tu marca. Tu cliente lo abre sin cuenta, lo acepta y lo paga con tu pasarela o en tu negocio, y desde ahí agenda cada servicio.",
            puntos: {
              p1: "Servicios, bonos, productos o conceptos libres",
              p2: "Impuestos incluidos o desglosados",
              p3: "También en su cuenta y en la app",
            },
          },
          factura: {
            titulo: "Facturas que se hacen solas*",
            texto:
              "Al pagarse el anticipo o la cita se timbra su CFDI con los datos fiscales que tu cliente guardó en su cuenta. Al pagar el resto, sale la factura del servicio con el CFDI de egreso que aplica el anticipo.",
            puntos: {
              p1: "Tú la enciendes y tu cliente la pide",
              p2: "Si un timbrado falla, se reintenta solo",
              p3: "En el plan Pro, con tus paquetes de timbres",
            },
          },
          widget: {
            titulo: "Que te agenden desde tu propia web",
            texto:
              "Pon en tu sitio tus servicios con su precio, su duración y su anticipo. Quien visita elige el suyo y agenda, y paga si tú lo pides, sin crear una cuenta.",
            puntos: {
              p1: "Abre tu página con el servicio y la sede ya elegidos",
              p2: "Con tu color, en tema claro u oscuro",
              p3: "Tus bonos también, para captar interesados",
            },
          },
          bandeja: {
            titulo: "WhatsApp e Instagram, en una sola bandeja",
            texto:
              "Lee y contesta los mensajes de tu WhatsApp Business y de tu Instagram desde el panel o la app, con todo tu equipo, y liga cada conversación con la ficha del cliente. En el plan Pro.",
            puntos: {
              p1: "Aviso en el celular cuando alguien escribe",
              p2: "Fotos y PDF dentro de la conversación",
              p3: "Fuera de las 24 horas, con tus plantillas de WhatsApp",
            },
          },
          app: {
            titulo: "Tu negocio, en el celular de tu equipo y de tus clientes",
            texto:
              "Tu equipo agenda, reprograma y cobra desde la app. Tus clientes agendan, pagan, responden sus presupuestos y llevan su pase en Apple Wallet o Google Wallet.",
            puntos: {
              // El widget de inicio solo está en Android (en iPhone falta compilarlo: ios/WIDGET.md).
              p1: "En Android, su próxima cita en la pantalla de inicio",
              p2: "Reprograman o cancelan según tu política",
              p3: "Tu página se instala como app, con tu logo",
            },
          },
        },
      },
      // ModalidadView /citas: claves de MODALIDADES.citas. Agendar sin cuenta (ADR
      // 0093), «Cualquier profesional» (ADR 0062), cobro al agendar* (ADR 0065) y
      // recordatorios solo por correo. Nada de cupos, lista de espera ni clases.
      hero: {
        etiqueta: "Software de citas para profesionales y equipos",
        titulo: "Tus clientes agendan solos. Tú atiendes.",
        enfasis: "agendan solos",
        subtitulo:
          "Organiza servicios, horarios y cobros en una sola agenda. Comparte tu enlace para que tus clientes elijan servicio, profesional y hora, sin crear una cuenta.",
        avisoTitulo: "Cita confirmada",
        avisoDetalle: "Corte y peinado · Hoy 17:30 · con Ana",
      },
      confianza: { cobro: "Planes por profesionales contratados" },
      producto: {
        titulo: "La agenda de todo tu equipo, en una vista.",
        enfasis: "en una vista",
        subtitulo:
          "Explora una agenda de ejemplo, sin registrarte: cada profesional con sus citas, sus servicios y su duración.",
        cta: "Quiero organizar mis citas",
      },
      seccion: {
        titulo: "Lo que tu negocio necesita para crecer.",
        enfasis: "crecer",
        subtitulo:
          "Hecho para citas 1 a 1: el tiempo de cada profesional, bien aprovechado.",
      },
      comoFunciona: {
        subtitulo:
          "Tú defines servicios y horarios. Tus clientes agendan. Tu equipo atiende.",
      },
      recorrido: {
        titulo: "De la cita al corte del día, en un solo lugar.",
        subtitulo:
          "Sigue una misma cita: tu cliente agenda, llega a la agenda de tu profesional, se registra el pago y ves el resultado.",
      },
      pasos: {
        preparar: {
          titulo: "Configura servicios y horarios",
          texto:
            "Agrega tus servicios con su duración y precio, y el horario de atención de cada profesional. Con varias sucursales (Premium y Pro), el de cada una.",
          ejemplo: "Corte y barba",
          linea: "60 min · $350",
          estado: "Marco, Luis y Alex",
        },
        compartir: {
          titulo: "Comparte tu página",
          texto:
            "Pon tu página para agendar en Instagram, WhatsApp o un código QR. Tus clientes eligen servicio, profesional y hora, sin crear una cuenta.",
          ejemplo: "Tu página para agendar",
          linea: "tunegocio.agendauno.mx",
          estado: "Elige servicio y hora",
        },
        operar: {
          titulo: "Atiende y cobra",
          texto:
            "Las citas llegan a la agenda de cada profesional. Registra la llegada y el cobro en recepción, o pide el pago en línea al agendar*.",
          ejemplo: "Cita de hoy",
          linea: "Corte y barba · 17:30 · Marco",
          estado: "Confirmada · Recordatorio por correo",
        },
      },
      operacion: {
        beneficios: {
          llegada: "Llegada de cada cita: llegó, llegó tarde o no vino",
          cobro: "Cobro en recepción o en línea al agendar*",
          pos: "Punto de venta con inventario por sucursal (Premium y Pro)",
        },
        enlace: "Empieza con tu negocio",
      },
      pagina: {
        etiqueta: "Tu página para agendar",
        titulo: "De tu Instagram a una cita en tu agenda.",
        subtitulo:
          "Tus clientes eligen servicio, profesional y hora en una página con tus servicios, precios, equipo y sucursales. No necesitan crear una cuenta.",
        beneficios: {
          compartir: "Compártela por Instagram, WhatsApp o un código QR",
          cualquiera:
            "«Cualquier profesional» muestra las horas libres de todo tu equipo",
          cobro: "Si conectas tu pasarela, puedes pedir el pago al agendar*",
          app: "Tus clientes la instalan como app, con tu logo",
          // El dominio propio es del sitio avanzado («Plus», ADR 0167 y 0189).
          dominio: "Con SEO y, en Premium y Pro, en tu propio dominio",
        },
        // Ejemplo interactivo (DemoPaginaPublicaCitas): servicio → profesional →
        // hora. Los datos ficticios (negocio, servicios, equipo y horas) van en el
        // componente.
        demo: {
          aria: "Ejemplo de una página para agendar",
          ejemplo: "Datos de ejemplo",
          servicio: "Servicio",
          conQuien: "Con quién",
          hora: "Hora · Hoy",
          cualquiera: "Cualquier profesional",
          resumen: "{servicio} · Hoy {hora} · {con}",
          confirmar: "Confirmar cita",
        },
      },
      precio: {
        titulo: "Tu agenda, a solas o con todo tu equipo.",
        subtitulo:
          "Individual si atiendes por tu cuenta. Premium o Pro cuando trabajas con un equipo. Elige el plan y los profesionales que necesitas.",
      },
      giros: {
        etiqueta: "Para quién es",
        titulo: "Barberías, estéticas, spas y consultorios.",
        subtitulo:
          "Barberías, estéticas, spas, wellness, terapeutas, psicólogos, nutriólogos y dentistas: si atiendes con cita, uno a uno, organiza el tiempo de tu equipo.",
        cta: "Empieza con tu negocio",
        pie: "Tu agenda. Tu equipo. Tu próxima cita.",
        soluciones: "Conoce AgendaUno para tu tipo de negocio",
      },
      faq: {
        cuenta: {
          q: "¿Mis clientes necesitan crear una cuenta?",
          a: "No. Agendan desde tu página con su nombre, correo y teléfono, y su ficha queda en tu lista de clientes. Si quieres que tengan cuenta, tú los invitas.",
        },
        cualquierProfesional: {
          q: "¿Mi cliente tiene que elegir profesional?",
          a: "No. Con más de un profesional, «Cualquier profesional» le muestra las horas libres de todo tu equipo y la cita se asigna a quien tiene menos citas ese día. Si prefiere a alguien, lo elige.",
        },
        cobro: {
          q: "¿Puedo cobrar al agendar?",
          a: "Sí*. Conecta tu pasarela y decide si pides el pago en línea al agendar: la cita se aparta hasta que se paga. Si no lo pides, queda confirmada y se cobra en tu negocio. También puedes pedir solo un anticipo, un monto fijo o un porcentaje del servicio: si tu cliente cancela a tiempo se le devuelve solo, y si cancela tarde o no llega, lo conservas.",
        },
        // El widget «Agendar cita» (ADR 0141): abre la página para agendar con el
        // servicio y la sede ya elegidos.
        web: {
          q: "¿Mis clientes pueden agendar desde mi propia página web?",
          a: "Sí. Desde Captación copias el código de «Agendar cita» y lo pegas en tu web: muestra tus servicios con su precio, su duración y su anticipo, y cada uno abre tu página para agendar con ese servicio ya elegido. Tu cliente agenda y, si lo pides, paga*, sin crear una cuenta.",
        },
        // Presupuestos en línea (ADR 0127, ajuste del 2026-10-21).
        presupuestos: {
          q: "¿Puedo mandar presupuestos y cobrarlos en línea?",
          a: "Sí. Armas el presupuesto con tus servicios, bonos, productos o conceptos libres, con impuestos incluidos o desglosados, y lo mandas por correo con tu marca. Tu cliente lo abre sin cuenta, o desde su cuenta y la app, y lo acepta o lo rechaza. Al aceptarlo se vuelve su cobro: lo paga en línea con tu pasarela* o en tu negocio, y desde ahí agenda cada servicio.",
        },
        // Facturas automáticas de citas (ADR 0163): facturación abre en Pro (ADR 0107).
        factura: {
          q: "¿Puedo facturar las citas y los anticipos en automático?",
          a: "Sí*, en el plan Pro. Tú enciendes la factura automática y tu cliente la pide con los datos para facturar que guarda en su cuenta. Al pagarse el anticipo o la cita se timbra su CFDI; al pagar el resto, la factura del servicio y el CFDI de egreso que aplica el anticipo, como pide el SAT. Cada CFDI usa un timbre de tus paquetes.",
        },
        recordatorios: {
          q: "¿Mis clientes reciben recordatorios?",
          a: "Sí, por correo: la confirmación al agendar y recordatorios antes de la cita, con la anticipación que tú configures.",
        },
        paquetes: {
          q: "¿Puedo vender paquetes de servicios?",
          a: "Sí, en los planes Premium y Pro. Un paquete junta varios servicios de tu catálogo con un solo precio y se agenda en una sola cita; tu cliente ve qué incluye antes de agendar. También puedes vender bonos de sesiones (por ejemplo, 5 cortes); venderlos en línea es del plan Pro.",
        },
        espacios: {
          q: "¿Puedo manejar cabinas, sillones o consultorios?",
          a: "Sí, en los planes Premium y Pro. Indica qué espacios usa cada servicio y solo se ofrecen horas con un espacio libre, para que dos citas no choquen en la misma cabina.",
        },
        salud: {
          q: "¿Sirve para consultorios o profesionales de la salud?",
          a: "Sí, para organizar citas, disponibilidad, recordatorios y cobros. AgendaUno no sustituye un expediente clínico ni un sistema médico especializado.",
        },
        migracion: {
          q: "¿Puedo traer a mis clientes desde otro sistema?",
          a: "Sí. Importas a tus clientes y a tu equipo con archivos CSV (los sacas de Excel o de tu sistema actual). Antes de guardar revisamos cada archivo y te decimos qué filas están listas, cuáles ya estaban y cuáles hay que corregir. Los bonos que trae cada cliente se cargan a la fecha de corte que elijas, sin registrar ventas, y las citas que ya tenías agendadas se importan sin avisarle nada a tus clientes.",
        },
        preparacion: {
          q: "¿Cuánto tarda dejarlo listo?",
          a: "Depende de cuánto cargues. El asistente de inicio te lleva paso a paso: tu sucursal, tus servicios, tus horarios de atención y tu equipo. Con lo básico ya puedes publicar tu página y compartir tu enlace; lo demás lo completas mientras operas.",
        },
        soporte: {
          q: "¿Quién me ayuda si tengo dudas?",
          a: "Nosotros. Escríbenos a {correo} o por WhatsApp al {whatsapp}: te ayudamos a configurar tu negocio, a traer tus datos de otro sistema y con cualquier duda de AgendaUno.",
          // Sin WhatsApp de soporte configurado, solo el correo.
          aSinWhatsapp:
            "Nosotros. Escríbenos a {correo}: te ayudamos a configurar tu negocio, a traer tus datos de otro sistema y con cualquier duda de AgendaUno.",
        },
        // Sin nombrar la otra modalidad ni el otro producto: esta página habla solo de
        // citas (ADR 0104 y 0108, como en `landing.clases.faq.modalidad`).
        modalidad: {
          q: "¿Puedo cambiar de modalidad después?",
          a: "AgendaUno es para negocios que atienden con cita, uno a uno; los grupos con horario y lugares limitados tienen su propio producto. Si tu negocio quedó en el que no le corresponde, solo el equipo de AgendaUno puede pasarlo al otro, y solo antes de que empieces a operar: mientras no tengas citas ni reservas. Si haces las dos cosas, registra un negocio en cada producto, cada uno con su suscripción.",
        },
        precio: {
          q: "¿Cómo se calcula el precio de AgendaUno?",
          a: "Eliges un plan (Individual, Premium o Pro) y cuántos profesionales contratas, y pagas por adelantado, cada mes o cada año (el anual cuesta {mesesAnual} meses). Puedes cambiar de plan cuando quieras. Los precios están en dólares, más impuestos; en México se cobran en pesos al tipo de cambio FIX del Banco de México vigente el día en que se emite el cargo, más IVA.",
        },
      },
      final: {
        titulo: "Prueba AgendaUno con tu próxima cita.",
        subtitulo:
          "Agrega un servicio, comparte tu página y recibe tu primera cita. Tienes {dias} días para probarlo, sin tarjeta.",
        otra: "¿Das clases con cupo?",
        otraEnlace: "Conoce {otro}, para negocios con clases",
      },
    },
    // Portada «/» (LandingView): corta, para elegir modalidad. Cada negocio es solo de
    // clases o solo de citas (ADR 0104); lo de cada una vive en /clases y /citas.
    portada: {
      subtitulo:
        "Agenda, reservas en línea, cobros y tu equipo en un solo lugar.",
      elegir: "¿Cómo atiendes a tus clientes?",
      opciones: {
        clases: "Doy clases",
        clasesGiros: "Estudios, gimnasios y academias",
        citas: "Atiendo con cita",
        citasGiros: "Barberías, estéticas, spas y consultorios",
      },
      unaModalidad:
        "Cada negocio trabaja con una modalidad, clases con cupo o citas 1 a 1, y la eliges al registrarte.",
      modalidades: {
        etiqueta: "Dos formas de trabajar",
        titulo: "Tu negocio tiene su ritmo. Tu agenda también.",
        subtitulo:
          "Organiza los lugares de cada clase o el tiempo de cada profesional. Elige la tuya para conocerla a fondo.",
      },
      precios: {
        etiqueta: "Tu suscripción a AgendaUno",
        titulo: "Dos modelos de suscripción. Uno para tu forma de trabajar.",
        subtitulo:
          "Pagas por alumnos activos si das clases, o eliges tu plan por los profesionales que contratas si atiendes con cita.",
        clases: "Por alumnos activos",
        citas: "Por plan y profesionales",
        desde: "Desde",
        // {moneda}: la de la tarifa publicada de esa modalidad.
        porMes: "{moneda} / mes",
        iva: "Más impuestos",
        verClases: "Ver precios de clases",
        verCitas: "Ver precios de citas",
        prueba: "Pruébalo {dias} días gratis, sin tarjeta, antes de contratar.",
      },
      giros: {
        etiqueta: "Encuentra tu forma de trabajar",
        titulo: "Conoce AgendaUno para tu tipo de negocio.",
        verClases: "Todo sobre las clases",
        verCitas: "Todo sobre las citas",
      },
      faq: {
        titulo: "Preguntas frecuentes",
        prueba: "¿Necesito tarjeta para la prueba?",
        pruebaR:
          "No. Pruebas AgendaUno gratis durante {dias} días, sin tarjeta y sin compromiso.",
        cancelacion: "¿Hay plazo forzoso o penalización por cancelar?",
        cancelacionR:
          "No. No hay permanencia forzosa ni penalización por cancelar. Pagas mes con mes según los alumnos activos de tu negocio, o tu plan de citas mensual o anual.",
        datos: "¿Qué pasa con la información de mi negocio?",
        datosR:
          "Cada negocio tiene su propia base de datos, separada de la de los demás, y se respalda todos los días. Tus clientes pueden pedir el acceso, la corrección o la cancelación de sus datos, como indica el aviso de privacidad.",
        dosNegocios: "¿Puedo tener clases y citas en el mismo negocio?",
        dosNegociosR:
          "No. Cada negocio trabaja con una modalidad, clases con cupo o citas 1 a 1, y la eliges al registrarte. Si das clases y también atiendes con cita, registra dos negocios, cada uno con su cuenta, su agenda y su suscripción.",
        // Quién cambia la modalidad (ADR 0104): aquí y no en el registro.
        cambiarModalidad: "¿Puedo cambiar de clases a citas después?",
        cambiarModalidadR:
          "Solo AgendaUno puede cambiar la modalidad, de clases a citas o al revés, y solo antes de que empieces a operar: mientras tu negocio no tenga clases, citas ni reservas.",
      },
      final: {
        titulo: "Prueba AgendaUno con tu próxima clase o cita.",
        subtitulo:
          "Elige tu modalidad, configura tu negocio y comparte tu enlace. Tienes {dias} días para probarlo, sin tarjeta.",
        clases: "Probar con clases",
        citas: "Probar con citas",
      },
    },
    // Lo que reemplaza y lo que nadie más da (comparativo del 2026-10-20).
    porQue: {
      etiqueta: "Por qué AgendaUno",
      // Las filas de ReemplazaHerramientas: si cambian, cambia el número.
      titulo: "Un solo sistema en lugar de ocho.",
      subtitulo:
        "Lo que hoy resuelves con hojas de cálculo, mensajes a mano y servicios por separado, en un solo lugar y con un solo pago al mes.",
      aparte: "Si quieres más ayuda",
    },
    heroEscritura: {
      prefijo: "Una agenda para",
      pausar: "Pausar texto animado",
      reanudar: "Reanudar texto animado",
      negocios: {
        pilates: "estudios de Pilates",
        pole: "estudios de Pole dance",
        academias: "academias",
        acuaticas: "escuelas de natación",
        yoga: "estudios de yoga",
        crossfit: "boxes de CrossFit",
        hyrox: "centros de HYROX",
        barberias: "barberías",
        esteticas: "estéticas",
        spas: "spas",
        wellness: "centros de bienestar",
        terapeutas: "terapeutas",
        dentistas: "consultorios dentales",
        psicologos: "psicólogos",
        nutriologos: "nutriólogos",
      },
    },
    titulo: "Menos pendientes. Más tiempo para tus clientes.",
    ctaRegistrar: "Probar AgendaUno gratis",
    heroVisual: {
      pole: "Pole dance",
      poleAlt: "Atleta practicando Pole dance en un estudio profesional",
      barberia: "Barberías",
      barberiaAlt: "Barbero atendiendo a un cliente en una barbería moderna",
      hyrox: "HYROX",
      hyroxAlt:
        "Atleta empujando un trineo en un centro de entrenamiento funcional",
      confirmada: "Reserva confirmada",
      confirmadaDetalle: "Pole dance · Hoy 18:00",
    },
    prueba: "Prueba gratis de {dias} días. Sin tarjeta.",
    pieHero: "Cancela cuando quieras.",
    confianza: {
      titulo: "Condiciones de la prueba y operación",
      // {dias}: los de la tarifa publicada (SellosConfianza).
      prueba: "{dias} días gratis, sin tarjeta",
      configuracion: "Configuración guiada para empezar",
      // Portada: los dos modelos (clases por alumnos, citas por profesionales).
      cobro: "Precio por alumnos activos o por profesionales",
      cancelacion: "Sin permanencia forzosa",
    },
    funciones: {
      agenda: "Agenda por profesional, recurso o clase",
      agendaDesc:
        "Disponibilidad individual por barbero o profesional, horarios recurrentes, duración, cabinas y cupos por clase.",
      reservas: "Reservas en línea",
      reservasDesc:
        "Cada persona elige servicio, profesional y hora; para clases, la lista de espera llena las bajas.",
      membresias: "Paquetes y membresías",
      membresiasDesc:
        "Créditos, mensualidades y planes con renovación automática.",
      pagos: "Cobros en línea*",
      pagosDesc:
        "Conecta una pasarela compatible o registra pagos en recepción desde el mismo flujo.",
      pos: "Punto de venta",
      posDesc: "Vende productos y controla tu inventario por sucursal.",
      reportes: "Reportes de rentabilidad",
      reportesDesc:
        "Mira qué servicios, clases y horarios dejan más y decide con datos.",
    },
    paraQuien: {
      saludTitulo: "Para profesionales de la salud:",
      saludAlcance:
        "organiza citas, disponibilidad y cobros de dentistas, psicólogos, nutriólogos y otros profesionales. No sustituye un expediente clínico ni un sistema médico especializado.",
      negocios: {
        crossfit: {
          nombre: "CrossFit",
          descripcion:
            "Clases, cupos y membresías para la comunidad de tu box.",
          alt: "Atleta realizando una sentadilla frontal con barra en un box de entrenamiento",
        },
        hyrox: {
          nombre: "HYROX",
          descripcion:
            "Sesiones de entrenamiento, grupos y reservas para tu centro.",
          alt: "Atleta empujando un trineo durante una sesión de entrenamiento funcional",
        },
        nutriologos: {
          nombre: "Nutriólogos",
          descripcion:
            "Consultas de primera vez y seguimiento, horarios y cobros por profesional.",
          alt: "Nutriólogo conversando con una paciente durante una consulta de nutrición",
        },
        barberia: {
          nombre: "Barberías",
          descripcion:
            "Cada cliente elige servicio, barbero, sede y horario disponible.",
          alt: "Barbero atendiendo a un cliente en una barbería moderna",
        },
        estetica: {
          nombre: "Peluquerías y estéticas",
          descripcion:
            "Citas por estilista, cabinas, duración y cobro en línea al agendar*.",
          alt: "Estilista atendiendo a una clienta en un salón de belleza",
        },
        pilates: {
          nombre: "Pilates",
          descripcion: "Reformer, mat y paquetes por sesiones.",
          alt: "Alumna practicando Pilates Reformer en un estudio moderno",
        },
        pole: {
          nombre: "Pole dance",
          descripcion: "Cupos, horarios y asistencia en cada clase.",
          alt: "Atleta practicando Pole dance en un estudio profesional",
        },
        gimnasio: {
          nombre: "Gimnasios",
          // El cobro automático con tarjeta guardada es en línea: solo México.
          descripcion: "Membresías, accesos y cobros recurrentes*.",
          alt: "Alumna entrenando con pesa rusa acompañada por un instructor",
        },
        yoga: {
          nombre: "Yoga",
          descripcion: "Pases, talleres y reservas sin complicaciones.",
          alt: "Hombre practicando la postura del árbol en un estudio de yoga",
        },
        danza: {
          nombre: "Danza",
          descripcion: "Niveles, ensayos y mensualidades organizadas.",
          alt: "Clase de danza contemporánea en un estudio luminoso",
        },
        academias: {
          nombre: "Academias",
          descripcion: "Grupos, ciclos y pagos por alumno.",
          alt: "Instructora dirigiendo una clase de artes marciales para adultos",
        },
        acuaticas: {
          // El nombre del giro, como en el registro («Escuela de natación»).
          nombre: "Natación",
          descripcion: "Clases de natación, cupos y horarios por grupo.",
          alt: "Nadadora entrenando en una alberca de carriles",
        },
        psicologos: {
          nombre: "Psicólogos",
          descripcion: "Organiza tus horarios de consulta, citas y cobros.",
          alt: "Psicóloga conversando con un paciente adulto en su consultorio",
        },
        wellness: {
          nombre: "Wellness",
          descripcion:
            "Sesiones de bienestar, horarios y reservas por profesional.",
          // De citas (ADR 0104): la foto es una sesión individual, no una clase.
          alt: "Profesional de bienestar acompañando a una clienta en una sesión individual de respiración",
        },
        spa: {
          nombre: "Spas",
          descripcion: "Servicios, duración y citas por especialista.",
          alt: "Profesional de spa realizando un masaje relajante de hombros",
        },
        terapeutas: {
          nombre: "Terapeutas",
          descripcion: "Sesiones, disponibilidad y cobros por profesional.",
          alt: "Fisioterapeuta guiando un ejercicio de movilidad de brazo",
        },
        dentistas: {
          nombre: "Dentistas",
          descripcion:
            "Citas por dentista, disponibilidad y cobros en un solo lugar.",
          alt: "Profesional dental conversando con un paciente antes de una cita",
        },
      },
    },
  },
  // Celular con su lada (components/CampoCelular.vue, ADR 0103).
  campoCelular: {
    lada: "Lada del país",
    frecuentes: "Más usados",
    todos: "Todos los países",
  },
  // Lista larga con buscador (components/SelectorBuscable.vue).
  selectorBuscable: {
    sinCoincidencias: "Nada coincide con esa búsqueda.",
  },
  registro: {
    titulo: "Crea tu negocio en AgendaUno",
    pais: "País de tu negocio",
    paisAyuda:
      "Con él te proponemos la lada de los celulares y la zona horaria. Puedes cambiarlo después.",
    subtitulo: "En un minuto tendrás tu agenda lista para configurar.",
    // Lo mismo que anuncia la landing, del mismo dato (C01).
    pruebaSinTarjeta: "Tienes {dias} días de prueba gratis, sin tarjeta.",
    nombre: "Nombre del negocio",
    nombrePh: "Distrito 27",
    perfil: "Tipo de negocio",
    // Llegó con su giro (`?giro=`): «Tipo de negocio: Barbería · Citas 1 a 1».
    perfilElegido: "Tipo de negocio:",
    perfilPh: "Selecciona una opción",
    // El giro prepara la configuración inicial y los nombres; no limita lo que ofrece.
    perfilAyuda:
      "Elige el más parecido: sirve para preparar tu configuración inicial y los nombres que verás en tu panel; no te limita a ese giro.",
    perfiles: {
      barberia: "Barbería",
      estetica: "Estética o centro de belleza",
      salon: "Salón de belleza o peluquería",
      spa: "Spa o centro de bienestar",
      salud: "Consultorio, nutrición o profesional de la salud",
      pilates: "Estudio de Pilates",
      pole: "Estudio de Pole dance",
      cycling: "Estudio de cycling",
      yoga: "Estudio de Yoga",
      danza: "Escuela de danza",
      gimnasio: "Gimnasio",
      crossfit: "CrossFit",
      hyrox: "HYROX",
      natacion: "Escuela de natación",
      academia: "Academia o escuela",
      general: "Otro negocio con clases",
      general_citas: "Otro negocio de citas",
    },
    slug: "Dirección",
    slugAyuda: "Así te encontrarán: {slug}.agendauno.mx",
    slugLibre: "Disponible",
    slugOcupado: "No disponible",
    personalizar: "Personalizar",
    contactoNombre: "Tu nombre",
    contactoEmail: "Tu correo",
    correoAyuda: "Con este correo activarás tu cuenta de propietario.",
    terminos: "Acepto los términos y el aviso de privacidad",
    aceptoInicio: "Acepto los",
    terminosEnlace: "términos",
    yEl: "y el",
    avisoEnlace: "aviso de privacidad",
    avisoTitulo: "Aviso de privacidad",
    terminosTitulo: "Términos y condiciones",
    pasoActual: "Paso {n} de 3",
    progreso: "Progreso del registro",
    // El ejemplo de cada producto (ADR 0131).
    ejemploCita: {
      etiqueta: "Ejemplo de cita",
      titulo: "Sesión de terapia",
      detalle: "10:30 · Confirmada",
    },
    ejemploClase: {
      etiqueta: "Ejemplo de clase",
      titulo: "Pilates Reformer",
      detalle: "18:00 · 7 de 8 lugares",
    },
    paso1: "Tu negocio",
    paso2: "Tus datos",
    paso3: "Contacto",
    intro1: "Empecemos por el nombre y el tipo de tu negocio.",
    intro2: "¿Cómo te llamas? Así sabremos con quién hablamos.",
    intro3:
      "Un WhatsApp y un correo para validar tu cuenta y ayudarte a arrancar.",
    siguiente: "Continuar",
    atras: "Atrás",
    contactoSegundoNombre: "Segundo nombre",
    contactoPrimerApellido: "Apellido paterno",
    contactoSegundoApellido: "Apellido materno",
    whatsapp: "WhatsApp",
    whatsappNumeroPh: "Tu número de celular",
    whatsappAyuda: "Te contactaremos por aquí para ayudarte a arrancar.",
    whatsappAvisos: "Recibir avisos de AgendaUno por WhatsApp",
    whatsappExplica:
      "Te mandamos un código a este número para confirmar que es tuyo.",
    whatsappEnviar: "Enviar código",
    whatsappCodigo: "Código de 6 dígitos",
    whatsappReenviar: "Enviar otro código",
    whatsappReenviarEn: "Puedes pedir otro en {s} s",
    whatsappVerificado: "WhatsApp verificado",
    opcional: "Opcional",
    crear: "Crear mi negocio",
    creando: "Creando…",
    exitoTitulo: "¡Tu negocio está listo!",
    exitoDesc:
      "Activa tu cuenta de propietario para entrar. Te enviamos un enlace a {email}.",
    pendienteTitulo: "Revisa tu correo",
    pendienteDesc:
      "Te enviamos un enlace de activación a {email}. Ábrelo para definir tu contraseña y entrar. Si no lo ves, revisa spam.",
    reenviar: "Reenviar correo de activación",
    reenviando: "Reenviando…",
    reenviado: "Listo, reenviamos el correo a {email}.",
    tokenDev: "Token de activación (solo desarrollo):",
    irActivar: "Activar mi cuenta",
    yaActivar: "Ya tengo el token",
  },
  activacion: {
    titulo: "Activa tu cuenta",
    subtitulo: "Define tu contraseña para entrar a {estudio}.",
    email: "Correo",
    token: "Token de activación",
    password: "Contraseña",
    passwordConfirm: "Confirma la contraseña",
    activar: "Activar y continuar",
    activando: "Activando…",
    exito: "Cuenta activada. Ya puedes entrar.",
    irEntrar: "Entrar",
    desdeCorreo: "Vas a activar la cuenta {email}. Solo define tu contraseña.",
    otrosDatos: "Usar otros datos",
    // El enlace venció o no sirvió (ADR 0169).
    pedirOtro: "Enviarme un enlace nuevo",
    pidiendoOtro: "Enviando…",
    reenviado:
      "Si la cuenta {email} sigue pendiente, te enviamos un enlace nuevo. Revisa tu correo.",
  },
  // La dirección no existe (ADR 0174).
  noEncontrada: {
    etiqueta: "Error 404",
    titulo: "No encontramos esta página",
    texto:
      "Puede que la dirección esté mal escrita o que la página ya no exista.",
    tituloPagina: "Página no encontrada",
    inicio: "Ir al inicio",
    miInicio: "Ir a mi inicio",
    volver: "Volver",
  },
  directorio: {
    etiqueta: "Acceso a negocios",
    titulo: "Encuentra tu negocio.",
    subtitulo:
      "Busca el estudio, academia o gimnasio que te compartió AgendaUno y abre su página para reservar tu clase o entrar.",
    // TurnoUno: negocios de citas (ADR 0131).
    subtituloCitas:
      "Busca la barbería, spa, consultorio o profesional que te compartió TurnoUno y abre su página para agendar tu cita o entrar.",
    vacio: "Aún no hay negocios publicados en esta categoría.",
    buscar: "Negocio, servicio, ciudad o país",
    buscarCta: "Buscar",
    disciplina: "Tipo de negocio",
    todas: "Todos los negocios",
    categoriasTitulo: "¿Qué estás buscando?",
    limpiar: "Limpiar filtros",
    resultados: "Resultados encontrados: {n}",
    entrar: "Entrar",
    verEstudio: "Ver servicios y horarios",
    ubicacionPendiente: "Ubicación por confirmar",
    sinResultados: "Sin resultados para “{q}”.",
    duenoTitulo: "¿Administras un negocio con agenda?",
    duenoDesc:
      "Crea tu cuenta, publica tus clases y recibe reservas desde tu propia página.",
    duenoDescCitas:
      "Crea tu cuenta, publica tus servicios y recibe citas desde tu propia página.",
    // Producto que aún no recibe registros (ADR 0108): el botón lleva a la lista de
    // interesados.
    duenoDescPrelanzamiento:
      "AgendaUno abre pronto. Déjanos tus datos y te avisamos en cuanto puedas registrar tu negocio.",
  },
  // Calendario público de clases (plan de mejoras M02).
  calendarioPublico: {
    dias: "Días de la semana",
    semanaAnterior: "Semana anterior",
    semanaSiguiente: "Semana siguiente",
    sede: "Sede",
    actividad: "Actividad",
    instructor: "Instructor",
    todasSedes: "Todas las sedes",
    todasActividades: "Todas las clases",
    todosInstructores: "Todos los instructores",
    sinClasesDia: "No hay clases este día.",
    verDia: "Ver el {fecha}",
    siguiente: "Siguiente fecha con clases: {fecha}",
    minutos: "{n} min",
    lugares: "{libres} de {total} lugares",
    llena: "Llena",
    cupoAbierto: "Cupo abierto",
  },
  escaparate: {
    cargando: "Cargando el negocio…",
    noDisponible: "Este negocio no está disponible por ahora.",
    volverDirectorio: "← Volver a negocios",
    // Abre «Pide tu acceso» (registro cerrado, ADR 0093): no reserva todavía.
    reservar: "Pedir mi primera clase",
    agendarCita: "Agendar una cita",
    agendaEnLinea: "Agenda en línea",
    avisoPrivacidad: "Aviso de privacidad",
    agendaCitasTitulo: "Reserva a tu manera",
    agendaCitasDesc:
      "Elige el servicio, la persona que te atenderá y un horario disponible. Puedes pagar en línea si el negocio lo requiere.",
    agendaPaso1: "Elige servicio",
    agendaPaso2: "Elige profesional",
    agendaPaso3: "Elige horario",
    pedirAcceso: "Pedir acceso",
    // Pedir lugar en una clase concreta: llega a Prospectos con esa clase (ADR 0117).
    pedirLugar: "Pedir este lugar",
    // Registro cerrado (ADR 0093): el negocio da de alta a sus clientes.
    acceso: {
      titulo: "Pide tu acceso",
      explicacion:
        "Las cuentas de {estudio} las crea el negocio. Pídele tu acceso: te llegará una invitación a tu correo para activar tu cuenta.",
      whatsapp: "Pedirlo por WhatsApp",
      llamar: "Llamar al {telefono}",
      yaTengo: "Ya tengo cuenta · Entrar",
    },
    yaSoyAlumno: "Ya soy alumno · Entrar",
    yaSoyCliente: "Ya soy cliente · Entrar",
    proximasClases: "Próximas clases",
    sinClases: "Aún no hay clases publicadas.",
    precios: "Precios",
    sinPrecios: "Aún no hay precios publicados.",
    errorPrecios: "No se pudieron cargar los precios.",
    instructores: "Instructores",
    profesionales: "Equipo profesional",
    profesionalesDesc:
      "Consulta disponibilidad y elige a tu {profesional} al reservar.",
    ubicacion: "Ubicación",
    resenas: "Lo que dicen sus clientes",
    promedio: "{promedio} de 5",
    totalResenas: "1 reseña | {n} reseñas",
    estrellas: "{n} de 5 estrellas",
    lugaresLibres: "{n} lugares",
    lleno: "Lleno",
    cupoAbierto: "Cupo abierto",
    creditos: "1 crédito | {n} créditos",
    // En citas un bono incluye sesiones (ADR 0130).
    creditosCitas: "1 sesión | {n} sesiones",
    ilimitado: "Ilimitado",
    // Planes para comparar antes de comprar (M03/B03).
    planes: {
      destacado: "Recomendado",
      primeraVez: "Para tu primera vez",
      primeraVezAyuda: "Solo para quien viene por primera vez, una vez.",
      // Dónde vale (M06): nunca «multisucursal» sin decir cuáles.
      todasLasSedes: "Todas las sedes incluidas",
      soloEn: "Solo en {sedes}",
      // Qué deja reservar un plan restringido a unas clases o servicios.
      soloClases: "Clases: {lista}",
      soloServicios: "Servicios: {lista}",
      deSede: "Planes que valen en {sede}.",
      verTodos: "Ver todos",
      porMes: "1 crédito al mes | {n} créditos al mes",
      porMesCitas: "1 sesión al mes | {n} sesiones al mes",
      paraActividad: "Para {actividad}",
      vence: "Si lo pagas hoy, se puede usar hasta el {fecha}",
      renueva: "Se renueva cada mes mientras la pagues",
      // Deja sus datos para ese plan (Prospectos); no lo compra aquí.
      loQuiero: "Me interesa",
      permanencia:
        "Permanencia mínima de 1 mes | Permanencia mínima de {n} meses",
      grupos: {
        primera_vez: "Para tu primera vez",
        sesion_individual: "Clase suelta",
        paquete: "Paquetes de clases",
        membresia: "Membresías",
        otro: "Talleres y pases",
        add_on: "Clases extra",
      },
      gruposCitas: {
        primera_vez: "Para tu primera visita",
        sesion_individual: "Sesión suelta",
        paquete: "Bonos de sesiones",
        membresia: "Membresías",
        otro: "Otros",
        add_on: "Extras",
      },
    },
    registro: {
      tipos: {
        membresia: "Membresía",
        paquete: "Paquete",
        paqueteCitas: "Bono",
        pase_dia: "Pase del día",
        sesion_individual: "Sesión individual",
        add_on: "Extra",
        taller: "Taller",
      },
    },
  },
  entrar: {
    etiqueta: "Tu operación empieza aquí",
    titulo: "Iniciar sesión",
    tituloEstudio: "Entrar a {nombre}",
    otraSesion:
      "Tienes la sesión abierta en {negocio}. Si entras aquí, en este navegador se cambia a este negocio.",
    volverAlPanel: "Volver a mi panel",
    subtitulo: "Consulta tu agenda, equipo, clientes y cobros.",
    subtituloEstudio: "Ingresa tu correo y contraseña para continuar.",
    selectorTitulo: "¿A qué negocio quieres entrar?",
    selectorSubtitulo:
      "Elige uno de tus negocios recientes o encuentra el tuyo.",
    recientesTitulo: "Tus negocios recientes",
    recientesPrivacidad: "En este dispositivo",
    accesoGuardado: "Acceso guardado",
    olvidar: "Olvidar",
    olvidarAria: "Olvidar acceso a {nombre}",
    buscarTitulo: "Busca otro negocio",
    buscarPlaceholder: "Nombre de tu estudio",
    buscarPlaceholderCitas: "Nombre de tu negocio",
    selectorVacioClases:
      "Escribe el nombre de tu estudio, academia o gimnasio para acceder.",
    selectorVacioCitas:
      "Escribe el nombre de tu barbería, spa o consultorio para acceder.",
    buscar: "Buscar",
    buscando: "Buscando…",
    sinResultados: "No encontramos negocios con esa búsqueda.",
    // Un negocio del otro producto (ADR 0136): cada producto es un sitio aparte.
    otroProducto: "{negocio} usa {producto}.",
    irAlOtroProducto: "Entra desde {producto}",
    negocioNoDisponible:
      "Ese negocio ya no está disponible. Busca otro para continuar.",
    // El negocio no se pudo consultar (sin red o el servidor no respondió): no es
    // que ya no exista.
    sinConexion:
      "No pudimos conectar con el servidor. Revisa tu conexión y vuelve a intentarlo.",
    elegirOtro: "Elegir otro negocio",
    slug: "Dirección de tu negocio",
    slugPh: "mi-negocio",
    estudioFijo: "Estás entrando a este negocio.",
    email: "Correo",
    password: "Contraseña",
    entrar: "Entrar",
    entrando: "Entrando…",
    google: "Continuar con Google",
    googleEnRaiz: "Para entrar con Google te llevamos a {host}.",
    ayudaSlug:
      "Es la dirección web de tu negocio, por ejemplo: mi-negocio.agendauno.mx",
    panelTitulo: "Todo tu día, en una sola agenda.",
    panelDescCitas:
      "Organiza tus citas, recibe a tus clientes y cobra sin perder el ritmo del día.",
    visualRegistroTitulo: "Dale espacio a lo que sigue.",
    visualRegistroDesc:
      "Tu negocio, tu equipo y tus reservas. Empieza a organizarlos en un mismo lugar.",
    panelDesc:
      "Mira lo que sigue, confirma asistencias y cobra sin perder el ritmo del día.",
    // El collage de cada producto (ADR 0131): clases en AgendaUno, citas en TurnoUno.
    collageClases: {
      uno: "Pilates Reformer",
      unoDetalle: "10:30 · Lugar reservado",
      dos: "Pole dance básico",
      dosDetalle: "18:00 · 7 de 8 lugares",
      tres: "Yoga matutino",
      tresDetalle: "07:00 · Andrea · Asistió",
    },
    collageCitas: {
      uno: "Corte y barba",
      unoDetalle: "12:30 · Marco · Confirmada",
      dos: "Corte y peinado",
      dosDetalle: "16:00 · con Sofía",
      tres: "Masaje relajante",
      tresDetalle: "18:00 · 60 min · Confirmada",
    },
    buscarReserva: "¿Buscas reservar? Encuentra tu negocio",
    sinCuentaCliente:
      "¿Aún no tienes cuenta? Pídela a {negocio}: te enviará una invitación a tu correo.",
    sinCuenta: "¿Administras un negocio?",
    registrar: "Prueba AgendaUno gratis",
  },
  panel: {
    salir: "Cerrar sesión",
    suscripcion: "Suscripción",
    verDetalle: "Ver detalle",
    estados: {
      trial: "En prueba",
      active: "Al corriente",
      past_due: "Pago pendiente",
      grace_period: "Periodo de gracia",
      suspended: "Suspendida",
      cancelled: "Cancelada",
    },
    pruebaHasta: "gratis hasta el {fecha}",
    cargoEstimado: "Cargo estimado",
  },
  onboarding: {
    titulo: "Configura tu negocio",
    subtitulo: "Guarda y continua. Puedes volver cuando quieras.",
    progreso: "{hechos} de {total} pasos",
    completo: "¡Configuración completa!",
    irPanel: "Ir al panel",
    anterior: "Anterior",
    siguiente: "Guardar y continuar",
    omitir: "Omitir por ahora",
    guardado: "Guardado",
    hecho: "Listo",
    pasos: {
      marca: "Marca",
      sucursal: "Sucursal",
      horarios: "Horarios",
      actividades: "Actividades",
      productos: "Productos",
      politicas: "Políticas",
      pasarela: "Pagos",
      personal: "Personal",
      publicacion: "Publicación",
    },
    marca: {
      desc: "La identidad de tu estudio. El logo aparece en tu perfil público.",
      logo: "Logo (opcional)",
    },
    sucursal: {
      desc: "Crea tu primera sucursal. Su zona horaria define los horarios de clase.",
      nombre: "Nombre de la sucursal",
      nombrePh: "Sede Principal",
      zona: "Zona horaria",
      crear: "Crear sucursal",
      creada: "Sucursal creada: {nombre}",
      yaTienes:
        "Ya tienes tu primera sede. ¿Tienes más? Agrégalas o edítalas cuando quieras.",
      gestionar: "Gestionar sucursales",
    },
    horarios: {
      desc: "Programa al menos un horario recurrente o una clase para poder recibir reservas.",
      ayuda:
        "Aún no tienes horarios ni clases. Créalos en la Agenda y vuelve para continuar.",
      abrirAgenda: "Abrir la Agenda",
      yaListo: "Ya lo hice, continuar",
      listo: "Ya tienes clases u horarios programados.",
    },
    actividades: {
      desc: "Crea una actividad y una oferta (una clase que tus alumnos podrán reservar).",
      programa: "Programa",
      programaPh: "Pole dance",
      actividad: "Actividad",
      actividadPh: "Pole dance",
      oferta: "Oferta (clase)",
      ofertaPh: "Nivel 1",
      modalidad: "Modalidad",
      capacidad: "Cupo (opcional)",
      crear: "Crear actividad y oferta",
      creada: "Oferta creada: {nombre}",
    },
    productos: {
      desc: "Crea un producto vendible: un paquete de créditos o una membresía.",
      nombre: "Nombre del producto",
      nombrePh: "Pack 8 clases",
      tipo: "Tipo",
      precio: "Precio (MXN)",
      creditos: "Créditos incluidos",
      creditosAyuda: "1 crédito = 1 clase.",
      crear: "Crear producto",
      creado: "Producto creado: {nombre}",
    },
    politicas: {
      desc: "Define tu política de cancelación para poder continuar. Podrás ajustarla luego.",
      horas: "Horas límite para cancelar",
      horasAyuda: "Cancelar con esta antelación no cobra la clase.",
      penalizaTarde: "Cobrar cancelaciones tardías",
      penalizaNoShow: "Cobrar inasistencias (no-show)",
      guardar: "Guardar y continuar",
      listo: "Ya tienes una política de cancelación definida.",
    },
    pasarela: {
      desc: "Conecta tu pasarela de pagos (Stripe, OpenPay o Mercado Pago) cuando tengas tus llaves. Por ahora puedes cobrar en ventanilla.",
    },
    personal: {
      desc: "Invita a tu equipo. Cada quien activará su cuenta con su correo.",
      nombre: "Nombre",
      email: "Correo",
      rol: "Rol",
      invitar: "Invitar",
      invitado: "Invitación creada para {email}",
      tokenDev: "Token de activación (solo desarrollo):",
    },
    publicacion: {
      desc: "Publica tu estudio en el directorio para que te encuentren. Puedes mantenerlo privado.",
      publicar: "Publicar en el directorio",
      privado: "Mantener privado (no listado)",
      enDirectorio: "Tu estudio aparece en el directorio.",
      fuera: "Tu estudio no está en el directorio.",
      guardar: "Guardar publicación",
    },
    roles: {
      admin: "Administrador",
      recepcionista: "Recepcionista",
      instructor: "Instructor",
      miembro: "Miembro",
    },
    modalidades: {
      grupal: "Grupal",
      privada: "Privada",
      individual: "Individual",
    },
    tipos: {
      paquete: "Paquete de créditos",
      membresia: "Membresía ilimitada",
    },
  },
  miembros: {
    subtitulo:
      "Consulta y administra a tus miembros: sus planes, sus pagos y su asistencia.",
    resumen: {
      total: "Total de {grupo}",
      conPlan: "Con plan vigente",
      nuevos: "Nuevos este mes",
      porVencer: "Por vencer ({dias} días)",
      porVencerValor: "{n} · {vencidas} vencidas",
      adeudo: "Con pagos pendientes",
    },
    nuevoTitulo: "Nuevo miembro",
    nombre: "Nombre",
    primerApellido: "Primer apellido",
    email: "Correo",
    celular: "Celular",
    crear: "Agregar",
    creando: "Agregando…",
    vacio: "Aún no hay miembros. Agrega el primero.",
    // Con búsqueda o filtros: nadie coincide (sí hay personas).
    sinResultados: "Nadie coincide con la búsqueda o los filtros.",
    quitarBusqueda: "Quitar búsqueda y filtros",
    creado: "Miembro agregado.",
    colNombre: "Nombre",
    colCorreo: "Correo",
    colEstado: "Registro",
    activo: "Activo",
    nuevo: "Nuevo",
    nuevoAyuda: "Aún no ha asistido a ninguna clase.",
    invitar: "Invitar",
    invitando: "Invitando…",
    invitado: "Invitado",
    buscar: "Buscar por nombre o correo…",
    suspendido: "Suspendido",
    noFacturable: "No facturable",
    archivado: "Archivado",
    filtros: {
      facturableSi: "Facturables",
      facturableNo: "No facturables",
      archivados: "Archivados",
      todos: "Todos",
      sede: "Sede",
    },
    editar: {
      guardado: "Datos de {nombre} guardados.",
      abrir: "Editar",
      titulo: "Editar alumno",
      estado: "Estado",
      activo: "Activo",
      activoAyuda: "Si lo suspendes, no podrá reservar ni entrar.",
      facturable: "Facturable",
      facturableAyuda: "Cuenta para la renta del SaaS.",
      archivado: "Archivado",
      archivadoAyuda: "Lo oculta del listado sin borrarlo.",
    },
  },
  padron: {
    titulo: "Padrón facturable",
    alumnosFacturables: "Alumnos facturables",
    exportar: "Exportar CSV",
    exportando: "Exportando…",
    regla: "Regla: {regla}.",
    vacio: "Aún no hay alumnos facturables.",
    colAlumno: "Alumno",
    colSucursal: "Sucursal",
    colAlta: "Alta",
    colRazon: "Razón de cobro",
  },
  importar: {
    filtro: {
      todas: "Todas ({n})",
      conErrores: "Con errores (1) | Con errores ({n})",
    },
    titulo: "Importar miembros",
    subtitulo: "Trae a tus alumnos desde otro sistema con un archivo CSV.",
    instrucciones:
      "Sube un archivo CSV con una fila por alumno. Primero lo revisamos sin guardar: te decimos qué filas están listas, cuáles ya estaban (no se repiten) y cuáles hay que corregir.",
    columnas: "Columnas",
    elegir: "Elegir archivo CSV",
    plantilla: "Descargar plantilla",
    analizando: "Analizando el archivo…",
    total: "Filas",
    validas: "Listas",
    invalidas: "Por corregir",
    existentes: "Ya estaban",
    corrige:
      "Hay filas por corregir. Puedes cargar ahora solo las listas y subir después el archivo corregido: lo que ya se cargó no se repite.",
    estados: {
      valida: "Lista",
      existente: "Ya está",
      conflicto: "Conflicto",
      // Los datos sirven pero falta algo del negocio (p. ej. un plan con saldo).
      pendiente: "Pendiente",
      invalida: "Con errores",
    },
    importarListas: "Cargar solo las {n} listas",
    confirmarListas:
      "Se cargarán {n} filas listas y quedarán {m} por corregir.",
    porCorregir:
      "Quedaron {n} filas por corregir: corrígelas en tu archivo y vuelve a subirlo; lo que ya se cargó no se repite.",
    // El servidor volvió a revisar el archivo y no cargó nada (422 con su análisis).
    rechazado:
      "No se cargó nada: hay filas por corregir. Revísalas en la lista de abajo, corrige tu archivo y vuelve a subirlo.",
    sinFilas: "El archivo no tiene filas para cargar.",
    colNombre: "Nombre",
    colCorreo: "Correo",
    colEstado: "Estado",
    // Título de la ficha de cada fila en el teléfono.
    filaN: "Fila {n}",
    ok: "OK",
    importar: "Importar {n} miembros",
    importando: "Importando…",
    creados: "{n} miembros importados",
    verMiembros: "Ver alumnos",
  },
  importarSaldos: {
    titulo: "Saldos de apertura",
    subtitulo:
      "El plan, los créditos y la vigencia con que cada alumno llega de tu sistema anterior.",
    // En citas lo que trae cada alumno es un bono con sus sesiones (ADR 0130).
    subtituloCitas:
      "El bono, las sesiones y la vigencia con que cada alumno llega de tu sistema anterior.",
    instruccionesCitas:
      "Si vienes de otro sistema, carga aquí el bono, las sesiones que le quedan y la vigencia que cada alumno trae al corte acordado. No se registran ventas ni pagos: el saldo aparece en su estado de cuenta como «Saldo de apertura», con la evidencia que indiques.",
    pasoPlanesCitas:
      "Crea los bonos con el mismo nombre que usarás en el archivo.",
    colPlanCitas: "Bono",
    colCreditosCitas: "Sesiones",
    ir: "Cargar saldos de apertura",
    instrucciones:
      "Si vienes de otro sistema, carga aquí el plan, los créditos y la vigencia que cada alumno trae al corte acordado. No se registran ventas ni pagos: el saldo aparece en su estado de cuenta como «Saldo de apertura», con la evidencia que indiques.",
    pasoMiembros: "Primero importa a los alumnos (con su correo).",
    pasoPlanes: "Crea los planes con el mismo nombre que usarás en el archivo.",
    pasoCorte:
      "Acuerda una fecha de corte: desde ese día los saldos se operan solo aquí, no en los dos sistemas.",
    // Las reservas futuras de clases se importan después de los saldos (ADR 0176).
    pasoReservas:
      "Después de cargar los saldos, importa las reservas futuras de tus alumnos en «Importar reservas», sin avisarles nada.",
    // En citas, la agenda futura se importa con su archivo (ADR 0160); en clases, no (ADR 0125).
    pasoReservasCitas:
      "Las citas que ya tenías agendadas se importan en Agenda › Importar citas, sin avisarle nada a tus alumnos.",
    corte: "Fecha de corte",
    evidencia: "De dónde salen los saldos",
    evidenciaEjemplo:
      "Ej.: reporte de saldos exportado del sistema anterior el 30/09",
    faltan:
      "Indica la fecha de corte y de dónde salen los saldos para revisar el archivo.",
    revisar: "Revisar archivo",
    colAlumno: "Alumno",
    colPlan: "Plan",
    colCreditos: "Créditos",
    colVence: "Vence",
    cargar: "Cargar {n} saldos",
    confirmar:
      "Se cargarán {n} saldos de apertura al corte del {corte}, sin ventas ni pagos.",
    cargados: "{n} saldos de apertura cargados",
  },
  importarInstructores: {
    titulo: "Importar instructores",
    subtitulo: "Trae a tus instructores desde otro sistema con un archivo CSV.",
    instrucciones:
      "Sube un archivo CSV con una fila por instructor (nombre y correo). Primero lo revisamos sin guardar; después eliges si les enviamos ya la invitación para activar su acceso o si los invitas más tarde desde Equipo.",
    importar: "Importar {n} instructores",
    ayudaInvitar:
      "Las invitaciones salen por correo solo si lo eliges. Sin invitar, las cuentas quedan sin activar y las invitas cuando quieras desde Equipo.",
    importarInvitar: "Importar e invitar a {n}",
    importarSinInvitar: "Importar sin invitar",
    confirmarInvitar:
      "Se enviará un correo de invitación a {n} instructores. ¿Continuar?",
    invitados: "Se enviaron {n} invitaciones.",
    sinInvitar: "Quedaron sin invitar: invítalos cuando quieras desde Equipo.",
    creados: "{n} instructores importados",
    verInstructores: "Ver instructores",
  },
  comunicaciones: {
    titulo: "Comunicación",
    segmentoLabel: "¿A quién le escribes?",
    canalLabel: "Canal",
    canalInterno: "Bandeja del alumno",
    canalEmail: "Correo",
    asuntoLabel: "Asunto",
    asuntoPh: "Ej. Tu membresía está por vencer",
    asuntoPhCitas: "Ej. Tu bono está por vencer",
    cuerpoLabel: "Mensaje",
    cuerpoPh: "Hola Rocío, te escribimos para recordarte…",
    marcadores:
      "Puedes usar {a} y {b}: se reemplazan por el nombre y el correo de cada alumno.",
    destinatarios: "Se enviará a {n} persona(s).",
    enviar: "Enviar difusión",
    enviando: "Enviando…",
    enviada: "Difusión encolada: {n} destinatario(s).",
    sinSegmento: "Aún no hay alumnos para segmentar.",
    historial: "Envíos recientes",
    historialVacio: "Todavía no has enviado ninguna difusión.",
    colFecha: "Fecha",
    colSegmento: "Segmento",
    colAsunto: "Asunto",
    colTotal: "Destinatarios",
    // B08: controlar una campaña sin tocar los avisos de operación.
    colEstado: "Estado",
    colAcciones: "Acciones",
    // Segmentos rotulados por su clave (`comunicaciones.segmentos.${clave}.etiqueta`);
    // el texto del API queda de respaldo.
    segmentos: {
      // En citas no hay membresías ni padrón (ADR 0104): sus variantes «…Citas».
      todos: {
        etiqueta: "Todos los alumnos",
        descripcion: "Todos los alumnos activos del padrón.",
        descripcionCitas: "Todos tus alumnos activos.",
      },
      por_vencer: {
        etiqueta: "Membresía por vencer",
        descripcion: "Alumnos cuya membresía vence en los próximos 14 días.",
        etiquetaCitas: "Bono por vencer",
        descripcionCitas: "Alumnos cuyo bono vence en los próximos 14 días.",
      },
      vencidos: {
        etiqueta: "Membresía vencida",
        descripcion:
          "Alumnos cuya membresía venció hace 14 días o menos (se pueden recuperar).",
        etiquetaCitas: "Bono vencido",
        descripcionCitas:
          "Alumnos cuyo bono venció hace 14 días o menos (se pueden recuperar).",
      },
      primerizos: {
        etiqueta: "Primerizos (sin asistir aún)",
        descripcion: "Alumnos que aún no registran ninguna asistencia.",
      },
      sin_asistir: {
        etiqueta: "Con plan y sin venir",
        descripcion:
          "Tienen un plan en vigor y no vienen desde hace los días que fija tu negocio en Seguimiento.",
        etiquetaCitas: "Con bono y sin venir",
        descripcionCitas:
          "Tienen un bono en vigor y no vienen desde hace los días que fija tu negocio en Seguimiento.",
      },
      sin_iniciar: {
        etiqueta: "Compró y no ha iniciado",
        descripcion:
          "Tienen un plan en vigor, nunca han venido y no tienen nada reservado.",
        descripcionCitas:
          "Tienen un bono en vigor, nunca han venido y no tienen cita.",
      },
      saldo_por_vencer: {
        etiqueta: "Paquete por vencer con saldo",
        descripcion: "Su paquete vence pronto y aún les queda saldo sin usar.",
        etiquetaCitas: "Bono por vencer con saldo",
        descripcionCitas:
          "Su bono vence pronto y aún les quedan sesiones sin usar.",
      },
      vino_sin_comprar: {
        etiqueta: "Vino y no ha comprado",
        descripcion: "Vinieron hace poco y nunca han comprado un plan.",
      },
      pago_fallido: {
        etiqueta: "Su pago automático falló",
        descripcion:
          "Un cobro automático de su plan falló (en mora o suspendida).",
      },
      debe_regresar: {
        etiqueta: "Ya debería regresar",
        descripcion:
          "Ya pasó el tiempo en que suelen repetir su servicio y no tienen otra cita.",
      },
    },
    estados: {
      enviando: "Enviando · faltan {n}",
      enviada: "Enviada",
      pausada: "En pausa",
      cancelada: "Cancelada",
    },
    // Cómo salió cada campaña (ADR 0166).
    resultado: {
      enviados: "{n} enviados",
      fallidos: "{n} no se pudieron enviar",
      sinConfirmar: "{n} sin confirmar (revísalos en la bandeja de salida)",
      descartados: "{n} no se mandaron (no desean promociones o se canceló)",
    },
    pausar: "Pausar",
    reanudar: "Reanudar",
    cancelar: "Cancelar el resto",
    confirmarCancelar:
      "¿Cancelar lo que falta por enviar de esta difusión? Lo ya enviado no se puede deshacer.",
  },
  enlace: {
    titulo: "Enlace público",
    qrAlt: "Código QR del enlace de tu negocio",
    copiar: "Copiar enlace",
    copiado: "Copiado",
    descargar: "Descargar QR",
  },
  retencion: {
    titulo: "Retención",
    porVencer: "Por vencer",
    vencidas: "Vencidas",
    mostrar: "Mostrar",
    todas: "Todas",
    ventana: "Próximos {n} días",
    ventanaLabel: "Ventana",
    exportar: "Exportar CSV",
    exportando: "Exportando…",
    vacio:
      "¡Nadie en riesgo! No hay membresías por vencer ni vencidas en esta ventana.",
    colAlumno: "Alumno",
    colVence: "Vence",
    colActividad: "Última asistencia",
    colActividadCitas: "Última visita",
    venceEn: "en {n} días",
    venceHoy: "hoy",
    vencioHace: "hace {n} días",
    sinAsistencia: "Sin asistencias",
    sinAsistenciaCitas: "Sin visitas",
    haceDias: "hace {n} días",
    hoy: "hoy",
    contactar: "Contactar",
    verFicha: "Ver ficha",
    // Se usa con llave dinámica (`retencion.estados.${estado}`, RetencionView).
    estados: { por_vencer: "Por vencer", vencida: "Vencida" },
    vistas: {
      renovaciones: "Por vencer y vencidas",
      recuperar: "Para recuperar",
    },
    // Dar seguimiento desde la tabla (deja registro en Tareas).
    // Se usa con llave dinámica (`retencion.seguimiento.${estado}`).
    seguimiento: {
      dar: "Dar seguimiento",
      creado: "Seguimiento creado · Ver",
      abierta: "Ya tiene seguimiento · Ver",
      no_promociones: "No desea promociones",
      reciente: "Se le contactó hace poco · Ver",
    },
    // Retención por segmentos (ADR 0159, components/SegmentosRetencion.vue).
    segmentos: {
      ayuda:
        "Grupos por una señal que el sistema verifica. Escríbeles a todos o crea su seguimiento: una tarea por persona con un mensaje sugerido, sin repetir a quien ya tiene una.",
      cargando: "Buscando a quién recuperar…",
      error: "No se pudieron cargar los grupos para recuperar.",
      vacio: "Nadie por recuperar por ahora.",
      personas: "1 persona | {n} personas",
      ver: "Ver quiénes",
      ocultar: "Ocultar",
      escribirles: "Escribirles",
      sinPromociones:
        "Nadie de este grupo acepta promociones: no se les puede escribir una difusión.",
      crearSeguimiento: "Crear seguimiento",
      confirmarSeguimiento:
        "Crear una tarea de seguimiento para cada una de las {n} personas de «{grupo}». A quien ya tiene una abierta o reciente por lo mismo no se le repite.",
      creadas:
        "Se creó 1 tarea de seguimiento. | Se crearon {n} tareas de seguimiento.",
      ningunaNueva: "Todos ya tenían su seguimiento: no se creó ninguna tarea.",
      yaTiene: "Ya tiene seguimiento",
      noPromociones: "No desea promociones",
      errorLista: "No se pudo cargar a las personas de este grupo.",
      // Se usa con llave dinámica (`retencion.segmentos.desc.${clave}`).
      desc: {
        sin_asistir:
          "Tienen un plan en vigor y no vienen desde hace los días que fijas en Seguimiento.",
        sin_iniciar:
          "Pagaron su plan hace los días que fijas en Seguimiento, nunca han venido y no tienen nada reservado.",
        saldo_por_vencer:
          "Su paquete vence pronto y aún les queda saldo sin usar.",
        vino_sin_comprar: "Vinieron hace poco y nunca han comprado un plan.",
        pago_fallido:
          "Falló el cobro automático de su plan (en mora o con la membresía suspendida).",
        debe_regresar:
          "Ya pasó el tiempo en que suelen repetir su servicio y no tienen otra cita. Se fija en cada servicio del catálogo.",
      },
    },
  },
  // Lugares libres filtrado por un día desde el Inicio (`?fecha=`).
  oportunidadesFecha: {
    solo: "Solo el {fecha} · Quitar",
  },
  cobranza: {
    titulo: "Cobranza",
    morosos: "En mora",
    sinMorosos: "Nadie en mora.",
    // Lo que ya se debe: compras sin pagar y citas o clases que ya pasaron.
    pendientes: {
      titulo: "Pendientes de pago",
      vacio: "Nada pendiente de pago.",
      proximas:
        "1 cita próxima se cobra al atenderla. | {n} citas próximas se cobran al atenderlas.",
      colCliente: "Alumno",
      colConcepto: "Concepto",
      con: "Con {nombre}",
      comprada: "Comprada el {fecha}",
      // Orden de una renovación: va con su vencimiento (EstadoVencimiento).
      renovacion: "Renovación",
      registrar: "Registrar pago",
      registrarTitulo: "Registrar pago",
      // Precio por forma de pago (ADR 0191).
      descuentoContado: "De contado: {pct} % menos (antes {antes}).",
      descuentoContadoAyuda:
        "Todo en efectivo o todo por transferencia tiene {pct} % de descuento.",
      // Exentar una cuota por cobrar (B04).
      exentar: "Exentar",
      exentadaTodo:
        "Se exentó «{cuota}»: ya no hay nada por cobrar en esa orden.",
      exentada: "Se exentó «{cuota}»: la orden queda en {total}.",
      referencia: "Referencia (opcional)",
      registrado: "Pago de {nombre} registrado: {monto}.",
    },
    pagos: "Pagos",
    sinPagos: "Aún no hay pagos con pasarela.",
    renovaciones: "Renovaciones",
    sinRenovaciones: "No hay membresías con cobro recurrente.",
    colAlumno: "Alumno",
    colEstado: "Estado",
    colIntentos: "Intentos",
    colGracia: "Gracia hasta",
    graciaVencida: "Gracia vencida",
    colFecha: "Fecha",
    colMembresia: "Membresía",
    colProxima: "Renueva",
    colMonto: "Monto",
    // Filtro de Renovaciones por su vencimiento (`cobranza.situacion.${f || "todas"}`).
    situacion: {
      etiqueta: "Vencimiento de la renovación",
      todas: "Todas",
      proximas: "Próximas",
      vencidas: "Vencidas sin cobrar",
    },
    // Por qué no se cobró, dicho a quien administra (`motivo_clave` de En mora). El
    // detalle del banco va aparte, debajo. Nunca la frase que recibe el alumno.
    motivosMora: {
      sin_cobro_en_linea:
        "No se pudo intentar el cobro en línea. Regístralo en caja o invita al alumno a pagar desde su cuenta.",
      pago_sin_completar:
        "El pago en línea quedó sin completar. Si ya pagó, regístralo en caja; si no, invita al alumno a terminarlo desde su cuenta.",
      rechazado:
        "La pasarela rechazó el cobro. Regístralo en caja o invita al alumno a pagar con otra tarjeta.",
      tarjeta_rechazada:
        "El banco rechazó el cobro automático a su tarjeta. Pide al alumno que la actualice o registra el pago en caja.",
      suscripcion_sin_cobro:
        "La pasarela no ha confirmado el cobro automático de la suscripción. Revísalo en su panel o registra el pago en caja.",
      sinClave: "Cobro no realizado",
    },
    regularizar: "Regularizar",
    regularizando: "Regularizando…",
    reembolsar: "Reembolsar",
    corregir: "Corregir",
    estados: {
      en_mora: "En mora",
      suspendido: "Suspendido",
      regularizado: "Regularizado",
      pausado: "En pausa",
    },
    // Un solo vocabulario en Cobros (J): pendiente, confirmado, devolución e
    // incidencia. Se usa con llave dinámica (`cobranza.pagoEstados.${estado}`).
    pagoEstados: {
      pendiente: "Pendiente",
      aprobado: "Confirmado",
      parcialmente_reembolsado: "Devuelto en parte",
      reembolsado: "Devuelto",
      rechazado: "Incidencia: rechazado",
      anulado: "Anulado (se registró por error)",
    },
    // Aparte del estado del pago (ADR 0166).
    porConciliar: "Por conciliar",
    // El banco del alumno lo disputó (ADR 0168).
    contracargo: {
      abierto: "Contracargo abierto: responde en la pasarela",
      ganado: "Contracargo ganado",
      perdido: "Contracargo perdido: se devolvió al alumno",
    },
    comprobante: {
      enviado: "Recibo enviado",
      fallido: "El recibo no se pudo enviar",
      incierto: "Recibo sin confirmar",
      pendiente: "Recibo por enviar",
    },
    reembolso: {
      titulo: "Reembolsar pago",
      reembolsable: "Reembolsable: {monto}",
      monto: "Monto a reembolsar",
      // Un cobro con varias formas (ADR 0177) se devuelve por una de ellas.
      salePor: "Se devuelve por",
      motivo: "Motivo",
      motivoPh: "Cancelación, error de cobro…",
      revertir: "Revertir créditos",
      revertirAyuda:
        "Quita los créditos otorgados por esta compra. Si la pagó otro cobro o es una renovación, se devuelve solo el dinero: para quitarle el periodo, pausa o da de baja la membresía.",
      // En citas lo que da una compra son sesiones de un bono (ADR 0130).
      revertirCitas: "Quitar las sesiones del bono",
      revertirAyudaCitas:
        "Quita las sesiones que dio esta compra. Si la pagó otro cobro, se devuelve solo el dinero.",
      confirmar: "Reembolsar",
      procesando: "Reembolsando…",
    },
  },
  ficha: {
    volver: "Volver a {grupo}",
    // aria-label de las pestañas de la ficha.
    secciones: "Secciones de la ficha",
    editar: "Editar",
    vender: "Vender / cobrar",
    alta: "Alta",
    sucursal: "Sucursal",
    membresia: "Membresía",
    // En citas lo que se vende son bonos, no membresías (ADR 0130).
    membresiaCitas: "Bono",
    saldo: "Saldo",
    creditos: "1 crédito | {n} créditos",
    proxima: "Próxima reserva",
    sinProxima: "Sin próxima reserva",
    asistencias: "Asistencias",
    documentos: "Documentos pendientes",
    derechos: {
      titulo: "Membresías y paquetes",
      tituloCitas: "Bonos",
      vacio: "Sin membresías ni paquetes.",
      vacioCitas: "Sin bonos.",
      // Saldo de lo vigente (lo que puede usar y lo ya reservado); lo vencido o
      // cancelado es historial (aparte).
      saldoVigente: "Saldo vigente: {saldo}",
      sinVigente: "Sin membresía ni paquete vigente.",
      sinVigenteCitas: "Sin bono vigente.",
      sinSaldo: "Sin saldo vigente",
      apartadas: "1 apartada | {n} apartadas",
      historial: "Historial",
      verHistorial: "Ver historial ({n})",
      ocultarHistorial: "Ocultar historial",
      ilimitado: "Ilimitado",
      disponible: "1 disponible | {n} disponibles",
      vence: "Vence {fecha}",
      sinVence: "Sin vencimiento",
    },
    reservas: {
      titulo: "Historial de reservas",
      tituloCitas: "Historial de citas",
      vacio: "Aún no tiene reservas.",
    },
    // Lo que debe, para cobrarlo desde la ficha.
    pendientes: {
      titulo: "Pendiente de pago",
    },
    // Negocio de citas: sus visitas.
    visitas: {
      titulo: "Visitas",
      proxima: "Próxima cita",
      sinProxima: "Sin próxima cita",
      ultima: "Última visita",
      sinUltima: "Aún no ha venido",
      servicio: "Servicio habitual",
      profesional: "Profesional habitual",
    },
    ordenes: {
      titulo: "Historial de compras",
      vacio: "Aún no tiene compras.",
      estados: {
        pendiente: "Pendiente",
        pagada: "Pagada",
        cancelada: "Cancelada",
      },
    },
    acuerdo: {
      activo: "Activo",
      pausado: "Pausado",
      cancelado: "Cancelado",
      suspendido: "Suspendido",
    },
  },
  ventas: {
    titulo: "Ventas",
    errorCarga: "No se pudieron cargar los planes y las ventas.",
    vender: {
      titulo: "Vender",
      subtitulo: "Vende un plan o paquete a un alumno.",
      administrarPlanes: "Administrar planes y paquetes",
      // En citas lo que se vende son bonos de sesiones (ADR 0130).
      subtituloCitas: "Vende un bono a un alumno.",
      administrarPlanesCitas: "Administrar bonos",
      // Lo disponible del bono recién vendido, con decimales (1000 unidades = 1).
      exitoPackCitas:
        "Vendido. {persona} tiene 1 sesión disponible en este bono. | Vendido. {persona} tiene {saldo} sesiones disponibles en este bono.",
      exitoMembresiaCitas: "Vendido. {persona} tiene un bono activo.",
      miembro: "Alumno",
      producto: "Producto",
      metodo: "Método de pago",
      elegir: "Elige…",
      cobrar: "Cobrar y activar",
      cobrando: "Cobrando…",
      // Lo disponible del plan recién vendido, con decimales (1000 unidades = 1).
      exitoPack:
        "Vendido. Con este plan, {persona} tiene 1 crédito disponible. | Vendido. Con este plan, {persona} tiene {saldo} créditos disponibles.",
      exitoVenta: "Venta registrada para {persona}.",
      exitoMembresia: "Vendido. {persona} tiene una membresía activa.",
      sinProductos: "Primero crea un producto.",
      promo: "Código de promoción",
      promoPlaceholder: "CUPÓN",
      promoAplicar: "Aplicar",
      promoDescuento: "Descuento {monto}",
      // Inscripción (B04): se suma si es su alta o regresa tras un tiempo sin plan.
      inscripcion: "Inscripción (no da clases)",
      exentarInscripcion: "Exentar la inscripción",
      motivoExencion: "Por qué se exenta",
      aceptaCondiciones: "El alumno leyó y aceptó estas condiciones",
      promoTotal: "Total {monto}",
    },
    metodos: {
      efectivo: "Efectivo",
      // Con la terminal del negocio (no por la pasarela en línea).
      tarjeta: "Tarjeta (terminal)",
      transferencia: "Transferencia",
      ventanilla: "Ventanilla",
      // Con varias formas de pago (ADR 0177).
      mixto: "Varias formas",
    },
    productos: {
      titulo: "Productos",
      nuevo: "Nuevo producto",
      vacio: "Aún no hay productos.",
      ilimitado: "Ilimitado",
      creditosSufijo: "{n} créditos",
      editar: "Editar",
      archivado: "Archivado",
      vigencia: "vence {n} d",
      renueva: "renueva",
    },
    ordenes: {
      titulo: "Ventas recientes",
      vacio: "Sin ventas aún.",
      pagada: "Pagada",
      pendiente: "Pendiente",
      colComprador: "Comprador",
      colTotal: "Total",
      colEstado: "Estado",
    },
  },
  instructores: {
    administrarAcceso: "Administrar acceso",
    vacio: "Aún no hay instructores. Invita al primero.",
    invitar: {
      titulo: "Invitar instructor",
      nombre: "Nombre",
      email: "Correo",
      sede: "Sucursal",
      eligeSede: "Elige su sucursal",
      sedeAyuda:
        "Solo verá lo de esa sucursal. Puedes asignarle más desde Usuarios.",
      enviar: "Invitar",
      enviando: "Invitando…",
      creada:
        "Invitación creada para {email}. Comparte este token de activación:",
      enviada:
        "Invitación enviada a {email}: le llega un correo para activar su cuenta.",
    },
  },
  horarios: {
    titulo: "Horarios de atención",
    sucursal: "Sucursal",
    proveedor: "¿Quién atiende?",
    elegirSucursal: "Elige una sucursal",
    elegirProveedor: "Elige a la persona",
    seleccionaAmbos:
      "Elige una sucursal y a la persona para configurar su horario de atención.",
    sinProveedores:
      "Aún no hay instructores. Invita al primero desde «Instructores» para poder darle horario.",
    errorReferencias: "No se pudieron cargar las sucursales y el equipo.",
    dias: {
      "1": "Lunes",
      "2": "Martes",
      "3": "Miércoles",
      "4": "Jueves",
      "5": "Viernes",
      "6": "Sábado",
      "7": "Domingo",
    },
    desde: "Desde",
    hasta: "Hasta",
    agregarFranja: "Agregar franja",
    quitarFranja: "Quitar franja",
    copiar: "Aplicar a toda la semana",
    cerrado: "Cerrado",
    guardar: "Guardar horario",
    guardando: "Guardando…",
    guardado: "Horario guardado.",
    rangoInvalido:
      "Revisa las franjas: la hora de fin debe ser mayor que la de inicio.",
    soloLectura: "No tienes permiso para editar horarios.",
    preview: {
      titulo: "Vista previa de huecos",
      ayuda: "Comprueba cómo se verán los huecos disponibles para una cita.",
      fecha: "Fecha",
      duracion: "Duración (min)",
      ver: "Ver huecos",
      viendo: "Calculando…",
      vacio: "No hay huecos ese día con esa duración.",
      total: "{n} huecos disponibles",
    },
  },
  catalogo: {
    titulo: "Catálogo",
    vacio:
      "Aún no hay servicios en el catálogo. Créalos desde el onboarding o la agenda.",
    vacioClases:
      "Aún no hay clases en el catálogo. Créalas desde el onboarding o la agenda.",
    modalidad: {
      grupal: "Grupal",
      privada: "Privada",
      individual: "Individual",
    },
    politica: {
      etiqueta: "¿Cómo se reserva?",
      entitlement: "Con créditos o membresía",
      pago: "Pago por clase",
      entitlementAyuda:
        "El alumno reserva con su membresía o su paquete de créditos.",
      pagoAyuda:
        "El alumno paga la clase en línea al reservarla, sin paquete ni membresía.",
    },
    precio: "Precio por cita",
    precioClases: "Precio por clase",
    precioReq: "Define un precio mayor a 0 para el pago al agendar.",
    duracion: "Duración (min)",
    duracionReq: "Define la duración de la cita para calcular los huecos.",
    duracionAyuda: "Cuánto dura el servicio; con esto se arman los horarios.",
    duracionAyudaClases:
      "Cuánto dura la clase; con esto se calcula su hora de término.",
    lugares: "Lugares numerados",
    lugaresAyuda: "0 = sin numerar. Útil para salas con lugar asignado.",
    regreso: "Suele regresar cada (días)",
    regresoAyuda:
      "Opcional. Con esto, en Renovaciones › Para recuperar ves a quien ya debería haber vuelto.",
    // Anticipo para apartar en línea (ADR 0161).
    anticipo: {
      etiqueta: "Anticipo para apartar en línea",
      tipos: {
        ninguno: "Sin anticipo: se paga completo",
        monto: "Un monto fijo",
        porcentaje: "Un porcentaje del precio",
      },
      ayuda:
        "Con anticipo, quien agenda en línea paga una parte para confirmar (o todo, si quiere) y el resto en el negocio. Si cancela a tiempo, se le devuelve; si cancela tarde, lo conservas.",
      resumen:
        "Al agendar en línea paga {anticipo} para confirmar; el resto ({resto}) se cobra en el negocio.",
      montoInvalido: "Escribe un monto mayor a 0 y menor que el precio.",
      porcentajeInvalido: "Escribe un porcentaje entero del 1 al 99.",
    },
    configurar: "Configurar",
    cerrar: "Cerrar",
    guardar: "Guardar",
    guardando: "Guardando…",
    guardado: "Servicio actualizado.",
    guardadoClases: "Clase actualizada.",
    badgePago: "Cita con pago",
    badgePagoClases: "Clase con pago",
    badgeEntitlement: "Con membresía",
    badgeEntitlementCitas: "Con bono",
    // Negocio de citas: un servicio se paga o se toma con bono (ADR 0091).
    politicaCitas: {
      pago: "Se paga",
      pagoAyuda:
        "Aparece con su precio en tu página para agendar; se paga en línea o en la sucursal.",
      entitlement: "Con bono",
      entitlementAyuda:
        "No aparece en tu página pública: lo agendan desde su cuenta quienes tienen un bono que lo incluye, o tú desde la agenda.",
      dejaDeAparecer:
        "Al guardar, «{servicio}» deja de aparecer en tu página para agendar: solo lo podrán tomar quienes tengan un bono que lo incluya.",
      apareceConPrecio:
        "Al guardar, «{servicio}» aparece en tu página para agendar con su precio.",
    },
  },
  reservar: {
    cargando: "Cargando…",
    noDisponible: "Este negocio no está disponible para agendar en línea.",
    volverDirectorio: "Ver otros negocios",
    sinServicios:
      "Este negocio aún no ofrece servicios para agendar en línea. Contáctalo para reservar.",
    titulo: "Agendar una cita",
    intro:
      "Elige el servicio, el día y la hora, y con quién. Te toma un minuto.",
    servicio: "Elige un servicio",
    sucursal: "Sucursal",
    barbero: "¿Con quién?",
    elegirBarbero: "Elige a la persona",
    sinProfesionales:
      "No hay profesionales disponibles para reservar en línea. Contacta al negocio.",
    cuando: "¿Qué día?",
    hora: "Elige una hora",
    sinHorario: "Elige el día para ver los horarios disponibles.",
    sinHuecos: "No hay horarios disponibles ese día. Prueba con otro.",
    calculando: "Buscando horarios…",
    datos: "Tus datos",
    nombre: "Nombre",
    celular: "Celular",
    email: "Correo (opcional)",
    emailAyuda: "Para enviarte la confirmación.",
    resumen: "Resumen",
    total: "Total",
    duracionMin: "{n} min",
    agendarYPagar: "Agendar y pagar",
    agendando: "Agendando…",
    errorFalta: "Elige servicio, persona y una hora para continuar.",
    listoTitulo: "¡Listo, tu cita quedó agendada!",
    listoResumen: "{servicio} con {barbero}",
    apartado:
      "Apartamos tu lugar por tiempo limitado. Completa el pago para confirmarlo.",
    pagar: "Pagar ahora",
    pagando: "Redirigiendo al pago…",
    pendientePago:
      "Tu lugar está apartado. El negocio te compartirá cómo completar el pago para confirmarlo.",
    otra: "Agendar otra cita",
    avisoDatos:
      "Tus datos los recibe {negocio} para agendar y atender tu cita, y los administra en AgendaUno.",
    verAviso: "Ver su aviso de privacidad",
  },
  sucursalesPub: {
    titulo: "Elige tu sucursal",
    subtitulo:
      "Este negocio tiene varias sedes. Selecciona en cuál quieres tu cita.",
    cargando: "Cargando…",
    noDisponible: "Este negocio no está disponible por ahora.",
    volver: "Ver otros negocios",
    comoLlegar: "Cómo llegar",
  },
  sedes: {
    titulo: "Sucursales",
    agregar: "Agregar sucursal",
    vacio: "Aún no hay sucursales.",
    editar: "Editar",
    nueva: "Nueva sucursal",
    editarTitulo: "Editar sucursal",
    organizacion: "Organización",
    nombre: "Nombre",
    nombrePh: "Ej. Centro",
    region: "Ciudad o zona (opcional)",
    regionPh: "Ej. Centro, Zona Norte",
    zona: "Zona horaria",
    moneda: "Moneda",
    monedaNegocio: "La del negocio ({moneda})",
    ivaLabel: "IVA (%)",
    iva: "{n}% IVA",
  },
  usuarios: {
    rolesGuardados: "Roles de {nombre} guardados.",
    titulo: "Usuarios",
    vacio: "Aún no hay usuarios.",
    colUsuario: "Usuario",
    colRoles: "Roles",
    colEstado: "Estado",
    activo: "Activa",
    inactivo: "Pendiente",
    sinCorreo: "—",
    editarRoles: "Editar roles",
    reenviar: "Reenviar invitación",
    reenviando: "Reenviando…",
    reenviado: "Invitación reenviada",
    guardar: "Guardar roles",
    guardando: "Guardando…",
    cancelar: "Cancelar",
    minimoUnRol: "Selecciona al menos un rol.",
    duenoProtegido:
      "El rol de dueño está protegido: siempre debe existir al menos uno.",
    rol: {
      propietario: "Dueño",
      admin: "Administrador",
      recepcionista: "Recepción",
      instructor: "Instructor",
      miembro: "Miembro",
    },
    sedesTitulo: "Sedes asignadas",
    sedesAyuda:
      "El personal solo ve lo de sus sucursales y necesita al menos una. Propietario y administración ven todas.",
    sedesVacio: "Ve todas las sucursales por su rol.",
    sedeSucursal: "Sucursal",
    sedeRol: "Rol en la sede",
    sedeAsignar: "Asignar",
    sedeAsignando: "Asignando…",
    sedeQuitar: "Quitar de la sucursal",
    confirmarQuitarSede:
      "¿Quitar a {nombre} de {sucursal}? Deja de ver y atender lo de esa sucursal.",
  },
  datosFiscales: {
    titulo: "Datos fiscales",
    razonSocial: "Razón social",
    rfc: "RFC",
    // El régimen (catálogo del SAT) y los requisitos para timbrar:
    // datosFiscalesSat.es-MX.ts.
    codigoPostal: "Código postal",
    guardar: "Guardar datos fiscales",
    guardando: "Guardando…",
    sellos: {
      titulo: "Sello digital (CSD)",
      subtitulo:
        "Tu certificado y llave del SAT para timbrar. Se guardan cifrados.",
      cargados: "Sellos cargados",
      pendientes: "Sellos pendientes",
      certificado: "Certificado (.cer)",
      llave: "Llave privada (.key)",
      password: "Contraseña de la llave",
      cargar: "Cargar sellos",
      cargando: "Cargando…",
      cargado: "Sellos guardados.",
      requiereDatos: "Guarda primero tus datos fiscales.",
    },
  },
  facturas: {
    titulo: "Facturas",
    vacio: "Aún no has emitido facturas.",
    colReceptor: "Receptor",
    colTotal: "Total",
    colEstado: "Estado",
    colFolio: "Folio (UUID)",
    estado: {
      timbrada: "Timbrada",
      error: "Error",
    },
    nueva: {
      titulo: "Emitir factura",
      receptor: "Receptor",
      nombre: "Nombre / Razón social",
      rfc: "RFC",
      email: "Correo (opcional)",
      codigoPostal: "Código postal",
      usoCfdi: "Uso de CFDI",
      formaPago: "Forma de pago",
      conceptos: "Conceptos",
      descripcion: "Descripción",
      cantidad: "Cantidad",
      precio: "Precio unitario (antes de IVA)",
      claveProdServ: "Clave prod/serv (SAT)",
      agregarConcepto: "Agregar concepto",
      quitar: "Quitar",
      emitir: "Emitir y timbrar",
      emitiendo: "Timbrando…",
      timbrada: "Factura timbrada.",
    },
    // Anticipos (ADR 0163): CFDI de anticipo y del resto («por remanente»).
    anticipo: {
      es: "Es la factura de un anticipo",
      esAyuda:
        "Un solo concepto con la clave 84111506, unidad ACT y «Anticipo del bien o servicio», como pide el SAT. Al facturar el resto, relaciónalo con este.",
      importe: "Importe del anticipo (antes de IVA)",
      importeAyuda: "Se le suma el IVA del negocio.",
      porAplicarTitulo: "Anticipos de este RFC por aplicar",
      opcion: "{total} · {fecha} · folio {folio}…",
      restoAyuda:
        "Factura aquí solo el resto: se relaciona con el anticipo (tipo 07) y lleva la leyenda «CFDI por remanente de un anticipo».",
      porAplicar: "Anticipo por aplicar",
      aplicado: "Anticipo aplicado",
    },
    verPdf: "PDF",
  },
  // Avisos de AgendaUno al dueño, en su página de Renta (ADR 0072).
  avisosAgendaUno: {
    titulo: "Avisos de AgendaUno",
    ayuda:
      "Te avisamos cuando tu prueba está por terminar, cuando tu renta está lista, si vence y cuando recibimos tu pago. Te llegan a {correo}.",
    whatsapp: "WhatsApp",
    verificadoEstado: "Verificado",
    sinVerificar: "Sin verificar",
    recibir: "Recibirlos también por WhatsApp",
    verificar: "Verificar por WhatsApp",
    codigo: "Código de 6 dígitos que te llegó por WhatsApp",
    reenviar: "Enviar otro código",
    reenviarEn: "Puedes pedir otro en {s} s",
    verificado: "Tu WhatsApp quedó verificado.",
    cambiar: "Cambiar",
    enTuPagina: "Es el que aparece en tu página para que te escriban.",
    numeroNuevo: "Número nuevo",
    cambioConCodigo:
      "Te mandamos un código a ese WhatsApp; el número cambia al confirmarlo. También es el que aparece en tu página.",
    cambioSinCodigo: "También es el que aparece en tu página.",
    enviarCodigo: "Enviar código",
    guardar: "Guardar",
    cancelar: "Cancelar",
    codigoNuevo: "Código de 6 dígitos que te llegó a {numero}",
    cambiado: "Tu WhatsApp quedó actualizado.",
  },
  // Foto con lupa para verla en grande (p. ej. el profesional al agendar).
  fotoAmpliable: {
    ver: "Ver la foto de {nombre} en grande",
    titulo: "Foto de {nombre}",
    cerrar: "Cerrar",
  },
  plataforma: {
    titulo: "Administración de plataforma",
    // Sin marca: la plataforma es de los dos productos (ADR 0132).
    subtitulo:
      "Todos los negocios de los dos productos y la configuración de la plataforma.",
    token: "Token de la plataforma",
    tokenAyuda: "Solo para el equipo de la plataforma.",
    entrar: "Entrar",
    salir: "Salir",
    tokenInvalido: "Tu sesión terminó o no tiene acceso. Vuelve a entrar.",
    // Entrada en dos pasos (ADR 0126).
    correo: "Correo",
    contrasena: "Contraseña",
    accesoAyuda:
      "Solo para el equipo de la plataforma: tu correo, tu contraseña y el token de la plataforma. Después te enviamos un código por correo.",
    codigoTitulo: "Revisa tu correo",
    codigoAyuda:
      "Te enviamos un código de 6 dígitos a {correo}. Vence en unos minutos y sirve una sola vez.",
    codigo: "Código",
    verificar: "Verificar y entrar",
    empezarDeNuevo: "Empezar de nuevo",
    codigoDesarrollo: "Solo en desarrollo, el código es {codigo}.",
    operador: "Operador: {nombre}",
    facturapi: {
      titulo: "FacturAPI: factura de la renta",
      subtitulo:
        "La llave en vivo de la organización de la plataforma (sk_live_…): con ella se factura la renta de cada negocio.",
      configurada: "Configurada",
      noConfigurada: "Sin configurar",
      llave: "Llave en vivo de la plataforma",
      guardar: "Guardar llave",
      guardando: "Guardando…",
      guardado: "Configuración guardada.",
      ayuda: "Se guarda cifrada y nunca se vuelve a mostrar.",
      // Quitarla detiene la facturación de todos: se confirma (ADR 0132).
      quitar: "Quitar llave",
      confirmarQuitar:
        "¿Quitar la llave de la plataforma? La factura de la renta deja de emitirse hasta que pongas otra.",
      quitada: "Llave de FacturAPI quitada.",
    },
    legales: {
      titulo: "Documentos legales",
      subtitulo:
        "El aviso de privacidad y los términos que ven los negocios al registrarse.",
      aviso: "Aviso de privacidad",
      terminos: "Términos y condiciones",
      guardar: "Guardar documentos",
      guardando: "Guardando…",
      guardado: "Documentos guardados.",
    },
    pasarelas: {
      titulo: "Pasarelas de cobro",
      subtitulo:
        "Cobra la renta del SaaS a los dueños. Las llaves se guardan cifradas y nunca se muestran.",
      activa: "Activa",
      modo: "Modo",
      test: "Pruebas",
      live: "Producción",
      configurada: "•••••• (configurada)",
      guardar: "Guardar",
      guardando: "Guardando…",
      guardado: "Pasarela actualizada.",
      webhookUrl: "URL del webhook",
      webhookEventosStripe:
        "Regístrala en Stripe con los eventos checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, payment_intent.succeeded, payment_intent.payment_failed y payment_intent.canceled.",
    },
    estudios: {
      titulo: "Estudios",
      colSlug: "Slug",
      colNombre: "Nombre",
      colFacturacion: "Facturación",
      colCobro: "Cobro",
      vacio: "Aún no hay estudios.",
      editar: "Editar cobro",
      editarTitulo: "Cobro de {estudio}",
      modoCobro: "Modo de cobro",
      cuotaFija: "Cuota fija mensual",
      guardar: "Guardar",
      modo: {
        activos: "Por alumnos activos",
        // Un negocio de citas en modo «activos» paga su plan (ADR 0107).
        plan: "Por plan y profesionales",
        fijo: "Cuota fija",
      },
    },
  },
  tareas: {
    titulo: "Tareas",
    // Sin permiso para las del equipo: solo las suyas.
    tituloMias: "Mis tareas",
    tabPendientes: "Pendientes",
    tabReglas: "Automatización",
    nueva: "Nueva tarea",
    completar: "Completar",
    reabrir: "Reabrir",
    verCompletadas: "Ver completadas",
    verPendientes: "Ver pendientes",
    automatica: "Automática",
    sinVencimiento: "Sin fecha",
    sinPendientes: "Sin tareas pendientes. ¡Todo al día!",
    sinCompletadas: "Aún no hay tareas completadas.",
    errorCarga: "No se pudieron cargar las tareas.",
    guardar: "Guardar",
    cancelar: "Cancelar",
    campos: {
      titulo: "Título",
      detalle: "Detalle",
      vence: "Fecha límite",
    },
    reglas: {
      errorCarga: "No se pudieron cargar las reglas.",
      subtitulo:
        "Cuando ocurre un evento y se cumple la condición, se crea una tarea.",
      nueva: "Nueva regla",
      sinReglas: "Aún no hay reglas. Crea la primera.",
      nombre: "Nombre",
      evento: "Cuando ocurre",
      condicionCampo: "Campo",
      condicionValor: "Valor",
      tituloTarea: "Título de la tarea",
      detalleTarea: "Detalle de la tarea",
      retraso: "Vencimiento (minutos después)",
      activa: "Activa",
      inactiva: "Inactiva",
      activar: "Activar regla",
      desactivar: "Desactivar regla",
      guardar: "Guardar regla",
      editar: "Editar",
      eliminar: "Eliminar regla",
      ayudaPlantilla: "Usa {a}, {b} y otros datos del evento.",
      confirmarEliminar: "¿Eliminar esta regla?",
    },
    eventos: {
      reserva_creada: "Se crea una reserva",
      reserva_ofrecida: "Se ofrece un lugar (lista de espera)",
      pago_reembolsado: "Se reembolsa un pago",
      acceso_registrado: "Se registra un acceso",
      factura_timbrada: "Se timbra una factura",
      membresia_suspendida: "Se suspende una membresía",
      membresia_regularizada: "Se regulariza una membresía",
    },
  },
  promociones: {
    titulo: "Promociones",
    nueva: "Nueva promoción",
    editar: "Editar",
    eliminar: "Eliminar promoción",
    sinPromos: "Aún no hay promociones. Crea la primera.",
    confirmarEliminar: "¿Eliminar esta promoción?",
    guardada: "Promoción {codigo} guardada.",
    guardar: "Guardar",
    cancelar: "Cancelar",
    vigente: "Vigente",
    noVigente: "No vigente",
    usos: "Usos",
    sinLimite: "sin límite",
    vence: "Vence",
    sinVence: "sin vencimiento",
    campos: {
      codigo: "Código",
      descripcion: "Descripción",
      tipo: "Tipo de descuento",
      valorPorcentaje: "Porcentaje (%)",
      valorMonto: "Monto (en la moneda)",
      minimo: "Compra mínima (opcional)",
      usosMaximos: "Usos máximos (opcional)",
      vence: "Vence (opcional)",
      activa: "Activa",
    },
    tipos: {
      porcentaje: "Porcentaje",
      monto_fijo: "Monto fijo",
    },
  },
  pos: {
    titulo: "Punto de venta",
    tabVender: "Vender",
    tabInventario: "Inventario",
    sucursal: "Sucursal",
    sinArticulos: "Aún no hay artículos. Agrégalos en Inventario.",
    sinSucursales: "Primero crea una sucursal.",
    agotado: "Agotado",
    stock: "Stock",
    carrito: "Carrito",
    carritoVacio: "Toca un artículo para agregarlo.",
    metodo: "Método de pago",
    total: "Total",
    cobrar: "Cobrar",
    cobrando: "Cobrando…",
    vendido: "Venta registrada.",
    metodos: {
      efectivo: "Efectivo",
      tarjeta: "Tarjeta",
      transferencia: "Transferencia",
      // Con varias formas de pago (ADR 0177).
      mixto: "Varias formas",
    },
    inventario: {
      nuevo: "Nuevo artículo",
      sinArticulos: "Aún no hay artículos.",
      nombre: "Nombre",
      sku: "SKU (opcional)",
      precio: "Precio (en la moneda)",
      guardar: "Guardar",
      cancelar: "Cancelar",
      reabastecer: "Reabastecer",
      cantidad: "Cantidad",
      stockTotal: "Stock total",
      agregarStock: "Agregar stock",
    },
    ventas: {
      titulo: "Ventas recientes",
      vacio: "Aún no hay ventas.",
      colSucursal: "Sucursal",
      colTotal: "Total",
      colMetodo: "Método",
    },
  },
  quickstart: {
    titulo: "Pon tu negocio en marcha",
    guiada: "Configuración guiada",
    ir: "Ir",
    opcionales: "Opcionales:",
    hecho: "(hecho)",
    pendiente: "(pendiente)",
    // Lo opcional; los pasos llevan los nombres de la configuración guiada.
    tareas: {
      cobro: "Cobrar en línea",
      miembros: "Registra a tu primer alumno",
    },
    // La lista para empezar, comprobada con los datos (H).
    // Se usa con llave dinámica (`quickstart.comprobaciones.${clave}`).
    comprobaciones: {
      titulo: "Comprobado",
      servicios: "Tienes servicios a la venta",
      disponibilidad: "Hay una fecha que se puede reservar",
      precios: "Tienen precio (o hay un plan para entrar)",
      cobro: "Ya se registró un cobro (en caja o aprobado en línea)",
      prueba:
        "Un alumno ya reservó desde tu página o su cuenta (haz una prueba con «Ver como alumno»)",
      publicar: "Tu página está publicada",
    },
  },
  lealtad: {
    recompensaCreada: "Recompensa «{nombre}» creada.",
    titulo: "Lealtad",
    puntos: "puntos",
    guardado: "Programa actualizado.",
    canjeado: "Recompensa canjeada.",
    ajustado: "Puntos ajustados.",
    programa: {
      titulo: "Cómo se ganan puntos",
      activa: "Programa activo",
      porAsistencia: "Puntos por asistir a una clase",
      porMoneda: "Puntos por unidad de moneda gastada",
      // {monto}: una unidad de la moneda del negocio («$1», «1 €»).
      porMonedaAyuda: "Ej. 1 = un punto por cada {monto} pagado.",
    },
    recompensas: {
      titulo: "Recompensas",
      vacio: "Aún no hay recompensas.",
      nombrePh: "Ej. Clase gratis",
      crear: "Agregar",
      activa: "Activa",
      inactiva: "Inactiva",
    },
    miembro: {
      titulo: "Puntos de un miembro",
      elige: "Elige un miembro…",
      saldo: "Puntos disponibles",
      canjear: "Canjear recompensa",
      canjearBtn: "Canjear",
      ajustar: "Ajustar",
      ajustePh: "± puntos",
      motivo: "Motivo (opcional)",
      verMas: "Ver más ({n})",
    },
    canjes: {
      titulo: "Canjes recientes",
      vacio: "Aún no hay canjes.",
      colMiembro: "Miembro",
      colRecompensa: "Recompensa",
      colPuntos: "Puntos",
      colEstado: "Estado",
      colAccion: "Acción",
      entregar: "Entregar",
      cancelar: "Cancelar canje",
    },
    estados: {
      pendiente: "Pendiente",
      entregado: "Entregado",
      cancelado: "Cancelado",
    },
    origen: {
      asistencia: "Asistencia",
      compra: "Compra",
      manual: "Ajuste manual",
      canje: "Canje",
    },
  },
  renta: {
    titulo: "Mi suscripción",
    suspendidoTitulo: "Tu negocio está suspendido por una renta sin pagar",
    // Para quien no ve la facturación (alumnos, quien imparte, recepción).
    noDisponibleTitulo: "Este negocio no está disponible por ahora",
    noDisponibleAyuda:
      "Por el momento no se puede agendar ni entrar. Cuando vuelva a estar activo podrás usarlo como siempre; tu información está a salvo.",
    suspendidoAyuda:
      "Mientras tanto nadie puede agendar ni entrar al panel. Paga la renta pendiente aquí abajo y se reactiva solo en unos minutos; tu información está a salvo.",
    historial: "Historial de cargos",
    // Con qué se paga la renta (ADR 0192).
    pagarCon: "Pagar con",
    pasarelas: {
      stripe: "Tarjeta",
      mercadopago: "Mercado Pago",
      openpay: "OpenPay",
    },
    sinCargos: "Aún no hay cargos generados.",
    colPeriodo: "Periodo",
    colMonto: "Monto",
    colEstado: "Estado",
    colVence: "Vence",
    colAccion: "Acción",
    pagar: "Pagar",
    pagando: "Procesando…",
    pago: {
      iniciado:
        "Pago iniciado. Se confirmará en cuanto la pasarela reporte el cobro.",
      confirmado: "¡Pago confirmado! Tu suscripción quedó al corriente.",
    },
    factura: {
      facturar: "Facturar",
      reintentar: "Reintentar",
      procesando: "Emitiendo…",
      pdf: "PDF",
      xml: "XML",
      timbrada: "Factura emitida. Ya puedes descargar tu CFDI (PDF/XML).",
    },
    // Sin factura posible (otra moneda u otro país): recibo sin valor fiscal.
    recibo: {
      descargar: "Descargar recibo",
      nota: "El pago se procesa de forma segura con la pasarela de AgendaUno. De cada pago puedes descargar un recibo sin valor fiscal.",
    },
    pagoNota:
      "El pago se procesa de forma segura con la pasarela de AgendaUno. Tu factura (CFDI) se emite con tus datos fiscales.",
  },
  reportes: {
    noSeConsulto: "No se pudo consultar este reporte. {error}",
    titulo: "Reportes",
    // Subtítulo de cada vista de Reportes (la del menú, `?vista=`).
    subtitulos: {
      resumen: "Las cifras principales del negocio en el periodo elegido.",
      ingresos:
        "Lo que se vendió y se cobró en el periodo, y cuánto deja lo que ofreces.",
      ocupacion:
        "Qué horarios se llenan y qué actividades tienen más demanda en el periodo.",
      clientes:
        "Cuántos alumnos nuevos compran y cuántos alumnos siguen asistiendo mes con mes.",
      embudo:
        "Cuántos avanzan en cada paso, desde que se interesan hasta que vuelven.",
      equipo:
        "La agenda de cada instructor en el periodo y cómo está hoy cada sucursal.",
      equipoSolo: "Tu agenda en el periodo y cómo está hoy cada sucursal.",
    },
    desde: "Desde",
    hasta: "Hasta",
    esteMes: "Este mes",
    tendencias: {
      titulo: "Tendencia de ingresos",
      agrupacion: "Agrupación",
      dia: "Por día",
      semana: "Por semana",
      mes: "Por mes",
      exportar: "Exportar CSV",
      exportando: "Exportando…",
      ticket: "Ticket promedio",
      vacio: "Sin ventas ni cobros en el periodo.",
      // Qué representa cada cifra: lo vendido (por la fecha de la compra) o el
      // dinero que entró menos devoluciones (por la fecha del cobro).
      queMuestra: "Qué muestra la gráfica",
      cobradoNeto: "Cobrado neto",
      cobradoDevuelto: "cobrado {cobrado}, devuelto {devuelto}",
      vendido: "Vendido",
      vendidoN:
        "Vendido · sin ventas | Vendido · 1 venta | Vendido · {n} ventas",
      ayudaNeto:
        "El dinero que entró (en caja, en línea y mostrador) menos lo devuelto, por la fecha del cobro.",
      ayudaVendido:
        "Lo que se vendió (compras y mostrador, pagado o no), por la fecha de la compra.",
      porProducto: "Ventas por producto",
      sinProducto: "Sin ventas por producto en el periodo.",
      colProducto: "Producto",
      colUnidades: "Unidades",
      colVendido: "Vendido",
      otrasMonedas: "Otras monedas",
      otrasMonedasAyuda:
        "Dinero registrado en otra moneda antes de trabajar con una sola. Se muestra aparte: las monedas nunca se suman.",
    },
    conversion: {
      titulo: "Conversión",
      subtitulo:
        "De las altas de los últimos {n} meses: cuántas compraron y cuántas se activaron.",
      registrados: "Registrados",
      compraron: "Compraron",
      activos: "Activos",
    },
    cohortes: {
      titulo: "Retención por cohorte",
      subtitulo:
        "De cada mes de alta, qué % siguió asistiendo en los meses siguientes.",
      colCohorte: "Alta",
      colAltas: "Altas",
      mesN: "Mes {n}",
      leyenda:
        "Cada celda es el % de esa cohorte que asistió ese mes. El color marca la intensidad.",
    },
    rentabilidad: {
      titulo: "Rentabilidad por clase",
      vacio: "No hay clases con asistencia en el periodo.",
      colClase: "Clase",
      colSesiones: "Sesiones",
      colAsistentes: "Asistentes",
      // Citas: por servicio y clientes atendidos (ADR 0131).
      tituloCitas: "Rentabilidad por servicio",
      vacioCitas: "No hay citas atendidas en el periodo.",
      colClaseCitas: "Servicio",
      colSesionesCitas: "Citas",
      colAsistentesCitas: "Alumnos atendidos",
      colIngreso: "Ingreso",
      colCosto: "Costo instructor",
      colMargen: "Margen",
      total: "Total",
      sinCosto:
        "{n} asistencia(s) sin valor unitario (membresías ilimitadas o cortesías); no suman ingreso.",
      sinCostoCitas:
        "{n} cita(s) sin valor unitario (cortesías); no suman ingreso.",
    },
    demanda: {
      titulo: "Demanda por horario",
      subtitulo:
        "Cuándo se llena tu estudio y qué clases tienen más presión de lista de espera.",
      vacio: "No hay sesiones en el periodo.",
      dias: "Lun,Mar,Mié,Jue,Vie,Sáb,Dom",
      hora: "Hora",
      leyenda:
        "El color indica la ocupación; el punto marca clases con lista de espera.",
      subtituloAgenda:
        "Cuándo está más llena la agenda de tu equipo: horas agendadas entre las disponibles, por día y hora.",
      leyendaAgenda:
        "El color indica qué parte del horario de atención estuvo agendada, sin días cerrados ni bloqueos.",
      resumenAgenda:
        "Agenda ocupada al {pct}: {agendadas} de {disponibles} disponibles.",
      porActividad: "Demanda por actividad",
      colActividad: "Actividad",
      colSesiones: "Sesiones",
      colConfirmadas: "Confirmadas",
      colEspera: "En espera",
      colOcupacion: "Ocupación",
      total: "Total",
      vistas: { calor: "Mapa de calor", burbujas: "Burbujas" },
      medidas: { ocupacion: "Ocupación", espera: "Lista de espera" },
      promedio: "Promedio de ocupación",
      horaPico: "Hora pico",
      llenos: "Horarios llenos",
      deTotal: "{n} de {total}",
      niveles: {
        baja: "Baja (0–40%)",
        media: "Media (41–70%)",
        alta: "Alta (71–100%)",
        bajaEspera: "Poca espera",
        mediaEspera: "Media",
        altaEspera: "Mucha espera",
      },
      puntoEspera: "Punto = lista de espera",
      lugaresReservados: "{n} de {total} lugares reservados",
      horasAgendadas: "{agendadas} agendadas de {disponibles}",
      enEspera: "{n} en lista de espera",
      ocupacion: "ocupación",
    },
    metricas: {
      ingresos: "Ingresos netos",
      ocupacion: "Ocupación",
      noShow: "Inasistencias",
      alumnos: "Alumnos activos",
      arpu: "ARPU",
      clases: "Clases",
    },
    // El dinero del periodo por moneda (nunca se suman monedas distintas).
    dinero: {
      moneda: "Moneda",
      vendido: "Vendido",
      cobrado: "Cobrado",
      devuelto: "Devuelto",
      neto: "Neto",
      ayuda:
        "Vendido: compras y ventas de mostrador por su fecha. Cobrado: el dinero que entró, por la fecha del cobro. Neto: cobrado menos devuelto.",
    },
    // Agenda del equipo (ADR 0081).
    equipo: {
      titulo: "Agenda del equipo",
      subtitulo:
        "Qué tan llena estuvo la agenda de cada instructor, cuánto dejó lo que atendió y cuánto se le paga.",
      // Desde Equipo › Rendimiento.
      rendimiento: "Rendimiento del equipo",
      // Quien trabaja solo: su propia agenda.
      tituloSolo: "Tu agenda",
      subtituloSolo:
        "Qué tan llena estuvo tu agenda, cuántas faltas hubo y cuánto dejó lo que atendiste.",
      vacio: "No hay instructores ni clases en el periodo.",
      colProfesional: "Instructor",
      colOcupacion: "Ocupación",
      colSesiones: "Clases",
      colInasistencia: "Inasistencias",
      colValor: "Valor atendido",
      colPago: "Pago",
      colMargen: "Margen",
      sinHorario: "Sin horario",
      sinProfesional: "Sin instructor asignado",
      clases: "1 clase | {n} clases",
      citas: "1 cita | {n} citas",
      total: "Total",
      leyenda:
        "Ocupación: horas agendadas dentro de su horario de atención entre las disponibles, sin días cerrados ni bloqueos. El valor atendido es el precio de lo que se atendió (o el de las sesiones de bono que se usaron); el pago sale de su esquema en Nómina, incluidas las clases que cubre o donde apoya.",
      leyendaClases:
        "El valor atendido es el precio de lo que se atendió (o los créditos que se usaron); el pago sale de su esquema en Nómina, incluidas las clases que cubre o donde apoya.",
    },
    porSucursal: "Por sucursal",
    colSucursal: "Sucursal",
    colRegion: "Región",
    colMoneda: "Moneda",
    colMiembros: "Miembros",
    colClases: "Clases próximas",
    sinSucursales: "Aún no hay sucursales.",
  },
  oportunidades: {
    titulo: "Oportunidades",
    horizonte: "Horizonte",
    dias: "Próximos {n} días",
    vacio: "No hay clases próximas con lugares libres.",
    libres: "{n} libres",
    enEspera: "{n} en espera",
    promover: "Ofrecer lugares",
    promoviendo: "Ofreciendo…",
    ofrecidas:
      "Se ofreció 1 lugar a la lista de espera. | Se ofrecieron {n} lugares a la lista de espera.",
    sinPromover: "No hay a quién ofrecer en esta clase.",
  },
  recursos: {
    titulo: "Recursos",
    nuevo: "Nuevo recurso",
    nombre: "Nombre",
    sucursal: "Sucursal",
    tipo: "Tipo (opcional)",
    modo: "Modo",
    modoUnidad: "Unidad (una clase a la vez)",
    modoPool: "Pool (varias en paralelo)",
    capacidad: "Capacidad simultánea",
    crear: "Crear recurso",
    creando: "Creando…",
    vacio: "Aún no hay recursos.",
    eliminar: "Eliminar espacio",
    confirmarEliminar:
      "¿Eliminar este espacio? Sus citas próximas pasan a otro espacio libre del servicio; si alguna no cabe, te diremos cuál reprogramar.",
    activado: "Espacio activado.",
    desactivado: "Espacio desactivado.",
    eliminado: "Espacio eliminado.",
    creado: "Espacio «{nombre}» creado.",
    // Corregir un espacio (su sede no cambia).
    editar: "Editar",
    editarTitulo: "Editar espacio",
    guardar: "Guardar cambios",
    guardando: "Guardando…",
    guardado: "«{nombre}» quedó guardado.",
    capacidadAyuda:
      "No puede quedar por debajo de las sesiones próximas que ya lo usan a la vez.",
    movidas:
      "Su cita próxima pasó a otro espacio. | Sus {n} citas próximas pasaron a otros espacios.",
  },
  nomina: {
    titulo: "Nómina",
    esquemas: "Esquemas de pago",
    staff: "Miembro del staff",
    tipo: "Tipo",
    tipoPorClase: "Por clase",
    tipoPorAsistente: "Por asistente",
    tipoPorHora: "Por hora",
    monto: "Monto",
    guardarEsquema: "Guardar esquema",
    calcular: "Calcular",
    desde: "Desde",
    hasta: "Hasta",
    periodo: "Periodo",
    colStaff: "Equipo",
    colTipo: "Tipo",
    colUnidades: "Unidades",
    colTotal: "Total",
    vacio: "Define un esquema de pago para calcular la nómina.",
    guardado: "Esquema guardado.",
    totalPeriodo: "Total del periodo",
  },
  cursos: {
    titulo: "Grupos",
    nuevo: "Nuevo grupo",
    nombre: "Nombre del grupo",
    horario: "Horario recurrente",
    crear: "Crear grupo",
    creando: "Creando…",
    vacio: "Aún no hay grupos.",
    inscritos: "{n} inscritos",
    inscribir: "Inscribir",
    inscribiendo: "Inscribiendo…",
    elegirMiembro: "Elige un alumno",
    inscritosTotal: "Sin inscritos | 1 inscrito | {n} inscritos",
    buscarInscrito: "Buscar inscrito",
    inscritoBaja: "Baja",
    adelantarBaja: "Adelantar o cambiar",
    sinInscritos: "Aún no hay inscritos.",
    creado: "Grupo «{nombre}» creado.",
    sinInscritosBusqueda: "Nadie inscrito con ese nombre.",
    errorInscritos: "No se pudieron cargar los inscritos.",
    verMasInscritos: "Ver 1 más | Ver {n} más",
    // Cómo quedó cada fecha al inscribir.
    resumenInscripcion: "Inscrito: {detalle}.",
    resumen: {
      reservadas:
        "ninguna reservada | 1 clase reservada | {n} clases reservadas",
      enEspera:
        "nadie en espera | 1 en lista de espera | {n} en lista de espera",
      noReservadas: "todas reservadas | 1 sin reservar | {n} sin reservar",
    },
    sinFechas: "Quedó inscrito. El grupo aún no tiene clases próximas.",
    verFechas: "Ver fecha por fecha",
    fechasTitulo: "Cómo quedó cada fecha",
    col: { fecha: "Fecha", estado: "Cómo quedó", motivo: "Motivo" },
    estadoFecha: {
      reservada: "Reservada",
      en_espera: "En espera",
      no_reservada: "Sin reservar",
    },
    // Salidas con fecha: baja, cambio de grupo y cierre.
    darDeBaja: "Dar de baja del grupo",
    salidaTitulo: "Salida de {persona}",
    desde: "Desde",
    pasarA: "Pasar a otro grupo",
    soloBaja: "No, solo darle de baja",
    salidaAyuda:
      "Desde esa fecha se cancelan sus reservas de este grupo y se le devuelven sus créditos. Lo anterior queda en su historial.",
    salidaAyudaCambio:
      "Desde esa fecha se cancelan sus reservas de este grupo, se le devuelven sus créditos y se reservan sus clases en «{grupo}».",
    cambiarDeGrupo: "Cambiar de grupo",
    dadoDeBaja:
      "{persona} dejó el grupo desde el {fecha}. | {persona} dejó el grupo desde el {fecha}; se canceló 1 reserva. | {persona} dejó el grupo desde el {fecha}; se cancelaron {n} reservas.",
    cambiado: "{persona} pasó a «{grupo}».",
    noCanceladas:
      "1 reserva no se pudo cancelar (ya empezó o tiene asistencia). | {n} reservas no se pudieron cancelar (ya empezaron o tienen asistencia).",
    bajaDesde: "Baja desde el {fecha}",
    // Alta con fecha futura (al cambiar de grupo desde un día).
    empiezaEl: "Empieza el {fecha}",
    // Vista previa del cambio de grupo (R08).
    previa: {
      calculando: "Revisando qué pasaría…",
      resumen:
        "Se cancelan {canceladas} reservas de este grupo. En «{grupo}»: {reservadas} reservadas y {espera} en lista de espera.",
      parcial:
        "1 fecha no se podría reservar o cancelar: | {n} fechas no se podrían reservar o cancelar:",
      aceptarParcial:
        "Hacer el cambio de todos modos (esas fechas quedan sin reservar).",
    },
    cerrarGrupo: "Cerrar grupo",
    cerrarTitulo: "Cerrar «{grupo}»",
    cerrarAyuda:
      "Ya no se inscribe a nadie. Quienes siguen inscritos se dan de baja desde esa fecha: se cancelan sus reservas del grupo y se les devuelven sus créditos. Su historial se conserva.",
    cerrado:
      "Se cerró «{grupo}». | Se cerró «{grupo}» y se dio de baja a 1 inscrito. | Se cerró «{grupo}» y se dio de baja a {n} inscritos.",
    cerradoEl: "Cerrado el {fecha}. Reábrelo para inscribir de nuevo.",
    cerradoSinFecha: "Grupo cerrado. Reábrelo para inscribir de nuevo.",
    reabrir: "Reabrir",
    confirmarReabrir:
      "¿Reabrir «{grupo}» para inscribir? Quienes se dieron de baja siguen así.",
    reabierto: "«{grupo}» está abierto de nuevo.",
    confirmarInscribir:
      "Al inscribirlo en «{grupo}» se reservan, con su plan, todas las clases próximas del grupo.",
  },
  recepcion: {
    titulo: "Recepción",
    fecha: "Fecha",
    hoy: "Hoy",
    todasSucursales: "Todas las sucursales",
    metricas: {
      sesiones: "Clases",
      ocupacion: "Ocupación",
      confirmadas: "Confirmadas",
      enEspera: "En espera",
      presentes: "Presentes",
      ausentes: "Ausentes",
    },
    abrirAgenda: "Abrir en agenda",
    vacio: "No hay clases este día.",
    cancelada: "Cancelada",
    panel: {
      cerrar: "Cerrar",
      presentes: "{n} presentes",
      enSala: "{n} en clase",
      espera: "{n} en espera",
      llego: "Llegó",
      noVino: "No vino",
      // Llegó tarde: cuenta como asistencia (ADR 0101).
      retardo: "Retardo",
      // La lista se pasa desde unos minutos antes (configurable).
      abreDesde: "La asistencia se registra desde las {hora}.",
      terminarLista: "Terminar lista",
      terminarListaConfirmar:
        "Quien sigue sin registro ({n}) quedará como «no vino», con la política de inasistencias del negocio.",
      listaTerminada:
        "Lista terminada: nadie quedó sin registro. | Lista terminada: 1 persona quedó como «no vino». | Lista terminada: {n} personas quedaron como «no vino».",
      listaEspera: "Lista de espera",
      promover: "Ofrecer lugares",
      agregar: "Agregar alumno",
      buscarAgregar: "Buscar alumno para agregar…",
    },
    buscarMiembro: "Buscar alumno por nombre o correo…",
    sinResultados: "Sin resultados.",
    alertas: {
      adeudo: "Adeudo",
      membresia_vencida: "Membresía vencida",
      membresia_por_vencer: "Vence pronto",
      membresia_pausada: "Membresía en pausa",
      sin_acceso: "Sin plan para reservar",
      documentos: "Documentos pendientes",
      // En citas lo que se vende son bonos, no membresías (ADR 0130).
      membresia_vencidaCitas: "Bono vencido",
      membresia_pausadaCitas: "Bono en pausa",
    },
    membresia: {
      sin: "Sin membresía ni paquete",
      vigente: "Vigente",
      por_vencer: "Por vencer",
      vencida: "Vencida",
      pausada: "En pausa",
      sinCitas: "Sin bono",
      vencidaCitas: "Vencido",
    },
    miembro: {
      sinAlertas: "Todo en orden",
      membresia: "Membresía",
      membresiaCitas: "Bono",
      saldo: "Saldo",
      creditos: "1 disponible | {n} disponibles",
      ilimitado: "Ilimitado",
      proxima: "Próxima reserva",
      sinProxima: "Sin próxima reserva",
      asistencias: "Asistencias",
      documentos: "Documentos pendientes",
      vender: "Vender / cobrar",
      elegirProducto: "Elige un producto o membresía",
      elegirProductoCitas: "Elige un bono",
      // Se suma al plan si es su alta o regresa tras un tiempo (B04).
      conInscripcion: "Más inscripción de {monto}: se cobran {total}.",
      efectivo: "Efectivo",
      tarjeta: "Tarjeta (terminal)",
      transferencia: "Transferencia",
      cobrar: "Cobrar",
      cobrando: "Cobrando…",
      vendido: "Venta registrada y cobrada.",
      verFicha: "Ver ficha completa",
    },
  },
  agenda: {
    titulo: "Agenda",
    subtitulo:
      "Gestiona y visualiza todas tus clases, instructores y espacios en un solo lugar.",
    disponibilidadEquipo: "Disponibilidad del equipo",
    reglasReserva: "Reglas de reserva",
    hoy: "Hoy",
    semanaAnterior: "Semana anterior",
    semanaSiguiente: "Semana siguiente",
    todasSucursales: "Todas las sucursales",
    nuevaClase: "Nueva clase",
    sinClasesDia: "Sin clases este día.",
    errorCarga: "No se pudo cargar la agenda.",
    completo: "Completo",
    estados: {
      programada: "Con lugar",
      completa: "Llena",
      cancelada: "Cancelada",
      disponible: "Disponible",
      casi: "Casi llena",
      llena: "Llena",
      espera: "Con lista de espera",
      esperaN: "{n} en espera",
    },
    cerrarDetalle: "Cerrar",
    nueva: {
      titulo: "Nueva clase",
      oferta: "Oferta",
      sucursal: "Sucursal",
      fecha: "Fecha y hora",
      duracion: "Duración (min)",
      capacidad: "Cupo (opcional)",
      instructor: "Instructor (opcional)",
      sinInstructor: "Sin asignar",
      sala: "Sala / recurso",
      sinSala: "Sin sala",
      crear: "Programar clase",
      creando: "Programando…",
      conflictos: "Hay un conflicto de horario:",
      sinOfertas: "Primero crea una clase en el Catálogo.",
      sinSucursales: "Primero crea una sucursal.",
      errorCarga: "No se pudieron cargar las clases y sucursales.",
      modoUna: "Una sola clase",
      modoVarias: "Varias que se repiten",
      primeraClase: "Primera clase (fecha y hora)",
      diasSemana: "¿Qué días se repite?",
      repetirHasta: "Repetir hasta (opcional)",
      repetirAyuda:
        "Si lo dejas vacío, se repite sin fecha final: siempre hay clases programadas por adelantado.",
    },
    sesion: {
      reservas: "Reservas",
      cancelar: "Cancelar clase",
    },
    // El cupo y la sala de esta fecha (ADR 0223).
    cupoSala: {
      titulo: "Cupo y sala de esta fecha",
      cupo: "Lugares",
      sinTope: "Sin tope",
      sala: "Sala",
      sinSala: "Sin sala",
      guardar: "Guardar cupo y sala",
      guardado: "Cupo y sala guardados.",
      ayuda:
        "Solo cambia esta fecha. El cupo no puede quedar por debajo de los lugares ya ocupados.",
    },
    staff: {
      titulo: "Equipo",
      persona: "Miembro del equipo",
      rolLabel: "Rol",
      sustituye: "Sustituye a",
      asignar: "Asignar",
      quitar: "Quitar de la clase",
      rol: {
        instructor: "Instructor",
        asistente: "Asistente",
        sustituto: "Sustituto",
      },
    },
    reservar: {
      miembro: "Alumno",
      elegir: "Elige…",
      esperar: "A lista de espera si está llena",
      reservar: "Reservar",
      canal: "Canal",
    },
    canales: {
      directo: "Directo",
      wellhub: "Wellhub",
      totalpass: "TotalPass",
      classpass: "ClassPass",
      fitpass: "Fitpass",
      otro: "Otro",
    },
    cuposCanal: {
      titulo: "Cupos por canal",
      ayuda:
        "Aparta lugares de esta oferta para un marketplace. Se liberan al pool general las horas indicadas antes del inicio.",
      cupos: "{n} cupos",
      libera: "libera {h} h antes",
      campoCupos: "Cupos",
      campoLibera: "Libera (h)",
      guardar: "Guardar",
      quitar: "Quitar cupos del canal",
    },
    lugares: {
      titulo: "Mapa de lugares",
      ayuda:
        "Número de lugares numerados de esta clase (p. ej. reformers, bicis). 0 = sin lugares asignados.",
      numero: "Lugares",
      precio: "Precio de clase",
      precioPh: "0 = por créditos",
      precioAyuda:
        "Precio drop-in de esta clase para el reporte de rentabilidad. Déjalo en 0 para estimarlo por los créditos consumidos.",
      guardar: "Guardar",
      elige: "Elige un lugar (opcional)",
      ocupado: "Ocupado",
      lugarN: "Lugar {n}",
    },
    roster: {
      vacio: "Sin reservas.",
      errorCarga: "No se pudo cargar quién va.",
      sinPermiso:
        "Tu rol no incluye ver reservas: aquí no se muestra quién va.",
      confirmada: "Confirmada",
      en_espera: "En espera",
      ofrecida: "Oferta",
      pendiente_pago: "Pago pendiente",
      expirada: "Expirada",
      cancelada: "Cancelada",
      presente: "Presente",
      ausente: "Ausente",
      retardo: "Llegó tarde",
      // La marcó el sistema al terminar la clase o cita sin registro.
      ausenteAutomatica: "No se presentó (automático)",
      marcarPresente: "Presente",
      marcarAusente: "Ausente",
      aceptar: "Aceptar",
      cobrar: "Cobrar",
      cancelarReserva: "Cancelar reserva",
      transferir: "Transferir",
      transferirA: "Regalar el lugar a",
      // Historial de un lugar que se transfirió (bitácora).
      transferido: "Lugar transferido · Ver historial",
      sistema: "Sistema",
      confirmarTransfer: "Transferir",
      primeraVez: "1ª vez",
      primeraVezAyuda: "Es su primera clase: dale la bienvenida.",
      // Lo que falta pagar de la reserva (aparte de si vino).
      porCobrar: "Por cobrar {monto}",
      // Después de cancelar: a quién y qué pasó con su crédito o su pago.
      reservaCancelada: "Se canceló la reserva de {persona}. {efecto}",
      adeudo: "Adeudo",
      documentos: "Documentos",
      documentosAyuda:
        "Tiene documentos o consentimientos pendientes de firmar.",
    },
  },
  pasarelas: {
    titulo: "Pasarelas de pago",
    subtitulo:
      "Conecta tus llaves para cobrar en línea. Se guardan cifradas y nunca se muestran.",
    activa: "Activa",
    modo: "Modo",
    test: "Pruebas",
    live: "Producción",
    guardar: "Guardar",
    guardando: "Guardando…",
    guardado: "Guardado.",
    configurada: "configurada",
    nuevaLlave: "Escribe para reemplazar",
    sinLlaves: "Ventanilla no requiere llaves.",
    proveedores: {
      stripe: "Stripe",
      openpay: "OpenPay",
      mercadopago: "Mercado Pago",
      ventanilla: "Ventanilla (depósito)",
    },
  },
  documentos: {
    titulo: "Documentos",
    tipos: {
      titulo: "Documentos requeridos",
      nuevo: "Nuevo requisito",
      nombre: "Nombre",
      nombrePh: "INE / Identificación",
      descripcion: "Descripción (opcional)",
      obligatorio: "Obligatorio",
      aplicaA: "Aplica a",
      miembro: "Alumnos",
      instructor: "Instructores",
      todos: "Todos",
      crear: "Agregar requisito",
      vacio: "Aún no defines documentos requeridos.",
    },
    docs: {
      titulo: "Documentos cargados",
      subir: "Subir documento",
      persona: "Persona",
      tipo: "Tipo",
      sinTipo: "Sin tipo",
      archivo: "Archivo (jpg, png o pdf)",
      subirBtn: "Subir",
      subiendo: "Subiendo…",
      filtro: "Filtrar",
      todos: "Todos",
      pendiente: "Pendiente",
      aprobado: "Aprobado",
      rechazado: "Rechazado",
      aprobar: "Aprobar",
      rechazar: "Rechazar",
      rechazarTitulo: "Rechazar documento",
      rechazarAyuda:
        "{documento} de {persona}. Cuéntale qué corregir: verá el motivo en sus documentos.",
      ver: "Ver",
      motivo: "Motivo del rechazo (opcional)",
      vacio: "Sin documentos.",
      elegir: "Elige…",
    },
  },
  formularios: {
    errorCarga: "No se pudieron cargar las fichas de datos.",
    disenar: "Diseñar fichas de datos",
    verRespuestas: "Ver respuestas",
    titulo: "Ficha de datos",
    nuevo: "Nueva ficha de datos",
    nombrePh: "Ficha médica",
    miembro: "Alumnos",
    instructor: "Instructores",
    todos: "Todos",
    crear: "Crear ficha de datos",
    vacio: "Aún no hay fichas de datos.",
    seleccionar: "Selecciona una ficha de datos para editarla o responderla.",
    campos: {
      titulo: "Campos",
      etiquetaPh: "¿Alguna condición médica?",
      obligatorio: "Obligatorio",
      opciones: "Opciones (separa con comas)",
      agregar: "Agregar campo",
      vacio: "Esta ficha de datos aún no tiene campos.",
    },
    tipos: {
      texto: "Texto",
      textarea: "Párrafo",
      numero: "Número",
      fecha: "Fecha",
      booleano: "Sí / No",
      seleccion: "Selección",
    },
    respuestas: {
      titulo: "Respuestas",
    },
  },
  miCuenta: {
    titulo: "Mi cuenta",
    subtitulo: "Tus créditos, tus reservas y las próximas clases.",
    creditos: "Mis créditos",
    ilimitado: "Ilimitado",
    saldo: "Saldo",
    disponible: "Disponible",
    sinCreditos: "Aún no tienes créditos.",
    misReservas: "Mis reservas",
    sinReservas: "No tienes reservas próximas.",
    cancelar: "Cancelar reserva",
    cancelarCita: "Cancelar cita",
    agenda: "Próximas clases",
    sinClases: "No hay clases programadas.",
    reservar: "Reservar",
    esperar: "Lista de espera",
    listaEspera: "Lista de espera",
    reservado: "Reservado.",
    reservada: "Reservada",
    enListaEspera: "Te anotamos en la lista de espera.",
    cancelado: "Listo: cancelamos tu reserva.",
    plazaAceptada: "Listo: el lugar es tuyo.",
    confirmada: "Confirmada",
    en_espera: "En espera",
    ofrecida: "Plaza ofrecida",
    pendiente_pago: "Pendiente de pago",
    aceptarPlaza: "Aceptar plaza",
    waiversTitulo: "Consentimientos pendientes",
    aceptarWaiver: "Acepto",
    politica:
      "Puedes cancelar hasta {horas} h antes; después puede aplicar penalización.",
    comprar: {
      titulo: "Comprar",
      subtitulo: "Compra un paquete o membresía para reservar tus clases.",
      comprar: "Comprar",
      comprando: "Creando…",
      creada: "Compra creada. Completa el pago para activar tus créditos.",
      // En citas lo que se compra es un bono de sesiones (ADR 0130).
      creadaCitas: "Compra creada. Completa el pago para activar tu bono.",
      destacado: "Recomendado",
      primeraVez: "Para tu primera vez",
      vence: "Si la pagas hoy, se puede usar hasta el {fecha}.",
      masInscripcion: "Más {monto} de inscripción (no da clases).",
      conAnualidad: "Con este plan se cobra también la anualidad del negocio.",
      aceptoCondiciones: "Leí y acepto estas condiciones",
      creditos: "1 crédito | {n} créditos",
      creditosCitas: "1 sesión | {n} sesiones",
      tipos: {
        membresia: "Membresía",
        paquete: "Paquete",
        paqueteCitas: "Bono",
        pase_dia: "Pase del día",
        sesion_individual: "Sesión individual",
        add_on: "Extra",
        taller: "Taller",
      },
    },
    compras: {
      titulo: "Mis compras",
      nota: "Las compras pendientes se activan al completar el pago (en línea o en el negocio).",
      estados: {
        pendiente: "Pendiente de pago",
        pagada: "Pagada",
        cancelada: "Cancelada",
      },
    },
  },
  integraciones: {
    activa: "Activa",
    guardar: "Guardar",
    guardando: "Guardando…",
    guardado: "Guardado.",
    configurada: "configurada",
    nuevaLlave: "Escribe para reemplazar",
    // Lo que pide cada plataforma (te lo da al dar de alta tu gimnasio).
    llaves: {
      wellhub: {
        api_key: "Token de API de Wellhub",
        gym_id: "Gym ID",
        webhook_secret: "Llave secreta de avisos (si Wellhub te dio una)",
      },
      totalpass: {
        api_key: "Llave de API de TotalPass",
        codigo_gimnasio: "Código de tu gimnasio",
        codigo_plan: "Código del plan (si tienes varios)",
        place_api_key: "Llave de API de tu sede (check-in automático)",
      },
    },
    ayuda: {
      wellhub:
        "Valida la visita con el Wellhub ID de 13 dígitos que la persona ve en su app, en los 20 minutos después de su check-in.",
      totalpass:
        "Valida la visita con el token del día que la persona muestra en su app de TotalPass.",
      // Sin API (ADR 0197).
      fitpass:
        "Fitpass no tiene API: valida la visita en tu app de socios de Fitpass y registra aquí su folio. Ocupa su lugar en la clase y se concilia con el reporte de pagos de Fitpass.",
    },
    soloMexico: "Disponible para negocios en México.",
    // Lo que hace y lo que no (M10): validar la visita, no sincronizar todo.
    alcance:
      "Lo que hace: valida el check-in de cada visita, sola cuando la plataforma avisa que la persona lo hizo en su app (check-in automático) o con el código que captura recepción, y la visita ocupa su lugar en la clase. Con Wellhub y TotalPass también publicas tus clases para que sus usuarios las reserven desde su app. Quien viene así es usuario de la plataforma: lo ves en Reportes → Plataformas, no en tu directorio. Aún no sincroniza membresías ni pagos. Si la plataforma no responde, la visita queda «sin verificar»: se reintenta o quien tiene permiso la registra como excepción, con su motivo y quién la autorizó.",
    proveedores: {
      wellhub: "Wellhub",
      totalpass: "TotalPass",
      fitpass: "Fitpass",
    },
    codigoParaWellhub:
      "Al elegir a AgendaUno como tu sistema en Wellhub, da este código: {codigo}. Con él, tu Gym ID se captura solo.",
  },
  checkins: {
    titulo: "Check-ins de bienestar",
    proveedor: "Plataforma",
    codigo: "Código del usuario",
    reservacionFitpass: "ID de la reservación (Fitpass Admin)",
    identificacionRevisada:
      "Revisé su identificación oficial (lo pide Fitpass)",
    registrar: "Registrar check-in",
    registrando: "Validando…",
    vacio: "Sin check-ins de bienestar.",
    ok: "Check-in registrado.",
    validado: "Validado",
    alcance:
      "Valida la visita con la plataforma. No sincroniza reservas, membresías ni pagos.",
    estados: {
      validado: "Validada por {proveedor}",
      no_verificable: "Sin verificar",
      excepcion: "Excepción manual",
      excepcionPor: "Excepción · autorizó {nombre}",
    },
    sinVerificarConPermiso:
      "No cuenta como validada: intenta de nuevo con el mismo código en unos minutos o regístrala como excepción.",
    sinVerificarSinPermiso:
      "No cuenta como validada: intenta de nuevo con el mismo código en unos minutos o pide a quien puede autorizarlo que la registre como excepción.",
    registrarExcepcion: "Registrar como excepción",
    excepcionAyuda:
      "Solo cuando la plataforma no respondió. Queda quién la autorizó y lo que se comprobó; la plataforma puede no reconocerla.",
    motivoExcepcion: "Motivo",
    evidencia: "Qué se comprobó",
    evidenciaEjemplo:
      "Ej.: vi su check-in activo en la app de la persona a las 7:58",
    autorizar: "Autorizar excepción",
  },
  configuracion: {
    titulo: "Configuración",
    logoTitulo: "Logo del negocio",
    logoDesc:
      "Se muestra en la pantalla de acceso de tu negocio. Si no subes uno, se usa el logo de AgendaUno.",
    logoSubir: "Subir logo",
    logoSubiendo: "Subiendo…",
    logoQuitar: "Quitar logo",
    logoArrastra: "Arrastra tu logo aquí o haz clic para elegir",
    logoTipo: "Usa una imagen PNG, JPG o WebP.",
    logoPeso: "La imagen no debe pesar más de 2 MB.",
    logoAyuda: "PNG, JPG o WEBP, hasta 2 MB. Idealmente cuadrado.",
    directorioTitulo: "Aparecer en el directorio público",
    directorioDesc:
      "Si está activo, cualquier persona puede descubrir tu negocio en el directorio. Si lo desactivas, seguirá disponible mediante el enlace directo que compartas.",
    directorioActivo: "Tu negocio aparece en el directorio público.",
    directorioInactivo:
      "Tu negocio no aparece en el directorio; sigue disponible mediante su enlace directo.",
    enlaceDirecto: "Enlace público para compartir",
    copiar: "Copiar",
    copiado: "Copiado",
    guardado: "Guardado.",
  },
};
