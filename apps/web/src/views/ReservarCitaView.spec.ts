import { flushPromises, mount } from "@vue/test-utils";
import { AxiosError, type InternalAxiosRequestConfig } from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import ReservarCitaView from "./ReservarCitaView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  push: vi.fn(),
  replace: vi.fn(),
  query: {} as Record<string, string>,
  sesion: {
    autenticado: false,
    slug: null as string | null,
    usuario: null as Record<string, unknown> | null,
    puede: () => false,
    // Sin sesión, la tienda trae la región por omisión (México).
    pais: "MX",
    lada: "52",
  },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/negociosRecientes", () => ({ recordarNegocio: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => mocks.sesion,
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({
    params: { slug: "demo" },
    query: mocks.query,
    fullPath: "/agendar/demo",
    path: "/agendar/demo",
  }),
  useRouter: () => ({ replace: mocks.replace, push: mocks.push }),
  RouterLink: { template: "<a><slot /></a>" },
}));

function opciones(
  sedes = 2,
  cobro = { pago_obligatorio: true, pago_en_linea: true },
) {
  return {
    data: {
      data: {
        estudio: { slug: "demo", nombre: "Negocio de prueba", logo_url: null },
        servicios: [
          {
            id: "servicio",
            nombre: "Corte",
            foto_url: "/storage/corte.webp",
            precio_minor: 20000,
            moneda: "MXN",
            duracion_minutos: 30,
          },
        ],
        sucursales: [
          {
            id: "centro",
            nombre: "Centro",
            region: "Ciudad de México",
            zona_horaria: "America/Mexico_City",
            direccion: "Av. Juárez 10, Centro",
            foto_url: "/storage/centro.webp",
            mapa_url: "https://maps.app.goo.gl/centro",
            redes: [
              { red: "instagram", url: "https://www.instagram.com/centro" },
              { red: "facebook", url: "https://www.facebook.com/centro" },
            ],
          },
          {
            id: "norte",
            nombre: "Norte",
            region: null,
            zona_horaria: "America/Mexico_City",
            direccion: null,
            foto_url: null,
            mapa_url: null,
            redes: [],
          },
        ].slice(0, sedes),
        instructores: [
          { id: "ana", nombre: "Ana Pérez", foto_url: "/storage/ana.webp" },
          { id: "luis", nombre: "Luis López", foto_url: null },
        ],
        cobro,
      },
    },
  };
}
// El domingo 6 nadie atiende; el lunes 7 y el martes 8, sí.
const dias = {
  data: {
    data: [
      { fecha: "2030-01-06", abierto: false },
      { fecha: "2030-01-07", abierto: true },
      { fecha: "2030-01-08", abierto: true },
    ],
  },
};
// Todo el equipo: a las 09:00 solo Ana; a las 09:30, Ana y Luis.
const horarios = {
  data: {
    data: {
      slots: [
        {
          inicia: "2030-01-07T15:00:00Z",
          termina: "2030-01-07T15:30:00Z",
          profesionales: ["ana"],
        },
        {
          inicia: "2030-01-07T15:30:00Z",
          termina: "2030-01-07T16:00:00Z",
          profesionales: ["ana", "luis"],
        },
      ],
    },
  },
};
// Responde según la ruta (opciones, días y horarios).
function api(
  op = opciones(),
  huecos: () => Promise<unknown> = () => Promise.resolve(horarios),
  porPagar: () => Promise<unknown> = () => Promise.reject(new Error("404")),
  // Las opciones de la cuenta del cliente (con los servicios de su bono).
  mias: () => Promise<unknown> = () => Promise.reject(new Error("401")),
  // El escaparate (de ahí sale la lada del negocio si las opciones no la traen).
  escaparate: () => Promise<unknown> = () => Promise.reject(new Error("404")),
) {
  mocks.get.mockImplementation((url: string) =>
    url.endsWith("/escaparate")
      ? escaparate()
      : url.endsWith("/mi/citas/opciones")
        ? mias()
        : url.endsWith("/citas/opciones")
          ? Promise.resolve(op)
          : url.endsWith("/citas/dias")
            ? Promise.resolve(dias)
            : url.includes("/citas/orden/")
              ? porPagar()
              : huecos(),
  );
}
// La cita del enlace del correo de apartado.
function citaPorPagar(extra: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        orden_id: "orden-1",
        estado_orden: "pendiente",
        estado_reserva: "pendiente_pago",
        servicio: "Corte",
        inicia_en: "2030-01-07T16:00:00+00:00",
        zona_horaria: "America/Mexico_City",
        sucursal: {
          nombre: "Centro",
          direccion: "Av. Juárez 10, Centro",
          mapa_url: "https://maps.app.goo.gl/centro",
        },
        total_minor: 20000,
        moneda: "MXN",
        vence_en: "2030-01-06T18:30:00+00:00",
        pago_en_linea: true,
        ...extra,
      },
    },
  };
}
function montar() {
  return mount(ReservarCitaView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, perfilPublico } },
        }),
      ],
    },
  });
}
type Vista = ReturnType<typeof montar>;
async function elegirHora(vista: Vista, hora: string): Promise<void> {
  await vista
    .findAll("button")
    .find((b) => b.text() === hora)!
    .trigger("click");
}
function marcado(vista: Vista, selector: string): boolean {
  return (vista.get(selector).element as HTMLInputElement).checked;
}
// Elige el servicio: el calendario se abre en el primer día con atención.
async function hastaHorario(vista: Vista): Promise<void> {
  await vista.get('input[value="servicio"]').setValue();
  await flushPromises();
}
async function continuar(vista: Vista): Promise<void> {
  await vista.get('[data-prueba="continuar"]').trigger("click");
}
async function agendarComo(
  vista: Vista,
  boton = es.reservar.agendarYPagar,
): Promise<void> {
  if (vista.find("#rc-nom").exists()) {
    await vista.get("#rc-nom").setValue("Cliente de prueba");
    await vista.get("#rc-email").setValue("cliente@correo.mx");
  }
  await vista
    .findAll("button")
    .find((b) => b.text() === boton)!
    .trigger("click");
  await flushPromises();
}
function pasos(vista: Vista): string[] {
  return vista
    .findAll('[data-prueba="pasos"] li')
    .map((li) => li.text().trim());
}
beforeEach(() => {
  vi.clearAllMocks();
  mocks.query = {};
  mocks.sesion.autenticado = false;
  mocks.sesion.slug = null;
  mocks.sesion.usuario = null;
  sessionStorage.clear();
  api();
});

