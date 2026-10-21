import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import SitioCompartir from "./SitioCompartir.vue";
import SitioDominio from "./SitioDominio.vue";
import SitioReservasVentas from "./SitioReservasVentas.vue";

/*
| Las pestañas del sitio en Captación (ADR 0167): lo que se muestra sale de los
| módulos del negocio (sin otro catálogo), cómo se comparte y cómo se publica.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
const sesion = vi.hoisted(() => ({
  slug: "barberia",
  esCitas: true,
  moneda: "MXN",
  pais: "MX",
  estudio: { nombre: "Barbería", publicado: true, en_directorio: true },
  puede: () => true,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => true }));

const global = {
  plugins: [i18n],
  stubs: {
    RouterLink: {
      props: ["to"],
      template: '<a :data-ruta="to.name"><slot /></a>',
    },
    SeoPaginaSitio: true,
    DominioPropioSitio: { template: '<div data-prueba="dominio-propio" />' },
    EnlaceEstudio: true,
    IntegrarWebView: true,
  },
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe("reservas y ventas", () => {
  const secciones = [
    { tipo: "agenda", visible: true },
    { tipo: "servicios", visible: true },
    { tipo: "precios", visible: false },
    { tipo: "equipo", visible: true },
    { tipo: "sucursales", visible: true },
  ];

  it("lo que se muestra sale de cada módulo y se edita en su lugar", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          servicios: [
            {
              id: "s1",
              nombre: "Corte de cabello",
              precio_minor: 25000,
              moneda: "MXN",
              duracion_minutos: 30,
            },
            { id: "s2", nombre: "Corte con bono", duracion_minutos: 30 },
          ],
          productos: [],
          instructores: [{ nombre: "Leo" }],
          sucursales: [{ id: "r1", nombre: "Roma", direccion: "Colima 1" }],
          horario_clases: [],
          captacion: { activa: false },
        },
      },
    });
    const w = mount(SitioReservasVentas, {
      props: {
        secciones,
        ocultos: { servicios: ["s2"], productos: [], equipo: [] },
      },
      global,
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/barberia/sitio/oferta");
    const servicios = w.get('[data-prueba="sitio-grupo-servicios"]');
    expect(servicios.text()).toContain("Corte de cabello");
    expect(servicios.text()).toContain("$250");
    expect(servicios.text()).toContain("30 min");
    expect(
      servicios.get('[data-prueba="editar-servicios"]').attributes("data-ruta"),
    ).toBe("catalogo");
    // En citas, los bonos (oculta su sección) y sin horario semanal.
    expect(w.find('[data-prueba="sitio-grupo-horario"]').exists()).toBe(false);
    expect(
      w.get('[data-prueba="mostrar-precios"]').attributes("aria-checked"),
    ).toBe("false");

    await w.get('[data-prueba="mostrar-servicios"]').trigger("click");
    expect(w.emitted("mostrar")).toEqual([["servicios", false]]);

    // Cada servicio se ve o no (lo oculto sigue a la venta): se decide aquí.
    expect(
      w.get('[data-prueba="ver-servicios-s2"]').attributes("aria-checked"),
    ).toBe("false");
    await w.get('[data-prueba="ver-servicios-s1"]').trigger("click");
    await w.get('[data-prueba="ver-servicios-s2"]').trigger("click");
    expect(w.emitted("ocultar")).toEqual([
      ["servicios", "s1", true],
      ["servicios", "s2", false],
    ]);
    // Las sucursales se ven todas.
    expect(
      w
        .find(
          '[data-prueba="sitio-grupo-sucursales"] [role="switch"][data-prueba^="ver-"]',
        )
        .exists(),
    ).toBe(false);
  });
});

describe("compartir", () => {
  it("el enlace de la página de enlaces del negocio y sus widgets", () => {
    const w = mount(SitioCompartir, { global });
    expect(w.get('[data-prueba="pagina-enlaces"]').text()).toContain(
      "barberia.",
    );
    expect(w.get('[data-prueba="pagina-enlaces"]').text()).toContain(
      "/enlaces",
    );
    expect(w.findComponent({ name: "IntegrarWebView" }).exists()).toBe(true);
  });
});

describe("dominio y SEO", () => {
  const props = {
    seo: { titulo: null, descripcion: null, imagen_url: null },
    catalogo: { max_seo_titulo: 70, max_seo_descripcion: 160 },
    errores: {},
    publicacion: { publicado: true, privado: false },
    paginas: 0,
    conPlus: true,
  };

  it("cambia cómo se publica al momento: solo con el enlace", async () => {
    api.put.mockResolvedValue({
      data: { data: { publicado: true, privado: true, en_directorio: false } },
    });
    const w = mount(SitioDominio, { props, global });
    expect(
      w.get('[data-prueba="guardar-visibilidad"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="visibilidad-enlace"]').setValue();
    await w.get('[data-prueba="guardar-visibilidad"]').trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/barberia/publicacion", {
      publicado: true,
      privado: true,
    });
    expect(confirmar).not.toHaveBeenCalled();
    expect(w.emitted("publicacion")).toEqual([
      [{ publicado: true, privado: true }],
    ]);
  });

  it("sin permiso para publicar se ve cómo está, pero no se cambia", () => {
    const w = mount(SitioDominio, {
      props: { ...props, puedePublicar: false },
      global,
    });
    expect(w.find('[data-prueba="guardar-visibilidad"]').exists()).toBe(false);
    // Las opciones van en un grupo deshabilitado.
    expect(w.get("fieldset").attributes("disabled")).toBeDefined();
  });

  it("cerrarla se confirma; el dominio propio va en su tarjeta", async () => {
    confirmar.mockResolvedValueOnce(false);
    const w = mount(SitioDominio, {
      props: { ...props, conPlus: false },
      global,
    });

    await w.get('[data-prueba="visibilidad-cerrada"]').setValue();
    await w.get('[data-prueba="guardar-visibilidad"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
    expect(w.find('[data-prueba="dominio-propio"]').exists()).toBe(true);
  });
});
