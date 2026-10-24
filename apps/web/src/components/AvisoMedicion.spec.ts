import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { i18n } from "@/i18n";
import AvisoMedicion from "./AvisoMedicion.vue";

/*
| El aviso de cookies de las páginas públicas (ADR 0143 y 0206): en las del producto, al
| entrar; en la de un negocio, solo si mide. Mientras no se haya elegido: aceptar todas,
| solo las necesarias o configurar cada cosa; después, «Cookies» para cambiarlo.
*/

const etiquetas = vi.hoisted(() => ({
  medicion: {
    ambito: "plataforma:agendauno",
    destinos: { ga4: "G-X1234", meta: null } as {
      ga4: string | null;
      meta: string | null;
    } | null,
    preferencias: null as { analitica: boolean; publicidad: boolean } | null,
    pausada: false,
    eligiendo: false,
  },
  elegir: vi.fn(),
}));
vi.mock("@/lib/etiquetas", async () => {
  const { reactive } = await import("vue");
  const medicion = reactive(etiquetas.medicion);
  etiquetas.medicion = medicion;
  return {
    medicion,
    elegir: etiquetas.elegir,
    hayDestinos: (d: { ga4: string | null; meta: string | null } | null) =>
      d !== null && (d.ga4 !== null || d.meta !== null),
  };
});

function montar() {
  return mount(AvisoMedicion, {
    global: {
      plugins: [i18n],
      stubs: { RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  Object.assign(etiquetas.medicion, {
    ambito: "plataforma:agendauno",
    destinos: { ga4: "G-X1234", meta: null },
    preferencias: null,
    pausada: false,
    eligiendo: false,
  });
});

describe("aviso de medición", () => {
  it("pregunta al entrar: aceptar todas, solo las necesarias o configurar, con su política", async () => {
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(true);
    expect(w.get('[data-prueba="am-politica"]').text()).toBe(
      "Política de cookies",
    );
    expect(w.get('[data-prueba="am-rechazar"]').text()).toBe("Solo necesarias");

    await w.get('[data-prueba="am-rechazar"]').trigger("click");
    expect(etiquetas.elegir).toHaveBeenLastCalledWith({
      analitica: false,
      publicidad: false,
    });
    await w.get('[data-prueba="am-aceptar"]').trigger("click");
    expect(etiquetas.elegir).toHaveBeenLastCalledWith({
      analitica: true,
      publicidad: true,
    });

    await w.get('[data-prueba="am-elegir"]').trigger("click");
    await w.get('[data-prueba="am-analitica"]').setValue(true);
    await w.get('[data-prueba="am-guardar"]').trigger("click");
    expect(etiquetas.elegir).toHaveBeenLastCalledWith({
      analitica: true,
      publicidad: false,
    });
  });

  it("en las páginas del producto aparece aunque aún no mida; en un negocio, solo si mide", async () => {
    etiquetas.medicion.destinos = { ga4: null, meta: null };
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(true);

    etiquetas.medicion.ambito = "negocio:fluo";
    await nextTick();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
    etiquetas.medicion.destinos = { ga4: "G-X1234", meta: null };
    await nextTick();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(true);
  });

  it("en una página que no se mide, no aparece", async () => {
    const w = montar();

    etiquetas.medicion.destinos = { ga4: "G-X1234", meta: null };
    etiquetas.medicion.pausada = true;
    await nextTick();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
    expect(w.find('[data-prueba="am-privacidad"]').exists()).toBe(false);
  });

  it("ya elegido, queda «Cookies» para cambiarlo con lo que había elegido", async () => {
    etiquetas.medicion.preferencias = { analitica: true, publicidad: false };
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
    expect(w.get('[data-prueba="am-privacidad"]').text()).toBe("Cookies");

    await w.get('[data-prueba="am-privacidad"]').trigger("click");
    expect(
      (w.get('[data-prueba="am-analitica"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
    expect(
      (w.get('[data-prueba="am-publicidad"]').element as HTMLInputElement)
        .checked,
    ).toBe(false);
  });
});
