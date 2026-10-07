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

describe("el día de hoy en el Inicio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.permisos = null;
    api.esCitas = false;
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

  it("pasar lista de una clase lleva a Recepción con esa clase", async () => {
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

    const marcar = JSON.parse(
      w.get('[data-prueba="marcar-pendiente"]').attributes("data-to")!,
    );
    expect(marcar).toEqual({
      name: "recepcion",
      query: { fecha: "2026-10-01", sesion: "clase1" },
    });
  });
});
