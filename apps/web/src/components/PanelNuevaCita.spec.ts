import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { api } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PanelNuevaCita from "./PanelNuevaCita.vue";

/*
| Nueva cita guía antes de guardar: sucursal, servicio, día, quién atiende ahí y sus
| HORARIOS LIBRES (los da el servidor). Quien gestiona la agenda conserva la captura
| manual de otra hora; entonces se avisa si cae fuera de los libres, fuera de la
| atención del profesional (se puede agendar igual) o choca con un bloqueo (no).
*/

vi.mock("@/lib/api", () => ({
  api: { post: vi.fn(), get: vi.fn() },
  fijarBearer: vi.fn(),
  mensajeDeError: () => "Error",
}));
// Los libres del lunes 5 con Beto: 10:00, 10:30 y 11:00.
function libres(horas = ["10:00", "10:30", "11:00"]) {
  vi.mocked(api.get).mockResolvedValue({
    data: {
      data: {
        slots: horas.map((h) => ({ inicia_local: `2026-10-05T${h}` })),
      },
    },
  });
}
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

// El 5 de octubre de 2026 es lunes (día 1).
function montar(
  hora: string,
  profesionales: { id: string; nombre: string; sucursales?: string[] }[] = [
    { id: "i1", nombre: "Beto" },
  ],
) {
  return mount(PanelNuevaCita, {
    props: {
      abierto: false,
      base: "/api/v1/app/demo",
      ofertas: [{ id: "o1", nombre: "Corte", duracion_minutos: 30 }],
      sucursales: [{ id: "s1", nombre: "Roma" }],
      profesionales,
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
        // Iván atiende los martes (el lunes, no).
        {
          instructor_id: "i2",
          sucursal_id: "s1",
          dia_semana: 2,
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

async function abrir(
  hora: string,
  profesionales?: { id: string; nombre: string; sucursales?: string[] }[],
) {
  const w = montar(hora, profesionales);
  await w.setProps({ abierto: true });
  await flushPromises();
  return w;
}

// Quien gestiona la agenda (puede capturar otra hora).
function gestionaLaAgenda(): void {
  const sesion = useSesionTenantStore();
  sesion.usuario = { permisos: ["*"] } as typeof sesion.usuario;
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.mocked(api.get).mockReset();
  vi.mocked(api.post).mockReset();
  libres();
});

describe("horarios libres antes de guardar", () => {
  it("muestra los libres del profesional y la hora tocada en la agenda queda elegida", async () => {
    const w = await abrir("10:30");
    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/disponibilidad", {
      params: {
        instructor_id: "i1",
        sucursal_id: "s1",
        fecha: "2026-10-05",
        oferta_id: "o1",
      },
    });
    expect(w.findAll("[data-hora]").map((b) => b.text())).toEqual([
      "10:00",
      "10:30",
      "11:00",
    ]);
    expect(w.get('[data-hora="10:30"]').attributes("aria-pressed")).toBe(
      "true",
    );
    // La elegida se ve distinta de las demás.
    expect(w.get('[data-hora="10:30"]').classes()).toContain("tu-btn-primario");
    expect(w.get('[data-hora="10:00"]').classes()).toContain(
      "tu-btn-secundario",
    );
    expect(w.find('[data-prueba="aviso-horario"]').exists()).toBe(false);
  });

  it("sin permiso de gestionar la agenda, una hora que no está libre no se captura", async () => {
    const w = await abrir("13:45");
    expect(w.find('[data-prueba="otra-hora"]').exists()).toBe(false);
    expect(w.find('[data-prueba="hora-manual"]').exists()).toBe(false);
    expect(w.findAll('[aria-pressed="true"]')).toHaveLength(0);
    // Agendar espera a que se elija un horario libre.
    const agendar = w
      .findAll("button")
      .find((b) => b.text() === "Agendar cita")!;
    expect(agendar.attributes("disabled")).toBeDefined();
  });

  it("sin libres ese día lo dice, en lugar de mostrar el día como libre", async () => {
    libres([]);
    const w = await abrir("10:00");
    expect(w.get('[data-prueba="sin-horarios"]').text()).toContain(
      "Beto no tiene horarios libres ese día",
    );
  });

  it("solo ofrece a quienes atienden en la sucursal; quien no trabaja ese día va al final", async () => {
    const w = await abrir("10:00", [
      { id: "i2", nombre: "Iván", sucursales: ["s1"] },
      { id: "i1", nombre: "Beto", sucursales: ["s1"] },
      { id: "i3", nombre: "Memo", sucursales: ["s2"] },
    ]);
    const opciones = w
      .get('[data-prueba="profesional-cita"]')
      .findAll("option")
      .map((o) => o.text());
    // Iván no tiene horario el lunes; Memo atiende en otra sucursal.
    expect(opciones).toEqual(["Beto", "Iván · no atiende este día"]);
  });
});

describe("aviso del horario al agendar (otra hora, a mano)", () => {
  beforeEach(gestionaLaAgenda);

  it("una hora libre no lleva aviso; otra hora dentro de su atención avisa que no es libre", async () => {
    const w = await abrir("10:00");
    expect(w.find('[data-prueba="aviso-horario"]').exists()).toBe(false);
    await w.get('[data-prueba="otra-hora"]').trigger("click");
    await w.get('[data-prueba="hora-manual"]').setValue("09:30");
    expect(w.get('[data-prueba="aviso-horario"]').text()).toContain(
      "No es uno de los horarios libres",
    );
  });

  it("fuera de su atención, avisa pero deja agendar", async () => {
    const w = await abrir("13:45");
    expect(w.get('[data-prueba="aviso-horario"]').text()).toContain(
      "fuera del horario de atención de Beto",
    );
  });

  it("un día que no atiende, lo dice", async () => {
    const w = await abrir("10:00");
    await w.get('[data-prueba="otra-hora"]').trigger("click");
    await w.get('input[type="date"]').setValue("2026-10-06");
    await flushPromises();
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
      .find((b) => b.text() === "Alumno nuevo")!
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
