import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";

import { useConfirmacionPago } from "./retornoPago";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
const router = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => ruta,
  useRouter: () => router,
}));

function montar(alConfirmar = vi.fn()) {
  let estado: ReturnType<typeof useConfirmacionPago> | undefined;
  const w = mount(
    defineComponent({
      setup() {
        estado = useConfirmacionPago(
          (ref) => `/pagos/${ref}/confirmacion`,
          alConfirmar,
        );
        return () => h("div");
      },
    }),
  );
  return { w, estado: estado!, alConfirmar };
}

describe("al volver de la pasarela", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("dice «confirmando» hasta que el servidor confirma, y entonces avisa", async () => {
    ruta.query = { pago: "exito", ref_pago: "P1", seccion: "x" };
    api.get
      .mockResolvedValueOnce({ data: { data: { estado: "en_proceso" } } })
      .mockResolvedValueOnce({ data: { data: { estado: "confirmado" } } });
    const { estado, alConfirmar } = montar();
    // Se quita de la URL para que recargar no repita el aviso.
    expect(router.replace).toHaveBeenCalledWith({ query: { seccion: "x" } });
    expect(estado.value).toBe("confirmando");
    await vi.advanceTimersByTimeAsync(0);
    expect(estado.value).toBe("confirmando");
    expect(alConfirmar).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(3000);
    expect(api.get).toHaveBeenLastCalledWith("/pagos/P1/confirmacion");
    expect(estado.value).toBe("confirmado");
    expect(alConfirmar).toHaveBeenCalledOnce();
  });

  it("sin referencia no se afirma nada: queda en proceso, sin preguntar", async () => {
    ruta.query = { pago: "exito" };
    const { estado } = montar();
    await vi.advanceTimersByTimeAsync(0);
    expect(estado.value).toBe("en_proceso");
    expect(api.get).not.toHaveBeenCalled();
  });

  it("si la pasarela lo rechaza, no se completó; si no confirma en un minuto, sigue en proceso", async () => {
    ruta.query = { pago: "exito", ref_pago: "P2" };
    api.get.mockResolvedValue({ data: { data: { estado: "no_completado" } } });
    const rechazado = montar();
    await vi.advanceTimersByTimeAsync(0);
    expect(rechazado.estado.value).toBe("cancelado");
    rechazado.w.unmount();

    api.get.mockResolvedValue({ data: { data: { estado: "en_proceso" } } });
    const lento = montar();
    await vi.advanceTimersByTimeAsync(60000);
    expect(lento.estado.value).toBe("en_proceso");
    expect(lento.alConfirmar).not.toHaveBeenCalled();
  });

  it("cancelado no pregunta y fuera del regreso no hay aviso", async () => {
    ruta.query = { pago: "cancelado", ref_pago: "P3" };
    expect(montar().estado.value).toBe("cancelado");
    ruta.query = {};
    expect(montar().estado.value).toBeNull();
    await vi.advanceTimersByTimeAsync(0);
    expect(api.get).not.toHaveBeenCalled();
  });
});
