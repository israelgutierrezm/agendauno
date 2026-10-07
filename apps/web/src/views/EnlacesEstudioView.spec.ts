import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import EnlacesEstudioView from "./EnlacesEstudioView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api: { get: mocks.get } }));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/lib/seo", () => ({ updateSeo: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "casa-navaja" }, query: {} }),
  RouterLink: {
    props: ["to"],
    template: "<a :data-ruta='JSON.stringify(to)'><slot /></a>",
  },
}));

function datos(extra: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        estudio: {
          slug: "casa-navaja",
          nombre: "Casa Navaja",
          logo_url: null,
          descripcion: "Barbería profesional.",
          redes: [
            { red: "instagram", url: "https://www.instagram.com/casa_navaja" },
            { red: "sitio_web", url: "https://casanavaja.mx" },
          ],
          whatsapp_url: "https://wa.me/526145517175",
          modalidad: "citas",
          capacidades: { clases: false, citas: true },
        },
        sucursales: [
          {
            nombre: "Cantera",
            mapa_url: "https://www.google.com/maps/search/?api=1&query=x",
          },
        ],
        horario_clases: [],
        productos: [],
        resenas: { promedio: 5, total: 33 },
        ...extra,
      },
    },
  };
}

function montar() {
  return mount(EnlacesEstudioView, {
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

describe("página de enlaces del negocio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("para citas: agendar, web, WhatsApp, ubicación, redes y calificación", async () => {
    mocks.get.mockResolvedValue(datos());
    const w = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/casa-navaja/escaparate",
    );
    const botones = w
      .find('[data-prueba="botones"]')
      .findAll("a")
      .map((a) => a.text());
    expect(botones).toEqual([
      "Agenda tu cita",
      "Visita nuestra web",
      "Escríbenos por WhatsApp",
      "Nuestra ubicación",
      "Conoce más de nosotros",
    ]);
    expect(w.find('[data-prueba="calificacion"]').text()).toContain("5.0");
    // El sitio web va como botón, no entre los íconos de redes.
    expect(
      w
        .find('[data-prueba="redes"]')
        .findAll("a")
        .map((a) => a.attributes("href")),
    ).toEqual(["https://www.instagram.com/casa_navaja"]);
    expect(w.text()).toContain("Crea la página de tu negocio");
  });

  it("para clases: reservar, horario y precios (estudio de pole)", async () => {
    const d = datos({
      horario_clases: [{ dia: 1 }],
      productos: [{ nombre: "8 clases" }],
      sucursales: [
        { nombre: "Roma", mapa_url: "https://maps/roma" },
        { nombre: "Condesa", mapa_url: "https://maps/condesa" },
      ],
    });
    Object.assign(d.data.data.estudio, {
      modalidad: "clases",
      capacidades: { clases: true, citas: false },
    });
    mocks.get.mockResolvedValue(d);
    const w = montar();
    await flushPromises();

    const botones = w
      .find('[data-prueba="botones"]')
      .findAll("a")
      .map((a) => a.text());
    expect(botones.slice(0, 3)).toEqual([
      "Reserva tu clase",
      "Horario de clases",
      "Planes y precios",
    ]);
    expect(botones).toContain("Ubicación · Roma");
    expect(botones).toContain("Ubicación · Condesa");
    expect(botones).not.toContain("Agenda tu cita");
  });

  it("un negocio de citas no ofrece reservar clase aunque tenga servicios grupales (ADR 0104)", async () => {
    mocks.get.mockResolvedValue(
      datos({ servicios: [{ grupal: true }], horario_clases: [] }),
    );
    const w = montar();
    await flushPromises();

    const botones = w
      .find('[data-prueba="botones"]')
      .findAll("a")
      .map((a) => a.text());
    expect(botones[0]).toBe("Agenda tu cita");
    expect(botones).not.toContain("Reserva tu clase");
  });
});
