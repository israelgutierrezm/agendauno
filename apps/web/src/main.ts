import { createApp } from "vue";
import { createPinia } from "pinia";

import "./style.css";
import "./marketing/public-ui.css";
import App from "./App.vue";
import { i18n } from "./i18n";
import router from "./router";

const app = createApp(App).use(createPinia()).use(i18n).use(router);
// Conservar el HTML comercial visible mientras se resuelve la primera ruta.
void router.isReady().then(() => app.mount("#app"));
