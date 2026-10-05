import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import type { ResumenTarjeta } from "@/lib/resumenTarjeta";
import TarjetaMiembro from "./TarjetaMiembro.vue";

const resumen: ResumenTarjeta = {
  membresia: {
    estado: "vigente",
    plan: "Paquete de cinco visitas",
    valido_hasta: null,
    pausada_hasta: null,
    ilimitado: false,
    saldo_unidades: 5000,
    tiene_acceso: true,
  },
  ultima_visita: null,
  proxima: null,
  adeudo: true,
};

function montar(mostrarPlan?: boolean) {
  return mount(TarjetaMiembro, {
    props: {
      nombre: "Ana",
      nombreCompleto: "Ana López",
      email: "ana@example.test",
      activo: true,
      resumen,
      ...(mostrarPlan === undefined ? {} : { mostrarPlan }),
    },
    global: { plugins: [i18n] },
  });
}

describe("tarjeta administrativa de una persona", () => {
  it("conserva el plan y los créditos por defecto", () => {
    const w = montar();
    expect(w.get(".tm-plan").text()).toContain("Paquete de cinco visitas");
    expect(w.get(".tm-plan").text()).toContain("5 disponibles");
  });

  it("oculta el bloque de plan sin ocultar adeudos ni contacto", () => {
    const w = montar(false);
    expect(w.find(".tm-plan").exists()).toBe(false);
    expect(w.text()).toContain(i18n.global.t("tarjetas.adeudo"));
    expect(w.text()).toContain("ana@example.test");
    expect(w.text()).toContain(i18n.global.t("tarjetas.proxima"));
  });
});
