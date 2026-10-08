import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DocumentosView from "./DocumentosView.vue";

/*
| El aviso de privacidad del negocio para sus clientes: se ofrece la plantilla
| mientras no lo publique, y una plantilla con campos por llenar no se publica.
*/
enableAutoUnmount(afterEach);

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    estudio: { nombre: "Barbería La Navaja" },
    esCitas: true,
    puede: () => true,
  }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ query: { vista: "consentimientos" } }),
  useRouter: () => ({ push: vi.fn() }),
}));

function montar(waivers: unknown[]) {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: { data: url.endsWith("/waivers") ? waivers : [], meta: {} },
    }),
  );
  return mount(DocumentosView, {
    attachTo: document.body,
    global: {
      plugins: [i18n],
      stubs: { BuscarPersona: true, ZonaArchivo: true, RouterLink: true },
    },
  });
}

describe("aviso de privacidad del negocio", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it("ofrece la plantilla con el negocio y no publica con campos por llenar", async () => {
    const w = montar([]);
    await flushPromises();
    await w.get('[data-prueba="plantilla-aviso"] button').trigger("click");
    await flushPromises();

    const texto = document.querySelector<HTMLTextAreaElement>("#c-contenido")!;
    expect(texto.value).toContain("AVISO DE PRIVACIDAD DE Barbería La Navaja");
    expect(texto.value).toContain("tus citas");
    expect(texto.value).toContain("[DOMICILIO DEL NEGOCIO]");

    document
      .querySelector<HTMLFormElement>("#form-consentimiento")!
      .dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith(
      expect.stringContaining("campos entre corchetes"),
    );
  });

  it("ya publicado, no vuelve a ofrecer la plantilla", async () => {
    const w = montar([
      {
        id: "w1",
        clave: "aviso-privacidad",
        titulo: "Aviso de privacidad",
        contenido: "Texto",
        version: 1,
        publicado_en: "2026-10-01T12:00:00Z",
        firmas: 0,
      },
    ]);
    await flushPromises();
    expect(w.find('[data-prueba="plantilla-aviso"]').exists()).toBe(false);
  });
});
