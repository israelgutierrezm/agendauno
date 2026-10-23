import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";

import { i18n } from "@/i18n";
import PlanesView from "./PlanesView.vue";

/*
| Planes con el patrón de los listados: en el teléfono, una ficha por plan con
| «Editar» a la vista; el editor guarda desde el pie del diálogo (form="form-plan").
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), aviso: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    pais: "MX",
    puede: () => true,
  }),
}));

// El editor tiene sus propias pruebas: aquí solo avisa que aún no se puede guardar.
const EditorFalso = defineComponent({
  props: { plan: { type: Object, default: null } },
  emits: ["estado", "guardado"],
  setup(_, { emit }) {
    emit("estado", { guardando: false, sucio: false, valido: false });
    return () => h("form", { id: "form-plan", "data-prueba": "editor-falso" });
  },
});

const paquete = {
  id: "p1",
  nombre: "Paquete 8 clases",
  tipo: "paquete",
  precio_minor: 89900,
  moneda: "MXN",
  ilimitado: false,
  creditos_incluidos: 8000,
  vigencia_tipo: "meses",
  vigencia_cantidad: 1,
  politica_reset: "ninguno",
  unidades_por_ciclo: null,
  politica_rollover: "ninguno",
  rollover_max: null,
  sucursal_id: null,
  archivado: false,
  ofertas: [],
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: { data: url.endsWith("/coherencia") ? [] : [paquete] },
    }),
  );
});

function montar() {
  return mount(PlanesView, {
    global: {
      plugins: [i18n],
      stubs: { teleport: true, EditorPlan: EditorFalso },
    },
  });
}

describe("planes y paquetes", () => {
  it("en el teléfono, una ficha por plan con «Editar» como botón a la vista", async () => {
    const w = montar();
    await flushPromises();

    expect(w.find(".tu-tabla-adaptable table.tu-tabla").exists()).toBe(true);
    // Cada dato lleva su columna (la ficha la muestra); el precio, segundo.
    expect(
      w
        .get('[data-prueba="plan"]')
        .findAll("td[data-etiqueta]")
        .map((td) => td.attributes("data-etiqueta")),
    ).toEqual([
      "Plan",
      "Precio",
      "Incluye",
      "Vigencia y renovación",
      "Válido para",
      "Estado",
    ]);
    const editar = w.get('[data-prueba="editar-plan"]');
    expect(editar.classes()).toEqual(
      expect.arrayContaining(["tu-btn", "tu-btn-secundario", "tu-btn-chico"]),
    );
    expect(editar.element.closest("td")?.className).toContain(
      "tu-celda-acciones",
    );
  });

  it("el editor guarda desde el pie del diálogo, desactivado mientras falte algo", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="editar-plan"]').trigger("click");

    expect(w.find('[data-prueba="editor-falso"]').exists()).toBe(true);
    const guardar = w.get('[data-prueba="guardar-plan"]');
    expect(guardar.attributes("type")).toBe("submit");
    expect(guardar.attributes("form")).toBe("form-plan");
    expect((guardar.element as HTMLButtonElement).disabled).toBe(true);
  });
});
