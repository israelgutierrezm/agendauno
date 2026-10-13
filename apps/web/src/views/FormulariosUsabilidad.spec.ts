import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { i18n } from "@/i18n";
import FormulariosView from "./FormulariosView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  puede: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: mocks,
  mensajeDeError: () => "No se pudo crear",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: mocks.puede }),
}));
beforeEach(() => {
  vi.clearAllMocks();
  mocks.puede.mockReturnValue(true);
  mocks.get.mockResolvedValue({ data: { data: [] } });
});
const fichaSalud = {
  id: "salud",
  nombre: "Cuestionario de salud",
  descripcion: null,
  aplica_a: "miembro",
  activo: true,
  campos: [
    {
      id: "c1",
      etiqueta: "Contacto de emergencia",
      tipo: "texto",
      obligatorio: true,
      opciones: null,
      orden: 0,
    },
  ],
};
async function montar(vista = "") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        name: "formularios",
        path: "/formularios",
        component: { template: "<div />" },
      },
    ],
  });
  await router.push({ name: "formularios", query: vista ? { vista } : {} });
  const w = mount(FormulariosView, {
    global: { plugins: [router, i18n], stubs: { Teleport: true } },
  });
  await flushPromises();
  return w;
}
describe("alta de fichas sin estorbar al diseñador", () => {
  it("al ir a respuestas mantiene la ficha elegida, no cambia a la primera", async () => {
    mocks.get.mockImplementation(async (url: string) => ({
      data: {
        data: url.endsWith("/respuestas")
          ? []
          : [
              fichaSalud,
              { ...fichaSalud, id: "otra", nombre: "Encuesta de satisfacción" },
            ],
      },
    }));
    const w = await montar();
    await w
      .get('button[aria-label="Gestionar ficha: Encuesta de satisfacción"]')
      .trigger("click");
    await w.vm.$router.push({
      name: "formularios",
      query: { vista: "respuestas" },
    });
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/formularios/otra/respuestas",
    );
    expect(
      w.findAll("button").some((b) => b.text() === "Agregar pregunta"),
    ).toBe(false);
    w.unmount();
  });
  it("abre una ventana con campos etiquetados, conserva los datos si falla y permite cancelar", async () => {
    mocks.post.mockRejectedValue(new Error("error"));
    const w = await montar();
    expect(w.find("#nombre-ficha").exists()).toBe(false);
    await w.get('[data-prueba="nueva-ficha"]').trigger("click");
    expect(w.get("#nombre-ficha").attributes("placeholder")).toBe(
      "Ejemplo: Ficha médica",
    );
    expect(w.get('label[for="destinatarios-ficha"]').text()).toBe(
      "A quién se aplica",
    );
    await w.get("#nombre-ficha").setValue("Encuesta de satisfacción");
    await w.get('[role="dialog"] form').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/formularios", {
      nombre: "Encuesta de satisfacción",
      aplica_a: "miembro",
    });
    expect(w.get('[role="alert"]').text()).toBe("No se pudo crear");
    expect((w.get("#nombre-ficha").element as HTMLInputElement).value).toBe(
      "Encuesta de satisfacción",
    );
    await w
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    w.unmount();
  });
  it("primero lista las fichas y solo abre la gestión al elegirla explícitamente", async () => {
    mocks.get.mockResolvedValue({ data: { data: [fichaSalud] } });
    const w = await montar();
    expect(w.text()).toContain("Cuestionario de salud");
    expect(w.text()).toContain("1 campo");
    expect(w.text()).toContain("Activa");
    expect(w.text()).not.toContain("Contacto de emergencia");
    await w
      .get('button[aria-label="Gestionar ficha: Cuestionario de salud"]')
      .trigger("click");
    expect(w.text()).toContain("Gestionando: Cuestionario de salud");
    expect(w.text()).toContain("Contacto de emergencia");
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    await w
      .findAll("button")
      .find((b) => b.text() === "Agregar pregunta")!
      .trigger("click");
    expect(w.get('[role="dialog"]').text()).toContain(
      "«Cuestionario de salud»",
    );
    await w.get('[role="dialog"] input[required]').setValue("Alergias");
    mocks.post.mockRejectedValue(new Error("fallo"));
    await w.get('[role="dialog"] form').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/formularios/salud/campos",
      {
        etiqueta: "Alergias",
        tipo: "texto",
        obligatorio: false,
        opciones: null,
      },
    );
    expect(w.get('[role="dialog"]').text()).toContain("No se pudo crear");
    expect(
      (w.get('[role="dialog"] input[required]').element as HTMLInputElement)
        .value,
    ).toBe("Alergias");
    await w
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    await w
      .findAll("button")
      .find((b) => b.text() === "Volver a las fichas")!
      .trigger("click");
    expect(
      w
        .find('button[aria-label="Gestionar ficha: Cuestionario de salud"]')
        .exists(),
    ).toBe(true);
    expect(w.text()).not.toContain("Contacto de emergencia");
    w.unmount();
  });
  it("el modo lectura no ofrece el editor de preguntas", async () => {
    mocks.get.mockResolvedValue({ data: { data: [fichaSalud] } });
    mocks.puede.mockReturnValue(false);
    const w = await montar();
    await w
      .get('button[aria-label="Ver campos: Cuestionario de salud"]')
      .trigger("click");
    expect(w.text()).toContain("Contacto de emergencia");
    expect(
      w.findAll("button").some((b) => b.text() === "Agregar pregunta"),
    ).toBe(false);
    w.unmount();
  });
  it("no ofrece el alta al revisar respuestas ni sin permiso de gestión", async () => {
    const respuestas = await montar("respuestas");
    expect(respuestas.find('[data-prueba="nueva-ficha"]').exists()).toBe(false);
    respuestas.unmount();
    mocks.puede.mockReturnValue(false);
    const lectura = await montar();
    expect(lectura.find('[data-prueba="nueva-ficha"]').exists()).toBe(false);
    lectura.unmount();
  });
});
