import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import FichaMiembroView from "./FichaMiembroView.vue";

/**
 * Ficha de un cliente de un negocio de citas: no es una ficha de alumno. Primero lo
 * que debe (con «Registrar pago»), luego sus visitas; sin planes no hay «Membresías
 * y paquetes» ni saldo. Datos sintéticos.
 */
const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  sesion: { slug: "demo", esCitas: true, puede: () => true },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => mocks.sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/regreso", () => ({
  useRegreso: () => ({ destino: { name: "miembros" }, etiqueta: "Clientes" }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { id: "p1" }, query: {} }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const visita = (id: string, clase: string, instructor: string) => ({
  id,
  clase,
  tipo: "cita",
  instructor,
  inicia_en: "2026-09-20T16:00:00Z",
  zona_horaria: "America/Mexico_City",
  estado: "confirmada",
  asistencia: "presente",
});

function respuestas(ordenes: unknown[] | null, pendientes: unknown[] | null) {
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve(
      url.endsWith("/resumen")
        ? {
            data: {
              data: {
                nombre_completo: "Jorge Pineda",
                email: null,
                saldo_creditos: 0,
                membresia: { estado: "sin", valido_hasta: null },
                adeudo: false,
                documentos_pendientes: 0,
                asistencias: 3,
                primera_vez: false,
                proxima_reserva: null,
                ultima_visita: {
                  clase: "Corte",
                  profesional: "Luis",
                  inicia_en: "2026-09-20T16:00:00Z",
                  zona_horaria: "America/Mexico_City",
                },
                alertas: [],
              },
            },
          }
        : {
            data: {
              data: {
                persona: {
                  id: "p1",
                  nombre_completo: "Jorge Pineda",
                  email: null,
                  tipo: "miembro",
                  activo: true,
                  es_facturable: true,
                  archivado: false,
                  alta: null,
                  sucursal: null,
                },
                derechos: [],
                reservas: [
                  visita("r1", "Corte", "Luis"),
                  visita("r2", "Corte", "Luis"),
                  visita("r3", "Barba", "Ana"),
                ],
                ordenes,
                pendientes,
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

const pendiente = {
  id: "o1",
  fecha: "2026-09-20T16:00:00Z",
  estado: "pendiente",
  total_minor: 25000,
  moneda: "MXN",
  metodo_pago: null,
  pagada_en: null,
  concepto: "Corte",
  sesion: {
    profesional: "Luis",
    inicia_en: "2026-09-20T16:00:00Z",
    zona_horaria: "America/Mexico_City",
  },
};

describe("ficha de un cliente de citas", () => {
  beforeEach(() => vi.clearAllMocks());

  it("primero lo que debe y sus visitas; sin planes no hay membresías ni saldo", async () => {
    respuestas([pendiente], [pendiente]);
    mocks.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    const pend = w.get('[data-prueba="pendientes-ficha"]');
    expect(pend.text()).toContain("Corte");
    expect(pend.text()).toContain("Con Luis");
    expect(pend.text()).toContain("$250.00");

    const visitas = w.get('[data-prueba="visitas-cliente"]');
    expect(visitas.text()).toContain("Sin próxima cita");
    expect(visitas.text()).toContain("Servicio habitual");
    // Lo que más pide y con quién.
    expect(visitas.text()).toMatch(/Servicio habitual\s*Corte/);
    expect(visitas.text()).toMatch(/Profesional habitual\s*Luis/);

    expect(w.text()).not.toContain("Membresías y paquetes");
    expect(w.text()).not.toContain("Saldo");
    expect(w.text()).not.toContain("Sin plan para reservar");
    expect(w.text()).toContain("Historial de citas");

    // Cita → cliente → importe → registrar pago.
    await w.get('[data-prueba="cobrar-pendiente"]').trigger("click");
    await w.get('[data-prueba="confirmar-pago"]').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes/o1/liquidar",
      { metodo: "efectivo", referencia: null },
    );
  });

  it("sin permiso para ver órdenes no hay compras ni pendientes", async () => {
    respuestas(null, null);
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="pendientes-ficha"]').exists()).toBe(false);
    expect(w.text()).not.toContain("Historial de compras");
  });
});
