import { puedeEntrar } from "@/lib/acceso";
import type {
  ModalidadServicio,
  useSesionTenantStore,
} from "@/stores/sesionTenant";

/**
 * Cómo se organiza la navegación (presentación). Quién puede entrar a cada pantalla
 * NO se decide aquí: es `lib/acceso.ts`, que también usa el router.
 *
 * - El lateral tiene grupos (títulos discretos, sin desplegar) con áreas: un enlace
 *   directo por área que abre su primera vista permitida.
 * - Cada área muestra sus vistas en pestañas arriba de la pantalla.
 * - Configuración del negocio tiene un tercer nivel: categorías con sus opciones,
 *   en una navegación secundaria (`CATEGORIAS_CONFIGURACION`).
 */
type Sesion = ReturnType<typeof useSesionTenantStore>;

export interface Vista {
  clave: string;
  ruta: string;
  etiqueta: string; // clave i18n
  icono?: string;
  query?: Record<string, string>;
  hash?: string;
  // La etiqueta es el plural del término del negocio (p. ej. Barberos).
  termino?: "miembro" | "instructor";
  // Solo en esta modalidad (además de la regla de acceso de su ruta).
  modalidad?: ModalidadServicio;
  // Permisos que pide esta vista además de los de su ruta (todos).
  permiso?: string | string[];
}

export interface Area {
  clave: string;
  etiqueta: string; // clave i18n
  icono: string;
  // La etiqueta es el plural del término del negocio (Alumnos, Clientes…).
  termino?: "miembro" | "instructor";
  vistas: Vista[];
}

export interface GrupoMenu {
  clave: string;
  etiqueta: string; // clave i18n
  areas: Area[];
  // Portal del alumno o de quien imparte: su contexto, no el del dueño.
  portal?: boolean;
}

export interface OpcionConfiguracion extends Vista {
  // Para encontrarla en «Buscar ajuste» (además de su nombre y su categoría).
  sinonimos: string[];
}

export interface CategoriaConfiguracion {
  clave: string;
  etiqueta: string;
  descripcion: string;
  icono: string;
  opciones: OpcionConfiguracion[];
}

const v = (
  clave: string,
  ruta: string,
  etiqueta: string,
  extra: Partial<Vista> = {},
): Vista => ({ clave, ruta, etiqueta, ...extra });

const o = (
  clave: string,
  ruta: string,
  etiqueta: string,
  sinonimos: string[],
  extra: Partial<Vista> = {},
): OpcionConfiguracion => ({ clave, ruta, etiqueta, sinonimos, ...extra });

