import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import IntegrarWebView from "./IntegrarWebView.vue";

/*
| Integrar en mi web (ADR 0141): se arma un widget con su tipo, filtros, tema, color y
| webs; guardado, se ve en vista previa y su código queda listo para copiar. En citas,
| el calendario no se ofrece.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  // Los errores de campo vienen en `campos` de la prueba.
  erroresPorCampo: (e: { campos?: Record<string, string> }) => e.campos ?? {},
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  esCitas: false,
  puede: () => true,
  estudio: { publicado: true } as { publicado: boolean },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const GUARDADO = {
  id: "w1",
  tipo: "calendario",
  nombre: "Horario",
  filtros: { sucursal: "s1", actividad: "a1" },
  tema: "oscuro",
  acento: null,
  dominios: ["www.miestudio.com"],
  activo: true,
};

function montar() {
  return mount(IntegrarWebView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: { template: "<div><slot name='acciones' /></div>" },
        RouterLink: true,
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.esCitas = false;
  sesion.estudio = { publicado: true };
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/captacion/widgets")
          ? []
          : url.endsWith("/sucursales")
            ? [{ id: "s1", nombre: "Roma Norte" }]
            : { filtros: { actividades: [{ id: "a1", nombre: "Pole" }] } },
      },
    }),
  );
  api.post.mockResolvedValue({ data: { data: GUARDADO } });
});

describe("integrar en mi web", () => {
  it("arma un calendario con sus filtros, tema y web; guardado, da vista previa y código", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="nuevo-widget"]').trigger("click");
    await w.get('[data-prueba="nombre-widget"]').setValue("Horario");
    const [sede, actividad] = w.findAll('[data-prueba="editor-widget"] select');
    await sede!.setValue("s1");
    await actividad!.setValue("a1");
    await w
      .findAll("button")
      .find((b) => b.text() === "Oscuro")!
      .trigger("click");
    await w
      .get('[data-prueba="dominio-widget"]')
      .setValue("https://www.miestudio.com/clases");
    await w.get('[data-prueba="editor-widget"]').trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/captacion/widgets",
      {
        tipo: "calendario",
        nombre: "Horario",
        filtros: { sucursal: "s1", actividad: "a1" },
        tema: "oscuro",
        acento: null,
        dominios: ["https://www.miestudio.com/clases"],
        activo: true,
      },
    );
    expect(w.get('[data-prueba="previa-widget"]').attributes("src")).toContain(
      "/w/demo/w1?vista_previa=1",
    );
    const codigo = (
      w.get('[data-prueba="codigo-widget"]').element as HTMLTextAreaElement
    ).value;
    expect(codigo).toContain('<iframe src="');
    expect(codigo).toContain("/w/demo/w1");
  });

  it("en citas se ofrece agendar cita y planes, no el calendario", async () => {
    sesion.esCitas = true;
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="nuevo-widget"]').trigger("click");
    const tipos = w
      .get('[data-prueba="editor-widget"] .tu-segmentado')
      .findAll("button")
      .map((b) => b.text());
    expect(tipos).toEqual(["Agendar cita", "Planes"]);
    // Agendar cita, de entrada.
    const agendar = w
      .get('[data-prueba="editor-widget"] .tu-segmentado')
      .findAll("button")
      .find((b) => b.text() === "Agendar cita")!;
    expect(agendar.attributes("aria-pressed")).toBe("true");
    expect(w.text()).toContain("agenda (y paga, si lo pides) sin cuenta");
  });

  it("con la página del negocio oculta avisa que los widgets no se muestran", async () => {
    sesion.estudio = { publicado: false };
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: { data: url.endsWith("/captacion/widgets") ? [GUARDADO] : [] },
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="widgets-pagina-oculta"]').text()).toContain(
      "Tu página está oculta",
    );

    // Publicada, no hay aviso.
    sesion.estudio = { publicado: true };
    const otra = montar();
    await flushPromises();
    expect(otra.find('[data-prueba="widgets-pagina-oculta"]').exists()).toBe(
      false,
    );
  });

  it("si no se cargan las sucursales lo dice (no es una lista vacía) y deja reintentar", async () => {
    let falla = true;
    api.get.mockImplementation((url: string) =>
      url.endsWith("/sucursales") && falla
        ? Promise.reject(new Error("red"))
        : Promise.resolve({
            data: {
              data: url.endsWith("/captacion/widgets")
                ? []
                : url.endsWith("/sucursales")
                  ? [{ id: "s1", nombre: "Roma Norte" }]
                  : { filtros: { actividades: [] } },
            },
          }),
    );
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="nuevo-widget"]').trigger("click");

    expect(w.get('[data-prueba="error-opciones-widget"]').text()).toContain(
      "No se pudieron cargar las sucursales",
    );
    expect(
      w.get('[data-prueba="editor-widget"] select').attributes("disabled"),
    ).toBeDefined();

    falla = false;
    await w
      .get('[data-prueba="error-opciones-widget"] button')
      .trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-opciones-widget"]').exists()).toBe(
      false,
    );
    expect(w.text()).toContain("Roma Norte");
  });

  it("el error al guardar queda junto a su campo y lo capturado no se pierde", async () => {
    api.post.mockRejectedValueOnce({
      campos: { "dominios.0": "Escribe una dirección web válida." },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="nuevo-widget"]').trigger("click");
    await w.get('[data-prueba="nombre-widget"]').setValue("Horario");
    await w.get('[data-prueba="dominio-widget"]').setValue("no es web");
    await w.get('[data-prueba="editor-widget"]').trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="error-dominios"]').text()).toBe(
      "Escribe una dirección web válida.",
    );
    expect(
      (w.get('[data-prueba="dominio-widget"]').element as HTMLInputElement)
        .value,
    ).toBe("no es web");
  });
});
