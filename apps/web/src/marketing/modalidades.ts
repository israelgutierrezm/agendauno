import { soluciones, type Solucion } from "./soluciones.ts";

/*
| Las dos modalidades del producto en la parte comercial: /clases y /citas (ADR 0104:
| cada negocio es solo de clases o solo de citas). Lo importa `vite.config` (por
| seoConfig): sin alias `@/`, sin vue-i18n ni el i18n del proyecto. Aquí va lo que el
| SEO, las rutas y el registro necesitan en texto plano; el cuerpo de cada página va en
| `landing.clases.*` y `landing.citas.*` (i18n), con las claves que se listan abajo.
*/

/** Cómo atiende el negocio: clases con cupo o citas 1 a 1 con un profesional. */
export type Modo = "clases" | "citas";

export const MODOS: readonly Modo[] = ["clases", "citas"];

export function esModo(valor: unknown): valor is Modo {
  return valor === "clases" || valor === "citas";
}

/**
 * El modo de un `?modo=` (o de cualquier valor que llegue de afuera), normalizado:
 * sin espacios y en minúsculas. `null` si no es una modalidad. Con el parámetro
 * repetido, vale el primero.
 */
export function modoDeQuery(valor: unknown): Modo | null {
  const crudo = Array.isArray(valor) ? valor[0] : valor;
  if (typeof crudo !== "string") {
    return null;
  }
  const modo = crudo.trim().toLowerCase();
  return esModo(modo) ? modo : null;
}

/** Nombre de la ruta de cada modalidad, el mismo en el router y en el prerender. */
export const NOMBRE_RUTA_MODALIDAD = {
  clases: "modalidad-clases",
  citas: "modalidad-citas",
} as const satisfies Record<Modo, string>;

export function rutaModalidad(modo: Modo): `/${Modo}` {
  return `/${modo}`;
}

/**
 * Nombre de la modalidad: el mismo en la portada, los precios y el registro
 * (`modalidadNegocio.nombres` en i18n dice lo mismo; una prueba lo vigila).
 */
export const NOMBRE_MODALIDAD: Record<Modo, string> = {
  clases: "Clases con cupo",
  citas: "Citas 1 a 1",
};

/** Lo que dice el menú comercial y la miga de pan de cada modalidad. */
export const ETIQUETA_MENU: Record<Modo, string> = {
  clases: "Clases",
  citas: "Citas",
};

/**
 * Giros del registro (`PerfilNegocio` del API) por modalidad: la misma lista que
 * `ModalidadServicio::perfiles()`. Cada modalidad cierra con su giro para quien no
 * encuentra el suyo: `general` («Otro negocio con clases») y `general_citas` («Otro
 * negocio de citas»). `lib/modalidad.ts` la reutiliza.
 */
export const PERFILES_POR_MODO: Record<Modo, readonly string[]> = {
  clases: [
    "pilates",
    "pole",
    "yoga",
    "danza",
    "gimnasio",
    "crossfit",
    "hyrox",
    "natacion",
    "academia",
    "general",
  ],
  citas: ["barberia", "estetica", "salon", "spa", "salud", "general_citas"],
};

/**
 * Los giros para quien no encuentra el suyo. Sus nombres («Otro negocio con clases»,
 * «Otro negocio de citas») son para el selector del registro: a los clientes (página
 * pública del negocio, tarjetas del directorio) no se les muestran como insignia.
 */
export const PERFILES_GENERALES: readonly string[] = [
  "general",
  "general_citas",
];

/** ¿El giro se muestra a los clientes como insignia? Los generales no. */
export function perfilVisibleAlPublico(
  perfil: string | null | undefined,
): boolean {
  return (
    typeof perfil === "string" &&
    perfil !== "" &&
    !PERFILES_GENERALES.includes(perfil)
  );
}

/** La modalidad de un giro del registro; `null` si no es un giro del registro. */
export function modoDePerfil(perfil: string): Modo | null {
  return MODOS.find((modo) => PERFILES_POR_MODO[modo].includes(perfil)) ?? null;
}

