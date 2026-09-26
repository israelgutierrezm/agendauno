import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { i18n } from "@/i18n";
import TablaDatos from "./TablaDatos.vue";
import BarraListado from "./BarraListado.vue";
import PaginacionListado from "./PaginacionListado.vue";
import PanelLateral from "./PanelLateral.vue";

const global = { plugins: [i18n] };
afterEach(() => {
  vi.restoreAllMocks();
});

describe("listados operativos", () => {
  it("busca nombres sin exigir acentos y permite recuperar el listado", async () => {
    const vista = mount(TablaDatos, {
      global,
      props: {
        columnas: [{ clave: "nombre", etiqueta: "Nombre" }],
        filas: [{ nombre: "Mónica Pérez" }, { nombre: "Andrea" }],
      },
    });
    await vista.get('input[type="search"]').setValue("monica");
    expect(vista.findAll("tbody tr")).toHaveLength(1);
    expect(vista.text()).toContain("Mónica Pérez");
    await vista.get('input[type="search"]').setValue("zzz");
    expect(vista.text()).toContain("No encontramos coincidencias");
    await vista.get('[role="status"] button').trigger("click");
    expect(vista.findAll("tbody tr")).toHaveLength(2);
    vista.unmount();
  });
  it("no ofrece limpiar filtros cuando no existen registros", () => {
    const vista = mount(TablaDatos, {
      global,
      props: {
        columnas: [{ clave: "nombre", etiqueta: "Nombre" }],
        filas: [],
        vacio: "Agrega tu primer cliente.",
      },
    });
    expect(vista.text()).toContain("Agrega tu primer cliente.");
    expect(vista.find('[role="status"] button').exists()).toBe(false);
    vista.unmount();
  });
  it("restablece filtros y búsqueda también en cuadrícula", async () => {
    const vista = mount(TablaDatos, {
      global,
      props: {
        claveVista: "prueba-interfaz",
        columnas: [{ clave: "nombre", etiqueta: "Nombre" }],
        filas: [{ nombre: "Andrea", activo: true }],
        filtros: [
          { clave: "inactivo", etiqueta: "Inactivos", tipo: "booleano" },
        ],
        filtrarFila: (_fila, valores) => !valores.inactivo,
      },
    });
    vista.findComponent(BarraListado).vm.$emit("update:vista", "cuadricula");
    await nextTick();
    vista.findComponent(BarraListado).vm.$emit("cambioFiltro", "inactivo", "1");
    await nextTick();
    await vista.get('input[type="search"]').setValue("And");
    await vista.get('[role="status"] button').trigger("click");
    expect(vista.text()).toContain("Andrea");
    expect(
      (vista.get('input[type="search"]').element as HTMLInputElement).value,
    ).toBe("");
    vista.unmount();
    localStorage.removeItem("tu.vista.prueba-interfaz");
  });
  it("identifica filtros abiertos y activa un filtro booleano con valor no", async () => {
    const vista = mount(BarraListado, {
      global,
      props: {
        filtros: [{ clave: "activo", etiqueta: "Activos", tipo: "booleano" }],
        valores: { activo: "no" },
      },
    });
    const boton = vista.get("button[aria-controls]");
    expect(boton.attributes("aria-expanded")).toBe("false");
    await boton.trigger("click");
    expect(boton.attributes("aria-expanded")).toBe("true");
    await vista.get('button[aria-pressed="false"]').trigger("click");
    expect(vista.emitted("cambioFiltro")?.[0]).toEqual(["activo", "1"]);
    vista.unmount();
  });
  it("muestra el total incluso cuando cabe en una página", () => {
    const vista = mount(PaginacionListado, {
      global,
      props: { page: 1, ultimaPagina: 1, total: 3, perPage: 10 },
    });
    expect(vista.text()).toContain("1–3 de 3");
    expect(vista.find("button").exists()).toBe(false);
    vista.unmount();
  });
  it("marca la página actual y emite navegación", async () => {
    const vista = mount(PaginacionListado, {
      global,
      props: { page: 2, ultimaPagina: 3, total: 25, perPage: 10 },
    });
    expect(vista.get('[aria-current="page"]').text()).toBe("2");
    await vista.get('button[aria-label="Siguiente"]').trigger("click");
    expect(vista.emitted("ir")?.[0]).toEqual([3]);
    vista.unmount();
  });
});

describe("paneles laterales", () => {
  it("enfoca al abrir, bloquea scroll y devuelve el foco al cerrar", async () => {
    const origen = document.createElement("button");
    document.body.append(origen);
    origen.focus();
    document.body.style.overflow = "auto";
    const vista = mount(PanelLateral, {
      global,
      props: { abierto: true, titulo: "Editar cliente" },
      attachTo: document.body,
    });
    await nextTick();
    expect(document.activeElement?.getAttribute("role")).toBe("dialog");
    expect(document.activeElement?.getAttribute("aria-label")).toBe(
      "Editar cliente",
    );
    expect(document.body.style.overflow).toBe("hidden");
    await vista.setProps({ abierto: false });
    expect(document.activeElement).toBe(origen);
    expect(document.body.style.overflow).toBe("auto");
    vista.unmount();
    origen.remove();
    document.body.style.overflow = "";
  });
  it("solo cierra el panel superior con Escape y conserva el bloqueo del inferior", async () => {
    const base = mount(PanelLateral, {
      global,
      props: { abierto: true, titulo: "Base" },
      attachTo: document.body,
    });
    await nextTick();
    const superior = mount(PanelLateral, {
      global,
      props: { abierto: true, titulo: "Superior" },
      attachTo: document.body,
    });
    await nextTick();
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    expect(superior.emitted("cerrar")).toHaveLength(1);
    expect(base.emitted("cerrar")).toBeUndefined();
    superior.unmount();
    expect(document.body.style.overflow).toBe("hidden");
    expect(document.activeElement?.getAttribute("aria-label")).toBe("Base");
    base.unmount();
    expect(document.body.style.overflow).toBe("");
  });
  it("mantiene Tab y Mayús+Tab dentro del panel", async () => {
    vi.spyOn(HTMLElement.prototype, "getClientRects").mockReturnValue([
      { width: 10, height: 10 },
    ] as unknown as DOMRectList);
    const vista = mount(PanelLateral, {
      global,
      props: { abierto: true },
      slots: { default: '<button id="ultimo-panel">Guardar</button>' },
      attachTo: document.body,
    });
    await nextTick();
    const primero = document.querySelector<HTMLButtonElement>(
      '[role="dialog"] button',
    )!;
    const ultimo = document.getElementById("ultimo-panel")!;
    primero.focus();
    window.dispatchEvent(
      new KeyboardEvent("keydown", {
        key: "Tab",
        shiftKey: true,
        cancelable: true,
      }),
    );
    expect(document.activeElement).toBe(ultimo);
    window.dispatchEvent(
      new KeyboardEvent("keydown", { key: "Tab", cancelable: true }),
    );
    expect(document.activeElement).toBe(primero);
    vista.unmount();
  });
});
