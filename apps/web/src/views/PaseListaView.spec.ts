import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PaseListaView from "./PaseListaView.vue";

enableAutoUnmount(afterEach);

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
const permisos = vi.hoisted(() => new Set<string>());
const toast = vi.hoisted(() => ({ exito: vi.fn(), info: vi.fn() }));
const confirmo = vi.hoisted(() => ({ asistencia: vi.fn(), lista: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    rutaInicio: "panel",
    puede: (p: string) => permisos.has(p),
  }),
}));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
  useRouter: () => ({ back: vi.fn(), push: vi.fn() }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: confirmo.lista }));
vi.mock("@/lib/confirmarAsistencia", () => ({
  confirmarAsistencia: confirmo.asistencia,
}));

const reserva = (
  id: string,
  persona: string,
  asistencia: string | null = null,
  extra: Record<string, unknown> = {},
) => ({
  id,
  estado: "confirmada",
  lugar: null,
  persona_id: id,
  persona,
  primera_vez: false,
  adeudo: false,
  documentos_pendientes: 0,
  asistencia,
  ...extra,
});
// La clase: 10:00–11:00 en CDMX (16:00 UTC); la lista abre a las 9:30.
const meta = (empezo: boolean) => ({
  asistencia_desde: "2030-01-09T15:30:00Z",
  empezo,
  sesion: {
    id: "s1",
    oferta: "Pole Nivel 1",
    instructor: "Caro",
    sala: "Sala 1",
    sucursal: "Roma",
    inicia_en: "2030-01-09T16:00:00Z",
    termina_en: "2030-01-09T17:00:00Z",
    zona_horaria: "America/Mexico_City",
    capacidad: 4,
    estado: "programada",
  },
});

function montar() {
  return mount(PaseListaView, {
    props: { id: "s1" },
    global: {
      plugins: [i18n],
      stubs: {
        BotonPantallaCompleta: true,
        AgregarAClase: true,
        FitpassOperacion: true,
      },
    },
  });
}
function botones(w: ReturnType<typeof montar>, fila: string, prueba: string) {
  return w.get(`[data-prueba="fila-${fila}"] [data-prueba="${prueba}"]`);
}

