// Importar citas futuras de otro sistema (views/ImportarCitasView.vue, ADR 0160).
export default {
  titulo: "Importar citas",
  subtitulo:
    "Trae las citas que ya tenías agendadas en otro sistema. Se revisan antes y no se le avisa nada a tus clientes.",
  volver: "Volver a la agenda",
  errorCatalogos:
    "No se pudieron cargar tus servicios, profesionales y sucursales.",
  preparar: "1. Prepara tu archivo",
  plantilla: "Descargar plantilla",
  reglaFormato:
    "Una cita por fila. La fecha va como AAAA-MM-DD y la hora como HH:MM (24 horas), en la zona horaria de su sucursal. Solo citas futuras.",
  reglaClientes:
    "Cada cliente se reconoce por su número en tu sistema anterior (cliente_ref), su correo o su celular; si no existe, se crea su ficha. Sin ninguno de los tres la fila no se importa: se duplicaría la persona.",
  reglaReferencia:
    "Con la referencia de cada cita, al volver a importar se reconoce: si no cambió, no se duplica; si cambió (otra hora, profesional o sucursal), se avisa para que lo decidas en la agenda.",
  reglaCobro:
    "Las citas importadas quedan por cobrar en caja. Si ya te pagaron algo en tu sistema anterior (un anticipo o todo), ponlo en «pagado»: se descuenta y no se registra como ingreso nuevo.",
  reglaNombres:
    "Servicio, profesional y sucursal van con su nombre. Si en tu sistema anterior se llamaban distinto, aquí eliges a cuál corresponde.",
  reglaTodo:
    "Se importa todo o nada: si una fila no se puede (un horario encimado, un dato que falta), no se importa ninguna. Una cita que ya estaba no se duplica.",
  subir: "2. Súbelo y revisa",
  limite: "Hasta {n} citas por archivo.",
  recordatorios: "Mandarles su recordatorio antes de cada cita",
  recordatoriosAyuda:
    "Apagado, no se les avisa nada: ni al importar ni antes de la cita. Encendido, reciben el recordatorio de siempre.",
  elegir: "Elegir archivo",
  analizando: "Revisando el archivo…",
  correspondencias: "Correspondencias",
  correspondenciasAyuda:
    "Estos nombres no coinciden con tu catálogo. Elige a cuál corresponde cada uno; vale para todas sus filas.",
  campos: {
    servicio: "Servicio",
    profesional: "Profesional",
    sucursal: "Sucursal",
  },
  revisar: "Revisar de nuevo",
  resultado: "3. Vista previa",
  // Lo que queda por cobrar al importar (R14).
  porCobrar:
    "1 cita quedará por cobrar ({monto}). | {n} citas quedarán por cobrar ({monto} en total).",
  pagadasAntes:
    "1 ya estaba pagada en tu sistema anterior: no queda nada por cobrar. | {n} ya estaban pagadas en tu sistema anterior: no queda nada por cobrar.",
  porCobrarAyuda:
    "Lo que ya te pagaron allá no se registra aquí como ingreso. Si falta indicarlo, agrégalo en la columna «pagado» antes de importar.",
  indicadores: {
    filas: "Filas",
    nuevos: "Clientes nuevos",
    errores: "Con errores",
    omitidas: "Ya estaban",
  },
  corrige:
    "Corrige las filas marcadas en tu archivo (o elige sus correspondencias) y vuelve a revisarlo.",
  fila: "Fila",
  cliente: "Cliente",
  cita: "Cita",
  revision: "Revisión",
  fichaNueva: "Se crea su ficha",
  fichaExistente: "Ya tiene ficha",
  lista: "Lista",
  omitida: "Ya estaba",
  importar: "Importar 1 cita | Importar {n} citas",
  cambios:
    "Algo cambió desde la vista previa: revisa las filas marcadas antes de importar.",
  creadas: "Se importó 1 cita | Se importaron {n} citas",
  sinAvisos: "No se le avisó nada a tus clientes.",
  conRecordatorios: "Recibirán su recordatorio antes de su cita.",
  otra: "Importar otro archivo",
};
