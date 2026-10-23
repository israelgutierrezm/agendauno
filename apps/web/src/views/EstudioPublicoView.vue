<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import IconoRed from "@/components/IconoRed.vue";
import InstalarApp from "@/components/InstalarApp.vue";
import CalendarioPublico from "@/components/CalendarioPublico.vue";
import TarjetaPlanPublico from "@/components/TarjetaPlanPublico.vue";
import BloqueSitio from "@/components/BloqueSitio.vue";
import CarruselSitio from "@/components/CarruselSitio.vue";
import FichaIntegrante from "@/components/FichaIntegrante.vue";
import FormularioCaptacion from "@/components/FormularioCaptacion.vue";
import ServicioIncluye from "@/components/ServicioIncluye.vue";
import { api } from "@/lib/api";
import { volverConInteres } from "@/lib/destinoSeguro";
import { eventoComercio } from "@/lib/etiquetas";
import { adaptarTexto, type TerminosNegocio } from "@/lib/terminologia";
import { trackEvent } from "@/lib/analytics";
import {
  capacidadesDeNegocio,
  type Capacidades,
  type ModalidadServicio,
} from "@/lib/modalidad";
import { recordarNegocio } from "@/lib/negociosRecientes";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { marcarPwa } from "@/lib/pwa";
import { updateSeo } from "@/lib/seo";
import { urlCanonicaEstudio } from "@/lib/tenant";
import { cargarFuente, pilaDeFuente } from "@/lib/fuentes";
import { dinero as dineroDelPais } from "@/lib/formato";
import {
  avisarEditorSitio,
  claveSeccion,
  colorDeContraste,
  esBloque,
  FAMILIAS_SITIO,
  mensajeEditorSitio,
  RADIOS_SITIO,
  sitioPorDefecto,
  ventanaDelEditor,
  type SitioPublico,
} from "@/lib/sitioWeb";
import { claveSegunModalidad } from "@/lib/menu";
import { perfilVisibleAlPublico } from "@/marketing/modalidades";

/**
 * La página pública del negocio: sus datos salen del sistema y su forma, de su sitio
 * (ADR 0114: plantilla, orden y visibilidad de las secciones, títulos, textos y
 * banners). Con `vistaPrevia`, la ve quien configura el negocio con su borrador.
 *
 * Varias páginas (ADR 0146): Inicio y sus páginas propias (`/estudio/{slug}/p/{pagina}`;
 * en la vista previa, `?pagina=`), con su menú, su encabezado y el pie de página en
 * todas. Cada una con su SEO.
 */
const props = defineProps<{ vistaPrevia?: boolean }>();

interface Sesion {
  // Identificador público (para pedir lugar en esa clase).
  id?: string;
  clase: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  sucursal: string | null;
  instructor: string | null;
  capacidad: number | null;
  lugares_libres: number | null;
}
interface Producto {
  id?: string;
  todas_sucursales?: boolean;
  sucursales?: { id: string; nombre: string }[];
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
  // Para comparar antes de comprar (M03/B03).
  descripcion?: string | null;
  destacado?: boolean;
  primera_vez?: boolean;
  actividad?: string | null;
  unidades_por_ciclo?: number | null;
  // La fecha exacta: la vigencia corre desde el pago.
  vence_si_compra_hoy?: string | null;
  // Permanencia mínima (B04).
  permanencia_meses?: number | null;
}
type NombreRed = "instagram" | "facebook" | "tiktok" | "youtube" | "sitio_web";
interface Red {
  red: NombreRed;
  url: string;
}
interface DiaAbierto {
  dia: number;
  abre: string;
  cierra: string;
}
interface Sucursal {
  id?: string;
  nombre: string;
  zona_horaria: string | null;
  region: string | null;
  direccion?: string | null;
  foto_url?: string | null;
  mapa_url?: string | null;
  telefono?: string | null;
  whatsapp_url?: string | null;
  redes?: Red[];
  // Null: no hay horario capturado ni de sus profesionales.
  horario?: DiaAbierto[] | null;
}
interface Servicio {
  id: string;
  nombre: string;
  descripcion: string | null;
  categoria: string | null;
  // Paquete: qué incluye y cuánto costaría por separado.
  incluye?: string[];
  precio_por_separado_minor?: number | null;
  foto_url?: string | null;
  grupal: boolean;
  duracion_minutos: number | null;
  precio_minor: number | null;
  moneda: string;
  agendable: boolean;
  niveles: string[];
  // Para quien llega por primera vez (C03).
  para_principiantes?: boolean;
  requisitos?: string | null;
  que_llevar?: string | null;
}
interface ClaseHorario {
  dia: number;
  hora: string;
  duracion_minutos: number | null;
  clase: string;
  categoria: string | null;
  instructor: string | null;
  sucursal: string | null;
}
interface Resena {
  calificacion: number;
  comentario: string | null;
  nombre: string | null;
  actividad: string | null;
  fecha: string | null;
}
interface Integrante {
  id?: string;
  nombre: string;
  foto_url: string | null;
  semblanza?: string | null;
}
interface Escaparate {
  // Sus datos estructurados, los mismos que da la cabecera del servidor (ADR 0155).
  json_ld?: Record<string, unknown>;
  estudio: {
    slug: string;
    nombre: string;
    // ¿Publicó su aviso de privacidad para sus clientes?
    aviso_privacidad?: boolean;
    // ¿Aparece en el directorio? «Solo con enlace» se ve, pero no se indexa (ADR 0128).
    en_directorio?: boolean;
    logo_url: string | null;
    portada_url?: string | null;
    color_marca?: string | null;
    descripcion?: string | null;
    redes?: Red[];
    whatsapp_url?: string | null;
    perfil: string;
    perfil_config: {
      terminologia?: Record<string, string>;
      modalidad?: ModalidadServicio;
    };
    // Solo clases o solo citas (ADR 0104): lo dice el servidor, no las ofertas.
    modalidad?: ModalidadServicio;
    capacidades?: Capacidades;
    ciudad: string | null;
    pais: string | null;
    whatsapp: string | null;
  };
  sucursales: Sucursal[];
  instructores: Integrante[];
  servicios?: Servicio[];
  horario_clases?: ClaseHorario[];
  productos: Producto[];
  proximas_sesiones: Sesion[];
  // Solo las que el negocio deja visibles (promedio de todas; comentarios recientes).
  resenas?: { promedio: number | null; total: number; recientes: Resena[] };
  // Su sitio publicado (o el borrador en la vista previa).
  sitio?: SitioPublico;
  // Captación (ADR 0117): si recibe prospectos y con qué formulario.
  captacion?: { activa: boolean; formulario: string | null };
}

const route = useRoute();
const router = useRouter();

const slug = computed(() => String(route.params.slug));
const escaparate = ref<Escaparate | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);

// Registro cerrado (ADR 0093): nadie crea su cuenta aquí. Quien quiere reservar
// pide su acceso al negocio (que lo da de alta y lo invita) o entra si ya lo tiene.
// Por dónde pedir el acceso: el WhatsApp del negocio o, si no tiene, el WhatsApp o el
// teléfono de una de sus sucursales (nunca un aviso sin ningún canal).
const canalAcceso = computed<{ whatsapp?: string; telefono?: string } | null>(
  () => {
    const e = escaparate.value;
    if (!e) {
      return null;
    }
    if (e.estudio.whatsapp_url) {
      return { whatsapp: e.estudio.whatsapp_url };
    }
    const conWhatsapp = e.sucursales.find((s) => s.whatsapp_url);
    if (conWhatsapp?.whatsapp_url) {
      return { whatsapp: conWhatsapp.whatsapp_url };
    }
    const conTelefono = e.sucursales.find((s) => s.telefono);
    return conTelefono?.telefono ? { telefono: conTelefono.telefono } : null;
  },
);
const pidiendoAcceso = ref(false);

// Lo que decide el negocio de su página; sin sitio (un API anterior), la de siempre.
const sitio = computed<SitioPublico>(
  () => escaparate.value?.sitio ?? sitioPorDefecto(usaCitas.value),
);
function seccion(tipo: string) {
  return sitio.value.secciones.find((x) => x.tipo === tipo);
}

// ---- Varias páginas (ADR 0146) ----
// La que se pide: `/p/{pagina}` o, en la vista previa, `?pagina=`; sin ella, Inicio.
const direccionPedida = computed(() => {
  const valor = props.vistaPrevia ? route.query.pagina : route.params.pagina;
  return typeof valor === "string" && valor !== "" ? valor : null;
});
const paginaActual = computed(
  () =>
    (sitio.value.paginas ?? []).find((p) => p.slug === direccionPedida.value) ??
    null,
);
// Pidió una página que no existe (o ya no): se dice, con el camino a Inicio.
const paginaNoExiste = computed(
  () => direccionPedida.value !== null && paginaActual.value === null,
);
const seccionesAMostrar = computed(() =>
  paginaNoExiste.value
    ? []
    : (paginaActual.value?.secciones ?? sitio.value.secciones),
);
const paginasDelMenu = computed(() =>
  (sitio.value.paginas ?? []).filter((p) => p.en_menu),
);
// A Inicio o a una página, en la vista previa sin salir de ella.
function aInicio() {
  return props.vistaPrevia
    ? { name: "sitio-vista-previa", params: { slug: slug.value } }
    : { name: "estudio-publico", params: { slug: slug.value } };
}
function aPagina(direccion: string) {
  return props.vistaPrevia
    ? {
        name: "sitio-vista-previa",
        params: { slug: slug.value },
        query: { pagina: direccion },
      }
    : {
        name: "estudio-pagina",
        params: { slug: slug.value, pagina: direccion },
      };
}
/**
 * Un enlace de un bloque o banner: `/p/{pagina}` es una página de su sitio (con o sin
 * ancla); lo demás (`#…`, `https://…`, otra ruta) queda como está.
 */
