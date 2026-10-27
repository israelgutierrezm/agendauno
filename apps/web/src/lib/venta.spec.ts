import { beforeEach, describe, expect, it, vi } from "vitest";

import { crearVenta, derechoVendido } from "./venta";

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));
// La sucursal de la barra (ADR 0175): se cobra en su caja.
const sede = vi.hoisted(() => ({
  fija: { value: null as string | null },
  varias: { value: false },
}));
vi.mock("@/lib/sucursalOperativa", () => ({ useSucursales: () => sede }));

const orden = {
  comprador_id: "p1",
  items: [{ producto_id: "pack", cantidad: 1 }],
};

type Llamada = [string, { liquidar: { clave: string } }];
function claves(): string[] {
  return (api.post.mock.calls as Llamada[]).map(
    ([, cuerpo]) => cuerpo.liquidar.clave,
  );
}

describe("vender", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sede.fija.value = null;
    sede.varias.value = false;
  });

  it("con varias sucursales y «Todas» en la barra, dice que falta elegir la caja", () => {
    expect(crearVenta().sinSucursal.value).toBe(false);
    sede.varias.value = true;
    expect(crearVenta().sinSucursal.value).toBe(true);
    sede.fija.value = "S2";
    expect(crearVenta().sinSucursal.value).toBe(false);
  });

  it("vende y cobra de una vez, en la caja de la sucursal de la barra", async () => {
    sede.fija.value = "S1";
    api.post.mockResolvedValue({ data: { data: { id: "o1" } } });
    expect(await crearVenta().vender("/b", orden, "efectivo")).toBe("o1");
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenLastCalledWith("/b/ordenes", {
      ...orden,
      liquidar: {
        metodo: "efectivo",
        clave: expect.any(String),
        sucursal_id: "S1",
      },
    });
  });

  it("si falla, reintentar la misma venta lleva la misma clave (no cobra dos veces)", async () => {
    const venta = crearVenta();
    api.post.mockRejectedValueOnce(new Error("Sin red"));
    await expect(venta.vender("/b", orden, "efectivo")).rejects.toThrow();
    api.post.mockResolvedValue({ data: { data: { id: "o1" } } });
    expect(await venta.vender("/b", orden, "efectivo")).toBe("o1");
    const [primera, segunda] = claves();
    expect(primera).toBe(segunda);

    // Hecha: la siguiente venta es otra (otra clave).
    await venta.vender("/b", orden, "efectivo");
    expect(claves()[2]).not.toBe(primera);
  });

  it("otra venta (otro producto, persona o forma) lleva otra clave", async () => {
    const venta = crearVenta();
    api.post.mockRejectedValueOnce(new Error("Sin red"));
    await expect(venta.vender("/b", orden, "efectivo")).rejects.toThrow();
    api.post.mockResolvedValue({ data: { data: { id: "o3" } } });
    await venta.vender("/b", { ...orden, comprador_id: "p2" }, "efectivo");
    const [primera, segunda] = claves();
    expect(primera).not.toBe(segunda);
  });
});

describe("el derecho que dejó la venta", () => {
  const d = (id: string, disponible: number, saldo = disponible) => ({
    id,
    ilimitado: false,
    saldo,
    disponible,
  });

  it("es el nuevo, aunque no sea el primero de la lista", () => {
    const antes = [d("viejo", 3000, 5000)];
    const despues = [d("viejo", 3000, 5000), d("nuevo", 2500)];
    expect(derechoVendido(antes, despues)?.id).toBe("nuevo");
  });

  it("si se sumó a uno que ya tenía (clases extra), el que cambió", () => {
    const antes = [d("a", 1000), d("b", 4000)];
    const despues = [d("a", 1000), d("b", 6000)];
    expect(derechoVendido(antes, despues)?.id).toBe("b");
  });

  it("sin cambios, ninguno", () => {
    expect(derechoVendido([d("a", 1000)], [d("a", 1000)])).toBeNull();
  });
});
