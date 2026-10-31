import { afterEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import { TESTIMONIOS, testimoniosPublicables } from "./testimonios";
import TestimoniosLanding from "@/components/TestimoniosLanding.vue";

afterEach(() => {
  TESTIMONIOS.clases = [];
  TESTIMONIOS.citas = [];
  vi.unstubAllEnvs();
});

describe("publicación de testimonios", () => {
  it("no publica entradas sin autorización, ni borradores", () => {
    const real = {
      nombre: "Cliente de prueba",
      cargo: "Dirección",
      negocio: "Estudio",
      cita: "Una experiencia autorizada",
      autorizado: true,
      publicado: true,
    };
    TESTIMONIOS.clases = [
      real,
      { ...real, autorizado: false },
      { ...real, publicado: false },
      { ...real, cita: " " },
    ];
    expect(testimoniosPublicables("clases")).toEqual([real]);
    expect(testimoniosPublicables("citas")).toEqual([]);
  });
  it.each(["clases", "citas"] as const)(
    "%s: ?boceto=1 nunca muestra reseñas ficticias en producción",
    async (modo) => {
      vi.stubEnv("DEV", false);
      const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: "/", component: { template: "<div />" } }],
      });
      await router.push("/?boceto=1");
      await router.isReady();
      const wrapper = mount(TestimoniosLanding, {
        props: { modo },
        global: { plugins: [router] },
      });
      expect(wrapper.find('[data-prueba="testimonios-landing"]').exists()).toBe(
        false,
      );
      expect(wrapper.text()).not.toContain("ficticio");
      wrapper.unmount();
    },
  );
  it("marca los ejemplos de desarrollo como ficticios", async () => {
    vi.stubEnv("DEV", true);
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: "/", component: { template: "<div />" } }],
    });
    await router.push("/?boceto=1");
    await router.isReady();
    const wrapper = mount(TestimoniosLanding, {
      props: { modo: "clases" },
      global: { plugins: [router] },
    });
    expect(wrapper.text()).toContain("no testimonios reales");
    expect(wrapper.findAll("blockquote")).toHaveLength(3);
    wrapper.unmount();
  });
});
