import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import ModalidadPlataforma from "./ModalidadPlataforma.vue";

/*
| La modalidad en la ficha del negocio (superadmin, ADR 0104): solo clases o solo
| citas. Se cambia con confirmación mientras no tenga sesiones ni reservas; si ya
| opera, se explica y no se ofrece.
*/

const http = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: { message?: string }) => e.message ?? "Error",
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

function montar(modalidad: "clases" | "citas", cambiable: boolean | null) {
  return mount(ModalidadPlataforma, {
    props: {
      apiUrl: "http://api",
      token: "tk",
      slug: "casa-navaja",
      nombre: "Casa Navaja",
      modalidad,
      cambiable,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { modalidadNegocio } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  confirmar.mockResolvedValue(true);
});

describe("modalidad del negocio (superadmin)", () => {
  it("antes de operar se cambia con confirmación", async () => {
    http.put.mockResolvedValue({ data: { data: { modalidad: "clases" } } });
    const w = montar("citas", true);

    expect(w.get('[data-prueba="modalidad"]').text()).toBe("Citas 1 a 1");
    expect(w.get('[data-prueba="modalidad-ayuda"]').text()).toContain(
      "todavía se puede cambiar",
    );
    const boton = w.get('[data-prueba="cambiar-modalidad"]');
    expect(boton.text()).toBe("Cambiar a Clases con cupo");
    await boton.trigger("click");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("Casa Navaja"),
      expect.objectContaining({ peligro: true }),
    );
    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios/casa-navaja/modalidad",
      { modalidad: "clases" },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(toast.exito).toHaveBeenCalledWith("Modalidad cambiada.");
    expect(w.emitted("cambiada")).toEqual([["clases"]]);
  });

  it("sin confirmar no cambia nada", async () => {
    confirmar.mockResolvedValue(false);
    const w = montar("clases", true);
    await w.get('[data-prueba="cambiar-modalidad"]').trigger("click");
    await flushPromises();

    expect(http.put).not.toHaveBeenCalled();
    expect(w.emitted("cambiada")).toBeUndefined();
  });

  it("si ya opera, lo explica y no ofrece cambiarla", () => {
    const w = montar("clases", false);

    expect(w.get('[data-prueba="modalidad"]').text()).toBe("Clases con cupo");
    expect(w.find('[data-prueba="cambiar-modalidad"]').exists()).toBe(false);
    expect(w.get('[data-prueba="modalidad-ayuda"]').text()).toBe(
      "Ya tiene sesiones o reservas: su modalidad ya no se puede cambiar.",
    );
  });

  it("si el servidor lo niega (MODALITY_IN_USE), muestra su mensaje", async () => {
    http.put.mockRejectedValue({
      message:
        "Ya tiene sesiones o reservas; su modalidad no se puede cambiar.",
    });
    const w = montar("citas", true);
    await w.get('[data-prueba="cambiar-modalidad"]').trigger("click");
    await flushPromises();

    expect(toast.error).toHaveBeenCalledWith(
      "Ya tiene sesiones o reservas; su modalidad no se puede cambiar.",
    );
    expect(w.emitted("cambiada")).toBeUndefined();
  });

  it("si el servidor no dice si se puede cambiar, solo muestra la modalidad", () => {
    const w = montar("citas", null);

    expect(w.get('[data-prueba="modalidad"]').text()).toBe("Citas 1 a 1");
    expect(w.find('[data-prueba="cambiar-modalidad"]').exists()).toBe(false);
    expect(w.find('[data-prueba="modalidad-ayuda"]').exists()).toBe(false);
  });
});
