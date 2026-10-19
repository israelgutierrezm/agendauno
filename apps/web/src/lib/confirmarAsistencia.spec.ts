import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import {
  textoInasistencia,
  type EfectoInasistencia,
} from "./confirmarAsistencia";

/*
| «No vino» dice lo que de verdad pasa con su crédito según su política (lo calcula
| el API), no un «puede perder».
*/

const t = i18n.global.t as unknown as (
  llave: string,
  valores?: Record<string, unknown>,
  plural?: number,
) => string;

function efecto(cambios: Partial<EfectoInasistencia>): EfectoInasistencia {
  return {
    con_credito: true,
    penaliza: true,
    tolerada: false,
    pierde_credito: true,
    unidades: 1000,
    ...cambios,
  };
}

describe("efecto de una falta", () => {
  it("dice cuántos créditos pierde", () => {
    expect(textoInasistencia(t, efecto({}))).toBe(
      "Pierde 1 crédito de su plan.",
    );
    expect(textoInasistencia(t, efecto({ unidades: 2000 }))).toBe(
      "Pierde 2 créditos de su plan.",
    );
  });

  it("si se tolera o su política no cobra, no lo pierde", () => {
    expect(
      textoInasistencia(t, efecto({ tolerada: true, pierde_credito: false })),
    ).toContain("tolerancia");
    expect(
      textoInasistencia(t, efecto({ penaliza: false, pierde_credito: false })),
    ).toContain("vuelve a su saldo");
  });

  it("sin crédito apartado su saldo no cambia; sin dato, el texto general", () => {
    expect(
      textoInasistencia(
        t,
        efecto({ con_credito: false, pierde_credito: false }),
      ),
    ).toContain("su saldo no cambia");
    expect(textoInasistencia(t, null)).toBeNull();
  });
});
