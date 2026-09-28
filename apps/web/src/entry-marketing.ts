import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import PublicShell from "@/components/PublicShell.vue";
import LandingView from "@/views/LandingView.vue";
import SolucionView from "@/views/SolucionView.vue";
import { i18n } from "@/i18n";
import { soluciones, rutaSolucion } from "@/marketing/soluciones";
export {
  rutasMarketing,
  seoParaRuta,
  renderSeoHead,
  SITE_URL,
} from "@/marketing/seoConfig";

/** Solo contenido comercial estático: nunca sesión, API ni datos de negocios. */
export async function render(
  path: string,
): Promise<{ html: string; modules: string[] }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "inicio", component: LandingView },
      { path: "/aviso-de-privacidad", component: { render: () => null } },
      ...soluciones.map((s) => ({
        path: rutaSolucion(s.slug),
        component: SolucionView,
        props: { slug: s.slug },
      })),
      ...["registro", "entrar", "directorio"].map((name) => ({
        path: name === "directorio" ? "/negocios" : `/${name}`,
        name,
        component: { render: () => null },
      })),
    ],
  });
  const app = createSSRApp({
    render: () => h(PublicShell, {}, { default: () => h(RouterView) }),
  });
  app.use(router).use(i18n);
  await router.push(path);
  await router.isReady();
  const context: { modules?: Set<string> } = {};
  const html = await renderToString(app, context);
  return { html, modules: [...(context.modules ?? [])] };
}
