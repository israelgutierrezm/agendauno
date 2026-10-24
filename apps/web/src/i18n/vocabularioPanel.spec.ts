import { describe, expect, it } from "vitest";

import { mensajesBase, SIN_ADAPTAR } from "@/i18n";

/**
 * En los textos del panel el público se llama «alumno/alumnos», la palabra canónica: el
 * adaptador (lib/terminologia.ts) la cambia por la del negocio (Alumno, Miembro, Socio,
 * Cliente, Paciente) con su artículo y su género. «Cliente» y «personas con perfil de…»
 * no se adaptan: escritas en un texto del panel salen tal cual, y en una academia se lee
 * «cliente» junto a «Alumnos». Quedan fuera las secciones que no se adaptan
 * (SIN_ADAPTAR: páginas públicas y cobro del SaaS) y, en esta lista, los textos en los
 * que «cliente» es lo correcto, cada grupo con su motivo.
 */
const PERMITIDAS = new Set([
  // CFDI: quien recibe la factura es el cliente del SAT (receptor), no el público del
  // negocio. Decisión del dueño.
  "datosFacturacion.ayudaFicha",
  "cancelarFactura.enEspera",
  "cancelarFactura.esperaAyuda",
  "cancelarFactura.enEsperaHecha",
  "cancelarFactura.regimen",
  "region.pais.fueraDeMexico",
  "region.avisos.facturacion",
  "region.noDisponible.facturacionDetalle",
  "suscripcion.timbres.ayuda",
  "suscripcion.timbres.pantalla.buscar",
  // Páginas públicas dentro de secciones del panel: ahí rigen los textos base (App.vue
  // pausa la terminología) y habla quien visita. El widget embebido en otra web y la
  // página para agendar una cita.
  "captacion.widgets.publico.reservarConCuenta",
  "captacion.widgets.publico.comprarConCuenta",
  "perfilPublico.agendar.datosCliente",
  "perfilPublico.agendar.sesionEquipo",
  // «cliente_ref» es el nombre de una columna del archivo que se importa.
  "importarCitas.reglaAlumnos",
]);

const PROHIBIDO = /(?<!\p{L})client[ea]s?(?!\p{L})|personas? con perfil/iu;
// Lo que el adaptador tampoco toca: los {marcadores} y los mensajes enlazados (@:clave).
const INTOCABLE = /\{[^}]*\}|@(?:\.\w+)?:[\w.]+/g;

function recorrerTextos(
  valor: unknown,
  ruta: string,
  salida: Map<string, string>,
): void {
  if (typeof valor === "string") {
    salida.set(ruta, valor);
  } else if (Array.isArray(valor)) {
    valor.forEach((v, i) => recorrerTextos(v, `${ruta}.${i}`, salida));
  } else if (valor !== null && typeof valor === "object") {
    for (const [clave, v] of Object.entries(valor)) {
      recorrerTextos(v, `${ruta}.${clave}`, salida);
    }
  }
}

const textosDelPanel = new Map<string, string>();
for (const [seccion, valor] of Object.entries(mensajesBase)) {
  if (!SIN_ADAPTAR.has(seccion)) {
    recorrerTextos(valor, seccion, textosDelPanel);
  }
}

function diceCliente(texto: string): boolean {
  return PROHIBIDO.test(texto.replace(INTOCABLE, ""));
}

describe("vocabulario del panel", () => {
  it("ningún texto del panel llama «cliente» al público", () => {
    const malos = [...textosDelPanel]
      .filter(([ruta, texto]) => !PERMITIDAS.has(ruta) && diceCliente(texto))
      .map(([ruta, texto]) => `${ruta}: ${texto}`);
    expect(malos).toEqual([]);
  });

  it("cada excepción de la lista existe y todavía la necesita", () => {
    const sobran = [...PERMITIDAS].filter((ruta) => {
      const texto = textosDelPanel.get(ruta);
      return texto === undefined || !diceCliente(texto);
    });
    expect(sobran).toEqual([]);
  });
});
