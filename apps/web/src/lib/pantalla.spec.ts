import { afterEach, describe, expect, it, vi } from "vitest";

import { centrarEnTira, llevarALaVista } from "./pantalla";

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

/*
| Centrar en su tira (la de días de la agenda del teléfono): solo se desplaza la tira,
| nunca la página; sin animación con «reducir movimiento»; una tira oculta no se toca.
*/
function tiraDe(ancho: number, total: number, desplazada = 0) {
  const tira = document.createElement("div");
  Object.defineProperty(tira, "clientWidth", { value: ancho });
  Object.defineProperty(tira, "scrollWidth", { value: total });
  Object.defineProperty(tira, "scrollLeft", {
    value: desplazada,
    writable: true,
  });
  tira.getBoundingClientRect = () => ({ left: 16 }) as DOMRect;
  const ir = vi.fn();
  tira.scrollTo = ir as unknown as HTMLElement["scrollTo"];
  return { tira, ir };
}
function piezaEn(izquierda: number, ancho = 48): HTMLElement {
  const el = document.createElement("button");
  el.getBoundingClientRect = () =>
    ({ left: izquierda, width: ancho }) as DOMRect;
  return el;
}
function conMenosMovimiento(si: boolean): void {
  window.matchMedia = vi.fn().mockImplementation((consulta: string) => ({
    matches: si && consulta.includes("reduced-motion"),
  })) as unknown as typeof window.matchMedia;
}

describe("centrar en la tira", () => {
  it("deja el día al centro de la tira, con animación si se pide", () => {
    conMenosMovimiento(false);
    // Tira de 300 px (en x = 16) con 900 de contenido, ya desplazada 40; el día está
    // en x = 500.
    const { tira, ir } = tiraDe(300, 900, 40);
    centrarEnTira(tira, piezaEn(500), true);
    // 40 + (500 - 16) - (300 - 48) / 2 = 398
    expect(ir).toHaveBeenCalledWith({ left: 398, behavior: "smooth" });
    // Sin pedir animación (al entrar o al redimensionar), directo.
    centrarEnTira(tira, piezaEn(500));
    expect(ir).toHaveBeenLastCalledWith({ left: 398, behavior: "auto" });
  });

  it("no se pasa de los extremos ni anima con «reducir movimiento»", () => {
    conMenosMovimiento(true);
    const { tira, ir } = tiraDe(300, 600);
    centrarEnTira(tira, piezaEn(20), true);
    expect(ir).toHaveBeenLastCalledWith({ left: 0, behavior: "auto" });
    centrarEnTira(tira, piezaEn(900), true);
    expect(ir).toHaveBeenLastCalledWith({ left: 300, behavior: "auto" });
  });

  it("una tira oculta (sin ancho) o sin día no se mueve, y la página tampoco", () => {
    conMenosMovimiento(false);
    const pagina = vi
      .spyOn(window, "scrollTo")
      .mockImplementation(() => undefined);
    const { tira, ir } = tiraDe(0, 0);
    centrarEnTira(tira, piezaEn(500), true);
    centrarEnTira(tiraDe(300, 600).tira, null, true);
    expect(ir).not.toHaveBeenCalled();
    expect(pagina).not.toHaveBeenCalled();
  });
});