describe("agenda pública por pasos", () => {
  it("busca sin distinguir acentos y filtra catálogos grandes por categoría", async () => {
    const op = opciones(1);
    op.data.data.servicios = Array.from({ length: 6 }, (_, i) => ({
      ...op.data.data.servicios[0],
      id: `servicio-${i}`,
      nombre: `Consulta clínica ${i}`,
      categoria: i < 3 ? "Consultas" : "Terapias",
    }));
    api(op);
    const vista = montar();
    await flushPromises();
    await vista.get("#rc-buscar-servicio").setValue("clinica");
    expect(vista.findAll('input[name="servicio"]')).toHaveLength(6);
    await vista
      .findAll(".rc-categorias button")
      .find((b) => b.text() === "Terapias")!
      .trigger("click");
    expect(vista.findAll('input[name="servicio"]')).toHaveLength(3);
    await vista.get("#rc-buscar-servicio").setValue("inexistente");
    expect(vista.get('[role="status"]').text()).toContain(
      perfilPublico.agendar.sinResultadosServicio,
    );
    await vista.get(".rc-vacio button").trigger("click");
    expect(vista.findAll('input[name="servicio"]')).toHaveLength(6);
    vista.unmount();
  });

  it("agrupa horarios según la hora local de la sucursal, no según UTC", async () => {
    api(opciones(1), () =>
      Promise.resolve({
        data: {
          data: {
            slots: [
              "2030-01-07T15:00:00Z",
              "2030-01-07T21:00:00Z",
              "2030-01-08T02:00:00Z",
            ].map((inicia) => ({
              inicia,
              termina: inicia,
              profesionales: ["ana"],
            })),
          },
        },
      }),
    );
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    const franjas = vista.findAll(".rc-franja");
    expect(franjas).toHaveLength(3);
    expect(franjas[0].text()).toContain("09:00");
    expect(franjas[1].text()).toContain("15:00");
    expect(franjas[2].text()).toContain("20:00");
    await elegirHora(vista, "15:00");
    expect(vista.get(".rc-seleccion-hora").text()).toContain("15:00");
    vista.unmount();
  });

  it("un profesional único se muestra sin pedir una elección de equipo", async () => {
    const op = opciones(1);
    op.data.data.instructores = [op.data.data.instructores[0]];
    api(op);
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    expect(vista.find('[data-prueba="ver-horarios-de"]').exists()).toBe(false);
    expect(vista.get(".rc-profesional-unico").text()).toContain("Ana Pérez");
    await elegirHora(vista, "09:00");
    expect(vista.find('input[name="profesional"]').exists()).toBe(false);
    expect(
      vista.get('[data-prueba="continuar"]').attributes("disabled"),
    ).toBeUndefined();
    vista.unmount();
  });

  it("busca un siguiente día con huecos reales para el servicio sin crear la cita", async () => {
    const siguientes = {
      data: {
        data: {
          slots: horarios.data.data.slots.map((s) => ({
            ...s,
            inicia: s.inicia.replace("01-07", "01-08"),
            termina: s.termina.replace("01-07", "01-08"),
          })),
        },
      },
    };
    mocks.get.mockImplementation(
      (url: string, config?: { params?: { fecha?: string } }) =>
        Promise.resolve(
          url.endsWith("/opciones")
            ? opciones(1)
            : url.endsWith("/dias")
              ? dias
              : config?.params?.fecha === "2030-01-08"
                ? siguientes
                : { data: { data: { slots: [] } } },
        ),
    );
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await vista.get('[data-prueba="siguiente-horario"]').trigger("click");
    await flushPromises();
    expect(
      vista.get('[data-fecha="2030-01-08"]').attributes("aria-pressed"),
    ).toBe("true");
    expect(vista.find('[data-prueba="sin-horarios"]').exists()).toBe(false);
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      {
        params: expect.objectContaining({
          fecha: "2030-01-08",
          oferta_id: "servicio",
          duracion_minutos: 30,
        }),
      },
    );
    expect(mocks.post).not.toHaveBeenCalled();
    vista.unmount();
  });

  it("descarta una búsqueda de siguiente horario si el usuario vuelve al servicio", async () => {
    api(opciones(1), () => Promise.resolve({ data: { data: { slots: [] } } }));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    let responder!: (value: typeof dias) => void;
    mocks.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          responder = resolve;
        }),
    );
    await vista.get('[data-prueba="siguiente-horario"]').trigger("click");
    await vista.get('button[data-paso="servicio"]').trigger("click");
    const llamadas = mocks.get.mock.calls.length;
    responder(dias);
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledTimes(llamadas);
    expect(vista.find('input[name="servicio"]').exists()).toBe(true);
    vista.unmount();
  });

  it("no promete disponibilidad cuando los próximos días también están llenos", async () => {
    api(opciones(1), () => Promise.resolve({ data: { data: { slots: [] } } }));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await vista.get('[data-prueba="siguiente-horario"]').trigger("click");
    await flushPromises();
    expect(vista.get('[data-prueba="sin-horarios"]').text()).toContain(
      perfilPublico.agendar.sinSiguiente,
    );
    expect(
      vista.get('[data-prueba="continuar"]').attributes("disabled"),
    ).toBeDefined();
    vista.unmount();
  });

  it("con varias sedes empieza por la sede (foto, dirección y redes) y luego el servicio", async () => {
    const vista = montar();
    await flushPromises();
    expect(pasos(vista)).toEqual([
      "1Sucursal",
      "2Servicio",
      "3Fecha y hora",
      "4Confirmación",
    ]);
    expect(vista.findAll('input[name="sucursal"]')).toHaveLength(2);
    expect(vista.get('img[src="/storage/centro.webp"]').exists()).toBe(true);
    expect(vista.text()).toContain("Av. Juárez 10, Centro");
    // Solo la sede que tiene redes las muestra; abrirlas no elige la sede.
    const redes = vista.findAll('[data-prueba="redes-sede"]');
    expect(redes).toHaveLength(1);
    const enlaces = redes[0].findAll("a");
    expect(enlaces.map((a) => a.attributes("href"))).toEqual([
      "https://www.instagram.com/centro",
      "https://www.facebook.com/centro",
    ]);
    expect(enlaces[0].attributes("aria-label")).toBe("Instagram de Centro");
    await enlaces[0].trigger("click");
    expect(vista.find('input[name="sucursal"]').exists()).toBe(true);

    await vista.get('input[value="centro"]').setValue();
    // Paso 2: el servicio, con la sede elegida a la vista.
    expect(vista.find('input[name="sucursal"]').exists()).toBe(false);
    expect(vista.get('[data-prueba="contexto"]').text()).toContain("Centro");
    // El servicio con su foto en miniatura y su duración.
    expect(vista.get('[data-prueba="foto-servicio"]').attributes("src")).toBe(
      "/storage/corte.webp",
    );
    expect(vista.get(".rc-duracion").text()).toContain("30");
    await hastaHorario(vista);
    // Paso 3: primero el día y la hora; todavía no se pregunta con quién.
    expect(vista.find('input[name="profesional"]').exists()).toBe(false);
    await elegirHora(vista, "09:30");
    // Los dos libres a esa hora y, antes, «cualquier profesional disponible».
    expect(vista.findAll('input[name="profesional"]')).toHaveLength(3);
    // Si la foto no carga (aquí y en «Ver horarios de»), queda la inicial.
    for (const foto of vista.findAll('img[src="/storage/ana.webp"]')) {
      await foto.trigger("error");
    }
    expect(vista.find('img[src="/storage/ana.webp"]').exists()).toBe(false);
    expect(
      vista
        .get('input[name="profesional"][value="ana"]')
        .element.closest("label")?.textContent,
    ).toContain("A");
    vista.unmount();
  });

  it("el calendario empieza hoy, no deja elegir días sin atención y abre en el primero con atención", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/citas/dias", {
      params: expect.objectContaining({ sucursal_id: "centro", dias: 14 }),
    });
    const domingo = vista.get('[data-fecha="2030-01-06"]');
    expect(domingo.attributes("disabled")).toBeDefined();
    expect(
      vista.get('[data-fecha="2030-01-07"]').attributes("aria-pressed"),
    ).toBe("true");
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ fecha: "2030-01-07" }) },
    );
    // Otro día con atención y más fechas.
    await vista.get('[data-fecha="2030-01-08"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ fecha: "2030-01-08" }) },
    );
    await vista.get('[data-prueba="mas-fechas"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/citas/dias", {
      params: expect.objectContaining({ desde: "2030-01-09" }),
    });
    vista.unmount();
  });

  it("con una sola sede (o la del enlace) empieza por el servicio", async () => {
    api(opciones(1));
    let vista = montar();
    await flushPromises();
    expect(pasos(vista)).toEqual([
      "1Servicio",
      "2Fecha y hora",
      "3Confirmación",
    ]);
    expect(vista.find('input[name="sucursal"]').exists()).toBe(false);
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    vista.unmount();

    api();
    mocks.query = { sucursal: "norte" };
    vista = montar();
    await flushPromises();
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    expect(vista.get('[data-prueba="contexto"]').text()).toContain("Norte");
    vista.unmount();
  });

  it("solo deja volver a los pasos hechos y no continúa sin hora", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    expect(vista.find('button[data-paso="horario"]').exists()).toBe(false);
    await hastaHorario(vista);
    expect(
      vista.get('[data-prueba="continuar"]').attributes("disabled"),
    ).toBeDefined();
    await elegirHora(vista, "09:00");
    expect(
      vista.get('[data-prueba="continuar"]').attributes("disabled"),
    ).toBeUndefined();
    await vista.get('button[data-paso="servicio"]').trigger("click");
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    expect(vista.find('[data-prueba="dias"]').exists()).toBe(false);
    vista.unmount();
  });

  it.each([
    [true, true, perfilPublico.agendar.pagoPrevio],
    [false, true, perfilPublico.agendar.pagoFlexible],
    [false, false, perfilPublico.agendar.pagoEnLugar],
  ])(
    "explica el pago según la configuración (%s, %s)",
    async (obligatorio, enLinea, mensaje) => {
      api(
        opciones(1, { pago_obligatorio: obligatorio, pago_en_linea: enLinea }),
      );
      const vista = montar();
      await flushPromises();
      await hastaHorario(vista);
      await elegirHora(vista, "09:00");
      await continuar(vista);
      expect(vista.get('[data-prueba="pago-ayuda"]').text()).toBe(mensaje);
      expect(vista.get('[data-prueba="precio-resumen"]').text()).toContain(
        "$200.00",
      );
      expect(vista.get('[data-prueba="guia-paso"]').text()).toContain(
        perfilPublico.agendar.guia.confirmar.titulo,
      );
      expect(mocks.post).not.toHaveBeenCalled();
      vista.unmount();
    },
  );

  it("presenta la foto, duración y profesional sin perder los campos opcionales", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await vista.get('input[name="profesional"][value="ana"]').setValue();
    await continuar(vista);
    const resumen = vista.get('[data-prueba="resumen"]');
    expect(resumen.text()).toContain("30 min");
    expect(resumen.text()).toContain("Ana Pérez");
    expect(resumen.get(".rc-avatar-resumen").attributes("src")).toBe(
      "/storage/ana.webp",
    );
    expect(resumen.get(".rc-ticket-imagen").attributes("src")).toBe(
      "/storage/corte.webp",
    );
    expect(vista.findAll("details.rc-opcional")).toHaveLength(2);
    expect(vista.get("#rc-ape").attributes("placeholder")).toBe("Opcional");
    expect(vista.get("#rc-email").attributes("aria-describedby")).toBe(
      "rc-email-ayuda",
    );
    await vista.get('button[aria-label="Cambiar servicio"]').trigger("click");
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    expect(mocks.post).not.toHaveBeenCalled();
    vista.unmount();
  });

  it("pide el correo y confirma con el resumen de dónde es la cita (pago obligatorio: queda apartada)", async () => {
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "pendiente_pago",
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="centro"]').setValue();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await vista.get('input[name="profesional"][value="ana"]').setValue();
    await continuar(vista);

    const donde = vista.get('[data-prueba="donde"]');
    expect(donde.text()).toContain("Av. Juárez 10, Centro");
    expect(donde.get('[data-prueba="como-llegar"]').attributes("href")).toBe(
      "https://maps.app.goo.gl/centro",
    );
    // Sin correo válido no se agenda.
    await vista.get("#rc-nom").setValue("Cliente de prueba");
    await vista.get("#rc-email").setValue("no-es-correo");
    const boton = vista
      .findAll("button")
      .find((b) => b.text() === es.reservar.agendarYPagar)!;
    expect(boton.attributes("disabled")).toBeDefined();

    await agendarComo(vista);
    expect(vista.get("h2.text-success").text()).toBe(es.reservar.listoTitulo);
    expect(vista.get('[role="status"]').text()).toContain(es.reservar.apartado);
    expect(vista.get('[data-prueba="donde-listo"]').text()).toContain(
      "Av. Juárez 10, Centro",
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({
        email: "cliente@correo.mx",
        sucursal_id: "centro",
        instructor_id: "ana",
        oferta_id: "servicio",
      }),
    );
    vista.unmount();
  });

  it("si el negocio no pide pagar en línea, la cita queda confirmada y se puede pagar después", async () => {
    api(opciones(1, { pago_obligatorio: false, pago_en_linea: true }));
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "confirmada",
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    await agendarComo(vista, "Agendar");

    expect(vista.get('[data-prueba="confirmada"]').text()).toContain(
      "Puedes pagarla ahora en línea o en la sucursal.",
    );
    expect(vista.text()).not.toContain(es.reservar.apartado);
    expect(
      vista.findAll("button").some((b) => b.text().startsWith("Pagar ahora")),
    ).toBe(true);
    vista.unmount();
  });

  it("parte de «cualquier profesional»: ve los horarios de todo el equipo y dice quién atenderá", async () => {
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "pendiente_pago",
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
          profesional: { id: "luis", nombre: "Luis López" },
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      {
        params: expect.not.objectContaining({
          instructor_id: expect.anything(),
        }),
      },
    );
    await elegirHora(vista, "09:30");
    expect(marcado(vista, '[data-prueba="cualquiera"] input')).toBe(true);
    await continuar(vista);
    expect(vista.get('[data-prueba="resumen"]').text()).toContain(
      "Cualquier profesional disponible",
    );
    await agendarComo(vista);
    expect(mocks.post.mock.calls[0][1]).not.toHaveProperty("instructor_id");
    expect(vista.get('[role="status"]').text()).toContain(
      "Corte con Luis López",
    );
    vista.unmount();
  });

  it("al cambiar de hora solo ofrece a quienes siguen libres y suelta a quien ya no lo está", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:30");
    await vista.get('input[name="profesional"][value="luis"]').setValue();
    await elegirHora(vista, "09:00");
    expect(vista.text()).toContain("Libres a las 09:00.");
    expect(vista.find('input[name="profesional"][value="luis"]').exists()).toBe(
      false,
    );
    expect(marcado(vista, '[data-prueba="cualquiera"] input')).toBe(true);
    await vista.get('input[name="profesional"][value="ana"]').setValue();
    await elegirHora(vista, "09:30");
    expect(marcado(vista, 'input[name="profesional"][value="ana"]')).toBe(true);
    vista.unmount();
  });

  it("con alguien de preferencia ve solo sus días y horarios y ya no pregunta con quién", async () => {
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: { data: { orden_id: "orden", total_minor: 20000, moneda: "MXN" } },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await vista.get('[data-prueba="filtro-alguien"] input').setValue();
    await vista
      .get('[data-prueba="filtro-luis"] input[type="radio"]')
      .setValue();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/citas/dias", {
      params: expect.objectContaining({ instructor_id: "luis" }),
    });
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ instructor_id: "luis" }) },
    );
    await elegirHora(vista, "09:30");
    expect(vista.find('input[name="profesional"]').exists()).toBe(false);
    await continuar(vista);
    expect(vista.get('[data-prueba="resumen"]').text()).toContain("Luis López");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ instructor_id: "luis" }),
    );
    vista.unmount();
  });

  it("ver horarios de: todo el equipo o alguien específico, elegido por su foto (la lupa la abre en grande)", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);

    const tarjetas = vista.get('[data-prueba="ver-horarios-de"]');
    expect(tarjetas.text()).toContain("Todo el equipo");
    // Cada opción dice qué es.
    expect(tarjetas.get('[data-prueba="filtro-todos"]').text()).toContain(
      "Cualquier profesional",
    );
    expect(tarjetas.get('[data-prueba="filtro-alguien"]').text()).toBe(
      "Elegir a alguien específicoSelecciona un profesionista",
    );
    // Con todo el equipo no se piden fotos.
    expect(vista.find('[data-prueba="filtro-ana"]').exists()).toBe(false);

    // Alguien específico: queda el primero y se ven todos por su primer nombre.
    await vista.get('[data-prueba="filtro-alguien"] input').setValue();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ instructor_id: "ana" }) },
    );
    expect(vista.get('[data-prueba="filtro-ana"] .ep-nombre').text()).toBe(
      "Ana",
    );
    expect(vista.get('[data-prueba="filtro-luis"] .ep-nombre').text()).toBe(
      "Luis",
    );
    expect(vista.get('[data-prueba="filtro-ana"] img').attributes("src")).toBe(
      "/storage/ana.webp",
    );
    await vista
      .get('[data-prueba="filtro-luis"] input[type="radio"]')
      .setValue();
    // Sin foto no hay nada que ampliar.
    expect(
      vista
        .find('[data-prueba="filtro-luis"] [data-prueba="ampliar-foto"]')
        .exists(),
    ).toBe(false);

    // Pulsar directamente la foto no cambia al profesional seleccionado.
    await vista.get('[data-prueba="filtro-ana"] img').trigger("click");
    const grande = document.querySelector('[data-prueba="foto-grande"]');
    expect(grande?.querySelector("img")?.getAttribute("src")).toBe(
      "/storage/ana.webp",
    );
    expect(grande?.textContent).toContain("Ana Pérez");
    expect(
      (
        vista.get('[data-prueba="filtro-luis"] input')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true);
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    await flushPromises();
    expect(document.querySelector('[data-prueba="foto-grande"]')).toBeNull();
    vista.unmount();
  });

  it("descarta horarios tardíos al cambiar de sede y vuelve a ver todo el equipo", async () => {
    let resolver!: (value: typeof horarios) => void;
    api(
      opciones(),
      () =>
        new Promise((resolve) => {
          resolver = resolve;
        }),
    );
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="centro"]').setValue();
    await hastaHorario(vista);
    await vista.get('[data-prueba="filtro-alguien"] input').setValue();
    await flushPromises();
    // Vuelve a la sede desde el mapa de pasos y elige otra.
    await vista.get('button[data-paso="sucursal"]').trigger("click");
    await vista.get('input[value="norte"]').setValue();
    resolver(horarios);
    await flushPromises();
    expect(vista.text()).not.toContain("09:00");
    // El servicio ya estaba elegido: tocarlo de nuevo lleva a la fecha y hora.
    await vista.get('input[value="servicio"]').trigger("click");
    await flushPromises();
    expect(
      (
        vista.get('[data-prueba="filtro-todos"] input')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true);
    expect(vista.find("#rc-nom").exists()).toBe(false);
    vista.unmount();
  });
});

