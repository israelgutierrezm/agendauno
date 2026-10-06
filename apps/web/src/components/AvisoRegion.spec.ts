import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AvisoRegion from "./AvisoRegion.vue";

/*
| Lo que no se puede usar se explica: por la moneda del negocio (con enlace para
| cambiarla) o porque la plataforma aún no factura (sin nada que cambiar).
*/

vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ moneda: "USD" }),
}));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => true }));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a class='enlace'><slot /></a>" },
}));

function montar(tipo: "facturacion" | "facturacionPlataforma") {
  return mount(AvisoRegion, {
    props: { tipo },
    global: { plugins: [i18n] },
  });
}

describe("aviso de lo que no está disponible", () => {
  it("por la moneda: lo explica y lleva a cambiarla", () => {
    const w = montar("facturacion");
    expect(w.text()).toContain("pesos mexicanos");
    expect(w.find(".enlace").exists()).toBe(true);
  });

  it("la plataforma aún no factura: lo dice, sin enlace a la moneda", () => {
    const w = montar("facturacionPlataforma");
    expect(w.text()).toContain("La facturación aún no está disponible");
    expect(w.find(".enlace").exists()).toBe(false);
  });
});
