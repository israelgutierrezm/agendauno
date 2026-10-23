import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PlantillasWhatsAppNegocio from "./PlantillasWhatsAppNegocio.vue";

/*
| Las plantillas de WhatsApp del negocio (ADR 0190): cuánto gasta cada categoría, el
| editor con su vista previa y el paso a revisión de Meta.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "fluo",
    estudio: { nombre: "Fluo" },
    puede: () => true,
  }),
}));

const URL = "/api/v1/app/fluo/comunicaciones/whatsapp/plantillas";
const META = {
  disponible: true,
  max_cuerpo: 1024,
  cobra: true,
  categorias: [
    { clave: "utilidad", mensajes: 1, precio_minor: 45 },
    { clave: "marketing", mensajes: 5, precio_minor: 225 },
  ],
};
const BORRADOR = {
  id: "p1",
  titulo: "Promo de octubre",
  categoria: "marketing",
  cuerpo: "Hola {{persona_nombre}}, en {{negocio}} hay 2x1 este mes.",
  pie: null,
  estado: "borrador",
  motivo: null,
  editable: true,
};
// El editor es un diálogo: se pinta en su lugar para poder llenarlo.
const global = { plugins: [i18n], stubs: { teleport: true } };

beforeEach(() => {
  vi.clearAllMocks();
});

describe("PlantillasWhatsAppNegocio", () => {
  it("dice cuánto gasta cada categoría y escribe una con su vista previa", async () => {
    api.get.mockResolvedValue({ data: { data: [], meta: META } });
    api.post.mockResolvedValue({ data: { data: BORRADOR } });
    const w = mount(PlantillasWhatsAppNegocio, { global });
    await flushPromises();

    const costos = w.get('[data-prueba="costos-whatsapp"]').text();
    expect(costos).toContain("1 mensaje");
    expect(costos).toContain("5 mensajes");
    expect(costos).toContain("$2.25");

    await w.get('[data-prueba="nueva-plantilla-whatsapp"]').trigger("click");
    await w
      .get('[data-prueba="plantilla-titulo"]')
      .setValue("Promo de octubre");
    await w.get('[data-prueba="plantilla-cuerpo"]').setValue(BORRADOR.cuerpo);
    expect(w.get('[data-prueba="plantilla-vista-previa"]').text()).toContain(
      "Hola Ana, en Fluo hay 2x1",
    );

    await w.get('[data-prueba="editor-plantilla-whatsapp"]').trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(URL, {
      titulo: "Promo de octubre",
      categoria: "marketing",
      cuerpo: BORRADOR.cuerpo,
      pie: null,
    });
  });

  it("un borrador se manda a revisión; una rechazada muestra lo que dijo Meta", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          BORRADOR,
          {
            ...BORRADOR,
            id: "p2",
            estado: "rechazada",
            motivo: "INVALID_FORMAT",
          },
        ],
        meta: META,
      },
    });
    api.post.mockResolvedValue({ data: { data: BORRADOR } });
    const w = mount(PlantillasWhatsAppNegocio, { global });
    await flushPromises();

    expect(w.get('[data-prueba="motivo-plantilla"]').text()).toContain(
      "INVALID_FORMAT",
    );
    await w.get('[data-prueba="enviar-plantilla-whatsapp"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/p1/enviar`);
    expect(w.emitted("cambio")).toHaveLength(1);
  });
});