describe("pase de lista en su propia pantalla", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useRealTimers();
    permisos.clear();
    permisos.add("asistencia.marcar");
    permisos.add("reservas.ver");
    confirmo.asistencia.mockResolvedValue(true);
    confirmo.lista.mockResolvedValue(true);
  });

  it("antes de la hora, dice cuándo se abre y no deja marcar", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T14:00:00Z"));
    api.get.mockResolvedValue({
      data: { data: [reserva("a", "Ana Ruiz")], meta: meta(false) },
    });
    const w = montar();
    await flushPromises();

    expect(w.get("h1").text()).toBe("Pole Nivel 1");
    expect(w.text()).toContain("10:00–11:00 · Caro · Roma · Sala 1");
    expect(w.get('[data-prueba="estado-lista"]').text()).toBe(
      "La lista se abre a las 09:30.",
    );
    expect(botones(w, "a", "llego").attributes("disabled")).toBeDefined();
    // Terminar la lista aparece cuando ya empezó la clase.
    expect(w.find('[data-prueba="pie-lista"]').exists()).toBe(false);
  });

  it("un toque marca al momento, sin recargar ni mover a nadie; se puede corregir", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T16:05:00Z"));
    api.get.mockResolvedValue({
      data: {
        data: [
          reserva("b", "Beto Luna"),
          reserva("a", "Ana Ruiz"),
          { ...reserva("e", "Eva Sol"), estado: "en_espera" },
        ],
        meta: meta(true),
      },
    });
    api.post.mockResolvedValue({
      data: { data: { estado: "presente", retardo: false } },
    });
    const w = montar();
    await flushPromises();

    // En orden alfabético; la lista de espera, aparte.
    const filas = () =>
      w.findAll(".pl-fila").map((f) => f.get(".pl-nombre").text());
    expect(filas()).toEqual(["Ana Ruiz", "Beto Luna"]);
    expect(w.get(".pl-espera").text()).toContain("Eva Sol");
    expect(w.get('[data-prueba="estado-lista"]').text()).toBe(
      "Ya empezó y faltan 2 por marcar.",
    );

    await botones(w, "b", "llego").trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/b/asistencia",
      { estado: "presente", retardo: false },
    );
    // Se ve al momento y nadie cambia de lugar; no se volvió a pedir la lista.
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(filas()).toEqual(["Ana Ruiz", "Beto Luna"]);
    expect(botones(w, "b", "estado").text()).toBe("Llegó");
    expect(w.get('[data-prueba="filtro-llegaron"]').text()).toContain("1");

    // Corregir: vuelven los tres botones; «Tarde» no mueve créditos.
    await botones(w, "b", "cambiar").trigger("click");
    api.post.mockResolvedValue({
      data: { data: { estado: "presente", retardo: true } },
    });
    await botones(w, "b", "tarde").trigger("click");
    await flushPromises();
    expect(confirmo.asistencia).toHaveBeenCalledTimes(1);
    expect(botones(w, "b", "estado").text()).toBe("Llegó tarde");

    // El filtro «Por marcar» deja solo a quien falta.
    await w.get('[data-prueba="filtro-por_marcar"]').trigger("click");
    expect(filas()).toEqual(["Ana Ruiz"]);
  });

  it("buscar por nombre sin acentos y terminar la lista", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T16:20:00Z"));
    api.get.mockResolvedValue({
      data: {
        data: [reserva("a", "Ángela Díaz"), reserva("b", "Beto Luna")],
        meta: meta(true),
      },
    });
    api.post.mockResolvedValue({ data: { data: { no_se_presentaron: 2 } } });
    const w = montar();
    await flushPromises();

    await w.get('input[type="search"]').setValue("angela");
    expect(w.findAll(".pl-fila")).toHaveLength(1);
    await w.get('input[type="search"]').setValue("zzz");
    expect(w.text()).toContain("Nadie en la lista coincide con la búsqueda.");

    expect(w.get('[data-prueba="pie-lista"]').text()).toContain(
      "Faltan 2 por marcar: al terminar, quedarán como «no vino».",
    );
    await w.get('[data-prueba="terminar-lista"]').trigger("click");
    await flushPromises();
    expect(confirmo.lista).toHaveBeenCalled();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/sesiones/s1/terminar-lista",
      {},
    );
    expect(toast.exito).toHaveBeenCalledWith(
      "Lista terminada: 2 personas quedaron como «no vino».",
    );
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  it("sin permiso de marcar, solo se ve cómo va", async () => {
    permisos.delete("asistencia.marcar");
    api.get.mockResolvedValue({
      data: {
        data: [reserva("a", "Ana Ruiz", "presente")],
        meta: meta(true),
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="llego"]').exists()).toBe(false);
    expect(w.find('[data-prueba="cambiar"]').exists()).toBe(false);
    expect(w.find('[data-prueba="pie-lista"]').exists()).toBe(false);
    expect(w.find('[data-prueba="abrir-agregar"]').exists()).toBe(false);
    expect(botones(w, "a", "estado").text()).toBe("Llegó");
  });

  it("si no carga, lo dice y deja reintentar", async () => {
    api.get.mockRejectedValueOnce(new Error("Sin conexión"));
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Sin conexión");
    api.get.mockResolvedValue({ data: { data: [], meta: meta(false) } });
    await w.get(".tu-card button").trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="estado-lista"]').exists()).toBe(true);
  });

  it("muestra quién reservó desde una plataforma: Wellhub se marca, TotalPass llega sola y se puede cancelar", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T16:05:00Z"));
    permisos.add("reservas.gestionar");
    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: {
          ...meta(true),
          plataforma: [
            {
              id: "l1",
              proveedor: "wellhub",
              nombre: "Beto Ruiz",
              estado: "reservado",
              puede_marcar: true,
            },
            {
              id: "l2",
              proveedor: "totalpass",
              nombre: "Luis Mora",
              estado: "reservado",
              puede_marcar: false,
            },
          ],
        },
      },
    });
    api.post.mockResolvedValue({ data: {} });
    const w = montar();
    await flushPromises();

    const seccion = w.get('[data-prueba="plataforma-clase"]');
    expect(seccion.text()).toContain("Beto Ruiz");
    expect(seccion.text()).toContain("Hace check-in en su app");
    await seccion
      .findAll("button")
      .find((b) => b.text() === "Llegó")!
      .trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/lugares-plataforma/l1/llego",
      {},
    );

    await w
      .get('[data-prueba="plataforma-clase"]')
      .findAll("button")
      .find((b) => b.text() === "Cancelar reserva")!
      .trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/lugares-plataforma/l2/cancelar",
      {},
    );
  });

  it("con Fitpass activo, recepción pega las reservas de Fitpass Admin", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T15:00:00Z"));
    permisos.add("reservas.gestionar");
    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: { ...meta(false), plataforma: [], fitpass: true },
      },
    });
    api.post.mockResolvedValue({
      data: { data: [{ id: "48211", error: null }] },
    });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="capturar-fitpass"]').trigger("click");
    await w
      .get("#reservas-fitpass")
      .setValue("48211\tAna Ruiz\tana@correo.mx\n48212, Beto Paz");
    await w.get('[data-prueba="plataforma-clase"] form').trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/sesiones/s1/lugares-plataforma",
      {
        reservas: [
          { id: "48211", nombre: "Ana Ruiz", correo: "ana@correo.mx" },
          { id: "48212", nombre: "Beto Paz" },
        ],
      },
    );
  });

  it("antes de la clase, quien gestiona reservas cancela una invitación (con confirmación)", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T15:00:00Z"));
    permisos.add("reservas.gestionar");
    const invitado = {
      id: "i1",
      nombre: "Luz Mar",
      anfitrion: "Ana Ruiz",
      estado: "reservado",
    };
    api.get
      .mockResolvedValueOnce({
        data: { data: [], meta: { ...meta(false), invitados: [invitado] } },
      })
      .mockResolvedValue({
        data: { data: [], meta: { ...meta(false), invitados: [] } },
      });
    api.delete.mockResolvedValue({ data: {} });
    const w = montar();
    await flushPromises();

    const seccion = w.get('[data-prueba="invitados-clase"]');
    expect(seccion.text()).toContain("Luz Mar");
    await seccion.get('[data-prueba="cancelar-invitado"]').trigger("click");
    await flushPromises();

    expect(confirmo.lista).toHaveBeenCalledWith(
      expect.stringContaining("recupera su pase"),
      expect.objectContaining({
        peligro: true,
        aceptar: "Cancelar invitación",
      }),
    );
    expect(api.delete).toHaveBeenCalledWith("/api/v1/app/demo/invitados/i1");
    expect(w.find('[data-prueba="invitados-clase"]').exists()).toBe(false);
  });

  it("sin permiso de reservas, o ya empezada la clase, no se cancela la invitación", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T16:10:00Z"));
    permisos.add("reservas.gestionar");
    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: {
          ...meta(true),
          invitados: [
            {
              id: "i1",
              nombre: "Luz Mar",
              anfitrion: null,
              estado: "reservado",
            },
          ],
        },
      },
    });
    const empezada = montar();
    await flushPromises();
    expect(empezada.find('[data-prueba="cancelar-invitado"]').exists()).toBe(
      false,
    );
    empezada.unmount();

    vi.setSystemTime(new Date("2030-01-09T15:00:00Z"));
    permisos.delete("reservas.gestionar");
    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: {
          ...meta(false),
          invitados: [
            {
              id: "i1",
              nombre: "Luz Mar",
              anfitrion: null,
              estado: "reservado",
            },
          ],
        },
      },
    });
    const sinPermiso = montar();
    await flushPromises();
    expect(sinPermiso.find('[data-prueba="cancelar-invitado"]').exists()).toBe(
      false,
    );
  });

  it("con Fitpass activo, recepción ve lo pendiente en Fitpass Admin sin ir a Integraciones", async () => {
    permisos.add("checkins.registrar");
    api.get.mockResolvedValue({
      data: { data: [], meta: { ...meta(false), fitpass: true } },
    });
    const w = montar();
    await flushPromises();

    const seccion = w.get('[data-prueba="fitpass-admin"]');
    expect(seccion.text()).toContain("Pendientes en Fitpass Admin");
    expect(w.findComponent({ name: "FitpassOperacion" }).exists()).toBe(false);
    await seccion.get('[data-prueba="ver-fitpass-admin"]').trigger("click");
    const fitpass = w.findComponent({ name: "FitpassOperacion" });
    expect(fitpass.exists()).toBe(true);
    expect(fitpass.props("operacion")).toBe(true);
  });

  it("sin Fitpass activo, o sin permisos de recepción, no aparece", async () => {
    permisos.add("checkins.registrar");
    api.get.mockResolvedValue({
      data: { data: [], meta: { ...meta(false), fitpass: false } },
    });
    const sinFitpass = montar();
    await flushPromises();
    expect(sinFitpass.find('[data-prueba="fitpass-admin"]').exists()).toBe(
      false,
    );
    sinFitpass.unmount();

    permisos.clear();
    api.get.mockResolvedValue({
      data: { data: [], meta: { ...meta(false), fitpass: true } },
    });
    const sinPermiso = montar();
    await flushPromises();
    expect(sinPermiso.find('[data-prueba="fitpass-admin"]').exists()).toBe(
      false,
    );
  });
});
