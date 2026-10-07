import { createPinia } from "pinia";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import PublicShell from "@/components/PublicShell.vue";
import LandingView from "@/views/LandingView.vue";
import ModalidadView from "@/views/ModalidadView.vue";
import SolucionView from "@/views/SolucionView.vue";
import { i18n } from "@/i18n";
import { rutasComerciales } from "@/router/comerciales";
export {
  paginasMarketing,
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
      // Las mismas rutas comerciales (nombre, meta y props) que el router de la app.
      ...rutasComerciales({
        landing: LandingView,
        modalidad: ModalidadView,
        solucion: SolucionView,
      }),
      {
        path: "/aviso-de-privacidad",
        name: "aviso-privacidad",
        component: { render: () => null },
      },
      // Destinos de los enlaces por nombre: aquí no se renderizan.
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
  // Pinia vacía: la barra pública lee la sesión (ADR 0085) y aquí nunca la hay.
  app.use(router).use(i18n).use(createPinia());
  await router.push(path);
  await router.isReady();
  const context: { modules?: Set<string> } = {};
  const html = await renderToString(app, context);
  return { html, modules: [...(context.modules ?? [])] };
}
