import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { terminologiaNegocio } from "@/i18n/locales/gestion.es-MX";
import type { DatosTerminologia } from "@/lib/terminologia";
import TerminologiaNegocio from "./TerminologiaNegocio.vue";

vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: unknown) => String(e),
}));

const barberia: DatosTerminologia = {
  opciones: {
    sesion: ["Clase", "Cita", "Consulta"],
    miembro: ["Cliente", "Socio"],
    instructor: ["Barbero", "Estilista"],
  },
  del_perfil: { sesion: "Cita", miembro: "Cliente", instructor: "Barbero" },
  propia: {},
  vigente: {
    sesion: "Cita",
    sesiones: "Citas",
    miembro: "Cliente",
    miembros: "Clientes",
    instructor: "Barbero",
    instructores: "Barberos",
  },
};

describe("cómo se llaman las cosas", () => {
  it("parte de lo del giro y guarda solo lo que se eligió", async () => {
    const guardar = vi.fn().mockResolvedValue({
      ...barberia,
      propia: { instructor: "Estilista" },
      vigente: {
        ...barberia.vigente,
        instructor: "Estilista",
        instructores: "Estilistas",
      },
    });
    const w = mount(TerminologiaNegocio, {
      props: { cargar: () => Promise.resolve(barberia), guardar },
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            messages: { es: { terminologiaNegocio } },
          }),
        ],
      },
    });
    await flushPromises();

    expect(w.get("#term-sesion option").text()).toBe("Como en tu giro (Cita)");
    await w.get("#term-instructor").setValue("Estilista");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(guardar).toHaveBeenCalledWith({
      sesion: null,
      miembro: null,
      instructor: "Estilista",
    });
    expect(w.emitted("guardado")?.[0]?.[0]).toMatchObject({
      instructores: "Estilistas",
    });
    expect(w.text()).toContain("tus pantallas ya usan estas palabras");
  });
});
