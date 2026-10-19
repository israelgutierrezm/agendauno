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

/** Lo que un plan vendido deja reservar y dónde (lo mismo que se aplica al reservar). */
export interface AlcancePlan extends CoberturaSedes {
  actividad?: string | null;
  aplica_a?: string[] | null;
}

/**
 * «Solo en Roma · Pole dance · Clases: Pole y Aéreo», en una línea. Las sedes se
 * dicen si el plan no vale en todas o el negocio tiene varias; la actividad y las
 * clases, si las restringe. Null = no hay nada que aclarar.
 */
export function textoAlcance(
  plan: AlcancePlan,
  t: Traducir,
  opciones: { variasSedes: boolean; esCitas: boolean },
): string | null {
  const partes: string[] = [];
  if (plan.todas_sucursales === false || opciones.variasSedes) {
    partes.push(textoCobertura(plan, t));
  }
  if (plan.actividad) {
    partes.push(
      t("escaparate.planes.paraActividad", { actividad: plan.actividad }),
    );
  }
  const clases = plan.aplica_a ?? [];
  if (clases.length > 0) {
    partes.push(
      t(
        opciones.esCitas
          ? "escaparate.planes.soloServicios"
          : "escaparate.planes.soloClases",
        { lista: listaDeNombres(clases) },
      ),
    );
  }
  return partes.length > 0 ? partes.join(" · ") : null;
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
