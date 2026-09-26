import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import CarruselNegocios from "./CarruselNegocios.vue";

const negocios = Array.from({ length: 9 }, (_, i) => ({
  clave: String(i),
  nombre: "Negocio " + i,
  descripcion: "Descripción " + i,
  alt: "Foto " + i,
  src: "/foto.jpg",
}));
let reducido = false;
let observador: (entradas: { isIntersecting: boolean }[]) => void;
const desconectar = vi.fn();
const montajes: ReturnType<typeof mount>[] = [];
function montar() {
  const vista = mount(CarruselNegocios, { props: { negocios } });
  montajes.push(vista);
  return vista;
}
describe("carrusel de negocios", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    reducido = false;
    vi.stubGlobal(
      "matchMedia",
      vi.fn(() => ({
        matches: reducido,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      })),
    );
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(callback: typeof observador) {
          observador = callback;
        }
        observe() {
          observador([{ isIntersecting: true }]);
        }
        disconnect = desconectar;
      },
    );
  });
  afterEach(() => {
    montajes.splice(0).forEach((vista) => vista.unmount());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });
  it("destaca una imagen y mantiene cinco fotografías visibles", () => {
    const vista = montar();
    expect(vista.findAll(".es-principal")).toHaveLength(1);
    expect(vista.findAll(".orbita-foto:not(.fuera)")).toHaveLength(5);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
  });
  it("navega circularmente y permite elegir una categoría", async () => {
    const vista = montar();
    await vista.get('[aria-label="Negocio anterior"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 8");
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
    await vista.findAll(".orbita-categorias button")[4]!.trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 4");
  });
  it("avanza automáticamente, permite pausar y limpia el temporizador", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(5100);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vista.get('[aria-label="Pausar carrusel"]').trigger("click");
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vista.get('[aria-label="Reanudar carrusel"]').trigger("click");
    await vi.advanceTimersByTimeAsync(5100);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 2");
    vista.unmount();
    expect(vi.getTimerCount()).toBe(0);
    expect(desconectar).toHaveBeenCalled();
  });
  it("se detiene fuera de pantalla pero no al pasar el cursor", async () => {
    const vista = montar();
    observador([{ isIntersecting: false }]);
    await vi.advanceTimersByTimeAsync(6000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
    observador([{ isIntersecting: true }]);
    await vista.trigger("mouseenter");
    await vi.advanceTimersByTimeAsync(6000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vista.trigger("mouseleave");
    await vi.advanceTimersByTimeAsync(5100);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 2");
  });
  it("respeta movimiento reducido y mantiene la navegación por teclado", async () => {
    reducido = true;
    const vista = montar();
    await vi.advanceTimersByTimeAsync(12000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
    expect(vista.find("[data-reproducir]").exists()).toBe(false);
    await vista.trigger("keydown", { key: "ArrowRight" });
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vista.trigger("keydown", { key: "End" });
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 8");
    await vista.trigger("keydown", { key: "Home" });
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
  });
  it("pausa al enfocar un control para no mover contenido durante su lectura", async () => {
    const vista = montar();
    vi.spyOn(
      vista.get('[aria-label="Negocio siguiente"]').element,
      "matches",
    ).mockReturnValue(true);
    await vista.get('[aria-label="Negocio siguiente"]').trigger("focusin");
    await vi.advanceTimersByTimeAsync(6000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
    expect(vista.find('[aria-label="Reanudar carrusel"]').exists()).toBe(true);
  });
  it("las flechas reinician los cinco segundos sin desactivar el giro", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(4000);
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vi.advanceTimersByTimeAsync(4000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vi.advanceTimersByTimeAsync(1100);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 2");
  });
  it("usar las flechas mientras está pausado no reactiva el giro", async () => {
    const vista = montar();
    await vista.get('[aria-label="Pausar carrusel"]').trigger("click");
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    expect(vista.find('[aria-label="Reanudar carrusel"]').exists()).toBe(true);
  });
});
