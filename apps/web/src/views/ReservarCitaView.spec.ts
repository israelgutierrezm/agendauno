import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
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
const horarios = {
  data: {
    data: {
      slots: [
        { inicia: "2030-01-07T15:00:00Z", termina: "2030-01-07T15:30:00Z" },
      ],
    },
  },
};
function montar() {
  return mount(ReservarCitaView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}
beforeEach(() => {
  vi.clearAllMocks();
  mocks.query = {};
  mocks.get.mockResolvedValue(opciones());
});

describe("agenda pública visual", () => {
  it("elige sucursal antes del servicio y muestra profesionales con foto o inicial", async () => {
    const vista = montar();
    await flushPromises();
    expect(vista.findAll('input[name="sucursal"]')).toHaveLength(2);
    expect(vista.text()).toContain("Ciudad de México");
    expect(vista.find('input[value="servicio"]').exists()).toBe(false);
    await vista.get('input[value="centro"]').setValue();
    await vista.get('input[value="servicio"]').setValue();
    expect(vista.findAll('input[name="profesional"]')).toHaveLength(2);
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
    expect(
      (vista.get('input[value="norte"]').element as HTMLInputElement).checked,
    ).toBe(true);
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
    await vista.get('input[value="ana"]').setValue();
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await flushPromises();
    await vista
      .findAll("button")
      .find((b) => b.text() === "09:00")!
      .trigger("click");
    await vista.get("#rc-nom").setValue("Cliente de prueba");
    await vista
      .findAll("button")
      .find((b) => b.text() === es.reservar.agendarYPagar)!
      .trigger("click");
    await flushPromises();
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

  it("descarta horarios tardíos al cambiar sucursal y obliga a elegir profesional otra vez", async () => {
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
    await vista.get('input[value="ana"]').setValue();
    await vista.get("#rc-fecha").setValue("2030-01-07");
    await vista.get('input[value="norte"]').setValue();
    resolver(horarios);
    await flushPromises();
    expect(
      (vista.get('input[value="ana"]').element as HTMLInputElement).checked,
    ).toBe(false);
    expect(vista.find("#rc-fecha").exists()).toBe(false);
    expect(vista.find("#rc-nom").exists()).toBe(false);
    expect(vista.text()).not.toContain("09:00");
    vista.unmount();
  });
});
