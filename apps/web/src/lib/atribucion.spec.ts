import { beforeEach, describe, expect, it } from "vitest";

import {
  ambitoDeRuta,
  atribucionParaEnviar,
  capturarAtribucion,
  leerAtribucion,
  referenteExterno,
  valorDeCampana,
} from "./atribucion";

/*
| Atribución de campañas (ADR 0142): cada llegada con campaña se anota en su ámbito
| (el producto o cada negocio), no solo la primera lectura de la pestaña; el primer
| origen se queda y el último toque se reemplaza. Lo que parece un dato personal no se
| guarda y los identificadores de clic no salen sin autorización.
*/

const AHORA = new Date("2026-10-12T18:00:00.000Z");
const dias = (n: number) => new Date(AHORA.getTime() + n * 86_400_000);
const q = (texto: string) => new URLSearchParams(texto);

beforeEach(() => window.sessionStorage.clear());

describe("atribución", () => {
  it("anota el primer origen y reemplaza el último toque con cada campaña", () => {
    const ambito = "negocio:grecon" as const;
    capturarAtribucion(
      ambito,
      q("utm_source=google&utm_medium=cpc&utm_campaign=busqueda"),
      "",
      "grecon.agendauno.mx",
      AHORA,
    );
    // Navegar dentro, sin campaña, no cambia nada.
    capturarAtribucion(ambito, q(""), "", "grecon.agendauno.mx", dias(1));
    const despues = capturarAtribucion(
      ambito,
      q("utm_source=instagram&utm_campaign=octubre"),
      "",
      "grecon.agendauno.mx",
      dias(3),
    );

    expect(despues.primer_toque).toMatchObject({
      fuente: "google",
      medio: "cpc",
      campana: "busqueda",
      en: AHORA.toISOString(),
    });
    expect(despues.ultimo_toque).toMatchObject({
      fuente: "instagram",
      campana: "octubre",
      en: dias(3).toISOString(),
    });
    // Recargar la misma dirección no cambia la fecha del toque.
    expect(
      capturarAtribucion(
        ambito,
        q("utm_source=instagram&utm_campaign=octubre"),
        "",
        "grecon.agendauno.mx",
        dias(4),
      ).ultimo_toque?.en,
    ).toBe(dias(3).toISOString());
  });

  it("cada negocio y cada producto guardan lo suyo", () => {
    capturarAtribucion(
      "negocio:grecon",
      q("utm_source=ig&utm_campaign=pole"),
      "",
      "grecon.agendauno.mx",
      AHORA,
    );
    capturarAtribucion(
      "plataforma:agendauno",
      q("utm_source=google&utm_campaign=software"),
      "",
      "agendauno.mx",
      AHORA,
    );

    expect(leerAtribucion("negocio:grecon", AHORA).ultimo_toque?.campana).toBe(
      "pole",
    );
    expect(
      leerAtribucion("plataforma:agendauno", AHORA).ultimo_toque?.campana,
    ).toBe("software");
    expect(leerAtribucion("negocio:otro", AHORA)).toEqual({
      primer_toque: null,
      ultimo_toque: null,
    });
  });

  it("no guarda correos, teléfonos ni valores raros; el sitio de pago no es una campaña", () => {
    expect(valorDeCampana("ana@correo.mx")).toBeNull();
    expect(valorDeCampana("55 1234 5678")).toBeNull();
    expect(valorDeCampana("<script>")).toBeNull();
    expect(valorDeCampana("Primavera 2026")).toBe("Primavera 2026");
    expect(
      referenteExterno(
        "https://checkout.stripe.com/pay",
        "grecon.agendauno.mx",
      ),
    ).toBeNull();
    expect(
      referenteExterno("https://agendauno.mx/negocios", "grecon.agendauno.mx"),
    ).toBeNull();
    expect(
      referenteExterno("https://l.instagram.com/?u=x", "grecon.agendauno.mx"),
    ).toBe("l.instagram.com");
  });

  it("los identificadores de clic no salen del navegador (aún sin autorización de publicidad)", () => {
    capturarAtribucion(
      "negocio:grecon",
      q("utm_source=facebook&utm_campaign=otono&fbclid=IwAR0abcDEF123"),
      "",
      "grecon.agendauno.mx",
      AHORA,
    );
    expect(leerAtribucion("negocio:grecon", AHORA).ultimo_toque?.fbclid).toBe(
      "IwAR0abcDEF123",
    );
    const enviado = atribucionParaEnviar("negocio:grecon", AHORA);
    expect(enviado?.ultimo_toque).toEqual({
      fuente: "facebook",
      campana: "otono",
      en: AHORA.toISOString(),
    });
  });

  it("lo que pasó del tope se descarta; sin campaña vigente no se manda nada", () => {
    capturarAtribucion(
      "negocio:grecon",
      q("utm_source=ig&utm_campaign=viejo"),
      "",
      "grecon.agendauno.mx",
      AHORA,
    );
    expect(atribucionParaEnviar("negocio:grecon", dias(91))?.ultimo_toque).toBe(
      null,
    );
    expect(atribucionParaEnviar("negocio:grecon", dias(400))).toBeNull();
  });

  it("el ámbito sale de la ruta: el negocio por su slug o su subdominio, el producto en sus páginas", () => {
    const ruta = (r: Partial<Parameters<typeof ambitoDeRuta>[0]>) => ({
      params: {},
      query: {},
      meta: {},
      ...r,
    });
    expect(
      ambitoDeRuta(ruta({ params: { slug: "Grecon" } }), "localhost"),
    ).toBe("negocio:grecon");
    expect(ambitoDeRuta(ruta({}), "grecon.agendauno.mx")).toBe(
      "negocio:grecon",
    );
    expect(
      ambitoDeRuta(ruta({ query: { estudio: "demo" } }), "localhost"),
    ).toBe("negocio:demo");
    expect(
      ambitoDeRuta(ruta({ meta: { marketing: true } }), "agendauno.mx"),
    ).toBe("plataforma:agendauno");
    expect(ambitoDeRuta(ruta({ name: "registro" }), "agendauno.mx")).toBe(
      "plataforma:agendauno",
    );
    // El panel no es un ámbito de campaña.
    expect(ambitoDeRuta(ruta({ name: "miembros" }), "app.agendauno.mx")).toBe(
      null,
    );
  });
});
