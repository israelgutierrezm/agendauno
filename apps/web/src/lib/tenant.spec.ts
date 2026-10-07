import { describe, expect, it } from "vitest";

import {
  DOMINIO_PUBLICO,
  esDominioPrincipal,
  origenDominioRaiz,
  slugDeContexto,
  slugDeSubdominio,
  urlEnSubdominioDelNegocio,
  urlEntrarEnDominioRaiz,
  urlPublicaEstudio,
} from "./tenant";

describe("tenant / subdominio", () => {
  it("deriva el slug de un subdominio de estudio (prod y dev)", () => {
    expect(slugDeSubdominio(`barberia.${DOMINIO_PUBLICO}`)).toBe("barberia");
    expect(slugDeSubdominio(`barberia.${DOMINIO_PUBLICO}:443`)).toBe(
      "barberia",
    );
    expect(slugDeSubdominio("mi-estudio.localhost")).toBe("mi-estudio");
  });

  it("el dominio raíz y los subdominios reservados no son un estudio", () => {
    expect(slugDeSubdominio(DOMINIO_PUBLICO)).toBeNull();
    expect(slugDeSubdominio(`www.${DOMINIO_PUBLICO}`)).toBeNull();
    expect(slugDeSubdominio(`app.${DOMINIO_PUBLICO}`)).toBeNull();
    expect(slugDeSubdominio("localhost")).toBeNull();
    // Solo un nivel de subdominio (evita hosts anidados inesperados).
    expect(slugDeSubdominio(`a.b.${DOMINIO_PUBLICO}`)).toBeNull();
  });

  it("el contexto admite el subdominio o el fallback dev ?estudio=", () => {
    expect(slugDeContexto(`barberia.${DOMINIO_PUBLICO}`, "")).toBe("barberia");
    // En localhost sin subdominio, ?estudio= simula el estudio en contexto.
    expect(slugDeContexto("localhost", "?estudio=barberia")).toBe("barberia");
    expect(slugDeContexto("localhost", "")).toBeNull();
    // El subdominio real tiene prioridad sobre el query.
    expect(slugDeContexto(`otra.${DOMINIO_PUBLICO}`, "?estudio=barberia")).toBe(
      "otra",
    );
  });

  it("construye la URL pública corta del estudio", () => {
    expect(urlPublicaEstudio("barberia")).toBe(`barberia.${DOMINIO_PUBLICO}`);
  });
});

describe("dominio raíz desde el subdominio de un negocio", () => {
  const prod = {
    protocol: "https:",
    hostname: `barberia.${DOMINIO_PUBLICO}`,
    port: "",
  };
  const dev = {
    protocol: "http:",
    hostname: "barberia.localhost",
    port: "5175",
  };

  it("quita el subdominio y conserva protocolo y puerto", () => {
    expect(origenDominioRaiz(prod)).toBe(`https://${DOMINIO_PUBLICO}`);
    expect(origenDominioRaiz(dev)).toBe("http://localhost:5175");
    // Fuera de un subdominio de negocio, el origen actual.
    expect(
      origenDominioRaiz({
        protocol: "https:",
        hostname: DOMINIO_PUBLICO,
        port: "",
      }),
    ).toBe(`https://${DOMINIO_PUBLICO}`);
  });

  it("arma «Entrar» de ese negocio en el dominio raíz, con la ruta interna a la que volver", () => {
    expect(urlEntrarEnDominioRaiz("barberia", null, prod)).toBe(
      `https://${DOMINIO_PUBLICO}/entrar?estudio=barberia`,
    );
    expect(urlEntrarEnDominioRaiz("barberia", "/mi-perfil", dev)).toBe(
      "http://localhost:5175/entrar?estudio=barberia&volver=%2Fmi-perfil",
    );
    // Solo rutas internas: nunca otro sitio.
    expect(urlEntrarEnDominioRaiz("barberia", "//otro.sitio", prod)).toBe(
      `https://${DOMINIO_PUBLICO}/entrar?estudio=barberia`,
    );
  });
});

describe("los negocios viven en su subdominio", () => {
  const ruta = (name: string, slug: string, fullPath: string) => ({
    name,
    params: { slug },
    fullPath,
  });

  it("solo el dominio principal (con o sin www) es el dominio principal", () => {
    expect(esDominioPrincipal(DOMINIO_PUBLICO)).toBe(true);
    expect(esDominioPrincipal(`www.${DOMINIO_PUBLICO}`)).toBe(true);
    expect(esDominioPrincipal(`WWW.${DOMINIO_PUBLICO}:443`)).toBe(true);
    expect(esDominioPrincipal(`barberia.${DOMINIO_PUBLICO}`)).toBe(false);
    expect(esDominioPrincipal("localhost")).toBe(false);
    expect(esDominioPrincipal("barberia.localhost")).toBe(false);
  });

  it("en el dominio principal, el enlace corto y la página del negocio van a su subdominio", () => {
    for (const host of [DOMINIO_PUBLICO, `www.${DOMINIO_PUBLICO}`]) {
      expect(
        urlEnSubdominioDelNegocio(
          ruta("estudio-corto", "barberia", "/barberia"),
          host,
        ),
      ).toBe(`https://barberia.${DOMINIO_PUBLICO}/`);
      // La página del negocio sigue en /estudio/{slug} (no en la raíz, que en un
      // negocio de citas lleva directo a agendar), con la query y el ancla.
      expect(
        urlEnSubdominioDelNegocio(
          ruta(
            "estudio-publico",
            "Barberia",
            "/estudio/Barberia?utm_source=ig#horarios",
          ),
          host,
        ),
      ).toBe(
        `https://barberia.${DOMINIO_PUBLICO}/estudio/barberia?utm_source=ig#horarios`,
      );
      expect(
        urlEnSubdominioDelNegocio(
          ruta("estudio-publico", "foo", "/estudio/foo?x=1#precios"),
          host,
        ),
      ).toBe(`https://foo.${DOMINIO_PUBLICO}/estudio/foo?x=1#precios`);
      // Con el resto de la ruta: sus enlaces viven en /enlaces del subdominio.
      expect(
        urlEnSubdominioDelNegocio(
          ruta("enlaces-estudio", "barberia", "/barberia/enlaces?x=1"),
          host,
        ),
      ).toBe(`https://barberia.${DOMINIO_PUBLICO}/enlaces?x=1`);
    }
  });

  it("no aplica en desarrollo, en el subdominio, en otras rutas ni con un slug que no es subdominio", () => {
    const corto = ruta("estudio-corto", "barberia", "/barberia");
    expect(urlEnSubdominioDelNegocio(corto, "localhost")).toBeNull();
    expect(urlEnSubdominioDelNegocio(corto, "127.0.0.1")).toBeNull();
    expect(
      urlEnSubdominioDelNegocio(corto, `barberia.${DOMINIO_PUBLICO}`),
    ).toBeNull();
    // /agendar/:slug no cambia: la generan el API y los correos.
    expect(
      urlEnSubdominioDelNegocio(
        ruta("agendar-cita", "barberia", "/agendar/barberia"),
        DOMINIO_PUBLICO,
      ),
    ).toBeNull();
    for (const slug of ["www", "api", "a.b", "//otro.sitio", "-x", ""]) {
      expect(
        urlEnSubdominioDelNegocio(
          ruta("estudio-corto", slug, `/${slug}`),
          DOMINIO_PUBLICO,
        ),
      ).toBeNull();
    }
  });
});
