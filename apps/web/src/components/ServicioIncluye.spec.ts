import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import ServicioIncluye from "./ServicioIncluye.vue";

function montar(props: Record<string, unknown>) {
  return mount(ServicioIncluye, {
    props,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { perfilPublico } },
        }),
      ],
    },
  });
}

describe("qué incluye un paquete", () => {
  it("lista lo que incluye y cuánto costaría por separado si sale más barato", () => {
    const w = montar({
      incluye: ["Limpieza dental", "Aplicación de flúor"],
      precioMinor: 50000,
      porSeparadoMinor: 65000,
      moneda: "MXN",
    });
    expect(w.text()).toContain(
      "Incluye: Limpieza dental · Aplicación de flúor",
    );
    expect(w.text()).toContain("Por separado:");
    expect(w.get("s").text()).toBe("$650.00");
  });

  it("no presume ahorro si no lo hay o no se puede comparar", () => {
    const igual = montar({
      incluye: ["Limpieza dental"],
      precioMinor: 30000,
      porSeparadoMinor: 30000,
    });
    expect(igual.text()).toContain("Incluye: Limpieza dental");
    expect(igual.find("s").exists()).toBe(false);

    const sinComparar = montar({
      incluye: ["Limpieza dental"],
      precioMinor: 30000,
      porSeparadoMinor: null,
    });
    expect(sinComparar.find("s").exists()).toBe(false);
  });

  it("un servicio simple no muestra nada", () => {
    expect(montar({ incluye: [] }).html()).not.toContain("Incluye");
  });
});
