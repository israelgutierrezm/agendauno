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
const extraServicios = vi.hoisted(() => ({
  lista: [] as Record<string, unknown>[],
}));
const equipo = vi.hoisted(() => ({
  lista: [] as { id: string; nombre: string; foto_url?: string | null }[],
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
              ...extraServicios.lista,
            ],
            sucursales: [
              {
                id: "centro",
                nombre: "Centro",
                zona_horaria: "America/Mexico_City",
              },
            ],
            instructores: equipo.lista,
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
  equipo.lista = [{ id: "ana", nombre: "Ana" }];
  extraServicios.lista = [];
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

  it("con varios profesionales, ve los horarios de todo el equipo o de alguien por su foto", async () => {
    equipo.lista = [
      { id: "ana", nombre: "Ana Pérez", foto_url: "/storage/ana.webp" },
      { id: "luis", nombre: "Luis López" },
    ];
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await flushPromises();
    // La última búsqueda de horarios (el calendario también pide sus días).
    const ultimaBusqueda = (): Record<string, unknown> =>
      (
        api.get.mock.calls
          .filter(([url]) => String(url).endsWith("/mi/citas/disponibilidad"))
          .at(-1)?.[1] as { params: Record<string, unknown> }
      ).params;
    // Parte de todo el equipo: sin profesional en la búsqueda.
    expect(ultimaBusqueda()).not.toHaveProperty("instructor_id");

    await w.get('[data-prueba="filtro-alguien"] input').setValue();
    await w.get('[data-prueba="filtro-luis"] input').setValue();
    await flushPromises();
    expect(ultimaBusqueda().instructor_id).toBe("luis");
    expect(
      w.get('[data-prueba="filtro-ana"] [data-prueba="ampliar-foto"]').exists(),
    ).toBe(true);
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

  it("un servicio que se toma con su bono no muestra precio: se descuenta del bono", async () => {
    extraServicios.lista = [
      {
        id: "masaje",
        nombre: "Masaje",
        precio_minor: 90000,
        moneda: "MXN",
        duracion_minutos: 60,
        con_plan: true,
      },
    ];
    const w = montar();
    await flushPromises();

    const opcion = w
      .findAll("#cc-servicio option")
      .find((o) => o.text().includes("Masaje"))!;
    expect(opcion.text()).toContain("citaCuenta.conTuBono");
    expect(opcion.text()).not.toContain("900");
    await w.get("#cc-servicio").setValue("masaje");
    expect(w.get('[data-prueba="con-bono"]').text()).toContain(
      "conTuBonoAyuda",
    );
  });
});
