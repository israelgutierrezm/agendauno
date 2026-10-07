import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { api } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PanelNuevaCita from "./PanelNuevaCita.vue";

/*
| Al agendar desde el negocio se avisa, antes de guardar, si la hora cae fuera de la
| atención del profesional (se puede agendar igual) o choca con un bloqueo (no).
*/

vi.mock("@/lib/api", () => ({
  api: { post: vi.fn() },
  fijarBearer: vi.fn(),
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

// El 5 de octubre de 2026 es lunes (día 1).
function montar(hora: string) {
  return mount(PanelNuevaCita, {
    props: {
      abierto: false,
      base: "/api/v1/app/demo",
      ofertas: [{ id: "o1", nombre: "Corte", duracion_minutos: 30 }],
      sucursales: [{ id: "s1", nombre: "Roma" }],
      profesionales: [{ id: "i1", nombre: "Beto" }],
      clientes: [],
      inicial: {
        fecha: "2026-10-05",
        hora,
        instructorId: "i1",
        sucursalId: "s1",
      },
      ventanas: [
        {
          instructor_id: "i1",
          sucursal_id: "s1",
          dia_semana: 1,
          hora_inicio: "09:00",
          hora_fin: "14:00",
        },
      ],
      bloqueos: [
        {
          ambito: "profesional",
          instructor_id: "i1",
          sucursal_id: null,
          desde: "2026-10-05T18:00:00Z", // 12:00 en Ciudad de México
          hasta: "2026-10-05T19:00:00Z",
          motivo: "Comida",
        },
      ],
      zona: "America/Mexico_City",
    },
    global: {
      plugins: [i18n],
      stubs: {
        PanelLateral: {
          props: ["abierto"],
          template: "<div><slot /><slot name='pie' /></div>",
        },
      },
    },
  });
}

async function abrir(hora: string) {
  const w = montar(hora);
  await w.setProps({ abierto: true });
  return w;
}

beforeEach(() => setActivePinia(createPinia()));

describe("aviso del horario al agendar", () => {
  it("dentro de su atención, sin aviso", async () => {
    const w = await abrir("10:00");
    expect(w.find('[data-prueba="aviso-horario"]').exists()).toBe(false);
  });

  it("fuera de su atención, avisa pero deja agendar", async () => {
    const w = await abrir("13:45");
    expect(w.get('[data-prueba="aviso-horario"]').text()).toContain(
      "fuera del horario de atención de Beto",
    );
  });

  it("un día que no atiende, lo dice", async () => {
    const w = await abrir("10:00");
    await w.get('input[type="date"]').setValue("2026-10-06");
    expect(w.get('[data-prueba="aviso-horario"]').text()).toContain(
      "Beto no atiende ese día",
    );
  });

  it("si choca con un bloqueo, avisa que no se podrá", async () => {
    const w = await abrir("11:45");
    expect(w.get('[data-prueba="aviso-horario"]').text()).toContain(
      "Choca con un bloqueo de la agenda (Comida)",
    );
  });
});

describe("cliente nuevo desde la agenda", () => {
  it("su celular lleva la lada del negocio (o la que elija)", async () => {
    // Un negocio en Colombia; quien agenda puede dar de alta clientes.
    const sesion = useSesionTenantStore();
    sesion.estudio = { pais: "CO", lada: "57" } as typeof sesion.estudio;
    sesion.usuario = { permisos: ["*"] } as typeof sesion.usuario;
    vi.mocked(api.post).mockRejectedValue(new Error("sin red"));
    const w = await abrir("10:00");
    await w
      .findAll("button")
      .find((b) => b.text() === "Cliente nuevo")!
      .trigger("click");

    expect(w.get('[data-prueba="lada-celular"]').text()).toBe("CO +57");
    await w.get("#pnc-celular").setValue("300 123 4567");
    await w
      .findAll("label.block")
      .find((l) => l.text() === "Nombre")!
      .get("input")
      .setValue("Lía");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/miembros",
      expect.objectContaining({ nombre: "Lía", celular: "+57 3001234567" }),
    );
  });
});