function enlaceDelSitio(enlace: string): string {
  const m = /^\/p\/([a-z0-9-]+)(#[a-z0-9-]+)?$/.exec(enlace);
  return m ? router.resolve(aPagina(m[1]!)).href + (m[2] ?? "") : enlace;
}
// La portada: titular y texto propios o, sin ellos, el nombre y la descripción.
const titular = computed(
  () => seccion("inicio")?.titulo ?? escaparate.value?.estudio.nombre ?? "",
);
const textoPortada = computed(
  () => seccion("inicio")?.texto ?? escaparate.value?.estudio.descripcion ?? "",
);
// Cómo se presenta la portada: «portada» necesita foto; sin ella, la esencial.
const portada = computed(() => {
  const p = sitio.value.plantilla;
  if (p === "portada" && escaparate.value?.estudio.portada_url) {
    return "foto";
  }
  return p === "compacta" ? "compacta" : "esencial";
});
// El color de su marca en los botones (con el texto que se lea encima).
// Su estilo (ADR 0146, fase 3): el color del sitio (o el de su marca) en los botones,
// su tipografía y sus esquinas.
const familiaDelSitio = computed(
  () => FAMILIAS_SITIO[sitio.value.estilo?.tipografia ?? "predeterminada"],
);
const estiloMarca = computed<Record<string, string>>(() => {
  const color =
    sitio.value.estilo?.color ?? escaparate.value?.estudio.color_marca;
  const estilo: Record<string, string> = {
    ...RADIOS_SITIO[sitio.value.estilo?.esquinas ?? "suaves"],
  };
  if (familiaDelSitio.value) {
    estilo.fontFamily = pilaDeFuente(familiaDelSitio.value);
  }
  if (!color) {
    return estilo;
  }
  const texto = colorDeContraste(color);
  return {
    ...estilo,
    "--marketing-cta": color,
    "--marketing-cta-hover": `color-mix(in srgb, ${color} 85%, #000)`,
    "--marketing-cta-contraste": texto,
    "--primario": color,
    "--primario-contraste": texto,
  };
});
// Claro u oscuro fijo; «automático», como lo tenga quien visita.
const temaDelSitio = computed(() => sitio.value.estilo?.tema ?? "automatico");
// El botón principal del encabezado, si lo puso.
const botonPrincipal = computed(() => {
  const e = sitio.value.encabezado;
  return e?.boton_texto && e.boton_enlace
    ? { texto: e.boton_texto, enlace: enlaceDelSitio(e.boton_enlace) }
    : null;
});
// La barra del sitio: con sus páginas en el menú o con su botón principal.
const conBarra = computed(
  () => paginasDelMenu.value.length > 0 || botonPrincipal.value !== null,
);

const ubicacion = computed(() => {
  const e = escaparate.value?.estudio;
  return e ? [e.ciudad, e.pais].filter(Boolean).join(", ") : "";
});
// «Agendar cita» solo si el negocio es de citas (su modalidad, no sus ofertas).
const usaCitas = computed(
  () => capacidadesDeNegocio(escaparate.value?.estudio).citas,
);

// Descripción larga: se recorta y se abre con «Leer más».
const descripcionAbierta = ref(false);
const descripcionLarga = computed(() => textoPortada.value.length > 280);

// Servicios o clases, por categoría y con buscador.
const busqueda = ref("");
const categoria = ref<string | null>(null);
const servicios = computed(() => escaparate.value?.servicios ?? []);
// Solo las clases para principiantes (si el negocio marcó alguna).
const soloPrincipiantes = ref(false);
const hayPrincipiantes = computed(
  () => !usaCitas.value && servicios.value.some((x) => x.para_principiantes),
);
const categorias = computed(() => [
  ...new Set(
    servicios.value
      .map((x) => x.categoria)
      .filter((c): c is string => c !== null && c !== ""),
  ),
]);
const serviciosVisibles = computed(() => {
  const q = busqueda.value.trim().toLocaleLowerCase("es-MX");
  return servicios.value.filter(
    (x) =>
      (categoria.value === null || x.categoria === categoria.value) &&
      (!soloPrincipiantes.value || x.para_principiantes === true) &&
      (q === "" ||
        `${x.nombre} ${x.descripcion ?? ""}`
          .toLocaleLowerCase("es-MX")
          .includes(q)),
  );
});
const gruposServicios = computed(() => {
  const grupos = new Map<string, Servicio[]>();
  for (const x of serviciosVisibles.value) {
    const clave = x.categoria ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), x]);
  }
  return [...grupos.entries()].map(([nombre, lista]) => ({ nombre, lista }));
});

// Horario semanal de clases, por día (lunes a domingo).
const horarioPorDia = computed(() => {
  const filas = escaparate.value?.horario_clases ?? [];
  return [1, 2, 3, 4, 5, 6, 7]
    .map((dia) => ({ dia, clases: filas.filter((f) => f.dia === dia) }))
    .filter((d) => d.clases.length > 0);
});
const variasSedes = computed(
  () => (escaparate.value?.sucursales.length ?? 0) > 1,
);
function horaCorta(hora: string): string {
  return hora.slice(0, 5);
}
function horarioDeSede(su: Sucursal): { dia: number; texto: string | null }[] {
  return [1, 2, 3, 4, 5, 6, 7].map((dia) => {
    const d = (su.horario ?? []).find((h) => h.dia === dia);
    return { dia, texto: d ? `${d.abre} – ${d.cierra}` : null };
  });
}
// Precios: el destacado primero y luego la clase de prueba; el resto, por precio
// (como llegan). Así se comparan sin buscar.
const precios = computed(() => {
  const lista = escaparate.value?.productos ?? [];
  const orden = (p: Producto) => (p.destacado ? 0 : p.primera_vez ? 1 : 2);
  return [...lista].sort((a, b) => orden(a) - orden(b));
});
// Agrupados para comparar (M03): primero lo de la primera vez, luego clase suelta,
// paquetes, membresías y lo demás; con un solo grupo, sin títulos.
const GRUPOS_PRECIO = [
  "primera_vez",
  "sesion_individual",
  "paquete",
  "membresia",
  "otro",
  "add_on",
] as const;
type GrupoPrecio = (typeof GRUPOS_PRECIO)[number];
function grupoDe(p: Producto): GrupoPrecio {
  if (p.primera_vez) {
    return "primera_vez";
  }
  return (GRUPOS_PRECIO as readonly string[]).includes(p.tipo)
    ? (p.tipo as GrupoPrecio)
    : "otro";
}
// La sede que eligió en el calendario (M06): los planes muestran solo los que valen
// ahí; sin elegir, todos.
const sedeElegida = ref("");
const nombreSedeElegida = computed(
  () =>
    escaparate.value?.sucursales.find((s) => s.id === sedeElegida.value)
      ?.nombre ?? null,
);
function valeEnSede(p: Producto): boolean {
  return (
    sedeElegida.value === "" ||
    p.todas_sucursales !== false ||
    (p.sucursales ?? []).some((s) => s.id === sedeElegida.value)
  );
}
const gruposPrecios = computed(() =>
  GRUPOS_PRECIO.map((clave) => ({
    clave,
    lista: precios.value.filter((p) => grupoDe(p) === clave && valeEnSede(p)),
  })).filter((g) => g.lista.length > 0),
);
// Con semblanzas, el equipo se presenta en tarjetas; si no, en una línea.
const equipoConSemblanza = computed(
  () => escaparate.value?.instructores.some((p) => p.semblanza) ?? false,
);
// «8 de octubre» de una fecha AAAA-MM-DD, sin que la zona horaria la mueva.
// La página habla con las palabras del negocio, no con las de quien la visita (ADR
// 0131): los pacientes de un consultorio, los coaches de un estudio de pole, las
// lecciones de una escuela de natación.
const { t, te } = useI18n();
const terminosPagina = computed<TerminosNegocio | null>(() => {
  const terminos = escaparate.value?.estudio.perfil_config.terminologia;
  return terminos?.sesion && terminos.miembro && terminos.instructor
    ? {
        sesion: terminos.sesion,
        sesiones: terminos.sesiones,
        miembro: terminos.miembro,
        miembros: terminos.miembros,
        instructor: terminos.instructor,
        instructores: terminos.instructores,
      }
    : null;
});
function tn(
  clave: string,
  valores?: Record<string, unknown> | number,
  cuantos?: number,
): string {
  const texto =
    cuantos !== undefined
      ? t(clave, valores as Record<string, unknown>, cuantos)
      : typeof valores === "number"
        ? t(clave, valores)
        : valores !== undefined
          ? t(clave, valores)
          : t(clave);
  return terminosPagina.value
    ? adaptarTexto(texto, terminosPagina.value)
    : texto;
}
const etiquetaProfesional = computed(
  () =>
    escaparate.value?.estudio.perfil_config.terminologia?.instructor ??
    "Profesional",
);

// Con los números del país del negocio («$ 25.000,00» en Colombia).
function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, escaparate.value?.estudio.pais);
}
function fechaHora(iso: string | null, zona: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}
// La ficha de alguien del equipo (ADR 0167): su semblanza y qué hacer con él (agendar
// en citas; sus próximas clases en clases).
const fichaDe = ref<Integrante | null>(null);
const agendarCon = computed(() =>
  fichaDe.value?.id && usaCitas.value
    ? {
        name: "agendar-cita",
        params: { slug: slug.value },
        query: { profesional: fichaDe.value.id },
      }
    : null,
);
const clasesDe = computed(() => {
  const persona = fichaDe.value;
  if (persona === null || usaCitas.value) {
    return [];
  }
  return (escaparate.value?.proximas_sesiones ?? [])
    .filter((s) => s.instructor === persona.nombre)
    .slice(0, 3)
    .map((s) =>
      [s.clase, fechaHora(s.inicia_en, s.zona_horaria), s.sucursal]
        .filter(Boolean)
        .join(" · "),
    );
});

function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join("")
    .toUpperCase();
}

// Un enlace de banner a otra página se abre aparte; uno del sitio (/…, #…), aquí.
function esEnlaceExterno(url: string): boolean {
  return /^https?:\/\//i.test(url);
}

// El calendario por fecha no cargó: se queda la lista de próximas clases.
const calendarioFallo = ref(false);
// Lo que tenía elegido al pedir acceso (una clase, un plan): viaja al prospecto.
const interesAcceso = ref<{
  sesion?: string | null;
  producto?: string | null;
  etiqueta?: string | null;
} | null>(null);
// Si ya tiene cuenta, entra y sigue con lo que eligió (ADR 0142).
const volverTrasEntrar = computed(() => volverConInteres(interesAcceso.value));
// Con la captación encendida, «Pedir acceso» deja sus datos en Prospectos (ADR 0117).
const formularioAcceso = computed(() =>
  escaparate.value?.captacion?.activa
    ? (escaparate.value.captacion.formulario ?? null)
    : null,
);