/**
 * El giro de un `?giro=` (o de cualquier valor que llegue de afuera), normalizado:
 * sin espacios y en minúsculas. `null` si no es un giro del registro (un negocio del
 * carrusel o el slug de una página por giro tampoco lo son). Con el parámetro
 * repetido, vale el primero.
 */
export function giroDeQuery(valor: unknown): string | null {
  const crudo = Array.isArray(valor) ? valor[0] : valor;
  if (typeof crudo !== "string") {
    return null;
  }
  const giro = crudo.trim().toLowerCase();
  return modoDePerfil(giro) === null ? null : giro;
}

/**
 * El giro del registro de cada página por giro (`/software-para-{slug}`), solo si la
 * página es de un giro: su «Probar gratis» lo manda (`?giro=`) y el registro ya llega
 * con él elegido. «Barberías y estéticas» junta dos giros: no lleva ninguno.
 */
export const PERFIL_DE_SOLUCION: Readonly<Record<string, string>> = {
  // «CrossFit y HYROX» junta dos giros: no lleva ninguno.
  nutriologos: "salud",
  pilates: "pilates",
  "pole-dance": "pole",
  academias: "academia",
  spas: "spa",
  terapeutas: "salud",
};

/**
 * El giro del registro de cada negocio del carrusel (`NEGOCIOS_POR_MODO`), solo si el
 * negocio es de un giro: el botón del carrusel lo manda cuando la persona eligió ese
 * negocio.
 */
export const PERFIL_DE_NEGOCIO: Readonly<Record<string, string>> = {
  pilates: "pilates",
  pole: "pole",
  hyrox: "hyrox",
  academias: "academia",
  acuaticas: "natacion",
  crossfit: "crossfit",
  gimnasio: "gimnasio",
  danza: "danza",
  yoga: "yoga",
  // `estetica` («Peluquerías y estéticas») junta dos giros: no lleva ninguno.
  dentistas: "salud",
  barberia: "barberia",
  psicologos: "salud",
  wellness: "spa",
  nutriologos: "salud",
  spa: "spa",
  terapeutas: "salud",
};

/** El giro del registro de una página por giro; `null` si junta varios. */
export function perfilDeSolucion(slug: string): string | null {
  return PERFIL_DE_SOLUCION[slug] ?? null;
}

/** El giro del registro de un negocio del carrusel; `null` si junta varios. */
export function perfilDeNegocio(
  clave: string | null | undefined,
): string | null {
  return clave == null ? null : (PERFIL_DE_NEGOCIO[clave] ?? null);
}

/**
 * Negocios del carrusel y de «para quién» (`landing.paraQuien.negocios.{clave}`) por
 * modalidad. Wellness es de citas (sesiones 1 a 1 con un profesional).
 */
export const NEGOCIOS_POR_MODO: Record<Modo, readonly string[]> = {
  clases: [
    "pilates",
    "pole",
    "hyrox",
    "academias",
    "acuaticas",
    "crossfit",
    "gimnasio",
    "danza",
    "yoga",
  ],
  citas: [
    "estetica",
    "dentistas",
    "barberia",
    "psicologos",
    "wellness",
    "nutriologos",
    "spa",
    "terapeutas",
  ],
};

/** Giros del registro de esta modalidad (los que puede elegir el negocio). */
export function girosDe(modo: Modo): readonly string[] {
  return PERFILES_POR_MODO[modo];
}

/** Negocios del carrusel de esta modalidad. */
export function negociosDe(modo: Modo): readonly string[] {
  return NEGOCIOS_POR_MODO[modo];
}

/** Páginas por giro (`/software-para-*`) de esta modalidad. */
export function solucionesDe(modo: Modo): readonly Solucion[] {
  return soluciones.filter((s) => s.modo === modo);
}

/**
 * La modalidad de un giro: un perfil del registro (`barberia`), un negocio del
 * carrusel (`wellness`) o el slug de una página por giro (`pole-dance`). `null` si no
 * se conoce. Ninguna clave tiene una modalidad distinta en dos listas.
 */
export function modoDeGiro(giro: string): Modo | null {
  for (const modo of MODOS) {
    if (
      PERFILES_POR_MODO[modo].includes(giro) ||
      NEGOCIOS_POR_MODO[modo].includes(giro)
    ) {
      return modo;
    }
  }
  return soluciones.find((s) => s.slug === giro)?.modo ?? null;
}

