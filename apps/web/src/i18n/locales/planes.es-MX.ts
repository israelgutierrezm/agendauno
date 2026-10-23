/**
 * Planes y paquetes (ADR 0050): qué vende el negocio para reservar (paquetes de
 * clases, membresías, clase suelta, clases extra), su vigencia y a qué clases
 * aplican; el menú de Membresías, Punto de venta y Cobros; y el corte del alumno.
 * "Clase" y "alumno" se adaptan solos a la terminología del negocio (ADR 0049).
 */
export default {
  nav: {
    membresias: "Membresías",
    planes: "Planes y paquetes",
    vender: "Ventas",
    puntoVenta: "Punto de venta",
    mostrador: "Mostrador",
    inventario: "Inventario",
    cobros: "Cobros",
    clasesServicios: "Servicios",
    clasesServiciosClases: "Clases",
  },
  titulo: "Planes y paquetes",
  // Planes «socio» (ADR 0182): se congelan en cada venta.
  socio: {
    titulo: "Reglas de reserva",
    ayuda:
      "Opcional. Lo que pongas aquí se congela en cada venta: cambiarlo después no afecta lo ya vendido.",
    topeDia: "Máximo de reservas por día",
    topeSemana: "Máximo de reservas por semana",
    sinTope: "Sin tope",
    anticipado: "Reserva antes que los demás (horas)",
    sinAnticipado: "Igual que todos",
    invitados: "Invitados al mes",
    sinInvitados: "Sin invitados",
  },
  // Avisos de coherencia del catálogo (antes de abrir).
  coherencia: {
    titulo: "Revisa tu catálogo",
    ayuda:
      "No impide vender: son detalles que tus alumnos notan al comparar planes.",
  },
  subtitulo:
    "Lo que tus alumnos compran para reservar: cuántas clases incluye, cuánto dura y para qué clases sirve.",
  nuevo: "Nuevo plan",
  vacio: "Aún no tienes planes. Crea el primero.",
  editar: "Editar",
  archivado: "Archivado",
  verArchivados: "Ver archivados",
  tipos: {
    paquete: "Paquete de clases",
    membresia: "Membresía",
    sesion_individual: "Clase suelta",
    add_on: "Clases extra",
    pase_dia: "Pase de día",
    taller: "Taller",
  },
  // Con citas: bonos de sesiones; sin membresías (ADR 0091, 0130).
  tiposCitas: {
    paquete: "Bono de sesiones",
    membresia: "Membresía",
  },
  tiposAyudaCitas: {
    paquete: "Varias visitas prepagadas (p. ej. 5 masajes).",
    membresia: "Una cuota que se renueva con servicios o beneficios.",
  },
  editorCitas: {
    nombrePh: "Bono 5 masajes",
    clases: "Número de sesiones",
    clasesPorMes: "Sesiones al mes",
    aplicaA: "¿Para qué servicios sirve?",
    todas: "Todos los servicios con bono",
    algunas: "Solo algunos",
    eligeClases: "Elige al menos un servicio.",
    sinClases: "Aún no hay servicios que se tomen con bono.",
    servicioOCombo:
      "Un servicio suelto (un corte) o un combo (corte y barba en la misma visita) se dan de alta en",
    irCatalogo: "Catálogo",
    sinServiciosConBono:
      "Para usar un bono, el servicio debe tomarse «con bono». Se cambia en",
    descripcionPh: "Cinco masajes de 60 minutos, para usar en tres meses.",
    primeraVez: "Es para la primera visita",
    primeraVezAyuda:
      "Solo lo compra, una vez, quien nunca ha venido: sin planes antes y sin visitas registradas.",
  },
  tiposAyuda: {
    paquete: "Un número de clases por un precio (p. ej. 8 clases).",
    membresia: "Se renueva cada mes: ilimitada o con clases por mes.",
    sesion_individual: "Una sola clase, para quien no tiene paquete.",
    add_on: "Se suman al paquete vigente del alumno y vencen con él.",
    otro: "Pase de día o taller.",
  },
  secciones: {
    paquete: "Paquetes de clases",
    membresia: "Membresías",
    sesion_individual: "Clase suelta",
    add_on: "Clases extra",
    otro: "Otros",
  },
  resumen: {
    clases: "1 clase | {n} clases",
    ilimitado: "Ilimitado",
    porMes: "1 clase al mes | {n} clases al mes",
    sinVencimiento: "No vence",
    dias: "Vence 1 día después de la compra | Vence {n} días después de la compra",
    meses:
      "Vence 1 mes después de la compra | Vence {n} meses después de la compra",
    finDeMes:
      "Vence al terminar el mes de compra | Vence al terminar el mes {n} (contando el de compra)",
    conElPaquete: "Vencen con el paquete",
    todas: "Todas las clases",
    soloAlgunas: "Solo: {clases}",
  },
  editor: {
    guardadoNombre: "«{nombre}» quedó guardado.",
    tituloNuevo: "Nuevo plan",
    tituloEditar: "Editar plan",
    queEs: "¿Qué es?",
    otro: "Otro",
    nombre: "Nombre",
    nombrePh: "Paquete 8 clases",
    precio: "Precio",
    clases: "Número de clases",
    clasesExtra: "Clases que se suman",
    unaClase: "Incluye una clase.",
    ilimitada: "Ilimitada",
    clasesPorMes: "Clases al mes",
    ciclo: "El mes se cuenta",
    cicloCalendario: "Del 1 al último día del mes",
    cicloAniversario: "Desde el día de compra",
    vigencia: "¿Cuánto dura?",
    vigencias: {
      ninguna: "No vence",
      dias: "Días desde la compra",
      meses: "Meses desde la compra",
      fin_de_mes: "Hasta fin de mes",
    },
    cantidadDias: "Días",
    cantidadMeses: "Meses",
    cantidadFinDeMes: "¿Qué mes?",
    finDeEsteMes: "El mes de compra",
    finDelSiguiente: "El mes siguiente",
    finDeMasMeses: "{n} meses (contando el de compra)",
    ejemplo: "Si se compra hoy ({hoy}), se puede usar hasta el {hasta}.",
    extraVence:
      "Se suman al paquete vigente del alumno: sirven para las mismas clases y vencen el mismo día.",
    aplicaA: "¿Para qué clases sirve?",
    todas: "Todas las clases",
    algunas: "Solo algunas",
    eligeClases: "Elige al menos una clase.",
    sinClases: "Aún no hay clases en el catálogo.",
    // <summary> de `tu-avanzado`; con alguna configurada, cuántas.
    avanzado: "Más condiciones",
    avanzadoActivas:
      "Más condiciones · {n} activa | Más condiciones · {n} activas",
    // Dónde se puede usar (ADR 0017): todas las sucursales o las marcadas.
    cobertura: "Dónde se puede usar",
    coberturaAlgunas: "Una o varias sucursales",
    coberturaTodasAyuda:
      "Incluye las sucursales actuales y las que abras después.",
    coberturaAlgunasAyuda:
      "Solo las sucursales marcadas; las que abras después no se agregan solas.",
    coberturaCompras: "Los cambios aplican a las compras nuevas.",
    acumula: "Las clases que no use en el mes",
    acumulaNinguno: "Se pierden",
    acumulaTodo: "Pasan al mes siguiente",
    acumulaLimitado: "Pasan, hasta un máximo",
    acumulaMax: "Máximo de clases que pasan",
    archivar: "Archivado (ya no se vende; lo vendido sigue igual)",
    confirmarArchivar:
      "¿Archivar «{nombre}»? Ya no se vende; quien lo tiene lo conserva igual.",
    archivarAceptar: "Archivar el plan",
    guardar: "Guardar",
    guardando: "Guardando…",
    cancelar: "Cancelar",
    soloFuturo:
      "Los cambios aplican a las ventas nuevas; lo ya vendido no cambia.",
    descripcion: "Qué incluye, en una línea",
    descripcionPh: "8 clases para usar en un mes, en cualquier horario.",
    enTuPagina: "En tu página",
    destacado: "Destacarlo",
    destacadoAyuda:
      "Se muestra primero y marcado como recomendado en tu página y en la app.",
    primeraVez: "Es una clase de prueba",
    primeraVezAyuda:
      "Solo la compra, una vez, quien nunca ha venido: sin planes antes y sin asistencias registradas.",
  },
  // Antes de guardar un plan, lo que quedará en una sola vista (en el pie del editor).
  // Se usa con llave dinámica (`planes.antesDeGuardar.${clave}`).
  antesDeGuardar: {
    titulo: "Así quedará",
    ayuda:
      "Revisa el precio, dónde se usa, para qué sirve y sus condiciones antes de guardar.",
    precio: "Precio",
    sinPrecio: "Falta el precio",
    sucursales: "Sucursales",
    todasSucursales: "Todas las sucursales",
    comoElPaquete: "Las del paquete al que se suma",
    ningunaSucursal: "Elige al menos una sucursal",
    sirvePara: "Sirve para",
    mismasDelPaquete: "Lo mismo que el paquete al que se suma",
    incluye: "Incluye",
    sumaClases: "Suma 1 clase al paquete | Suma {n} clases al paquete",
    vigencia: "Vigencia",
    seRenueva: "Se renueva cada mes mientras se pague",
    condiciones: "Condiciones",
    sinCondiciones: "Sin condiciones especiales",
    primeraVez: "Solo para quien nunca ha venido",
    permanencia: "Permanencia de 1 mes | Permanencia de {n} meses",
    inscripcion: "Cobra inscripción",
    anualidad: "Cobra anualidad",
    destacado: "Destacado en tu página",
    archivado: "Archivado: ya no se vende",
    // Las condiciones avanzadas que se configuraron.
    topeDia: "Máximo 1 reserva al día | Máximo {n} reservas al día",
    topeSemana:
      "Máximo 1 reserva a la semana | Máximo {n} reservas a la semana",
    anticipado:
      "Reserva 1 hora antes que los demás | Reserva {n} horas antes que los demás",
    invitados: "1 invitado al mes | {n} invitados al mes",
    acumula: "Acumula lo no usado",
    acumulaHasta: "Acumula hasta 1 clase | Acumula hasta {n} clases",
  },
  // Permanencia de una membresía (B04).
  permanencia: {
    titulo: "Permanencia",
    meses: "Permanencia mínima (meses)",
    sin: "Sin permanencia",
    siDaDeBaja: "Si da de baja antes de cumplirla:",
    modos: {
      sin_penalizacion: "Sin penalización",
      monto_fijo: "Paga un monto fijo",
      meses_restantes: "Paga las mensualidades que falten",
    },
    monto: "Monto de la penalización",
    // Qué parte de las mensualidades que falten (p. ej. 50 %); 100 = todas.
    porcentaje: "Porcentaje de lo que falte (%)",
    porcentajeAyuda:
      "Ej.: con 50 paga la mitad de las mensualidades que falten. Con 100, todas.",
    ayuda:
      "Quien lo compra acepta estas condiciones antes de pagar; lo pactado no cambia si editas el plan después. La baja aplica al terminar el periodo pagado. La redacción legal de tu contrato la debe revisar un profesional.",
  },
  // Cuotas del negocio (B04): inscripción y anualidad, aparte de los planes.
  cuotas: {
    guardadas: "Cuotas guardadas.",
    titulo: "Cuotas",
    ayuda:
      "Lo que se cobra aparte de los planes. No da clases. Un monto vacío o en 0 no se cobra.",
    inscripcion: "Inscripción",
    anualidad: "Anualidad",
    monto: "Monto ({simbolo})",
    sinCobro: "No se cobra",
    reingreso: "Se vuelve a cobrar tras (meses sin plan)",
    inscripcionAyuda:
      "Se suma al vender un plan que la cobra, en la primera alta y al regresar tras esos meses sin plan. Quien tiene permiso puede exentarla.",
    modos: {
      aniversario: "En el aniversario de su alta",
      fecha_fija: "En una fecha fija",
    },
    mes: "Mes",
    dia: "Día",
    aniversarioAyuda:
      "Cada año en la fecha en que contrató; quien entró un 29 de febrero paga el 28 en los años que no son bisiestos.",
    fechaFijaAyuda:
      "La misma fecha para todos los que tienen un plan que la cobra. El 29 de febrero se cobra el 28 en los años que no son bisiestos.",
    enElPlan: "Cuotas",
    cobraInscripcion: "Cobra la inscripción",
    cobraInscripcionAyuda:
      "Al venderlo se suma la inscripción si es su primera alta o regresa tras un tiempo sin plan.",
    cobraAnualidad: "Cobra la anualidad",
    cobraAnualidadAyuda: "Quien lo tiene paga la anualidad del negocio.",
  },
  vender: {
    configurar: "Configurar planes y paquetes →",
    venceHoy: "Si se vende hoy, se puede usar hasta el {hasta}.",
    extraAyuda: "Se suman al paquete vigente del alumno y vencen con él.",
  },
  corte: {
    titulo: "Mis planes",
    tituloEquipo: "Planes",
    vacio: "Aún no tienes planes.",
    estados: {
      vigente: "Vigente",
      por_empezar: "Por empezar",
      vencido: "Vencido",
      agotado: "Sin clases",
      pausado: "En pausa",
      suspendido: "Suspendido",
      cancelado: "Cancelado",
    },
    comprado: "Comprado el {fecha}",
    vigencia: "Del {desde} al {hasta}",
    sinVencimiento: "Desde el {desde}, no vence",
    // Membresía con permanencia o dada de baja (B04).
    permanencia: "Permanencia hasta el {fecha}",
    bajaProgramada: "Dada de baja: termina el {fecha}",
    condiciones: "Condiciones aceptadas",
    aplicaA: "Sirve para: {clases}",
    numeros: {
      incluidas: "Incluidas",
      extras: "Extras",
      usadas: "Usadas",
      apartadas: "Reservadas",
      disponibles: "Disponibles",
      // Lo que quedó sin usar en un plan que ya no está vigente.
      sinUsarVencido: "Sin usar al vencer",
      sinUsarCancelado: "Sin usar al cancelar",
      vencidas: "Vencidas",
      devueltas: "Devueltas",
    },
    extras: "Clases extra",
    extra: "{producto} · {fecha} · {n} de {total} usadas",
    usos: "Cómo lo usaste",
    usosEquipo: "Cómo lo usó",
    sinUsos: "Aún no se ha usado.",
    verMasUsos: "Ver 1 más | Ver {n} más",
    usoEstados: {
      asistio: "Asistió",
      no_asistio: "No asistió",
      cancelacion_tardia: "Cancelación tardía",
      proxima: "Próxima",
      tomada: "Tomada",
    },
    deExtra: "extra",
    verDetalle: "Ver detalle",
    anteriores: "Planes anteriores",
    // Los anteriores, plegados: una línea por plan.
    verAnteriores: "Ver planes anteriores ({n})",
    ocultarAnteriores: "Ocultar planes anteriores",
    plegar: "Plegar",
    sinVigentes: "Sin plan vigente.",
    resumenUso: "Usaste {usadas} de {total}",
    resumenIlimitado: "{usadas} usadas",
    aplicaAMas: "Sirve para: {clases} y {n} más",
  },
};
