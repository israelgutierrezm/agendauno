import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import FichaMiembroView from "./FichaMiembroView.vue";

/**
 * Ficha del miembro: el saldo es solo de lo vigente; lo vencido o cancelado es
 * historial y se abre aparte. Datos sintéticos.
 */
const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/regreso", () => ({
  useRegreso: () => ({ destino: { name: "miembros" }, etiqueta: "Miembros" }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { id: "p1" }, query: {} }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

function derecho(
  id: string,
  producto: string,
  extra: Record<string, unknown> = {},
) {
  return {
    id,
    producto,
    estado: "activo",
    ilimitado: false,
    saldo_creditos: 4,
    saldo_unidades: 4000,
    disponible_unidades: 4000,
    valido_hasta: "2099-12-31",
    acuerdo_id: `a-${id}`,
    pausa_hasta: null,
    ...extra,
  };
}

function respuestas(derechos: unknown[]) {
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve(
      url.endsWith("/resumen")
        ? {
            data: {
              data: {
                nombre_completo: "Vale Ruiz",
                email: null,
                saldo_creditos: 0,
                membresia: { estado: "vigente", valido_hasta: null },
                adeudo: false,
                documentos_pendientes: 0,
                asistencias: 0,
                primera_vez: false,
                proxima_reserva: null,
                alertas: [],
              },
            },
          }
        : {
            data: {
              data: {
                persona: {
                  id: "p1",
                  nombre_completo: "Vale Ruiz",
                  email: null,
                  tipo: "miembro",
                  activo: true,
                  es_facturable: true,
                  archivado: false,
                  alta: null,
                  sucursal: null,
                },
                derechos,
                reservas: [],
                ordenes: [],
              },
            },
          },
    ),
  );
}

function montar() {
  return mount(FichaMiembroView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      stubs: {
        PanelMiembro: true,
        PanelEditarMiembro: true,
        CortePlanes: true,
        ExpedientePersona: true,
        AvatarIniciales: true,
        teleport: true,
      },
    },
  });
}

describe("membresías y paquetes en la ficha", () => {
  beforeEach(() => vi.clearAllMocks());

  it("el saldo suma solo lo vigente; lo vencido y lo cancelado van al historial", async () => {
    respuestas([
      // 6 en el saldo: 4 puede usarlas y 2 ya las reservó.
      derecho("d1", "Paquete 8 clases", {
        saldo_creditos: 6,
        saldo_unidades: 6000,
        disponible_unidades: 4000,
      }),
      derecho("d2", "Paquete 4 clases", {
        saldo_creditos: 3,
        saldo_unidades: 3000,
        valido_hasta: "2020-01-31",
      }),
      derecho("d3", "Paquete 12 clases", {
        estado: "cancelado",
        saldo_creditos: 10,
        saldo_unidades: 10000,
      }),
    ]);
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="saldo-vigente"]').text()).toBe(
      "Saldo vigente: 4 disponibles · 2 apartadas",
    );
    // El resumen de al lado dice lo mismo.
    expect(w.get('[data-prueba="saldo-resumen"]').text()).toBe(
      "4 disponibles · 2 apartadas",
    );
    expect(w.text()).toContain("Paquete 8 clases");
    expect(w.text()).not.toContain("Paquete 4 clases");
    expect(w.text()).not.toContain("Paquete 12 clases");

    const ver = w.get('[data-prueba="ver-historial-planes"]');
    expect(ver.text()).toBe("Ver historial (2)");
    await ver.trigger("click");
    expect(w.find('[data-prueba="historial-planes"]').exists()).toBe(true);
    expect(w.text()).toContain("Paquete 4 clases");
    expect(w.text()).toContain("Paquete 12 clases");
    // El saldo no cambia al ver el historial.
    expect(w.get('[data-prueba="saldo-vigente"]').text()).toBe(
      "Saldo vigente: 4 disponibles · 2 apartadas",
    );
  });

  it("con una membresía ilimitada vigente, todo dice «Ilimitado»; la fecha es la del calendario", async () => {
    respuestas([
      derecho("d1", "Ilimitada", {
        ilimitado: true,
        saldo_creditos: null,
        saldo_unidades: null,
        disponible_unidades: null,
        valido_hasta: "2099-10-31",
      }),
      derecho("d2", "Paquete 4 clases", {
        saldo_unidades: 2000,
        disponible_unidades: 2000,
      }),
    ]);
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="saldo-vigente"]').text()).toBe(
      "Saldo vigente: Ilimitado",
    );
    expect(w.get('[data-prueba="saldo-resumen"]').text()).toBe("Ilimitado");
    // 31 de octubre, no el 30 (una fecha no es la medianoche UTC).
    expect(w.text()).toContain("Vence 31 oct 2099");
  });

  it("sin nada vigente lo dice, y lo anterior sigue en el historial", async () => {
    respuestas([
      derecho("d2", "Paquete 4 clases", { valido_hasta: "2020-01-31" }),
    ]);
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="saldo-vigente"]').text()).toBe(
      "Sin membresía ni paquete vigente.",
    );
    expect(w.find('[data-prueba="ver-historial-planes"]').exists()).toBe(true);
  });
});
