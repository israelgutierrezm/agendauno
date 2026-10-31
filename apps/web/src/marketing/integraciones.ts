/// <reference types="vite/client" />
import type { Producto } from "../lib/producto.ts";

/**
 * Lo que la parte comercial puede prometer (ADR 0234): las landings y su SEO solo
 * nombran lo comprobado en producción. Se decide al compilar (las landings se
 * pre-generan), con variables públicas documentadas en `web.env.example`:
 *
 * - `VITE_INTEGRACION_*=1`: esa integración pasó su caso real de docs/VERIFICACION-V1.md.
 *   Apagadas por omisión.
 * - `VITE_{PRODUCTO}_PLAY_URL` y `VITE_{PRODUCTO}_APP_STORE_URL`: los enlaces de la app
 *   en cada tienda. Vacíos por omisión: sin enlace no se habla de apps nativas y solo se
 *   dice que la página se instala como app (ADR 0110).
 *
 * Sin alias `@/` ni vue-i18n: lo importan `modalidades.ts` y `soluciones.ts`.
 */
export type Integracion =
  | "wellhub"
  | "totalpass"
  | "fitpass"
  | "bandeja_whatsapp"
  | "bandeja_instagram"
  | "bandeja_messenger"
  | "apple_wallet"
  | "google_wallet"
  | "google_calendar"
  | "outlook";

function variable(clave: string): string {
  // `import.meta.env` no existe fuera de Vite: de ahí el `?.`.
  const variables = import.meta.env as Record<string, unknown> | undefined;
  const valor = variables?.[clave];
  return typeof valor === "string" ? valor.trim() : "";
}

/** ¿Esa integración ya pasó su caso real con el proveedor? */
export function comprobada(integracion: Integracion): boolean {
  return ["1", "true", "si", "sí"].includes(
    variable(`VITE_INTEGRACION_${integracion.toUpperCase()}`).toLowerCase(),
  );
}

/** Los nombres de las comprobadas, en el orden dado. */
function nombresDe(
  nombres: readonly (readonly [Integracion, string])[],
): string[] {
  return nombres.filter(([i]) => comprobada(i)).map(([, nombre]) => nombre);
}

/** Con conexión directa: sus reservas llegan solas y el check-in se valida solo. */
export function plataformasConectadas(): string[] {
  return nombresDe([
    ["wellhub", "Wellhub"],
    ["totalpass", "TotalPass"],
  ]);
}
export function hayPlataformas(): boolean {
  return plataformasConectadas().length > 0 || comprobada("fitpass");
}
export function canalesDeBandeja(): string[] {
  return nombresDe([
    ["bandeja_whatsapp", "WhatsApp"],
    ["bandeja_instagram", "Instagram"],
    ["bandeja_messenger", "Messenger"],
  ]);
}
export function carteras(): string[] {
  return nombresDe([
    ["apple_wallet", "Apple Wallet"],
    ["google_wallet", "Google Wallet"],
  ]);
}
export function calendarios(): string[] {
  return nombresDe([
    ["google_calendar", "Google Calendar"],
    ["outlook", "Outlook"],
  ]);
}

/** «A», «A y B», «A, B y C» (con «e» u «u» cuando el último nombre lo pide). */
export function listaNatural(
  nombres: readonly string[],
  conjuncion: "y" | "o" = "y",
): string {
  const ultimo = nombres.at(-1);
  if (ultimo === undefined) {
    return "";
  }
  if (nombres.length === 1) {
    return ultimo;
  }
  const sinAcento = ultimo.replace(/^tu /, "").toLowerCase();
  const nexo =
    conjuncion === "y"
      ? sinAcento.startsWith("i")
        ? "e"
        : "y"
      : sinAcento.startsWith("o")
        ? "u"
        : "o";
  return `${nombres.slice(0, -1).join(", ")} ${nexo} ${ultimo}`;
}

export interface TiendasApp {
  play: string;
  appStore: string;
}

/** Solo un enlace https: lo demás cuenta como vacío. */
function enlace(clave: string): string {
  const valor = variable(clave);
  return valor.startsWith("https://") ? valor : "";
}

/** Los enlaces de la app de un producto en cada tienda (vacíos si no está publicada). */
export function tiendasDeApp(producto: Producto): TiendasApp {
  const prefijo = `VITE_${producto.toUpperCase()}`;
  return {
    play: enlace(`${prefijo}_PLAY_URL`),
    appStore: enlace(`${prefijo}_APP_STORE_URL`),
  };
}

/** ¿Se puede descargar la app del producto de alguna tienda? */
export function appPublicada(producto: Producto): boolean {
  const { play, appStore } = tiendasDeApp(producto);
  return play !== "" || appStore !== "";
}
