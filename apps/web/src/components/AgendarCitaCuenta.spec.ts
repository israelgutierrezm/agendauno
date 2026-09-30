import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import AgendarCitaCuenta from "./AgendarCitaCuenta.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

const privacidad = vi.hoisted(() => ({
  datos: {} as Record<string, unknown>,
}));

function respuestas(): void {
  api.get.mockImplementation((url: string) => {
    if (url.endsWith("/mi/privacidad")) {
      return Promise.resolve({ data: { data: privacidad.datos } });
    }
    if (url.endsWith("/mi/citas/opciones")) {
      return Promise.resolve({
        data: {
          data: {
            servicios: [
              {
                id: "corte",
                nombre: "Corte",
                precio_minor: 20000,
                moneda: "MXN",
                duracion_minutos: 30,
              },
            ],
            sucursales: [
              {
                id: "centro",
                nombre: "Centro",
                zona_horaria: "America/Mexico_City",
              },
            ],
            instructores: [{ id: "ana", nombre: "Ana" }],
          },
        },
      });
    }
    if (url.endsWith("/mi/citas/dias")) {
      return Promise.resolve({
        data: {
          data: [
            { fecha: "2030-01-06", abierto: false },
            { fecha: "2030-01-07", abierto: true },
          ],
        },
      });
    }
    return Promise.resolve({
      data: {
        data: {
          slots: [
            { inicia: "2030-01-07T16:00:00Z", termina: "2030-01-07T16:30:00Z" },
          ],
        },
      },
    });
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  privacidad.datos = {};
  respuestas();
});

function montar() {
  return mount(AgendarCitaCuenta, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...es, perfilPublico } },
        }),
      ],
    },
  });
}

describe("agendar desde la cuenta", () => {
  it("ofrece los avisos por WhatsApp solo si el negocio los usa, aún no los aceptó y tiene celular", async () => {
    privacidad.datos = {
      whatsapp_disponible: true,
      acepta_whatsapp: false,
      whatsapp_con_celular: true,
    };
    api.post.mockResolvedValue({ data: { data: { estado: "confirmada" } } });
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await w.get("#cc-profesional").setValue("ana");
    await flushPromises();
    await w.get("[data-prueba='acepta-whatsapp']").setValue(true);
    await w
      .findAll("button")
      .find((b) => b.text().includes("16:00") || b.text().includes("10:00"))
      ?.trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas",
      expect.objectContaining({ acepta_whatsapp: true }),
    );
    // Ya los aceptó: no se le vuelve a preguntar.
    expect(w.find("[data-prueba='acepta-whatsapp']").exists()).toBe(false);
  });

  it("sin celular no se le ofrecen", async () => {
    privacidad.datos = {
      whatsapp_disponible: true,
      acepta_whatsapp: false,
      whatsapp_con_celular: false,
    };
    const w = montar();
    await flushPromises();
    expect(w.find("[data-prueba='acepta-whatsapp']").exists()).toBe(false);
  });

  it("el calendario solo deja elegir días con atención y abre en el primero", async () => {
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await w.get("#cc-profesional").setValue("ana");
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.objectContaining({
        sucursal_id: "centro",
        instructor_id: "ana",
      }),
    });
    expect(
      w.get('[data-fecha="2030-01-06"]').attributes("disabled"),
    ).toBeDefined();
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas/disponibilidad",
      { params: expect.objectContaining({ fecha: "2030-01-07" }) },
    );
    // Ya no hay calendario nativo.
    expect(w.find('input[type="date"]').exists()).toBe(false);
  });
});
