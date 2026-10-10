import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import SitioWebView from "./SitioWebView.vue";

/*
| El constructor del sitio (ADR 0114): ordena y oculta secciones, cambia de plantilla
| conservando textos, agrega banners, guarda el borrador y publica (con confirmación,
| guardando antes lo que está en pantalla).
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "fluo",
    esCitas: false,
    estudio: { nombre: "Fluō Pilates" },
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
}));

const ORDEN = [
  "inicio",
  "promociones",
  "nosotros",
  "agenda",
  "servicios",
  "horario",
  "precios",
  "equipo",
  "resenas",
  "sucursales",
  "contacto",
];

function datos(cambios: Record<string, unknown> = {}) {
  return {
    borrador: {
      plantilla: "esencial",
      secciones: ORDEN.map((tipo) => ({
        tipo,
        visible: true,
        titulo: tipo === "nosotros" ? "Quiénes somos" : null,
        texto: null,
        foto_url: null,
      })),
      banners: [],
    },
    cambios_sin_publicar: false,
    publicado_en: null,
    pagina_publica: true,
    tiene_portada: false,
    catalogo: {
      plantillas: [
        { clave: "esencial", orden: ORDEN.slice(1, -1) },
        {
          clave: "portada",
          orden: [
            "promociones",
            "servicios",
            "equipo",
            "nosotros",
            "agenda",
            "horario",
            "precios",
            "resenas",
            "sucursales",
          ],
        },
        { clave: "compacta", orden: ORDEN.slice(1, -1) },
      ],
      con_texto: ["inicio", "nosotros", "contacto"],
      con_foto: ["nosotros"],
      max_titulo: 80,
      max_texto: 2000,
      banners_maximos: 2,
    },
    ...cambios,
  };
}

function montar() {
  return mount(SitioWebView, {
    attachTo: document.body,
    global: { plugins: [i18n], stubs: { CargadorImagen: true } },
  });
}
type Vista = ReturnType<typeof montar>;

function orden(w: Vista): string[] {
  return w
    .findAll('[data-prueba^="seccion-"]')
    .map((li) => li.attributes("data-prueba")!.replace("seccion-", ""));
}

describe("constructor del sitio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: datos() } });
    api.put.mockImplementation((_url: string, cuerpo: unknown) =>
      Promise.resolve({
        data: {
          data: datos({ borrador: cuerpo, cambios_sin_publicar: true }),
        },
      }),
    );
    api.post.mockResolvedValue({
      data: { data: datos({ publicado_en: "2026-10-10T12:00:00Z" }) },
    });
  });

  it("ordena y oculta secciones; la portada y el contacto no se mueven", async () => {
    const w = montar();
    await flushPromises();
    expect(orden(w)).toEqual(ORDEN);
    expect(w.get('[data-prueba="subir-inicio"]').attributes()).toHaveProperty(
      "disabled",
    );
    expect(w.find('[data-prueba="visible-inicio"]').exists()).toBe(false);
    expect(
      w.get('[data-prueba="subir-promociones"]').attributes(),
    ).toHaveProperty("disabled");

    await w.get('[data-prueba="bajar-nosotros"]').trigger("click");
    expect(orden(w).slice(1, 4)).toEqual(["promociones", "agenda", "nosotros"]);
    await w.get('[data-prueba="visible-resenas"]').setValue(false);

    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    const enviado = api.put.mock.calls[0][1];
    expect(enviado.secciones.map((s: { tipo: string }) => s.tipo)).toEqual(
      orden(w),
    );
    expect(
      enviado.secciones.find((s: { tipo: string }) => s.tipo === "resenas")
        .visible,
    ).toBe(false);
    w.unmount();
  });

  it("otra plantilla cambia el orden y conserva los textos", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="plantilla-portada"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(orden(w).slice(0, 4)).toEqual([
      "inicio",
      "promociones",
      "servicios",
      "equipo",
    ]);
    await w.get('[data-prueba="editar-nosotros"]').trigger("click");
    expect(
      (w.get("#sw-titulo-nosotros").element as HTMLInputElement).value,
    ).toBe("Quiénes somos");
    w.unmount();
  });

  it("agrega banners hasta su tope", async () => {
    const w = montar();
    await flushPromises();
    const agregar = w.get('[data-prueba="agregar-banner"]');
    await agregar.trigger("click");
    await agregar.trigger("click");
    expect(w.findAll('[data-prueba^="banner-"]')).toHaveLength(2);
    expect(agregar.attributes()).toHaveProperty("disabled");
    expect(w.text()).toContain("Puedes tener hasta 2 banners.");
    w.unmount();
  });

  it("publicar confirma y guarda antes lo que está en pantalla", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="bajar-nosotros"]').trigger("click");
    await w.get('[data-prueba="publicar-sitio"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.put).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/fluo/sitio/publicar");
    expect(w.get('[data-prueba="estado-sitio"]').text()).toContain("Publicado");
    w.unmount();
  });

  it("avisa si la página no está abierta al público", async () => {
    api.get.mockResolvedValue({
      data: { data: datos({ pagina_publica: false }) },
    });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="pagina-oculta"]').exists()).toBe(true);
    // La vista previa es la del borrador, en el mismo origen que el panel.
    expect(w.get("iframe").attributes("src")).toBe(
      "/estudio/fluo/vista-previa",
    );
    w.unmount();
  });
});
