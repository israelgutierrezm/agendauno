import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ModulosLanding from "./ModulosLanding.vue";
import {
  NEGOCIOS_POR_MODO,
  solucionDeNegocio,
  solucionesDe,
} from "@/marketing/modalidades";

describe("catálogo de módulos y enlaces por negocio", () => {
  it.each(["clases", "citas"] as const)(
    "%s: permite explorar un módulo y conserva los límites del plan",
    async (modo) => {
      const v = mount(ModulosLanding, { props: { modo } });
      expect(v.findAll("article")).toHaveLength(6);
      expect(v.findAll("h3").map((h) => h.text())).toContain(
        modo === "clases" ? "Alumnos" : "Clientes",
      );
      const controles = v.findAll("button");
      await controles[1]!.trigger("click");
      expect(controles[1]!.attributes("aria-expanded")).toBe("true");
      expect(
        v.get(`#${controles[1]!.attributes("aria-controls")}`).isVisible(),
      ).toBe(true);
      expect(
        v.get(`#${controles[1]!.attributes("aria-controls")}`).text(),
      ).toContain("México");
      await controles[4]!.trigger("click");
      expect(controles[1]!.attributes("aria-expanded")).toBe("false");
      expect(controles[4]!.attributes("aria-expanded")).toBe("true");
      await controles[4]!.trigger("click");
      expect(controles[4]!.attributes("aria-expanded")).toBe("false");
      v.unmount();
    },
  );
  it.each(["clases", "citas"] as const)(
    "%s: todas las páginas existentes se descubren desde el carrusel",
    (modo) => {
      const destinos = NEGOCIOS_POR_MODO[modo]
        .map(solucionDeNegocio)
        .filter(Boolean);
      const existentes = solucionesDe(modo).map(
        (s) => `/software-para-${s.slug}`,
      );
      expect([...new Set(destinos)].sort()).toEqual(existentes.sort());
      expect(solucionDeNegocio("no-existe")).toBeUndefined();
    },
  );
});
