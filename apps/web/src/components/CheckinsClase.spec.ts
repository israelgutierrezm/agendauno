import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CheckinsClase from "./CheckinsClase.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: { response?: { data?: { message?: string } } }) =>
    e.response?.data?.message ?? "error",
}));
const permisos = vi.hoisted(() => ({ excepcion: true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: (p: string) => p !== "checkins.excepcion" || permisos.excepcion,
  }),
}));

const base = {
  proveedor: "wellhub",
  registrado_en: "2026-10-01T13:00:00Z",
  registrado_por: "Recepción",
  motivo: null,
  autorizado_por: null,
  motivo_excepcion: null,
  evidencia: null,
};
const validada = {
  ...base,
  id: "c1",
  usuario: "1000000000001",
  estado: "validado",
};
const sinVerificar = {
  ...base,
  id: "c2",
  usuario: "1000000000002",
  estado: "no_verificable",
  motivo: "No se pudo contactar a Wellhub.",
};

function montar() {
  return mount(CheckinsClase, {
    props: { sesionId: "s1" },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.excepcion = true;
});

describe("check-ins de bienestar: aprobada, sin verificar o excepción (M10)", () => {
  it("si la plataforma no responde no la da por validada y se autoriza como excepción con lo que se comprobó", async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: [validada] } })
      .mockResolvedValue({ data: { data: [sinVerificar, validada] } });
    api.post.mockRejectedValueOnce(
      Object.assign(new Error("409"), {
        isAxiosError: true,
        response: {
          status: 409,
          data: {
            code: "CHECKIN_UNVERIFIABLE",
            message: "No se pudo contactar a Wellhub.",
          },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="estado-checkin"]').text()).toBe(
      "Validada por Wellhub",
    );

    await w.get("#cc").setValue("1000000000002");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="checkin-sin-verificar"]').text()).toContain(
      "No cuenta como validada",
    );
    const estados = w.findAll('[data-prueba="estado-checkin"]');
    expect(estados[0]!.text()).toBe("Sin verificar");

    api.post.mockResolvedValueOnce({
      data: {
        data: {
          ...sinVerificar,
          estado: "excepcion",
          autorizado_por: "Ana",
          motivo_excepcion: "Wellhub caído",
          evidencia: "Vi su check-in activo en la app",
        },
      },
    });
    await w
      .findAll("button")
      .find((b) => b.text() === "Registrar como excepción")!
      .trigger("click");
    await w.get("#exm-c2").setValue("Wellhub caído");
    await w.get("#exe-c2").setValue("Vi su check-in activo en la app");
    await w.get('[data-prueba="form-excepcion"]').trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/checkins/c2/excepcion",
      { motivo: "Wellhub caído", evidencia: "Vi su check-in activo en la app" },
    );
    expect(w.findAll('[data-prueba="estado-checkin"]')[0]!.text()).toBe(
      "Excepción · autorizó Ana",
    );
  });

  it("sin el permiso no ofrece la excepción", async () => {
    permisos.excepcion = false;
    api.get.mockResolvedValue({ data: { data: [sinVerificar] } });
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="estado-checkin"]').text()).toBe(
      "Sin verificar",
    );
    expect(
      w.findAll("button").some((b) => b.text() === "Registrar como excepción"),
    ).toBe(false);
  });
});