describe("datos del cliente", () => {
  it("manda apellidos, lada, cómo nos conoció y la nota para el negocio", async () => {
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "pendiente_pago",
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);

    await vista.get("#rc-nom").setValue("Beto");
    await vista.get("#rc-ape").setValue("López García");
    await vista.get('[data-prueba="elegir-lada"]').setValue("US");
    await vista.get("#rc-cel").setValue("555 123 4567");
    await vista.get("#rc-email").setValue("beto@correo.mx");
    await vista.get("#rc-origen").setValue("instagram");
    await vista.get("#rc-nota").setValue("Es mi primera vez.");
    await vista
      .findAll("button")
      .find((b) => b.text() === es.reservar.agendarYPagar)!
      .trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({
        nombre: "Beto",
        apellidos: "López García",
        lada: "+1",
        celular: "5551234567",
        email: "beto@correo.mx",
        como_nos_conocio: "instagram",
        nota: "Es mi primera vez.",
      }),
    );
    vista.unmount();
  });
});

describe("lada del celular", () => {
  it("propone la lada del negocio que traen las opciones", async () => {
    const op = opciones(1);
    api({
      data: {
        data: {
          ...op.data.data,
          estudio: { ...op.data.data.estudio, pais: "CO", lada: "57" },
        },
      },
    });
    mocks.post.mockResolvedValue({
      data: { data: { estado: "pendiente_pago", orden_id: "orden" } },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);

    expect(vista.get('[data-prueba="lada-celular"]').text()).toBe("CO +57");
    // Ya la tenía: no pide el escaparate.
    expect(mocks.get).not.toHaveBeenCalledWith("/api/v1/app/demo/escaparate");
    await vista.get("#rc-cel").setValue("300 123 4567");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ lada: "+57", celular: "3001234567" }),
    );
    vista.unmount();
  });

  it("si las opciones no la traen, la toma del escaparate", async () => {
    api(opciones(1), undefined, undefined, undefined, () =>
      Promise.resolve({
        data: { data: { estudio: { pais: "CL", lada: "56" } } },
      }),
    );
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/escaparate");
    expect(vista.get('[data-prueba="lada-celular"]').text()).toBe("CL +56");
    // De la lista completa de países.
    expect(vista.findAll('[data-prueba="elegir-lada"] option')).toHaveLength(
      250,
    );
    vista.unmount();
  });

  it("sin la lada del negocio no supone México: manda el celular sin lada", async () => {
    // Ni las opciones ni el escaparate dicen el país del negocio.
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: { data: { estado: "pendiente_pago", orden_id: "orden" } },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    await flushPromises();

    await vista.get("#rc-cel").setValue("300 123 4567");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ celular: "3001234567", lada: null }),
    );
    vista.unmount();
  });

  it("sin la lada del negocio, una lada elegida sí se manda", async () => {
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: { data: { estado: "pendiente_pago", orden_id: "orden" } },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    await flushPromises();

    await vista.get('[data-prueba="elegir-lada"]').setValue("CO");
    await vista.get("#rc-cel").setValue("300 123 4567");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ celular: "3001234567", lada: "+57" }),
    );
    vista.unmount();
  });
});

