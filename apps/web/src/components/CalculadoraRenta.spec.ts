import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import CalculadoraRenta from "./CalculadoraRenta.vue";

/*
| «Calcula tu mensualidad»: con los mismos precios que la tabla (aquí, el respaldo),
| para que lo que alguien calcula sea lo que verá al contratar.
*/

function montar(modo: "clases" | "citas", anual = false) {
  return mount(CalculadoraRenta, {
    props: { modo, precios: PRECIOS_POR_OMISION, anual },
    global: { stubs: { RouterLink: true } },
  });
}

describe("calculadora de la mensualidad", () => {
  it("con el último FIX publicado, el equivalente en pesos con IVA", () => {
    const w = mount(CalculadoraRenta, {
      global: { stubs: { RouterLink: true } },
      props: {
        modo: "clases",
        precios: {
          ...PRECIOS_POR_OMISION,
          tipo_cambio: { diezmilesimas: 185000, fecha: "2026-10-19" },
        },
      },
    });
    // 45 alumnos: USD 30 + IVA = 34.80 × 18.50 = $643.80 → unos $644.
    expect(w.get('[data-prueba="calculadora-pesos"]').text()).toContain(
      "unos $644 MXN al mes con IVA, al tipo de cambio FIX del 19 de octubre de 2026 (18.5000 pesos por dólar)",
    );
    // Sin tipo de cambio no se inventa uno.
    expect(
      montar("clases").find('[data-prueba="calculadora-pesos"]').exists(),
    ).toBe(false);
  });

  it("clases: una banda para todo el mes, con el IVA de México aparte", async () => {
    const w = montar("clases");
    // 45 por omisión: banda de 41 a 60.
    expect(w.get('[data-prueba="calculadora-importe"]').text()).toBe("$30");
    expect(w.text()).toContain("Banda de 41 a 60 alumnos activos");
    expect(w.get('[data-prueba="calculadora-iva"]').text()).toContain(
      "$30 + IVA 16 % ($4.80) = $34.80 USD, que se cobran en pesos al tipo de cambio FIX",
    );

    await w.get('[data-prueba="calculadora-alumnos"]').setValue("0");
    expect(w.get('[data-prueba="calculadora-importe"]').text()).toBe("$0");
    expect(w.find('[data-prueba="calculadora-iva"]').exists()).toBe(false);

    await w.get('[data-prueba="calculadora-alumnos"]').setValue("1500");
    expect(w.find('[data-prueba="calculadora-importe"]').exists()).toBe(false);
    expect(w.text()).toContain("Más de 1,000 alumnos activos");
  });

  it("citas: plan y profesionales, al mes o al año por adelantado", async () => {
    const mensual = montar("citas");
    // Premium con 3 por omisión.
    expect(mensual.get('[data-prueba="calculadora-importe"]').text()).toBe(
      "$28",
    );
    expect(mensual.text()).toContain("al mes, por adelantado");

    const anual = montar("citas", true);
    expect(anual.get('[data-prueba="calculadora-importe"]').text()).toBe(
      "$280",
    );
    expect(anual.text()).toContain("10 meses por 12 de servicio");

    await mensual
      .get('[data-prueba="calculadora-plan"]')
      .setValue("individual");
    expect(mensual.get('[data-prueba="calculadora-importe"]').text()).toBe(
      "$9",
    );
    expect(
      mensual.find('[data-prueba="calculadora-profesionales"]').exists(),
    ).toBe(false);

    await mensual.get('[data-prueba="calculadora-plan"]').setValue("pro");
    await mensual
      .get('[data-prueba="calculadora-profesionales"]')
      .setValue("25");
    expect(mensual.text()).toContain("Más de 20 profesionales");
  });
});
