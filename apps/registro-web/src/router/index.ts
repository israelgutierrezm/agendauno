import { createRouter, createWebHistory } from "vue-router";

import LandingView from "@/views/LandingView.vue";
import { trackPageView } from "@/lib/analytics";
import { DEFAULT_SEO, updateSeo } from "@/lib/seo";
import { slugDeContexto } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

declare module "vue-router" {
  interface RouteMeta {
    requiereSesion?: boolean;
  }
}

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition;
    if (to.hash) return { el: to.hash, behavior: "smooth" };
    return { top: 0 };
  },
  routes: [
    { path: "/", name: "inicio", component: LandingView },
    {
      path: "/registro",
      name: "registro",
      component: () => import("@/views/RegistroView.vue"),
    },
    {
      path: "/directorio",
      name: "directorio",
      component: () => import("@/views/DirectorioView.vue"),
    },
    {
      path: "/estudio/:slug",
      name: "estudio-publico",
      component: () => import("@/views/EstudioPublicoView.vue"),
    },
    {
      // Agendar cita (público, guest): elegir servicio → persona → hueco → pagar.
      path: "/agendar/:slug",
      name: "agendar-cita",
      component: () => import("@/views/ReservarCitaView.vue"),
    },
    {
      path: "/activar/:slug?",
      name: "activar",
      component: () => import("@/views/ActivacionView.vue"),
    },
    {
      path: "/entrar",
      name: "entrar",
      component: () => import("@/views/EntrarView.vue"),
    },
    {
      path: "/panel",
      name: "panel",
      component: () => import("@/views/PanelView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/onboarding",
      name: "onboarding",
      component: () => import("@/views/OnboardingView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/miembros",
      name: "miembros",
      component: () => import("@/views/MiembrosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/miembros/:id",
      name: "ficha-miembro",
      component: () => import("@/views/FichaMiembroView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/retencion",
      name: "retencion",
      component: () => import("@/views/RetencionView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/padron",
      name: "padron",
      component: () => import("@/views/PadronView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/importar",
      name: "importar",
      component: () => import("@/views/ImportarMiembrosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/instructores",
      name: "instructores",
      component: () => import("@/views/InstructoresView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/usuarios",
      name: "usuarios",
      component: () => import("@/views/UsuariosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/ventas",
      name: "ventas",
      component: () => import("@/views/VentasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/catalogo",
      name: "catalogo",
      component: () => import("@/views/CatalogoView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/facturas",
      name: "facturas",
      component: () => import("@/views/FacturasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/cobranza",
      name: "cobranza",
      component: () => import("@/views/CobranzaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/renta",
      name: "renta",
      component: () => import("@/views/RentaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/reportes",
      name: "reportes",
      component: () => import("@/views/ReportesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/comunicaciones",
      name: "comunicaciones",
      component: () => import("@/views/ComunicacionesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/datos-fiscales",
      name: "datos-fiscales",
      component: () => import("@/views/DatosFiscalesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/agenda",
      name: "agenda",
      component: () => import("@/views/AgendaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/horarios",
      name: "horarios",
      component: () => import("@/views/HorariosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/oportunidades",
      name: "oportunidades",
      component: () => import("@/views/OportunidadesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/tareas",
      name: "tareas",
      component: () => import("@/views/TareasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/promociones",
      name: "promociones",
      component: () => import("@/views/PromocionesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/lealtad",
      name: "lealtad",
      component: () => import("@/views/LealtadView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/pos",
      name: "pos",
      component: () => import("@/views/PosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/recepcion",
      name: "recepcion",
      component: () => import("@/views/FrontDeskView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/grupos",
      name: "grupos",
      component: () => import("@/views/GruposView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/recursos",
      name: "recursos",
      component: () => import("@/views/RecursosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/nomina",
      name: "nomina",
      component: () => import("@/views/NominaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/pasarelas",
      name: "pasarelas",
      component: () => import("@/views/PasarelasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/integraciones",
      name: "integraciones",
      component: () => import("@/views/IntegracionesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/configuracion",
      name: "configuracion",
      component: () => import("@/views/ConfiguracionView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/documentos",
      name: "documentos",
      component: () => import("@/views/DocumentosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/formularios",
      name: "formularios",
      component: () => import("@/views/FormulariosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mi-cuenta",
      name: "mi-cuenta",
      component: () => import("@/views/MiCuentaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/plataforma",
      name: "plataforma",
      component: () => import("@/views/PlataformaView.vue"),
    },
    {
      // Enlace corto público del estudio (agendauno.mx/mi-estudio). Va al FINAL, antes
      // del catch-all: solo captura rutas de UN segmento que no sean una ruta con nombre.
      path: "/:slug",
      name: "estudio-corto",
      component: () => import("@/views/EstudioPublicoView.vue"),
    },
    { path: "/:pathMatch(.*)*", redirect: { name: "inicio" } },
  ],
});

router.beforeEach(async (to) => {
  const sesion = useSesionTenantStore();
  await sesion.verificarSesion();

  if (to.meta.requiereSesion === true && !sesion.autenticado) {
    return { name: "entrar" };
  }

  // Un usuario autenticado no debe quedarse en las páginas públicas de acceso
  // (landing/login/registro): se le lleva a su inicio según rol (P0).
  if (
    sesion.autenticado &&
    ["inicio", "entrar", "registro"].includes(String(to.name))
  ) {
    return { name: sesion.rutaInicio };
  }

  // En el subdominio de un estudio (`{slug}.agendauno.mx`), la raíz no es la
  // landing de marketing: aterriza directo en la página pública de ese estudio.
  if (String(to.name) === "inicio") {
    const slug = slugDeContexto();
    if (slug !== null) {
      return { name: "estudio-publico", params: { slug } };
    }
  }

  return true;
});

const PUBLIC_SEO: Record<string, { title: string; description: string }> = {
  inicio: DEFAULT_SEO,
  registro: {
    title: "Crea tu estudio gratis | AgendaUno",
    description:
      "Configura tu estudio en AgendaUno y prueba agenda, reservas, membresías y cobros durante 14 días sin tarjeta.",
  },
  directorio: {
    title: "Encuentra clases y estudios | AgendaUno",
    description:
      "Descubre estudios, gimnasios y academias, consulta sus próximas clases y crea tu cuenta directamente con cada estudio.",
  },
  "estudio-publico": {
    title: "Clases y estudios en AgendaUno",
    description:
      "Consulta horarios, instructores y precios de este estudio en AgendaUno.",
  },
  entrar: {
    title: "Entrar a tu estudio | AgendaUno",
    description: "Accede a la cuenta independiente de tu estudio en AgendaUno.",
  },
};

router.afterEach((to) => {
  const routeName = String(to.name ?? "");
  const seo = PUBLIC_SEO[routeName];
  if (seo) {
    updateSeo({ ...seo, path: to.path });
  }
  trackPageView(to.path, seo?.title ?? routeName);
});

export default router;
