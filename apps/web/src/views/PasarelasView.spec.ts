import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PasarelasView from "./PasarelasView.vue";

/*
| Pasarelas de pago (ADR 0099): las de cobro en línea solo funcionan en pesos
| mexicanos. Con otra moneda se explica y no se pueden configurar; la ventanilla
| (depósito con comprobante) sigue.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    moneda: "USD",
    puede: () => true,
  }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  onBeforeRouteLeave: () => undefined,
}));

const pasarela = (proveedor: string) => ({
  proveedor,
  activa: false,
  modo: "test",
  llaves_configuradas: [],
  disponible: true,
  lista: false,
  webhook_url: null,
  codigo_verificacion: null,
});

describe("pasarelas fuera de pesos mexicanos", () => {
  it("lo explica y solo deja configurar la ventanilla", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [pasarela("stripe"), pasarela("ventanilla")],
        meta: { en_linea_disponible: false },
      },
    });
    const w = mount(PasarelasView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.get('[data-prueba="aviso-pasarelas"]').text()).toContain(
      "solo funciona en pesos mexicanos",
    );
    // Stripe sin formulario (solo el aviso); la ventanilla, con el suyo.
    expect(w.findAll('input[type="checkbox"]')).toHaveLength(1);
    expect(w.text()).toContain("Solo con pesos mexicanos (MXN)");
  });
});
