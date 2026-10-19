import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ResumenDelDia from "./ResumenDelDia.vue";

// Que ningún Inicio de una prueba anterior siga escuchando el regreso a la pestaña.
enableAutoUnmount(afterEach);

const api = vi.hoisted(() => ({
  get: vi.fn(),
  permisos: null as string[] | null,
  // La modalidad sale de la sesión (ADR 0104), no de la respuesta del día.
  esCitas: false,
  modalidad: undefined as string | undefined,
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No se pudo cargar.",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    estudio: { nombre: "Estudio Demo", perfil: "pole" },
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
    zonaHoraria: "America/Mexico_City",
    get esCitas() {
      return api.esCitas;
    },
    get modalidad() {
      return api.modalidad;
    },
    puede: (permiso: string) =>
      api.permisos === null || api.permisos.includes(permiso),
  }),
}));

const sesion = (extra: Record<string, unknown>) => ({
  id: "s1",
  tipo: "clase",
  oferta: "Pole Nivel 1",
  instructor: "Caro",
  sucursal: "Roma Norte",
  cliente: null,
  inicia_en: "2026-10-01T14:00:00Z",
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  esperados: 3,
  llegaron: 1,
  sin_marcar: 0,
  cancelada: false,
  momento: "proxima",
  ...extra,
});

function montar() {
  return mount(ResumenDelDia, {
    global: {
      plugins: [i18n],
      stubs: {
        RouterLink: {
          props: ["to"],
          template: "<a :data-to='JSON.stringify(to)'><slot /></a>",
        },
      },
    },
  });
}

// El inicio abre en «Por comenzar»: lo que ya pasó o se canceló se ve en «Todas».
async function verTodas(w: ReturnType<typeof montar>): Promise<void> {
  await w
    .findAll("button")
    .find((b) => b.text() === "Todas")!
    .trigger("click");
}

