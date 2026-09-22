import { beforeEach, describe, expect, it, vi } from "vitest";

import { trackEvent } from "./analytics";

describe("analytics", () => {
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
      event: "turnouno_event",
      event_name: "marketing_cta_clicked",
      page_path: "/",
      utm_source: "instagram",
      utm_campaign: "lanzamiento",
      placement: "hero",
      destination: "register",
    });
  });
});
