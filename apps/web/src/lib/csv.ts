/**
 * Las celdas de los CSV que se arman en el navegador (ADR 0173), con la misma regla que
 * las exportaciones del API: un texto que empieza con `=`, `+`, `-`, `@`, un tabulador o
 * un retorno, la hoja de cálculo lo tomaría por fórmula; se le antepone un apóstrofo,
 * salvo que sea un número. Siempre entre comillas.
 */
const INICIOS_DE_FORMULA = ["=", "+", "-", "@", "\t", "\r"];

function esNumero(texto: string): boolean {
  return texto.trim() !== "" && Number.isFinite(Number(texto));
}

export function celdaCsv(valor: string | number | null | undefined): string {
  let texto = valor === null || valor === undefined ? "" : String(valor);
  if (
    texto !== "" &&
    INICIOS_DE_FORMULA.includes(texto[0]) &&
    !esNumero(texto)
  ) {
    texto = `'${texto}`;
  }
  return `"${texto.replaceAll('"', '""')}"`;
}

/** Una línea con sus celdas separadas por coma. */
export function lineaCsv(
  valores: ReadonlyArray<string | number | null | undefined>,
): string {
  return valores.map(celdaCsv).join(",");
}
