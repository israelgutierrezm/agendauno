import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PromocionesView from "./PromocionesView.vue";

/*
| Promociones con el patrón de los listados: indicadores, búsqueda por código y
| filtro de vigentes; el estado con punto.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: {
    response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
  }) =>
    Object.fromEntries(
      Object.entries(e.response?.data?.meta?.errors ?? {}).map(([c, m]) => [
        c,
        m[0]!,
      ]),
    ),
}));
const confirmar = vi.hoisted(() => vi.fn(async () => false));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const promo = (id: string, codigo: string, vigente: boolean, usos: number) => ({
  id,
  codigo,
  descripcion: null,
  tipo: "porcentaje",
  valor: 1500,
  monto_minimo_minor: null,
  usos_maximos: null,
  usos,
  vence_en: null,
  activa: vigente,
  vigente,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockResolvedValue({
    data: {
      data: [promo("1", "VERANO", true, 4), promo("2", "BUENFIN", false, 9)],
    },
  });
});

describe("promociones", () => {
  it("suma los usos y filtra por código y por vigencia", async () => {
    const w = mount(PromocionesView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain("Veces usadas");
    expect(w.text()).toContain("13");
    expect(w.findAll('[data-prueba="promo"]')).toHaveLength(2);

    await w.get('[data-prueba="buscar-promo"]').setValue("ver");
    expect(w.findAll('[data-prueba="promo"]')).toHaveLength(1);

    await w.get('[data-prueba="buscar-promo"]').setValue("");
    await w
      .findAll("button")
      .find((b) => b.text() === "No vigentes")!
      .trigger("click");
    const filas = w.findAll('[data-prueba="promo"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("BUENFIN");
  });

  it("van por páginas, con las vigentes primero", async () => {
    // 25 vencidas de otros años y una vigente creada al final.
    mocks.get.mockResolvedValue({
      data: {
        data: [
          ...Array.from({ length: 25 }, (_, i) =>
            promo(`v${i}`, `VIEJA${i}`, false, 1),
          ),
          promo("n", "OTONO", true, 0),
        ],
      },
    });
    const w = mount(PromocionesView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    const filas = w.findAll('[data-prueba="promo"]');
    expect(filas).toHaveLength(20);
    expect(filas[0]!.text()).toContain("OTONO");
    expect(w.text()).toContain("1–20 de 26");
  });

  it("el error va junto a su campo, lo capturado se queda y no se descarta sin preguntar", async () => {
    mocks.post.mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          meta: {
            errors: { codigo: ["Ya existe una promoción con ese código."] },
          },
        },
      },
    });
    const w = mount(PromocionesView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    await w
      .findAll("button")
      .find((b) => b.text().includes("Nueva promoción"))!
      .trigger("click");
    await w.get("#p-codigo").setValue("verano");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();

    expect(w.get("#p-codigo-error").text()).toBe(
      "Ya existe una promoción con ese código.",
    );
    expect(w.get("#p-codigo").attributes("aria-invalid")).toBe("true");
    expect((w.get("#p-codigo").element as HTMLInputElement).value).toBe(
      "verano",
    );
    expect(w.text()).toContain("Revisa los campos marcados");

    // Con cambios, «Cancelar» pregunta; sin confirmar, sigue abierto.
    await w
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(w.find("#p-codigo").exists()).toBe(true);
  });
});
