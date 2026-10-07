import { createApp } from "vue";
import { createPinia } from "pinia";

import "./style.css";
import "./marketing/public-ui.css";
import App from "./App.vue";
import { i18n } from "./i18n";
import { alPerderSesion } from "./lib/api";
import { instalarGuardaDeArrastre } from "./lib/archivos";
import { instalarReporteDeErrores } from "./lib/errores";
import { instalarRecargaPorVersion } from "./lib/recargaPorVersion";
import { enSubdominioDeEstudio } from "./lib/tenant";
import router from "./router";
import { useSesionTenantStore } from "./stores/sesionTenant";
import { useToastStore } from "./stores/toast";

const pinia = createPinia();
const app = createApp(App).use(pinia).use(i18n).use(router);
// Los errores que nadie atrapa llegan al monitoreo de la plataforma (ADR 0080).
instalarReporteDeErrores(app, {
  ruta: () => router.currentRoute.value.path,
  estudio: () => useSesionTenantStore(pinia).slug,
});
// Un archivo soltado fuera de una zona de carga no abre una pestaña nueva.
instalarGuardaDeArrastre();

// Tras publicar una versión, una pestaña abierta desde antes recarga una vez en la
// pantalla a la que iba (sus archivos viejos ya no existen).
instalarRecargaPorVersion(router, () =>
  useToastStore(pinia).mostrar("aviso", i18n.global.t("comun.versionNueva"), 0),
);

// El servidor ya no reconoce la sesión (la revocaron: baja, cambio de contraseña,
// cerró sesión en otro lado): se olvida aquí y, si estaba en una pantalla privada,
// se lleva a Entrar para volver a ella. Sin ciclos: limpia el token, así que la
// siguiente petición ya no lo lleva.
alPerderSesion(() => {
  const sesion = useSesionTenantStore(pinia);
  const slug = sesion.slug;
  sesion.olvidarSesion();
  const actual = router.currentRoute.value;
  if (actual.meta.requiereSesion !== true) {
    return;
  }
  useToastStore(pinia).aviso(i18n.global.t("validacion.sesion.terminada"));
  void router.replace({
    name: "entrar",
    query: {
      ...(slug !== null && !enSubdominioDeEstudio() ? { estudio: slug } : {}),
      volver: actual.fullPath,
    },
  });
});

// Conservar el HTML comercial visible mientras se resuelve la primera ruta. Si la
// primera navegación falla (y no se pudo recargar), se monta igual: mejor la app
// con su aviso que la página en blanco.
void router
  .isReady()
  .catch(() => undefined)
  .then(() => {
    app.mount("#app");
    // Al abrir no se pudo confirmar la sesión guardada (sin red, mantenimiento):
    // sigue guardada. En Entrar lo dice junto a «Reintentar»; en otra pantalla, aquí.
    const sesion = useSesionTenantStore(pinia);
    if (
      sesion.avisoSinConfirmar !== null &&
      router.currentRoute.value.name !== "entrar"
    ) {
      useToastStore(pinia).mostrar("aviso", sesion.avisoSinConfirmar, 10_000);
    }
  });
