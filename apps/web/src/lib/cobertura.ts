/**
 * Dónde vale un plan (M06), dicho igual en la página pública, la cuenta y la ficha:
 * «Todas las sedes incluidas» o «Solo en Roma y Polanco». Nunca «multisucursal» sin
 * decir cuáles.
 */
export interface CoberturaSedes {
  todas_sucursales?: boolean;
  sucursales?: { nombre: string }[] | null;
}

type Traducir = (llave: string, valores?: Record<string, unknown>) => string;

/** «Roma», «Roma y Polanco», «Roma, Polanco y Condesa». */
export function listaDeNombres(nombres: string[]): string {
  if (nombres.length <= 1) {
    return nombres[0] ?? "";
  }
  return `${nombres.slice(0, -1).join(", ")} y ${nombres[nombres.length - 1]}`;
}

export function textoCobertura(plan: CoberturaSedes, t: Traducir): string {
  if (plan.todas_sucursales !== false) {
    return t("escaparate.planes.todasLasSedes");
  }
  const nombres = (plan.sucursales ?? []).map((s) => s.nombre);
  return nombres.length > 0
    ? t("escaparate.planes.soloEn", { sedes: listaDeNombres(nombres) })
    : t("escaparate.planes.todasLasSedes");
}
