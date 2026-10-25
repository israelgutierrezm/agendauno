import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import ArranqueYReferidos from "./ArranqueYReferidos.vue";
import ReemplazaHerramientas from "./ReemplazaHerramientas.vue";

/*
| Lo que la landing promete, por producto: TurnoUno (citas) no ofrece la app con tu
| marca (es de AgendaUno, ADR 0111), no habla de membresías ni de salas, y nada dice
| que «nadie más lo da».
*/

vi.mock("@/marketing/preciosPublicos", () => ({
  usePreciosPublicos: () => ({ datos: {} }),
}));

describe("lo que reemplaza y lo que incluye", () => {
  it("en citas: cabinas, sin sedes ilimitadas ni CFDI como incluidos", () => {
    const w = mount(ReemplazaHerramientas, { props: { modo: "citas" } });
    const texto = w.text();
    expect(texto).toContain("Incluido en tu suscripción");
    expect(texto).not.toContain("nadie más");
    expect(texto).toContain("sedes, cabinas y equipo");
    expect(texto).not.toContain("salas");
    expect(texto).not.toContain("Todas tus sedes sin costo extra");
    expect(texto).not.toContain("CFDI a tus clientes, con cancelación");
  });

  it("en clases: salas y sedes incluidas", () => {
    const texto = mount(ReemplazaHerramientas, {
      props: { modo: "clases" },
    }).text();
    expect(texto).toContain("sedes, salas y equipo");
    expect(texto).toContain("Todas tus sedes sin costo extra");
  });
});

describe("lo que se cobra aparte", () => {
  it("en citas no se ofrece la app con tu marca y se migran bonos", () => {
    const w = mount(ArranqueYReferidos, { props: { modo: "citas" } });
    expect(w.find('[data-prueba="app-con-tu-marca"]').exists()).toBe(false);
    expect(w.text()).toContain("saldos y bonos");
    expect(w.text()).not.toContain("membresías");
  });

  it("en clases, la app con tu marca dice que hoy es en Google Play", () => {
    const w = mount(ArranqueYReferidos, { props: { modo: "clases" } });
    const app = w.get('[data-prueba="app-con-tu-marca"]');
    expect(app.text()).toContain("Google Play");
    expect(app.text()).toContain("Por ahora solo para Android");
  });
});