/** Configuración del negocio: categoría → opción (la pantalla que trabaja). */
export const CATEGORIAS_CONFIGURACION: CategoriaConfiguracion[] = [
  {
    clave: "negocio",
    etiqueta: "configNegocio.categorias.negocio",
    descripcion: "configNegocio.descripciones.negocio",
    icono: "comercio",
    opciones: [
      o(
        "datos",
        "configuracion",
        "configNegocio.opciones.datos",
        ["logo", "logotipo", "nombre", "imagen", "marca", "contacto"],
        { hash: "#datos" },
      ),
      o(
        "pagina",
        "configuracion",
        "configNegocio.opciones.pagina",
        ["página pública", "portada", "directorio", "redes", "enlace"],
        { hash: "#pagina-publica" },
      ),
      o("sedes", "sedes", "configNegocio.opciones.sucursales", [
        "sede",
        "sucursal",
        "dirección",
        "horario",
        "ubicación",
      ]),
      o("fiscales", "datos-fiscales", "configNegocio.opciones.fiscales", [
        "rfc",
        "factura",
        "fiscal",
        "cfdi",
      ]),
      o(
        "terminologia",
        "configuracion",
        "configNegocio.opciones.terminologia",
        ["nombres", "alumnos", "clientes", "pacientes", "instructores"],
        { hash: "#terminologia" },
      ),
    ],
  },
  {
    clave: "servicios",
    etiqueta: "configNegocio.categorias.servicios",
    descripcion: "configNegocio.descripciones.servicios",
    icono: "etiqueta",
    opciones: [
      o("catalogo", "catalogo", "planes.nav.clasesServicios", [
        "servicios",
        "clases",
        "precio",
        "duración",
        "catálogo",
      ]),
      o("planes", "planes", "configNegocio.opciones.planes", [
        "membresía",
        "paquete",
        "plan",
        "créditos",
        "vigencia",
      ]),
      o("recursos", "recursos", "configNegocio.opciones.recursos", [
        "sala",
        "salón",
        "equipo",
        "espacio",
        "recurso",
      ]),
    ],
  },
  {
    clave: "agenda",
    etiqueta: "configNegocio.categorias.agenda",
    descripcion: "configNegocio.descripciones.agenda",
    icono: "agenda",
    opciones: [
      o(
        "politicas",
        "reglas-agenda",
        "configNegocio.opciones.politicas",
        ["cancelación", "tolerancia", "inasistencia", "ventana", "reserva"],
        { hash: "#politicas" },
      ),
      o(
        "cierres",
        "reglas-agenda",
        "configNegocio.opciones.cierres",
        ["cerrado", "días festivos", "vacaciones", "cierre"],
        { hash: "#cierres" },
      ),
      o(
        "programacion",
        "reglas-agenda",
        "configNegocio.opciones.programacion",
        ["series", "recurrente", "generar clases", "plantilla"],
        { hash: "#programacion", modalidad: "clases" },
      ),
      // Límites y tiempos del negocio (ADR 0042): pagos, recordatorios, cobros en caja…
      o(
        "parametros",
        "reglas-agenda",
        "configNegocio.opciones.parametros",
        [
          "límites",
          "tiempos",
          "parámetros",
          "recordatorios",
          "cobros en caja",
          "forma de pago",
          "corregir pago",
        ],
        { hash: "#parametros", permiso: "estudio.gestionar" },
      ),
    ],
  },
  {
    clave: "pagos",
    etiqueta: "configNegocio.categorias.pagos",
    descripcion: "configNegocio.descripciones.pagos",
    icono: "pasarelas",
    opciones: [
      o("pasarelas", "pasarelas", "configNegocio.opciones.pasarelas", [
        "conectar pagos",
        "stripe",
        "mercado pago",
        "openpay",
        "cobro en línea",
      ]),
      o("integraciones", "integraciones", "nav.integraciones", [
        "api",
        "llaves",
        "webhooks",
        "conexiones",
      ]),
    ],
  },
  {
    clave: "accesos",
    etiqueta: "configNegocio.categorias.accesos",
    descripcion: "configNegocio.descripciones.accesos",
    icono: "usuarios",
    opciones: [
      o("usuarios", "usuarios", "configNegocio.opciones.usuarios", [
        "invitar",
        "accesos",
        "cuentas",
        "usuarios",
      ]),
      o("roles", "roles", "operacion.rolesPropios.titulo", [
        "roles",
        "permisos",
        "perfiles",
      ]),
    ],
  },
  {
    clave: "documentos",
    etiqueta: "configNegocio.categorias.documentos",
    descripcion: "configNegocio.descripciones.documentos",
    icono: "documentos",
    opciones: [
      o(
        "requisitos",
        "documentos",
        "configNegocio.opciones.requisitos",
        ["documentos requeridos", "requisitos", "identificación"],
        { query: { vista: "requisitos" } },
      ),
      o(
        "consentimientos",
        "documentos",
        "configNegocio.opciones.consentimientos",
        ["consentimiento", "deslinde", "waiver", "firma"],
        {
          query: { vista: "consentimientos" },
          permiso: "documentos.gestionar",
        },
      ),
      o("formularios", "formularios", "nav.formularios", [
        "ficha de datos",
        "formulario",
        "campos",
        "encuesta",
        "cuestionario",
      ]),
      o("privacidad", "privacidad", "privacidadNegocio.titulo", [
        "arco",
        "datos personales",
        "baja de datos",
      ]),
      o("bitacora", "bitacora", "bitacora.titulo", [
        "auditoría",
        "registro",
        "actividad",
        "historial",
      ]),
    ],
  },
];