describe("avisos por WhatsApp", () => {
  it("si el negocio los usa, los ofrece a quien deja su celular y manda que aceptó", async () => {
    const op = opciones(1);
    const conWhatsApp = { data: { data: { ...op.data.data, whatsapp: true } } };
    api(conWhatsApp);
    mocks.post.mockResolvedValue({
      data: { data: { estado: "pendiente_pago", orden_id: "orden" } },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);

    // Sin celular no hay a dónde mandarlos.
    expect(vista.find('[data-prueba="acepta-whatsapp"]').exists()).toBe(false);
    await vista.get("#rc-cel").setValue("55 1234 5678");
    await vista.get('[data-prueba="acepta-whatsapp"]').setValue(true);
    await agendarComo(vista);

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({
        celular: "5512345678",
        acepta_whatsapp: true,
      }),
    );
    vista.unmount();
  });

  it("si el negocio no los usa, no aparecen", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    await vista.get("#rc-cel").setValue("55 1234 5678");

    expect(vista.find('[data-prueba="acepta-whatsapp"]').exists()).toBe(false);
    vista.unmount();
  });
});

describe("para otra persona", () => {
  it("manda quién asiste solo si marca que es para otra persona", async () => {
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "pendiente_pago",
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    expect(vista.find("#rc-asiste").exists()).toBe(false);
    await vista.get('[data-prueba="para-otra"]').setValue(true);
    await vista.get("#rc-asiste").setValue("Juanito");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ asiste: "Juanito" }),
    );
    vista.unmount();
  });
});

