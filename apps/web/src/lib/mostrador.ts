import { terminoParaPersona } from "@/lib/terminologia";

type Traducir = (clave: string) => string;
type Existe = (clave: string) => boolean;

/**
 * Qué es en el negocio quien compra en el mostrador (ADR 0145), con las palabras del
 * negocio y en su género: «Alumna», «Maestro», «Cliente». Al equipo, «Equipo».
 */
export function terminoComprador(
  tipo: string | null | undefined,
  genero: string | null | undefined,
  t: Traducir,
  te: Existe,
): string {
  const clave = `mostradorVisual.comprador.tipos.${tipo ?? ""}`;
  if (!tipo || !te(clave)) {
    return "";
  }
  return tipo === "staff" ? t(clave) : terminoParaPersona(t(clave), genero);
}
