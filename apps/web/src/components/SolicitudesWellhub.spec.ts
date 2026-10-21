import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import SolicitudesWellhub from "./SolicitudesWellhub.vue";

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: () => "Error" }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockResolvedValue({
    data: {
      data: [
        {
          id: "s1",
          gym_id: "778899",
          gimnasio: "Fluo Roma",
          campos: [{ key: "cms_id", value: "no-existe" }],
          estado: "pendiente",
          nota: null,
          recibida_en: "2030-01-09T15:00:00Z",
        },
      ],
    },
  });
  http.post.mockResolvedValue({ data: {} });
});

describe("gimnasios que eligieron a AgendaUno en Wellhub", () => {
  it("se asignan al negocio por su código", async () => {
    const w = mount(SolicitudesWellhub, {
      props: { apiUrl: "https://api", token: "t" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(w.text()).toContain("Fluo Roma");
    await w.get("#negocio-s1").setValue("fluo");
    await w
      .findAll("button")
      .find((b) => b.text() === "Asignar")!
      .trigger("click");
    await flushPromises();
    expect(http.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/wellhub/solicitudes/s1/asignar",
      { estudio: "fluo" },
    );
  });
});