describe("el día de hoy en el Inicio", () => {
  it("pagina la agenda del inicio sin omitir sesiones y vuelve al inicio al buscar", async () => {
    api.get.mockImplementation(async (url: string) => ({
      data: {
        data: url.endsWith("/clima")
          ? null
          : {
              fecha: "2026-10-01",
              agenda: {
                totales: {
                  sesiones: 8,
                  esperados: 16,
                  llegaron: 0,
                  sin_marcar: 0,
                  capacidad: 80,
                },
                sesiones: Array.from({ length: 8 }, (_, i) =>
                  sesion({ id: `s${i}`, oferta: `Clase ${i + 1}` }),
                ),
              },
              cobros: null,
              renovaciones: null,
            },
      },
    }));
    const w = montar();
    await flushPromises();
    expect(w.findAll(".hoy-agenda-lista>li")).toHaveLength(6);
    expect(w.get('[role="img"]').attributes("aria-label")).toBe(
      "Ocupación de tus clases: 20%",
    );
    await w
      .findAll("button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    expect(w.findAll(".hoy-agenda-lista>li")).toHaveLength(2);
    expect(w.get(".hoy-agenda-lista").text()).toContain("Clase 8");
    await w.get('input[type="search"]').setValue("Clase 1");
    expect(w.findAll(".hoy-agenda-lista>li")).toHaveLength(1);
    expect(w.get(".hoy-agenda-lista").text()).toContain("Clase 1");
  });
  beforeEach(() => {
    vi.clearAllMocks();
    api.permisos = null;
    api.esCitas = false;
    api.modalidad = undefined;
  });

  it("indicadores, agenda con lo que falta marcar y pendientes", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: {
              sesiones: 2,
              esperados: 5,
              llegaron: 1,
              sin_marcar: 2,
              capacidad: 20,
              listas_pendientes: 1,
              en_espera: 2,
            },
            sesiones: [
              sesion({ id: "s1", momento: "termino", sin_marcar: 2 }),
              sesion({
                id: "s2",
                tipo: "cita",
                oferta: "Corte",
                cliente: "Dana",
                capacidad: 1,
                esperados: 1,
                inicia_en: "2026-10-01T18:00:00Z",
                momento: "en_curso",
              }),
            ],
          },
          cobros: {
            ordenes_pendientes: 2,
            por_cobrar: [{ moneda: "MXN", total_minor: 50000 }],
            en_mora: 0,
          },
          renovaciones: { por_vencer: 3, vencidas: 0, dias: 7 },
        },
      },
    });
    const w = montar();
    await flushPromises();
    await verTodas(w);
    const texto = w.text();

    // La fecha local del día va a la API.
    expect(api.get.mock.calls[0][1].params.fecha).toMatch(
      /^\d{4}-\d{2}-\d{2}$/,
    );
    // La tarjeta principal: lo que está en curso y los indicadores del día.
    expect(texto).toContain("En curso");
    expect(texto).toContain("Corte · Dana");
    expect(texto).toContain("Abrir agenda");
    // Clases: ocupación, listas por registrar, espera y planes por vencer.
    expect(texto).toContain("Lugares ocupados");
    expect(texto).toContain("5 de 20");
    expect(texto).toContain("Listas por registrar");
    expect(texto).toContain("En lista de espera");
    expect(texto).toContain("Planes por vencer");
    expect(texto).not.toContain("Por cobrar");
    expect(texto).toContain("08:00");
    expect(texto).toContain("Falta pasar lista a 2 personas");
    expect(texto).toContain("3 de 10");
    expect(texto).toContain("Corte · Dana");
    expect(texto).toContain("En curso");
    expect(texto).toContain("2 órdenes por cobrar");
    expect(texto).toContain("$500.00");
    expect(texto).toContain("3 membresías vencen en 7 días o menos");
  });

  it("cada indicador y pendiente abre su listado ya filtrado; también solicitudes y seguimientos (M08)", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: {
              sesiones: 1,
              esperados: 1,
              llegaron: 0,
              sin_marcar: 0,
              capacidad: 10,
              listas_pendientes: 0,
              en_espera: 0,
            },
            sesiones: [sesion({})],
          },
          cobros: {
            ordenes_pendientes: 0,
            por_cobrar: [],
            en_mora: 1,
          },
          renovaciones: { por_vencer: 3, vencidas: 2, dias: 7 },
          manana: { fecha: "2026-10-02", poca_ocupacion: 2 },
          atencion: { solicitudes: 2, seguimientos: 1 },
        },
      },
    });
    const w = montar();
    await flushPromises();

    const destinos = w.findAll("a").map((a) => a.attributes("data-to") ?? "");
    const porVencer = JSON.stringify({
      name: "retencion",
      query: { estado: "por_vencer", dias: "7" },
    });
    // La tarjeta y el pendiente de planes por vencer: la lista ya filtrada.
    expect(
      w.get('[data-prueba="indicador-porVencer"] a').attributes("data-to"),
    ).toBe(porVencer);
    expect(destinos).toContain(porVencer);
    expect(destinos).toContain(
      JSON.stringify({
        name: "retencion",
        query: { estado: "vencida", dias: "7" },
      }),
    );
    expect(destinos).toContain(
      JSON.stringify({ name: "cobranza", hash: "#morosos" }),
    );
    expect(
      w.get('[data-prueba="pendiente-solicitudes"]').attributes("data-to"),
    ).toBe(JSON.stringify({ name: "prospectos", query: { estado: "nuevo" } }));
    expect(w.text()).toContain("2 solicitudes de acceso sin atender");
    expect(
      w.get('[data-prueba="pendiente-seguimientos"]').attributes("data-to"),
    ).toBe(JSON.stringify({ name: "tareas", query: { tipo: "seguimiento" } }));
    // Mañana con poca gente (el enlace a Lugares libres de ese día lo cuida su
    // permiso de clases; aquí, la cifra).
    expect(w.get('[data-prueba="indicador-mananaPoca"]').text()).toContain(
      "Mañana, a menos de la mitad",
    );
  });

  it("las tarjetas de clases abren lo que cuentan y se ven los bloqueos del día (ADR 0166)", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: {
              sesiones: 2,
              esperados: 3,
              ocupados: 4,
              llegaron: 0,
              sin_marcar: 1,
              capacidad: 20,
              listas_pendientes: 1,
              en_espera: 2,
            },
            sesiones: [sesion({})],
            bloqueos: [
              {
                id: "b1",
                motivo: "Comida",
                de: "Caro",
                tipo: "profesional",
                desde: "2026-10-01T20:00:00Z",
                hasta: "2026-10-01T21:00:00Z",
                todo_el_dia: false,
                zona_horaria: "America/Mexico_City",
              },
            ],
          },
          cobros: null,
          renovaciones: null,
          manana: { fecha: "2026-10-02", poca_ocupacion: 2 },
        },
      },
    });
    const w = montar();
    await flushPromises();

    const destino = (clave: string) =>
      JSON.parse(
        w.get(`[data-prueba="indicador-${clave}"] a`).attributes("data-to")!,
      );
    // Los ocupados con la regla de cupo (4), no los esperados (3).
    expect(w.get('[data-prueba="indicador-ocupados"]').text()).toContain("4");
    expect(destino("clases").query.filtro).toBe("activas");
    expect(destino("listas").query.filtro).toBe("listas");
    expect(destino("enEspera")).toEqual({
      name: "recepcion",
      query: { fecha: "2026-10-01", filtro: "enEspera" },
    });
    expect(w.get('[data-prueba="bloqueos-hoy"]').text()).toContain(
      "Caro · 14:00–15:00 · Comida",
    );
  });

  it("se pone al día con «Actualizar» y al volver a la pestaña, sin quitar lo que se ve", async () => {
    const dia = (sesiones: number) => ({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: {
              sesiones,
              esperados: 0,
              llegaron: 0,
              sin_marcar: 0,
              capacidad: 10,
              listas_pendientes: 0,
              en_espera: 0,
            },
            sesiones: [sesion({ id: "s1" })],
          },
          cobros: null,
          renovaciones: null,
        },
      },
    });
    // Cuántas veces se pidió el día (el clima va por su lado).
    const pedidosDelDia = () =>
      api.get.mock.calls.filter(([url]) => String(url).endsWith("/inicio/hoy"))
        .length;
    api.get.mockImplementation((url: string) =>
      Promise.resolve(
        url.endsWith("/clima") ? { data: { data: null } } : dia(1),
      ),
    );
    const w = montar();
    await flushPromises();
    expect(pedidosDelDia()).toBe(1);
    expect(w.get('[data-prueba="actualizado-hace"]').text()).toContain(
      "Actualizado hace un momento",
    );

    // «Actualizar»: vuelve a pedir el día; mientras llega, lo de antes sigue a la vista.
    let terminar: (v: unknown) => void = () => undefined;
    api.get.mockReturnValueOnce(new Promise((r) => (terminar = r)));
    await w.get('[data-prueba="actualizar"]').trigger("click");
    expect(pedidosDelDia()).toBe(2);
    expect(w.text()).toContain("Pole Nivel 1");
    expect(w.text()).not.toContain("Cargando…");
    terminar(dia(1));
    await flushPromises();

    // Al volver a la pestaña (pasado el mínimo entre recargas), también.
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(Date.now() + 60_000);
    window.dispatchEvent(new Event("focus"));
    await flushPromises();
    vi.useRealTimers();
    expect(pedidosDelDia()).toBe(3);

    // Si una actualización falla, lo que se ve sigue y se avisa que es de antes.
    api.get.mockRejectedValueOnce(new Error("red"));
    await w.get('[data-prueba="actualizar"]').trigger("click");
    await flushPromises();
    expect(w.text()).toContain("Pole Nivel 1");
    expect(w.get('[data-prueba="desactualizado"]').text()).toContain(
      "No se pudo actualizar",
    );
  });

  it("sin permisos de cobro ni agenda no muestra esos bloques; el error no es un día vacío", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: null,
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.text()).not.toContain("Agenda de hoy");
    expect(w.text()).not.toContain("Pendientes");

    api.get.mockRejectedValueOnce(new Error("red"));
    const conError = montar();
    await flushPromises();
    expect(conError.text()).toContain("No se pudo cargar.");
    expect(conError.text()).not.toContain("No hay clases hoy.");
  });

  it("en citas: quién viene después, qué falta por atender y cobrar, y los espacios libres", async () => {
    api.esCitas = true;
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: {
              sesiones: 2,
              esperados: 2,
              llegaron: 1,
              sin_marcar: 0,
              por_atender: 1,
              por_cobrar: 1,
            },
            sesiones: [
              sesion({
                id: "c1",
                tipo: "cita",
                oferta: "Corte",
                cliente: "Dana",
                capacidad: 1,
                esperados: 1,
                llegaron: 1,
                momento: "termino",
              }),
              sesion({
                id: "c2",
                tipo: "cita",
                oferta: "Barba",
                cliente: "Eli",
                capacidad: 1,
                esperados: 1,
                llegaron: 0,
                por_cobrar: true,
                inicia_en: "2026-10-01T18:00:00Z",
                momento: "proxima",
              }),
            ],
          },
          libres: [
            {
              profesional: "Caro",
              sucursal: "Roma Norte",
              zona_horaria: "America/Mexico_City",
              huecos: 6,
              siguiente: "2026-10-01T19:30:00Z",
            },
          ],
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await verTodas(w);
    const texto = w.text();

    expect(texto).toContain("Quién viene después");
    expect(texto).toContain("Eli");
    expect(w.get('[data-prueba="detalle-siguiente"]').text()).toContain(
      "Barba · Caro",
    );
    expect(texto).toContain("Por atender");
    expect(texto).toContain("Llegó");
    expect(w.get('[data-prueba="cita-por-cobrar"]').text()).toBe("Por cobrar");
    expect(texto).not.toContain("Listas por registrar");
    const libres = w.get('[data-prueba="libres"]').text();
    expect(libres).toContain("Caro");
    expect(libres).toContain("6 espacios");
    expect(libres).toContain("desde las 13:30");
    // Cada tarjeta abre la jornada de Recepción ya filtrada en lo que cuenta.
    expect(
      w.get('[data-prueba="indicador-porCobrar"] a').attributes("data-to"),
    ).toBe(
      JSON.stringify({
        name: "recepcion",
        query: { fecha: "2026-10-01", filtro: "porCobrar" },
      }),
    );
    expect(
      w.get('[data-prueba="indicador-llegaron"] a').attributes("data-to"),
    ).toBe(
      JSON.stringify({
        name: "recepcion",
        query: { fecha: "2026-10-01", filtro: "llegaron" },
      }),
    );
  });
  it("abre en «Por comenzar» sin quitar «Todas»; si ya no queda nada, ofrece verlas", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: { sesiones: 3, esperados: 6, llegaron: 0, sin_marcar: 0 },
            sesiones: [
              sesion({ id: "uno", oferta: "Pole", momento: "proxima" }),
              sesion({ id: "dos", oferta: "Exotic", momento: "termino" }),
              sesion({ id: "tres", oferta: "Flex", momento: "en_curso" }),
            ],
          },
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    const activo = () =>
      w
        .findAll('[role="group"] button')
        .filter((b) => b.attributes("aria-pressed") === "true")
        .map((b) => b.text());
    expect(activo()).toEqual(["Por comenzar"]);
    expect(w.findAll(".hoy-sesion").map((e) => e.text())).toHaveLength(2);
    expect(w.get(".hoy-agenda-lista").text()).not.toContain("Exotic");
    await verTodas(w);
    expect(activo()).toEqual(["Todas"]);
    expect(w.findAll(".hoy-sesion")).toHaveLength(3);
    w.unmount();

    // Con el día terminado no dice «sin resultados»: ofrece ver todas.
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: { sesiones: 1, esperados: 3, llegaron: 3, sin_marcar: 0 },
            sesiones: [sesion({ oferta: "Exotic", momento: "termino" })],
          },
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const v = montar();
    await flushPromises();
    const vacio = v.get('[data-prueba="nada-por-comenzar"]');
    expect(vacio.text()).toContain("No quedan clases por comenzar hoy");
    expect(v.text()).not.toContain("No hay actividad que coincida");
    await vacio.get("button").trigger("click");
    expect(v.findAll(".hoy-sesion")).toHaveLength(1);
    expect(v.get(".hoy-sesion").text()).toContain("Exotic");
  });
  it("busca por nombre sin acentos y filtra sin cambiar las cifras del día", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: { sesiones: 3, esperados: 6, llegaron: 0, sin_marcar: 0 },
            sesiones: [
              sesion({ id: "uno", instructor: "María", momento: "proxima" }),
              sesion({ id: "dos", oferta: "Exotic", momento: "termino" }),
              sesion({
                id: "tres",
                oferta: "Yoga",
                cancelada: true,
                momento: "cancelada",
              }),
            ],
          },
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await verTodas(w);
    await w.get('input[type="search"]').setValue("maria");
    expect(w.findAll(".hoy-sesion")).toHaveLength(1);
    expect(w.get(".hoy-sesion").text()).toContain("María");
    await w.get('input[type="search"]').setValue("exotic");
    expect(w.findAll(".hoy-sesion")).toHaveLength(1);
    expect(w.get('[data-prueba="indicador-clases"]').text()).toContain("3");
    await w.get('input[type="search"]').setValue("");
    await w
      .findAll("button")
      .find((b) => b.text() === "Por comenzar")!
      .trigger("click");
    expect(w.findAll(".hoy-sesion")).toHaveLength(1);
    expect(w.get(".hoy-sesion").text()).toContain("María");
    await w
      .findAll("button")
      .find((b) => b.text() === "Canceladas")!
      .trigger("click");
    expect(w.get(".hoy-sesion").text()).toContain("Yoga");
  });
  it("no ofrece pasar lista a un rol sin permiso y distingue horarios pasados de cierre", async () => {
    api.permisos = ["agenda.ver"];
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: { sesiones: 1, esperados: 3, llegaron: 0, sin_marcar: 3 },
            sesiones: [sesion({ momento: "termino", sin_marcar: 3 })],
          },
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await verTodas(w);
    expect(w.text()).toContain("No quedan clases por comenzar hoy");
    expect(w.text()).toContain("Revisa la asistencia");
    expect(
      w.findAll("a").some((a) => a.text().includes("Falta pasar lista")),
    ).toBe(false);
    expect(
      w.findAll("a").some((a) => a.text().includes("Ir a recepción")),
    ).toBe(false);
  });

  it("cada pendiente abre su clase o cita; en citas, sin registrar no es por atender", async () => {
    api.esCitas = true;
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/clima")
            ? null
            : {
                fecha: "2026-10-01",
                agenda: {
                  totales: {
                    sesiones: 2,
                    esperados: 2,
                    llegaron: 0,
                    sin_marcar: 1,
                    por_atender: 1,
                    pendientes_registrar: 1,
                    por_cobrar: 0,
                  },
                  sesiones: [
                    sesion({
                      id: "pasada",
                      tipo: "cita",
                      oferta: "Corte",
                      cliente: "Dana",
                      capacidad: 1,
                      esperados: 1,
                      llegaron: 0,
                      sin_marcar: 1,
                      termina_en: "2026-10-01T14:45:00Z",
                      momento: "termino",
                    }),
                    sesion({
                      id: "luego",
                      tipo: "cita",
                      oferta: "Barba",
                      cliente: "Eli",
                      capacidad: 1,
                      esperados: 1,
                      llegaron: 0,
                      inicia_en: "2026-10-01T18:00:00Z",
                      termina_en: "2026-10-01T18:30:00Z",
                      momento: "proxima",
                    }),
                  ],
                },
                libres: [],
                cobros: null,
                renovaciones: null,
              },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    await verTodas(w);

    // Por atender (la que sigue) y sin registrar (la que ya pasó), por separado.
    expect(w.get('[data-prueba="indicador-porAtender"]').text()).toContain("1");
    expect(w.get('[data-prueba="indicador-sinRegistrar"]').text()).toContain(
      "1",
    );
    const llegadas = w
      .findAll('[data-prueba="cita-llegada"]')
      .map((e) => e.text());
    expect(llegadas).toEqual(["Sin registrar", "Próxima"]);
    // La cita dice cuánto dura (no su cupo «1/1»).
    expect(w.text()).toContain("Corte · 45 min · Caro");
    expect(w.text()).not.toContain("1 de 1");
    // Cada fila y su pendiente abren ESA cita en la agenda, en su día.
    const fila = JSON.parse(
      w.findAll('[data-prueba="abrir-sesion"]')[0].attributes("data-to")!,
    );
    expect(fila).toEqual({
      name: "agenda",
      query: { fecha: "2026-10-01", sesion: "pasada" },
    });
    const marcar = JSON.parse(
      w.get('[data-prueba="marcar-pendiente"]').attributes("data-to")!,
    );
    expect(marcar.query.sesion).toBe("pasada");
    // Lo que sigue se abre directo.
    expect(w.get('[data-prueba="abrir-siguiente"]').text()).toBe("Ver clase");
  });

  it("pasar lista de una clase lleva a su pase de lista; sin él, a Recepción", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/clima")
            ? null
            : {
                fecha: "2026-10-01",
                agenda: {
                  totales: {
                    sesiones: 1,
                    esperados: 3,
                    llegaron: 1,
                    sin_marcar: 2,
                  },
                  sesiones: [
                    sesion({ id: "clase1", momento: "termino", sin_marcar: 2 }),
                  ],
                },
                cobros: null,
                renovaciones: null,
              },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    await verTodas(w);

    const marcar = JSON.parse(
      w.get('[data-prueba="marcar-pendiente"]').attributes("data-to")!,
    );
    expect(marcar).toEqual({
      name: "recepcion",
      query: { fecha: "2026-10-01", sesion: "clase1" },
    });
    w.unmount();

    // En un negocio de clases, quien puede pasar lista va directo a la lista.
    api.modalidad = "clases";
    const v = montar();
    await flushPromises();
    await verTodas(v);
    expect(
      JSON.parse(
        v.get('[data-prueba="marcar-pendiente"]').attributes("data-to")!,
      ),
    ).toEqual({ name: "pase-lista", params: { id: "clase1" } });
  });
});
