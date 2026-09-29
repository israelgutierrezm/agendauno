import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import SucursalesView from "./SucursalesView.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const roma = {
  id: "roma",
  nombre: "Roma Norte",
  zona_horaria: "America/Mexico_City",
  region: null,
  moneda: "MXN",
  impuesto_tasa_bps: 1600,
  direccion: "Av. Álvaro Obregón 120",
  foto_url: "/storage/roma.webp",
  mapa_url: "https://maps.app.goo.gl/roma",
};

async function montar() {
  api.get.mockResolvedValue({
    data: { data: [{ id: "org", nombre: "Org", sucursales: [roma] }] },
  });
  const w = mount(SucursalesView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { perfilPublico } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: true,
        PanelLateral: { template: "<div><slot /><slot name='pie' /></div>" },
      },
    },
  });
  await flushPromises();
  return w;
}

beforeEach(() => vi.clearAllMocks());

describe("sedes: foto y enlace de Google Maps", () => {
  it("al editar muestra su foto para cambiarla y guarda el enlace de Maps", async () => {
    api.put.mockResolvedValue({ data: { data: roma } });
    const w = await montar();
    // La lista muestra la miniatura de la sede.
    expect(w.find('li img[src="/storage/roma.webp"]').exists()).toBe(true);

    await w
      .findAll("button")
      .find((b) => b.text() === "sedes.editar")!
      .trigger("click");
    const foto = w.get('[data-prueba="foto-sede"]');
    expect(foto.find('img[src="/storage/roma.webp"]').exists()).toBe(true);
    expect(foto.text()).toContain("Quitar foto");
    expect((w.get("#s-mapa").element as HTMLInputElement).value).toBe(
      "https://maps.app.goo.gl/roma",
    );
    // La arroba del ejemplo de redes se ve tal cual (no es un mensaje enlazado).
    expect(w.find('input[placeholder="@tu_negocio"]').exists()).toBe(true);

    await w.get("#s-mapa").setValue("maps.app.goo.gl/otra");
    await w
      .findAll("button")
      .find((b) => b.text() === "comun.guardar")!
      .trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/sucursales/roma",
      expect.objectContaining({ mapa_url: "maps.app.goo.gl/otra" }),
    );
  });

  it("una sede nueva pide guardarla antes de subir su foto", async () => {
    const w = await montar();
    await w
      .findAll("button")
      .find((b) => b.text() === "sedes.agregar")!
      .trigger("click");
    const foto = w.get('[data-prueba="foto-sede"]');
    expect(foto.text()).toContain("Guarda la sede para agregar su foto.");
    expect(foto.find('input[type="file"]').exists()).toBe(false);
  });

  it("muestra el error del enlace que no es de Google Maps", async () => {
    api.put.mockRejectedValue({
      response: {
        data: {
          meta: {
            errors: {
              mapa_url: [
                "Pega el enlace que da Google Maps al compartir la ubicación de la sede.",
              ],
            },
          },
        },
      },
    });
    const w = await montar();
    await w
      .findAll("button")
      .find((b) => b.text() === "sedes.editar")!
      .trigger("click");
    await w.get("#s-mapa").setValue("https://maps.ejemplo.com/x");
    await w
      .findAll("button")
      .find((b) => b.text() === "comun.guardar")!
      .trigger("click");
    await flushPromises();
    expect(w.text()).toContain(
      "Pega el enlace que da Google Maps al compartir la ubicación de la sede.",
    );
  });
});
