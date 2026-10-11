import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import sitioWeb from "@/i18n/locales/sitioWeb.es-MX";
import { updateSeo } from "@/lib/seo";
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
          modalidad: "clases",
          capacidades: { clases: true, citas: false },
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

function montar(props: { vistaPrevia?: boolean } = {}) {
  return mount(EstudioPublicoView, {
    props,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, perfilPublico, sitioWeb } },
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

  it("«Agendar una cita» sale de la modalidad, no de sus ofertas (ADR 0104)", async () => {
    // Un estudio de clases con una clase de pago no se vuelve de citas.
    const clases = escaparate({ promedio: null, total: 0, recientes: [] });
    Object.assign(clases.data.data.estudio, { tiene_citas: true });
    mocks.get.mockResolvedValue(clases);
    const w = montar();
    await flushPromises();
    expect(w.text()).not.toContain("Agendar una cita");
    expect(w.text()).toContain("Reservar primera clase");

    const citas = escaparate({ promedio: null, total: 0, recientes: [] });
    Object.assign(citas.data.data.estudio, {
      modalidad: "citas",
      capacidades: { clases: false, citas: true },
    });
    mocks.get.mockResolvedValue(citas);
    const conCitas = montar();
    await flushPromises();
    expect(conCitas.text()).toContain("Agendar una cita");
    expect(conCitas.text()).not.toContain("Reservar primera clase");
  });

  it("muestra el giro como insignia, salvo los generales del registro", async () => {
    mocks.get.mockResolvedValue(
      escaparate({ promedio: null, total: 0, recientes: [] }),
    );
    const pole = montar();
    await flushPromises();
    expect(pole.get('[data-prueba="insignia-giro"]').text()).toBe(
      es.registro.perfiles.pole,
    );
    // «Otro negocio con clases / de citas» es para el selector del registro: a los
    // clientes no se les muestra.
    for (const [perfil, modalidad] of [
      ["general", "clases"],
      ["general_citas", "citas"],
    ] as const) {
      const respuesta = escaparate({ promedio: null, total: 0, recientes: [] });
      Object.assign(respuesta.data.data.estudio, {
        perfil,
        modalidad,
        capacidades: {
          clases: modalidad === "clases",
          citas: modalidad === "citas",
        },
      });
      mocks.get.mockResolvedValue(respuesta);
      const w = montar();
      await flushPromises();
      expect(w.find('[data-prueba="insignia-giro"]').exists()).toBe(false);
      expect(w.text()).not.toContain("Otro negocio");
      expect(w.get("h1").text()).toBe("Estudio A");
    }
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

  it("para quien llega por primera vez: planes comparables, clases para principiantes y el equipo", async () => {
    const base = escaparate({ promedio: null, total: 0, recientes: [] });
    const plan = (extra: Record<string, unknown>) => ({
      tipo: "paquete",
      precio_minor: 100000,
      moneda: "MXN",
      ilimitado: false,
      creditos_incluidos: 8000,
      ...extra,
    });
    Object.assign(base.data.data, {
      captacion: { activa: true, formulario: "f1" },
      productos: [
        plan({
          id: "p1",
          nombre: "Clase muestra",
          primera_vez: true,
          precio_minor: 15000,
          creditos_incluidos: 1000,
          vence_si_compra_hoy: "2026-10-08",
        }),
        plan({ id: "p2", nombre: "Pack 4", creditos_incluidos: 4000 }),
        plan({
          id: "p3",
          nombre: "Pack 8",
          destacado: true,
          descripcion: "8 clases en un mes.",
          actividad: "Pole",
          vence_si_compra_hoy: "2026-11-01",
        }),
      ],
      servicios: [
        {
          id: "o1",
          nombre: "Pole Básico",
          descripcion: null,
          categoria: null,
          grupal: true,
          duracion_minutos: 60,
          precio_minor: null,
          moneda: "MXN",
          agendable: false,
          niveles: [],
          para_principiantes: true,
          requisitos: "Ninguno",
          que_llevar: "Short y top",
        },
        {
          id: "o2",
          nombre: "Pole Avanzado",
          descripcion: null,
          categoria: null,
          grupal: true,
          duracion_minutos: 60,
          precio_minor: null,
          moneda: "MXN",
          agendable: false,
          niveles: [],
        },
      ],
      instructores: [
        {
          nombre: "Diana",
          foto_url: null,
          semblanza: "Instructora desde 2015.",
        },
      ],
    });
    mocks.get.mockResolvedValue(base);
    const w = mount(EstudioPublicoView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            messages: { es: { ...es, perfilPublico, sitioWeb } },
          }),
        ],
        stubs: {
          FormularioCaptacion: {
            props: ["interes"],
            template:
              '<div data-prueba="form-captacion">{{ interes?.producto }}</div>',
          },
        },
      },
    });
    await flushPromises();

    // El recomendado primero, luego la clase de prueba; con la fecha exacta.
    const precios = w.findAll('[data-prueba="precio"]');
    // Agrupados: primero lo de la primera vez; en cada grupo, el recomendado primero.
    expect(
      w.findAll('[data-prueba="grupo-precios"] h3').map((h) => h.text()),
    ).toEqual(["Para tu primera vez", "Paquetes de clases"]);
    expect(precios.map((p) => p.find(".font-light").text())).toEqual([
      "Clase muestra",
      "Pack 8",
      "Pack 4",
    ]);
    expect(precios[1]!.find('[data-prueba="destacado"]').exists()).toBe(true);
    expect(precios[1]!.text()).toContain("8 clases en un mes.");
    expect(precios[1]!.text()).toContain("Para Pole");
    expect(precios[0]!.get('[data-prueba="vence"]').text()).toBe(
      "Si lo pagas hoy, se puede usar hasta el 8 de octubre",
    );
    expect(precios[0]!.text()).toContain(
      "Solo para quien viene por primera vez",
    );
    await precios[0]!.get('[data-prueba="lo-quiero"]').trigger("click");
    expect(w.get('[data-prueba="form-captacion"]').text()).toBe("p1");

    // Clases para principiantes: qué saber, qué llevar y su filtro.
    const servicios = w.get('[data-prueba="servicios"]');
    expect(servicios.findAll('[data-prueba="principiantes"]')).toHaveLength(1);
    expect(servicios.get('[data-prueba="primera-vez-clase"]').text()).toContain(
      "Short y top",
    );
    await servicios
      .get('[data-prueba="solo-principiantes"] input')
      .setValue(true);
    expect(servicios.text()).not.toContain("Pole Avanzado");

    expect(w.get('[data-prueba="integrante"]').text()).toContain(
      "Instructora desde 2015.",
    );
  });

  it("con varias sedes: cada plan dice dónde vale y la sede elegida en el calendario filtra los planes (M06)", async () => {
    const base = escaparate({ promedio: null, total: 0, recientes: [] });
    const plan = (extra: Record<string, unknown>) => ({
      tipo: "paquete",
      precio_minor: 100000,
      moneda: "MXN",
      ilimitado: false,
      creditos_incluidos: 8000,
      ...extra,
    });
    Object.assign(base.data.data, {
      sucursales: [
        {
          id: "roma",
          nombre: "Roma",
          zona_horaria: null,
          region: "Roma Norte",
          direccion: "Calle 1",
          foto_url: "/f.jpg",
        },
        { id: "polanco", nombre: "Polanco", zona_horaria: null, region: null },
      ],
      productos: [
        plan({
          id: "p1",
          nombre: "Todas",
          todas_sucursales: true,
          sucursales: [],
        }),
        plan({
          id: "p2",
          nombre: "Solo Roma",
          todas_sucursales: false,
          sucursales: [{ id: "roma", nombre: "Roma" }],
        }),
      ],
    });
    mocks.get.mockResolvedValue(base);
    const w = mount(EstudioPublicoView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            messages: { es: { ...es, perfilPublico, sitioWeb } },
          }),
        ],
        stubs: {
          CalendarioPublico: {
            props: ["sucursal"],
            emits: ["update:sucursal"],
            template: `<button data-prueba="elegir-polanco" @click="$emit('update:sucursal', 'polanco')" />`,
          },
        },
      },
    });
    await flushPromises();

    const textos = () =>
      w.findAll('[data-prueba="precio"]').map((p) => p.text());
    expect(textos()[0]).toContain("Todas las sedes incluidas");
    expect(textos()[1]).toContain("Solo en Roma");
    // La sede con su foto, zona y dirección.
    const sede = w.findAll('[data-prueba="sede"]')[0]!;
    expect(sede.text()).toContain("Roma Norte · Calle 1");
    expect(sede.find("img").exists()).toBe(true);

    await w.get('[data-prueba="elegir-polanco"]').trigger("click");
    expect(textos()).toHaveLength(1);
    expect(w.get('[data-prueba="planes-de-sede"]').text()).toContain(
      "Planes que valen en Polanco.",
    );
  });

  it("su sitio decide el orden, lo que se ve, sus textos, banners y color (ADR 0114)", async () => {
    const respuesta = escaparate({ promedio: null, total: 0, recientes: [] });
    Object.assign(respuesta.data.data, {
      sitio: {
        plantilla: "compacta",
        secciones: [
          {
            tipo: "inicio",
            titulo: "Fluō, pole y aéreos",
            texto: "Clases para todos los niveles.",
            foto_url: null,
          },
          {
            tipo: "nosotros",
            titulo: "Quiénes somos",
            texto: "Un estudio de barrio.",
            foto_url: "https://cdn/nosotros.jpg",
          },
          { tipo: "promociones", titulo: null, texto: null, foto_url: null },
          {
            tipo: "precios",
            titulo: "Membresías",
            texto: null,
            foto_url: null,
          },
          {
            tipo: "contacto",
            titulo: "Escríbenos",
            texto: "Te respondemos hoy.",
            foto_url: null,
          },
        ],
        banners: [
          {
            id: "b1",
            titulo: "Primera clase gratis",
            texto: "Solo en octubre",
            enlace_texto: "Ver precios",
            enlace_url: "#precios",
            foto_url: null,
          },
        ],
      },
    });
    Object.assign(respuesta.data.data.estudio, { color_marca: "#f5d76e" });
    mocks.get.mockResolvedValue(respuesta);
    const w = montar();
    await flushPromises();

    expect(w.get("h1").text()).toBe("Fluō, pole y aéreos");
    expect(w.get('[data-prueba="portada"]').attributes("data-portada")).toBe(
      "compacta",
    );
    expect(w.get('[data-prueba="descripcion"]').text()).toBe(
      "Clases para todos los niveles.",
    );
    // En su orden; lo que ocultó (próximas clases, reseñas…) no está.
    const ids = w
      .findAll("section[id]")
      .map((x) => x.attributes("id"))
      .filter((id) => id !== "inicio");
    expect(ids).toEqual(["nosotros", "promociones", "precios", "contacto"]);
    expect(w.text()).not.toContain("Próximas clases");
    expect(w.get("#precios h2").text()).toBe("Membresías");
    expect(w.get("#nosotros img").attributes("src")).toBe(
      "https://cdn/nosotros.jpg",
    );
    const banner = w.get('[data-prueba="promociones"]');
    expect(banner.text()).toContain("Primera clase gratis");
    expect(banner.get("a").attributes("href")).toBe("#precios");
    expect(banner.get("a").attributes("target")).toBeUndefined();
    expect(w.get('[data-prueba="contacto"]').text()).toContain(
      "Te respondemos hoy.",
    );
    // Su color en los botones, con texto que se lee encima.
    const estilo = w.get('[data-prueba="sitio"]').attributes("style");
    expect(estilo).toContain("--marketing-cta: #f5d76e");
    expect(estilo).toContain("--marketing-cta-contraste: #111111");
  });

  it("la vista previa pide el borrador y no cuenta como visita", async () => {
    mocks.get.mockResolvedValue(
      escaparate({ promedio: null, total: 0, recientes: [] }),
    );
    const w = montar({ vistaPrevia: true });
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/sitio/vista-previa",
    );
    expect(w.find('[data-prueba="aviso-vista-previa"]').exists()).toBe(true);
    expect(updateSeo).not.toHaveBeenCalled();
    // Sin sitio en la respuesta, la página de siempre.
    expect(w.find("#precios").exists()).toBe(true);
  });

  it("la plantilla «Portada» pone la foto a todo lo ancho; sin foto, la esencial", async () => {
    for (const [portada, esperada] of [
      ["https://cdn/portada.jpg", "foto"],
      [null, "esencial"],
    ] as const) {
      const respuesta = escaparate({ promedio: null, total: 0, recientes: [] });
      Object.assign(respuesta.data.data.estudio, { portada_url: portada });
      Object.assign(respuesta.data.data, {
        sitio: {
          plantilla: "portada",
          secciones: [
            { tipo: "inicio", titulo: null, texto: null, foto_url: null },
            { tipo: "contacto", titulo: null, texto: null, foto_url: null },
          ],
          banners: [],
        },
      });
      mocks.get.mockResolvedValue(respuesta);
      const w = montar();
      await flushPromises();
      const hero = w.get('[data-prueba="portada"]');
      expect(hero.attributes("data-portada")).toBe(esperada);
      if (portada) {
        expect(hero.attributes("style")).toContain(portada);
      }
      w.unmount();
    }
  });
});
