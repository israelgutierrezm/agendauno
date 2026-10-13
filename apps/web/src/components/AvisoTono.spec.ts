import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import AvisoTono from "@/components/AvisoTono.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import LogoPlataforma from "@/components/LogoPlataforma.vue";

describe("AvisoTono", () => {
  it("muestra el mensaje en su tono, con título y acciones", () => {
    const w = mount(AvisoTono, {
      props: { tono: "aviso", titulo: "Revisa el archivo" },
      slots: {
        default: "Hay 2 filas con errores.",
        acciones: '<button type="button">Ver filas</button>',
      },
    });

    const raiz = w.get('[data-prueba="aviso"]');
    expect(raiz.classes()).toEqual(
      expect.arrayContaining(["tu-alerta", "tu-tono-aviso"]),
    );
    expect(raiz.classes()).not.toContain("tu-alerta-simple");
    expect(w.get(".tu-alerta-titulo").text()).toBe("Revisa el archivo");
    expect(w.get(".tu-alerta-mensaje").text()).toBe("Hay 2 filas con errores.");
    expect(w.get("button").text()).toBe("Ver filas");
    // El icono es decorativo.
    expect(w.get("svg").attributes("aria-hidden")).toBe("true");
  });

  it("sin título es compacto, informativo por omisión y acepta role y clases", () => {
    const w = mount(AvisoTono, {
      attrs: { role: "alert", class: "mt-4", "data-prueba": "error-carga" },
      slots: { default: "No se pudo cargar." },
    });

    const raiz = w.get('[data-prueba="error-carga"]');
    expect(raiz.classes()).toEqual(
      expect.arrayContaining(["tu-tono-info", "tu-alerta-simple", "mt-4"]),
    );
    expect(raiz.attributes("role")).toBe("alert");
    expect(raiz.text()).toBe("No se pudo cargar.");
    expect(w.find(".tu-alerta-titulo").exists()).toBe(false);
  });
});

describe("InterruptorSiNo", () => {
  it("se enciende y se apaga con un clic y lo anuncia como interruptor", async () => {
    const w = mount(InterruptorSiNo, {
      props: {
        modelValue: false,
        "onUpdate:modelValue": (v: boolean) => w.setProps({ modelValue: v }),
        etiqueta: "Activa",
        prueba: "activa-wellhub",
      },
    });

    const boton = w.get('[data-prueba="activa-wellhub"]');
    expect(boton.attributes("role")).toBe("switch");
    expect(boton.attributes("aria-checked")).toBe("false");
    expect(boton.text()).toBe("Activa");

    await boton.trigger("click");
    expect(w.emitted("update:modelValue")?.[0]).toEqual([true]);
    expect(boton.attributes("aria-checked")).toBe("true");
  });

  it("deshabilitado no cambia", async () => {
    const w = mount(InterruptorSiNo, {
      props: { modelValue: true, etiqueta: "Activa", deshabilitado: true },
    });

    expect(w.get("button").attributes("disabled")).toBeDefined();
    await w.get("button").trigger("click");
    expect(w.emitted("update:modelValue")).toBeUndefined();
  });
});

describe("LogoPlataforma", () => {
  it("dibuja el distintivo de cada plataforma a su tamaño", () => {
    const wellhub = mount(LogoPlataforma, {
      props: { proveedor: "wellhub", tam: 40 },
    });
    expect(wellhub.classes()).toContain("logo-wellhub");
    expect(wellhub.attributes("style")).toContain("width: 40px");
    expect(wellhub.findAll("ellipse")).toHaveLength(6);

    const totalpass = mount(LogoPlataforma, {
      props: { proveedor: "totalpass" },
    });
    expect(totalpass.classes()).toContain("logo-totalpass");
    expect(totalpass.text()).toBe("TP");
    expect(totalpass.attributes("aria-hidden")).toBe("true");
  });
});