/**
 * Foto de cada negocio del carrusel (`NEGOCIOS_POR_MODO`) en
 * `/assets/landing/disciplinas/`: la misma en la portada, en /clases y en /citas.
 */
export const IMAGEN_NEGOCIO: Record<string, string> = {
  pilates: "pilates-v1.jpg",
  pole: "pole-v1.jpg",
  hyrox: "crossfit-hyrox-v1.webp",
  academias: "academias-v1.jpg",
  acuaticas: "natacion-v1.jpg",
  crossfit: "crossfit-v1.webp",
  gimnasio: "gimnasio-v1.jpg",
  danza: "danza-v1.jpg",
  yoga: "yoga-v2.webp",
  estetica: "estetica-v1.jpg",
  dentistas: "consultorios-v1.webp",
  barberia: "barberia-v1.jpg",
  psicologos: "psicologia-v1.webp",
  wellness: "wellness-v1.webp",
  nutriologos: "nutriologos-v3.webp",
  spa: "spa-v1.webp",
  terapeutas: "terapeutas-v1.webp",
};

/** Ruta pública de la foto de un negocio del carrusel. */
export function imagenNegocio(clave: string): string {
  return `/assets/landing/disciplinas/${IMAGEN_NEGOCIO[clave] ?? IMAGEN_NEGOCIO.pilates}`;
}

export interface FotoMarketing {
  /** Ruta pública de una imagen que ya existe en `public/`. */
  src: string;
  alt: string;
}

export interface ContenidoModalidad {
  modo: Modo;
  ruta: `/${Modo}`;
  nombreRuta: (typeof NOMBRE_RUTA_MODALIDAD)[Modo];
  nombre: string;
  /**
   * SEO en texto plano (title, description, Open Graph, miga de pan). El título, de
   * hasta 60 caracteres con la marca. La descripción no lleva la frase de cierre: la
   * pone seoConfig según si el producto recibe registros (prueba gratis o lista de
   * interesados).
   */
  seo: {
    title: string;
    description: string;
    /** Imagen Open Graph: ruta pública existente; seoConfig la vuelve absoluta. */
    imagen: string;
    /** Su texto alternativo (`og:image:alt`). */
    imagenAlt: string;
    miga: string;
  };
  perfiles: readonly string[];
  negocios: readonly string[];
  /**
   * Funciones de la página, en orden: claves de `landing.{modo}.funciones.{clave}`.
   * Solo lo que el producto hace hoy (ver la tabla de incongruencias del análisis).
   */
  funciones: readonly string[];
  /** Preguntas frecuentes propias: claves de `landing.{modo}.faq.{clave}`. */
  preguntas: readonly string[];
  fotos: {
    /** Banda de recepción y ventas. */
    recepcion: FotoMarketing;
  };
  /** Hero de la página: el texto animado y las tres fotos del collage. */
  hero: {
    /** Claves de `landing.heroEscritura.negocios.*`, en orden. */
    escritura: readonly string[];
    /** Negocios del carrusel para el collage; el del centro se pide primero. */
    collage: readonly [string, string, string];
  };
  /** «Cómo funciona», en orden: `landing.{modo}.pasos.{clave}`. */
  pasos: readonly string[];
  /** Recepción y ventas: `landing.{modo}.operacion.beneficios.{clave}`. */
  operacion: readonly string[];
  /** Página pública: `landing.{modo}.pagina.beneficios.{clave}`. */
  pagina: readonly string[];
  /** Bajo los giros, la nota de alcance para profesionales de la salud. */
  notaSalud: boolean;
  /** La otra modalidad: el enlace discreto con el que cierra la página. */
  otra: Modo;
}

