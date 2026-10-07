import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import AvisosAgendaUno from "./AvisosAgendaUno.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const permisos = vi.hoisted(() => ({ gestionar: true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: (p: string) => p !== "estudio.gestionar" || permisos.gestionar,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const URL = "/api/v1/app/demo/avisos-plataforma";

function avisos(
  whatsapp: Record<string, unknown> | null,
  numero = "+52 5512345678",
) {
  return {
    data: { data: { correo: "duena@correo.mx", numero, pais: "52", whatsapp } },
  };
}

function montar() {
  return mount(AvisosAgendaUno, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.gestionar = true;
});

describe("avisos de AgendaUno al dueño", () => {
  it("sin WhatsApp en la plataforma solo dice a qué correo le llegan", async () => {
    permisos.gestionar = false;
    api.get.mockResolvedValue(avisos(null));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("duena@correo.mx");
    expect(w.find('[data-prueba="estado-whatsapp"]').exists()).toBe(false);
    expect(w.find('[data-prueba="cambiar-whatsapp"]').exists()).toBe(false);
  });

  it("verifica su número con el código y queda recibiéndolos por WhatsApp", async () => {
    api.get.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: false, acepta: false }),
    );
    api.post.mockImplementation((url: string) =>
      Promise.resolve(
        url.endsWith("/verificar")
          ? avisos({
              numero: "+52 5512345678",
              verificado: true,
              acepta: true,
            })
          : { data: { data: { enviado: true } } },
      ),
    );
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="estado-whatsapp"]').text()).toBe(
      "Sin verificar",
    );

    await w.get('[data-prueba="verificar-whatsapp"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/whatsapp/codigo`, {});

    await w.get("#aa-codigo").setValue("123456");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/whatsapp/verificar`, {
      codigo: "123456",
    });
    expect(w.get('[data-prueba="estado-whatsapp"]').text()).toBe("Verificado");
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it("puede dejar de recibirlos por WhatsApp", async () => {
    api.get.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: true, acepta: true }),
    );
    api.put.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: true, acepta: false }),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="acepta-whatsapp"]').setValue(false);
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(URL, { acepta_whatsapp: false });
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(false);
  });

  it("cambia el número con el código que llega al nuevo", async () => {
    api.get.mockResolvedValue(
      avisos({ numero: "+52 5512345678", verificado: true, acepta: true }),
    );
    api.post.mockResolvedValue({ data: { data: { enviado: true } } });
    api.put.mockResolvedValue(
      avisos(
        { numero: "+52 55 8765 4321", verificado: true, acepta: true },
        "+52 55 8765 4321",
      ),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="cambiar-whatsapp"]').trigger("click");
    await w.get("#aa-numero").setValue("55 8765 4321");
    await w.get('[data-prueba="cambio-whatsapp"]').trigger("submit");
    await flushPromises();
    // La lada es la del dueño; el número va sin espacios.
    expect(api.post).toHaveBeenCalledWith(`${URL}/whatsapp/cambio/codigo`, {
      contacto_whatsapp_pais: "52",
      contacto_telefono: "5587654321",
    });
    expect(w.get('[data-prueba="codigo-whatsapp"]').text()).toContain(
      "+52 5587654321",
    );

    await w.get("#aa-codigo").setValue("654321");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(`${URL}/whatsapp`, {
      contacto_whatsapp_pais: "52",
      contacto_telefono: "5587654321",
      codigo: "654321",
    });
    expect(w.get('[data-prueba="numero-whatsapp"]').text()).toBe(
      "+52 55 8765 4321",
    );
    expect(w.find('[data-prueba="codigo-whatsapp"]').exists()).toBe(false);
  });

  it("sin WhatsApp en la plataforma, quien gestiona el negocio lo cambia sin código", async () => {
    api.get.mockResolvedValue(avisos(null));
    api.put.mockResolvedValue(avisos(null, "+57 300 123 4567"));
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("aparece en tu página");

    await w.get('[data-prueba="cambiar-whatsapp"]').trigger("click");
    // De la lista completa de países (la lada del dueño, de inicio).
    expect(w.get('[data-prueba="lada-celular"]').text()).toBe("MX +52");
    await w.get("select").setValue("CO");
    await w.get("#aa-numero").setValue("300 123 4567");
    await w.get('[data-prueba="cambio-whatsapp"]').trigger("submit");
    await flushPromises();

    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).toHaveBeenCalledWith(`${URL}/whatsapp`, {
      contacto_whatsapp_pais: "57",
      contacto_telefono: "3001234567",
    });
    expect(w.get('[data-prueba="numero-whatsapp"]').text()).toBe(
      "+57 300 123 4567",
    );
  });
});

describe("lada del dueño fuera de México", () => {
  it("propone la lada que tiene el dueño, aunque no sea de México", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          correo: "duena@correo.mx",
          numero: "+56 912345678",
          pais: "56",
          whatsapp: null,
        },
      },
    });
    api.put.mockResolvedValue(avisos(null, "+56 987654321"));
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="cambiar-whatsapp"]').trigger("click");
    expect(w.get('[data-prueba="lada-celular"]').text()).toBe("CL +56");
    await w.get("#aa-numero").setValue("9 8765 4321");
    await w.get('[data-prueba="cambio-whatsapp"]').trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(`${URL}/whatsapp`, {
      contacto_whatsapp_pais: "56",
      contacto_telefono: "987654321",
    });
  });
});
