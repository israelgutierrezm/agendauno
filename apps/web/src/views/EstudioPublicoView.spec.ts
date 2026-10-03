import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import EstudioPublicoView from "./EstudioPublicoView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/lib/seo", () => ({ updateSeo: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "estudio-a" }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

function escaparate(resenas: unknown) {
  return {
    data: {
      data: {
        estudio: {
          slug: "estudio-a",
          nombre: "Estudio A",
          logo_url: null,
          perfil: "pole",
          perfil_config: {},
          ciudad: null,
          pais: null,
          whatsapp: null,
          tiene_citas: false,
        },
        sucursales: [],
        instructores: [],
        productos: [],
        proximas_sesiones: [],
        resenas,
      },
    },
  };
}

function montar() {
  return mount(EstudioPublicoView, {
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

describe("página pública del estudio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("no deja crear una cuenta: se pide acceso al negocio (ADR 0093)", async () => {
    const respuesta = escaparate({ promedio: null, total: 0, recientes: [] });
    respuesta.data.data.estudio = {
      ...respuesta.data.data.estudio,
      whatsapp_url: "https://wa.me/525512345678",
    } as typeof respuesta.data.data.estudio;
    mocks.get.mockResolvedValue(respuesta);
    const w = montar();
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text() === "Reservar primera clase")!
      .trigger("click");
    const acceso = w.get('[data-prueba="pedir-acceso"]');
    expect(acceso.text()).toContain("Pide tu acceso");
    expect(acceso.find('input[type="password"]').exists()).toBe(false);
    expect(acceso.get("a[href^='https://wa.me']").text()).toBe(
      "Pedirlo por WhatsApp",
    );
  });

  it("muestra el promedio y los comentarios que el negocio deja visibles", async () => {
    mocks.get.mockResolvedValue(
      escaparate({
        promedio: 4.5,
        total: 2,
        recientes: [
          {
            calificacion: 5,
            comentario: "Excelente clase",
            nombre: "Ana",
            actividad: "Pole nivel 1",
            fecha: "2030-01-07",
          },
        ],
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Lo que dicen sus clientes");
    expect(w.text()).toContain("4.5 de 5");
    expect(w.text()).toContain("2 reseñas");
    expect(w.text()).toContain("Excelente clase");
    expect(w.text()).toContain("Ana · Pole nivel 1");
    expect(w.get('[role="img"]').attributes("aria-label")).toBe(
      "5 de 5 estrellas",
    );
  });

  it("sin reseñas visibles no muestra la sección", async () => {
    mocks.get.mockResolvedValue(
      escaparate({ promedio: null, total: 0, recientes: [] }),
    );
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Estudio A");
    expect(w.text()).not.toContain("Lo que dicen sus clientes");
  });

  it("muestra el perfil completo: redes, sedes con mapa y horario, servicios por categoría y horario de clases", async () => {
    const base = escaparate({ promedio: null, total: 0, recientes: [] });
    Object.assign(base.data.data.estudio, {
      descripcion: "Estudio de pole en la Roma.",
      redes: [{ red: "instagram", url: "https://www.instagram.com/estudio_a" }],
      whatsapp_url: "https://wa.me/525512345678",
    });
    Object.assign(base.data.data, {
      sucursales: [
        {
          nombre: "Roma Norte",
          zona_horaria: "America/Mexico_City",
          region: null,
          direccion: "Av. Álvaro Obregón 120",
          mapa_url: "https://www.google.com/maps/search/?api=1&query=x",
          telefono: "55 1234 5678",
          whatsapp_url: "https://wa.me/525512345678",
          redes: [{ red: "instagram", url: "https://www.instagram.com/roma" }],
          horario: [{ dia: 1, abre: "07:00", cierra: "21:00" }],
        },
      ],
      instructores: [
        { nombre: "Caro Díaz", foto_url: "https://cdn/caro.jpg" },
        { nombre: "Beto", foto_url: null },
      ],
      servicios: [
        {
          id: "o1",
          nombre: "Pole Nivel 1",
          descripcion: "Base de giros y trepa.",
          categoria: "Pole",
          grupal: true,
          duracion_minutos: 60,
          precio_minor: null,
          moneda: "MXN",
          agendable: false,
          niveles: ["Principiante", "Intermedio"],
        },
        {
          id: "o2",
          nombre: "Flexibilidad",
          descripcion: null,
          categoria: "Flex",
          grupal: true,
          duracion_minutos: 50,
          precio_minor: null,
          moneda: "MXN",
          agendable: false,
          niveles: [],
        },
      ],
      horario_clases: [
        {
          dia: 3,
          hora: "19:00",
          duracion_minutos: 60,
          clase: "Pole Nivel 1",
          categoria: "Pole",
          instructor: "Caro Díaz",
          sucursal: "Roma Norte",
        },
        {
          dia: 1,
          hora: "08:00",
          duracion_minutos: 50,
          clase: "Flexibilidad",
          categoria: "Flex",
          instructor: "Beto",
          sucursal: "Roma Norte",
        },
      ],
    });
    mocks.get.mockResolvedValue(base);
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="descripcion"]').text()).toBe(
      "Estudio de pole en la Roma.",
    );
    const redes = w.find('[data-prueba="redes"]').findAll("a");
    expect(redes.map((a) => a.attributes("href"))).toEqual([
      "https://www.instagram.com/estudio_a",
      "https://wa.me/525512345678",
    ]);

    const sede = w.find('[data-prueba="sede"]');
    expect(sede.text()).toContain("Av. Álvaro Obregón 120");
    expect(sede.text()).toContain("Cómo llegar");
    expect(sede.text()).toContain("Lunes");
    expect(sede.text()).toContain("07:00 – 21:00");
    expect(sede.text()).toContain("Cerrado");

    const servicios = w.find('[data-prueba="servicios"]');
    expect(servicios.text()).toContain("Base de giros y trepa.");
    expect(servicios.text()).toContain("Niveles: Principiante, Intermedio");
    // Filtrar por categoría.
    const flex = servicios.findAll("button").find((b) => b.text() === "Flex");
    await flex!.trigger("click");
    expect(servicios.text()).not.toContain("Pole Nivel 1");
    expect(servicios.text()).toContain("Flexibilidad");

    // Horario semanal en orden de lunes a domingo.
    const horario = w.find('[data-prueba="horario-clases"]').text();
    expect(horario.indexOf("Lunes")).toBeLessThan(horario.indexOf("Miércoles"));
    expect(horario).toContain("08:00");
    expect(horario).toContain("Caro Díaz");

    // Profesionales con foto (o iniciales si no tienen).
    expect(w.find('img[src="https://cdn/caro.jpg"]').exists()).toBe(true);
  });
});
