export type AnalyticsValue = string | number | boolean | null;

export type AnalyticsProperties = Record<string, AnalyticsValue>;

interface Attribution {
  utm_source?: string;
  utm_medium?: string;
  utm_campaign?: string;
  utm_content?: string;
  utm_term?: string;
  referrer?: string;
}

declare global {
  interface Window {
    dataLayer?: Array<Record<string, unknown>>;
  }
}

const ATTRIBUTION_KEY = "turnouno_attribution";
const UTM_KEYS = [
  "utm_source",
  "utm_medium",
  "utm_campaign",
  "utm_content",
  "utm_term",
] as const;

function readAttribution(): Attribution {
  if (typeof window === "undefined") {
    return {};
  }

  try {
    const stored = window.sessionStorage.getItem(ATTRIBUTION_KEY);
    if (stored !== null) {
      return JSON.parse(stored) as Attribution;
    }

    const params = new URLSearchParams(window.location.search);
    const attribution: Attribution = {};
    for (const key of UTM_KEYS) {
      const value = params.get(key)?.trim();
      if (value) {
        attribution[key] = value.slice(0, 160);
      }
    }

    if (document.referrer) {
      try {
        attribution.referrer = new URL(document.referrer).hostname.slice(
          0,
          160,
        );
      } catch {
        attribution.referrer = "unknown";
      }
    }

    window.sessionStorage.setItem(ATTRIBUTION_KEY, JSON.stringify(attribution));
    return attribution;
  } catch {
    return {};
  }
}

function deliver(payload: Record<string, unknown>): void {
  if (typeof window === "undefined") {
    return;
  }

  window.dataLayer?.push(payload);
  window.dispatchEvent(
    new CustomEvent("turnouno:analytics", { detail: payload }),
  );

  const endpoint = import.meta.env.VITE_ANALYTICS_ENDPOINT as
    string | undefined;
  if (!endpoint) {
    return;
  }

  const body = JSON.stringify(payload);
  if (typeof navigator.sendBeacon === "function") {
    navigator.sendBeacon(
      endpoint,
      new Blob([body], { type: "application/json" }),
    );
    return;
  }

  void fetch(endpoint, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body,
    keepalive: true,
  }).catch(() => undefined);
}

export function trackEvent(
  name: string,
  properties: AnalyticsProperties = {},
): void {
  deliver({
    event: "turnouno_event",
    event_name: name,
    occurred_at: new Date().toISOString(),
    page_path: typeof window === "undefined" ? "" : window.location.pathname,
    ...readAttribution(),
    ...properties,
  });
}

export function trackPageView(path: string, title: string): void {
  trackEvent("page_view", { path, title });
}