export const MODALIDADES: Record<Modo, ContenidoModalidad> = {
  clases: {
    modo: "clases",
    ruta: "/clases",
    nombreRuta: NOMBRE_RUTA_MODALIDAD.clases,
    nombre: NOMBRE_MODALIDAD.clases,
    seo: {
      title: "Software de clases para estudios y gimnasios | AgendaUno",
      description:
        "Organiza clases con cupo, lista de espera, membresías y créditos, y pasa lista con retardos.",
      imagen: "/assets/landing/disciplinas/pilates-v1.jpg",
      imagenAlt: "Alumna practicando Pilates Reformer en un estudio moderno",
      miga: ETIQUETA_MENU.clases,
    },
    perfiles: PERFILES_POR_MODO.clases,
    negocios: NEGOCIOS_POR_MODO.clases,
    // Cupos por clase, lista de espera, membresías y créditos, pase de lista con
    // retardos (ADR 0101) y el QR como pase de entrada (no registra la asistencia).
    funciones: ["cupos", "listaEspera", "membresias", "asistencia", "paseQr"],
    // Sin autorregistro de alumnos (ADR 0093) y el QR como pase de entrada. Quién
    // cambia la modalidad (ADR 0104) va aquí, no en el registro.
    preguntas: [
      "alumnos",
      "creditos",
      "listaEspera",
      "asistencia",
      "paseQr",
      "sucursales",
      "modalidad",
      "precio",
    ],
    hero: {
      escritura: [
        "pilates",
        "pole",
        "yoga",
        "academias",
        "acuaticas",
        "crossfit",
        "hyrox",
      ],
      collage: ["pilates", "pole", "hyrox"],
    },
    pasos: ["preparar", "invitar", "operar"],
    operacion: ["lista", "paseQr", "ventas"],
    // La página pública de clases termina en «Pedir acceso / Ya soy alumno».
    pagina: ["compartir", "lugares", "directorio"],
    notaSalud: false,
    otra: "citas",
    fotos: {
      recepcion: {
        src: "/assets/landing/agendauno-checkin-pos.webp",
        alt: "Teléfono con la lista de asistencia y tableta con productos del mostrador",
      },
    },
  },
  citas: {
    modo: "citas",
    ruta: "/citas",
    nombreRuta: NOMBRE_RUTA_MODALIDAD.citas,
    nombre: NOMBRE_MODALIDAD.citas,
    seo: {
      title: "Agenda de citas: barberías, spas y consultorios | AgendaUno",
      description:
        "Agenda por profesional, servicios con su duración y precio, paquetes y recordatorios por correo.",
      imagen: "/assets/landing/disciplinas/barberia-v1.jpg",
      imagenAlt: "Barbero atendiendo a un cliente en una barbería moderna",
      miga: ETIQUETA_MENU.citas,
    },
    perfiles: PERFILES_POR_MODO.citas,
    negocios: NEGOCIOS_POR_MODO.citas,
    // Agenda por profesional, «cualquier profesional disponible» (ADR 0062),
    // paquetes de servicios (ADR 0063), cobro en línea al agendar* (ADR 0065; solo
    // México) y recordatorios por correo (sin WhatsApp, push ni app).
    funciones: [
      "agendaProfesional",
      "cualquierProfesional",
      "paquetes",
      "cobroAlAgendar",
      "recordatorios",
    ],
    // Agendar sin cuenta (ADR 0093), cobro al agendar* (ADR 0065), espacios (ADR 0039)
    // y quién cambia la modalidad (ADR 0104).
    preguntas: [
      "cuenta",
      "cualquierProfesional",
      "cobro",
      "recordatorios",
      "paquetes",
      "espacios",
      "salud",
      "modalidad",
      "precio",
    ],
    hero: {
      escritura: [
        "barberias",
        "esteticas",
        "spas",
        "wellness",
        "terapeutas",
        "dentistas",
        "psicologos",
        "nutriologos",
      ],
      // La barbería va en la foto de recepción: no se repite en el hero.
      collage: ["spa", "estetica", "dentistas"],
    },
    pasos: ["preparar", "compartir", "operar"],
    operacion: ["llegada", "cobro", "pos"],
    // Servicio → profesional («Cualquier profesional») → hora, sin cuenta.
    pagina: ["compartir", "cualquiera", "cobro"],
    notaSalud: true,
    otra: "clases",
    fotos: {
      recepcion: {
        // No la de estética: abre el carrusel, justo antes de esta banda.
        src: "/assets/landing/disciplinas/barberia-v1.jpg",
        alt: "Barbero atendiendo a un cliente en una barbería moderna",
      },
    },
  },
};
