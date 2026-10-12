import { afterEach, describe, expect, it, vi } from "vitest";

import { llevarALaVista } from "./pantalla";

/*
| Llevar a la vista el detalle que se abre junto a una lista (Recepción): se baja hasta
| él, debajo de la barra superior; si ya se ve arriba, no se mueve nada.
*/

function elementoEn(arriba: number): HTMLElement {
  const el = document.createElement("div");
  el.getBoundingClientRect = () => ({ top: arriba }) as DOMRect;
  return el;
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = "";
});

describe("llevar a la vista", () => {
  it("baja hasta el detalle, justo debajo de la barra superior", () => {
    const barra = document.createElement("header");
    barra.className = "tu-barra-superior";
    barra.getBoundingClientRect = () => ({ height: 64 }) as DOMRect;
    document.body.appendChild(barra);
    const ir = vi.spyOn(window, "scrollTo").mockImplementation(() => undefined);

    llevarALaVista(elementoEn(523));

    expect(ir).toHaveBeenCalledWith({ top: 523 - 64 - 16 });
  });

  it("si ya se ve arriba no se mueve; si quedó arriba de la pantalla, sube", () => {
    const ir = vi.spyOn(window, "scrollTo").mockImplementation(() => undefined);

    llevarALaVista(elementoEn(40));
    expect(ir).not.toHaveBeenCalled();

    llevarALaVista(elementoEn(-300));
    expect(ir).toHaveBeenCalledWith({ top: 0 });
    llevarALaVista(null);
    expect(ir).toHaveBeenCalledTimes(1);
  });
});
