import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AvisoNegocioView from "./AvisoNegocioView.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  noEncontrado: (e: unknown) =>
    (e as { response?: { status?: number } }).response?.status === 404,
}));
vi.mock("@/lib/seo", () => ({ updateSeo: vi.fn() }));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
  useRoute: () => ({ params: { slug: "navaja" } }),
}));

beforeEach(() => vi.clearAllMocks());

describe("aviso de privacidad de un negocio", () => {
  it("muestra el aviso publicado del negocio, con su versión", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          negocio: "La Navaja",
          titulo: "Aviso de privacidad",
          contenido: "1. Responsable\n\nLa Navaja trata tus datos.",
          version: 2,
          publicado_en: "2026-10-01T12:00:00Z",
        },
      },
    });
    const w = mount(AvisoNegocioView);
    await flushPromises();
    expect(api.get).toHaveBeenCalledWith("/api/v1/app/navaja/aviso-privacidad");
    expect(w.get("h1").text()).toBe("Aviso de privacidad");
    expect(w.text()).toContain("La Navaja trata tus datos.");
    expect(w.text()).toContain("Versión 2");
  });

  it("si no lo ha publicado, lo dice", async () => {
    api.get.mockRejectedValue({ response: { status: 404 } });
    const w = mount(AvisoNegocioView);
    await flushPromises();
    expect(w.text()).toContain("aún no publica su aviso de privacidad");
  });
});
