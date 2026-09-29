import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import ReservarCitaView from "./ReservarCitaView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  query: {} as Record<string, string>,
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/negociosRecientes", () => ({ recordarNegocio: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "demo" }, query: mocks.query }),
  useRouter: () => ({ replace: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

function opciones(sedes = 2) {
  return {
    data: {
      data: {
        estudio: { slug: "demo", nombre: "Negocio de prueba", logo_url: null },
        servicios: [
          {
            id: "servicio",
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
            region: "Ciudad de México",
            zona_horaria: "America/Mexico_City",
          },
          {
            id: "norte",
            nombre: "Norte",
            region: null,
            zona_horaria: "America/Mexico_City",
          },
        ].slice(0, sedes),
        instructores: [
          { id: "ana", nombre: "Ana Pérez", foto_url: "/storage/ana.webp" },
          { id: "luis", nombre: "Luis López", foto_url: null },
        ],
      },
    },
  };
}
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
async function agendarComo(vista: Vista): Promise<void> {
  await vista.get("#rc-nom").setValue("Cliente de prueba");
  await vista
    .findAll("button")
    .find((b) => b.text() === es.reservar.agendarYPagar)!
    .trigger("click");
  await flushPromises();
}
beforeEach(() => {
  vi.clearAllMocks();
  mocks.query = {};
  mocks.get.mockResolvedValue(opciones());
});

describe("agenda pública visual", () => {
  it("elige sucursal antes del servicio y, tras la hora, muestra con foto o inicial a quienes están libres", async () => {
    mocks.get.mockResolvedValueOnce(opciones()).mockResolvedValue(horarios);
    const vista = montar();
    await flushPromises();
    expect(vista.findAll('input[name="sucursal"]')).toHaveLength(2);
    expect(vista.text()).toContain("Ciudad de México");
    expect(vista.find('input[value="servicio"]').exists()).toBe(false);
    await vista.get('input[value="centro"]').setValue();
    await vista.get('input[value="servicio"]').setValue();
    // Primero el día y la hora: todavía no se pregunta con quién.
    expect(vista.find('input[name="profesional"]').exists()).toBe(false);
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
    await elegirHora(vista, "09:30");
    // Los dos libres a esa hora y, antes, «cualquier profesional disponible».
    expect(vista.findAll('input[name="profesional"]')).toHaveLength(3);
    const foto = vista.get('img[src="/storage/ana.webp"]');
    expect(
      vista.get('input[value="luis"]').element.closest("label")?.textContent,
    ).toContain("L");
    await foto.trigger("error");
    expect(vista.find('img[src="/storage/ana.webp"]').exists()).toBe(false);
    expect(
      vista.get('input[value="ana"]').element.closest("label")?.textContent,
    ).toContain("A");
    vista.unmount();
  });

  it("selecciona automáticamente la única sede y respeta una sede del enlace", async () => {
    mocks.get.mockResolvedValueOnce(opciones(1));
    let vista = montar();
    await flushPromises();
    expect(vista.find('input[name="sucursal"]').exists()).toBe(false);
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    vista.unmount();
    mocks.query = { sucursal: "norte" };
    vista = montar();
    await flushPromises();
    expect(marcado(vista, 'input[value="norte"]')).toBe(true);
    expect(vista.find('input[value="servicio"]').exists()).toBe(true);
    vista.unmount();
  });

  it("confirma en success sin confundir una cita apartada con un pago realizado", async () => {
    mocks.get.mockResolvedValueOnce(opciones(1)).mockResolvedValue(horarios);
    mocks.post.mockResolvedValue({
      data: { data: { orden_id: "orden", total_minor: 20000, moneda: "MXN" } },
    });
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="servicio"]').setValue();
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
    await elegirHora(vista, "09:00");
    await vista.get('input[value="ana"]').setValue();
    await agendarComo(vista);
    expect(vista.get("h2.text-success").text()).toBe(es.reservar.listoTitulo);
    expect(vista.get('[role="status"]').text()).toContain(es.reservar.apartado);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({
        sucursal_id: "centro",
        instructor_id: "ana",
        oferta_id: "servicio",
      }),
    );
    vista.unmount();
  });

  it("parte de «cualquier profesional»: ve los horarios de todo el equipo, no manda profesional y dice quién atenderá", async () => {
    mocks.get.mockResolvedValueOnce(opciones(1)).mockResolvedValue(horarios);
    mocks.post.mockResolvedValue({
      data: {
        data: {
          orden_id: "orden",
          total_minor: 20000,
          moneda: "MXN",
          profesional: { id: "luis", nombre: "Luis López" },
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="servicio"]').setValue();
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
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
    expect(vista.text()).toContain("Cualquier profesional disponible · 09:30");
    await agendarComo(vista);
    expect(mocks.post.mock.calls[0][1]).not.toHaveProperty("instructor_id");
    expect(vista.get('[role="status"]').text()).toContain(
      "Corte con Luis López",
    );
    vista.unmount();
  });

  it("al cambiar de hora solo ofrece a quienes siguen libres y suelta a quien ya no lo está", async () => {
    mocks.get.mockResolvedValueOnce(opciones(1)).mockResolvedValue(horarios);
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="servicio"]').setValue();
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
    await elegirHora(vista, "09:30");
    await vista.get('input[value="luis"]').setValue();
    // A las 09:00 Luis está ocupado: ya no aparece y se vuelve a «cualquiera».
    await elegirHora(vista, "09:00");
    expect(vista.text()).toContain("Libres a las 09:00.");
    expect(vista.find('input[value="luis"]').exists()).toBe(false);
    expect(vista.find('input[value="ana"]').exists()).toBe(true);
    expect(marcado(vista, '[data-prueba="cualquiera"] input')).toBe(true);
    // Ana sí sigue libre al volver a las 09:30.
    await vista.get('input[value="ana"]').setValue();
    await elegirHora(vista, "09:30");
    expect(marcado(vista, 'input[value="ana"]')).toBe(true);
    vista.unmount();
  });

  it("con alguien de preferencia ve solo sus horarios y ya no pregunta con quién", async () => {
    mocks.get.mockResolvedValueOnce(opciones(1)).mockResolvedValue(horarios);
    mocks.post.mockResolvedValue({
      data: { data: { orden_id: "orden", total_minor: 20000, moneda: "MXN" } },
    });
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="servicio"]').setValue();
    await vista.get("#rc-filtro").setValue("luis");
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/citas/disponibilidad",
      { params: expect.objectContaining({ instructor_id: "luis" }) },
    );
    await elegirHora(vista, "09:30");
    expect(vista.find('input[name="profesional"]').exists()).toBe(false);
    expect(vista.text()).toContain("Luis López · 09:30");
    await agendarComo(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/citas",
      expect.objectContaining({ instructor_id: "luis" }),
    );
    vista.unmount();
  });

  it("descarta horarios tardíos al cambiar sucursal y vuelve a ver todo el equipo", async () => {
    let resolver!: (value: typeof horarios) => void;
    mocks.get.mockResolvedValueOnce(opciones()).mockImplementation(
      () =>
        new Promise((resolve) => {
          resolver = resolve;
        }),
    );
    const vista = montar();
    await flushPromises();
    await vista.get('input[value="centro"]').setValue();
    await vista.get('input[value="servicio"]').setValue();
    await vista.get("#rc-filtro").setValue("ana");
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await vista.get('input[value="norte"]').setValue();
    resolver(horarios);
    await flushPromises();
    expect((vista.get("#rc-filtro").element as HTMLSelectElement).value).toBe(
      "",
    );
    expect((vista.get("#rc-fecha").element as HTMLInputElement).value).toBe("");
    expect(vista.find("#rc-nom").exists()).toBe(false);
    expect(vista.text()).not.toContain("09:00");
    vista.unmount();
  });
});
