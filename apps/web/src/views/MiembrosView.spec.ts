import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MiembrosView from "./MiembrosView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  esCitas: false,
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "estudio-a",
    esCitas: mocks.esCitas,
    puede: () => true,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRoute: () => ({ query: {} }),
}));

const resumen = {
  total: 40,
  con_plan: 31,
  nuevos_mes: 6,
  por_vencer: 3,
  vencidas: 1,
  con_adeudo: 2,
  dias_por_vencer: 7,
};

function montar() {
  return mount(MiembrosView, {
    global: {
      plugins: [i18n],
      stubs: {
        teleport: true,
        BotonImportar: true,
        PaginacionListado: true,
        PanelMiembro: true,
        PanelEditarMiembro: true,
        ModalMiembro: true,
      },
    },
  });
}
// Los parámetros con que se pidió la lista la última vez.
function ultimaConsulta(): Record<string, unknown> {
  const llamada = mocks.get.mock.calls
    .filter(([url]) => String(url).endsWith("/miembros"))
    .at(-1);
  return (llamada?.[1] as { params: Record<string, unknown> }).params;
}
function encabezados(w: ReturnType<typeof montar>): string[] {
  return w.findAll("th").map((th) => th.text());
}

// Lo que la lista recibe de cada miembro (con cambios por prueba).
const miembroBase = {
  id: "m1",
  nombre: "Vale",
  segundo_nombre: null,
  primer_apellido: "Ruiz",
  segundo_apellido: null,
  nombre_completo: "Vale Ruiz",
  email: "vale@correo.mx",
  tipo: "miembro",
  activo: true,
  es_facturable: true,
  archivado: false,
  primera_vez: true,
};
let miembro: Record<string, unknown> = miembroBase;

