import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import { miCuentaExtra, miReprogramar } from "@/i18n/locales/gestion.es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import { reiniciarMiCuenta, useMiCuenta } from "@/lib/miCuenta";
import MiCuentaView from "./MiCuentaView.vue";
import MisReservasView from "./MisReservasView.vue";
import MisPagosView from "./MisPagosView.vue";
import MiExpedienteView from "./MiExpedienteView.vue";

enableAutoUnmount(afterEach);

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
const retorno = vi.hoisted(() => ({
  valor: null as "confirmado" | "confirmando" | null,
}));
vi.mock("@/lib/retornoPago", async (original) => {
  const { ref } = await import("vue");
  return {
    ...(await original<typeof import("@/lib/retornoPago")>()),
    useConfirmacionPago: () => ref(retorno.valor),
  };
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

function montar(
  componente:
    | typeof MiCuentaView
    | typeof MisReservasView
    | typeof MisPagosView
    | typeof MiExpedienteView,
) {
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
        CortePlanes: true,
        HistorialCompras: true,
        PagoAutomatico: true,
        MisDocumentos: true,
        ListaFormularios: true,
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
    reiniciarMiCuenta();
    localStorage.clear();
    sesion.esCitas = false;
    retorno.valor = null;
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

  it("muestra a la mano lo que le interesó como prospecto (ADR 0137)", async () => {
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const cuerpo =
        ruta === "/mi/interes"
          ? {
              plan: { id: "p1", nombre: "Mensual 8 clases" },
              clase: {
                id: "s1",
                nombre: "Pole Nivel 1",
                inicia_en: "2030-01-15T19:00:00-06:00",
                zona_horaria: "America/Mexico_City",
              },
            }
          : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: cuerpo } });
    });
    const w = montar(MiCuentaView);
    await flushPromises();

    expect(w.find('[data-prueba="interes-plan"]').text()).toContain(
      "Te interesó Mensual 8 clases",
    );
    expect(w.find('[data-prueba="interes-clase"]').text()).toContain(
      "Te interesó Pole Nivel 1",
    );
  });

  it("sin nada pendiente de cuando era prospecto, no muestra el aviso", async () => {
    const w = montar(MiCuentaView);
    await flushPromises();

    expect(w.find('[data-prueba="interes-plan"]').exists()).toBe(false);
    expect(w.find('[data-prueba="interes-clase"]').exists()).toBe(false);
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
    // Sin nada agendado, lo que ofrece el negocio: su próxima clase.
    expect(texto).toContain("Tu próxima clase");
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

  it("reservas: antes de reservar dice lo que pasará; al confirmar, su reserva real", async () => {
    let anotada = false;
    api.post.mockImplementation(() => {
      anotada = true;
      return Promise.resolve({ data: { data: {} } });
    });
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      if (ruta === "/mi/sesiones/s2/antes-de-reservar") {
        return Promise.resolve({
          data: {
            data: {
              resultado: "lista_espera",
              codigo: null,
              motivo: null,
              accion: null,
              mensaje: null,
              unidades: 0,
              inicia_en: futuro(3),
              zona_horaria: "America/Mexico_City",
              sucursal: { nombre: "Roma Norte", mapa_url: null },
              requisitos: null,
              que_llevar: "Ropa cómoda",
              plan: {
                nombre: "Pack 8 clases",
                ilimitado: false,
                vence: null,
                disponible_despues: 6000,
              },
              de_pago: null,
              cancelacion: {
                limite: futuro(3, 9),
                horas_limite: 6,
                penaliza_tarde: true,
                cancela_tarde: true,
              },
            },
          },
        });
      }
      if (ruta === "/mi/perfil" && anotada) {
        const perfil = datos["/mi/perfil"] as { reservas: unknown[] };
        return Promise.resolve({
          data: {
            data: {
              ...perfil,
              reservas: [
                ...perfil.reservas,
                {
                  id: "r2",
                  sesion_id: "s2",
                  estado: "en_espera",
                  oferta: "Flexibilidad",
                  sucursal: "Roma Norte",
                  inicia_en: futuro(3),
                  zona_horaria: "America/Mexico_City",
                  oferta_expira_en: null,
                  orden_id: null,
                  cancelacion: null,
                },
              ],
            },
          },
        });
      }
      return Promise.resolve({ data: { data: datos[ruta] ?? [] } });
    });
    const w = montar(MisReservasView);
    await flushPromises();

    // «Lista de espera» no anota de inmediato: abre lo que va a pasar.
    const flexi = w
      .findAll('[data-prueba="clase-disponible"]')
      .find((f) => f.text().includes("Flexibilidad"))!;
    await flexi.get('[data-prueba="reservar-clase"]').trigger("click");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();
    const antes = w.get('[data-prueba="antes-de-reservar"]');
    expect(antes.text()).toContain("No se descuenta nada hasta que te toque");
    expect(antes.text()).toContain("Ropa cómoda");

    await antes.get('[data-prueba="confirmar-reserva"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/mi/reservas", {
      sesion_id: "s2",
      esperar: true,
      // La clave del intento (ADR 0201).
      idempotency_key: expect.stringMatching(/^[a-f0-9]{32}$/),
    });
    // Lo que quedó, de la reserva real: en espera, sin consumo.
    expect(w.get('[data-prueba="reserva-confirmada"]').text()).toBe(
      portal.miReserva.enEspera,
    );
    expect(w.get('[data-prueba="detalles-reserva"]').text()).toContain(
      portal.miReserva.esperaNoConsume,
    );
  });

  it("reservas: el error de una acción no se arrastra a la siguiente clase que abre", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    // Quedó el error de algo que falló en otra clase.
    useMiCuenta().error.value = "No quedan lugares.";
    await flushPromises();

    const flexi = w
      .findAll('[data-prueba="clase-disponible"]')
      .find((f) => f.text().includes("Flexibilidad"))!;
    await flexi.get('[data-prueba="reservar-clase"]').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-panel"]').exists()).toBe(false);
    expect(w.text()).not.toContain("No quedan lugares.");
  });

  it("reservas: en el calendario, la que está en espera no parece confirmada", async () => {
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      if (ruta === "/mi/perfil") {
        const perfil = datos["/mi/perfil"] as { reservas: object[] };
        return Promise.resolve({
          data: {
            data: {
              ...perfil,
              reservas: [{ ...perfil.reservas[0], estado: "en_espera" }],
            },
          },
        });
      }
      return Promise.resolve({ data: { data: datos[ruta] ?? [] } });
    });
    const w = montar(MisReservasView);
    await flushPromises();
    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    const propia = w.get(".cv-chip-propio");
    expect(propia.text()).toContain(esMX.miCuenta.en_espera);
    expect(propia.text()).not.toContain(portal.reservas.reservada);
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

    await w.get('[data-pestana="proximas"]').trigger("click");
    expect(w.get('[data-prueba="reserva-proxima"]').text()).toContain(
      "Pole Nivel 1",
    );
    expect(router.replace).toHaveBeenLastCalledWith({
      query: { pestana: "proximas" },
    });

    await w.get('[data-pestana="historial"]').trigger("click");
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

  it("mantiene búsqueda y cobertura al cambiar de lista a semana", async () => {
    const w = montar(MisReservasView);
    await flushPromises();
    await w.get('input[type="search"]').setValue("training");
    expect(w.findAll('[data-prueba="clase-disponible"]')).toHaveLength(1);
    expect(w.get('[data-prueba="clase-disponible"]').text()).not.toContain(
      esMX.miCuenta.reservar,
    );
    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    await flushPromises();
    expect(w.get('input[type="search"]').element.value).toBe("training");
    expect(w.find('[data-prueba="filtro-incluidas"]').exists()).toBe(true);
    expect(
      w.findAll(".cv-chip").some((c) => c.text().includes("Flexibilidad")),
    ).toBe(false);
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("al cancelar, el aviso dice que se canceló (no se queda el de un cambio anterior)", async () => {
    ruta.query = { pestana: "proximas" };
    api.post.mockResolvedValue({ data: { data: {} } });
    api.get.mockImplementation((url: string) => {
      const r = url.replace("/api/v1/app/demo", "");
      if (r === "/mi/reservas/r1/cancelacion") {
        return Promise.resolve({
          data: {
            data: { cancelable: true, mensaje: "Se devolverá 1 crédito." },
          },
        });
      }
      return Promise.resolve({ data: { data: datos[r] ?? [] } });
    });
    const w = montar(MisReservasView);
    const avisos = () => w.findAll('[role="status"]').map((a) => a.text());
    await flushPromises();

    // Primero cambió el horario: queda su aviso.
    await w.get('[data-prueba="reserva-proxima"] button').trigger("click");
    await w
      .findAll("button")
      .find((b) => b.text() === miReprogramar.boton)!
      .trigger("click");
    await flushPromises();
    w.findComponent({ name: "ReprogramarMiReserva" }).vm.$emit("hecho");
    await flushPromises();
    expect(avisos()).toEqual([miReprogramar.hecho]);

    // Luego la cancela.
    await w.get('[data-prueba="reserva-proxima"] button').trigger("click");
    await w
      .findAll("button")
      .find((b) => b.text() === esMX.miCuenta.cancelar)!
      .trigger("click");
    await flushPromises();
    await w.get('[data-prueba="confirmar-cancelacion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/reservas/r1/cancelar",
      {},
    );
    expect(avisos()).toEqual([esMX.miCuenta.cancelado]);
    expect(w.text()).not.toContain(miReprogramar.hecho);
  });

  it("en próximas muestra fecha visual y abre el detalle sin reservar de nuevo", async () => {
    ruta.query = { pestana: "proximas" };
    const w = montar(MisReservasView);
    await flushPromises();
    expect(w.find(".mr-proxima-fecha").exists()).toBe(true);
    await w.get('[data-prueba="reserva-proxima"] button').trigger("click");
    expect(w.text()).toContain("Agregar a mi calendario");
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("pagos tiene accesos a pendientes, planes e historial sin iniciar cobros", async () => {
    const w = montar(MisPagosView);
    await flushPromises();
    expect(w.get('a[href="#mp-pendientes"]').text()).toContain("1 por pagar");
    expect(w.find("#mp-pendientes").exists()).toBe(true);
    expect(w.find("#mp-planes").exists()).toBe(true);
    expect(w.find("#mp-historial").exists()).toBe(true);
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("pagos: dice si el cobro está en pruebas y si ya inició un pago en OXXO (M07)", async () => {
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      const extra =
        ruta === "/mi/perfil"
          ? {
              ...(datos[ruta] as Record<string, unknown>),
              pago_en_linea: true,
              pago_en_linea_prueba: true,
            }
          : ruta === "/mi/ordenes/pendientes"
            ? [
                {
                  ...(datos[ruta] as Record<string, unknown>[])[0],
                  pago_en_proceso: {
                    metodo: "oxxo",
                    iniciado_en: "2030-01-08T12:00:00Z",
                  },
                },
              ]
            : (datos[ruta] ?? []);
      return Promise.resolve({ data: { data: extra } });
    });
    const w = montar(MisPagosView);
    await flushPromises();

    expect(w.get('[data-prueba="modo-prueba"]').text()).toContain(
      "no se cobra de verdad",
    );
    expect(w.get('[data-prueba="pago-en-proceso"]').text()).toContain(
      "ficha para pagar en OXXO",
    );
    w.unmount();
  });

  it("comprar: el recomendado primero, la fecha exacta si paga hoy y la clase de prueba solo si le toca", async () => {
    const producto = (extra: Record<string, unknown>) => ({
      tipo: "paquete",
      precio_minor: 100000,
      moneda: "MXN",
      ilimitado: false,
      creditos_incluidos: 8000,
      disponible: true,
      ...extra,
    });
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/mi/productos")
            ? [
                producto({
                  id: "p1",
                  nombre: "Clase muestra",
                  primera_vez: true,
                  disponible: false,
                }),
                producto({ id: "p2", nombre: "Pack 4" }),
                producto({
                  id: "p3",
                  nombre: "Pack 8",
                  destacado: true,
                  descripcion: "8 clases en un mes.",
                  vence_si_compra_hoy: "2026-11-01",
                }),
              ]
            : (datos[url.replace("/api/v1/app/demo", "")] ?? []),
        },
      }),
    );
    const w = montar(MisPagosView);
    await flushPromises();
    const tarjetas = w.findAll('[data-prueba="comprable"]');
    expect(tarjetas.map((t) => t.find(".font-semibold").text())).toEqual([
      "Pack 8",
      "Pack 4",
    ]);
    expect(tarjetas[0]!.text()).toContain("Recomendado");
    expect(tarjetas[0]!.text()).toContain("8 clases en un mes.");
    expect(tarjetas[0]!.get('[data-prueba="vence"]').text()).toBe(
      "Si la pagas hoy, se puede usar hasta el 1 de noviembre.",
    );
    w.unmount();
  });

  it("comprar un plan con permanencia pide aceptar sus condiciones", async () => {
    api.post.mockResolvedValue({ data: { data: {} } });
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/mi/productos")
            ? [
                {
                  id: "p9",
                  nombre: "Mensualidad",
                  tipo: "membresia",
                  precio_minor: 120000,
                  moneda: "MXN",
                  ilimitado: true,
                  creditos_incluidos: null,
                  disponible: true,
                  condiciones: "Permanencia mínima de 6 meses.",
                },
              ]
            : (datos[url.replace("/api/v1/app/demo", "")] ?? []),
        },
      }),
    );
    const w = montar(MisPagosView);
    await flushPromises();
    const tarjeta = w.get('[data-prueba="comprable"]');
    expect(tarjeta.get('[data-prueba="condiciones"]').text()).toContain(
      "Permanencia mínima de 6 meses.",
    );
    const comprar = tarjeta.findAll("button").at(-1)!;
    expect(comprar.attributes("disabled")).toBeDefined();
    await tarjeta.get('[data-prueba="condiciones"] input').setValue(true);
    await comprar.trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/mi/ordenes", {
      items: [{ producto_id: "p9", cantidad: 1 }],
      acepta_condiciones: true,
      idempotency_key: expect.stringMatching(/^[a-f0-9]{32}$/),
    });
    w.unmount();
  });

  it("si la compra falla, el porqué va en la tarjeta del plan y no ofrece «Reintentar»", async () => {
    api.post.mockRejectedValue("Este plan ya no está a la venta.");
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/mi/productos")
            ? [
                {
                  id: "p9",
                  nombre: "Mensualidad",
                  tipo: "membresia",
                  precio_minor: 120000,
                  moneda: "MXN",
                  ilimitado: true,
                  creditos_incluidos: null,
                  disponible: true,
                },
              ]
            : (datos[url.replace("/api/v1/app/demo", "")] ?? []),
        },
      }),
    );
    const w = montar(MisPagosView);
    await flushPromises();
    const tarjeta = w.get('[data-prueba="comprable"]');
    await tarjeta.findAll("button").at(-1)!.trigger("click");
    await flushPromises();

    expect(
      w.get('[data-prueba="comprable"] [data-prueba="error-compra"]').text(),
    ).toBe("Este plan ya no está a la venta.");
    expect(w.text()).not.toContain("Reintentar");
    w.unmount();
  });

  it("al volver de pagar, solo con el pago confirmado sigue reservar con su plan", async () => {
    retorno.valor = "confirmando";
    const confirmando = montar(MisPagosView);
    await flushPromises();
    expect(
      confirmando.get('[data-prueba="retorno-pago"]').attributes("style"),
    ).not.toContain("--exito");
    expect(
      confirmando.find('[data-prueba="reservar-tras-pago"]').exists(),
    ).toBe(false);
    confirmando.unmount();

    retorno.valor = "confirmado";
    const w = montar(MisPagosView);
    await flushPromises();
    expect(w.get('[data-prueba="retorno-pago"]').attributes("style")).toContain(
      "--exito",
    );
    expect(w.get('[data-prueba="reservar-tras-pago"]').text()).toBe(
      portal.miReserva.reservarConPlan,
    );
    w.unmount();
  });

  it("una cuenta de citas sin plan no muestra un acceso de planes vacío", async () => {
    sesion.esCitas = true;
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/mi/perfil")
            ? { ...(datos["/mi/perfil"] as object), derechos: [] }
            : (datos[url.replace("/api/v1/app/demo", "")] ?? []),
        },
      }),
    );
    const w = montar(MisPagosView);
    await flushPromises();
    expect(w.find('a[href="#mp-planes"]').exists()).toBe(false);
    expect(w.find("#mp-planes").exists()).toBe(false);
    w.unmount();
  });

  it("expediente conserva completo el documento y requiere aceptación explícita", async () => {
    const w = montar(MiExpedienteView);
    await flushPromises();
    expect(w.get("#me-firmar").text()).toContain("Reglamento");
    expect(w.get("#me-firmar").text()).toContain("…");
    expect(w.find('a[href="#me-documentos"]').exists()).toBe(true);
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("si aceptar un documento falla, el porqué va junto a ese documento", async () => {
    api.post.mockRejectedValue("La versión del documento cambió.");
    const w = montar(MiExpedienteView);
    await flushPromises();
    await w.get("#me-firmar button").trigger("click");
    await flushPromises();
    expect(w.get('#me-firmar [data-prueba="error-waiver"]').text()).toBe(
      "La versión del documento cambió.",
    );
    expect(w.text()).not.toContain("Reintentar");
    w.unmount();
  });

  it("expediente no duplica accesos cuando solo hay documentos", async () => {
    api.get.mockImplementation((url: string) => {
      const ruta = url.replace("/api/v1/app/demo", "");
      return Promise.resolve({
        data: { data: ruta === "/mi/waivers" ? [] : (datos[ruta] ?? []) },
      });
    });
    const w = montar(MiExpedienteView);
    await flushPromises();
    expect(w.find(".me-accesos").exists()).toBe(false);
    expect(w.find("#me-documentos").exists()).toBe(true);
    expect(api.post).not.toHaveBeenCalled();
  });

  it("un error inicial no aparenta saldo al corriente o cuenta sin reservas", async () => {
    api.get.mockRejectedValue(new Error("Sin conexión"));
    const inicio = montar(MiCuentaView);
    await flushPromises();
    expect(inicio.text()).not.toContain("Nada agendado por ahora");
    expect(inicio.text()).not.toContain("Al corriente");
    inicio.unmount();
    reiniciarMiCuenta();
    const pagos = montar(MisPagosView);
    await flushPromises();
    expect(pagos.find(".mp-accesos").exists()).toBe(false);
    expect(pagos.text()).not.toContain("Al corriente");
    expect(pagos.text()).toContain("Sin conexión");
    pagos.unmount();
  });
});
