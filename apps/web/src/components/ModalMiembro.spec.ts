import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ModalMiembro, { type PersonaListado } from "./ModalMiembro.vue";

/*
| El detalle de un cliente desde Miembros, en un modal: su plan, saldo, última y
| próxima visita, sus datos, lo que hizo en el periodo y las notas del equipo; y la
| ficha completa y el cobro a un clic.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
const permisos = vi.hoisted(() => ({
  lista: [] as string[],
  esCitas: false,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    terminologia: { miembro: "Alumno", sesion: "Clase" },
    get esCitas() {
      return permisos.esCitas;
    },
    puede: (p: string) => permisos.lista.includes(p),
    usuario: { rol: "admin" },
    estudio: null,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const persona: PersonaListado = {
  id: "ana",
  nombre: "Ana",
  segundo_nombre: null,
  primer_apellido: "García",
  segundo_apellido: "López",
  nombre_completo: "Ana García López",
  email: "ana@correo.mx",
  celular: "5512345678",
  activo: true,
  archivado: false,
  alta: "2026-03-14",
};

function resumen(cambios: Record<string, unknown> = {}) {
  return {
    ilimitado: false,
    saldo_creditos: 8,
    saldo_unidades: 8000,
    como_nos_conocio: "instagram",
    membresia: {
      estado: "vigente",
      valido_hasta: "2026-12-12",
      pausada_hasta: null,
      plan: "Plan mensual 12 clases",
    },
    proxima_reserva: {
      clase: "Pole Fitness Intermedio",
      inicia_en: "2026-10-07T01:00:00Z",
      zona_horaria: "America/Mexico_City",
    },
    ultima_visita: {
      clase: "Pole Fitness Básico",
      profesional: "Sofía",
      inicia_en: "2026-09-29T00:00:00Z",
      zona_horaria: "America/Mexico_City",
    },
    estadisticas: {
      dias: 30,
      asistencias: 12,
      reservadas: 15,
      canceladas: 2,
      no_asistio: 1,
    },
    alertas: [],
    ...cambios,
  };
}
const nota = {
  id: "n1",
  texto: "Prefiere clases por la tarde.",
  autor: "Laura",
  creada_en: "2026-09-25T18:00:00Z",
};
let datosResumen = resumen();
let ficha: Record<string, unknown> = {
  reservas: [],
  ordenes: [],
  pendientes: [],
};

function montar() {
  return mount(ModalMiembro, {
    props: { abierto: true, persona },
    global: {
      plugins: [i18n],
      stubs: {
        teleport: true,
        RouterLink: {
          props: ["to"],
          template: "<a :data-to='JSON.stringify(to)'><slot /></a>",
        },
        CortePlanes: true,
        RegistrarPagoOrden: true,
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.lista = [
    "miembros.gestionar",
    "derechos.ver",
    "ordenes.ver",
    "ordenes.gestionar",
    "productos.ver",
    "agenda.ver",
  ];
  permisos.esCitas = false;
  datosResumen = resumen();
  ficha = { reservas: [], ordenes: [], pendientes: [] };
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/resumen")
          ? datosResumen
          : url.endsWith("/notas")
            ? [nota]
            : ficha,
      },
    }),
  );
});

describe("detalle de un cliente en un modal", () => {
  it("arriba, su nombre, contacto y estado; en el resumen, plan, saldo y visitas", async () => {
    const w = montar();
    await flushPromises();

    const cabecera = w.get('[data-prueba="detalle-miembro"]');
    expect(cabecera.text()).toContain("Ana García López");
    expect(cabecera.text()).toContain("ana@correo.mx");
    expect(cabecera.get(".tu-pildora").text()).toBe("Activo");

    const plan = w.get('[data-prueba="plan-vigente"]').text();
    expect(plan).toContain("Plan mensual 12 clases");
    expect(plan).toContain("vence el 12 dic 2026");
    expect(w.get('[data-prueba="saldo"]').text()).toContain("8 disponibles");
    expect(w.get('[data-prueba="ultima-visita"]').text()).toContain(
      "Pole Fitness Básico · Sofía",
    );
    // «Ver en agenda» abre la agenda en el día local de su próxima reserva.
    const proxima = w.get('[data-prueba="proxima-reserva"]');
    expect(proxima.text()).toContain("Pole Fitness Intermedio");
    expect(JSON.parse(proxima.get("a").attributes("data-to")!)).toEqual({
      name: "agenda",
      query: { fecha: "2026-10-06" },
    });

    const info = w.get('[data-prueba="info-personal"]').text();
    expect(info).toContain("García López");
    expect(info).toContain("5512345678");
    expect(info).toContain("Instagram");
  });

  it("las estadísticas cambian de periodo", async () => {
    const w = montar();
    await flushPromises();
    const estadisticas = w.get('[data-prueba="estadisticas"]').text();
    expect(estadisticas).toContain("Asistencias12");
    expect(estadisticas).toContain("Cancelaciones2");

    await w.get('[data-prueba="periodo-estadisticas"]').setValue("90");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/miembros/ana/resumen",
      { params: { dias: 90 } },
    );
  });

  it("las notas del equipo se agregan desde el resumen", async () => {
    const nueva = { ...nota, id: "n2", texto: "Lesión en el hombro." };
    api.post.mockResolvedValue({ data: { data: nueva } });
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="notas-resumen"]').text()).toContain(
      "Prefiere clases por la tarde.",
    );

    const agregar = w
      .get('[data-prueba="notas-resumen"]')
      .findAll("button")
      .find((b) => b.text().includes("Agregar nota"))!;
    await agregar.trigger("click");
    await w.get('[data-prueba="texto-nota"]').setValue("Lesión en el hombro.");
    await w.get('[data-prueba="notas-resumen"] form').trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/miembros/ana/notas",
      { texto: "Lesión en el hombro." },
    );
    expect(w.get('[data-prueba="notas-resumen"]').text()).toContain(
      "Lesión en el hombro.",
    );
  });

  it("en la pestaña de notas, quien gestiona las borra", async () => {
    api.delete.mockResolvedValue({});
    const w = montar();
    await flushPromises();
    const pestana = w
      .get('[data-prueba="pestanas-miembro"]')
      .findAll("button")
      .find((b) => b.text() === "Notas")!;
    await pestana.trigger("click");
    const borrar = w
      .get('[data-prueba="notas-miembro"]')
      .findAll("button")
      .find((b) => b.text() === "Eliminar")!;
    await borrar.trigger("click");
    await flushPromises();

    expect(api.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/miembros/ana/notas/n1",
    );
    expect(w.find('[data-prueba="notas-miembro"]').exists()).toBe(false);
  });

  it("al pie, cerrar, su ficha completa y cobrar", async () => {
    const w = montar();
    await flushPromises();
    expect(
      JSON.parse(
        w.get('[data-prueba="ficha-completa"]').attributes("data-to")!,
      ),
    ).toEqual({ name: "ficha-miembro", params: { id: "ana" } });

    await w.get('[data-prueba="cobrar-miembro"]').trigger("click");
    expect(w.emitted("cobrar")).toHaveLength(1);
    // Sin permiso de bajas, no se ofrece darle de baja.
    expect(w.text()).not.toContain("Dar de baja");
  });

  it("sin ver dinero no hay pestaña de pagos ni botón de cobro", async () => {
    permisos.lista = ["miembros.gestionar", "derechos.ver"];
    ficha = { reservas: [], ordenes: null, pendientes: null };
    const w = montar();
    await flushPromises();
    const pestanas = w
      .get('[data-prueba="pestanas-miembro"]')
      .findAll("button")
      .map((b) => b.text());
    expect(pestanas).toEqual([
      "Resumen",
      "Planes y créditos",
      "Clases",
      "Notas",
    ]);
    expect(w.find('[data-prueba="cobrar-miembro"]').exists()).toBe(false);
  });

  it("en citas, sin plan, no habla de plan ni de saldo", async () => {
    permisos.esCitas = true;
    datosResumen = resumen({
      saldo_creditos: 0,
      saldo_unidades: 0,
      membresia: {
        estado: "sin_membresia",
        valido_hasta: null,
        pausada_hasta: null,
        plan: null,
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="plan-vigente"]').exists()).toBe(false);
    expect(w.find('[data-prueba="saldo"]').exists()).toBe(false);
    expect(
      w
        .get('[data-prueba="pestanas-miembro"]')
        .findAll("button")
        .map((b) => b.text()),
    ).not.toContain("Planes y créditos");
  });
});
