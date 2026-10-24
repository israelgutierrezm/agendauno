import {
  createRouter,
  createWebHistory,
  type RouteLocationNormalized,
} from "vue-router";

import ModalidadView from "@/views/ModalidadView.vue";
import { analyticsPath, trackPageView } from "@/lib/analytics";
import { puedeEntrar } from "@/lib/acceso";
import {
  ambitoDeRuta,
  capturarAtribucion,
  fijarAmbitoActual,
} from "@/lib/atribucion";
import { volverSeguro } from "@/lib/destinoSeguro";
import { navegar } from "@/lib/etiquetas";
import { updateSeo } from "@/lib/seo";
import { giroDeQuery, modoDeQuery } from "@/marketing/modalidades";
import { preciosPublicos } from "@/marketing/preciosPublicos";
import { PRODUCTO_COMERCIAL, seoParaRuta } from "@/marketing/seoConfig";
import {
  registroDelOtroProducto,
  rutasComerciales,
} from "@/router/comerciales";
import {
  enSubdominioDeEstudio,
  salirA,
  slugDeContexto,
  urlEnSubdominioDelNegocio,
} from "@/lib/tenant";
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
    // Portada del producto, /clases, /citas y /software-para-*: la misma lista que el
    // prerender (ADR 0108: cada dominio, las de su producto).
    ...rutasComerciales({
      modalidad: ModalidadView,
      solucion: () => import("@/views/SolucionView.vue"),
    }),
    {
      path: "/aviso-de-privacidad",
      name: "aviso-privacidad",
      component: () => import("@/views/AvisoPrivacidadView.vue"),
    },
    {
      // Agendar una demo (ADR 0204): a ella llevan los botones de la landing.
      path: "/demo",
      name: "demo",
      component: () => import("@/views/DemoView.vue"),
    },
    {
      // Cancelar la demo agendada desde el enlace del correo (ADR 0193).
      path: "/demo/cancelar",
      name: "demo-cancelar",
      component: () => import("@/views/DemoCancelarView.vue"),
    },
    {
      path: "/terminos",
      name: "terminos",
      component: () => import("@/views/TerminosView.vue"),
    },
    {
      // Política de cookies (ADR 0206): la publica el superadmin, como el aviso.
      path: "/cookies",
      name: "politica-cookies",
      component: () => import("@/views/PoliticaCookiesView.vue"),
    },
    {
      // Un widget en la web del negocio (calendario o planes; ADR 0141): sin encabezado.
      path: "/w/:slug/:widget",
      name: "widget",
      component: () => import("@/views/WidgetView.vue"),
      props: true,
    },
    {
      // Un presupuesto en línea desde el enlace del correo (`?t=`): verlo,
      // responderlo y pagarlo sin cuenta.
      path: "/presupuesto/:slug/:id",
      name: "presupuesto-en-linea",
      component: () => import("@/views/PresupuestoEnLineaView.vue"),
    },
    {
      // Un formulario de captación por enlace, QR o embebido (`?embed=1`) (ADR 0117).
      path: "/f/:slug/:formulario",
      name: "formulario-captacion",
      component: () => import("@/views/FormularioCaptacionPublicoView.vue"),
      props: true,
    },
    {
      // El aviso de privacidad de un negocio para sus clientes (público).
      path: "/estudio/:slug/aviso-de-privacidad",
      name: "aviso-negocio",
      component: () => import("@/views/AvisoNegocioView.vue"),
    },
    {
      // `?modo=clases|citas` (desde /clases o /citas) y `?giro=` (desde la página de
      // un giro) llegan como props; uno inválido, como null.
      path: "/registro",
      name: "registro",
      component: () => import("@/views/RegistroView.vue"),
      // Un giro o una modalidad del otro producto: su registro (ADR 0131).
      beforeEnter: (to) => registroDelOtroProducto(to.query, to.fullPath),
      props: (r) => ({
        modo: modoDeQuery(r.query.modo),
        giro: giroDeQuery(r.query.giro),
      }),
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
      // Una página propia de su sitio (ADR 0146): /estudio/{slug}/p/{pagina}.
      path: "/estudio/:slug/p/:pagina",
      name: "estudio-pagina",
      component: () => import("@/views/EstudioPublicoView.vue"),
    },
    {
      // Vista previa del sitio con su borrador (la abre su editor, con la sesión de
      // quien configura el negocio; en el mismo origen que el panel).
      path: "/estudio/:slug/vista-previa",
      name: "sitio-vista-previa",
      component: () => import("@/views/EstudioPublicoView.vue"),
      props: { vistaPrevia: true },
    },
    {
      // Kiosco de llegada (ADR 0183): la tableta de recepción del negocio.
      path: "/estudio/:slug/kiosco",
      name: "kiosco",
      component: () => import("@/views/KioscoView.vue"),
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
      // Saldos de apertura al migrar de otro sistema (C07).
      path: "/importar/saldos",
      name: "importar-saldos",
      component: () => import("@/views/ImportarSaldosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Reservas futuras de clases de otro sistema (ADR 0176).
      path: "/importar/reservas",
      name: "importar-reservas",
      component: () => import("@/views/ImportarReservasView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Presupuestos de los negocios de citas (ADR 0127).
      path: "/presupuestos",
      name: "presupuestos",
      component: () => import("@/views/PresupuestosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Un presupuesto listo para imprimir o guardar como PDF.
      path: "/presupuestos/:id/imprimir",
      name: "presupuesto-impreso",
      component: () => import("@/views/PresupuestoImpresoView.vue"),
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
      path: "/agenda/importar-citas",
      name: "importar-citas",
      component: () => import("@/views/ImportarCitasView.vue"),
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
      path: "/renta/timbres",
      name: "timbres",
      component: () => import("@/views/TimbresView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/reportes",
      name: "reportes",
      component: () => import("@/views/ReportesView.vue"),
      meta: { requiereSesion: true },
    },
    // El asistente sobre los datos del negocio (ADR 0196).
    {
      path: "/reportes/asistente",
      name: "asistente-ia",
      component: () => import("@/views/AsistenteView.vue"),
      meta: { requiereSesion: true },
    },
    // Visitantes de Wellhub y TotalPass (ADR 0148), aparte de los alumnos.
    {
      path: "/reportes/plataformas",
      name: "reportes-plataformas",
      component: () => import("@/views/ReportePlataformasView.vue"),
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
      // La bandeja de WhatsApp e Instagram (ADR 0200).
      path: "/bandeja",
      name: "bandeja",
      component: () => import("@/views/BandejaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Captación (ADR 0117): quien todavía no es cliente, aparte de Miembros.
      path: "/prospectos",
      name: "prospectos",
      component: () => import("@/views/ProspectosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/captacion/formularios",
      name: "captacion-formularios",
      component: () => import("@/views/FormulariosCaptacionView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Integrar en mi web (ADR 0141): ahora en Sitio web › Compartir (ADR 0167).
      path: "/captacion/integrar",
      name: "captacion-integrar",
      redirect: { name: "sitio-web", query: { pestana: "compartir" } },
    },
    {
      // Campañas (ADR 0167): el reporte por campaña, en Captación.
      path: "/captacion/campanas",
      name: "campanas",
      component: () => import("@/views/CampanasView.vue"),
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
      // La caja de cada sucursal: abrir, mover efectivo, contar y cerrar (ADR 0175).
      path: "/caja",
      name: "caja",
      component: () => import("@/views/CajaView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Cobros › Cierres de caja: el historial de cada caja al cerrarse y su revisión.
      path: "/cierres-caja",
      name: "cierres-caja",
      component: () => import("@/views/CierresCajaView.vue"),
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
      // Biblioteca de videos (ADR 0184).
      path: "/videos",
      name: "videos",
      component: () => import("@/views/VideosView.vue"),
      meta: { requiereSesion: true },
    },
    {
      path: "/recepcion",
      name: "recepcion",
      component: () => import("@/views/FrontDeskView.vue"),
      meta: { requiereSesion: true },
    },
    {
      // Pase de lista de una clase en su propia pantalla (ADR 0101).
      path: "/pase-de-lista/:id",
      name: "pase-lista",
      component: () => import("@/views/PaseListaView.vue"),
      props: true,
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
      // La página pública pasó a Sitio web › Contenido (ADR 0167).
      beforeEnter: (to) =>
        to.hash === "#pagina-publica"
          ? { name: "sitio-web", query: { pestana: "contenido" } }
          : true,
    },
    {
      // El sitio del negocio (ADR 0114, 0167): en Captación, con sus pestañas.
      path: "/sitio",
      name: "sitio-web",
      component: () => import("@/views/SitioWebView.vue"),
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
      // Videos del negocio para el cliente (ADR 0184).
      path: "/mi-cuenta/videos",
      name: "mis-videos",
      component: () => import("@/views/MisVideosView.vue"),
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
      // Superadmin de AgendaUno (ADR 0126): correo, contraseña, el token de la
      // plataforma y un código por correo. No hay enlace a ella.
      path: "/plataforma",
      name: "plataforma",
      component: () => import("@/views/PlataformaView.vue"),
    },
    // La dirección anterior lleva a la nueva.
    { path: "/splataformadm1n", redirect: { name: "plataforma" } },
    {
      // Enlace corto público del estudio (agendauno.mx/mi-estudio). Va al FINAL, antes
      // del catch-all: solo captura rutas de UN segmento que no sean una ruta con nombre.
      path: "/:slug",
      name: "estudio-corto",
      component: () => import("@/views/EstudioPublicoView.vue"),
    },
    {
      // Lo que no existe se dice (ADR 0174), con el camino de vuelta; antes se mandaba
      // al inicio sin avisar.
      path: "/:pathMatch(.*)*",
      name: "no-encontrada",
      component: () => import("@/views/NoEncontradaView.vue"),
    },
  ],
});

// Atribución (ADR 0142): la llegada se anota antes de cualquier redirección (al
// subdominio del negocio, a su sucursal, al otro producto), que pierden la query. La
// página de la que se viene solo cuenta en la primera navegación de la carga.
let referrerDeLaCarga =
  typeof document === "undefined" ? "" : document.referrer;
router.beforeEach((to) => {
  const ambito = ambitoDeRuta(to);
  if (ambito !== null) {
    capturarAtribucion(
      ambito,
      new URL(to.fullPath, window.location.origin).searchParams,
      referrerDeLaCarga,
      window.location.hostname,
    );
  }
  referrerDeLaCarga = "";
});

router.beforeEach(async (to) => {
  // En el dominio principal no hay rutas con el slug de un negocio: su enlace corto,
  // su página y sus enlaces viven en su subdominio (en desarrollo, no aplica).
  const enSubdominio = urlEnSubdominioDelNegocio(to);
  if (enSubdominio !== null) {
    salirA(enSubdominio);
    return false;
  }

  const sesion = useSesionTenantStore();
  await sesion.verificarSesion();

  if (to.meta.requiereSesion === true && !sesion.autenticado) {
    // Sin perder a dónde iba: un enlace a una pantalla privada vuelve a ella al entrar
    // (M11). Fuera del subdominio, también de qué negocio.
    return {
      name: "entrar",
      query: {
        ...(sesion.slug !== null && !enSubdominioDeEstudio()
          ? { estudio: sesion.slug }
          : {}),
        ...(to.fullPath !== "/" ? { volver: to.fullPath } : {}),
      },
    };
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
      // que le explica cómo seguir) o a su inicio. Solo rutas internas de este negocio.
      const volver = volverSeguro(to.query.volver, sesion.slug, (r) =>
        router.resolve(r),
      );
      if (to.name === "entrar" && volver !== null) {
        return volver;
      }
      return { name: sesion.rutaInicio };
    }
  }

  // En el subdominio de un estudio (`{slug}.agendauno.mx`), ni la raíz ni ninguna
  // página comercial (/clases, /citas, /software-para-*) muestran la landing de
  // AgendaUno: pasan por su entrada pública, que va al flujo de citas solo si el
  // escaparate dice que el negocio es de citas (ADR 0104; elige sede si tiene varias)
  // y, si es de clases, a su página.
  if (to.meta.marketing === true) {
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
  fijarAmbitoActual(ambitoDeRuta(to));
  // Con lo que publicó el superadmin, si ya llegó: si el producto recibe registros.
  const seo = seoParaRuta(to.path, {
    registroAbierto: preciosPublicos.datos.registro[PRODUCTO_COMERCIAL],
  });
  updateSeo(seo);
  // No enviar slugs, IDs internos ni URLs de activación a la medición comercial.
  if (seo.index || to.name === "registro") trackPageView(to.path, seo.title);
  // GA4 y Meta (ADR 0143): una vista por navegación, solo en las páginas públicas.
  const ambito = ambitoDeRuta(to);
  void navegar(
    ambito,
    rutaMedible(to, ambito, seo.index === true),
    document.title,
  );
});

// Las páginas de un negocio que se miden con sus etiquetas: su página, su agenda y sus
// enlaces. No su panel, la cuenta del cliente, un widget ni la vista previa.
const RUTAS_DE_NEGOCIO_MEDIBLES = new Set([
  "estudio-publico",
  "estudio-pagina",
  "estudio-corto",
  "sucursales-estudio",
  "enlaces-estudio",
  "agendar-cita",
]);
function rutaMedible(
  to: RouteLocationNormalized,
  ambito: ReturnType<typeof ambitoDeRuta>,
  indexable: boolean,
): string | null {
  if (ambito === null || to.query.vista_previa !== undefined) return null;
  const i = to.fullPath.indexOf("?");
  const query = i >= 0 ? to.fullPath.slice(i).split("#")[0]! : "";
  if (ambito.startsWith("plataforma:")) {
    // Lo del producto, agrupado como en la medición interna (sin slugs ni tokens).
    return indexable || to.name === "registro"
      ? analyticsPath(to.path) + query
      : null;
  }
  return RUTAS_DE_NEGOCIO_MEDIBLES.has(String(to.name)) ? to.fullPath : null;
}

export default router;
