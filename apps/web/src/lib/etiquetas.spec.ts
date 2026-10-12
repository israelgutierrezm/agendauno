import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/*
| Etiquetas de GA4 y Meta (ADR 0143): nada se carga sin la autorización; una sola
| vista de página por navegación; cada negocio con sus cookies; en el panel no se
| mide; retirar la autorización apaga y borra; los identificadores con que viaja un
| registro son solo los permitidos.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));

import {
  elegir,
  eventoComercio,
  medicion,
  medicionPara,
  navegar,
  reenviarEvento,
  reiniciarEtiquetas,
  rutaParaMedir,
} from "./etiquetas";

type Llamada = unknown[];
const llamadasGtag = (): Llamada[] =>
  (window.dataLayer ?? []).map((a) => Array.from(a as unknown as Llamada));
const scripts = () =>
  Array.from(document.querySelectorAll("script")).map((s) => s.src);

function destinos(ga4: string | null, meta: string | null): void {
  api.get.mockResolvedValue({ data: { data: { ga4, meta } } });
}

beforeEach(() => {
  reiniciarEtiquetas();
  vi.clearAllMocks();
  api.post.mockResolvedValue({});
  window.localStorage.clear();
  window.sessionStorage.clear();
  document.head.innerHTML = "";
  delete window.gtag;
  delete window.fbq;
  delete window._fbq;
  window.dataLayer = undefined;
});
afterEach(() => {
  document.cookie
    .split(";")
    .forEach(
      (c) =>
        (document.cookie = `${c.split("=")[0]!.trim()}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`),
    );
});

describe("etiquetas", () => {
  it("sin elegir no se carga nada; el aviso sabe que hay destinos", async () => {
    destinos("G-PLATAFORMA1", "1234567890");
    await navegar("plataforma:agendauno", "/", "AgendaUno");

    expect(api.get).toHaveBeenCalledWith("/api/v1/medicion/agendauno");
    expect(medicion.destinos).toEqual({
      ga4: "G-PLATAFORMA1",
      meta: "1234567890",
    });
    expect(medicion.preferencias).toBeNull();
    expect(scripts()).toEqual([]);
    expect(window.gtag).toBeUndefined();
  });

  it("con analítica: GA4 con todo negado de inicio, sin vista automática y una vista por navegación", async () => {
    destinos("G-PLATAFORMA1", "1234567890");
    await navegar(
      "plataforma:agendauno",
      "/?utm_source=ig&email=a@b.mx",
      "AgendaUno",
    );
    elegir({ analitica: true, publicidad: false });

    expect(scripts()).toEqual([
      "https://www.googletagmanager.com/gtag/js?id=G-PLATAFORMA1",
    ]);
    const llamadas = llamadasGtag();
    expect(llamadas[0]).toEqual([
      "consent",
      "default",
      {
        ad_storage: "denied",
        ad_user_data: "denied",
        ad_personalization: "denied",
        analytics_storage: "denied",
      },
    ]);
    expect(llamadas).toContainEqual([
      "consent",
      "update",
      {
        analytics_storage: "granted",
        ad_storage: "denied",
        ad_user_data: "denied",
        ad_personalization: "denied",
      },
    ]);
    expect(llamadas).toContainEqual([
      "config",
      "G-PLATAFORMA1",
      { send_page_view: false, debug_mode: true },
    ]);
    const vistas = () => llamadasGtag().filter((l) => l[1] === "page_view");
    // La de la página actual, al aceptar; sin la query que no es de campaña.
    expect(vistas()).toHaveLength(1);
    expect(vistas()[0]![2]).toMatchObject({
      send_to: "G-PLATAFORMA1",
      page_location: "http://localhost:3000/?utm_source=ig",
    });
    // Sin publicidad, el píxel no se carga.
    expect(window.fbq).toBeUndefined();

    await navegar("plataforma:agendauno", "/precios", "Precios");
    expect(vistas()).toHaveLength(2);
    // Su preferencia viaja al servidor con su id de visitante.
    expect(api.post).toHaveBeenCalledWith("/api/v1/medicion/consentimiento", {
      visitante: expect.stringMatching(/^[a-f0-9]{32}$/),
      analitica: true,
      publicidad: false,
    });
  });

  it("con publicidad: el píxel sin eventos automáticos y los resultados con el id del servidor", async () => {
    destinos(null, "1234567890");
    await navegar("plataforma:agendauno", "/registro", "Registro");
    elegir({ analitica: false, publicidad: true });

    const fbq = window.fbq!;
    expect(fbq.disablePushState).toBe(true);
    expect(fbq.queue).toContainEqual([
      "set",
      "autoConfig",
      false,
      "1234567890",
    ]);
    expect(fbq.queue).toContainEqual(["init", "1234567890"]);
    expect(fbq.queue).toContainEqual(["trackSingle", "1234567890", "PageView"]);

    reenviarEvento("tenant_created", {
      evento_negocio_creado: "negocio_creado-01ABC",
    });
    expect(fbq.queue).toContainEqual([
      "trackSingle",
      "1234567890",
      "CompleteRegistration",
      {},
      { eventID: "negocio_creado-01ABC" },
    ]);
  });

  it("en un negocio: cookies propias, sin píxel en el navegador y nada en su panel", async () => {
    destinos("G-NEGOCIO123", "1234567890");
    await navegar("negocio:grecon", "/estudio/grecon", "Grecon");
    elegir({ analitica: true, publicidad: true });

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/grecon/medicion");
    expect(medicion.destinos?.meta).toBeNull();
    expect(llamadasGtag()).toContainEqual([
      "config",
      "G-NEGOCIO123",
      {
        send_page_view: false,
        cookie_prefix: "n_grecon",
        cookie_domain: "none",
        debug_mode: true,
      },
    ]);
    expect(window.fbq).toBeUndefined();

    // En su panel o su cuenta, GA4 queda apagado.
    await navegar("negocio:grecon", null, "Mi cuenta");
    expect(medicion.pausada).toBe(true);
    expect(
      (window as unknown as Record<string, boolean>)["ga-disable-G-NEGOCIO123"],
    ).toBe(true);
  });

  it("retirar la autorización apaga GA4, borra sus cookies y lo avisa", async () => {
    destinos("G-PLATAFORMA1", null);
    await navegar("plataforma:agendauno", "/", "AgendaUno");
    elegir({ analitica: true, publicidad: false });
    document.cookie = "_ga=GA1.1.123.456; path=/";

    elegir({ analitica: false, publicidad: false });

    expect(
      (window as unknown as Record<string, boolean>)[
        "ga-disable-G-PLATAFORMA1"
      ],
    ).toBe(true);
    expect(document.cookie).not.toContain("_ga=");
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/medicion/consentimiento",
      expect.objectContaining({ analitica: false, publicidad: false }),
    );
  });

  it("con un registro viajan solo los identificadores permitidos", async () => {
    destinos("G-NEGOCIO123", null);
    await navegar("negocio:grecon", "/estudio/grecon", "Grecon");
    expect(await medicionPara("negocio:grecon")).toBeNull();

    elegir({ analitica: false, publicidad: false });
    // Lo cargado no se usa en la cuenta; la cookie del negocio da su client_id.
    reiniciarEtiquetas();
    destinos("G-NEGOCIO123", null);
    window.localStorage.setItem(
      "tu.preferencias.negocio:grecon",
      JSON.stringify({
        analitica: true,
        publicidad: false,
        en: "2026-10-12T00:00:00Z",
        version: 1,
      }),
    );
    document.cookie = "n_grecon_ga=GA1.1.987654321.1700000000; path=/";
    document.cookie = "_fbp=fb.1.1700000000000.1; path=/";

    expect(await medicionPara("negocio:grecon")).toEqual({
      analitica: true,
      publicidad: false,
      url: "http://localhost:3000/",
      ga_client_id: "987654321.1700000000",
    });
  });

  it("los planes que cargan antes que la configuración se miden al saberla; sin nombres en salud", async () => {
    window.localStorage.setItem(
      "tu.preferencias.negocio:grecon",
      JSON.stringify({
        analitica: true,
        publicidad: false,
        en: "2026-10-12T00:00:00Z",
        version: 1,
      }),
    );
    let responder: (v: unknown) => void = () => undefined;
    api.get.mockReturnValue(new Promise((r) => (responder = r)));
    const navegando = navegar("negocio:grecon", "/estudio/grecon", "Grecon");
    // La página ya trae sus planes; la configuración aún no llega.
    eventoComercio(
      "view_item_list",
      [{ id: "p1", nombre: "Consulta", precio_minor: 50000, moneda: "MXN" }],
      false,
    );
    responder({ data: { data: { ga4: "G-NEGOCIO123", meta: null } } });
    await navegando;

    const lista = llamadasGtag().find((l) => l[1] === "view_item_list");
    expect(lista?.[2]).toEqual({
      send_to: "G-NEGOCIO123",
      items: [{ item_id: "p1", price: 500, quantity: 1 }],
      currency: "MXN",
    });

    eventoComercio("begin_checkout", [
      { id: "s1", nombre: "Corte", precio_minor: 25000, moneda: "MXN" },
    ]);
    expect(
      llamadasGtag().find((l) => l[1] === "begin_checkout")?.[2],
    ).toMatchObject({
      value: 250,
      currency: "MXN",
      items: [{ item_id: "s1", item_name: "Corte", price: 250, quantity: 1 }],
    });
  });

  it("la ruta que se mide no lleva más que la campaña", () => {
    expect(
      rutaParaMedir(
        "/agendar/grecon?pagar=01ABC&utm_campaign=otono&gclid=Cj0KCQjw123&token=x",
      ),
    ).toBe("/agendar/grecon?utm_campaign=otono&gclid=Cj0KCQjw123");
  });
});
