import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import AvisoPrivacidadView from "./AvisoPrivacidadView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api: mocks }));
const montar = () =>
  mount(AvisoPrivacidadView, {
    global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
  });
beforeEach(() => vi.clearAllMocks());
describe("página del aviso", () => {
  it("consulta la misma fuente que el registro y muestra el documento vigente", async () => {
    mocks.get.mockResolvedValue({
      data: { data: { aviso_privacidad: "Documento vigente" } },
    });
    const vista = montar();
    expect(vista.text()).toContain("Cargando el documento");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/legales");
    expect(vista.text()).toContain("Documento vigente");
    expect(vista.text()).not.toContain("Borrador para revisión");
    vista.unmount();
  });
  it("dice qué versión publicada se ve y desde cuándo rige", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: {
          aviso_privacidad: "Documento vigente",
          versiones: {
            aviso_privacidad: {
              version: 3,
              vigente_desde: "2026-10-01T12:00:00Z",
            },
          },
        },
      },
    });
    const vista = montar();
    await flushPromises();
    expect(vista.text()).toContain(
      "Versión 3 · vigente desde el 1 de octubre de 2026",
    );
    vista.unmount();
  });
  it("no sustituye un error de red por un borrador y permite reintentar", async () => {
    mocks.get.mockRejectedValueOnce(new Error("Sin conexión"));
    const vista = montar();
    await flushPromises();
    expect(vista.get('[role="alert"]').text()).toContain(
      "No pudimos consultar",
    );
    expect(vista.find(".aviso-texto").exists()).toBe(false);
    mocks.get.mockResolvedValueOnce({
      data: { data: { aviso_privacidad: "Aviso recuperado" } },
    });
    await vista.get("button").trigger("click");
    await flushPromises();
    expect(vista.text()).toContain("Aviso recuperado");
    expect(vista.find('[role="alert"]').exists()).toBe(false);
    vista.unmount();
  });
});
