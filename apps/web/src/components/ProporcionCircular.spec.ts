import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ProporcionCircular from "./ProporcionCircular.vue";
describe("proporción circular: datos reales y estado sin datos", () => {
  it("representa la proporción recibida con etiqueta accesible", () => {
    const w = mount(ProporcionCircular, {
      props: { valor: 15, total: 25, etiqueta: "Por atender" },
    });
    expect(w.attributes("aria-label")).toBe("Por atender: 60%");
    expect(w.get(".pc-valor").attributes("stroke-dasharray")).toBe("60 100");
  });
  it("no simula porcentajes cuando no hay denominador", () => {
    const w = mount(ProporcionCircular, {
      props: { valor: 5, total: 0, etiqueta: "Ocupación" },
    });
    expect(w.text()).toBe("—");
    expect(w.find(".pc-valor").exists()).toBe(false);
  });
  it("evita dibujar valores fuera del rango o inválidos", () => {
    expect(
      mount(ProporcionCircular, {
        props: { valor: 150, total: 100, etiqueta: "Ocupación" },
      }).text(),
    ).toBe("100%");
    expect(
      mount(ProporcionCircular, {
        props: { valor: Number.NaN, total: 100, etiqueta: "Ocupación" },
      }).text(),
    ).toBe("—");
  });
});