// Eligió un plan (GA4 del negocio): qué plan, no quién.
function elegirPlan(p: Producto): void {
  if (!p.id) return;
  eventoComercio(
    "select_item",
    [
      {
        id: String(p.id),
        nombre: p.nombre,
        precio_minor: p.precio_minor,
        moneda: p.moneda,
      },
    ],
    escaparate.value?.estudio.perfil !== "salud",
  );
}

function abrirAcceso(
  origen: string,
  interes: {
    sesion?: string | null;
    producto?: string | null;
    etiqueta?: string | null;
  } | null = null,
): void {
  interesAcceso.value = interes;
  pidiendoAcceso.value = true;
  trackEvent("student_access_opened", {
    source: origen,
    business_profile: escaparate.value?.estudio.perfil ?? "unknown",
  });
}

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: Escaparate }>(
      props.vistaPrevia
        ? `/api/v1/app/${slug.value}/sitio/vista-previa`
        : `/api/v1/app/${slug.value}/escaparate`,
    );
    escaparate.value = data.data;
    const estudio = data.data.estudio;
    // La vista previa no cuenta como visita ni cambia la app instalable ni el SEO.
    if (props.vistaPrevia) {
      return;
    }
    // Sus planes a la vista (GA4 del negocio, ADR 0143); en salud, sin nombres.
    eventoComercio(
      "view_item_list",
      data.data.productos
        .filter((p) => p.id)
        .map((p) => ({
          id: String(p.id),
          nombre: p.nombre,
          precio_minor: p.precio_minor,
          moneda: p.moneda,
        })),
      estudio.perfil !== "salud",
    );
    // Su app instalable (en su subdominio): nombre, ícono y color.
    marcarPwa(estudio);
    recordarNegocio({
      slug: estudio.slug,
      nombre: estudio.nombre,
      logo_url: estudio.logo_url,
      ciudad: estudio.ciudad,
      pais: estudio.pais,
      // En la lista de su producto (ADR 0136).
      producto: productoDeModalidad(estudio.modalidad ?? "clases"),
    });
    aplicarSeo();
    trackEvent("public_studio_viewed", { business_profile: estudio.perfil });
  } catch {
    // 404 (estudio no publicado/privado/inexistente) u otro error: pantalla amable.
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

/**
 * Su SEO: el de Inicio (lo que escribió en su sitio o, si no, el de siempre) o el de la
 * página que se ve (el suyo o su título con el nombre del negocio). La vista previa no
 * toca el SEO.
 */
function aplicarSeo(): void {
  const estudio = escaparate.value?.estudio;
  if (!estudio || props.vistaPrevia) {
    return;
  }
  const lugar = [estudio.ciudad, estudio.pais].filter(Boolean).join(", ");
  const esCitas = capacidadesDeNegocio(estudio).citas;
  const pagina = paginaActual.value;
  const propio = pagina ? pagina.seo : sitio.value.seo;
  const imagen =
    propio?.imagen_url ?? estudio.portada_url ?? estudio.logo_url ?? undefined;
  if (pagina) {
    updateSeo({
      title: propio?.titulo ?? `${pagina.titulo} | ${estudio.nombre}`,
      description:
        propio?.descripcion ?? `${pagina.titulo} de ${estudio.nombre}.`,
      path: urlCanonicaEstudio(
        estudio.slug,
        `/estudio/${estudio.slug}/p/${pagina.slug}`,
      ),
      image: imagen,
      imageAlt: estudio.nombre,
      type: "website",
      index: estudio.en_directorio !== false,
    });
    return;
  }
  updateSeo({
    title:
      propio?.titulo ??
      `${estudio.nombre} | ${esCitas ? "Servicios y citas" : "Horarios y clases"} en ${PRODUCTOS[esCitas ? "turnouno" : "agendauno"].nombre}`,
    description:
      propio?.descripcion ??
      (esCitas
        ? `Consulta servicios, profesionales y horarios disponibles de ${estudio.nombre}${lugar ? ` en ${lugar}` : ""}. Reserva tu cita en línea.`
        : `Consulta próximas clases, instructores y precios de ${estudio.nombre}${lugar ? ` en ${lugar}` : ""}.`),
    // La página del negocio vive en su subdominio.
    path: urlCanonicaEstudio(estudio.slug),
    image: imagen,
    imageAlt: estudio.nombre,
    type: "profile",
    index: estudio.en_directorio !== false,
    // Los del servidor (giro, dirección, teléfono y horario por sucursal): así la
    // página y su cabecera no se contradicen. Sin ellos, los básicos de siempre.
    jsonLd: escaparate.value?.json_ld ?? {
      "@context": "https://schema.org",
      "@type": "LocalBusiness",
      name: estudio.nombre,
      url: urlCanonicaEstudio(estudio.slug),
      image: estudio.logo_url ?? undefined,
      address: lugar || undefined,
      description: estudio.descripcion ?? undefined,
      sameAs: (estudio.redes ?? []).map((r) => r.url),
    },
  });
}

// Su tipografía, si es de Google Fonts, se carga cuando se usa.
watch(
  familiaDelSitio,
  (familia) => {
    if (familia) {
      cargarFuente(familia);
    }
  },
  { immediate: true },
);
// Otra página del sitio: arriba, con su SEO.
watch(paginaActual, async () => {
  aplicarSeo();
  await nextTick();
  if (typeof window !== "undefined" && typeof window.scrollTo === "function") {
    window.scrollTo({ top: 0 });
  }
});

// ---- Dentro del editor visual (ADR 0146, fase 3) ----
// La vista previa en el marco del editor: muestra el borrador sin guardar que le manda
// y, con un clic en una sección, le pide abrirla para editarla.
const editor = props.vistaPrevia ? ventanaDelEditor() : null;
const enEditor = editor !== null;
const resaltada = ref<{ clave: string; tipo: string } | null>(null);
const enfocada = ref<string | null>(null);
let peticionEnVivo = 0;

async function mostrarBorrador(borrador: unknown): Promise<void> {
  const mia = ++peticionEnVivo;
  try {
    const { data } = await api.post<{ data: Escaparate }>(
      `/api/v1/app/${slug.value}/sitio/vista-previa`,
      borrador,
    );
    // Solo la respuesta del último cambio: una anterior que llegue tarde no lo pisa.
    if (mia === peticionEnVivo) {
      escaparate.value = data.data;
      avisarEditorSitio(editor, { tipo: "estado", valido: true });
    }
  } catch {
    // Algo por completar en el borrador: se queda el último que se pudo mostrar.
    if (mia === peticionEnVivo) {
      avisarEditorSitio(editor, { tipo: "estado", valido: false });
    }
  }
}
function seccionDe(evento: Event): HTMLElement | null {
  const destino = evento.target as HTMLElement | null;
  return destino?.closest<HTMLElement>("[data-seccion]") ?? null;
}
function alPasarEnEditor(evento: MouseEvent): void {
  const seccion = enEditor ? seccionDe(evento) : null;
  resaltada.value = seccion
    ? {
        clave: seccion.dataset.seccion ?? "",
        tipo: seccion.dataset.tipo ?? "",
      }
    : null;
}
// Un clic en una sección la abre en el editor; sus enlaces y botones siguen funcionando.
function alClicEnEditor(evento: MouseEvent): void {
  const seccion = enEditor ? seccionDe(evento) : null;
  const destino = evento.target as HTMLElement | null;
  if (
    !seccion ||
    destino?.closest(
      "a, button, input, select, textarea, label, summary, iframe, [role='button']",
    )
  ) {
    return;
  }
  enfocada.value = seccion.dataset.seccion ?? null;
  avisarEditorSitio(editor, {
    tipo: "editar",
    pagina: paginaActual.value?.slug ?? null,
    seccion: seccion.dataset.seccion ?? "",
  });
}
// La que se abrió en el editor: se lleva a la vista y se marca.
function enfocarSeccion(clave: string): void {
  enfocada.value = clave;
  const seccion = [
    ...document.querySelectorAll<HTMLElement>("[data-seccion]"),
  ].find((e) => e.dataset.seccion === clave);
  seccion?.firstElementChild?.scrollIntoView?.({
    behavior: "smooth",
    block: "start",
  });
}
function nombreEnEditor(tipo: string): string {
  return esBloque(tipo)
    ? t(`sitioWeb.bloques.nombres.${tipo}`)
    : t(
        claveSegunModalidad(
          `sitioWeb.secciones.nombres.${tipo}`,
          usaCitas.value,
          te,
        ),
      );
}
function alMensajeDelEditor(evento: MessageEvent): void {
  const mensaje = mensajeEditorSitio(evento, editor);
  if (mensaje?.tipo === "borrador") {
    void mostrarBorrador(mensaje.borrador);
  } else if (mensaje?.tipo === "enfocar") {
    enfocarSeccion(mensaje.seccion);
  }
}

onMounted(async () => {
  if (enEditor) {
    window.addEventListener("message", alMensajeDelEditor);
  }
  await cargar();
  // Lista para recibir el borrador sin guardar.
  avisarEditorSitio(editor, { tipo: "lista" });
});
onBeforeUnmount(() => {
  window.removeEventListener("message", alMensajeDelEditor);
});
</script>

<template>
  <div>
    <p
      v-if="cargando"
      class="mx-auto max-w-5xl px-4 py-16 text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ tn("escaparate.cargando") }}
    </p>

    <section
      v-else-if="noDisponible"
      class="mx-auto max-w-md px-4 py-20 text-center"
    >
      <p class="text-lg font-semibold">{{ tn("escaparate.noDisponible") }}</p>
      <RouterLink
        :to="{ name: 'directorio' }"
        class="tu-enlace mt-4 inline-block"
        >{{ tn("escaparate.volverDirectorio") }}</RouterLink
      >
    </section>

    <div
      v-else-if="escaparate"
      :class="[
        `ep-sitio ep-plantilla-${sitio.plantilla}`,
        `ep-tema-${temaDelSitio}`,
      ]"
      :style="estiloMarca"
      data-prueba="sitio"
      @mouseover="alPasarEnEditor"
      @mouseleave="resaltada = null"
      @click.capture="alClicEnEditor"
    >
      <p
        v-if="props.vistaPrevia"
        class="ep-aviso-previa"
        data-prueba="aviso-vista-previa"
      >
        {{ $t("sitioWeb.publico.avisoVistaPrevia") }}
      </p>
      <!-- Su app instalable (PWA), en su subdominio. -->
      <div v-else class="mx-auto max-w-3xl px-4 pt-4">
        <InstalarApp :negocio="escaparate.estudio.nombre" />
      </div>

      <!-- Su barra: Inicio y sus páginas, y su botón principal (ADR 0146) -->
      <nav
        v-if="conBarra"
        class="ep-menu"
        :aria-label="$t('sitioWeb.publico.menu')"
        data-prueba="menu-sitio"
      >
        <div class="mx-auto flex max-w-5xl items-center gap-1 px-4">
          <div class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
            <RouterLink
              :to="aInicio()"
              class="ep-menu-enlace"
              :aria-current="paginaActual === null ? 'page' : undefined"
              >{{
                paginasDelMenu.length > 0
                  ? $t("sitioWeb.publico.inicio")
                  : escaparate.estudio.nombre
              }}</RouterLink
            >
            <RouterLink
              v-for="p in paginasDelMenu"
              :key="p.slug"
              :to="aPagina(p.slug)"
              class="ep-menu-enlace"
              :aria-current="paginaActual?.slug === p.slug ? 'page' : undefined"
              >{{ p.titulo }}</RouterLink
            >
          </div>
          <a
            v-if="botonPrincipal"
            :href="botonPrincipal.enlace"
            class="tu-btn tu-btn-primario ep-menu-boton shrink-0"
            :target="
              esEnlaceExterno(botonPrincipal.enlace) ? '_blank' : undefined
            "
            :rel="
              esEnlaceExterno(botonPrincipal.enlace)
                ? 'noopener noreferrer'
                : undefined
            "
            data-prueba="boton-principal"
            >{{ botonPrincipal.texto }}</a
          >
        </div>
      </nav>

      <!-- Una página propia: su nombre arriba -->
      <header
        v-if="paginaActual"
        class="ep-pagina-cabecera"
        data-prueba="pagina-cabecera"
      >
        <div class="mx-auto max-w-5xl px-4 py-10">
          <RouterLink :to="aInicio()" class="ep-pagina-negocio">{{
            escaparate.estudio.nombre
          }}</RouterLink>
          <h1 class="mt-1 text-3xl font-light">{{ paginaActual.titulo }}</h1>
        </div>
      </header>
      <section
        v-else-if="paginaNoExiste"
        class="mx-auto max-w-md px-4 py-20 text-center"
        data-prueba="pagina-no-existe"
      >
        <p>{{ $t("sitioWeb.publico.noExiste") }}</p>
        <RouterLink :to="aInicio()" class="tu-enlace mt-3 inline-block">{{
          $t("sitioWeb.publico.irAInicio")
        }}</RouterLink>
      </section>

      <!-- Cada sección donde la puso el negocio (ADR 0114); la portada primero y el
           contacto al final. Lo que muestra cada una sale del sistema. -->
      <div
        v-for="sec in seccionesAMostrar"
        :key="sec.id ?? sec.tipo"
        class="contents"
        :class="{
          'ep-en-editor': enEditor,
          'ep-resaltada': enEditor && resaltada?.clave === claveSeccion(sec),
          'ep-enfocada': enEditor && enfocada === claveSeccion(sec),
        }"
        :data-seccion="claveSeccion(sec)"
        :data-tipo="sec.tipo"
      >
        <!-- Portada -->
        <template v-if="sec.tipo === 'inicio'">
          <div
            v-if="portada === 'esencial' && escaparate.estudio.portada_url"
            class="ep-portada"
            :style="{
              backgroundImage: `url(${escaparate.estudio.portada_url})`,
            }"
            role="img"
            :aria-label="escaparate.estudio.nombre"
          ></div>
          <section
            id="inicio"
            class="px-4"
            :class="{
              'py-14 text-center': portada === 'esencial',
              'pt-0': portada === 'esencial' && escaparate.estudio.portada_url,
              'ep-portada-foto py-24 text-center': portada === 'foto',
              'py-8': portada === 'compacta',
            }"
            :style="
              portada === 'foto'
                ? {
                    backgroundImage: `url(${escaparate.estudio.portada_url})`,
                  }
                : { background: 'var(--superficie)' }
            "
            data-prueba="portada"
            :data-portada="portada"
          >
            <div
              :class="
                portada === 'compacta'
                  ? 'ep-compacta mx-auto max-w-5xl'
                  : [
                      'mx-auto max-w-3xl',
                      {
                        'ep-sobre-portada':
                          portada === 'esencial' &&
                          escaparate.estudio.portada_url,
                      },
                    ]
              "
            >
              <img
                v-if="escaparate.estudio.logo_url"
                :src="escaparate.estudio.logo_url"
                :alt="escaparate.estudio.nombre"
                class="rounded-2xl object-cover"
                :class="
                  portada === 'compacta' ? 'h-14 w-14' : 'mx-auto h-20 w-20'
                "
                :style="{ boxShadow: 'var(--sombra)' }"
              />
              <span
                v-else
                class="flex items-center justify-center rounded-2xl text-2xl font-bold"
                :class="
                  portada === 'compacta' ? 'h-14 w-14' : 'mx-auto h-20 w-20'
                "
                :style="{
                  background: 'var(--primario)',
                  color: 'var(--primario-contraste)',
                }"
                aria-hidden="true"
                >{{ iniciales(escaparate.estudio.nombre) }}</span
              >

              <div :class="{ 'min-w-0 flex-1': portada === 'compacta' }">
                <h1
                  class="font-extrabold tracking-tight"
                  :class="portada === 'compacta' ? 'text-3xl' : 'mt-5 text-4xl'"
                >
                  {{ titular }}
                </h1>
                <!-- «Otro negocio con clases / de citas» es para el registro, no para
                     los clientes: sin insignia. -->
                <span
                  v-if="perfilVisibleAlPublico(escaparate.estudio.perfil)"
                  class="tu-badge mt-4"
                  data-prueba="insignia-giro"
                  >{{
                    $t(`registro.perfiles.${escaparate.estudio.perfil}`)
                  }}</span
                >
                <p
                  v-if="ubicacion"
                  class="mt-2 text-lg"
                  :style="{
                    color:
                      portada === 'foto' ? 'inherit' : 'var(--texto-suave)',
                  }"
                >
                  {{ ubicacion }}
                </p>
              </div>
              <div
                v-if="textoPortada"
                class="mx-auto mt-5 max-w-2xl text-left"
                :class="{
                  'sm:text-center': portada !== 'compacta',
                  'ep-compacta-ancho': portada === 'compacta',
                }"
              >
                <p
                  class="whitespace-pre-line"
                  :class="{
                    'ep-recortada': descripcionLarga && !descripcionAbierta,
                  }"
                  data-prueba="descripcion"
                >
                  {{ textoPortada }}
                </p>
                <button
                  v-if="descripcionLarga"
                  type="button"
                  class="tu-enlace mt-1 text-sm"
                  @click="descripcionAbierta = !descripcionAbierta"
                >
                  {{
                    descripcionAbierta
                      ? $t("perfilPublico.publico.leerMenos")
                      : $t("perfilPublico.publico.leerMas")
                  }}
                </button>
              </div>
              <ul
                v-if="
                  (escaparate.estudio.redes ?? []).length > 0 ||
                  escaparate.estudio.whatsapp_url
                "
                class="mt-5 flex flex-wrap gap-2"
                :class="
                  portada === 'compacta'
                    ? 'ep-compacta-ancho'
                    : 'justify-center'
                "
                data-prueba="redes"
              >
                <li v-for="r in escaparate.estudio.redes ?? []" :key="r.red">
                  <a
                    :href="r.url"
                    target="_blank"
                    rel="noopener"
                    class="ep-red"
                    :aria-label="$t(`perfilPublico.redes.${r.red}`)"
                    :title="$t(`perfilPublico.redes.${r.red}`)"
                  >
                    <IconoRed :red="r.red" />
                  </a>
                </li>
                <li v-if="escaparate.estudio.whatsapp_url">
                  <a
                    :href="escaparate.estudio.whatsapp_url"
                    target="_blank"
                    rel="noopener"
                    class="ep-red"
                    :aria-label="$t('perfilPublico.publico.whatsapp')"
                    :title="$t('perfilPublico.publico.whatsapp')"
                  >
                    <IconoRed red="whatsapp" />
                  </a>
                </li>
              </ul>

              <div
                class="mt-7 flex flex-wrap gap-3"
                :class="
                  portada === 'compacta'
                    ? 'ep-compacta-ancho'
                    : 'justify-center'
                "
              >
                <RouterLink
                  v-if="usaCitas"
                  :to="{ name: 'agendar-cita', params: { slug } }"
                  class="tu-btn tu-btn-primario px-6"
                  @click="
                    trackEvent('book_appointment_clicked', { source: 'hero' })
                  "
                >
                  {{ tn("escaparate.agendarCita") }}
                </RouterLink>
                <button
                  v-if="!usaCitas"
                  type="button"
                  class="tu-btn tu-btn-primario px-6"
                  @click="abrirAcceso('hero')"
                >
                  {{ tn("escaparate.reservar") }}
                </button>
                <RouterLink
                  :to="{ name: 'entrar', query: { estudio: slug } }"
                  class="tu-btn tu-btn-secundario px-6"
                  @click="
                    trackEvent('student_login_clicked', {
                      source: 'public_studio',
                    })
                  "
                >
                  {{ tn("escaparate.yaSoyAlumno") }}
                </RouterLink>
              </div>
            </div>
          </section>
        </template>

        <!-- Promociones: los banners vigentes -->
        <section
          v-else-if="sec.tipo === 'promociones' && sitio.banners.length > 0"
          id="promociones"
          class="mx-auto max-w-5xl px-4 py-10"
          data-prueba="promociones"
        >
          <h2 v-if="sec.titulo" class="mb-5 text-2xl font-light">
            {{ sec.titulo }}
          </h2>
          <ul
            class="grid gap-4"
            :class="{ 'sm:grid-cols-2': sitio.banners.length > 1 }"
          >
            <li
              v-for="b in sitio.banners"
              :key="b.id ?? b.titulo"
              class="ep-banner tu-card"
            >
              <img
                v-if="b.foto_url"
                :src="b.foto_url"
                alt=""
                class="ep-banner-foto"
                loading="lazy"
              />
              <div class="p-5">
                <p class="text-lg font-medium">{{ b.titulo }}</p>
                <p
                  v-if="b.texto"
                  class="mt-1"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ b.texto }}
                </p>
                <a
                  v-if="b.enlace_url && b.enlace_texto"
                  :href="enlaceDelSitio(b.enlace_url)"
                  class="tu-btn tu-btn-primario mt-4 px-5"
                  :target="esEnlaceExterno(b.enlace_url) ? '_blank' : undefined"
                  :rel="
                    esEnlaceExterno(b.enlace_url)
                      ? 'noopener noreferrer'
                      : undefined
                  "
                  >{{ b.enlace_texto }}</a
                >
              </div>
            </li>
          </ul>
        </section>

        <!-- Nosotros: su historia, con su foto -->
        <section
          v-else-if="sec.tipo === 'nosotros' && (sec.texto || sec.foto_url)"
          id="nosotros"
          class="mx-auto max-w-5xl px-4 py-12"
          data-prueba="nosotros"
        >
          <div
            class="grid items-center gap-8"
            :class="{ 'md:grid-cols-2': sec.foto_url && sec.texto }"
          >
            <img
              v-if="sec.foto_url"
              :src="sec.foto_url"
              :alt="sec.titulo ?? escaparate.estudio.nombre"
              class="ep-nosotros-foto"
              loading="lazy"
            />
            <div>
              <h2 class="text-2xl font-light">
                {{ sec.titulo ?? $t("sitioWeb.publico.nosotros") }}
              </h2>
              <p v-if="sec.texto" class="mt-4 whitespace-pre-line">
                {{ sec.texto }}
              </p>
            </div>
          </div>
        </section>

        <template v-else-if="sec.tipo === 'agenda'">
          <!-- Flujo de citas -->
          <section v-if="usaCitas" id="agenda" class="tu-agenda-citas">
            <div class="mx-auto max-w-5xl px-4 py-12 sm:py-16">
              <div class="tu-agenda-citas-cabecera">
                <div>
                  <p class="tu-agenda-citas-etiqueta">
                    {{ tn("escaparate.agendaEnLinea") }}
                  </p>
                  <h2 class="text-3xl font-light tracking-tight">
                    {{ sec.titulo ?? tn("escaparate.agendaCitasTitulo") }}
                  </h2>
                  <p
                    class="mt-3 max-w-2xl"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ tn("escaparate.agendaCitasDesc") }}
                  </p>
                </div>
                <RouterLink
                  :to="{ name: 'agendar-cita', params: { slug } }"
                  class="tu-btn tu-btn-primario shrink-0 px-6"
                  @click="
                    trackEvent('book_appointment_clicked', { source: 'steps' })
                  "
                >
                  {{ tn("escaparate.agendarCita") }}
                </RouterLink>
              </div>
              <ol class="tu-agenda-pasos mt-9">
                <li v-for="n in 3" :key="n">
                  <span class="tu-agenda-paso-num">0{{ n }}</span>
                  <strong>{{ tn(`escaparate.agendaPaso${n}`) }}</strong>
                  <span
                    v-if="n < 3"
                    class="tu-agenda-paso-linea"
                    aria-hidden="true"
                  ></span>
                </li>
              </ol>
            </div>
          </section>

          <!-- Próximas clases -->
          <section v-else id="agenda" class="mx-auto max-w-5xl px-4 py-12">
            <h2 class="text-2xl font-light">
              {{ sec.titulo ?? tn("escaparate.proximasClases") }}
            </h2>
            <!-- Calendario por fecha (M02); sin él (p. ej. la vista previa con la
                 página cerrada), la lista de próximas clases de siempre. -->
            <CalendarioPublico
              v-if="!props.vistaPrevia && !calendarioFallo"
              v-model:sucursal="sedeElegida"
              class="mt-6"
              :slug="slug"
              :puede-pedir="!!formularioAcceso"
              @pedir="(c) => abrirAcceso('calendar', c)"
              @error="calendarioFallo = true"
            />
            <p
              v-else-if="escaparate.proximas_sesiones.length === 0"
              class="mt-4"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ tn("escaparate.sinClases") }}
            </p>
            <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <li
                v-for="(s, i) in escaparate.proximas_sesiones"
                :key="i"
                class="tu-card p-5"
              >
                <p class="font-light">{{ s.clase ?? "—" }}</p>
                <p
                  class="mt-1 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ fechaHora(s.inicia_en, s.zona_horaria) }}
                </p>
                <p
                  v-if="s.sucursal || s.instructor"
                  class="mt-1 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ [s.sucursal, s.instructor].filter(Boolean).join(" · ") }}
                </p>
                <button
                  v-if="formularioAcceso && s.id && s.lugares_libres !== 0"
                  type="button"
                  class="tu-enlace mt-3 mr-3 text-sm"
                  data-prueba="pedir-lugar"
                  @click="
                    abrirAcceso('session', {
                      sesion: s.id,
                      etiqueta: `${s.clase ?? ''} · ${fechaHora(s.inicia_en, s.zona_horaria)}`,
                    })
                  "
                >
                  {{ tn("escaparate.pedirLugar") }}
                </button>
                <span
                  class="tu-badge mt-3"
                  :class="
                    s.lugares_libres === 0 ? 'tu-badge-aviso' : 'tu-badge-exito'
                  "
                >
                  <template v-if="s.lugares_libres === null">{{
                    tn("escaparate.cupoAbierto")
                  }}</template>
                  <template v-else-if="s.lugares_libres === 0">{{
                    tn("escaparate.lleno")
                  }}</template>
                  <template v-else>{{
                    tn("escaparate.lugaresLibres", { n: s.lugares_libres })
                  }}</template>
                </span>
              </li>
            </ul>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'servicios'">
          <!-- Servicios o clases, por categoría -->
          <section
            v-if="servicios.length > 0"
            id="servicios"
            class="mx-auto max-w-5xl px-4 py-12"
            data-prueba="servicios"
          >
            <div class="flex flex-wrap items-end justify-between gap-3">
              <h2 class="text-2xl font-light">
                {{
                  sec.titulo ??
                  (usaCitas
                    ? $t("perfilPublico.publico.servicios")
                    : $t("perfilPublico.publico.clases"))
                }}
              </h2>
              <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                <label
                  v-if="hayPrincipiantes"
                  class="flex items-center gap-2 text-sm"
                  data-prueba="solo-principiantes"
                >
                  <input v-model="soloPrincipiantes" type="checkbox" />
                  {{ $t("perfilPublico.publico.soloPrincipiantes") }}
                </label>
                <input
                  v-if="servicios.length > 5"
                  v-model="busqueda"
                  type="search"
                  class="tu-input w-full sm:w-64"
                  :placeholder="$t('perfilPublico.publico.buscar')"
                  :aria-label="$t('perfilPublico.publico.buscar')"
                />
              </div>
            </div>
            <div
              v-if="categorias.length > 1"
              class="tu-segmentado mt-4 flex-wrap"
              role="group"
            >
              <button
                type="button"
                :aria-pressed="categoria === null"
                @click="categoria = null"
              >
                {{ $t("perfilPublico.publico.todos") }}
              </button>
              <button
                v-for="c in categorias"
                :key="c"
                type="button"
                :aria-pressed="categoria === c"
                @click="categoria = c"
              >
                {{ c }}
              </button>
            </div>
            <p
              v-if="serviciosVisibles.length === 0"
              class="mt-6"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("perfilPublico.publico.sinCoincidencias") }}
            </p>
            <div v-for="g in gruposServicios" :key="g.nombre" class="mt-6">
              <h3
                v-if="g.nombre && categorias.length > 1 && categoria === null"
                class="text-sm font-semibold"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ g.nombre }}
              </h3>
              <ul class="mt-2 divide-y divide-[var(--borde)]">
                <li
                  v-for="x in g.lista"
                  :key="x.id"
                  class="flex flex-wrap items-start justify-between gap-3 py-4"
                >
                  <img
                    v-if="x.foto_url"
                    :src="x.foto_url"
                    alt=""
                    class="h-16 w-16 shrink-0 rounded-xl object-cover"
                  />
                  <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 font-semibold">
                      {{ x.nombre }}
                      <span
                        v-if="x.para_principiantes && !usaCitas"
                        class="tu-badge"
                        data-prueba="principiantes"
                        >{{ $t("perfilPublico.publico.principiantes") }}</span
                      >
                    </p>
                    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                      {{
                        [
                          x.duracion_minutos
                            ? $t("perfilPublico.publico.duracion", {
                                n: x.duracion_minutos,
                              })
                            : null,
                          x.niveles.length > 0
                            ? $t("perfilPublico.publico.niveles", {
                                lista: x.niveles.join(", "),
                              })
                            : null,
                        ]
                          .filter(Boolean)
                          .join(" · ")
                      }}
                    </p>
                    <p v-if="x.descripcion" class="mt-1 text-sm">
                      {{ x.descripcion }}
                    </p>
                    <dl
                      v-if="x.requisitos || x.que_llevar"
                      class="ep-primera-vez mt-2 text-sm"
                      data-prueba="primera-vez-clase"
                    >
                      <template v-if="x.requisitos">
                        <dt>{{ $t("perfilPublico.publico.requisitos") }}</dt>
                        <dd>{{ x.requisitos }}</dd>
                      </template>
                      <template v-if="x.que_llevar">
                        <dt>{{ $t("perfilPublico.publico.queLlevar") }}</dt>
                        <dd>{{ x.que_llevar }}</dd>
                      </template>
                    </dl>
                    <ServicioIncluye
                      class="mt-1"
                      :incluye="x.incluye"
                      :precio-minor="x.precio_minor"
                      :por-separado-minor="x.precio_por_separado_minor"
                      :moneda="x.moneda"
                      :pais="escaparate?.estudio.pais"
                    />
                  </div>
                  <div class="flex items-center gap-3">
                    <span
                      v-if="x.precio_minor !== null"
                      class="font-semibold tabular-nums"
                      >{{ dinero(x.precio_minor, x.moneda) }}</span
                    >
                    <RouterLink
                      v-if="usaCitas && x.agendable"
                      :to="{ name: 'agendar-cita', params: { slug } }"
                      class="tu-btn tu-btn-secundario"
                      @click="
                        trackEvent('book_appointment_clicked', {
                          source: 'service',
                        })
                      "
                    >
                      {{ $t("perfilPublico.publico.agendar") }}
                    </RouterLink>
                  </div>
                </li>
              </ul>
            </div>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'horario'">
          <!-- Horario semanal de clases -->
          <section
            v-if="horarioPorDia.length > 0"
            id="horario"
            class="py-12"
            :style="{ background: 'var(--superficie)' }"
            data-prueba="horario-clases"
          >
            <div class="mx-auto max-w-5xl px-4">
              <h2 class="text-2xl font-light">
                {{ sec.titulo ?? $t("perfilPublico.publico.horarioClases") }}
              </h2>
              <div class="ep-semana mt-6">
                <div v-for="d in horarioPorDia" :key="d.dia">
                  <h3 class="text-sm font-semibold">
                    {{ $t(`perfilPublico.dias.${d.dia}`) }}
                  </h3>
                  <ul class="mt-2 space-y-2">
                    <li v-for="(c, i) in d.clases" :key="i" class="ep-clase">
                      <p class="text-sm font-semibold tabular-nums">
                        {{ horaCorta(c.hora) }}
                      </p>
                      <p class="text-sm">{{ c.clase }}</p>
                      <p
                        v-if="c.instructor || (variasSedes && c.sucursal)"
                        class="text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{
                          [c.instructor, variasSedes ? c.sucursal : null]
                            .filter(Boolean)
                            .join(" · ")
                        }}
                      </p>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'precios'">
          <!-- Precios -->
          <section
            id="precios"
            class="py-12"
            :style="{ background: 'var(--superficie)' }"
          >
            <div class="mx-auto max-w-5xl px-4">
              <h2 class="text-2xl font-light">
                {{ sec.titulo ?? tn("escaparate.precios") }}
              </h2>
              <p
                v-if="escaparate.productos.length === 0"
                class="mt-4"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ tn("escaparate.sinPrecios") }}
              </p>
              <template v-else>
                <p
                  v-if="variasSedes && nombreSedeElegida"
                  class="mt-3 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  data-prueba="planes-de-sede"
                >
                  {{
                    tn("escaparate.planes.deSede", {
                      sede: nombreSedeElegida,
                    })
                  }}
                  <button
                    type="button"
                    class="tu-enlace ml-1"
                    @click="sedeElegida = ''"
                  >
                    {{ tn("escaparate.planes.verTodos") }}
                  </button>
                </p>
                <div
                  v-for="g in gruposPrecios"
                  :key="g.clave"
                  class="mt-6"
                  data-prueba="grupo-precios"
                >
                  <h3
                    v-if="gruposPrecios.length > 1"
                    class="mb-3 text-sm font-semibold"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{
                      $t(
                        `escaparate.planes.${usaCitas ? "gruposCitas" : "grupos"}.${g.clave}`,
                      )
                    }}
                  </h3>
                  <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                      v-for="(p, i) in g.lista"
                      :key="p.id ?? i"
                      data-prueba="precio"
                    >
                      <TarjetaPlanPublico
                        :plan="p"
                        :terminos="terminosPagina"
                        :pais="escaparate?.estudio.pais"
                        :varias-sedes="variasSedes"
                        :marcar-primera-vez="gruposPrecios.length === 1"
                        :boton="tn('escaparate.planes.loQuiero')"
                        @elegir="
                          elegirPlan(p);
                          abrirAcceso('price_card', {
                            producto: p.id ?? null,
                            etiqueta: p.nombre,
                          });
                        "
                      />
                    </li>
                  </ul>
                </div>
              </template>
            </div>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'equipo'">
          <!-- Profesionales o instructores -->
          <section
            v-if="escaparate.instructores.length > 0"
            id="equipo"
            class="mx-auto max-w-5xl px-4 py-12"
          >
            <h2 class="text-2xl font-light">
              {{
                sec.titulo ??
                (usaCitas
                  ? tn("escaparate.profesionales")
                  : tn("escaparate.instructores"))
              }}
            </h2>
            <p
              v-if="usaCitas"
              class="mt-2 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                tn("escaparate.profesionalesDesc", {
                  profesional: etiquetaProfesional,
                })
              }}
            </p>
            <!-- Carrusel (ADR 0167): una fila que se desliza -->
            <CarruselSitio
              v-if="sec.presentacion === 'carrusel'"
              class="mt-6"
              :items="escaparate.instructores"
              :etiqueta="sec.titulo ?? tn('escaparate.instructores')"
            >
              <template #default="{ item: p }">
                <button
                  type="button"
                  class="ep-integrante ep-integrante-tarjeta"
                  data-prueba="integrante"
                  @click="fichaDe = p"
                >
                  <img
                    v-if="p.foto_url"
                    :src="p.foto_url"
                    alt=""
                    class="ep-integrante-foto"
                    loading="lazy"
                  />
                  <span
                    v-else
                    class="ep-integrante-foto ep-integrante-iniciales"
                    aria-hidden="true"
                    >{{ iniciales(p.nombre) }}</span
                  >
                  <span class="mt-3 block font-semibold">{{ p.nombre }}</span>
                  <span
                    v-if="p.semblanza"
                    class="ep-integrante-semblanza mt-1 block text-sm"
                    >{{ p.semblanza }}</span
                  >
                </button>
              </template>
            </CarruselSitio>

            <!-- Mosaico (ADR 0167): sus fotos, con su nombre encima -->
            <ul
              v-else-if="sec.presentacion === 'mosaico'"
              class="ep-mosaico mt-6"
            >
              <li v-for="(p, i) in escaparate.instructores" :key="p.id ?? i">
                <button
                  type="button"
                  class="ep-mosaico-celda"
                  data-prueba="integrante"
                  :aria-label="
                    $t('sitioWeb.publico.verPerfil', { nombre: p.nombre })
                  "
                  @click="fichaDe = p"
                >
                  <img
                    v-if="p.foto_url"
                    :src="p.foto_url"
                    alt=""
                    loading="lazy"
                  />
                  <span
                    v-else
                    class="ep-integrante-iniciales"
                    aria-hidden="true"
                    >{{ iniciales(p.nombre) }}</span
                  >
                  <span class="ep-mosaico-nombre">{{ p.nombre }}</span>
                </button>
              </li>
            </ul>

            <!-- Tarjetas (ADR 0167): foto grande, nombre y el inicio de su semblanza -->
            <ul
              v-else-if="sec.presentacion === 'tarjetas'"
              class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
              <li v-for="(p, i) in escaparate.instructores" :key="p.id ?? i">
                <button
                  type="button"
                  class="ep-integrante ep-integrante-tarjeta h-full"
                  data-prueba="integrante"
                  @click="fichaDe = p"
                >
                  <img
                    v-if="p.foto_url"
                    :src="p.foto_url"
                    alt=""
                    class="ep-integrante-foto"
                    loading="lazy"
                  />
                  <span
                    v-else
                    class="ep-integrante-foto ep-integrante-iniciales"
                    aria-hidden="true"
                    >{{ iniciales(p.nombre) }}</span
                  >
                  <span class="mt-3 block font-semibold">{{ p.nombre }}</span>
                  <span
                    v-if="p.semblanza"
                    class="ep-integrante-semblanza mt-1 block text-sm"
                    >{{ p.semblanza }}</span
                  >
                  <span class="tu-enlace mt-2 block text-sm">{{
                    $t("sitioWeb.publico.verMas")
                  }}</span>
                </button>
              </li>
            </ul>

            <!-- Como siempre: en una línea o, con semblanzas, en tarjetas -->
            <ul
              v-else
              class="mt-6"
              :class="
                equipoConSemblanza
                  ? 'grid gap-4 sm:grid-cols-2'
                  : 'flex flex-wrap gap-4'
              "
            >
              <li
                v-for="(p, i) in escaparate.instructores"
                :key="p.id ?? i"
                data-prueba="integrante"
              >
                <!-- Con semblanza o para agendar con él, se abre su ficha. -->
                <component
                  :is="p.semblanza || usaCitas ? 'button' : 'div'"
                  :type="p.semblanza || usaCitas ? 'button' : undefined"
                  class="ep-integrante flex gap-3"
                  :class="equipoConSemblanza ? 'items-start' : 'items-center'"
                  @click="p.semblanza || usaCitas ? (fichaDe = p) : undefined"
                >
                  <img
                    v-if="p.foto_url"
                    :src="p.foto_url"
                    alt=""
                    class="h-11 w-11 rounded-full object-cover"
                  />
                  <span
                    v-else
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full font-bold"
                    :style="{
                      background: 'var(--primario)',
                      color: 'var(--primario-contraste)',
                    }"
                    aria-hidden="true"
                    >{{ iniciales(p.nombre) }}</span
                  >
                  <span class="min-w-0 text-left">
                    <span class="block font-semibold">{{ p.nombre }}</span>
                    <span
                      v-if="p.semblanza"
                      class="ep-integrante-semblanza mt-1 block text-sm"
                      >{{ p.semblanza }}</span
                    >
                  </span>
                </component>
              </li>
            </ul>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'resenas'">
          <!-- Reseñas visibles de sus clientes -->
          <section
            v-if="(escaparate.resenas?.total ?? 0) > 0"
            id="resenas"
            class="mx-auto max-w-5xl px-4 py-12"
          >
            <h2 class="text-2xl font-light">
              {{ sec.titulo ?? tn("escaparate.resenas") }}
            </h2>
            <p class="mt-2 flex items-baseline gap-2">
              <span class="text-3xl font-light">{{
                tn("escaparate.promedio", {
                  promedio: escaparate.resenas?.promedio?.toFixed(1),
                })
              }}</span>
              <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
                tn("escaparate.totalResenas", escaparate.resenas?.total ?? 0)
              }}</span>
            </p>
            <CarruselSitio
              v-if="
                sec.presentacion === 'carrusel' &&
                (escaparate.resenas?.recientes ?? []).length > 0
              "
              class="mt-6"
              :items="escaparate.resenas?.recientes ?? []"
              :etiqueta="sec.titulo ?? tn('escaparate.resenas')"
            >
              <template #default="{ item: r }">
                <div
                  class="h-full rounded-lg border p-4"
                  :style="{ borderColor: 'var(--borde)' }"
                  data-prueba="resena"
                >
                  <p
                    class="text-sm tracking-widest"
                    :style="{ color: 'var(--aviso)' }"
                    :aria-label="
                      tn('escaparate.estrellas', { n: r.calificacion })
                    "
                    role="img"
                  >
                    {{ "★".repeat(r.calificacion)
                    }}<span :style="{ color: 'var(--borde)' }">{{
                      "★".repeat(5 - r.calificacion)
                    }}</span>
                  </p>
                  <p class="mt-2">{{ r.comentario }}</p>
                  <p
                    class="mt-2 text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ [r.nombre, r.actividad].filter(Boolean).join(" · ") }}
                  </p>
                </div>
              </template>
            </CarruselSitio>
            <ul
              v-else-if="(escaparate.resenas?.recientes ?? []).length > 0"
              class="mt-6 grid gap-4 sm:grid-cols-2"
            >
              <li
                v-for="(r, i) in escaparate.resenas?.recientes"
                :key="i"
                class="rounded-lg border p-4"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <p
                  class="text-sm tracking-widest"
                  :style="{ color: 'var(--aviso)' }"
                  :aria-label="
                    tn('escaparate.estrellas', { n: r.calificacion })
                  "
                  role="img"
                >
                  {{ "★".repeat(r.calificacion)
                  }}<span :style="{ color: 'var(--borde)' }">{{
                    "★".repeat(5 - r.calificacion)
                  }}</span>
                </p>
                <p class="mt-2">{{ r.comentario }}</p>
                <p
                  class="mt-2 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ [r.nombre, r.actividad].filter(Boolean).join(" · ") }}
                </p>
              </li>
            </ul>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'sucursales'">
          <!-- Ubicación -->
          <section
            v-if="escaparate.sucursales.length > 0 || ubicacion"
            id="sucursales"
            class="py-12"
            :style="{ background: 'var(--superficie)' }"
          >
            <div class="mx-auto max-w-5xl px-4">
              <h2 class="text-2xl font-light">
                {{ sec.titulo ?? tn("escaparate.ubicacion") }}
              </h2>
              <p
                v-if="ubicacion"
                class="mt-2"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ ubicacion }}
              </p>
              <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li
                  v-for="(su, i) in escaparate.sucursales"
                  :key="i"
                  class="tu-card p-4"
                  data-prueba="sede"
                >
                  <img
                    v-if="su.foto_url"
                    :src="su.foto_url"
                    alt=""
                    class="mb-3 h-32 w-full rounded-lg object-cover"
                    loading="lazy"
                  />
                  <p class="font-semibold">{{ su.nombre }}</p>
                  <p
                    v-if="su.direccion || su.region"
                    class="text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ [su.region, su.direccion].filter(Boolean).join(" · ") }}
                  </p>
                  <div
                    v-if="su.mapa_url || su.whatsapp_url || su.telefono"
                    class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm"
                  >
                    <a
                      v-if="su.mapa_url"
                      :href="su.mapa_url"
                      target="_blank"
                      rel="noopener"
                      class="tu-enlace inline-flex items-center gap-1.5"
                    >
                      <IconoRed red="mapa" :tamano="16" />
                      {{ $t("perfilPublico.publico.comoLlegar") }}
                    </a>
                    <a
                      v-if="su.whatsapp_url"
                      :href="su.whatsapp_url"
                      target="_blank"
                      rel="noopener"
                      class="tu-enlace inline-flex items-center gap-1.5"
                    >
                      <IconoRed red="whatsapp" :tamano="16" />
                      {{ $t("perfilPublico.publico.whatsapp") }}
                    </a>
                    <a
                      v-if="su.telefono"
                      :href="`tel:${su.telefono.replace(/[^0-9+]/g, '')}`"
                      class="tu-enlace inline-flex items-center gap-1.5"
                    >
                      <IconoRed red="telefono" :tamano="16" />
                      {{ su.telefono }}
                    </a>
                  </div>
                  <ul
                    v-if="(su.redes ?? []).length > 0"
                    class="mt-3 flex flex-wrap gap-2"
                  >
                    <li v-for="r in su.redes" :key="r.red">
                      <a
                        :href="r.url"
                        target="_blank"
                        rel="noopener"
                        class="ep-red ep-red-chica"
                        :aria-label="`${$t(`perfilPublico.redes.${r.red}`)} · ${su.nombre}`"
                      >
                        <IconoRed :red="r.red" :tamano="16" />
                      </a>
                    </li>
                  </ul>
                  <dl
                    v-if="su.horario"
                    class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm"
                  >
                    <template v-for="h in horarioDeSede(su)" :key="h.dia">
                      <dt :style="{ color: 'var(--texto-suave)' }">
                        {{ $t(`perfilPublico.dias.${h.dia}`) }}
                      </dt>
                      <dd class="tabular-nums">
                        {{ h.texto ?? $t("perfilPublico.publico.cerrado") }}
                      </dd>
                    </template>
                  </dl>
                </li>
              </ul>
            </div>
          </section>
        </template>

        <template v-else-if="sec.tipo === 'contacto'">
          <!-- CTA final -->
          <section
            id="contacto"
            class="mx-auto max-w-3xl px-4 py-14 text-center"
            data-prueba="contacto"
          >
            <h2 class="text-2xl font-light">
              {{ sec.titulo ?? escaparate.estudio.nombre }}
            </h2>
            <p
              v-if="sec.texto"
              class="mx-auto mt-3 max-w-xl whitespace-pre-line"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ sec.texto }}
            </p>
            <RouterLink
              v-if="usaCitas"
              :to="{ name: 'agendar-cita', params: { slug } }"
              class="tu-btn tu-btn-primario mt-5 px-8"
              @click="
                trackEvent('book_appointment_clicked', { source: 'final' })
              "
            >
              {{ tn("escaparate.agendarCita") }}
            </RouterLink>
            <button
              v-else
              type="button"
              class="tu-btn tu-btn-primario mt-5 px-8"
              @click="abrirAcceso('final')"
            >
              {{ tn("escaparate.reservar") }}
            </button>
            <p v-if="escaparate.estudio.aviso_privacidad" class="mt-8 text-sm">
              <RouterLink
                :to="{ name: 'aviso-negocio', params: { slug } }"
                class="tu-enlace"
                data-prueba="aviso-negocio"
                >{{ tn("escaparate.avisoPrivacidad") }}</RouterLink
              >
            </p>
          </section>
        </template>

        <!-- Bloques propios del negocio (ADR 0146) -->
        <BloqueSitio
          v-else-if="esBloque(sec.tipo)"
          :bloque="sec"
          :slug="slug"
          :captacion-activa="escaparate.captacion?.activa === true"
          :enlace="enlaceDelSitio"
        />
      </div>

      <!-- En el editor: qué sección se abre con un clic -->
      <p
        v-if="enEditor && resaltada"
        class="ep-editor-aviso"
        aria-hidden="true"
        data-prueba="aviso-editar"
      >
        {{
          $t("sitioWeb.editor.clicParaEditar", {
            seccion: nombreEnEditor(resaltada.tipo),
          })
        }}
      </p>

      <!-- Pie de página: su texto, sus páginas y su aviso de privacidad -->
      <footer class="ep-pie" data-prueba="pie-sitio">
        <div class="mx-auto max-w-5xl px-4 py-8 text-sm">
          <p class="font-medium">{{ escaparate.estudio.nombre }}</p>
          <p
            v-if="sitio.pie?.texto"
            class="mt-1 whitespace-pre-line ep-pie-suave"
          >
            {{ sitio.pie.texto }}
          </p>
          <nav
            v-if="paginasDelMenu.length > 0"
            class="mt-3 flex flex-wrap gap-x-4 gap-y-1"
            :aria-label="$t('sitioWeb.publico.paginas')"
          >
            <RouterLink :to="aInicio()" class="tu-enlace">{{
              $t("sitioWeb.publico.inicio")
            }}</RouterLink>
            <RouterLink
              v-for="p in paginasDelMenu"
              :key="p.slug"
              :to="aPagina(p.slug)"
              class="tu-enlace"
              >{{ p.titulo }}</RouterLink
            >
          </nav>
          <RouterLink
            :to="{ name: 'aviso-negocio', params: { slug } }"
            class="tu-enlace mt-3 inline-block"
            >{{ tn("escaparate.avisoPrivacidad") }}</RouterLink
          >
        </div>
      </footer>

      <!-- La ficha de alguien del equipo (ADR 0167) -->
      <FichaIntegrante
        :persona="fichaDe"
        :agendar="agendarCon"
        :clases="clasesDe"
        @cerrar="fichaDe = null"
      />

      <!-- Pedir acceso: el negocio da de alta a sus clientes (ADR 0093) -->
      <div
        v-if="pidiendoAcceso"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-prueba="pedir-acceso"
      >
        <div
          class="absolute inset-0 bg-black/50"
          @click="pidiendoAcceso = false"
        />
        <div
          class="relative w-full max-w-md tu-card p-6 max-h-[92vh] overflow-y-auto"
          :style="{ background: 'var(--superficie)' }"
          role="dialog"
          aria-modal="true"
          :aria-label="tn('escaparate.acceso.titulo')"
        >
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-xl font-medium">
              {{ tn("escaparate.acceso.titulo") }}
            </h3>
            <button
              type="button"
              class="tu-icono-btn shrink-0"
              :aria-label="$t('comun.cerrar')"
              @click="pidiendoAcceso = false"
            >
              <IconoNav nombre="cerrar" :tam="16" />
            </button>
          </div>
          <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{
              formularioAcceso
                ? $t("captacion.publico.accesoCerrado")
                : tn("escaparate.acceso.explicacion", {
                    estudio: escaparate.estudio.nombre,
                  })
            }}
          </p>
          <FormularioCaptacion
            v-if="formularioAcceso"
            class="mt-4"
            :slug="slug"
            :formulario="formularioAcceso"
            :interes="interesAcceso"
            compacto
            @enviado="
              trackEvent('student_access_requested', { channel: 'formulario' })
            "
          />
          <div class="mt-5 grid gap-2">
            <a
              v-if="canalAcceso?.whatsapp"
              :href="canalAcceso.whatsapp"
              target="_blank"
              rel="noopener"
              class="tu-btn w-full"
              :class="
                formularioAcceso ? 'tu-btn-secundario' : 'tu-btn-primario'
              "
              @click="
                trackEvent('student_access_requested', { channel: 'whatsapp' })
              "
            >
              {{ tn("escaparate.acceso.whatsapp") }}
            </a>
            <a
              v-else-if="canalAcceso?.telefono"
              :href="`tel:${canalAcceso.telefono.replace(/[^0-9+]/g, '')}`"
              class="tu-btn tu-btn-primario w-full"
              @click="
                trackEvent('student_access_requested', { channel: 'telefono' })
              "
            >
              {{
                tn("escaparate.acceso.llamar", {
                  telefono: canalAcceso.telefono,
                })
              }}
            </a>
            <!-- Con lo que eligió (un plan, una clase): su cuenta lo muestra al entrar. -->
            <RouterLink
              :to="{
                name: 'entrar',
                query: {
                  estudio: slug,
                  ...(volverTrasEntrar ? { volver: volverTrasEntrar } : {}),
                },
              }"
              class="tu-btn tu-btn-secundario w-full"
              data-prueba="ya-tengo-cuenta"
            >
              {{ tn("escaparate.acceso.yaTengo") }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* El equipo (ADR 0167): tarjetas, mosaico y carrusel; cada integrante abre su ficha. */
.ep-integrante {
  width: 100%;
  text-align: left;
  color: inherit;
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
}
div.ep-integrante {
  cursor: default;
}
.ep-integrante-tarjeta {
  display: block;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.ep-integrante:focus-visible,
.ep-mosaico-celda:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 2px;
}
.ep-integrante-foto {
  display: block;
  width: 100%;
  aspect-ratio: 1;
  border-radius: 0.5rem;
  object-fit: cover;
}
.ep-integrante-iniciales {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.75rem;
  font-weight: 700;
  background: var(--primario);
  color: var(--primario-contraste);
}
.ep-integrante-semblanza {
  color: var(--texto-suave);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.ep-mosaico {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 10rem), 1fr));
  gap: 0.5rem;
}
.ep-mosaico-celda {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 1;
  overflow: hidden;
  border: 0;
  border-radius: 0.5rem;
  padding: 0;
  cursor: pointer;
}
.ep-mosaico-celda img,
.ep-mosaico-celda .ep-integrante-iniciales {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.ep-mosaico-nombre {
  position: absolute;
  inset: auto 0 0 0;
  padding: 1.5rem 0.75rem 0.5rem;
  font-weight: 600;
  text-align: left;
  color: #fff;
  background: linear-gradient(transparent, rgb(0 0 0 / 0.65));
}
/* Claro u oscuro fijo (ADR 0146, fase 3): los colores de las páginas públicas (los de
   .tu-public-business en style.css), sin seguir el modo de quien visita. */
.ep-tema-claro {
  --fondo: #f6f8fc;
  --superficie: #fff;
  --superficie-2: #e9edf5;
  --texto: #182b39;
  --texto-suave: #59666b;
  --borde: #dce1de;
  --acento: #198754;
  --exito: #198754;
  --exito-texto: #146c43;
  --exito-suave: #d1e7dd;
  --error: #d70015;
  --error-suave: #fdeaea;
  --aviso: #b64400;
  --aviso-suave: #fbe9dd;
  --primario: #007e91;
  --primario-fuerte: #006574;
  --primario-contraste: #fff;
  --primario-suave: color-mix(in srgb, #007e91 10%, #fff);
  --enlace: #007487;
  --marketing-enfasis: #198754;
  background: var(--fondo);
  color: var(--texto);
  color-scheme: light;
}
.ep-tema-oscuro {
  --fondo: #020d24;
  --superficie: #071a3a;
  --superficie-2: #0b2854;
  --texto: #f3f4ef;
  --texto-suave: #b3bfbf;
  --borde: #34444c;
  --acento: #75b798;
  --exito: #198754;
  --exito-texto: #75b798;
  --exito-suave: #051b11;
  --error: #ff453a;
  --error-suave: #2a1414;
  --aviso: #ff9f0a;
  --aviso-suave: #2a1e0a;
  --primario: #74d4df;
  --primario-contraste: #182b39;
  --primario-fuerte: #a4e8ef;
  --primario-suave: color-mix(in srgb, #007e91 24%, #071a3a);
  --enlace: #74d4df;
  --marketing-enfasis: #75b798;
  background: var(--fondo);
  color: var(--texto);
  color-scheme: dark;
}
/* En el editor visual: la sección bajo el cursor y la que se está editando. */
.ep-en-editor > * {
  cursor: pointer;
}
.ep-resaltada > * {
  outline: 2px dashed var(--primario);
  outline-offset: -2px;
}
.ep-enfocada > * {
  outline: 2px solid var(--primario);
  outline-offset: -2px;
}
.ep-editor-aviso {
  position: fixed;
  bottom: 0.75rem;
  left: 50%;
  z-index: 30;
  transform: translateX(-50%);
  padding: 0.35rem 0.85rem;
  border-radius: 999px;
  background: var(--texto);
  color: var(--fondo);
  font-size: 0.8rem;
  white-space: nowrap;
  pointer-events: none;
}
.ep-menu-boton {
  min-height: 2.25rem;
  padding: 0.35rem 1rem;
  font-size: 0.9rem;
}
/* Su menú de páginas, su encabezado y el pie (ADR 0146). */
.ep-menu {
  position: sticky;
  top: 0;
  z-index: 15;
  border-bottom: 1px solid var(--borde);
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  backdrop-filter: blur(6px);
}
.ep-menu-enlace {
  flex-shrink: 0;
  padding: 0.85rem 0.75rem;
  border-bottom: 2px solid transparent;
  color: var(--texto-suave);
  font-size: 0.92rem;
  text-decoration: none;
  white-space: nowrap;
}
.ep-menu-enlace:hover {
  color: var(--texto);
}
.ep-menu-enlace[aria-current="page"] {
  border-bottom-color: var(--primario);
  color: var(--texto);
  font-weight: 500;
}
.ep-pagina-cabecera {
  background: color-mix(in srgb, var(--primario) 7%, var(--superficie));
}
.ep-pagina-negocio {
  color: var(--texto-suave);
  font-size: 0.9rem;
  text-decoration: none;
}
.ep-pie {
  margin-top: 2rem;
  border-top: 1px solid var(--borde);
}
.ep-pie-suave {
  color: var(--texto-suave);
}
.ep-aviso-previa {
  position: sticky;
  top: 0;
  z-index: 20;
  padding: 0.5rem 1rem;
  text-align: center;
  font-size: 0.875rem;
  background: var(--superficie-2);
  border-bottom: 1px solid var(--borde);
}
/* Plantilla «Portada»: la foto a todo lo ancho con el nombre encima. */
.ep-portada-foto {
  position: relative;
  background-size: cover;
  background-position: center;
  color: #fff;
  box-shadow: inset 0 0 0 100vmax rgb(0 0 0 / 0.5);
}
.ep-portada-foto .tu-btn-secundario {
  color: #fff;
  border-color: rgb(255 255 255 / 0.7);
}
.ep-portada-foto .ep-red {
  color: var(--texto);
}
/* Plantilla «Compacta»: logo y nombre en una línea, directo a reservar. */
.ep-compacta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1.25rem;
  text-align: left;
}
.ep-compacta-ancho {
  flex-basis: 100%;
  max-width: none;
  margin-top: 0;
  margin-left: 0;
  margin-right: 0;
}
.ep-compacta-ancho > p {
  max-width: 48rem;
}
.ep-banner {
  overflow: hidden;
  padding: 0;
}
.ep-banner-foto {
  width: 100%;
  aspect-ratio: 16 / 7;
  object-fit: cover;
}
.ep-nosotros-foto {
  width: 100%;
  max-height: 26rem;
  object-fit: cover;
  border-radius: 1rem;
}
.ep-portada {
  height: clamp(9rem, 28vw, 18rem);
  background-size: cover;
  background-position: center;
}
.ep-sobre-portada {
  margin-top: -2.5rem;
}
.ep-recortada {
  display: -webkit-box;
  -webkit-line-clamp: 4;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.ep-red {
  display: inline-flex;
  width: 2.5rem;
  height: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  border: 1px solid var(--borde);
  color: var(--texto);
  background: var(--superficie);
}
.ep-red:hover {
  border-color: var(--texto-suave);
}
.ep-red-chica {
  width: 2rem;
  height: 2rem;
}
.ep-semana {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
}
.ep-clase {
  padding: 0.6rem 0.75rem;
  border-radius: 0.75rem;
  border-left: 3px solid var(--primario);
  background: color-mix(in srgb, var(--primario) 7%, var(--superficie));
}
/* Qué saber y qué llevar: etiqueta y texto, uno debajo del otro. */
.ep-primera-vez {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.15rem 0.75rem;
}
.ep-primera-vez dt {
  color: var(--texto-suave);
}
@media (max-width: 30rem) {
  .ep-primera-vez {
    grid-template-columns: minmax(0, 1fr);
  }
  .ep-primera-vez dd {
    margin-bottom: 0.35rem;
  }
}
.tu-agenda-citas {
  background:
    radial-gradient(
      circle at 88% 12%,
      rgb(53 194 249 / 17%),
      transparent 22rem
    ),
    var(--fondo);
}
.tu-agenda-citas-cabecera {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 2rem;
}
.tu-agenda-citas-etiqueta {
  margin-bottom: 0.65rem;
  color: var(--primario);
  font-size: 0.72rem;
  font-weight: 750;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}
.tu-agenda-pasos {
  display: grid;
  gap: 0.85rem;
}
.tu-agenda-pasos li {
  position: relative;
  display: flex;
  min-height: 6.5rem;
  align-items: center;
  gap: 1rem;
  padding: 1.2rem;
  border: 1px solid var(--borde);
  border-radius: 1.2rem;
  background: color-mix(in srgb, var(--superficie) 90%, transparent);
}
.tu-agenda-paso-num {
  display: grid;
  width: 3rem;
  height: 3rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: color-mix(in srgb, var(--primario) 11%, var(--superficie));
  color: var(--primario);
  font-size: 0.76rem;
  font-weight: 800;
}
.tu-agenda-paso-linea {
  display: none;
}
@media (min-width: 720px) {
  .tu-agenda-pasos {
    grid-template-columns: repeat(3, 1fr);
  }
  .tu-agenda-pasos li {
    flex-direction: column;
    align-items: flex-start;
  }
  .tu-agenda-paso-linea {
    position: absolute;
    top: 2.7rem;
    left: calc(100% - 0.25rem);
    z-index: 2;
    display: block;
    width: 1.35rem;
    border-top: 1px dashed color-mix(in srgb, var(--primario) 45%, var(--borde));
  }
}
@media (max-width: 639px) {
  .tu-agenda-citas-cabecera {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
