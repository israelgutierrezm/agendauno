import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import LayoutPlataforma from "./LayoutPlataforma.vue";

/*
| El armazón del superadmin (ADR 0203): cambiar de sección no cambia de ruta, así que
| el aviso de cambios sin guardar lo pide el propio menú (y «Cerrar sesión»).
*/

const confirmarSinGuardar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/cambiosPendientes", () => ({ confirmarSinGuardar }));

const grupos = [
  {
    clave: "dinero",
    texto: "Dinero",
    secciones: [
      { clave: "cobros", texto: "Cobros", icono: "dinero" },
      { clave: "tarifas", texto: "Tarifas", icono: "etiqueta" },
    ],
  },
];

function montar() {
  return mount(LayoutPlataforma, {
    props: { grupos, operador: "Ana", modelValue: "tarifas" },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("LayoutPlataforma", () => {
  it("con cambios sin guardar, cambiar de sección pregunta y respeta la respuesta", async () => {
    confirmarSinGuardar.mockResolvedValue(false);
    const w = montar();
    await w.get('[data-pestana="cobros"]').trigger("click");
    await flushPromises();
    expect(confirmarSinGuardar).toHaveBeenCalledOnce();
    expect(w.emitted("update:modelValue")).toBeUndefined();

    confirmarSinGuardar.mockResolvedValue(true);
    await w.get('[data-pestana="cobros"]').trigger("click");
    await flushPromises();
    expect(w.emitted("update:modelValue")?.[0]).toEqual(["cobros"]);
  });

  it("la sección abierta no pregunta", async () => {
    const w = montar();
    await w.get('[data-pestana="tarifas"]').trigger("click");
    await flushPromises();
    expect(confirmarSinGuardar).not.toHaveBeenCalled();
  });

  it("«Cerrar sesión» también pregunta antes de salir", async () => {
    confirmarSinGuardar.mockResolvedValue(false);
    const w = montar();
    await w.get('[data-prueba="salir-plataforma"]').trigger("click");
    await flushPromises();
    expect(w.emitted("salir")).toBeUndefined();

    confirmarSinGuardar.mockResolvedValue(true);
    await w.get('[data-prueba="salir-plataforma"]').trigger("click");
    await flushPromises();
    expect(w.emitted("salir")).toHaveLength(1);
  });
});
