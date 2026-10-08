/**
 * Un documento legal (aviso de privacidad, términos) en bloques para mostrarlo: los
 * apartados numerados («1. Quiénes somos») son títulos; las líneas con «• », una
 * lista; lo demás, párrafos. El texto llega tal cual del documento publicado: nada se
 * interpreta como HTML.
 */
export type BloqueLegal =
  | { tipo: "parte"; texto: string }
  | { tipo: "titulo"; texto: string }
  | { tipo: "parrafo"; texto: string }
  | { tipo: "lista"; elementos: string[] };

const VINETA = /^\s*[•\-*]\s+/;
const TITULO = /^\d+\.\s+\S.*$/;

export function bloquesLegales(texto: string): BloqueLegal[] {
  const bloques: BloqueLegal[] = [];
  const partes = texto
    .split(/\r?\n\s*\r?\n/)
    .map((p) => p.trim())
    .filter((p) => p !== "");
  partes.forEach((parte, i) => {
    const lineas = parte.split(/\r?\n/).map((l) => l.trim());
    // Una línea en mayúsculas: el encabezado del documento (la página ya tiene su
    // título) o el de una de sus partes («PRIMERA PARTE: PARA LOS NEGOCIOS»).
    if (
      lineas.length === 1 &&
      parte === parte.toUpperCase() &&
      /[A-ZÁÉÍÓÚÑ]/.test(parte)
    ) {
      if (i > 0) {
        bloques.push({ tipo: "parte", texto: parte });
      }
      return;
    }
    if (lineas.length === 1 && TITULO.test(lineas[0]!)) {
      bloques.push({ tipo: "titulo", texto: lineas[0]! });
      return;
    }
    // Un párrafo (p. ej. «De los clientes del negocio:») seguido de su lista.
    const primera = lineas.findIndex((l) => VINETA.test(l));
    if (primera === -1) {
      bloques.push({ tipo: "parrafo", texto: lineas.join("\n") });
      return;
    }
    if (primera > 0) {
      bloques.push({
        tipo: "parrafo",
        texto: lineas.slice(0, primera).join("\n"),
      });
    }
    const elementos: string[] = [];
    for (const linea of lineas.slice(primera)) {
      if (VINETA.test(linea)) {
        elementos.push(linea.replace(VINETA, ""));
      } else if (elementos.length > 0) {
        // Continuación de la viñeta anterior.
        elementos[elementos.length - 1] += ` ${linea}`;
      }
    }
    bloques.push({ tipo: "lista", elementos });
  });
  return bloques;
}
