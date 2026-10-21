import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";

import { i18n } from "@/i18n";
import PresupuestoEnLineaView from "./PresupuestoEnLineaView.vue";

/*
| Presupuesto en línea desde el enlace del correo: verlo, aceptarlo (se vuelve su cobro),
| rechazarlo con motivo y pagarlo; sin token o con uno viejo, «enlace no válido».
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));

const URL = "/api/v1/app/barberia/presupuestos-en-linea/P1";

function presupuesto(cambios: Record<string, unknown> = {}) {
  return {
    id: "P1",
    folio: "P-0007",
    estado: "enviado",
    vencido: false,
    valido_hasta: "2026-10-16",
    revision: 2,
    cliente: "Lalo",
    sucursal: { id: "S1", nombre: "Centro" },
    moneda: "MXN",
    impuestos: "incluidos",
    tasa_impuesto: "16 %",
    subtotal_minor: 95000,
    descuento_minor: 0,
    impuesto_minor: 13103,
    total_minor: 95000,
    notas: null,
    respondido_en: null,
    respondido_por: null,
    lineas: [
      {
        tipo: "servicio",
        descripcion: "Facial",
        cantidad: 3,
        precio_unitario_minor: 25000,
        descuento_minor: 0,
        subtotal_minor: 75000,
      },
      {
        tipo: "libre",
        descripcion: "Crema",
        cantidad: 1,
        precio_unitario_minor: 20000,
        descuento_minor: 0,
        subtotal_minor: 20000,
      },
    ],
    servicios: [{ id: "O1", nombre: "Facial" }],
    puede_responder: true,
    orden: null,
    puede_pagar: false,
    ...cambios,
  };
}

async function montar(query = "?t=tok") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/presupuesto/:slug/:id", component: PresupuestoEnLineaView },
    ],
  });
  await router.push(`/presupuesto/barberia/P1${query}`);
  await router.isReady();
  const w = mount(PresupuestoEnLineaView, {
    global: { plugins: [i18n, router] },
  });
  await flushPromises();
  return w;
}

beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  api.get.mockImplementation((ruta: string) =>
    ruta.endsWith("/marca")
      ? Promise.resolve({
          data: { data: { nombre: "Barbería Centro", logo_url: null } },
        })
      : Promise.resolve({ data: { data: presupuesto() } }),
  );
});

describe("presupuesto en línea", () => {
  it("lo muestra, lo acepta con la revisión vista y luego lo paga en línea", async () => {
    const w = await montar();
    expect(api.get).toHaveBeenCalledWith(URL, { params: { t: "tok" } });
    expect(w.text()).toContain("Barbería Centro");
    expect(w.text()).toContain("Presupuesto P-0007");
    expect(w.get('[data-prueba="presupuesto-estado"]').text()).toBe(
      "Por responder",
    );
    expect(w.get('[data-prueba="presupuesto-total"]').text()).toContain(
      "950.00",
    );

    api.post.mockResolvedValueOnce({
      data: {
        data: presupuesto({
          estado: "convertido",
          puede_responder: false,
          respondido_por: "cliente",
          orden: { id: "OR1", estado: "pendiente" },
          puede_pagar: true,
        }),
      },
    });
    await w.get('[data-prueba="presupuesto-aceptar"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/aceptar`, {
      t: "tok",
      revision: 2,
    });
    expect(w.get('[data-prueba="presupuesto-estado"]').text()).toBe("Aceptado");
    // Sus servicios se agendan con la sede y el servicio ya elegidos.
    expect(
      w.get('[data-prueba="presupuesto-agendar"]').attributes("href"),
    ).toBe("/agendar/barberia?servicio=O1&sucursal=S1");

    const asignar = vi.fn();
    vi.stubGlobal("location", { ...window.location, assign: asignar });
    api.post.mockResolvedValueOnce({
      data: { data: { checkout: { url: "https://pago.test/c/1" } } },
    });
    await w.get('[data-prueba="presupuesto-pagar"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(`${URL}/pagar`, {
      t: "tok",
      idempotency_key: "presupuesto-P1-OR1",
    });
    expect(asignar).toHaveBeenCalledWith("https://pago.test/c/1");
    vi.unstubAllGlobals();
  });

  it("lo rechaza con su motivo", async () => {
    const w = await montar();
    api.post.mockResolvedValueOnce({
      data: {
        data: presupuesto({ estado: "rechazado", puede_responder: false }),
      },
    });
    await w.get('[data-prueba="presupuesto-rechazar"]').trigger("click");
    await w.get("textarea").setValue("Muy caro");
    await w
      .get('[data-prueba="presupuesto-confirmar-rechazo"]')
      .trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/rechazar`, {
      t: "tok",
      motivo: "Muy caro",
    });
    expect(w.get('[data-prueba="presupuesto-estado"]').text()).toBe(
      "Rechazado",
    );
  });

  it("al volver del pago usa el token guardado; sin token o con uno viejo, enlace no válido", async () => {
    await montar();
    vi.clearAllMocks();
    // Vuelve de la pasarela sin el token en la dirección.
    await montar("?pago=exito");
    expect(api.get).toHaveBeenCalledWith(URL, { params: { t: "tok" } });

    sessionStorage.clear();
    const sinToken = await montar("");
    expect(sinToken.find('[data-prueba="presupuesto-invalido"]').exists()).toBe(
      true,
    );

    api.get.mockRejectedValue(new Error("404"));
    const viejo = await montar("?t=viejo");
    expect(viejo.text()).toContain("Este enlace ya no es válido");
  });
});
