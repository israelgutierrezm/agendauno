import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { miPrivacidad } from "@/i18n/locales/gestion.es-MX";
import MiPrivacidad from "./MiPrivacidad.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function privacidad(extra: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        recibe_promociones: true,
        whatsapp_disponible: false,
        acepta_whatsapp: false,
        baja: null,
        ...extra,
      },
    },
  };
}

function montar() {
  return mount(MiPrivacidad, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { miPrivacidad } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("avisos por WhatsApp en Mi privacidad", () => {
  it("un fallo de carga se muestra y permite recuperar las preferencias", async () => {
    api.get.mockRejectedValueOnce(new Error("Sin conexión"));
    api.get.mockResolvedValueOnce(privacidad());
    const w = montar();
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toContain(miPrivacidad.errorCarga);
    await w.get('[role="alert"] button').trigger("click");
    await flushPromises();
    expect(w.find('[role="alert"]').exists()).toBe(false);
    expect(w.text()).toContain(miPrivacidad.promociones);
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  it("integrada en perfil no repite el título ni agrega otra tarjeta", async () => {
    api.get.mockResolvedValue(privacidad());
    const w = montar();
    await w.setProps({ integrado: true });
    await flushPromises();
    expect(w.find("h2").exists()).toBe(false);
    expect(w.classes()).not.toContain("tu-card");
    expect(w.text()).toContain(miPrivacidad.descargar);
  });
  it("si el negocio no los usa, no aparecen", async () => {
    api.get.mockResolvedValue(privacidad());
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="acepta-whatsapp"]').exists()).toBe(false);
  });

  it("si los usa, se aceptan o se retiran", async () => {
    api.get.mockResolvedValue(privacidad({ whatsapp_disponible: true }));
    api.put.mockResolvedValue(
      privacidad({ whatsapp_disponible: true, acepta_whatsapp: true }),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="acepta-whatsapp"]').setValue(true);
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/mi/privacidad", {
      acepta_whatsapp: true,
    });
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });
});
