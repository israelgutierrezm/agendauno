import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import AvisosAgendaUno from "./AvisosAgendaUno.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const URL = "/api/v1/app/demo/avisos-plataforma";

function avisos(whatsapp: Record<string, unknown> | null) {
  return { data: { data: { correo: "duena@correo.mx", whatsapp } } };
}

function montar() {
  return mount(AvisosAgendaUno, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("avisos de AgendaUno al dueño", () => {
  it("sin WhatsApp en la plataforma solo dice a qué correo le llegan", async () => {
    api.get.mockResolvedValue(avisos(null));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("duena@correo.mx");
    expect(w.find('[data-prueba="estado-whatsapp"]').exists()).toBe(false);
  });

  it("verifica su número con el código y queda recibiéndolos por WhatsApp", async () => {
    api.get.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: false, acepta: false }),
    );
    api.post.mockImplementation((url: string) =>
      Promise.resolve(
        url.endsWith("/verificar")
          ? avisos({
              numero: "+52 5512345678",
              verificado: true,
              acepta: true,
            })
          : { data: { data: { enviado: true } } },
      ),
    );
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="estado-whatsapp"]').text()).toBe(
      "Sin verificar",
    );

    await w.get('[data-prueba="verificar-whatsapp"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/whatsapp/codigo`, {});

    await w.get("#aa-codigo").setValue("123456");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/whatsapp/verificar`, {
      codigo: "123456",
    });
    expect(w.get('[data-prueba="estado-whatsapp"]').text()).toBe("Verificado");
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it("puede dejar de recibirlos por WhatsApp", async () => {
    api.get.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: true, acepta: true }),
    );
    api.put.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: true, acepta: false }),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="acepta-whatsapp"]').setValue(false);
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(URL, { acepta_whatsapp: false });
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(false);
  });
});
