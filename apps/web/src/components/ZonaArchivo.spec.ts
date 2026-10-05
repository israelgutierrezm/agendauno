import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import { aceptaArchivo, instalarGuardaDeArrastre } from "@/lib/archivos";
import ZonaArchivo from "./ZonaArchivo.vue";

/*
| La zona de carga de toda la app: se arrastra o se elige, revisa tipo y peso (al
| soltar el navegador no filtra) y dice debajo lo que no se acepta.
*/

const soltar = (archivo: File) => ({ dataTransfer: { files: [archivo] } });

function montar(props: Record<string, unknown> = {}) {
  return mount(ZonaArchivo, {
    props: { accept: ".csv,text/csv", ...props },
    global: { plugins: [i18n] },
  });
}

describe("ZonaArchivo", () => {
  it("al soltar un archivo válido lo entrega; lo demás lo dice debajo", async () => {
    const w = montar({ maxBytes: 10 });
    const zona = w.get('[data-prueba="zona-archivo"]');

    await zona.trigger("dragenter");
    expect(zona.classes()).toContain("tu-zona-archivo-activa");
    expect(zona.text()).toContain("Suéltalo para subirlo");

    // Un CSV puede llegar con el tipo de Excel en Windows: basta la extensión.
    const csv = new File(["a"], "alumnos.csv", {
      type: "application/vnd.ms-excel",
    });
    await zona.trigger("drop", soltar(csv));
    expect(zona.classes()).not.toContain("tu-zona-archivo-activa");
    expect(w.emitted("archivo")).toEqual([[csv]]);

    await zona.trigger("drop", soltar(new File(["x"], "foto.png")));
    expect(w.get("[role=alert]").text()).toBe(
      "Ese tipo de archivo no se acepta aquí.",
    );
    await zona.trigger(
      "drop",
      soltar(new File(["01234567890"], "grande.csv", { type: "text/csv" })),
    );
    expect(w.get("[role=alert]").text()).toBe(
      "El archivo pesa más de lo permitido.",
    );
    expect(w.emitted("archivo")).toHaveLength(1);
  });

  it("elegido con el selector, muestra su nombre y cómo cambiarlo", async () => {
    const w = montar({ texto: "Arrastra tu archivo CSV aquí o" });
    expect(w.text()).toContain("Arrastra tu archivo CSV aquí o elígelo");

    const entrada = w.get('input[type="file"]');
    const csv = new File(["a"], "agenda.csv", { type: "text/csv" });
    Object.defineProperty(entrada.element, "files", { value: [csv] });
    await entrada.trigger("change");
    expect(w.emitted("archivo")).toEqual([[csv]]);

    await w.setProps({ cargado: "agenda.csv" });
    expect(w.text()).toContain("agenda.csv");
    expect(w.text()).toContain("Arrastra otro o haz clic para cambiarlo");
  });

  it("deshabilitada u ocupada no recibe nada", async () => {
    const w = montar({ deshabilitado: true });
    const zona = w.get('[data-prueba="zona-archivo"]');
    expect(zona.attributes("aria-disabled")).toBe("true");
    await zona.trigger("drop", soltar(new File(["a"], "a.csv")));
    await w.setProps({ deshabilitado: false, ocupado: true });
    await zona.trigger("drop", soltar(new File(["a"], "a.csv")));
    expect(w.emitted("archivo")).toBeUndefined();
    expect(zona.text()).toContain("Subiendo…");
  });
});

describe("archivos", () => {
  it("acepta por extensión, por tipo exacto y por familia", () => {
    const png = new File(["x"], "Yo.PNG", { type: "image/png" });
    expect(aceptaArchivo(png, "image/*")).toBe(true);
    expect(aceptaArchivo(png, "image/jpeg,image/png")).toBe(true);
    expect(aceptaArchivo(png, ".png")).toBe(true);
    expect(aceptaArchivo(png, ".csv,text/csv")).toBe(false);
    expect(aceptaArchivo(png, "")).toBe(true);
  });

  it("soltar un archivo fuera de una zona no lo abre en la pestaña", () => {
    const ventana = new EventTarget() as Window;
    instalarGuardaDeArrastre(ventana);
    const evento = new Event("drop", { cancelable: true }) as DragEvent;
    Object.defineProperty(evento, "dataTransfer", {
      value: { types: ["Files"], dropEffect: "copy" },
    });
    ventana.dispatchEvent(evento);
    expect(evento.defaultPrevented).toBe(true);
    expect(evento.dataTransfer?.dropEffect).toBe("none");
  });
});
