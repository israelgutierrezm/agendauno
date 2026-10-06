import { createRouter, createWebHistory } from "vue-router";

import LandingView from "@/views/LandingView.vue";
import { trackPageView } from "@/lib/analytics";
import { puedeEntrar } from "@/lib/acceso";
import { updateSeo } from "@/lib/seo";
import { seoParaRuta } from "@/marketing/seoConfig";
import { soluciones, rutaSolucion } from "@/marketing/soluciones";
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
    // Un ancla que no es un elemento (p. ej. la pestaña de Reglas de la agenda)
    // no desplaza: la página la interpreta.
    if (to.hash && document.getElementById(to.hash.slice(1)) !== null) {
      // La barra fija de arriba: la de la página pública o la del panel.
      const headerHeight =
        document
          .querySelector(".tu-public-nav, .tu-barra-superior")
          ?.getBoundingClientRect().height ?? 0;
      return { el: to.hash, top: headerHeight + 16, behavior: "smooth" };
    }
    return { top: 0 };
  },
  routes: [
    { path: "/", name: "inicio", component: LandingView },
    {
      path: "/aviso-de-privacidad",
      name: "aviso-privacidad",
      component: () => import("@/views/AvisoPrivacidadView.vue"),
    },
    ...soluciones.map((solucion) => ({
      path: rutaSolucion(solucion.slug),
      name: `solucion-${solucion.slug}`,
      component: () => import("@/views/SolucionView.vue"),
      props: { slug: solucion.slug },
    })),
    {
      path: "/registro",
      name: "registro",
      component: () => import("@/views/RegistroView.vue"),
    },
    {
      path: "/negocios",
      alias: ["/explorar", "/directorio"],
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
      // Página de enlaces del negocio (la de la bio de Instagram):
      // agendauno.mx/{slug}/enlaces o {slug}.agendauno.mx/enlaces.
      path: "/:slug/enlaces",
      name: "enlaces-estudio",
      component: () => import("@/views/EnlacesEstudioView.vue"),
    },
    {
      path: "/enlaces",
      name: "enlaces-subdominio",
      component: () => import("@/views/EnlacesEstudioView.vue"),
    },
    {
      // Selector de sucursal (público): raíz del subdominio con varias sedes.
      path: "/sucursales/:slug",
      name: "sucursales-estudio",
      component: () => import("@/views/SucursalesEstudioView.vue"),
    },
    {
      // Recuperar la contraseña: pedir el enlace y, desde el correo, elegir la nueva.
      path: "/recuperar/:slug?",
      name: "recuperar-contrasena",
      component: () => import("@/views/RecuperarContrasenaView.vue"),
    },
    {
      path: "/restablecer/:slug",
      name: "restablecer-contrasena",
      component: () => import("@/views/RecuperarContrasenaView.vue"),
    },
    {
      // Confirmar el correo nuevo desde el enlace que le llegó.
      path: "/confirmar-correo/:slug",
      name: "confirmar-correo",
      component: () => import("@/views/ConfirmarCorreoView.vue"),
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
      path: "/importar-instructores",
      name: "importar-instructores",
      component: () => import("@/views/ImportarInstructoresView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/agenda/importar",
      name: "importar-clases",
      component: () => import("@/views/ImportarClasesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/instructores",
      name: "instructores",
      component: () => import("@/views/InstructoresView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Perfil de un profesional con su expediente (quien administra al equipo).
      path: "/instructores/:id",
      name: "ficha-instructor",
      component: () => import("@/views/FichaInstructorView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/usuarios",
      name: "usuarios",
      component: () => import("@/views/UsuariosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/roles",
      name: "roles",
      component: () => import("@/views/RolesView.vue"),
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
      path: "/region",
      name: "region",
      component: () => import("@/views/RegionNegocioView.vue"),
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
      // Inventario: los productos del mostrador y su stock por sucursal.
      path: "/inventario",
      name: "inventario",
      component: () => import("@/views/InventarioView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Planes y paquetes (ADR 0050): qué se vende para reservar.
      path: "/planes",
      name: "planes",
      component: () => import("@/views/PlanesView.vue"),
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
      path: "/reglas-agenda",
      name: "reglas-agenda",
      component: () => import("@/views/ReglasAgendaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/bitacora",
      name: "bitacora",
      component: () => import("@/views/BitacoraView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Solicitudes de baja de datos (derechos ARCO).
      path: "/privacidad",
      name: "privacidad",
      component: () => import("@/views/PrivacidadView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Reseñas de los alumnos (promedios y comentarios).
      path: "/resenas",
      name: "resenas",
      component: () => import("@/views/ResenasView.vue"),
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
      // Portada de Configuración del negocio: sus categorías y opciones.
      path: "/ajustes",
      name: "ajustes",
      component: () => import("@/views/ConfiguracionNegocioView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/sedes",
      name: "sedes",
      component: () => import("@/views/SucursalesView.vue"),
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
      // Portal del alumno o cliente: Inicio con accesos y una pantalla por tema.
      path: "/mi-cuenta",
      name: "mi-cuenta",
      component: () => import("@/views/MiCuentaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mi-cuenta/reservas",
      name: "mis-reservas",
      component: () => import("@/views/MisReservasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mi-cuenta/pagos",
      name: "mis-pagos",
      component: () => import("@/views/MisPagosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mi-cuenta/expediente",
      name: "mi-expediente",
      component: () => import("@/views/MiExpedienteView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mi-cuenta/configuracion",
      name: "mi-configuracion",
      redirect: { name: "mi-perfil" },
      meta: { requiereSesion: true },
    },
    {
      // Portal de quien imparte: Inicio con accesos y su calendario.
      path: "/mis-clases",
      name: "inicio-instructor",
      component: () => import("@/views/InicioInstructorView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/mis-clases/calendario",
      name: "mis-clases",
      component: () => import("@/views/MisClasesView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Mi perfil: foto, nombre y contraseña de quien tiene la sesión (todo rol).
      path: "/mi-perfil",
      name: "mi-perfil",
      component: () => import("@/views/MiPerfilView.vue"),
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
  // Cada pantalla privada tiene su regla en lib/acceso (sin regla, no se entra).
  // Cubre la URL escrita a mano: el menú solo ofrece lo permitido. Sin permiso se
  // va al inicio de quien entra, que también se valida; si ni ese se puede, a su
  // perfil (siempre permitido con sesión), sin ciclos.
  if (
    to.meta.requiereSesion === true &&
    !puedeEntrar(String(to.name), sesion)
  ) {
    if (to.name !== sesion.rutaInicio) {
      return { name: sesion.rutaInicio };
    }
    if (to.name !== "mi-perfil") {
      return { name: "mi-perfil" };
    }
  }

  // Un usuario autenticado no debe quedarse en las páginas públicas de acceso
  // (landing/login/registro): se le lleva a su inicio según rol (P0). Salvo entrar a
  // OTRO negocio (su `?estudio=` o su subdominio): la sesión es de un solo negocio y
  // entrar al otro la reemplaza en este navegador.
  if (
    sesion.autenticado &&
    ["inicio", "entrar", "registro"].includes(String(to.name))
  ) {
    const otro =
      to.name === "entrar"
        ? slugDeContexto(
            window.location.hostname,
            typeof to.query.estudio === "string"
              ? `?estudio=${encodeURIComponent(to.query.estudio)}`
              : "",
          )
        : null;
    if (otro === null || otro === sesion.slug) {
      // Ya tiene sesión en este negocio: de vuelta a donde venía (p. ej. agendar,
      // que le explica cómo seguir) o a su inicio. Solo rutas internas.
      const volver = String(to.query.volver ?? "");
      if (to.name === "entrar" && /^\/(?![/\\])/.test(volver)) {
        return volver;
      }
      return { name: sesion.rutaInicio };
    }
  }

  // En el subdominio de un estudio (`{slug}.agendauno.mx`), la raíz no es la
  // landing de marketing: pasa por el selector de sucursal, que redirige solo
  // cuando el estudio tiene una sola sede (o va directo a agendar/su página).
  if (String(to.name) === "inicio") {
    // El estudio del subdominio o del `?estudio=` de ESTA dirección, no de la página
    // de la que se viene: el logo de AgendaUno en /entrar?estudio=… lleva a la portada.
    const estudio =
      typeof to.query.estudio === "string" ? to.query.estudio : "";
    const slug = slugDeContexto(
      window.location.hostname,
      estudio !== "" ? `?estudio=${encodeURIComponent(estudio)}` : "",
    );
    if (slug !== null) {
      return { name: "sucursales-estudio", params: { slug } };
    }
  }

  return true;
});

router.afterEach((to, _from, failure) => {
  if (failure) return;
  const seo = seoParaRuta(to.path);
  updateSeo(seo);
  // No enviar slugs, IDs internos ni URLs de activación a la medición comercial.
  if (seo.index || to.name === "registro") trackPageView(to.path, seo.title);
});

export default router;
