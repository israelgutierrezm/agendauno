import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { avisosWhatsApp } from "@/i18n/locales/gestion.es-MX";
import PanelMiembro from "./PanelMiembro.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const permisos = vi.hoisted(() => ({ lista: ["miembros.gestionar"] }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    terminologia: { miembro: "Cliente" },
    puede: (p: string) => permisos.lista.includes(p),
  }),
}));

function resumen(whatsapp: Record<string, boolean> | undefined) {
  return {
    data: {
      data: {
        id: "caro",
        nombre_completo: "Caro López",
        email: null,
        tipo: "miembro",
        activo: true,
        asistencias: 3,
        primera_vez: false,
        saldo_creditos: 0,
        saldo_unidades: 0,
        membresia: { estado: "sin_membresia", valido_hasta: null },
        adeudo: false,
        documentos_pendientes: 0,
        proxima_reserva: null,
        alertas: [],
        whatsapp,
      },
    },
  };
}

function montar() {
  return mount(PanelMiembro, {
    props: { personaId: "caro", nombre: "Caro", incrustado: true },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...es, avisosWhatsApp } },
        }),
      ],
      stubs: { RouterLink: true },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.lista = ["miembros.gestionar"];
});

describe("avisos por WhatsApp en la ficha de recepción", () => {
  it("si el negocio no los usa, no aparecen", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: false, acepta: false, con_celular: true }),
    );
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="whatsapp"]').exists()).toBe(false);
  });

  it("recepción marca que el cliente los pidió", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: false, con_celular: true }),
    );
    api.put.mockResolvedValue({ data: { data: { acepta_whatsapp: true } } });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="acepta-whatsapp"]').setValue(true);
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/miembros/caro", {
      acepta_whatsapp: true,
    });
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it("sin celular lo dice, y sin permiso para editar no se puede cambiar", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: false, con_celular: false }),
    );
    const sinCelular = montar();
    await flushPromises();
    expect(sinCelular.get('[data-prueba="whatsapp"]').text()).toContain(
      "Falta su celular",
    );

    permisos.lista = [];
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: true, con_celular: true }),
    );
    const soloVer = montar();
    await flushPromises();
    expect(
      soloVer.get('[data-prueba="acepta-whatsapp"]').attributes("disabled"),
    ).toBeDefined();
  });
});
