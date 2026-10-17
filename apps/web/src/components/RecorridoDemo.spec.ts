import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import AyudaParaEmpezar from "./AyudaParaEmpezar.vue";
import CasosPiloto from "./CasosPiloto.vue";
import RecorridoDemo from "./RecorridoDemo.vue";

/*
| La landing explica el recorrido completo (reserva → la recibe el negocio → pago →
| resultado), qué se trae del sistema anterior y, solo con pilotos reales, sus casos.
*/

describe("recorrido de principio a fin", () => {
  it("clases: cuatro pasos con la misma alumna y la misma clase, sin hablar de citas", async () => {
    const w = mount(RecorridoDemo, { props: { modo: "clases" } });
    const pasos = w.findAll('[role="tab"]');
    expect(pasos.map((p) => p.attributes("data-paso"))).toEqual([
      "reserva",
      "recibe",
      "cobro",
      "resultado",
    ]);
    expect(w.get('[role="tabpanel"]').text()).toContain(
      "Sofía reserva su lugar",
    );

    await w.get('[data-prueba="recorrido-siguiente"]').trigger("click");
    expect(pasos[1]!.attributes("aria-selected")).toBe("true");
    expect(w.get('[role="tabpanel"]').text()).toContain("Pilates Reformer");

    await pasos[3]!.trigger("click");
    expect(w.get('[role="tabpanel"]').text()).toContain("corte de caja");
    expect(w.text()).not.toMatch(/\bcitas?\b/i);
    expect(w.text()).toContain("Datos de ejemplo");
  });

  it("citas: de agendar sin cuenta al corte del día", async () => {
    const w = mount(RecorridoDemo, { props: { modo: "citas" } });
    expect(w.get('[role="tabpanel"]').text()).toContain("sin crear una cuenta");
    await w.findAll('[role="tab"]')[2]!.trigger("click");
    expect(w.get('[role="tabpanel"]').text()).toContain("Se registra el pago");
    expect(w.text()).not.toMatch(/alumn|cupo/i);
  });
});

describe("ayuda para empezar", () => {
  it("dice qué se trae y qué no se traslada en cada modalidad", () => {
    const clases = mount(AyudaParaEmpezar, { props: { modo: "clases" } });
    expect(clases.text()).toContain("Tus clases y los horarios que se repiten");
    expect(clases.text()).toContain("Las reservas futuras");
    expect(clases.get('a[href^="mailto:"]').attributes("href")).toContain(
      "mailto:",
    );

    const citas = mount(AyudaParaEmpezar, { props: { modo: "citas" } });
    expect(citas.text()).toContain("Los bonos vigentes de cada cliente");
    // Hoy no hay importador de citas: no se promete.
    expect(citas.text()).toContain(
      "Las citas ya agendadas: se capturan de nuevo en la agenda",
    );
  });
});

describe("casos de pilotos", () => {
  it("sin casos reales y autorizados no muestra nada", () => {
    const w = mount(CasosPiloto, { props: { modo: "clases" } });
    expect(w.find('[data-prueba="casos-piloto"]').exists()).toBe(false);
  });
});
