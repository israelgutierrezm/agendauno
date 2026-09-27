import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { miCuentaExtra, miReprogramar } from "@/i18n/locales/gestion.es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import MiCuentaView from "./MiCuentaView.vue";
import MisReservasView from "./MisReservasView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    estudio: { nombre: "Estudio Demo" },
  }),
}));
vi.mock("@/lib/retornoPago", async () => {
  const { ref } = await import("vue");
  return { useRetornoPago: () => ref(null) };
});
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
}));

// Miércoles 9 de enero de 2030: el jueves 10 cae en la misma semana.
const HOY = new Date("2030-01-09T12:00:00Z");
const futuro = (dias: number, hora = 15) =>
  new Date(HOY.getTime() + dias * 86_400_000)
    .toISOString()
    .replace(/T.*/, `T${String(hora).padStart(2, "0")}:00:00Z`);

const datos: Record<string, unknown> = {
  "/mi/perfil": {
    derechos: [
      {
        id: "d1",
        producto: "Pack 8 clases",
        ilimitado: false,
        saldo: 6000,
        disponible: 6000,
      },
    ],
    reservas: [
      {
        id: "r1",
        sesion_id: "s1",
        estado: "confirmada",
        oferta: "Pole Nivel 1",
        sucursal: "Roma Norte",
        inicia_en: futuro(1),
        termina_en: futuro(1, 16),
        zona_horaria: "America/Mexico_City",
        oferta_expira_en: null,
        orden_id: null,
      },
    ],
    politica_cancelacion: null,
  },
  "/mi/agenda": [
    {
      id: "s1",
      oferta: "Pole Nivel 1",
      sucursal: "Roma Norte",
      inicia_en: futuro(2),
      zona_horaria: "America/Mexico_City",
      capacidad: 10,
      ocupados: 3,
    },
    {
      id: "s2",
      oferta: "Flexibilidad",
      sucursal: "Roma Norte",
      inicia_en: futuro(3),
      zona_horaria: "America/Mexico_City",
      capacidad: 8,
      ocupados: 8,
    },
  ],
  "/mi/waivers": [
    { id: "w1", titulo: "Reglamento", contenido: "…", version: 1 },
  ],
  "/mi/productos": [],
  "/mi/ordenes": [
    {
      id: "o1",
      estado: "pendiente",
      total_minor: 50000,
      moneda: "MXN",
      fecha: null,
      lineas: [],
    },
  ],
  "/mi/formularios": { persona_id: "p1", formularios: [] },
};

function montar(componente: typeof MiCuentaView | typeof MisReservasView) {
  return mount(componente, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: {
            es: { ...esMX, portal, miCuentaExtra, miReprogramar },
          },
        }),
      ],
      stubs: {
        teleport: true,
        PaseEntrada: true,
        CalificarClases: true,
        EncabezadoSeccion: {
          props: ["titulo"],
          template: "<h1>{{ titulo }}<slot name='acciones' /></h1>",
        },
      },
    },
  });
}

describe("portal del alumno", () => {
  afterEach(() => {
    vi.useRealTimers();
  });
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
    vi.clearAllMocks();
    localStorage.clear();
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: { data: datos[url.replace("/api/v1/app/demo", "")] ?? [] },
      }),
    );
  });

  it("el inicio muestra lo que pide atención, la próxima reserva y los accesos", async () => {
    const w = montar(MiCuentaView);
    await flushPromises();
    const texto = w.text();

    expect(texto).toContain("Tienes 1 documento por firmar");
    expect(texto).toContain("Tu próxima clase");
    expect(texto).toContain("Pole Nivel 1");
    expect(texto).toContain("Agregar a mi calendario");
    // Accesos directos con su dato.
    expect(texto).toContain("1 clase disponible");
    expect(texto).toContain("6 créditos");
    expect(texto).toContain("1 por pagar");
  });

  it("sin reserva la tarjeta principal sigue ahí (invita a reservar) y lleva el clima", async () => {
    const sinReservas = {
      ...(datos["/mi/perfil"] as object),
      reservas: [],
    };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const clima = {
        tipo: "ahora",
        lugar: "Guadalajara",
        aproximado: true,
        temperatura: 24,
        condicion: "Despejado",
        icono: "despejado",
        es_de_dia: true,
        lluvia: null,
      };
      const cuerpo =
        ruta === "/mi/perfil"
          ? sinReservas
          : ruta === "/mi/clima"
            ? clima
            : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();
    const texto = w.text();

    expect(texto).toContain("Tu próxima clase");
    expect(texto).toContain("Sin clase agendada");
    expect(texto).toContain("24°");
    expect(texto).toContain("Ahora cerca de Guadalajara");
  });

  it("reservas: lista con las suyas y las disponibles; en semana y detalle", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    expect(w.text()).toContain("Mis reservas");
    expect(w.text()).toContain("Flexibilidad");
    expect(w.text()).toContain(esMX.miCuenta.listaEspera); // la llena

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    expect(w.find(".cv-chip-propio").exists()).toBe(true);

    await w.find(".cv-chip-propio").trigger("click");
    expect(w.text()).toContain("Agregar a mi calendario");
    expect(w.text()).toContain(miReprogramar.boton);
  });
});
