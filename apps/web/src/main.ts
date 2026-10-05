import { createApp } from "vue";
import { createPinia } from "pinia";

import "./style.css";
import "./marketing/public-ui.css";
import App from "./App.vue";
import { i18n } from "./i18n";
import { instalarGuardaDeArrastre } from "./lib/archivos";
import { instalarReporteDeErrores } from "./lib/errores";
import router from "./router";
import { useSesionTenantStore } from "./stores/sesionTenant";

const pinia = createPinia();
const app = createApp(App).use(pinia).use(i18n).use(router);
// Los errores que nadie atrapa llegan al monitoreo de la plataforma (ADR 0080).
instalarReporteDeErrores(app, {
  ruta: () => router.currentRoute.value.path,
  estudio: () => useSesionTenantStore(pinia).slug,
});
// Un archivo soltado fuera de una zona de carga no abre una pestaña nueva.
instalarGuardaDeArrastre();
// Conservar el HTML comercial visible mientras se resuelve la primera ruta.
void router.isReady().then(() => app.mount("#app"));