describe("cliente con cuenta", () => {
  it("con sesión en el negocio no pide datos y agenda desde su cuenta", async () => {
    mocks.sesion.autenticado = true;
    mocks.sesion.slug = "demo";
    mocks.sesion.usuario = {
      nombre: "Vale Ruiz",
      email: "vale@correo.mx",
      rol: "miembro",
    };
    api(opciones(1));
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "pendiente_pago",
          orden_id: "orden",
          profesional: { id: "ana", nombre: "Ana Pérez" },
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);

    const conCuenta = vista.get('[data-prueba="con-cuenta"]');
    expect(conCuenta.text()).toContain("Vale Ruiz");
    expect(conCuenta.text()).toContain("vale@correo.mx");
    expect(vista.find("#rc-nom").exists()).toBe(false);
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas",
      expect.objectContaining({ oferta_id: "servicio", sucursal_id: "centro" }),
    );
    expect(mocks.post.mock.calls[0][1]).not.toHaveProperty("email");
    expect(mocks.post.mock.calls[0][1]).not.toHaveProperty("nota");
    // El total sale del servicio (la cuenta no lo devuelve).
    expect(vista.text()).toContain("$200.00");
    vista.unmount();
  });

  it("sin sesión ofrece entrar, guarda lo elegido y lo retoma al volver", async () => {
    api(opciones(1));
    let vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:30");
    await vista.get('input[name="profesional"][value="luis"]').setValue();
    await continuar(vista);
    await vista.get('[data-prueba="entrar"]').trigger("click");
    expect(mocks.push).toHaveBeenCalledWith({
      name: "entrar",
      query: { estudio: "demo", volver: "/agendar/demo" },
    });
    vista.unmount();

    // Vuelve ya con su cuenta: queda en la confirmación con lo que eligió.
    mocks.sesion.autenticado = true;
    mocks.sesion.slug = "demo";
    mocks.sesion.usuario = {
      nombre: "Vale",
      email: "v@correo.mx",
      rol: "miembro",
    };
    vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="con-cuenta"]').exists()).toBe(true);
    expect(vista.get('[data-prueba="resumen"]').text()).toContain("Luis López");
    expect(sessionStorage.getItem("agendar:demo")).toBeNull();
    vista.unmount();
  });

  it("un usuario del equipo (sin perfil de cliente) agenda como invitado", async () => {
    mocks.sesion.autenticado = true;
    mocks.sesion.slug = "demo";
    mocks.sesion.usuario = {
      nombre: "Recepción",
      email: "r@x.mx",
      rol: "recepcionista",
    };
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    await hastaHorario(vista);
    await elegirHora(vista, "09:00");
    await continuar(vista);
    expect(vista.find('[data-prueba="con-cuenta"]').exists()).toBe(false);
    expect(vista.find("#rc-nom").exists()).toBe(true);
    // No ofrece «entrar» (solo lo llevaría a su panel): explica con qué sesión está.
    expect(vista.find('[data-prueba="entrar"]').exists()).toBe(false);
    expect(vista.get('[data-prueba="sesion-equipo"]').text()).toContain(
      "Tienes la sesión abierta como",
    );
    vista.unmount();
  });
});

