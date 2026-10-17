import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { margenesServicio, validacion } from "@/i18n/locales/gestion.es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import CatalogoView from "./CatalogoView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
const sesion = vi.hoisted(() => ({
  slug: "a",
  puede: () => true,
  esCitas: true,
  moneda: "MXN",
  pais: "MX",
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

beforeEach(() => {
  sesion.moneda = "MXN";
  sesion.pais = "MX";
  sesion.esCitas = true;
});

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
          messages: { es: { margenesServicio, perfilPublico, validacion } },
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

  it("una clase dice si es para principiantes, qué saber y qué llevar", async () => {
    sesion.esCitas = false;
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = await montar([
      oferta({
        nombre: "Pole Fit",
        politica_reserva: "entitlement",
        precio_clase_minor: null,
        capacidad: 8,
        requisitos: "Ninguno",
      }),
    ]);
    await w.get('[data-prueba="configurar"]').trigger("click");
    expect((w.get("#c-req-o1").element as HTMLInputElement).value).toBe(
      "Ninguno",
    );
    await w.get('[data-prueba="para-principiantes"] input').setValue(true);
    await w.get("#c-llevar-o1").setValue("  Short y top  ");
    await w
      .findAll("button")
      .find((b) => b.text() === "catalogo.guardar")!
      .trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenLastCalledWith(
      "/api/v1/app/a/ofertas/o1",
      expect.objectContaining({
        para_principiantes: true,
        requisitos: "Ninguno",
        que_llevar: "Short y top",
      }),
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

  it("en Colombia el precio del alta se lee como allá: «25.000» son veinticinco mil", async () => {
    sesion.pais = "CO";
    sesion.moneda = "COP";
    api.post.mockResolvedValue({ data: { data: [] } });
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="nuevo-servicio"]').trigger("click");
    const fila = w.get(
      '[data-prueba="filas-alta"] .ct-fila:not(.ct-fila-cabeza)',
    );
    expect(fila.get(".ct-precio span").text()).toBe("$");
    await fila.get("input").setValue("Corte de cabello");
    await fila.get('[data-prueba="precio-alta"]').setValue("25.000");
    await w.get('[data-prueba="guardar-alta"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/rapidas", {
      items: [
        expect.objectContaining({
          nombre: "Corte de cabello",
          precio_minor: 2500000,
        }),
      ],
    });
  });

  it("un precio que se presta a confusión se marca y no deja guardar", async () => {
    api.post.mockClear();
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="nuevo-servicio"]').trigger("click");
    const fila = w.get(
      '[data-prueba="filas-alta"] .ct-fila:not(.ct-fila-cabeza)',
    );
    await fila.get("input").setValue("Corte de cabello");
    // En México, «12,50» no se puede leer sin adivinar.
    await fila.get('[data-prueba="precio-alta"]').setValue("12,50");
    expect(w.get('[data-prueba="precio-ilegible"]').text()).toBe(
      "No entendimos este precio. Escríbelo así: 1,250.50.",
    );
    expect(
      w.get('[data-prueba="guardar-alta"]').attributes("disabled"),
    ).toBeDefined();

    await fila.get('[data-prueba="precio-alta"]').setValue("12.50");
    expect(w.find('[data-prueba="precio-ilegible"]').exists()).toBe(false);
    await w.get('[data-prueba="guardar-alta"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/rapidas", {
      items: [expect.objectContaining({ precio_minor: 1250 })],
    });
  });

  it("los precios llevan el símbolo y los números de la moneda del negocio", async () => {
    sesion.pais = "ES";
    sesion.moneda = "EUR";
    const w = await montar([oferta({ precio_clase_minor: 125050 })]);
    expect(
      w.get('[data-prueba="servicio"]').text().replace(/\s/g, " "),
    ).toContain("1250,50 €");
    await w.get('[data-prueba="configurar"]').trigger("click");
    expect(w.get('[data-prueba="simbolo-precio"]').text()).toBe("€");
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

  it("al configurar, el precio se lee como en el país y el error queda en el diálogo", async () => {
    sesion.pais = "ES";
    sesion.moneda = "EUR";
    api.put.mockReset();
    api.put.mockRejectedValueOnce("No se pudo guardar");
    const w = await montar([oferta({ precio_clase_minor: 125050 })]);
    await w.get('[data-prueba="configurar"]').trigger("click");
    const precio = w.get('[data-prueba="precio-edicion"]');
    expect((precio.element as HTMLInputElement).value).toBe("1250,50");

    // «12.50» en España no se puede leer sin adivinar: se marca y no se guarda.
    await precio.setValue("12.50");
    expect(w.find('[data-prueba="precio-edicion-ilegible"]').exists()).toBe(
      true,
    );
    const guardar = () =>
      w.findAll("button").find((b) => b.text() === "catalogo.guardar")!;
    expect(guardar().attributes("disabled")).toBeDefined();

    await precio.setValue("12,50");
    await guardar().trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/a/ofertas/o1",
      expect.objectContaining({ precio_clase_minor: 1250 }),
    );
    expect(w.get('[data-prueba="error-edicion"]').text()).toBe(
      "No se pudo guardar",
    );
  });

  it("una fila con nombre y sin precio se marca en lugar de perderse al guardar", async () => {
    api.post.mockReset();
    api.post.mockResolvedValue({ data: { data: [] } });
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="nuevo-servicio"]').trigger("click");
    await w
      .findAll("button")
      .find((b) => b.text().includes("otraFila"))!
      .trigger("click");
    const filas = w.findAll(
      '[data-prueba="filas-alta"] .ct-fila:not(.ct-fila-cabeza)',
    );
    await filas[0]!.get("input").setValue("Corte");
    await filas[0]!.get('[data-prueba="precio-alta"]').setValue("250");
    await filas[1]!.get("input").setValue("Barba");

    await w.get('[data-prueba="guardar-alta"]').trigger("click");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();
    expect(w.get('[data-prueba="error-alta"]').exists()).toBe(true);
    expect(w.findAll('[data-prueba="fila-incompleta"]')).toHaveLength(1);
    expect(
      w.findAll('[data-prueba="precio-alta"]')[1]!.attributes("aria-invalid"),
    ).toBe("true");

    await w.findAll('[data-prueba="precio-alta"]')[1]!.setValue("150");
    await w.get('[data-prueba="guardar-alta"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/rapidas", {
      items: [
        expect.objectContaining({ nombre: "Corte", precio_minor: 25000 }),
        expect.objectContaining({ nombre: "Barba", precio_minor: 15000 }),
      ],
    });
  });

  it("un catálogo grande se busca y se ve por páginas", async () => {
    const w = await montar([
      ...Array.from({ length: 24 }, (_, i) =>
        oferta({ id: `o${i}`, nombre: `Servicio ${i}`, incluye: [] }),
      ),
      oferta({ id: "lim", nombre: "Limpieza dental", incluye: [] }),
    ]);
    expect(w.findAll('[data-prueba="servicio"]')).toHaveLength(20);
    // La paginación (aquí los textos van como claves).
    expect(w.text()).toContain("tabla.mostrando");

    await w.get('[data-prueba="buscar-servicio"]').setValue("limpieza");
    const filas = w.findAll('[data-prueba="servicio"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("Limpieza dental");
  });

  it("corrige el nombre y los lugares por clase; un cupo imposible no deja guardar", async () => {
    sesion.esCitas = false;
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = await montar([
      oferta({
        nombre: "Pole 1",
        modalidad: "grupal",
        capacidad: 12,
        politica_reserva: "entitlement",
        precio_clase_minor: null,
      }),
    ]);
    await w.get('[data-prueba="configurar"]').trigger("click");
    const guardar = () =>
      w.findAll("button").find((b) => b.text() === "catalogo.guardar")!;

    await w.get('[data-prueba="nombre-servicio"]').setValue("Pole Nivel 1");
    await w.get('[data-prueba="cupo-base"]').setValue("0");
    expect(guardar().attributes("disabled")).toBeDefined();

    await w.get('[data-prueba="cupo-base"]').setValue("15");
    await guardar().trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/a/ofertas/o1",
      expect.objectContaining({ nombre: "Pole Nivel 1", capacidad: 15 }),
    );
  });

  it("una cita no manda cupo, y sin nombre no se guarda", async () => {
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = await montar([oferta({})]);
    await w.get('[data-prueba="configurar"]').trigger("click");
    expect(w.find('[data-prueba="cupo-base"]').exists()).toBe(false);
    const guardar = () =>
      w.findAll("button").find((b) => b.text() === "catalogo.guardar")!;

    await w.get('[data-prueba="nombre-servicio"]').setValue("  ");
    expect(guardar().attributes("disabled")).toBeDefined();
    await w.get('[data-prueba="nombre-servicio"]').setValue("Masaje relajante");
    await guardar().trigger("click");
    await flushPromises();
    const carga = api.put.mock.calls.at(-1)![1] as Record<string, unknown>;
    expect(carga.nombre).toBe("Masaje relajante");
    expect(carga).not.toHaveProperty("capacidad");
  });

  it("archiva con confirmación y lo archivado se ve aparte para volver a ofrecerlo", async () => {
    const confirmar = vi.spyOn(window, "confirm").mockReturnValue(true);
    api.post.mockResolvedValue({
      data: { data: {}, meta: { sesiones_futuras: 2 } },
    });
    const w = await montar([
      oferta({ id: "o1", nombre: "Masaje", incluye: [] }),
      oferta({
        id: "o2",
        nombre: "Facial viejo",
        archivado: true,
        incluye: [],
      }),
    ]);
    // Lo que se ofrece, primero; lo archivado, aparte.
    expect(w.findAll('[data-prueba="servicio"]')).toHaveLength(1);
    await w.get('[data-prueba="ver-archivados"]').trigger("click");
    const filas = w.findAll('[data-prueba="servicio"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("Facial viejo");
    await filas[0]!.get('[data-prueba="restaurar"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/o2/restaurar");

    // Archivar pregunta antes y avisa lo que ya estaba agendado.
    w.findAll("button")
      .find((b) => b.attributes("aria-pressed") === "false")
      ?.trigger("click");
    await flushPromises();
    await w.get('[data-prueba="configurar"]').trigger("click");
    await w.get('[data-prueba="archivar"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/a/ofertas/o1/archivar");
    confirmar.mockRestore();
  });
});

describe("catálogo según la modalidad", () => {
  it("en clases no ofrece armar paquetes (una clase no incluye otras)", async () => {
    sesion.esCitas = false;
    const w = await montar([
      oferta({ id: "o1", nombre: "Pilates", incluye: [] }),
      oferta({ id: "o2", nombre: "Yoga", incluye: [] }),
    ]);
    await w.findAll('[data-prueba="configurar"]')[0]!.trigger("click");
    expect(w.find('[data-prueba="incluye"]').exists()).toBe(false);
  });
});
