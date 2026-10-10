/**
 * Plural en español de un término del perfil de negocio, para rotular menús y
 * encabezados sin forks por industria: Barbero → Barberos, Cliente → Clientes,
 * Profesional → Profesionales, Coach → Coaches.
 */
export function plural(palabra: string): string {
  const p = palabra.trim();
  if (p === "") {
    return p;
  }
  const ultima = p.slice(-1).toLowerCase();
  if ("aeiouáéó".includes(ultima)) {
    return `${p}s`;
  }
  if (ultima === "z") {
    return `${p.slice(0, -1)}ces`;
  }
  // Agudas en n o s pierden el acento: Sesión → Sesiones, Lección → Lecciones.
  const acentuada = /[áéíóú][ns]$/i.exec(p);
  if (acentuada) {
    const sinAcento = acentuada[0][0]
      .normalize("NFD")
      .replace(/\p{Diacritic}/gu, "");
    return `${p.slice(0, -2)}${sinAcento}${p.slice(-1)}es`;
  }
  return `${p}es`;
}

/**
 * Cómo se llaman las cosas en el negocio (ADR 0049), tal como las manda la API:
 * qué se reserva (Clase, Cita…), quién lo toma (Alumno, Cliente…) y quién lo imparte
 * (Instructor, Barbero…), con sus plurales.
 */
export interface TerminosNegocio {
  sesion: string;
  sesiones?: string;
  miembro: string;
  miembros?: string;
  instructor: string;
  instructores?: string;
}

export type TerminoEditable = "sesion" | "miembro" | "instructor";

// Términos con «o» o «a» al final que valen igual para mujeres y hombres.
const INVARIABLES = new Set(["miembro", "guía", "atleta"]);

/**
 * El término del negocio para UNA persona, en su género si se sabe: con «Alumno» y una
 * mujer, «Alumna»; con «Socia» y un hombre, «Socio». Los términos que no cambian
 * (Cliente, Paciente, Miembro, Terapeuta, Especialista, Estilista) y las personas sin
 * género quedan como el negocio.
 */
export function terminoParaPersona(
  termino: string,
  genero: string | null | undefined,
): string {
  const t = termino.trim();
  const minusculas = t.toLowerCase();
  const ultima = minusculas.slice(-1);
  if (
    (ultima !== "o" && ultima !== "a") ||
    INVARIABLES.has(minusculas) ||
    // -ista, -eta y -euta valen para ambos: la terapeuta, el especialista.
    /(?:ista|eta|euta)$/.test(minusculas)
  ) {
    return t;
  }
  const base = t.slice(0, -1);
  const conMayuscula = ultima === t.slice(-1) ? "" : "M";
  const final = (letra: string): string =>
    conMayuscula ? letra.toUpperCase() : letra;
  if (genero === "mujer") {
    return `${base}${final("a")}`;
  }
  if (genero === "hombre") {
    return `${base}${final("o")}`;
  }
  return t;
}

/** Terminología de un negocio para editarla (GET/PUT …/terminologia). */
export interface DatosTerminologia {
  opciones: Record<TerminoEditable, string[]>;
  del_perfil: Record<TerminoEditable, string>;
  propia: Partial<Record<TerminoEditable, string>>;
  vigente: TerminosNegocio;
}

/** Términos de quien toma el servicio que valen para "la alumna" y "el alumno". */
const GENERO_COMUN = new Set(["cliente", "paciente"]);

/**
 * ¿El término es femenino (Alumna, Socia, Clienta)? Termina en «a» y no es de género
 * común. Con él, «el alumno» se lee «la alumna»: cambian también el artículo y los
 * adjetivos de alrededor (ver `feminizar`).
 */
function esFemenino(termino: string): boolean {
  const t = termino.trim().toLowerCase();
  return t.endsWith("a") && !GENERO_COMUN.has(t);
}