describe("bono o membresía", () => {
  // Un negocio con servicios que se toman con bono (ADR 0091/0093).
  function conBono() {
    const op = opciones(1);
    (op.data.data as Record<string, unknown>).hay_con_plan = true;
    return op;
  }
  // Las opciones de su cuenta: además, la barba que cubre su bono.
  function mias() {
    return Promise.resolve({
      data: {
        data: {
          servicios: [
            {
              id: "servicio",
              nombre: "Corte",
              precio_minor: 20000,
              moneda: "MXN",
              duracion_minutos: 30,
            },
            {
              id: "barba",
              nombre: "Barba",
              precio_minor: 15000,
              moneda: "MXN",
              duracion_minutos: 30,
              con_plan: true,
            },
          ],
        },
      },
    });
  }

  it("al visitante le avisa que use su bono desde su cuenta", async () => {
    api(conBono());
    const vista = montar();
    await flushPromises();

    const aviso = vista.get('[data-prueba="aviso-bono"]');
    expect(aviso.text()).toContain("¿Tienes un bono o membresía?");
    await vista.get('[data-prueba="entrar-bono"]').trigger("click");
    expect(mocks.push).toHaveBeenCalledWith({
      name: "entrar",
      query: { estudio: "demo", volver: "/agendar/demo" },
    });
    // No pide sus opciones de cliente: no tiene sesión.
    expect(mocks.get).not.toHaveBeenCalledWith(
      expect.stringContaining("/mi/citas/opciones"),
    );
    vista.unmount();
  });

  it("sin servicios con bono no hay aviso", async () => {
    api(opciones(1));
    const vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="aviso-bono"]').exists()).toBe(false);
    vista.unmount();
  });

  it("con su cuenta ve los servicios de su bono y los agenda sin pagar", async () => {
    mocks.sesion.autenticado = true;
    mocks.sesion.slug = "demo";
    mocks.sesion.usuario = {
      nombre: "Vale Ruiz",
      email: "vale@correo.mx",
      rol: "miembro",
    };
    api(
      conBono(),
      () => Promise.resolve(horarios),
      () => Promise.reject(new Error("404")),
      mias,
    );
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estado: "confirmada",
          orden_id: null,
          profesional: { id: "ana", nombre: "Ana Pérez" },
        },
      },
    });
    const vista = montar();
    await flushPromises();

    expect(vista.find('[data-prueba="aviso-bono"]').exists()).toBe(false);
    // Sin repetir el corte, que ya está en la página pública.
    expect(vista.findAll('input[value="servicio"]')).toHaveLength(1);
    const barba = vista.get('input[value="barba"]').element.closest("label")!;
    expect(barba.textContent).toContain("Con tu bono");
    expect(barba.textContent).not.toContain("$150.00");

    await vista.get('input[value="barba"]').setValue();
    await flushPromises();
    await elegirHora(vista, "09:00");
    await continuar(vista);
    expect(vista.text()).toContain(
      "Se descuenta una sesión de tu bono o membresía",
    );
    // No se paga al agendar: el botón solo agenda.
    expect(
      vista
        .findAll("button")
        .some((b) => b.text() === es.reservar.agendarYPagar),
    ).toBe(false);
    await agendarComo(vista, perfilPublico.agendar.agendar);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/citas",
      expect.objectContaining({ oferta_id: "barba" }),
    );
    expect(vista.text()).not.toContain("$150.00");
    vista.unmount();
  });
});

