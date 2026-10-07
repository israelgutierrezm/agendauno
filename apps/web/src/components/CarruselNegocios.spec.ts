import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import CarruselNegocios from "./CarruselNegocios.vue";

const lista = (largo: number, prefijo = "") =>
  Array.from({ length: largo }, (_, i) => ({
    clave: prefijo + i,
    nombre: "Negocio " + prefijo + i,
    descripcion: "Descripción " + i,
    alt: "Foto " + i,
    src: "/foto.jpg",
  }));
const negocios = lista(9);
let reducido = false;
let observador: (entradas: { isIntersecting: boolean }[]) => void;
const desconectar = vi.fn();
const montajes: ReturnType<typeof mount>[] = [];
function montar(props = { negocios }) {
  const vista = mount(CarruselNegocios, { props });
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
  it("elegir con las flechas detiene el giro y «Reanudar» lo vuelve a mover", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(4000);
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    await vi.advanceTimersByTimeAsync(12000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    expect(vista.get(".orbita-detalle").attributes("aria-live")).toBe("polite");
    await vista.get('[aria-label="Reanudar carrusel"]').trigger("click");
    await vi.advanceTimersByTimeAsync(5100);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 2");
  });
  it("el negocio elegido con clic o deslizando no cambia solo a los cinco segundos", async () => {
    const vista = mount(CarruselNegocios, {
      props: { negocios },
      slots: {
        default: `<template #default="{ negocio, elegido }"><p class="pie">{{ negocio.clave }}:{{ elegido }}</p></template>`,
      },
    });
    montajes.push(vista);
    const pie = () => vista.get(".pie").text();
    // Clic con mouse en una categoría (sin :focus-visible, así que enfocar no pausa).
    await vista.findAll(".orbita-categorias button")[3]!.trigger("click");
    expect(pie()).toBe("3:true");
    await vi.advanceTimersByTimeAsync(5100);
    expect(pie()).toBe("3:true");
    await vi.advanceTimersByTimeAsync(20000);
    expect(pie()).toBe("3:true");
    // Clic en la foto de al lado.
    await vista.findAll(".orbita-foto")[4]!.trigger("click");
    await vi.advanceTimersByTimeAsync(10000);
    expect(pie()).toBe("4:true");
    // Deslizar a la izquierda (touch): pasa al siguiente y se queda ahí.
    // jsdom no trae PointerEvent: basta un evento con ese nombre y coordenadas.
    const escena = vista.get(".orbita-escena").element;
    const gesto = (tipo: string, clientX: number, clientY: number) =>
      escena.dispatchEvent(
        new MouseEvent(tipo, { clientX, clientY, bubbles: true }),
      );
    gesto("pointerdown", 300, 100);
    gesto("pointerup", 200, 105);
    await nextTick();
    expect(pie()).toBe("5:true");
    await vi.advanceTimersByTimeAsync(10000);
    expect(pie()).toBe("5:true");
    expect(vista.find('[aria-label="Reanudar carrusel"]').exists()).toBe(true);
  });
  it("usar las flechas mientras está pausado no reactiva el giro", async () => {
    const vista = montar();
    await vista.get('[aria-label="Pausar carrusel"]').trigger("click");
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 1");
    expect(vista.find('[aria-label="Reanudar carrusel"]').exists()).toBe(true);
  });
  it("con los 8 giros de citas mantiene cinco visibles y navega en círculo", async () => {
    const vista = montar({ negocios: lista(8) });
    expect(vista.findAll(".orbita-foto")).toHaveLength(8);
    expect(vista.findAll(".orbita-foto:not(.fuera)")).toHaveLength(5);
    expect(vista.get(".orbita-paginacion").text()).toBe("01 / 08");
    await vista.get('[aria-label="Negocio anterior"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 7");
    expect(vista.findAll(".orbita-foto:not(.fuera)")).toHaveLength(5);
    await vista.get('[aria-label="Negocio siguiente"]').trigger("click");
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
  });
  it("si el padre cambia la lista vuelve al primero sin quedar fuera de rango", async () => {
    const vista = montar();
    await vista.trigger("keydown", { key: "End" });
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 8");
    await vista.setProps({ negocios: lista(8, "c") });
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio c0");
    expect(vista.findAll(".es-principal")).toHaveLength(1);
    expect(vista.get(".orbita-paginacion").text()).toBe("01 / 08");
  });
  it("dice abajo qué negocio está activo y si la persona lo eligió", async () => {
    const vista = mount(CarruselNegocios, {
      props: { negocios },
      slots: {
        default: `<template #default="{ negocio, elegido }"><p class="pie">{{ negocio.clave }}:{{ elegido }}</p></template>`,
      },
    });
    montajes.push(vista);
    const pie = () => vista.get(".pie").text();
    expect(pie()).toBe("0:false");
    // El giro automático no cuenta como elección.
    await vi.advanceTimersByTimeAsync(5100);
    expect(pie()).toBe("1:false");
    await vista.findAll(".orbita-categorias button")[4]!.trigger("click");
    expect(pie()).toBe("4:true");
    await vista.trigger("keydown", { key: "ArrowRight" });
    expect(pie()).toBe("5:true");
    // Si sigue girando y cambia solo, ya no es la elección de la persona.
    await vista.get('[aria-label="Reanudar carrusel"]').trigger("click");
    await vi.advanceTimersByTimeAsync(5100);
    expect(pie()).toBe("6:false");
    // Otra lista: de vuelta al primero, sin elección.
    await vista.findAll(".orbita-categorias button")[2]!.trigger("click");
    await vista.setProps({ negocios: lista(8, "c") });
    expect(pie()).toBe("c0:false");
  });
  it("con un solo negocio no gira ni muestra controles", async () => {
    const vista = montar({ negocios: lista(1) });
    expect(vista.find(".orbita-controles").exists()).toBe(false);
    expect(vista.findAll(".es-principal")).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(12000);
    expect(vista.get(".orbita-detalle h3").text()).toBe("Negocio 0");
    expect(vi.getTimerCount()).toBe(0);
  });
});
