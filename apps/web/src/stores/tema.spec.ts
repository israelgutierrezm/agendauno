import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";

import { useTemaStore } from "./tema";

describe("useTemaStore", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    document.documentElement.className = "";
    delete document.documentElement.dataset.densidad;
  });

  it("alterna el modo y aplica la clase .dark en <html>", () => {
    const tema = useTemaStore();
    tema.inicializar();
    const inicial = tema.esOscuro;

    tema.alternarModo();
    expect(tema.esOscuro).toBe(!inicial);
    expect(document.documentElement.classList.contains("dark")).toBe(
      tema.esOscuro,
    );
  });

  it("ajusta la densidad dentro de los limites y la refleja en data-densidad", () => {
    const tema = useTemaStore();
    tema.inicializar();

    tema.ajustarDensidad(-1);
    expect(tema.densidad).toBe("compacta");
    expect(document.documentElement.dataset.densidad).toBe("compacta");

    // No baja de 'compacta'.
    tema.ajustarDensidad(-1);
    expect(tema.densidad).toBe("compacta");

    tema.ajustarDensidad(1);
    expect(tema.densidad).toBe("normal");
  });
});
