import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import TarjetasIndicadores, { type Indicador } from "./TarjetasIndicadores.vue";

function indicadores(n: number): Indicador[] {
  return Array.from({ length: n }, (_, i) => ({
    clave: `k${i}`,
    valor: String(i),
    etiqueta: `Indicador ${i}`,
    icono: "agenda",
    tono: "verde",
  }));
}

describe("Tarjetas de indicadores", () => {
  it("cada indicador con su ícono, su color, la etiqueta y el valor", () => {
    const w = mount(TarjetasIndicadores, {
      props: { tarjetas: indicadores(2) },
    });

    const tarjeta = w.get('[data-prueba="indicador-k1"]');
    expect(tarjeta.classes()).toContain("tu-tono-verde");
    expect(tarjeta.text()).toContain("Indicador 1");
    expect(tarjeta.get("dd").text()).toBe("1");
  });

  it("en una fila hasta 5; con 6, dos filas de 3 para que no quede uno solo", () => {
    const cinco = mount(TarjetasIndicadores, {
      props: { tarjetas: indicadores(5) },
    });
    const seis = mount(TarjetasIndicadores, {
      props: { tarjetas: indicadores(6) },
    });

    expect(cinco.get("dl").attributes("style")).toContain("--ti-n: 5");
    expect(seis.get("dl").attributes("style")).toContain("--ti-n: 3");
  });

  it("lo que pide atención va en el color de aviso", () => {
    const w = mount(TarjetasIndicadores, {
      props: {
        tarjetas: [
          { clave: "espera", valor: "2", etiqueta: "En espera", aviso: true },
        ],
      },
    });

    expect(w.get("dd").attributes("style")).toContain("var(--aviso)");
  });
});
