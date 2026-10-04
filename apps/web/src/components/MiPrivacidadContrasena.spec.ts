import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { miPrivacidad } from "@/i18n/locales/gestion.es-MX";
import MiPrivacidad from "./MiPrivacidad.vue";

/**
 * Descargar mis datos y pedir la baja se confirman con la contraseña en una ventana
 * (el servidor la valida). Datos sintéticos.
 */
const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "La contraseña no es correcta.",
}));
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
      stubs: { teleport: true },
    },
  });
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  return w.findAll("button").find((b) => b.text() === texto)!;
}

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue(privacidad());
  URL.createObjectURL = vi.fn(() => "blob:datos");
  URL.revokeObjectURL = vi.fn();
});

describe("confirmar con la contraseña en Mi privacidad", () => {
  it("descargar mis datos pide la contraseña; si es incorrecta lo dice y no descarga", async () => {
    const w = montar();
    await flushPromises();
    await boton(w, miPrivacidad.descargar).trigger("click");

    // Se abre la ventana; aún no se pidió nada.
    const ventana = w.get('[data-prueba="confirmar-contrasena"]');
    expect(ventana.text()).toContain(miPrivacidad.confirmarDescargaTexto);
    expect(api.post).not.toHaveBeenCalled();
    const enviar = w.get('button[form="mp-confirmar"]');
    expect(enviar.attributes("disabled")).toBeDefined();

    api.post.mockRejectedValueOnce(new Error("422"));
    await w.get("#mp-contrasena").setValue("otra");
    await ventana.trigger("submit");
    await flushPromises();
    expect(w.get('[data-prueba="error-contrasena"]').text()).toBe(
      "La contraseña no es correcta.",
    );
    expect(URL.createObjectURL).not.toHaveBeenCalled();

    api.post.mockResolvedValueOnce({ data: { data: { persona: {} } } });
    await w.get("#mp-contrasena").setValue("correcta");
    await w.get('[data-prueba="confirmar-contrasena"]').trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith("/api/v1/app/demo/mi/datos", {
      password: "correcta",
    });
    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(w.find('[data-prueba="confirmar-contrasena"]').exists()).toBe(false);
  });

  it("pedir la baja manda el motivo con la contraseña", async () => {
    const w = montar();
    await flushPromises();
    await boton(w, miPrivacidad.baja).trigger("click");
    await w.get("#mp-baja-motivo").setValue("Me mudo");
    await w.get("form").trigger("submit");
    await flushPromises();

    const ventana = w.get('[data-prueba="confirmar-contrasena"]');
    expect(ventana.text()).toContain(miPrivacidad.confirmarBajaTexto);
    expect(api.post).not.toHaveBeenCalled();

    api.post.mockResolvedValueOnce(
      privacidad({
        baja: { estado: "pendiente", solicitada_en: null, respuesta: null },
      }),
    );
    await w.get("#mp-contrasena").setValue("correcta");
    await ventana.trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/privacidad/baja",
      { motivo: "Me mudo", password: "correcta" },
    );
    expect(w.text()).toContain(miPrivacidad.bajaPendiente);
    expect(w.find('[data-prueba="confirmar-contrasena"]').exists()).toBe(false);
  });
});
