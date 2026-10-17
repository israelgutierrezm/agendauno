import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, ref } from "vue";

import { i18n } from "@/i18n";
import { useContenedorEditor, useEditorFormulario } from "./editorFormulario";

/*
| El patrón común de los formularios: no se cierra mientras guarda, pregunta antes de
| descartar cambios, deja lo capturado si falla y confirma lo guardado.
*/

const confirmar = vi.hoisted(() => vi.fn(async () => false));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("vue-router", () => ({ onBeforeRouteLeave: vi.fn() }));

function montar<T>(usar: () => T): T {
  let api!: T;
  mount(
    defineComponent({
      setup() {
        api = usar();
        return () => h("div");
      },
    }),
    { global: { plugins: [i18n] } },
  );
  return api;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("editor de formulario", () => {
  it("con cambios pregunta antes de cerrar; sin cambios cierra", async () => {
    const abierto = ref(true);
    const form = ref({ nombre: "Ana" });
    const ed = montar(() =>
      useEditorFormulario({ abierto, valor: () => form.value }),
    );
    ed.empezar();

    form.value.nombre = "Ana María";
    expect(await ed.cerrar()).toBe(false);
    expect(confirmar).toHaveBeenCalledOnce();
    expect(abierto.value).toBe(true);

    form.value.nombre = "Ana";
    expect(await ed.cerrar()).toBe(true);
    expect(abierto.value).toBe(false);
  });

  it("si falla, marca el campo y deja lo capturado; si sale, confirma lo guardado", async () => {
    const abierto = ref(true);
    const form = ref({ codigo: "VERANO" });
    const ed = montar(() =>
      useEditorFormulario({
        abierto,
        valor: () => form.value,
        campos: ["codigo"],
      }),
    );
    ed.empezar();
    form.value.codigo = "OTOÑO";

    const fallo = await ed.guardar(() =>
      Promise.reject({
        isAxiosError: true,
        response: {
          status: 422,
          data: { meta: { errors: { codigo: ["Ya existe."] } } },
        },
      }),
    );
    expect(fallo).toBeUndefined();
    expect(ed.errores.value).toEqual({ codigo: "Ya existe." });
    expect(ed.error.value).toBe("Revisa los campos marcados.");
    expect(ed.marcar("codigo", "c-error")).toEqual({
      "aria-invalid": "true",
      "aria-describedby": "c-error",
    });
    expect(form.value.codigo).toBe("OTOÑO");

    await ed.guardar(() => Promise.resolve({}), "Promoción OTOÑO guardada.");
    expect(toast.exito).toHaveBeenCalledWith("Promoción OTOÑO guardada.");
    // Ya guardado, cerrar no pregunta.
    expect(await ed.cerrar()).toBe(true);
    expect(confirmar).not.toHaveBeenCalled();
  });

  it("el contenedor no se cierra mientras su editor guarda", async () => {
    const abierto = ref(true);
    const c = montar(() => useContenedorEditor(abierto));

    c.alEstado({ guardando: true, sucio: true });
    expect(c.ocupado.value).toBe(true);
    await c.cerrar();
    expect(abierto.value).toBe(true);
    expect(confirmar).not.toHaveBeenCalled();

    c.alEstado({ guardando: false, sucio: true });
    await c.cerrar();
    expect(confirmar).toHaveBeenCalledOnce();
    expect(abierto.value).toBe(true);

    c.cerrado();
    expect(abierto.value).toBe(false);
  });
});