// Lo que va antes del sustantivo y concuerda con él (masculino → femenino).
const ANTES: Record<string, string> = {
  el: "la",
  los: "las",
  un: "una",
  unos: "unas",
  del: "de la",
  al: "a la",
  este: "esta",
  estos: "estas",
  ese: "esa",
  esos: "esas",
  nuevo: "nueva",
  nuevos: "nuevas",
  otro: "otra",
  otros: "otras",
  ningún: "ninguna",
  algún: "alguna",
  primer: "primera",
  mismo: "misma",
  mismos: "mismas",
  cuántos: "cuántas",
  varios: "varias",
  pocos: "pocas",
  muchos: "muchas",
  todos: "todas",
};
// Lo que va justo después y concuerda (alumnos activos → alumnas activas).
const DESPUES = [
  "activo",
  "inactivo",
  "nuevo",
  "inscrito",
  "registrado",
  "esperado",
  "archivado",
  "suspendido",
  "vencido",
  "asignado",
  "atendido",
  "invitado",
  "confirmado",
  "facturado",
];

/** «todos los alumnos activos» → «todas las alumnas activas» (con su forma). */
function feminizar(texto: string, singular: string, plurales: string): string {
  const antes = Object.keys(ANTES).join("|");
  const patron = new RegExp(
    `(?<!\\p{L})((?:(?:${antes})\\s+){0,2})(alumno|miembro)(s)?(?!\\p{L})(?!\\s+del\\s+(?:equipo|staff|personal))((?:\\s+(?:${DESPUES.join("|")})s?(?!\\p{L}))?)`,
    "giu",
  );
  return texto.replace(
    patron,
    (
      _m,
      previas: string,
      raiz: string,
      sufijo: string | undefined,
      sigue: string,
    ) => {
      const articulos = previas.replace(/\p{L}+/gu, (w) =>
        ANTES[w.toLowerCase()] !== undefined
          ? conMismaForma(w, ANTES[w.toLowerCase()])
          : w,
      );
      const adjetivo = sigue.replace(/(\p{L}+?)o(s?)$/u, "$1a$2");
      return `${articulos}${conMismaForma(raiz, sufijo ? plurales : singular)}${adjetivo}`;
    },
  );
}

interface Regla {
  patron: RegExp;
  singular: string;
  plural: string;
}

/** Misma forma que la palabra original: MAYÚSCULAS, Inicial o minúsculas. */
function conMismaForma(original: string, nuevo: string): string {
  if (original.length > 1 && original === original.toUpperCase()) {
    return nuevo.toUpperCase();
  }
  if (original[0] === original[0].toUpperCase()) {
    return nuevo.charAt(0).toUpperCase() + nuevo.slice(1).toLowerCase();
  }
  return nuevo.toLowerCase();
}

function palabra(raiz: string, pluralSufijo: string, despues = ""): RegExp {
  return new RegExp(
    `(?<!\\p{L})(${raiz})(${pluralSufijo})?(?!\\p{L})${despues}`,
    "giu",
  );
}

/**
 * Reglas para un negocio. Todo lo que se reserva es femenino como "clase" y quien
 * imparte, masculino o común como "instructor", así que el artículo no cambia. Quien
 * toma el servicio: "alumno" y "miembro" pasan al término del negocio salvo que sea
 * femenino (Alumna); "alumna" solo a términos comunes (la cliente, la paciente).
 */
