import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { cambiarSerie, reprogramar } from "@/i18n/locales/gestion.es-MX";
import CambiarSerie from "./CambiarSerie.vue";

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
}));

function montar(dias: number[] = [1, 3]) {
  return mount(CambiarSerie, {
    props: {
      base: "/api/v1/app/a",
      serieId: "s1",
      fecha: "2030-01-14",
      zona: "America/Mexico_City",
      iniciaEn: "2030-01-14T14:00:00Z",
      duracion: 60,
      profesionales: [],
      profesionalId: null,
      dias,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { cambiarSerie, reprogramar } },
        }),
      ],
    },
  });
}

describe("cambiar una clase recurrente", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("cambia los días y muestra qué fechas se quitan, se crean o no se pueden crear", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          aplicado: false,
          movidas: 1,
          conservadas: [],
          quitadas: 2,
          creadas: 3,
          omitidas: [{ fecha: "2030-01-23", motivo: "La sala está ocupada." }],
        },
      },
    });
    const w = montar();
    // Quita el miércoles y agrega el viernes.
    await w.get('[aria-label="miércoles"]').trigger("click");
    await w.get('[aria-label="viernes"]').trigger("click");
    expect(w.get('[aria-label="lunes"]').attributes("aria-pressed")).toBe(
      "true",
    );
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/a/plantillas-horario/s1/cambiar",
      expect.objectContaining({
        desde: "2030-01-14",
        dias_semana: [1, 5],
        previsualizar: true,
      }),
    );
    const texto = w.get('[role="status"]').text();
    expect(texto).toContain("Se quitan 2 fechas");
    expect(texto).toContain("Se crean 3 fechas");
    expect(texto).toContain("La sala está ocupada.");
  });

  it("no deja la clase sin días", async () => {
    const w = montar([2]);
    await w.get('[aria-label="martes"]').trigger("click");
    expect(w.get('[aria-label="martes"]').attributes("aria-pressed")).toBe(
      "true",
    );
  });
});
