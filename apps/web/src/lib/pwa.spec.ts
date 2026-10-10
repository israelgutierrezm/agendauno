import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import InstalarApp from "@/components/InstalarApp.vue";

/*
| La app instalable (PWA) de cada negocio (ADR 0110): solo en su subdominio se enlaza
| su manifiesto (de la API, con su nombre, logo y color) y se ofrece instalarla; con su
| logo como ícono de inicio en iPhone. Nada de esto fuera del subdominio.
*/

const host = vi.hoisted(() => ({ subdominio: true }));
vi.mock("@/lib/tenant", async (original) => ({
  ...(await original<typeof import("@/lib/tenant")>()),
  enSubdominioDeEstudio: () => host.subdominio,
}));

beforeEach(() => {
  vi.resetModules();
  document.head.innerHTML = "";
  localStorage.clear();
  host.subdominio = true;
});
afterEach(() => vi.restoreAllMocks());

describe("PWA del negocio", () => {
  it("en su subdominio enlaza su manifiesto y, con sus datos, su ícono y su color", async () => {
    const pwa = await import("./pwa");
    pwa.instalarPwa();
    expect(
      document.head.querySelector('link[rel="manifest"]')?.getAttribute("href"),
    ).toBe("/api/v1/pwa/manifest.webmanifest");
    expect(
      document.head
        .querySelector('meta[name="apple-mobile-web-app-capable"]')
        ?.getAttribute("content"),
    ).toBe("yes");

    pwa.marcarPwa({
      nombre: "Fluō Pilates",
      logo_url: "https://agendauno.mx/storage/estudios/8/logo.png",
      color_marca: "#b03a2e",
    });
    expect(
      document.head
        .querySelector('meta[name="apple-mobile-web-app-title"]')
        ?.getAttribute("content"),
    ).toBe("Fluō Pilates");
    // Desde su propio origen.
    expect(
      document.head
        .querySelector('link[rel="apple-touch-icon"]')
        ?.getAttribute("href"),
    ).toBe("/storage/estudios/8/logo.png");
    expect(
      document.head
        .querySelector('meta[name="theme-color"]')
        ?.getAttribute("content"),
    ).toBe("#b03a2e");
  });

  it("fuera del subdominio de un negocio no hace nada", async () => {
    host.subdominio = false;
    const pwa = await import("./pwa");
    pwa.instalarPwa();
    pwa.marcarPwa({ nombre: "X" });
    expect(document.head.querySelector('link[rel="manifest"]')).toBeNull();
    expect(
      document.head.querySelector('meta[name="apple-mobile-web-app-title"]'),
    ).toBeNull();
  });

  it("ofrece instalarla con el aviso del navegador y recuerda «Ahora no»", async () => {
    const pwa = await import("./pwa");
    pwa.instalarPwa();
    const prompt = vi.fn().mockResolvedValue(undefined);
    const aviso = Object.assign(new Event("beforeinstallprompt"), {
      prompt,
      userChoice: Promise.resolve({ outcome: "accepted" as const }),
    });
    window.dispatchEvent(aviso);
    expect(pwa.instalacion.disponible).toBe(true);

    const { default: Componente } =
      await import("@/components/InstalarApp.vue");
    const vista = mount(Componente, {
      props: { negocio: "Fluō Pilates" },
      global: { plugins: [i18n] },
    });
    expect(vista.text()).toContain("Instala Fluō Pilates en tu teléfono");
    await vista.get("button.tu-btn-primario").trigger("click");
    await vi.waitFor(() => expect(prompt).toHaveBeenCalled());
    vista.unmount();

    // Instalada, ya no se vuelve a ofrecer en este navegador.
    expect(localStorage.getItem("tu.pwa.descartada")).toBe("1");
    localStorage.clear();

    // «Ahora no» también se recuerda.
    window.dispatchEvent(
      Object.assign(new Event("beforeinstallprompt"), {
        prompt,
        userChoice: Promise.resolve({ outcome: "dismissed" as const }),
      }),
    );
    const otra = mount(Componente, {
      props: { negocio: "Fluō Pilates" },
      global: { plugins: [i18n] },
    });
    await otra.get("button.tu-btn-fantasma").trigger("click");
    expect(otra.find('[data-prueba="instalar-app"]').exists()).toBe(false);
    expect(localStorage.getItem("tu.pwa.descartada")).toBe("1");
    otra.unmount();
  });

  it("sin el aviso del navegador (ni iPhone), no se muestra", () => {
    const vista = mount(InstalarApp, {
      props: { negocio: "Fluō" },
      global: { plugins: [i18n] },
    });
    expect(vista.find('[data-prueba="instalar-app"]').exists()).toBe(false);
    vista.unmount();
  });
});