describe("enlace para pagar una cita apartada", () => {
  it("muestra la cita, hasta qué hora se paga y la paga", async () => {
    mocks.query = { pagar: "orden-1" };
    api(opciones(), undefined, () => Promise.resolve(citaPorPagar()));
    mocks.post.mockResolvedValue({ data: { data: { checkout: null } } });
    const vista = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/orden/orden-1",
    );
    const tarjeta = vista.get('[data-prueba="por-pagar"]');
    expect(tarjeta.text()).toContain("Paga tu cita");
    expect(tarjeta.text()).toContain("Corte");
    expect(tarjeta.text()).toContain("10:00");
    expect(tarjeta.text()).toContain("Av. Juárez 10, Centro");
    expect(tarjeta.text()).toContain("Págala antes de las 12:30");
    // No se muestra el asistente.
    expect(vista.find('[data-prueba="pasos"]').exists()).toBe(false);

    await tarjeta
      .findAll("button")
      .find((b) => b.text().startsWith("Pagar ahora"))!
      .trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/citas/pagar", {
      orden_id: "orden-1",
      metodo: "tarjeta",
    });
    vista.unmount();
  });

  it("si ya se pagó lo dice; si venció, ofrece agendar de nuevo", async () => {
    mocks.query = { pagar: "orden-1" };
    api(opciones(), undefined, () =>
      Promise.resolve(citaPorPagar({ estado_orden: "pagada" })),
    );
    let vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="ya-pagada"]').exists()).toBe(true);
    expect(
      vista.findAll("button").some((b) => b.text().startsWith("Pagar ahora")),
    ).toBe(false);
    vista.unmount();

    api(opciones(), undefined, () =>
      Promise.resolve(
        citaPorPagar({
          estado_orden: "cancelada",
          estado_reserva: "cancelada",
          vence_en: null,
        }),
      ),
    );
    vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="vencida"]').exists()).toBe(true);
    await vista
      .findAll("button")
      .find((b) => b.text() === "Agendar de nuevo")!
      .trigger("click");
    expect(vista.find('[data-prueba="pasos"]').exists()).toBe(true);
    vista.unmount();
  });

  it("un enlace que no existe lo dice y deja agendar", async () => {
    mocks.query = { pagar: "no-existe" };
    const vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="enlace-invalido"]').exists()).toBe(true);
    vista.unmount();
  });
});

