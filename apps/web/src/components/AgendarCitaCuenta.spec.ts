import { flushPromises, mount, RouterLinkStub } from "@vue/test-utils";
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

function montar(props: Record<string, unknown> = {}) {
  return mount(AgendarCitaCuenta, {
    props,
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
      stubs: { RouterLink: RouterLinkStub },
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

  // ADR 0201: si se perdió la respuesta, volver a enviar lo mismo lleva la misma
  // clave y el servidor devuelve la cita ya agendada (con «cualquiera» podía quedar
  // otra con otro profesional).
  it("reintentar la misma cita manda la misma clave", async () => {
    api.post
      .mockRejectedValueOnce(new Error("Sin conexión"))
      .mockResolvedValue({ data: { data: { estado: "confirmada" } } });
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await flushPromises();
    await w
      .findAll("button")
      .find((b) => b.text().includes("16:00") || b.text().includes("10:00"))
      ?.trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();
    await w.get("form").trigger("submit");
    await flushPromises();

    const claves = api.post.mock.calls.map(
      ([, cuerpo]) => (cuerpo as { idempotency_key?: string }).idempotency_key,
    );
    expect(claves).toHaveLength(2);
    expect(claves[0]).toMatch(/^[A-Za-z0-9]{32}$/);
    expect(claves[1]).toBe(claves[0]);
  });

  it("con anticipo elige pagar solo el anticipo (por omisión) o todo (ADR 0161)", async () => {
    extraServicios.lista = [
      {
        id: "color",
        nombre: "Color",
        precio_minor: 100000,
        moneda: "MXN",
        duracion_minutos: 30,
        anticipo_minor: 30000,
      },
    ];
    api.post.mockResolvedValue({
      data: { data: { estado: "pendiente_pago" } },
    });
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await flushPromises();
    // Sin anticipo no se pregunta.
    expect(w.find('[data-prueba="elegir-anticipo"]').exists()).toBe(false);

    await w.get("#cc-servicio").setValue("color");
    await flushPromises();
    const eleccion = w.get('[data-prueba="elegir-anticipo"]');
    expect(eleccion.text()).toContain("Pagar el anticipo: $300");
    expect(eleccion.text()).toContain("lo pagas en el negocio");
    await w.get('[data-prueba="pagar-todo"]').setValue();
    await w
      .findAll("button")
      .find((b) => b.text().includes("16:00") || b.text().includes("10:00"))
      ?.trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas",
      expect.objectContaining({ oferta_id: "color", pagar: "todo" }),
    );
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

  it("«Agendar de nuevo» parte del mismo servicio, sede y profesional", async () => {
    equipo.lista = [
      { id: "ana", nombre: "Ana Pérez" },
      { id: "luis", nombre: "Luis López" },
    ];
    const w = montar({
      inicial: { servicio: "corte", sucursal: "centro", profesional: "luis" },
    });
    await flushPromises();

    expect(w.get("#cc-servicio").element).toHaveProperty("value", "corte");
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas/disponibilidad",
      { params: expect.objectContaining({ instructor_id: "luis" }) },
    );
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
  it("al agendar, la confirmación se queda a la vista hasta «Listo»", async () => {
    api.post.mockResolvedValue({
      data: {
        data: { estado: "pendiente_pago", profesional: { nombre: "Ana" } },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await flushPromises();
    await w
      .findAll("button")
      .find((b) => b.text().includes("10:00") || b.text().includes("16:00"))
      ?.trigger("click");
    // Lo elegido va en la barra fija de «Agendar», a la vista aunque el formulario
    // sea largo.
    expect(w.get('[data-prueba="cita-elegida"]').text()).toContain("Corte");
    await w.get("form").trigger("submit");
    await flushPromises();

    const confirmada = w.get('[data-prueba="cita-confirmada"]');
    expect(confirmada.text()).toContain("Corte");
    expect(confirmada.text()).toContain("Centro");
    expect(confirmada.text()).toContain("Ana");
    expect(confirmada.text()).toContain("citaCuenta.pendientePago");
    // Apartada sin pagar: lleva a pagarla, y «Listo» sigue para cerrar.
    expect(w.find('[data-prueba="cita-pagar"]').exists()).toBe(true);
    expect(w.findComponent(RouterLinkStub).props("to")).toEqual({
      name: "mis-pagos",
    });
    // Avisa para recargar, pero no cierra: cierra quien la lee, con «Listo».
    expect(w.emitted("agendada")).toHaveLength(1);
    expect(w.emitted("cerrar")).toBeUndefined();
    await w.get('[data-prueba="cita-listo"]').trigger("click");
    expect(w.emitted("cerrar")).toHaveLength(1);
  });

  it("una respuesta tardía de horarios no reemplaza la de la selección actual", async () => {
    extraServicios.lista = [
      {
        id: "barba",
        nombre: "Barba",
        precio_minor: 15000,
        moneda: "MXN",
        duracion_minutos: 30,
      },
    ];
    // Las dos búsquedas quedan pendientes y se resuelven al revés.
    const pendientes: ((valor: unknown) => void)[] = [];
    const base = api.get.getMockImplementation()!;
    api.get.mockImplementation((url: string, opciones?: unknown) =>
      url.endsWith("/mi/citas/disponibilidad")
        ? new Promise((resolver) => pendientes.push(resolver))
        : base(url, opciones),
    );
    const w = montar();
    await flushPromises();
    await w.get("#cc-servicio").setValue("corte");
    await flushPromises();
    await w.get("#cc-servicio").setValue("barba");
    await flushPromises();
    expect(pendientes.length).toBeGreaterThanOrEqual(2);

    const slot = (inicia: string) => ({
      data: { data: { slots: [{ inicia, termina: inicia }] } },
    });
    pendientes[pendientes.length - 1](slot("2030-01-07T17:00:00Z"));
    await flushPromises();
    pendientes[0](slot("2030-01-07T16:00:00Z"));
    await flushPromises();

    const horas = w.findAll(".cc-hora").map((b) => b.text());
    expect(horas).toHaveLength(1);
    expect(horas[0]).toContain("11:00");
  });
});
