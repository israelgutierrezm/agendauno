import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import asistente from "@/i18n/locales/asistente.es-MX";
import esMX from "@/i18n/locales/es-MX";
import CargadorLogo from "./CargadorLogo.vue";

vi.mock("@/lib/api", () => ({ api: {}, mensajeDeError: String }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

function montar(logoUrl: string | null) {
  return mount(CargadorLogo, {
    props: { logoUrl },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, asistente } },
        }),
      ],
    },
  });
}

describe("cargador del logo", () => {
  it("sin logo invita a subirlo", () => {
    const w = montar(null);
    expect(w.find("img").exists()).toBe(false);
    expect(w.text()).toContain("Arrastra tu logo aquí o elígelo");
    expect(w.text()).toContain("Máx. 2 MB");
  });

  it("con logo muestra el del negocio", () => {
    expect(montar("/storage/logo.png").find("img").attributes("src")).toBe(
      "/storage/logo.png",
    );
  });
});
