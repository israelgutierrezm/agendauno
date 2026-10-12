import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { DestinoMedicion as Destino } from "@/lib/medicion";
import DestinoMedicion from "./DestinoMedicion.vue";

/*
| Un destino de medición (ADR 0143): el secreto se escribe y no se vuelve a mostrar (en
| blanco, se conserva); el estado con punto y texto; probar la conexión muestra lo que
| respondió el proveedor; los errores, bajo su campo.
*/

vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const GUARDADO: Destino = {
  producto: "agendauno",
  proveedor: "meta",
  identificador: "1234567890",
  secreto_configurado: true,
  codigo_prueba: null,
  activo: true,
  activo_desde: "2026-10-12T10:00:00Z",
  aviso_confirmado_en: "2026-10-12T10:00:00Z",
  estado: "con_error",
  ultimo_envio_en: null,
  ultimo_error:
    "Meta respondió 400 (código 190). El token venció o no es válido.",
  ultimo_error_en: "2026-10-12T11:00:00Z",
};

const alGuardar = vi.fn();
const alProbar = vi.fn();
const alDesconectar = vi.fn();

function montar(destino: Destino = GUARDADO) {
  return mount(DestinoMedicion, {
    props: { destino, alGuardar, alProbar, alDesconectar },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  alGuardar.mockResolvedValue(GUARDADO);
});

describe("destino de medición", () => {
  it("el secreto no se muestra; en blanco no se manda y se conserva", async () => {
    const w = montar();
    const secreto = w.get('[data-prueba="secreto-destino"]');
    expect((secreto.element as HTMLInputElement).value).toBe("");
    expect(secreto.attributes("type")).toBe("password");
    expect(secreto.attributes("placeholder")).toContain("Guardado");
    expect(w.get('[data-prueba="estado-destino"]').text()).toBe("Con error");
    expect(w.text()).toContain("El token venció o no es válido.");

    await w.get('[data-prueba="codigo-prueba-destino"]').setValue("TEST123");
    await w.get("form").trigger("submit");
    expect(alGuardar).toHaveBeenCalledWith({
      identificador: "1234567890",
      codigo_prueba: "TEST123",
      activo: true,
      aviso_confirmado: true,
    });
  });

  it("probar la conexión muestra lo que respondió el proveedor", async () => {
    alProbar.mockResolvedValue({
      ok: true,
      mensajes: ["El token tiene acceso al píxel «Ventas»."],
    });
    const w = montar();
    await w.get('[data-prueba="probar-destino"]').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="resultado-prueba"]').text()).toContain(
      "El token tiene acceso al píxel «Ventas».",
    );
  });

  it("lo que la API rechaza aparece bajo su campo", async () => {
    alGuardar.mockRejectedValue({
      response: {
        data: {
          meta: {
            errors: {
              aviso_confirmado: [
                "Confirma que el aviso de privacidad vigente ya informa esta medición.",
              ],
            },
          },
        },
      },
    });
    const w = montar({
      ...GUARDADO,
      proveedor: "ga4",
      aviso_confirmado_en: null,
      activo: false,
      estado: "apagado",
    });
    // GA4 no pide código de prueba.
    expect(w.find('[data-prueba="codigo-prueba-destino"]').exists()).toBe(
      false,
    );
    await w.get('[data-prueba="secreto-destino"]').setValue("nuevo-secreto");
    await w.get('[data-prueba="activo-destino"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(alGuardar).toHaveBeenCalledWith(
      expect.objectContaining({ secreto: "nuevo-secreto", activo: true }),
    );
    expect(w.text()).toContain(
      "Confirma que el aviso de privacidad vigente ya informa esta medición.",
    );
  });
});