function reglas(t: TerminosNegocio): Regla[] {
  const lista: Regla[] = [];
  const sesiones = t.sesiones ?? plural(t.sesion);
  const miembros = t.miembros ?? plural(t.miembro);
  const instructores = t.instructores ?? plural(t.instructor);

  if (t.sesion.toLowerCase() !== "clase") {
    lista.push({
      patron: palabra("clase", "s"),
      singular: t.sesion,
      plural: sesiones,
    });
  }
  if (t.instructor.toLowerCase() !== "instructor") {
    lista.push({
      patron: palabra("instructor", "es"),
      singular: t.instructor,
      plural: instructores,
    });
  }
  const miembro = t.miembro.toLowerCase();
  // Femenino (Alumna, Socia): lo hace `feminizar`, con artículos y adjetivos.
  if (!esFemenino(miembro)) {
    if (miembro !== "alumno") {
      lista.push({
        patron: palabra("alumno", "s"),
        singular: t.miembro,
        plural: miembros,
      });
    }
    if (miembro !== "miembro") {
      // "Miembro del equipo" es alguien del personal: no cambia.
      lista.push({
        patron: palabra(
          "miembro",
          "s",
          "(?!\\s+del\\s+(?:equipo|staff|personal))",
        ),
        singular: t.miembro,
        plural: miembros,
      });
    }
    if (GENERO_COMUN.has(miembro)) {
      lista.push({
        patron: palabra("alumna", "s"),
        singular: t.miembro,
        plural: miembros,
      });
    }
  } else if (miembro !== "alumna") {
    // Socia, Clienta: «la alumna» también es «la socia».
    lista.push({
      patron: palabra("alumna", "s"),
      singular: t.miembro,
      plural: miembros,
    });
  }
  return lista;
}

// {marcadores} y mensajes enlazados (@:clave) no se tocan.
const INTOCABLE = /(\{[^}]*\}|@(?:\.\w+)?:[\w.]+)/;

/**
 * Adapta un texto a la terminología del negocio: "Próximas clases" → "Próximas
 * citas", "Nuevo alumno" → "Nuevo cliente". Si el cambio deja "citas o citas", queda
 * "citas".
 */
export function adaptarTexto(texto: string, t: TerminosNegocio): string {
  const lista = reglas(t);
  if (lista.length === 0 && !esFemenino(t.miembro)) {
    return texto;
  }
  const destinos = lista
    .flatMap((r) => [r.singular, r.plural])
    .map((d) => d.toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
  const repetido = new RegExp(
    `(?<!\\p{L})(${destinos.join("|")}) (?:y|o|u|ni) \\1(?!\\p{L})`,
    "giu",
  );

  return texto
    .split(INTOCABLE)
    .map((parte, i) => {
      if (i % 2 === 1) {
        return parte;
      }
      let salida = esFemenino(t.miembro)
        ? feminizar(parte, t.miembro, t.miembros ?? plural(t.miembro))
        : parte;
      for (const r of lista) {
        salida = salida.replace(r.patron, (_m, raiz: string, sufijo?: string) =>
          conMismaForma(raiz, sufijo ? r.plural : r.singular),
        );
      }
      return salida.replace(repetido, "$1");
    })
    .join("");
}

/**
 * Adapta todos los textos de un árbol de mensajes, salvo los de las secciones
 * indicadas (las públicas y las del cobro del SaaS hablan de clases y citas en
 * general).
 */
export function adaptarMensajes<T>(
  mensajes: T,
  t: TerminosNegocio,
  excluir: ReadonlySet<string> = new Set(),
): T {
  const recorrer = (valor: unknown): unknown => {
    if (typeof valor === "string") {
      return adaptarTexto(valor, t);
    }
    if (Array.isArray(valor)) {
      return valor.map(recorrer);
    }
    if (valor !== null && typeof valor === "object") {
      return Object.fromEntries(
        Object.entries(valor).map(([k, v]) => [k, recorrer(v)]),
      );
    }
    return valor;
  };
  return Object.fromEntries(
    Object.entries(mensajes as Record<string, unknown>).map(([k, v]) => [
      k,
      excluir.has(k) ? v : recorrer(v),
    ]),
  ) as T;
}

// Terminología del negocio en sesión, para adaptar también los mensajes de la API.
let actuales: TerminosNegocio | null = null;

export function fijarTerminosActuales(t: TerminosNegocio | null): void {
  actuales = t;
}

/** Un mensaje de la API ("Esta clase ya está llena") con la terminología vigente. */
export function conTerminosActuales(texto: string): string {
  return actuales === null ? texto : adaptarTexto(texto, actuales);
}
