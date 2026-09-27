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

/** Terminología de un negocio para editarla (GET/PUT …/terminologia). */
export interface DatosTerminologia {
  opciones: Record<TerminoEditable, string[]>;
  del_perfil: Record<TerminoEditable, string>;
  propia: Partial<Record<TerminoEditable, string>>;
  vigente: TerminosNegocio;
}

/** Términos de quien toma el servicio que valen para "la alumna" y "el alumno". */
const GENERO_COMUN = new Set(["cliente", "paciente"]);

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
  if (miembro !== "alumna") {
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
  if (lista.length === 0) {
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
      let salida = parte;
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
