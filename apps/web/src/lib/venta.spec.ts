import { beforeEach, describe, expect, it, vi } from "vitest";

import { crearVenta } from "./venta";

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));

const orden = {
  comprador_id: "p1",
  items: [{ producto_id: "pack", cantidad: 1 }],
};

describe("vender", () => {
  beforeEach(() => vi.clearAllMocks());

  it("si el cobro falla, reintentar cobra la misma orden y no crea otra", async () => {
    const venta = crearVenta();
    api.post.mockImplementation((url: string) =>
      url.endsWith("/ordenes")
        ? Promise.resolve({ data: { data: { id: "o1" } } })
        : Promise.reject(new Error("Sin red")),
    );
    await expect(venta.vender("/b", orden, "efectivo")).rejects.toThrow();

    api.post.mockImplementation((url: string) =>
      url.endsWith("/ordenes")
        ? Promise.resolve({ data: { data: { id: "o2" } } })
        : Promise.resolve({ data: {} }),
    );
    expect(await venta.vender("/b", orden, "tarjeta")).toBe("o1");
    expect(
      api.post.mock.calls.filter(([url]) => url === "/b/ordenes"),
    ).toHaveLength(1);
    expect(api.post).toHaveBeenLastCalledWith("/b/ordenes/o1/liquidar", {
      metodo: "tarjeta",
    });

    // Cobrada: la siguiente venta es otra orden.
    expect(await venta.vender("/b", orden, "efectivo")).toBe("o2");
  });

  it("otra venta (otro producto o persona) no reusa la orden pendiente", async () => {
    const venta = crearVenta();
    api.post.mockImplementation((url: string) =>
      url.endsWith("/ordenes")
        ? Promise.resolve({ data: { data: { id: "o1" } } })
        : Promise.reject(new Error("Sin red")),
    );
    await expect(venta.vender("/b", orden, "efectivo")).rejects.toThrow();
    api.post.mockImplementation((url: string) =>
      url.endsWith("/ordenes")
        ? Promise.resolve({ data: { data: { id: "o3" } } })
        : Promise.resolve({ data: {} }),
    );
    expect(
      await venta.vender("/b", { ...orden, comprador_id: "p2" }, "efectivo"),
    ).toBe("o3");
  });
});
