import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import { miCuentaExtra, miReprogramar } from "@/i18n/locales/gestion.es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import MiCuentaView from "./MiCuentaView.vue";
import MisReservasView from "./MisReservasView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  esCitas: false,
  estudio: { nombre: "Estudio Demo" },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/retornoPago", async () => {
  const { ref } = await import("vue");
  return { useRetornoPago: () => ref(null) };
});
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
const router = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRoute: () => ruta,
  useRouter: () => router,
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
        estado: "vigente",
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
      cobertura: { estado: "incluida", motivo: null },
    },
    {
      id: "s2",
      oferta: "Flexibilidad",
      sucursal: "Roma Norte",
      inicia_en: futuro(3),
      zona_horaria: "America/Mexico_City",
      capacidad: 8,
      ocupados: 8,
      cobertura: { estado: "incluida", motivo: null },
    },
    {
      id: "s3",
      oferta: "Open Training",
      oferta_id: "o3",
      actividad: "Entrenamiento",
      actividad_id: "a2",
      sucursal: "Roma Norte",
      inicia_en: futuro(4),
      zona_horaria: "America/Mexico_City",
      capacidad: 10,
      ocupados: 1,
      cobertura: { estado: "solo_membresia", motivo: "clase" },
    },
  ],
  "/mi/waivers": [
    { id: "w1", titulo: "Reglamento", contenido: "…", version: 1 },
  ],
  "/mi/productos": [],
  "/mi/ordenes/pendientes": [
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
            es: { ...esMX, portal, miCuentaExtra, miReprogramar, miPerfil },
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
    sesion.esCitas = false;
    ruta.query = {};
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

  it("en clases: reservar, su plan con vencimiento y su asistencia, en ese orden", async () => {
    const perfil = datos["/mi/perfil"] as { derechos: object[] };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/perfil"
          ? {
              ...perfil,
              derechos: [
                { ...(perfil.derechos[0] as object), vence: "2030-01-31" },
              ],
              portal: { creditos: true, pase: false, expediente: true },
              asistencias_30_dias: 3,
            }
          : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();
    const texto = w.text();

    expect(texto).toContain("6 créditos · vence el 31 ene");
    expect(texto).toContain("3 clases en 30 días");
    expect(texto).not.toContain("Pase de entrada");
    const orden = ["Reservar", "Mis reservas", "Mis créditos", "Mi asistencia"];
    const posiciones = orden.map((x) => texto.indexOf(x));
    expect(posiciones).toEqual([...posiciones].sort((a, b) => a - b));
  });

  it("Mis créditos suma solo lo vigente: un paquete vencido no cuenta", async () => {
    const perfil = datos["/mi/perfil"] as { derechos: object[] };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/perfil"
          ? {
              ...perfil,
              derechos: [
                { ...(perfil.derechos[0] as object), vence: "2030-01-31" },
                {
                  ...(perfil.derechos[0] as object),
                  id: "vencido",
                  disponible: 4000,
                  vence: "2020-01-31",
                  estado: "vencido",
                },
              ],
              portal: { creditos: true, pase: false, expediente: true },
            }
          : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();

    // 6 del vigente; los 4 del vencido no cuentan ni su fecha.
    expect(w.text()).toContain("6 créditos · vence el 31 ene");
    expect(w.text()).not.toContain("10 créditos");
  });

  it("Mis créditos sigue el estado que da el servidor: un plan en pausa no suma", async () => {
    const perfil = datos["/mi/perfil"] as { derechos: object[] };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/perfil"
          ? {
              ...perfil,
              // No ha vencido, pero está en pausa: no hay créditos que usar.
              derechos: [
                {
                  ...(perfil.derechos[0] as object),
                  vence: "2030-01-31",
                  estado: "pausado",
                  pausa_hasta: "2030-01-20",
                },
              ],
              portal: { creditos: true, pase: false, expediente: true },
            }
          : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();

    expect(w.text()).toContain("En pausa hasta el 20 ene");
    expect(w.text()).not.toContain("6 créditos");
  });

  it("en citas: cómo llegar, cambiar o cancelar, volver a agendar; sin «Sin paquete» ni pase", async () => {
    sesion.esCitas = true;
    const perfil = datos["/mi/perfil"] as { reservas: object[] };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/perfil"
          ? {
              ...perfil,
              derechos: [],
              reservas: [
                {
                  ...(perfil.reservas[0] as object),
                  tipo: "cita",
                  oferta: "Corte de cabello",
                  mapa_url: "https://maps.example/roma",
                },
              ],
              portal: { creditos: false, pase: false, expediente: false },
            }
          : ruta === "/mi/waivers"
            ? []
            : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();
    const texto = w.text();

    expect(w.get('[data-prueba="como-llegar"]').attributes("href")).toBe(
      "https://maps.example/roma",
    );
    expect(w.get('[data-prueba="ver-detalle"]').text()).toContain(
      "Cambiar o cancelar",
    );
    expect(texto).not.toContain("Sin paquete activo");
    expect(texto).not.toContain("Mis créditos");
    expect(texto).not.toContain("Pase de entrada");
    expect(texto).not.toContain("Expediente");
    const orden = ["Mis citas", "Volver a agendar", "Pagos", "Mi perfil"];
    const posiciones = orden.map((x) => texto.indexOf(x));
    expect(posiciones.every((p) => p >= 0)).toBe(true);
    expect(posiciones).toEqual([...posiciones].sort((a, b) => a - b));
    expect(texto).not.toContain("Configuración");
    expect(
      w
        .findAllComponents({ name: "TarjetaOperacion" })
        .find((tarjeta) => tarjeta.props("titulo") === "Mi perfil")
        ?.props("to"),
    ).toEqual({ name: "mi-perfil" });
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

    // Sin reserva, el término general.
    expect(texto).toContain("Tu próxima reserva");
    expect(texto).toContain("Nada agendado por ahora");
    expect(texto).toContain("24°");
    expect(texto).toContain("Ahora cerca de Guadalajara");
  });

  it("una cita se nombra como cita, también en el pronóstico", async () => {
    const perfil = datos["/mi/perfil"] as { reservas: object[] };
    const conCita = {
      ...perfil,
      reservas: [
        {
          ...(perfil.reservas[0] as object),
          tipo: "cita",
          oferta: "Corte de cabello",
        },
      ],
    };
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/perfil"
          ? conCita
          : ruta === "/mi/clima"
            ? {
                tipo: "pronostico",
                lugar: "Roma Norte",
                aproximado: false,
                temperatura: 16,
                condicion: "Lluvia",
                icono: "lluvia",
                es_de_dia: true,
                lluvia: 70,
              }
            : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();
    const texto = w.text();

    expect(texto).toContain("Tu próxima cita");
    expect(texto).not.toContain("Tu próxima clase");
    expect(texto).toContain("Corte de cabello");
    expect(texto).toContain("Pronóstico para tu cita en Roma Norte");
  });

  it("reservas: pide las clases del periodo que se ve y las vuelve a pedir al moverse", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    const pedidos = () =>
      api.get.mock.calls.filter(
        ([url, cfg]) =>
          url === "/api/v1/app/demo/mi/agenda" && cfg?.params?.desde,
      );
    const primero = pedidos().at(-1)![1].params;
    expect(primero.desde).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(primero.hasta).toMatch(/^\d{4}-\d{2}-\d{2}$/);

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    await flushPromises();
    await w.find('[aria-label="Siguiente"]').trigger("click");
    await flushPromises();
    const semanaSiguiente = pedidos().at(-1)![1].params;
    expect(semanaSiguiente.desde > primero.desde).toBe(true);
  });

  it("si falla la carga del periodo, muestra el error con reintentar (no un calendario vacío)", async () => {
    let falla = true;
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      if (ruta === "/mi/agenda" && falla) {
        return Promise.reject(new Error("Sin conexión"));
      }
      return Promise.resolve({ data: { data: datos[ruta] ?? [] } });
    });
    const w = montar(MisReservasView);
    await flushPromises();

    expect(w.text()).toContain("Sin conexión");
    expect(w.text()).not.toContain(portal.periodo.sinNada);
    const reintentar = w
      .findAll("button")
      .find((b) => b.text() === esMX.comun.reintentar)!;

    falla = false;
    await reintentar.trigger("click");
    await flushPromises();
    expect(w.text()).not.toContain("Sin conexión");
    expect(w.text()).toContain("Flexibilidad");
  });

  it("reservas: en Reservar, las disponibles; en semana, también las suyas con su detalle", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    expect(w.text()).toContain("Flexibilidad");
    expect(w.text()).toContain(esMX.miCuenta.listaEspera); // la llena
    // La suya no se repite entre las disponibles.
    expect(
      w
        .findAll('[data-prueba="clase-disponible"]')
        .some((f) => f.text().includes("Pole Nivel 1")),
    ).toBe(false);

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    expect(w.find(".cv-chip-propio").exists()).toBe(true);

    await w.find(".cv-chip-propio").trigger("click");
    expect(w.text()).toContain("Agregar a mi calendario");
    expect(w.text()).toContain(miReprogramar.boton);
  });

  it("reservas: antes de reservar dice si su plan incluye la clase; la que no, sin «Reservar»", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    const filas = w.findAll('[data-prueba="clase-disponible"]');
    const open = filas.find((f) => f.text().includes("Open Training"))!;
    const flexi = filas.find((f) => f.text().includes("Flexibilidad"))!;

    expect(flexi.text()).toContain("Incluida en tu plan");
    expect(open.text()).toContain("Solo con membresía");
    expect(open.text()).not.toContain(esMX.miCuenta.reservar);

    // Su detalle dice por qué y lleva a los planes.
    await open.find("button").trigger("click");
    expect(w.text()).toContain("Esta clase solo la incluye una membresía.");
    expect(w.find('[data-prueba="ver-planes"]').exists()).toBe(true);

    // «Solo las incluidas en mi plan» la quita de la lista.
    await w.get('[data-prueba="filtro-incluidas"] input').setValue(true);
    expect(
      w
        .findAll('[data-prueba="clase-disponible"]')
        .map((f) => f.text())
        .join(" "),
    ).not.toContain("Open Training");
  });

  it("reservas: Próximas muestra las suyas e Historial vuelve a reservar la misma clase", async () => {
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      if (ruta === "/mi/historial") {
        return Promise.resolve({
          data: {
            data: [
              {
                id: "h1",
                tipo: "clase",
                oferta: "Open Training",
                oferta_id: "o3",
                sucursal: "Roma Norte",
                sucursal_id: "su1",
                instructor: "Abril",
                instructor_id: "i1",
                inicia_en: futuro(-2),
                zona_horaria: "America/Mexico_City",
                estado: "asistio",
                cancelada_por: null,
                reprogramada: true,
                resena: null,
                calificable: true,
              },
            ],
            meta: { page: 1, ultima_pagina: 1, total: 1, per_page: 10 },
          },
        });
      }
      return Promise.resolve({ data: { data: datos[ruta] ?? [] } });
    });
    const w = montar(MisReservasView);
    await flushPromises();

    await w.get('[data-prueba="pestana-proximas"]').trigger("click");
    expect(w.get('[data-prueba="reserva-proxima"]').text()).toContain(
      "Pole Nivel 1",
    );
    expect(router.replace).toHaveBeenLastCalledWith({
      query: { vista: "proximas" },
    });

    await w.get('[data-prueba="pestana-historial"]').trigger("click");
    await flushPromises();
    const fila = w.get('[data-prueba="historial-fila"]');
    expect(fila.text()).toContain("Asististe");
    expect(fila.text()).toContain("Cambió de horario");
    expect(fila.find('[data-prueba="historial-calificar"]').exists()).toBe(
      true,
    );

    await fila.get('[data-prueba="historial-de-nuevo"]').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="filtro-clase"]').text()).toContain(
      "Open Training",
    );
    const disponibles = w.findAll('[data-prueba="clase-disponible"]');
    expect(disponibles).toHaveLength(1);
    expect(disponibles[0].text()).toContain("Open Training");
  });
});
