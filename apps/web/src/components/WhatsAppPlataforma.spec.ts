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

const plantillas = {
  duenos: [
    {
      evento: "registro.codigo",
      nombre: "agendauno_codigo_verificacion",
      titulo: "Código de verificación",
      texto: "{{1}} es tu código de verificación.",
      idioma: "es_MX",
      categoria: "AUTHENTICATION",
    },
  ],
  negocios: [
    {
      evento: "reserva.confirmada",
      nombre: "agendauno_reserva_confirmada",
      titulo: "Reserva confirmada",
      texto: "Hola {{1}}, {{2}} confirmó tu lugar.",
      idioma: "es_MX",
      categoria: "UTILITY",
    },
  ],
};

function config(extra: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        negocios: false,
        duenos: false,
        conectado: false,
        phone_number_id: "",
        token_configurado: false,
        plantillas,
        webhook: {
          url: "http://api/api/v1/webhooks/whatsapp",
          token_verificacion: "token-de-verificacion",
          app_secret_configurado: false,
        },
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
  it("sin conectar lo dice, separa los dos usos con sus plantillas y no ofrece la prueba", async () => {
    http.get.mockResolvedValue(config({ negocios_habilitados: 2 }));
    const w = montar();
    await flushPromises();

    expect(http.get).toHaveBeenCalledWith("/api/v1/plataforma/whatsapp", {
      headers: { Authorization: "Bearer tk" },
    });
    expect(w.get('[data-prueba="estado"]').text()).toBe("Sin conectar");
    const duenos = w.get('[data-prueba="uso-duenos"]').text();
    expect(duenos).toContain("Con los dueños");
    expect(duenos).toContain("Apagado");
    expect(duenos).toContain("agendauno_codigo_verificacion");
    expect(duenos).toContain("Autenticación");
    const negocios = w.get('[data-prueba="uso-negocios"]').text();
    expect(negocios).toContain("De los negocios a sus clientes");
    expect(negocios).toContain("agendauno_reserva_confirmada");
    // Se activa negocio por negocio (ADR 0083).
    expect(w.get('[data-prueba="negocios-habilitados"]').text()).toBe(
      "Activado en 2 negocios",
    );
    expect(w.find('[data-prueba="prueba"]').exists()).toBe(false);
  });

  it("conecta y enciende solo lo de los dueños; el token no se vuelve a mostrar", async () => {
    http.put.mockResolvedValue(
      config({
        duenos: true,
        conectado: true,
        phone_number_id: "109876543210",
        token_configurado: true,
      }),
    );
    const w = montar();
    await flushPromises();

    await w.get("#wa-numero").setValue("109876543210");
    await w.get("#wa-token").setValue("EAAG-token");
    await w.get('[data-prueba="activar-duenos"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/whatsapp",
      {
        duenos: true,
        negocios: false,
        phone_number_id: "109876543210",
        token: "EAAG-token",
        app_secret: null,
      },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(w.get('[data-prueba="estado"]').text()).toBe("Conectado");
    expect(w.get('[data-prueba="uso-duenos"]').text()).toContain("Encendido");
    expect(w.get('[data-prueba="uso-negocios"]').text()).toContain("Apagado");
    expect((w.get("#wa-token").element as HTMLInputElement).value).toBe("");
    expect(w.get("#wa-token").attributes("placeholder")).toContain("Guardado");
    // Conectado: ya se puede mandar una prueba.
    expect(w.find('[data-prueba="prueba"]').exists()).toBe(true);
  });

  it("muestra lo que se carga en Meta para el webhook y guarda el App Secret sin mostrarlo", async () => {
    http.put.mockResolvedValue(
      config({
        webhook: {
          url: "http://api/api/v1/webhooks/whatsapp",
          token_verificacion: "token-de-verificacion",
          app_secret_configurado: true,
        },
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="webhook"]').text()).toContain(
      "Webhook de estados",
    );
    expect((w.get("#wa-webhook-url").element as HTMLInputElement).value).toBe(
      "http://api/api/v1/webhooks/whatsapp",
    );
    expect((w.get("#wa-webhook-token").element as HTMLInputElement).value).toBe(
      "token-de-verificacion",
    );

    await w.get("#wa-app-secret").setValue("secreto");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(http.put.mock.calls[0][1]).toMatchObject({ app_secret: "secreto" });
    expect((w.get("#wa-app-secret").element as HTMLInputElement).value).toBe(
      "",
    );
    expect(w.get("#wa-app-secret").attributes("placeholder")).toContain(
      "Guardado",
    );
  });
});
