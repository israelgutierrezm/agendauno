import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import WhatsAppNegocio, {
  type EstadoWhatsAppNegocio,
} from "./WhatsAppNegocio.vue";

const http = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

function montar(inicial: EstadoWhatsAppNegocio) {
  return mount(WhatsAppNegocio, {
    props: {
      apiUrl: "http://api",
      token: "tk",
      slug: "barberia",
      nombre: "Barbería Demo",
      inicial,
    },
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
  confirmar.mockResolvedValue(true);
});

describe("WhatsApp del negocio (superadmin)", () => {
  it("desactivado por omisión; activarlo pide confirmación porque lo paga la plataforma", async () => {
    http.put.mockResolvedValue({
      data: { data: { habilitado: true, plataforma: true } },
    });
    const w = montar({ habilitado: false, plataforma: true });

    expect(w.get('[data-prueba="estado"]').text()).toBe("Desactivado");
    expect(w.get('[data-prueba="cambiar"]').text()).toBe("Activar");

    await w.get('[data-prueba="cambiar"]').trigger("click");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("Barbería Demo"),
    );
    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios/barberia/whatsapp",
      { habilitado: true },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(w.get('[data-prueba="estado"]').text()).toBe("Activo");
    expect(w.get('[data-prueba="cambiar"]').text()).toBe("Desactivar");
  });

  it("si no confirma no cambia nada; desactivarlo no pregunta", async () => {
    confirmar.mockResolvedValue(false);
    const w = montar({ habilitado: false, plataforma: true });
    await w.get('[data-prueba="cambiar"]').trigger("click");
    await flushPromises();
    expect(http.put).not.toHaveBeenCalled();

    http.put.mockResolvedValue({
      data: { data: { habilitado: false, plataforma: true } },
    });
    const activo = montar({ habilitado: true, plataforma: true });
    await activo.get('[data-prueba="cambiar"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledTimes(1);
    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios/barberia/whatsapp",
      { habilitado: false },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(activo.get('[data-prueba="estado"]').text()).toBe("Desactivado");
  });

  it("activado con la plataforma apagada lo dice y explica dónde encenderlo", () => {
    const w = montar({ habilitado: true, plataforma: false });
    expect(w.get('[data-prueba="estado"]').text()).toBe(
      "Activado; apagado en la plataforma",
    );
    expect(w.text()).toContain("Configuración → WhatsApp");
  });
});
