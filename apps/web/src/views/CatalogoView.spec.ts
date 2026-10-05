import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { margenesServicio } from "@/i18n/locales/gestion.es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import CatalogoView from "./CatalogoView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    puede: () => true,
    esCitas: true,
    moneda: "MXN",
  }),
}));

const oferta = (extra: Record<string, unknown>) => ({
  id: "o1",
  nombre: "Masaje",
  modalidad: "individual",
  capacidad: null,
  lugares: 0,
  precio_clase_minor: 90000,
  politica_reserva: "pago",
  duracion_minutos: 60,
  actividad: null,
  preparacion_min: 0,
  limpieza_min: 0,
  ...extra,
});

async function montar(ofertas: unknown[]) {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: { data: url.endsWith("/ofertas") ? ofertas : [] },
    }),
  );
  const w = mount(CatalogoView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { margenesServicio, perfilPublico } },
        }),
      ],
      stubs: { teleport: true },
    },
  });
  await flushPromises();
  return w;
}

describe("catálogo", () => {
  it("un servicio de pago no dice 'con membresía', tenga o no márgenes", async () => {
    const sin = await montar([oferta({})]);
    expect(sin.text()).toContain("catalogo.badgePago");
    expect(sin.text()).not.toContain("catalogo.badgeEntitlement");

    const con = await montar([oferta({ limpieza_min: 15 })]);
    expect(con.text()).toContain("catalogo.badgePago");
    expect(con.text()).not.toContain("catalogo.badgeEntitlement");
    expect(con.text()).toContain("+15 min de preparación y limpieza");
  });

  it("uno con membresía lo dice aunque tenga márgenes", async () => {
    const w = await montar([
      oferta({ politica_reserva: "entitlement", limpieza_min: 10 }),
    ]);
    expect(w.text()).toContain("catalogo.badgeEntitlement");
    expect(w.text()).toContain("+10 min de preparación y limpieza");
  });

  it("arma un paquete con servicios del catálogo, en el orden en que se marcan", async () => {
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = await montar([
      oferta({ id: "o1", nombre: "Limpieza completa", incluye: [] }),
      oferta({ id: "o2", nombre: "Flúor", incluye: [] }),
      oferta({ id: "o3", nombre: "Limpieza", incluye: [] }),
    ]);
    await w.findAll('[data-prueba="configurar"]')[0]!.trigger("click");
    const incluye = w.get('[data-prueba="incluye"]');
    // Los demás servicios, no él mismo.
    expect(incluye.findAll('input[type="checkbox"]')).toHaveLength(2);
    await incluye.get('input[value="o3"]').setValue(true);
    await incluye.get('input[value="o2"]').setValue(true);
    await w
      .findAll("button")
      .find((b) => b.text() === "catalogo.guardar")!
      .trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/a/ofertas/o1",
      expect.objectContaining({ incluye: ["o3", "o2"] }),
    );
  });

  it("el paquete dice qué incluye y lo incluido no puede ser paquete", async () => {
    const w = await montar([
      oferta({ id: "o1", nombre: "Limpieza completa", incluye: ["o2"] }),
      oferta({ id: "o2", nombre: "Flúor", incluye: [] }),
    ]);
    expect(w.text()).toContain("Incluye Flúor");
    await w.findAll('[data-prueba="configurar"]')[1]!.trigger("click");
    const incluye = w.get('[data-prueba="incluye"]');
    expect(incluye.text()).toContain("Está incluido en Limpieza completa");
    expect(incluye.find('input[type="checkbox"]').exists()).toBe(false);
  });

  it("la foto del servicio se ve en la lista y se cambia al configurarlo", async () => {
    const w = await montar([
      oferta({ id: "o1", nombre: "Corte", foto_url: "/storage/corte.webp" }),
    ]);
    expect(
      w
        .find('[data-prueba="servicio"] img[src="/storage/corte.webp"]')
        .exists(),
    ).toBe(true);
    await w.findAll('[data-prueba="configurar"]')[0]!.trigger("click");
    const foto = w.get('[data-prueba="foto-servicio"]');
    expect(foto.find('img[src="/storage/corte.webp"]').exists()).toBe(true);
    expect(foto.text()).toContain("Quitar foto");
  });

  it("da de alta servicios en una línea: nombre, duración y precio", async () => {
    api.post.mockResolvedValue({ data: { data: [] } });
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="nuevo-servicio"]').trigger("click");
    const fila = w.get(
      '[data-prueba="filas-alta"] .ct-fila:not(.ct-fila-cabeza)',
    );
    await fila.get("input").setValue("Corte de cabello");
    await fila.get(".ct-precio input").setValue("250");
    await w.get('[data-prueba="guardar-alta"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/rapidas", {
      items: [
        {
          nombre: "Corte de cabello",
          duracion_minutos: 30,
          precio_minor: 25000,
        },
      ],
    });
  });

  it("con citas, pasar un servicio a «con bono» avisa que deja la página para agendar", async () => {
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="configurar"]').trigger("click");
    expect(w.find('[data-prueba="efecto-politica"]').exists()).toBe(false);

    await w.get('[data-prueba="politica-entitlement"]').setValue(true);
    expect(w.get('[data-prueba="efecto-politica"]').text()).toContain(
      "politicaCitas.dejaDeAparecer",
    );
    // Con citas, la duración se pide igual: la cita ocupa la agenda.
    expect(w.find('[id="duracion-o1"]').exists()).toBe(true);
  });
});
