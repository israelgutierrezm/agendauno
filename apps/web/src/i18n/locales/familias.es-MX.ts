/**
 * Familias y responsables (ADR 0123): la cuenta del responsable con sus personas a
 * cargo, la ficha del alumno y la configuración del negocio.
 */
export default {
  // Cuenta del responsable.
  cuentaDe: "Cuenta de",
  miCuenta: "Mi cuenta",
  gestionando:
    "Estás viendo la cuenta de {nombre}: sus reservas, planes, pagos y documentos.",
  aCargo: "Personas a tu cargo",
  resumenDe: "Aquí tienes el resumen de {nombre} en {estudio}.",
  estados: {
    pendiente: "Falta la autorización",
    activa: "Autorizada",
    retirada: "Autorización retirada",
  },
  autorizar: "Autorizar",
  verSuCuenta: "Ver su cuenta",
  retirar: "Retirar autorización",
  confirmarRetiro:
    "¿Retirar tu autorización para {nombre}? Ya no podrás ver ni reservar por esa persona; lo que ya está hecho se conserva.",
  retirada: "Retiraste tu autorización para {nombre}.",
  autorizada: "Listo: ya puedes gestionar la cuenta de {nombre}.",
  tituloAutorizacion: "Autorización del responsable",
  introAutorizacion:
    "Para gestionar la cuenta de {nombre}, lee y acepta la autorización del negocio:",
  version: "Versión {n} del texto del negocio.",
  acepto: "Leí el texto y lo acepto.",
  parentescos: {
    madre_padre: "Madre o padre",
    tutor_legal: "Tutor legal",
    familiar: "Otro familiar",
    autorizado: "Persona autorizada",
  },
  // Ficha del alumno (equipo).
  ficha: {
    titulo: "Familia",
    aSuCargo: "A su cargo",
    responsables: "Responsables",
    sinNada: "Sin personas a su cargo ni responsables.",
    agregar: "Poner a alguien a su cargo",
    sinCuenta:
      "Para tener personas a su cargo necesita una cuenta en el negocio: envíale una invitación.",
    participante: "Persona a su cargo",
    parentesco: "Parentesco",
    ayudaAgregar:
      "{nombre} podrá reservar, comprar y ver los documentos de esa persona cuando acepte la autorización del negocio en su cuenta o aquí, en el mostrador.",
    guardar: "Poner a su cargo",
    vinculado: "Listo. Falta la autorización del responsable.",
    registrarMostrador: "Registrar autorización en mostrador",
    confirmarMostrador:
      "Confirma que {nombre} leyó y aceptó este texto en el mostrador:",
    confirmarAceptacion: "Sí, lo aceptó",
    registrada: "Autorización registrada.",
    retirar: "Retirar",
    confirmarRetiro:
      "¿Retirar la autorización de {responsable} para {participante}? Dejará de ver y de reservar por esa persona.",
    autorizadaEl: "Autorizada el {fecha}, {via}",
    via: {
      cuenta: "en su cuenta",
      mostrador: "en el mostrador",
    },
  },
  // Configuración del negocio.
  config: {
    titulo: "Familias",
    texto:
      "Un alumno con cuenta gestiona a personas a su cargo (sus hijos, por ejemplo): reserva, compra y ve sus documentos, con su autorización. Cada persona conserva sus propios planes y créditos.",
    activar: "Usar familias en este negocio",
    textoAutorizacion: "Texto de la autorización",
    ayudaTexto:
      "Es lo que acepta el responsable. Si lo dejas vacío se usa el texto base. Cambiarlo sube la versión; quien ya aceptó conserva el texto que leyó. Revísalo con un profesional legal, sobre todo si atiendes a menores.",
    version: "Versión vigente: {n}.",
    guardar: "Guardar",
    guardado: "Familias guardadas",
  },
};
