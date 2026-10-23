import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { areaDeEvento, eventoLegible } from "@/lib/eventosNegocio";
import ComunicacionesView from "./ComunicacionesView.vue";

/*
| Difusiones, mensajes automáticos y la bandeja de salida son tres cosas distintas:
| cada pestaña dice cuál es. Los avisos se nombran en palabras (nunca
| «pago.anulado»), agrupados por área.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

/** Los eventos tal como los lista el API (EventoDeDominioTenant::TIPOS). */
function eventosDelApi(): string[] {
  const php = readFileSync(
    resolve(
      __dirname,
      "../../../api/app/Modules/Tenancy/Events/EventoDeDominioTenant.php",
    ),
    "utf8",
  );
  const cuerpo = php.split("const TIPOS = [")[1]?.split("];")[0] ?? "";
  return [...cuerpo.matchAll(/'([a-z_]+\.[a-z0-9_]+)'/g)].map((m) => m[1]);
}
const EVENTOS = eventosDelApi();

let fallaAutomaticos = false;
beforeEach(() => {
  vi.clearAllMocks();
  fallaAutomaticos = false;
  api.get.mockImplementation((url: string) => {
    if (url.endsWith("/plantillas-mensaje")) {
      return fallaAutomaticos
        ? Promise.reject(new Error("caído"))
        : Promise.resolve({
            data: {
              data: [
                {
                  id: "p1",
                  clave: "pago.anulado",
                  canal: "email",
                  destinatario: "persona",
                  asunto: "Anulamos tu cobro",
                  cuerpo: "Hola",
                  activo: false,
                },
              ],
              eventos_disponibles: EVENTOS,
              canales: ["interno", "email"],
            },
          });
    }
    return Promise.resolve({ data: { data: [] } });
  });
});

function montar() {
  return mount(ComunicacionesView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: true,
        RouterLink: { template: '<a class="enlace-tareas"><slot /></a>' },
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="editor"><h2>{{ titulo }}</h2></div>',
        },
      },
    },
  });
}

describe("comunicación: tres cosas distintas y avisos en palabras", () => {
  it("cada pestaña dice qué es: difusión, mensajes automáticos o lo enviado", async () => {
    const w = montar();
    await flushPromises();
    const ayuda = () => w.get('[data-prueba="ayuda-pestana"]').text();

    expect(w.get('[data-pestana="difusion"]').text()).toBe("Difusiones");
    expect(w.get('[data-pestana="automaticos"]').text()).toBe(
      "Mensajes automáticos",
    );
    expect(w.get('[data-pestana="salida"]').text()).toBe("Bandeja de salida");
    expect(ayuda()).toContain("una sola vez");

    await w.get('[data-pestana="automaticos"]').trigger("click");
    await flushPromises();
    expect(ayuda()).toContain("se envían solas");
    // Las reglas que crean tareas no están aquí: se dice dónde.
    expect(w.get('[data-prueba="reglas-tareas"]').text()).toContain(
      "Tareas › Automatización",
    );

    await w.get('[data-pestana="salida"]').trigger("click");
    await flushPromises();
    expect(ayuda()).toContain("ya salió");
  });

  it("cada evento del API tiene nombre en palabras, agrupado por área", async () => {
    expect(EVENTOS).toContain("pago.anulado");
    expect(EVENTOS).toContain("reserva.recordatorio_24h");
    const sinNombre = EVENTOS.filter(
      (e) => !i18n.global.te(`comunicacionPantalla.eventos.${e}`),
    );
    expect(sinNombre).toEqual([]);
    // Ninguno queda en «Otros avisos»: cada uno tiene su área.
    expect(EVENTOS.filter((e) => areaDeEvento(e) === "otros")).toEqual([]);

    const w = montar();
    await flushPromises();
    await w.get('[data-pestana="automaticos"]').trigger("click");
    await flushPromises();

    const nombres = w
      .findAll('[data-prueba="nombre-evento"]')
      .map((n) => n.text());
    expect(nombres).toHaveLength(EVENTOS.length);
    expect(nombres.filter((n) => /^[a-z_]+\.[a-z0-9_]+$/.test(n))).toEqual([]);
    expect(
      w
        .get(
          '[data-prueba="evento-pago.anulado"] [data-prueba="nombre-evento"]',
        )
        .text(),
    ).toBe("Cobro anulado");
    expect(
      w
        .get(
          '[data-prueba="evento-reserva.recordatorio_24h"] [data-prueba="nombre-evento"]',
        )
        .text(),
    ).toBe("Recordatorio un día antes de la clase");
    expect(w.findAll(".com-area-titulo").map((h) => h.text())).toEqual([
      "Reservas y asistencia",
      "Pagos y membresías",
      "Cuenta, privacidad y reseñas",
      "Prospectos y mensajes",
    ]);
    // Configurado pero apagado: se dice, con su punto gris.
    expect(w.get('[data-prueba="evento-pago.anulado"]').text()).toContain(
      "Correo · apagado",
    );

    // El editor se titula con el nombre del aviso.
    await w.get('[data-prueba="evento-pago.anulado"] button').trigger("click");
    expect(w.get('[data-prueba="editor"] h2').text()).toBe("Cobro anulado");
  });

  it("si los mensajes automáticos no cargan, lo dice y deja reintentar", async () => {
    fallaAutomaticos = true;
    const w = montar();
    await flushPromises();
    await w.get('[data-pestana="automaticos"]').trigger("click");
    await flushPromises();
    const aviso = w.get('[data-prueba="error-automaticos"]');
    expect(aviso.text()).toContain(
      "No se pudieron cargar los mensajes automáticos.",
    );

    fallaAutomaticos = false;
    await aviso.get("button").trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-automaticos"]').exists()).toBe(false);
    expect(w.findAll('[data-prueba="nombre-evento"]')).toHaveLength(
      EVENTOS.length,
    );
  });

  it("un evento nuevo sin nombre se lee legible, nunca con su llave", () => {
    expect(eventoLegible("pago.anulado")).toBe("Pago anulado");
    expect(eventoLegible("reserva.recordatorio_24h")).toBe(
      "Reserva recordatorio 24h",
    );
    expect(areaDeEvento("algo.nuevo")).toBe("otros");
  });
});
