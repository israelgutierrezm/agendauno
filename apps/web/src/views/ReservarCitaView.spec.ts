import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import ReservarCitaView from "./ReservarCitaView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  push: vi.fn(),
  query: {} as Record<string, string>,
  sesion: {
    autenticado: false,
    slug: null as string | null,
    usuario: null as Record<string, unknown> | null,
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
  useRouter: () => ({ replace: vi.fn(), push: mocks.push }),
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
) {
  mocks.get.mockImplementation((url: string) =>
    url.endsWith("/citas/opciones")
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
    // El servicio con su foto.
    expect(vista.get('[data-prueba="foto-servicio"]').attributes("src")).toBe(
      "/storage/corte.webp",
    );
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
    expect(tarjetas.text()).toContain("Cualquier profesional");
    expect(tarjetas.text()).toContain("Elegir a alguien específico");
    // Con todo el equipo no se piden fotos.
    expect(vista.find('[data-prueba="filtro-ana"]').exists()).toBe(false);

    // Alguien específico: queda el primero y se ven todos por su primer nombre.
    await vista.get('[data-prueba="filtro-alguien"] input').setValue();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ instructor_id: "ana" }) },
    );
    expect(
      vista.get('[data-prueba="filtro-ana"] .rc-profesional-nombre').text(),
    ).toBe("Ana");
    expect(
      vista.get('[data-prueba="filtro-luis"] .rc-profesional-nombre').text(),
    ).toBe("Luis");
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

    // La lupa no elige a la persona: solo muestra la foto en grande.
    await vista
      .get('[data-prueba="filtro-ana"] [data-prueba="ampliar-foto"]')
      .trigger("click");
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
    await vista.get("#rc-lada").setValue("+1");
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
        celular: "555 123 4567",
        email: "beto@correo.mx",
        como_nos_conocio: "instagram",
        nota: "Es mi primera vez.",
      }),
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
        celular: "55 1234 5678",
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
