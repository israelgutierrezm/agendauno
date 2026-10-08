import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import TerminosView from "./TerminosView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api: mocks }));
const montar = () =>
  mount(TerminosView, {
    global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
  });
beforeEach(() => vi.clearAllMocks());
afterEach(() => vi.unstubAllEnvs());

describe("página de los términos", () => {
  it("muestra los términos vigentes, los mismos que se aceptan al registrarse", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: {
          terminos: "1. El servicio\n\nTexto de los términos",
          versiones: {
            terminos: { version: 2, vigente_desde: "2026-10-01T12:00:00Z" },
          },
        },
      },
    });
    const vista = montar();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/legales");
    expect(vista.text()).toContain("Texto de los términos");
    expect(vista.text()).toContain("Versión 2");
    vista.unmount();
  });

  it("sin publicar, lo dice con un contacto; si falla, deja reintentar", async () => {
    // En producción (en desarrollo se ve el borrador).
    vi.stubEnv("DEV", false);
    mocks.get.mockResolvedValueOnce({ data: { data: { terminos: null } } });
    const vista = montar();
    await flushPromises();
    expect(vista.text()).toContain("aún no están publicados");
    expect(vista.get("a[href^='mailto:']").text()).toBe("hola@agendauno.mx");
    vista.unmount();

    mocks.get.mockRejectedValueOnce(new Error("red"));
    const otra = montar();
    await flushPromises();
    expect(otra.get('[role="alert"]').text()).toContain("Intenta de nuevo");
    otra.unmount();
  });
});
