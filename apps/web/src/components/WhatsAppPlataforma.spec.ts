import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import WhatsAppPlataforma from "./WhatsAppPlataforma.vue";

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const plantillas = [
  {
    evento: "reserva.confirmada",
    nombre: "agendauno_reserva_confirmada",
    titulo: "Reserva confirmada",
    texto: "Hola {{1}}, {{2}} confirmó tu lugar.",
    idioma: "es_MX",
    categoria: "UTILITY",
  },
];

function config(extra: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        encendido: false,
        activo: false,
        phone_number_id: "",
        token_configurado: false,
        plantillas,
        ...extra,
      },
    },
  };
}

function montar() {
  return mount(WhatsAppPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { plataformaAdmin } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockResolvedValue(config());
});

describe("WhatsApp de la plataforma", () => {
  it("apagado lo dice, lista las plantillas para Meta y no ofrece la prueba", async () => {
    const w = montar();
    await flushPromises();

    expect(http.get).toHaveBeenCalledWith("/api/v1/plataforma/whatsapp", {
      headers: { Authorization: "Bearer tk" },
    });
    expect(w.get('[data-prueba="estado"]').text()).toBe("Apagado");
    const lista = w.get('[data-prueba="plantillas"]').text();
    expect(lista).toContain("Plantillas a registrar en Meta (1)");
    expect(lista).toContain("agendauno_reserva_confirmada");
    expect(lista).toContain("Hola {{1}}, {{2}} confirmó tu lugar.");
    expect(w.find('[data-prueba="prueba"]').exists()).toBe(false);
  });

  it("lo enciende con número y token; el token no se vuelve a mostrar", async () => {
    http.put.mockResolvedValue(
      config({
        encendido: true,
        activo: true,
        phone_number_id: "109876543210",
        token_configurado: true,
      }),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="encendido"]').setValue(true);
    await w.get("#wa-numero").setValue("109876543210");
    await w.get("#wa-token").setValue("EAAG-token");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/whatsapp",
      { encendido: true, phone_number_id: "109876543210", token: "EAAG-token" },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(w.get('[data-prueba="estado"]').text()).toBe("Encendido");
    expect((w.get("#wa-token").element as HTMLInputElement).value).toBe("");
    expect(w.get("#wa-token").attributes("placeholder")).toContain("Guardado");
    // Encendido y completo: ya se puede mandar una prueba.
    expect(w.find('[data-prueba="prueba"]').exists()).toBe(true);
  });

  it("encendido sin número o token lo advierte", async () => {
    http.get.mockResolvedValue(config({ encendido: true, activo: false }));
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="estado"]').text()).toBe(
      "Encendido, falta el número o el token",
    );
  });
});
