import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { analyticsPath, trackEvent } from "./analytics";

describe("analytics", () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });
  beforeEach(() => {
    window.history.replaceState(
      {},
      "",
      "/?utm_source=instagram&utm_campaign=lanzamiento",
    );
    window.sessionStorage.clear();
    window.dataLayer = [];
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-09-21T18:00:00.000Z"));
  });

  it("envía eventos sin datos personales y conserva la atribución de campaña", () => {
    trackEvent("marketing_cta_clicked", {
      placement: "hero",
      destination: "register",
    });

    expect(window.dataLayer).toHaveLength(1);
    expect(window.dataLayer?.[0]).toMatchObject({
      event: "agendauno_event",
      event_name: "marketing_cta_clicked",
      page_path: "/",
      utm_source: "instagram",
      utm_campaign: "lanzamiento",
      placement: "hero",
      destination: "register",
    });
  });
  it("agrupa rutas sensibles sin identificadores ni tokens", () => {
    expect(
      analyticsPath("/activar/mi-negocio?email=privado&token=secreto"),
    ).toBe("/activar");
    expect(analyticsPath("/miembros/42")).toBe("/app");
    expect(analyticsPath("/estudio/negocio-privado")).toBe("/app");
    expect(analyticsPath("/software-para-pilates?utm_source=ig")).toBe(
      "/software-para-pilates",
    );
  });
  it("no interrumpe la operación si el proveedor lanza un error", () => {
    window.dataLayer = [];
    vi.spyOn(window.dataLayer, "push").mockImplementation(() => {
      throw new Error("Bloqueado");
    });
    expect(() => trackEvent("tenant_created")).not.toThrow();
  });
  it("intenta fetch si beacon rechaza el envío", () => {
    vi.stubEnv("VITE_ANALYTICS_ENDPOINT", "/eventos");
    Object.defineProperty(navigator, "sendBeacon", {
      value: vi.fn(() => false),
      configurable: true,
    });
    const request = vi.fn().mockResolvedValue({ ok: true });
    vi.stubGlobal("fetch", request);
    trackEvent("tenant_activated");
    expect(request).toHaveBeenCalledWith(
      "/eventos",
      expect.objectContaining({ method: "POST", keepalive: true }),
    );
  });
});
