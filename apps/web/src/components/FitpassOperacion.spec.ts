import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import FitpassOperacion from "./FitpassOperacion.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
}));
// Los permisos de quien la ve: cada parte se pide solo con el suyo.
const permisos = vi.hoisted(() => new Set<string>());
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
    puede: (p: string) => permisos.has("*") || permisos.has(p),
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const CLASE = {
  oferta_id: "o1",
  nombre: "Pole Nivel 1",
  actividad: "Pole",
  ofrecida: false,
  disciplina: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  permisos.clear();
  permisos.add("*");
  api.get.mockImplementation((ruta: string) =>
    Promise.resolve({
      data: {
        data: ruta.endsWith("/clases")
          ? [CLASE]
          : ruta.endsWith("/horarios")
            ? [
                {
                  id: "s1",
                  fecha: "2030-01-09",
                  hora: "08:00",
                  duracion_minutos: 60,
                  clase: "Pole Nivel 1",
                  disciplina: "Pole Dance",
                  instructor: "Caro",
                  sucursal: "Roma",
                  estado: "programada",
                  lugares: 4,
                },
              ]
            : [
                {
                  id: "l1",
                  reservacion: "R-1",
                  nombre: "Ana Ruiz",
                  clase: "Pole Nivel 1",
                  inicia_en: "2030-01-08T14:00:00Z",
                  asistio: false,
                  marcar_antes_de: "2030-01-10T14:00:00Z",
                },
              ],
      },
    }),
  );
});

describe("Fitpass en Integraciones", () => {
  it("guarda las clases que se ofrecen con su disciplina y marca lo que ya se capturó en Fitpass Admin", async () => {
    api.put.mockResolvedValue({
      data: { data: [{ ...CLASE, ofrecida: true, disciplina: "Pole Dance" }] },
    });
    api.post.mockResolvedValue({ data: {} });
    const w = mount(FitpassOperacion, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.text()).toContain("Pole Dance");
    await w.get('input[type="checkbox"]').setValue(true);
    await w.get('input[maxlength="40"]').setValue("Pole Dance");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar clases")!
      .trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/integraciones/fitpass/clases",
      {
        clases: [{ oferta_id: "o1", ofrecida: true, disciplina: "Pole Dance" }],
      },
    );

    expect(w.findAll('[data-prueba="por-marcar-fitpass"]')).toHaveLength(1);
    await w
      .findAll("button")
      .find((b) => b.text() === "Ya lo marqué")!
      .trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/lugares-plataforma/l1/marcado",
      {},
    );
    expect(w.findAll('[data-prueba="por-marcar-fitpass"]')).toHaveLength(0);
  });

  it("en el pase de lista, recepción ve horarios y lo que falta marcar, sin configurar clases", async () => {
    permisos.clear();
    permisos.add("agenda.ver");
    permisos.add("checkins.registrar");
    const w = mount(FitpassOperacion, {
      props: { operacion: true },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const rutas = api.get.mock.calls.map(([ruta]) => ruta as string);
    expect(rutas).toEqual([
      "/api/v1/app/demo/integraciones/fitpass/horarios",
      "/api/v1/app/demo/integraciones/fitpass/por-marcar",
    ]);
    expect(w.text()).not.toContain("Clases que ofreces en Fitpass");
    expect(w.text()).toContain("Horarios para capturar");
    expect(w.findAll('[data-prueba="por-marcar-fitpass"]')).toHaveLength(1);
  });

  it("sin permiso de check-in no pide ni muestra lo que falta marcar", async () => {
    permisos.clear();
    permisos.add("agenda.ver");
    const w = mount(FitpassOperacion, {
      props: { operacion: true },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(w.text()).not.toContain("Por marcar en Fitpass Admin");
  });
});
