import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AsistenteView from "./AsistenteView.vue";

/*
| El asistente sobre los datos del negocio (ADR 0196): pregunta, contesta y lo que
| propone se aplica solo tras revisarlo.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "fluo" }),
}));

const BASE = "/api/v1/app/fluo/asistente";
const global = { plugins: [i18n], stubs: { EncabezadoSeccion: true } };

beforeEach(() => {
  vi.clearAllMocks();
});

describe("AsistenteView", () => {
  it("si no está activo, lo dice", async () => {
    api.get.mockResolvedValue({ data: { data: { disponible: false } } });
    const w = mount(AsistenteView, { global });
    await flushPromises();
    expect(w.find('[data-prueba="asistente-no-disponible"]').exists()).toBe(
      true,
    );
  });

  it("contesta y la campaña que propone se aplica tras confirmarla", async () => {
    api.get.mockResolvedValue({ data: { data: { disponible: true } } });
    api.post.mockImplementation((url: string) =>
      Promise.resolve(
        url === BASE
          ? {
              data: {
                data: {
                  respuesta: "Te dejé lista la campaña.",
                  propuestas: [
                    {
                      tipo: "campana",
                      resumen: "Aviso del puente",
                      datos: { asunto: "Puente", cuerpo: "No hay clases" },
                      token: "tk",
                    },
                  ],
                },
              },
            }
          : { data: { data: { tipo: "campana", resultado: { enviados: 2 } } } },
      ),
    );
    const w = mount(AsistenteView, { global });
    await flushPromises();

    await w
      .get('[data-prueba="asistente-pregunta"]')
      .setValue("Avisa del puente");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(BASE, {
      mensajes: [{ rol: "usuario", texto: "Avisa del puente" }],
    });
    expect(w.text()).toContain("Te dejé lista la campaña.");

    await w.get('[data-prueba="aplicar-propuesta"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.post).toHaveBeenCalledWith(`${BASE}/acciones`, { token: "tk" });
    expect(w.get('[data-prueba="asistente-propuesta"]').text()).toContain(
      "se mandó a 2",
    );
  });
});
