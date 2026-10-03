import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import importarClases from "@/i18n/locales/importarClases.es-MX";
import ImportarClasesView from "./ImportarClasesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error de importación",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
function montar() {
  return mount(ImportarClasesView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { importarClases } },
        }),
      ],
      stubs: {
        RouterLink: { template: "<a><slot /></a>" },
        EncabezadoSeccion: true,
      },
    },
  });
}
function resultado(invalidas = 0) {
  return {
    ok: !invalidas,
    confirmacion: invalidas ? undefined : "firma",
    creados: 0,
    lote: null,
    resumen: {
      total: 1,
      validas: invalidas ? 0 : 1,
      invalidas,
      omitidas: 0,
      sesiones: invalidas ? 0 : 1,
    },
    filas: [
      {
        fila: 2,
        datos: {
          referencia: "oct-1",
          clase: "Pole",
          sucursal: "Centro",
          fecha: "2026-10-05",
          inicio: "10:00",
          fin: "11:00",
        },
        errores: invalidas ? ["Horario ocupado"] : [],
        avisos: [],
        omitida: false,
        sesiones: invalidas
          ? []
          : [
              {
                fecha: "2026-10-05",
                inicio: "10:00",
                fin: "11:00",
                estado: "programada",
              },
            ],
      },
    ],
  };
}
async function cargar(w: ReturnType<typeof montar>) {
  const input = w.get('input[type="file"]');
  Object.defineProperty(input.element, "files", {
    configurable: true,
    value: [new File(["csv"], "agenda.csv", { type: "text/csv" })],
  });
  await input.trigger("change");
  await flushPromises();
}
const boton = (w: ReturnType<typeof montar>, texto: string) =>
  w.findAll("button").find((b) => b.text().includes(texto))!;

describe("importación de clases", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({
      data: {
        data: {
          clases: [{ id: "o", nombre: "Pole" }],
          sucursales: [{ id: "s", nombre: "Centro" }],
          instructores: [],
          salas: [],
          max_sesiones: 500,
        },
      },
    });
    api.post.mockResolvedValue({ data: { data: resultado() } });
  });
  it("subir solo revisa y confirmar envía el archivo con su firma", async () => {
    const w = montar();
    await flushPromises();
    await cargar(w);
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post.mock.calls[0]![0]).toBe(
      "/api/v1/app/demo/importaciones/clases/preview",
    );
    expect(w.text()).toContain("2026-10-05");
    api.post.mockResolvedValueOnce({
      data: { data: { ...resultado(), creados: 1, lote: "lote-1" } },
    });
    await boton(w, "Importar 1 sesiones").trigger("click");
    await flushPromises();
    const [url, form] = api.post.mock.calls[1]!;
    expect(url).toBe("/api/v1/app/demo/importaciones/clases");
    expect(form.get("confirmacion")).toBe("firma");
    expect(form.get("modo")).toBe("fechas");
    expect(w.text()).toContain("Se importaron 1 sesiones");
  });
  it("cambiar de modalidad limpia el archivo y la confirmación anterior", async () => {
    const w = montar();
    await flushPromises();
    await cargar(w);
    await boton(w, "Programación semanal").trigger("click");
    expect(w.find(".tabla-preview").exists()).toBe(false);
    expect(w.text()).toContain("desde");
    await cargar(w);
    expect(api.post.mock.calls[1]![1].get("modo")).toBe("semanal");
  });
  it("bloquea la confirmación si hay errores por fila", async () => {
    api.post.mockResolvedValueOnce({ data: { data: resultado(1) } });
    const w = montar();
    await flushPromises();
    await cargar(w);
    expect(w.text()).toContain("Horario ocupado");
    expect(
      boton(w, "Importar 0 sesiones").attributes("disabled"),
    ).toBeDefined();
  });
  it("muestra conflictos surgidos al confirmar y exige otra revisión", async () => {
    const w = montar();
    await flushPromises();
    await cargar(w);
    api.post.mockRejectedValueOnce({
      response: { data: { data: resultado(1) } },
    });
    await boton(w, "Importar 1 sesiones").trigger("click");
    await flushPromises();
    expect(w.text()).toContain("No se importó ninguna sesión");
    expect(w.text()).toContain("Horario ocupado");
    expect(
      boton(w, "Importar 0 sesiones").attributes("disabled"),
    ).toBeDefined();
  });
  it("descarga la plantilla de la modalidad elegida", async () => {
    const w = montar();
    await flushPromises();
    await boton(w, "Programación semanal").trigger("click");
    const crear = vi.fn(() => "blob:plantilla");
    Object.defineProperty(URL, "createObjectURL", {
      configurable: true,
      value: crear,
    });
    Object.defineProperty(URL, "revokeObjectURL", {
      configurable: true,
      value: vi.fn(),
    });
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => {});
    api.get.mockResolvedValueOnce({ data: new Blob(["referencia,clase"]) });
    await boton(w, "Descargar layout").trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/importaciones/clases/plantilla",
      { params: { modo: "semanal" }, responseType: "blob" },
    );
    expect(crear).toHaveBeenCalled();
    click.mockRestore();
  });
});