describe("profesionales por sede", () => {
  it("solo ofrece a quien atiende en la sede elegida", async () => {
    const op = opciones();
    op.data.data.instructores = [
      {
        id: "ana",
        nombre: "Ana Pérez",
        foto_url: "/storage/ana.webp",
        sucursales: ["centro"],
      },
      {
        id: "luis",
        nombre: "Luis López",
        foto_url: null,
        sucursales: ["norte"],
      },
    ] as typeof op.data.data.instructores;
    api(op);
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="norte"]').setValue();
    await hastaHorario(vista);

    // En Norte solo atiende Luis: no hay equipo que elegir y no aparece Ana.
    expect(vista.find('[data-prueba="ver-horarios-de"]').exists()).toBe(false);
    expect(vista.text()).toContain("Luis López");
    expect(vista.text()).not.toContain("Ana Pérez");
    vista.unmount();
  });
});

describe("en un negocio de clases (ADR 0104)", () => {
  it("si el servidor dice que no agenda citas, lleva a su página", async () => {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    mocks.get.mockRejectedValue(
      new AxiosError("Falla", "ERR_BAD_REQUEST", config, null, {
        status: 403,
        statusText: "",
        headers: {},
        config,
        data: { code: "MODALITY_NOT_AVAILABLE", message: "Solo con citas." },
      }),
    );
    const vista = montar();
    await flushPromises();

    expect(mocks.replace).toHaveBeenCalledWith({
      name: "estudio-publico",
      params: { slug: "demo" },
    });
    expect(vista.text()).not.toContain(es.reservar.noDisponible);
  });

  it("otro error dice que no está disponible", async () => {
    mocks.get.mockRejectedValue(new Error("red"));
    const vista = montar();
    await flushPromises();

    expect(mocks.replace).not.toHaveBeenCalled();
    expect(vista.text()).toContain(es.reservar.noDisponible);
  });

  // El enlace del correo de apartado (?pagar) y el regreso de la pasarela llegan
  // aquí también para una clase de pago suelto: se pagan sin /citas/opciones.
  function negocioDeClases(
    porPagar: () => Promise<unknown> = () =>
      Promise.resolve(citaPorPagar({ servicio: "Pilates suelto" })),
  ): void {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    const negado = new AxiosError("Falla", "ERR_BAD_REQUEST", config, null, {
      status: 403,
      statusText: "",
      headers: {},
      config,
      data: { code: "MODALITY_NOT_AVAILABLE", message: "Solo con citas." },
    });
    mocks.get.mockImplementation((url: string) =>
      url.endsWith("/escaparate")
        ? Promise.resolve({
            data: {
              data: {
                estudio: {
                  slug: "demo",
                  nombre: "Estudio de clases",
                  logo_url: null,
                  modalidad: "clases",
                },
              },
            },
          })
        : url.includes("/citas/orden/")
          ? porPagar()
          : Promise.reject(negado),
    );
  }
  const aSuPagina = {
    name: "estudio-publico",
    params: { slug: "demo" },
  };

  it("con el enlace para pagar una clase la muestra y la paga, sin llevar a su página", async () => {
    mocks.query = { pagar: "orden-1" };
    negocioDeClases();
    mocks.post.mockResolvedValue({ data: { data: { checkout: null } } });
    const vista = montar();
    await flushPromises();

    expect(mocks.replace).not.toHaveBeenCalledWith(aSuPagina);
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/orden/orden-1",
    );
    expect(vista.text()).toContain("Estudio de clases");
    expect(vista.text()).not.toContain(es.reservar.titulo);
    const tarjeta = vista.get('[data-prueba="por-pagar"]');
    expect(tarjeta.text()).toContain(perfilPublico.agendar.pagoClase.titulo);
    expect(tarjeta.text()).toContain("Pilates suelto");
    expect(tarjeta.text()).toContain("Págala antes de las 12:30");

    await tarjeta
      .findAll("button")
      .find((b) => b.text().startsWith("Pagar ahora"))!
      .trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/citas/pagar", {
      orden_id: "orden-1",
      metodo: "tarjeta",
    });
    vista.unmount();
  });

  it("si la clase venció, vuelve a su página para reservar otra", async () => {
    mocks.query = { pagar: "orden-1" };
    negocioDeClases(() =>
      Promise.resolve(
        citaPorPagar({
          estado_orden: "cancelada",
          estado_reserva: "cancelada",
          vence_en: null,
        }),
      ),
    );
    const vista = montar();
    await flushPromises();

    expect(vista.get('[data-prueba="vencida"]').text()).toBe(
      perfilPublico.agendar.pagoClase.vencida,
    );
    await vista
      .findAll("button")
      .find((b) => b.text() === perfilPublico.agendar.pagoClase.deNuevo)!
      .trigger("click");
    expect(mocks.replace).toHaveBeenCalledWith(aSuPagina);
    vista.unmount();
  });

  it("al volver de pagar una clase avisa y ofrece su página", async () => {
    mocks.query = { pago: "exito" };
    negocioDeClases();
    const vista = montar();
    await flushPromises();

    expect(mocks.replace).not.toHaveBeenCalledWith(aSuPagina);
    expect(vista.find('[role="status"]').exists()).toBe(true);
    expect(vista.get('[data-prueba="pago-clase"]').text()).toBe(
      perfilPublico.agendar.pagoClase.verClases,
    );
    vista.unmount();
  });
});
