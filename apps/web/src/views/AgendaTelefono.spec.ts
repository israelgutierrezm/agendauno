import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { nuevaClase } from "@/i18n/locales/gestion.es-MX";
import AgendaView from "./AgendaView.vue";

/*
| La agenda en el teléfono: la tira de días deja a la vista el día elegido (al entrar,
| al cambiar de día o de semana y al cambiar el tamaño de la ventana) y, en «Nueva
| clase», las formas de cargar horarios se apilan en vez de desbordarse a lo ancho.
*/

const centrar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/pantalla", async (original) => ({
  ...(await original<typeof import("@/lib/pantalla")>()),
  centrarEnTira: centrar,
}));
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    puede: () => true,
    esCitas: false,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));

// Domingo 13 de enero de 2030: el último día de la tira.
const HOY = new Date("2030-01-13T18:00:00Z");

function montar() {
  return mount(AgendaView, {
    attachTo: document.body,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, nuevaClase } },
        }),
      ],
      stubs: {
        teleport: true,
        EncabezadoSeccion: {
          template: "<div><slot name='acciones' /></div>",
        },
        AgendaProfesionales: true,
        PanelCita: true,
        PanelNuevaCita: true,
        AgendaClasesSemana: true,
        AgendaKpis: true,
      },
    },
  });
}

/** La tira y el día que se le pidió centrar en la última llamada. */
function ultimoCentrado(): { tira: HTMLElement; dia: HTMLElement } {
  const [tira, dia] = centrar.mock.calls.at(-1)!;
  return { tira, dia };
}

describe("agenda en el teléfono", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/ofertas")
            ? [{ id: "o1", nombre: "Nivel 1", modalidad: "grupal" }]
            : url.endsWith("/sucursales")
              ? [
                  {
                    id: "s1",
                    nombre: "Roma Norte",
                    zona_horaria: "America/Mexico_City",
                  },
                ]
              : [],
        },
      }),
    );
  });
  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
  });

  it("la tira de días centra el día activo al entrar, al cambiar de día o de semana y al redimensionar", async () => {
    const w = montar();
    await flushPromises();

    // Al entrar: hoy (domingo 13), sin animación.
    const alEntrar = ultimoCentrado();
    expect(alEntrar.tira.dataset.prueba).toBe("tira-dias");
    let dia = alEntrar.dia;
    expect(dia.getAttribute("aria-pressed")).toBe("true");
    expect(dia.textContent).toContain("13");
    expect(centrar.mock.calls.at(-1)![2]).toBe(false);

    // Otro día: ese, con animación (salvo «reducir movimiento», ver lib/pantalla).
    const botones = w.findAll('[data-prueba="tira-dias"] button');
    await botones[2]!.trigger("click");
    await flushPromises();
    ({ dia } = ultimoCentrado());
    expect(dia).toBe(botones[2]!.element);
    expect(dia.textContent).toContain("9");
    expect(centrar.mock.calls.at(-1)![2]).toBe(true);

    // La semana siguiente: el mismo día de la semana (miércoles 16).
    await w
      .findAll("button")
      .find((b) => b.attributes("aria-label") === esMX.agenda.semanaSiguiente)!
      .trigger("click");
    await flushPromises();
    ({ dia } = ultimoCentrado());
    expect(dia.getAttribute("aria-pressed")).toBe("true");
    expect(dia.textContent).toContain("16");

    // Girar el teléfono o cambiar el tamaño: se vuelve a centrar, sin animación.
    const antes = centrar.mock.calls.length;
    window.dispatchEvent(new Event("resize"));
    expect(centrar.mock.calls.length).toBe(antes + 1);
    expect(ultimoCentrado().dia.textContent).toContain("16");
    expect(centrar.mock.calls.at(-1)![2]).toBe(false);

    // Fuera de la agenda ya no escucha la ventana.
    w.unmount();
    window.dispatchEvent(new Event("resize"));
    expect(centrar.mock.calls.length).toBe(antes + 1);
  });

  it("«Nueva clase»: las formas de cargar horarios llevan la variante que se apila en angosto", async () => {
    const w = montar();
    await flushPromises();
    await w
      .findAll("button")
      .find((b) => b.text() === esMX.agenda.nuevaClase)!
      .trigger("click");

    const modos = w.get('[data-prueba="modos-nueva-clase"]');
    expect(modos.classes()).toEqual(
      expect.arrayContaining(["tu-segmentado", "tu-segmentado-apilado"]),
    );
    expect(modos.findAll("button").map((b) => b.text())).toEqual([
      nuevaClase.modos.una,
      nuevaClase.modos.misma,
      nuevaClase.modos.porDia,
    ]);
    w.unmount();

    // La variante: por debajo de 640 px, una opción debajo de otra y a todo el ancho,
    // sin desplazamiento a lo ancho.
    const css = readFileSync(resolve(process.cwd(), "src/style.css"), "utf8");
    const angosto = css.match(
      /@media \(max-width: 639\.98px\) \{\s*\.tu-segmentado\.tu-segmentado-apilado \{([^}]*)\}/,
    );
    expect(angosto?.[1]).toMatch(/flex-direction:\s*column/);
    expect(angosto?.[1]).toMatch(/width:\s*100%/);
    expect(angosto?.[1]).toMatch(/overflow-x:\s*visible/);
  });
});
