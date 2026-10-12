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
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  esCitas: false,
  puede: () => true,
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
    global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.esCitas = false;
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

  it("en citas solo hay planes: el calendario no se ofrece", async () => {
    sesion.esCitas = true;
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="nuevo-widget"]').trigger("click");
    const calendario = w
      .findAll('[data-prueba="editor-widget"] button')
      .find((b) => b.text() === "Calendario de clases")!;
    expect(calendario.attributes("disabled")).toBeDefined();
    expect(w.text()).toContain("El calendario es para negocios de clases.");
  });
});