/** Menú del panel del negocio: tres grupos y sus áreas. */
export const MENU_NEGOCIO: GrupoMenu[] = [
  {
    clave: "operacion",
    etiqueta: "nav.grupos.operacionDiaria",
    areas: [
      {
        clave: "inicio",
        etiqueta: "nav.areas.inicio",
        icono: "panel",
        vistas: [
          v("resumen", "panel", "nav.vistas.resumen"),
          v("tareas", "tareas", "nav.tareas"),
        ],
      },
      {
        clave: "agenda",
        etiqueta: "nav.agenda",
        icono: "agenda",
        vistas: [
          v("calendario", "agenda", "nav.vistas.calendario"),
          v("recepcion", "recepcion", "nav.recepcion"),
          v("grupos", "grupos", "nav.cursos"),
          v("oportunidades", "oportunidades", "nav.vistas.lugares"),
        ],
      },
      {
        clave: "clientes",
        etiqueta: "nav.miembros",
        icono: "personas",
        termino: "miembro",
        vistas: [
          v("directorio", "miembros", "operacion.menu.directorio"),
          v("renovaciones", "retencion", "operacion.renovaciones.titulo"),
          v("expedientes", "documentos", "nav.vistas.expedientes"),
          v("respuestas", "formularios", "nav.vistas.respuestas", {
            query: { vista: "respuestas" },
          }),
        ],
      },
      {
        clave: "cobros",
        etiqueta: "planes.nav.cobros",
        icono: "facturas",
        vistas: [
          v("por-cobrar", "cobranza", "nav.vistas.porCobrar"),
          v("movimientos", "cobranza", "nav.vistas.movimientos", {
            query: { vista: "movimientos" },
          }),
          v("conciliacion", "cobranza", "nav.vistas.conciliacion", {
            query: { vista: "conciliacion" },
          }),
          v("caja", "cobranza", "nav.vistas.caja", {
            query: { vista: "caja" },
          }),
          v("facturas", "facturas", "nav.facturas"),
        ],
      },
      {
        clave: "equipo",
        etiqueta: "operacion.menu.equipo",
        icono: "instructores",
        vistas: [
          v("personas", "instructores", "nav.instructores", {
            termino: "instructor",
          }),
          v("disponibilidad", "horarios", "nav.vistas.disponibilidad"),
          v("nomina", "nomina", "nav.nomina"),
        ],
      },
    ],
  },
  {
    clave: "gestion",
    etiqueta: "nav.grupos.gestion",
    areas: [
      {
        clave: "ventas",
        etiqueta: "nav.grupos.ventas",
        icono: "ventas",
        vistas: [
          v("membresias", "ventas", "nav.vistas.membresias"),
          v("mostrador", "pos", "planes.nav.mostrador"),
          v("inventario", "inventario", "planes.nav.inventario"),
        ],
      },
      {
        clave: "marketing",
        etiqueta: "operacion.menu.marketing",
        icono: "promociones",
        vistas: [
          v("comunicacion", "comunicaciones", "nav.comunicaciones"),
          v("promociones", "promociones", "nav.promociones"),
          v("lealtad", "lealtad", "nav.lealtad"),
          v("resenas", "resenas", "resenas.titulo"),
        ],
      },
      {
        clave: "reportes",
        etiqueta: "nav.reportes",
        icono: "reportes",
        vistas: [v("reportes", "reportes", "nav.reportes")],
      },
    ],
  },
  {
    clave: "configuracion",
    etiqueta: "nav.grupos.configuracion",
    areas: [
      {
        clave: "configuracion",
        etiqueta: "nav.areas.configuracion",
        icono: "ajustes",
        // La portada primero; las opciones viven en sus categorías.
        vistas: [
          v("portada", "ajustes", "nav.areas.configuracion"),
          ...CATEGORIAS_CONFIGURACION.flatMap((c) => c.opciones),
        ],
      },
      {
        clave: "suscripcion",
        etiqueta: "nav.grupos.suscripcion",
        icono: "renta",
        vistas: [
          v("pagos", "renta", "nav.vistas.suscripcion"),
          v("uso", "padron", "nav.vistas.usoFacturable"),
        ],
      },
    ],
  },
];

/** Portal del alumno o cliente y de quien imparte: su propio contexto. */
export const MENU_PORTALES: GrupoMenu[] = [
  {
    clave: "mi-cuenta",
    etiqueta: "nav.miCuenta",
    portal: true,
    areas: [
      ["mi-cuenta", "portal.nav.inicio", "panel"],
      ["mis-reservas", "portal.nav.reservas", "agenda"],
      ["mis-pagos", "portal.nav.pagos", "ventas"],
      ["mi-expediente", "portal.nav.expediente", "expediente"],
      ["mi-configuracion", "portal.nav.configuracion", "configuracion"],
    ].map(([ruta, etiqueta, icono]) => ({
      clave: ruta,
      etiqueta,
      icono,
      vistas: [v(ruta, ruta, etiqueta)],
    })),
  },
  {
    clave: "instructor",
    etiqueta: "portal.instructor.nav.grupo",
    portal: true,
    areas: [
      ["inicio-instructor", "portal.instructor.nav.inicio", "panel"],
      ["mis-clases", "portal.instructor.nav.calendario", "cuadricula"],
    ].map(([ruta, etiqueta, icono]) => ({
      clave: ruta,
      etiqueta,
      icono,
      vistas: [v(ruta, ruta, etiqueta)],
    })),
  },
];

export const MENU: GrupoMenu[] = [...MENU_PORTALES, ...MENU_NEGOCIO];

