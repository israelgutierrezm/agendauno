import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AutomaticoBienestar from "./AutomaticoBienestar.vue";

vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

/* El check-in automático en Integraciones (ADR 0148). */
describe("check-in automático", () => {
  const url =
    "https://api.agendauno.mx/api/v1/webhooks/bienestar/fluo/wellhub/tok";

  it("en Wellhub, por activar: dice qué falta y muestra la dirección para Wellhub", () => {
    const w = mount(AutomaticoBienestar, {
      props: {
        proveedor: "wellhub",
        automatico: { aviso_url: url, listo: false, registrado_en: null },
      },
      global: { plugins: [i18n] },
    });
    expect(w.get('[data-prueba="checkin-automatico-estado"]').text()).toBe(
      "Por activar",
    );
    expect(w.text()).toContain("llave secreta");
    expect(w.text()).toContain(url);
  });

  it("en TotalPass, activo: ya no pide nada ni muestra la dirección", () => {
    const w = mount(AutomaticoBienestar, {
      props: {
        proveedor: "totalpass",
        automatico: {
          aviso_url: url,
          listo: true,
          registrado_en: "2026-10-01T12:00:00Z",
        },
      },
      global: { plugins: [i18n] },
    });
    expect(w.get('[data-prueba="checkin-automatico-estado"]').text()).toBe(
      "Activo",
    );
    expect(w.text()).not.toContain(url);
  });

  it("sin guardar aún: pide guardar para generar la dirección", () => {
    const w = mount(AutomaticoBienestar, {
      props: { proveedor: "wellhub", automatico: null },
      global: { plugins: [i18n] },
    });
    expect(w.text()).toContain("Guarda la integración");
  });
});
