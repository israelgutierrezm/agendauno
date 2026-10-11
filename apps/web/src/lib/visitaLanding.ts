import type { Producto } from "@/lib/producto";

/**
 * Cuenta una visita a la landing de un producto para el embudo de la plataforma (C08):
 * «landing → registro». Sin datos personales: el servidor solo guarda cuántas hubo por
 * día y producto (una por visitante y día). Va en `text/plain` para no pedir permiso
 * previo (CORS) y con `sendBeacon` para no frenar la página.
 */
export function contarVisitaLanding(producto: Producto): void {
  if (typeof window === "undefined" || typeof navigator === "undefined") {
    return;
  }
  const base =
    (import.meta.env.VITE_API_URL as string | undefined) ??
    "http://localhost:8000";
  const url = `${base}/api/v1/metricas/visita-landing`;
  const cuerpo = JSON.stringify({ producto });
  try {
    if (typeof navigator.sendBeacon === "function") {
      navigator.sendBeacon(url, new Blob([cuerpo], { type: "text/plain" }));
      return;
    }
    void fetch(url, {
      method: "POST",
      body: cuerpo,
      headers: { "Content-Type": "text/plain" },
      keepalive: true,
    }).catch(() => undefined);
  } catch {
    // Medir nunca rompe la página.
  }
}
