import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import RangoFechas from "./RangoFechas.vue";

/* El rango de fechas de los listados, con sus atajos de periodo (días del negocio). */
describe("rango de fechas con atajos", () => {
  it("un atajo llena las dos fechas y un rango libre se dice como tal", async () => {
    const w = mount(RangoFechas, {
      props: {
        desde: "",
        hasta: "",
        atajos: true,
        zona: "America/Mexico_City",
        prueba: "r",
        "onUpdate:desde": (v: string) => w.setProps({ desde: v }),
        "onUpdate:hasta": (v: string) => w.setProps({ hasta: v }),
      },
      global: { plugins: [i18n] },
    });
    const periodo = w.get('[data-prueba="r-periodo"]');
    expect((periodo.element as HTMLSelectElement).value).toBe("todo");

    await periodo.setValue("hoy");
    const desde = (w.get('[data-prueba="r-desde"]').element as HTMLInputElement)
      .value;
    expect(desde).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(
      (w.get('[data-prueba="r-hasta"]').element as HTMLInputElement).value,
    ).toBe(desde);
    expect((periodo.element as HTMLSelectElement).value).toBe("hoy");

    await w.setProps({ desde: "2020-01-02", hasta: "2020-01-09" });
    expect((periodo.element as HTMLSelectElement).value).toBe("libre");

    await periodo.setValue("todo");
    expect(w.props("desde")).toBe("");
    expect(w.props("hasta")).toBe("");
  });

  it("sin atajos, solo las dos fechas", () => {
    const w = mount(RangoFechas, {
      props: { desde: "", hasta: "", prueba: "r" },
      global: { plugins: [i18n] },
    });
    expect(w.find('[data-prueba="r-periodo"]').exists()).toBe(false);
  });
});
