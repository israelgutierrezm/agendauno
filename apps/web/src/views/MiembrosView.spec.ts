import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import MiembrosView from "./MiembrosView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "estudio-a",
    puede: () => true,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
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
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: esMX },
        }),
      ],
      stubs: {
        teleport: true,
        BarraListado: true,
        BotonImportar: true,
        PaginacionListado: true,
        PanelMiembro: true,
        PanelEditarMiembro: true,
      },
    },
  });
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
    localStorage.clear();
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

  it("en la lista, el contacto bajo el nombre y el estado con su punto", async () => {
    const w = montar();
    await flushPromises();

    const fila = w.get("tbody tr");
    expect(fila.text()).toContain("Vale Ruiz");
    expect(fila.text()).toContain("vale@correo.mx");
    expect(fila.find(".mb-etiqueta-nuevo").exists()).toBe(true);
    expect(fila.find(".mb-punto").exists()).toBe(true);
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
});