describe("directorio de clientes", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    mocks.esCitas = false;
    miembro = miembroBase;
    mocks.post.mockResolvedValue({ data: {} });
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/miembros/resumen")
          ? { data: resumen }
          : url.endsWith("/miembros")
            ? {
                data: [miembro],
                meta: { total: 1, page: 1, per_page: 25, ultima_pagina: 1 },
              }
            : { data: [] },
      }),
    );
  });

  it("arriba, cuántos hay y lo que pide atención", async () => {
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="indicador-total"]').text()).toContain("40");
    expect(w.get('[data-prueba="indicador-conPlan"]').text()).toContain("31");
    expect(w.get('[data-prueba="indicador-nuevos"]').text()).toContain("6");
    // Por vencer, con las recién vencidas al lado, en color de aviso.
    const porVencer = w.get('[data-prueba="indicador-porVencer"]');
    expect(porVencer.text()).toContain("3 · 1 vencidas");
    expect(porVencer.get("dd > span").attributes("style")).toContain(
      "var(--aviso)",
    );
    expect(w.get('[data-prueba="indicador-adeudo"]').text()).toContain("2");
  });

  it("en la lista, el contacto bajo el nombre y el estado como píldora", async () => {
    const w = montar();
    await flushPromises();

    const fila = w.get("tbody tr");
    expect(fila.text()).toContain("Vale Ruiz");
    expect(fila.text()).toContain("vale@correo.mx");
    expect(fila.find(".mb-etiqueta-nuevo").exists()).toBe(true);
    const estado = fila.get('[data-prueba="estado-miembro"]');
    expect(estado.classes()).toContain("tu-pildora");
    expect(estado.text()).toBe("Activo");
    expect(estado.attributes("style")).toContain("var(--exito)");
    expect(encabezados(w)).toEqual([
      "Nombre",
      "App",
      "Plan vigente",
      "Saldo",
      "Estado",
      "Última visita",
      "Más acciones",
    ]);
  });

  it("tocar a alguien abre su detalle en un modal (sin vista previa al lado)", async () => {
    const w = montar();
    await flushPromises();
    const modal = w.getComponent({ name: "ModalMiembro" });
    expect(modal.props("abierto")).toBe(false);

    await w.get('[data-prueba="fila-miembro"]').trigger("click");
    expect(modal.props("abierto")).toBe(true);
    expect(modal.props("persona")).toMatchObject({ id: "m1" });
    expect(w.findComponent({ name: "PanelMiembro" }).exists()).toBe(false);
  });

  it("ordena por nombre (A–Z, Z–A y de vuelta a los más recientes)", async () => {
    const w = montar();
    await flushPromises();
    expect(ultimaConsulta().orden).toBeUndefined();

    const ordenar = w.get('[data-prueba="ordenar-nombre"]');
    await ordenar.trigger("click");
    await flushPromises();
    expect(ultimaConsulta().orden).toBe("nombre");
    expect(w.get("th").attributes("aria-sort")).toBe("ascending");

    await ordenar.trigger("click");
    await flushPromises();
    expect(ultimaConsulta().orden).toBe("-nombre");

    await ordenar.trigger("click");
    await flushPromises();
    expect(ultimaConsulta().orden).toBeUndefined();
  });

  it("elige qué columnas ver y lo recuerda", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="columnas"]').trigger("click");
    await w.get('input[data-columna="ultima"]').setValue(false);
    await w.get('input[data-columna="registro"]').setValue(true);

    expect(encabezados(w)).not.toContain("Última visita");
    expect(encabezados(w)).toContain("Registro");
    expect(
      JSON.parse(localStorage.getItem("tu.columnas.miembros") ?? "[]"),
    ).toEqual(["app", "plan", "saldo", "estado", "registro"]);
  });

  it("cuántos por página: vuelve a la primera página con ese tamaño", async () => {
    const w = montar();
    await flushPromises();
    w.getComponent({ name: "PaginacionListado" }).vm.$emit("porPagina", 50);
    await flushPromises();
    expect(ultimaConsulta()).toMatchObject({ page: 1, per_page: 50 });
  });

  it("exporta lo que se ve (búsqueda, filtros y orden) a CSV", async () => {
    const crear = vi.fn(() => "blob:clientes");
    URL.createObjectURL = crear;
    URL.revokeObjectURL = vi.fn();
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="ordenar-nombre"]').trigger("click");
    await flushPromises();

    await w.get('[data-prueba="exportar-miembros"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/miembros/exportar",
      expect.objectContaining({
        params: expect.objectContaining({ orden: "nombre", archivado: "no" }),
        responseType: "blob",
      }),
    );
    expect(crear).toHaveBeenCalled();
  });

  it("desde el «…» de la fila se le cobra directo", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="menu-fila"]').trigger("click");
    await w.get('[data-prueba="cobrar-fila"]').trigger("click");

    const venta = w.getComponent({ name: "PanelMiembro" });
    expect(venta.props("personaId")).toBe("m1");
    expect(venta.props("venta")).toBe(true);
  });

  it("con la invitación sin activar, se reenvía (no se vuelve a invitar)", async () => {
    miembro = { ...miembroBase, invitacion_pendiente: "u9" };
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="reenviar-invitacion"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/usuarios/u9/reenviar",
    );
  });

  it("con más de un plan vigente, muestra el principal y «+1» con los demás", async () => {
    miembro = {
      ...miembroBase,
      resumen: {
        membresia: {
          estado: "vigente",
          plan: "Pack 8 clases",
          planes: ["Pack 8 clases", "2 clases extra"],
          valido_hasta: null,
          pausada_hasta: null,
          ilimitado: false,
          saldo_unidades: 7000,
          tiene_acceso: true,
        },
        ultima_visita: null,
        proxima: null,
        adeudo: false,
      },
    };
    const w = montar();
    await flushPromises();

    const mas = w.get('[data-prueba="mas-planes"]');
    expect(mas.text()).toBe("+1");
    expect(mas.attributes("title")).toBeDefined();
  });
  it("en citas sin paquetes no muestra plan, créditos ni filtros de alumnos facturables", async () => {
    mocks.esCitas = true;
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/miembros/resumen")
          ? { data: { ...resumen, con_plan: 0, por_vencer: 0, vencidas: 0 } }
          : url.endsWith("/miembros")
            ? {
                data: [miembroBase],
                meta: { total: 1, page: 1, per_page: 20, ultima_pagina: 1 },
              }
            : { data: [] },
      }),
    );
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="indicador-conPlan"]').exists()).toBe(false);
    expect(w.find('[data-prueba="indicador-porVencer"]').exists()).toBe(false);
    expect(encabezados(w)).not.toContain("Plan vigente");
    expect(encabezados(w)).not.toContain("Saldo");
    expect(
      w
        .getComponent({ name: "BarraListado" })
        .props("filtros")
        .some((f: { clave: string }) => f.clave === "facturable"),
    ).toBe(false);
  });
  it("un error de carga no se presenta como un directorio vacío", async () => {
    mocks.get.mockRejectedValue(new Error("red"));
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Error");
    expect(w.findComponent({ name: "EstadoVacio" }).exists()).toBe(false);
    expect(w.findAll("button").some((b) => b.text() === "Reintentar")).toBe(
      true,
    );
  });
});
