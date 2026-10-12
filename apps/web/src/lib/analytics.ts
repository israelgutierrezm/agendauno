import { ambitoEnUso, capturarAtribucion } from "@/lib/atribucion";
import { rutasMarketing } from "@/marketing/seoConfig";

export type AnalyticsValue = string | number | boolean | null;

export type AnalyticsProperties = Record<string, AnalyticsValue>;

declare global {
  interface Window {
    dataLayer?: Array<Record<string, unknown>>;
  }
}

/**
 * La campaña de la pantalla actual para los eventos (ADR 0142): el último toque del
 * ámbito (el producto o el negocio). Sin router (una página suelta), la de esta
 * dirección.
 */
function readAttribution(): Record<string, string> {
  if (typeof window === "undefined") {
    return {};
  }
  try {
    const { ultimo_toque: toque } = capturarAtribucion(
      ambitoEnUso(),
      new URLSearchParams(window.location.search),
      "",
      window.location.hostname,
    );
    if (toque === null) return {};
    const campos: Record<string, string | undefined> = {
      utm_source: toque.fuente,
      utm_medium: toque.medio,
      utm_campaign: toque.campana,
      utm_content: toque.contenido,
      utm_term: toque.termino,
      referrer: toque.referente,
    };
    return Object.fromEntries(
      Object.entries(campos).filter(
        (par): par is [string, string] => par[1] !== undefined,
      ),
    );
  } catch {
    return {};
  }
}

function deliver(payload: Record<string, unknown>): void {
  if (typeof window === "undefined") {
    return;
  }

  // Un proveedor bloqueado nunca debe impedir un registro o una activación.
  try {
    window.dataLayer?.push(payload);
  } catch {
    /* Medición opcional. */
  }
  window.dispatchEvent(
    new CustomEvent("agendauno:analytics", { detail: payload }),
  );

  const endpoint = import.meta.env.VITE_ANALYTICS_ENDPOINT as
    string | undefined;
  if (!endpoint) {
    return;
  }

  const body = JSON.stringify(payload);
  try {
    if (
      typeof navigator.sendBeacon === "function" &&
      navigator.sendBeacon(
        endpoint,
        new Blob([body], { type: "application/json" }),
      )
    )
      return;
  } catch {
    // Algunos navegadores bloquean beacon; se intenta fetch sin bloquear la UI.
  }

  try {
    void fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body,
      keepalive: true,
    }).catch(() => undefined);
  } catch {
    /* Incluso un transporte bloqueado es opcional. */
  }
}

export function analyticsPath(path: string): string {
  const clean = path.split(/[?#]/)[0]!.replace(/\/$/, "") || "/";
  if (
    rutasMarketing.includes(clean) ||
    ["/registro", "/entrar", "/negocios"].includes(clean)
  )
    return clean;
  if (/^\/activar(?:\/|$)/.test(clean)) return "/activar";
  return "/app";
}

export function trackEvent(
  name: string,
  properties: AnalyticsProperties = {},
): void {
  deliver({
    event: "agendauno_event",
    event_name: name,
    occurred_at: new Date().toISOString(),
    page_path:
      typeof window === "undefined"
        ? ""
        : analyticsPath(window.location.pathname),
    ...readAttribution(),
    ...properties,
  });
}

export function trackPageView(path: string, title: string): void {
  trackEvent("page_view", { path: analyticsPath(path), title });
}
