import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MedicionNegocio from "./MedicionNegocio.vue";

/*
| Medición del negocio (ADR 0143): sus propios GA4 y Meta, con los eventos adicionales
| que decide mandar; Meta explica que va desde el servidor (sin píxel en su página).
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const destino = (proveedor: "ga4" | "meta") => ({
  proveedor,
  identificador: null,
  secreto_configurado: false,
  codigo_prueba: null,
  activo: false,
  activo_desde: null,
  aviso_confirmado_en: null,
  estado: "apagado",
  ultimo_envio_en: null,
  ultimo_error: null,
  ultimo_error_en: null,
  eventos_extra: [],
});

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({
    data: {
      data: {
        destinos: [destino("ga4"), destino("meta")],
        envios: [
          {
            id: "e1",
            proveedor: "ga4",
            evento: "solicitud",
            nombre: "generate_lead",
            ocurrido_en: "2026-10-12T15:00:00Z",
            estado: "omitido",
            motivo: "preferencias",
            intentos: 0,
            proximo_intento_en: null,
            enviado_en: null,
            ultimo_error: null,
          },
        ],
      },
    },
  });
  api.put.mockResolvedValue({ data: { data: destino("ga4") } });
});

describe("medición del negocio", () => {
  it("muestra sus dos destinos, sus envíos y manda los eventos adicionales que elige", async () => {
    const w = mount(MedicionNegocio, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/medicion/destinos");
    const destinos = w.findAll('[data-prueba="destino-medicion"]');
    expect(destinos).toHaveLength(2);
    expect(destinos[1]!.text()).toContain("El píxel no se carga en tu página");
    expect(w.get('[data-prueba="envio-medicion"]').text()).toContain(
      "la persona no lo autorizó",
    );

    await destinos[0]!
      .get('[data-prueba="identificador-destino"]')
      .setValue("G-NEGOCIO123");
    await destinos[0]!.get('[data-prueba="extra-asistencia"]').setValue(true);
    await destinos[0]!.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/medicion/destinos/ga4",
      expect.objectContaining({
        identificador: "G-NEGOCIO123",
        eventos_extra: ["asistencia"],
      }),
    );
  });
});