/** Las vistas de un área que esta sesión puede abrir, en su orden. */
export function vistasVisibles(area: Area, sesion: Sesion): Vista[] {
  return area.vistas.filter(
    (x) =>
      (x.modalidad === undefined || x.modalidad === sesion.modalidad) &&
      (x.permiso === undefined ||
        (Array.isArray(x.permiso) ? x.permiso : [x.permiso]).every((p) =>
          sesion.puede(p),
        )) &&
      puedeEntrar(x.ruta, sesion),
  );
}

/**
 * El menú de esta sesión: solo áreas con al menos una vista permitida y solo grupos
 * con al menos un área. Nombres y orden son los mismos para todos los roles.
 */
export function menuVisible(sesion: Sesion): (GrupoMenu & {
  areas: (Area & { destino: Vista })[];
})[] {
  return MENU.map((g) => ({
    ...g,
    areas: g.areas.flatMap((a) => {
      const vistas = vistasVisibles(a, sesion);
      return vistas.length > 0 ? [{ ...a, destino: vistas[0] }] : [];
    }),
  })).filter((g) => g.areas.length > 0);
}

/** Todas las rutas del menú (cada pantalla con su destino principal). */
export function rutasDelMenu(): string[] {
  return [
    ...new Set(
      MENU.flatMap((g) => g.areas.flatMap((a) => a.vistas)).map((x) => x.ruta),
    ),
  ];
}

/** Pantallas fuera del menú y el área que las contiene (para marcarla activa). */
const AREA_DE_AUXILIAR: Record<string, { area: string; vista: string }> = {
  "ficha-miembro": { area: "clientes", vista: "directorio" },
  importar: { area: "clientes", vista: "directorio" },
  "ficha-instructor": { area: "equipo", vista: "personas" },
  "importar-instructores": { area: "equipo", vista: "personas" },
  onboarding: { area: "inicio", vista: "resumen" },
};

interface RutaActual {
  name: unknown;
  query?: Record<string, unknown>;
  hash?: string;
}

export interface Ubicacion {
  grupo: GrupoMenu;
  area: Area;
  vista: Vista;
  categoria: CategoriaConfiguracion | null;
}

/** ¿La vista corresponde a la ruta (nombre, su `vista` y, si la pide, su ancla)? */
function coincide(x: Vista, ruta: RutaActual, conAncla: boolean): boolean {
  if (x.ruta !== String(ruta.name)) {
    return false;
  }
  const pedida = typeof ruta.query?.vista === "string" ? ruta.query.vista : "";
  if ((x.query?.vista ?? "") !== pedida) {
    return false;
  }
  return !conAncla || (x.hash ?? "") === (ruta.hash ?? "");
}

/**
 * Dónde está la pantalla actual: su grupo, su área, su vista y, en Configuración,
 * su categoría. Un solo mecanismo para marcar lo activo (lateral, pestañas,
 * configuración), por nombre de ruta y su `vista`, no por el texto de la URL.
 */
export function ubicacion(ruta: RutaActual): Ubicacion | null {
  const auxiliar = AREA_DE_AUXILIAR[String(ruta.name)];
  for (const grupo of MENU) {
    for (const area of grupo.areas) {
      const vista = auxiliar
        ? area.clave === auxiliar.area
          ? area.vistas.find((x) => x.clave === auxiliar.vista)
          : undefined
        : (area.vistas.find((x) => coincide(x, ruta, true)) ??
          area.vistas.find((x) => coincide(x, ruta, false)));
      if (vista !== undefined) {
        const categoria =
          area.clave === "configuracion"
            ? (CATEGORIAS_CONFIGURACION.find((c) =>
                c.opciones.some((op) => op.clave === vista.clave),
              ) ?? null)
            : null;
        return { grupo, area, vista, categoria };
      }
    }
  }
  return null;
}

/** El destino de una vista para un RouterLink. */
export function destinoDe(x: Vista): {
  name: string;
  query?: Record<string, string>;
  hash?: string;
} {
  return {
    name: x.ruta,
    ...(x.query ? { query: x.query } : {}),
    ...(x.hash ? { hash: x.hash } : {}),
  };
}

/**
 * ¿El texto tiene la búsqueda al inicio de alguna de sus palabras? («logo» encuentra
 * «logotipo», no «catálogos»). Sin acentos ni mayúsculas.
 */
export function coincideBusqueda(texto: string, busqueda: string): boolean {
  const q = normalizar(busqueda.trim());
  if (q === "") {
    return false;
  }
  const escapada = q.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return new RegExp(`(^|[\\s/(·-])${escapada}`).test(normalizar(texto));
}

/** Normaliza para buscar: minúsculas y sin acentos. */
export function normalizar(texto: string): string {
  return texto.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
}
