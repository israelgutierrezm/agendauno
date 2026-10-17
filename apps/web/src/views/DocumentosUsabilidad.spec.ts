import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { i18n } from "@/i18n";
import DocumentosView from "./DocumentosView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  puede: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: mocks,
  mensajeDeError: () => "No se pudo guardar",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: mocks.puede }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ query: { vista: "requisitos" } }),
  useRouter: () => ({ push: vi.fn() }),
  onBeforeRouteLeave: vi.fn(),
}));
const requisito = {
  id: "t1",
  nombre: "Identificación",
  descripcion: null,
  obligatorio: true,
  aplica_a: "miembro",
  activo: true,
};
beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
  mocks.puede.mockReturnValue(true);
  mocks.get.mockImplementation(async (url: string) => ({
    data: { data: url.endsWith("/tipos-documento") ? [requisito] : [] },
  }));
});
async function montar() {
  const w = mount(DocumentosView, {
    global: { plugins: [i18n], stubs: { Teleport: true, RouterLink: true } },
  });
  await flushPromises();
  return w;
}
describe("requisitos: listado y edición explícita", () => {
  it("no muestra formularios de alta o edición hasta solicitarlos", async () => {
    const w = await montar();
    expect(w.text()).toContain("Identificación");
    expect(w.find("form").exists()).toBe(false);
    await w
      .findAll("button")
      .find((b) => b.text() === "Editar")!
      .trigger("click");
    expect(w.get('[role="dialog"]').attributes("aria-label")).toBe(
      "Editar requisito: Identificación",
    );
    await w
      .get('[role="dialog"] input[required]')
      .setValue("Documento oficial");
    mocks.put.mockResolvedValue({ data: {} });
    await w.get('[role="dialog"] form').trigger("submit");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/tipos-documento/t1",
      {
        nombre: "Documento oficial",
        descripcion: null,
        obligatorio: true,
        aplica_a: "miembro",
        activo: true,
      },
    );
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    w.unmount();
  });
  it("el alta conserva datos al fallar y cancelar no agrega requisitos", async () => {
    const w = await montar();
    await w
      .findAll("button")
      .find((b) => b.text() === "Nuevo requisito documental")!
      .trigger("click");
    await w.get('[role="dialog"] input[required]').setValue("Certificado");
    mocks.post.mockRejectedValue(new Error("fallo"));
    await w.get('[role="dialog"] form').trigger("submit");
    await flushPromises();
    expect(w.get('[role="dialog"]').text()).toContain("No se pudo guardar");
    expect(
      (w.get('[role="dialog"] input[required]').element as HTMLInputElement)
        .value,
    ).toBe("Certificado");
    await w
      .get('[role="dialog"]')
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    expect(mocks.post).toHaveBeenCalledTimes(1);
    w.unmount();
  });
  it("la vista de consulta no ofrece acciones de edición", async () => {
    mocks.puede.mockReturnValue(false);
    const w = await montar();
    expect(
      w
        .findAll("button")
        .some((b) =>
          ["Editar", "Nuevo requisito documental"].includes(b.text()),
        ),
    ).toBe(false);
    w.unmount();
  });
});
