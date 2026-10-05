import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import asistente from "@/i18n/locales/asistente.es-MX";
import esMX from "@/i18n/locales/es-MX";
import zonaArchivo from "@/i18n/locales/zonaArchivo.es-MX";
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
          messages: { es: { ...esMX, asistente, zonaArchivo } },
        }),
      ],
    },
  });
}

describe("cargador del logo", () => {
  it("sin logo invita a subirlo; el botón despliega la zona para arrastrarlo", async () => {
    const w = montar(null);
    expect(w.find("img").exists()).toBe(false);
    expect(w.find('[data-prueba="zona-archivo"]').exists()).toBe(false);

    await w.get('[data-prueba="abrir-zona"]').trigger("click");
    expect(w.text()).toContain("Arrastra tu logo aquí o elígelo");
    expect(w.text()).toContain("Máx. 2 MB");

    await w.get('[data-prueba="plegar-zona"]').trigger("click");
    expect(w.get('[data-prueba="abrir-zona"]').text()).toBe("Subir logo");
  });

  it("con logo muestra el del negocio y ofrece cambiarlo o quitarlo", () => {
    const w = montar("/storage/logo.png");
    expect(w.get('[data-prueba="logo-actual"]').attributes("src")).toBe(
      "/storage/logo.png",
    );
    const botones = w.findAll("button").map((b) => b.text());
    expect(botones).toEqual(["Cambiar logo", "Quitar"]);
  });
});
