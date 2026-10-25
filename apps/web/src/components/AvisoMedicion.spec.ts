import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { i18n } from "@/i18n";
import AvisoMedicion from "./AvisoMedicion.vue";

/*
| La barra de cookies de las páginas públicas (ADR 0143 y 0206), como la de Fitune: al
| pie; en las del producto, al entrar, y en la de un negocio, solo si mide. Un solo
| botón, «Aceptar»; en «Cookies y configuración» se elige o se rechaza cada cosa, y el
| mismo enlace del pie de página la vuelve a abrir con lo elegido.
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
    // La misma regla que lib/etiquetas.ts.
    pideConsentimiento: () =>
      !medicion.pausada &&
      medicion.ambito !== null &&
      (medicion.ambito.startsWith("plataforma:") ||
        (medicion.destinos !== null &&
          (medicion.destinos.ga4 !== null || medicion.destinos.meta !== null))),
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

describe("barra de cookies", () => {
  it("avisa al entrar qué se usa y para qué, con un solo botón: «Aceptar»", async () => {
    const w = montar();
    const barra = w.get('[data-prueba="aviso-medicion"]');
    expect(barra.text()).toContain("Esta página web usa cookies");
    expect(barra.text()).toContain("obtienen datos de tu navegación");
    expect(w.get('[data-prueba="am-elegir"]').text()).toBe(
      "Cookies y configuración",
    );
    expect(w.findAll("button.tu-btn").map((b) => b.text())).toEqual([
      "Aceptar",
    ]);

    await w.get('[data-prueba="am-aceptar"]').trigger("click");
    expect(etiquetas.elegir).toHaveBeenLastCalledWith({
      analitica: true,
      publicidad: true,
    });
  });

  it("en «Cookies y configuración» se elige cada cosa o se rechazan todas, con su política", async () => {
    const w = montar();
    await w.get('[data-prueba="am-elegir"]').trigger("click");
    expect(w.get('[data-prueba="am-politica"]').text()).toBe(
      "Política de cookies",
    );
    // Sin marcar nada: solo las necesarias.
    await w.get('[data-prueba="am-guardar"]').trigger("click");
    expect(etiquetas.elegir).toHaveBeenLastCalledWith({
      analitica: false,
      publicidad: false,
    });

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
    etiquetas.medicion.pausada = true;
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
  });

  it("ya elegido no estorba; el enlace del pie la vuelve a abrir con lo elegido", async () => {
    etiquetas.medicion.preferencias = { analitica: true, publicidad: false };
    const w = montar();
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);

    etiquetas.medicion.eligiendo = true;
    await nextTick();
    expect(
      (w.get('[data-prueba="am-analitica"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
    expect(
      (w.get('[data-prueba="am-publicidad"]').element as HTMLInputElement)
        .checked,
    ).toBe(false);
    await w.get('[data-prueba="am-cerrar"]').trigger("click");
    expect(w.find('[data-prueba="aviso-medicion"]').exists()).toBe(false);
  });
});
