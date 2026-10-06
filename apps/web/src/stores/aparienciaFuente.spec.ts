import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAparienciaStore } from "./apariencia";

/*
| Tipo de letra de Apariencia: se aplica como `--fuente` sobre <html>, se carga su
| hoja de Google Fonts una sola vez y se quita en las páginas públicas.
*/

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), put: vi.fn() } }));
vi.mock("@/stores/tema", () => ({
  useTemaStore: () => ({ inicializar: vi.fn() }),
}));

const apariencia = (fuente?: string) => ({
  clave: "agendauno",
  nombre: "AgendaUno",
  oscuro: false,
  permite_personalizar: true,
  tokens: { acento: "#006DF7" },
  personalizacion: {},
  ...(fuente
    ? { fuente: { clave: fuente.toLowerCase(), nombre: fuente } }
    : {}),
});

describe("tipo de letra de la apariencia", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    document.documentElement.removeAttribute("style");
    document.head
      .querySelectorAll("link[id^='fuente-']")
      .forEach((l) => l.remove());
  });

  it("aplica la letra elegida y carga su hoja una sola vez", () => {
    const store = useAparienciaStore();
    store.activar(apariencia("Montserrat"));
    store.activar(apariencia("Montserrat"));
    expect(
      document.documentElement.style.getPropertyValue("--fuente"),
    ).toContain('"Montserrat"');
    const enlaces = document.head.querySelectorAll("#fuente-montserrat");
    expect(enlaces).toHaveLength(1);
    expect((enlaces[0] as HTMLLinkElement).href).toContain("family=Montserrat");
  });

  it("Poppins ya viene en la página: no agrega otra hoja", () => {
    useAparienciaStore().activar(apariencia("Poppins"));
    expect(document.head.querySelector("link[id^='fuente-']")).toBeNull();
  });

  it("en las páginas públicas se quita y vuelve en el panel", () => {
    const store = useAparienciaStore();
    store.activar(apariencia("Open Sans"));
    store.pausar(true);
    expect(document.documentElement.style.getPropertyValue("--fuente")).toBe(
      "",
    );
    store.pausar(false);
    expect(
      document.documentElement.style.getPropertyValue("--fuente"),
    ).toContain('"Open Sans"');
  });
});
