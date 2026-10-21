import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import kiosco from "@/i18n/locales/kiosco.es-MX";
import KioscoView from "./KioscoView.vue";

/*
| Kiosco de llegada (ADR 0183): se activa con el enlace del panel (la llave en el
| fragmento), registra la llegada de quien muestra su pase y saluda solo con su
| primer nombre. Una tableta dada de baja vuelve a pedir que la activen.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "No autorizado" }));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "demo" } }),
}));

function montar() {
  return mount(KioscoView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, kiosco } },
        }),
      ],
      stubs: { EscanerPase: true, HechoCon: true },
    },
  });
}

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
  localStorage.clear();
  history.replaceState(null, "", "/estudio/demo/kiosco");
});

describe("kiosco de llegada", () => {
  it("se activa con el enlace y registra la llegada de quien muestra su pase", async () => {
    history.replaceState(null, "", "/estudio/demo/kiosco#llave=kio_abc");
    api.get.mockResolvedValueOnce({
      data: { data: { negocio: "Fluō", sucursal: "Roma" } },
    });
    api.post.mockResolvedValueOnce({
      data: {
        data: {
          resultado: "llegada",
          nombre: "Ana Sofía",
          clase: { nombre: "Pole Nivel 1", hora: "18:00", lugar: 4 },
          mensaje: "Registramos tu llegada.",
        },
      },
    });
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/kiosco", {
      headers: { "X-Kiosco": "kio_abc" },
    });
    // La llave se queda en la tableta y sale de la dirección.
    expect(localStorage.getItem("kiosco:demo")).toBe("kio_abc");
    expect(window.location.hash).toBe("");
    expect(w.text()).toContain("Fluō · Roma");

    await w.get('[data-prueba="kiosco-codigo"]').setValue("au.01H.9.firma");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/kiosco/llegadas",
      { codigo: "au.01H.9.firma" },
      { headers: { "X-Kiosco": "kio_abc" } },
    );
    const resultado = w.get('[data-prueba="kiosco-resultado"]').text();
    expect(resultado).toContain("¡Hola, Ana!");
    expect(resultado).toContain("Pole Nivel 1 · 18:00 · lugar 4");
  });

  it("dada de baja, olvida la llave y pide activarla", async () => {
    localStorage.setItem("kiosco:demo", "kio_vieja");
    api.get.mockRejectedValueOnce(new Error("401"));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Esta tableta no está activada");
    expect(localStorage.getItem("kiosco:demo")).toBeNull();
  });
});
