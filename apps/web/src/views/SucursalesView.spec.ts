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
  useSesionTenantStore: () => ({
    slug: "demo",
    pais: "MX",
    lada: "52",
    puede: () => true,
  }),
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

async function montar(sede: Record<string, unknown> = roma) {
  api.get.mockResolvedValue({
    data: { data: [{ id: "org", nombre: "Org", sucursales: [sede] }] },
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
    expect(
      w.find('[data-prueba="sucursal"] img[src="/storage/roma.webp"]').exists(),
    ).toBe(true);

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

describe("sedes: teléfono con lada y zona horaria", () => {
  it("el teléfono y el WhatsApp llevan lada; lo guardado sin «+» se queda si no se toca", async () => {
    api.put.mockResolvedValue({ data: { data: roma } });
    const w = await montar({
      ...roma,
      telefono: "5512345678",
      whatsapp: "+57 3001234567",
    });
    await w
      .findAll("button")
      .find((b) => b.text() === "sedes.editar")!
      .trigger("click");

    const ladas = w
      .findAll('[data-prueba="lada-celular"]')
      .map((l) => l.text());
    // Sin «+», con la lada del negocio; con «+», la suya.
    expect(ladas).toEqual(["MX +52", "CO +57"]);
    expect((w.get("#s-telefono").element as HTMLInputElement).value).toBe(
      "5512345678",
    );
    expect((w.get("#s-whatsapp").element as HTMLInputElement).value).toBe(
      "3001234567",
    );

    // Las zonas con buscador: las del país del negocio primero.
    const zona = w.get("#s-zona");
    expect((zona.element as HTMLInputElement).value).toBe(
      "Centro de México (Ciudad de México)",
    );
    await zona.trigger("click");
    expect(w.findAll('[role="option"]')[0].text()).toContain(
      "Centro de México",
    );
    await zona.setValue("chicago");
    await zona.trigger("keydown", { key: "Enter" });

    await w.get("#s-telefono").setValue("55 8888 9999");
    await w
      .findAll("button")
      .find((b) => b.text() === "comun.guardar")!
      .trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/sucursales/roma",
      expect.objectContaining({
        telefono: "+52 5588889999",
        whatsapp: "+57 3001234567",
        zona_horaria: "America/Chicago",
      }),
    );
    w.unmount();
  });
});
