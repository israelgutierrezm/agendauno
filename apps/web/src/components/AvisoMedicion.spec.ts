import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { i18n } from "@/i18n";
import AvisoMedicion from "./AvisoMedicion.vue";

/*
| El aviso de privacidad de las páginas públicas (ADR 0143): solo donde hay destinos y
| mientras no se haya elegido; aceptar, solo lo necesario o elegir cada cosa; después,
| «Privacidad» para cambiarlo.
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
  it("pregunta solo donde hay destinos: aceptar, solo lo necesario o elegir", async () => {
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(true);

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

  it("sin destinos o en una página que no se mide, no aparece", async () => {
    etiquetas.medicion.destinos = { ga4: null, meta: null };
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);

    etiquetas.medicion.destinos = { ga4: "G-X1234", meta: null };
    etiquetas.medicion.pausada = true;
    await nextTick();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
    expect(w.find('[data-prueba="am-privacidad"]').exists()).toBe(false);
  });

  it("ya elegido, queda «Privacidad» para cambiarlo con lo que había elegido", async () => {
    etiquetas.medicion.preferencias = { analitica: true, publicidad: false };
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);

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
