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

import CargadorImagen from "@/components/CargadorImagen.vue";
import EditorBloqueSitio from "@/components/EditorBloqueSitio.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PerfilPublicoNegocio from "@/components/PerfilPublicoNegocio.vue";
import SeoPaginaSitio from "@/components/SeoPaginaSitio.vue";
import SitioCompartir from "@/components/SitioCompartir.vue";
import SitioDominio from "@/components/SitioDominio.vue";
import SitioReservasVentas from "@/components/SitioReservasVentas.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { claveSegunModalidad } from "@/lib/menu";
import { cargarFuente, pilaDeFuente } from "@/lib/fuentes";
import {
  avisarEditorSitio,
  claveSeccion,
  direccionDe,
  FAMILIAS_SITIO,
  duplicarBloque,
  esBloque,
  esFija,
  mensajeEditorSitio,
  moverSeccion,
  nuevaPagina,
  nuevoBloque,
  type BannerSitio,
  type EncabezadoSitio,
  type EstiloSitio,
  type PaginaSitio,
  type PlantillaSitio,
  type SeccionSitio,
  type SeoSitio,
  type TipoBloque,
  type TipografiaSitio,
  type TipoSeccion,
} from "@/lib/sitioWeb";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * El constructor del sitio del negocio (ADR 0114): elegir plantilla, ordenar, mostrar u
 * ocultar secciones, sus títulos y textos, la foto de «Nosotros», los banners, la vista
 * previa y publicar. Lo que se guarda es un borrador: el público ve lo publicado.
 *
 * Bloques propios (ADR 0146): «Agregar bloque» pone uno nuevo antes del contacto (texto
 * con imagen, galería, preguntas, testimonios, llamado, video, beneficios o formulario);
 * se duplican, se quitan, se mueven y se editan con su estilo.
 *
 * Varias páginas (ADR 0146, fase 2): «Páginas» lista Inicio y las propias (su título,
 * su dirección, si van en el menú y su SEO); «Secciones» edita la que se elige. En una
 * página propia caben las secciones del sistema (una vez) y bloques. Más el pie.
 *
 * Fase 3: «Estilo del sitio» (color, tipografía de una lista, esquinas y claro u
 * oscuro), el botón principal del encabezado y «Versiones publicadas» para volver a
 * una (queda como borrador hasta publicarla).
 *
 * En Captación, con pestañas (ADR 0167): Contenido (su página pública y el
 * constructor), Reservas y ventas (qué de su negocio se muestra), Compartir (enlace,
 * QR, enlaces y widgets) y Dominio y SEO. Arriba, siempre a la vista, si hay borrador
 * o está publicado, la vista previa, ver el sitio y publicar: guardar no publica. Lo
 * avanzado (estilo propio, más páginas y bloques) es «Plus»: sin él se conserva lo que
 * ya tenía, pero no se agrega ni se cambia (el servidor lo niega igual).
 */
type SeccionBorrador = SeccionSitio & { visible: boolean };
interface Borrador {
  plantilla: PlantillaSitio;
  secciones: SeccionBorrador[];
  banners: BannerSitio[];
  seo: SeoSitio;
  paginas: (PaginaSitio & { secciones: SeccionBorrador[] })[];
  pie: { texto: string | null };
  estilo: EstiloSitio;
  encabezado: EncabezadoSitio;
}
interface VersionSitio {
  id: string;
  publicada_en: string | null;
  publicada_por: string | null;
  actual: boolean;
}
interface DatosSitio {
  borrador: Borrador;
  cambios_sin_publicar: boolean;
  publicado_en: string | null;
  pagina_publica: boolean;
  tiene_portada: boolean;
  // Fase 3 (ADR 0146): las versiones publicadas y el color de su marca.
  versiones?: VersionSitio[];
  // Si está abierta, solo con el enlace o cerrada (ADR 0167).
  publicacion?: { publicado: boolean; privado: boolean };
  color_marca?: string | null;
  catalogo: {
    plantillas: { clave: PlantillaSitio; orden: TipoSeccion[] }[];
    con_texto: TipoSeccion[];
    con_foto: TipoSeccion[];
    max_titulo: number;
    max_texto: number;
    banners_maximos: number;
    // Bloques propios (ADR 0146).
    bloques: TipoBloque[];
    bloques_maximos: number;
    fondos: string[];
    alineaciones: string[];
    max_elementos: number;
    max_preguntas: number;
    formularios: { id: string; nombre: string }[];
    // Páginas propias (ADR 0146, fase 2).
    paginas_maximas: number;
    movibles: TipoSeccion[];
    max_direccion: number;
    max_titulo_pagina: number;
    max_seo_titulo: number;
    max_seo_descripcion: number;
    max_pie: number;
    tipografias?: TipografiaSitio[];
    esquinas?: EstiloSitio["esquinas"][];
    temas?: EstiloSitio["tema"][];
  };
}

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/sitio`);

// Las partes del sitio, en pestañas (ADR 0167). Quien solo atiende prospectos entra
// a «Compartir»; editar y publicar es de quien configura el negocio.
const route = useRoute();
const router = useRouter();
const PESTANAS = ["contenido", "reservas", "compartir", "dominio"] as const;
type Pestana = (typeof PESTANAS)[number];
const puedeEditar = computed(() => sesion.puede("estudio.gestionar"));
const pestanas = computed<Pestana[]>(() =>
  puedeEditar.value ? [...PESTANAS] : ["compartir"],
);
const pestana = computed<Pestana>(() => {
  const pedida = String(route.query.pestana ?? "") as Pestana;
  return pestanas.value.includes(pedida) ? pedida : pestanas.value[0]!;
});
function elegirPestana(p: Pestana): void {
  void router.replace({ query: { ...route.query, pestana: p } });
}
// Lo avanzado es «Plus» (ADR 0167): estilo propio, más páginas y bloques propios.
const conPlus = computed(
  () => !(sesion.estudio?.plan?.sin ?? []).includes("sitio_avanzado"),
);
// «Ver sitio»: la página publicada (si está abierta).
const urlSitio = computed(
  () => `https://${urlPublicaEstudio(sesion.slug ?? "")}`,
);

const datos = ref<DatosSitio | null>(null);
const borrador = ref<Borrador | null>(null);
const guardadoComo = ref("");
const cargando = ref(true);
const ocupado = ref(false);
const error = ref<string | null>(null);
const errores = ref<Record<string, string[]>>({});
// La que se edita: su tipo o, si es un bloque propio, su id.
const abierta = ref<string | null>(null);

// Vista previa: en un marco con el ancho de un teléfono o de una computadora.
const anchoPrevia = ref<"telefono" | "computadora">("telefono");
const vuelta = ref(0);
const urlPrevia = computed(
  () =>
    `/estudio/${sesion.slug}/vista-previa` +
    (paginaEditada.value
      ? `?pagina=${encodeURIComponent(paginaEditada.value.slug)}`
      : ""),
);

const sinGuardar = computed(
  () =>
    borrador.value !== null &&
    JSON.stringify(borrador.value) !== guardadoComo.value,
);
const hayCambios = computed(
  () => sinGuardar.value || (datos.value?.cambios_sin_publicar ?? false),
);
// Con cambios sin guardar, salir de la pantalla (o cerrar la pestaña) pregunta antes.
useCambiosPendientes(() => sinGuardar.value);
// Qué pasa con el sitio: sin guardar, sin publicar, publicado o la página de siempre.
const etiquetaEstado = computed(() =>
  sinGuardar.value
    ? t("sitioWeb.estado.sinGuardar")
    : hayCambios.value
      ? t("sitioWeb.estado.cambios")
      : datos.value?.publicado_en
        ? t("sitioWeb.estado.alDia")
        : t("sitioWeb.estado.deSiempre"),
);
const portadaSubida = computed(() => datos.value?.tiene_portada ?? false);

function tm(clave: string): string {
  return t(claveSegunModalidad(clave, sesion.esCitas, te));
}
function nombreSeccion(tipo: TipoSeccion | TipoBloque): string {
  return esBloque(tipo)
    ? t(`sitioWeb.bloques.nombres.${tipo}`)
    : tm(`sitioWeb.secciones.nombres.${tipo}`);
}
// Debajo del nombre: qué muestra (una sección del sistema) o su título (un bloque).
function detalleSeccion(s: SeccionSitio): string {
  if (esBloque(s.tipo)) {
    return s.titulo ?? t("sitioWeb.bloques.propio");
  }
  return tm(`sitioWeb.secciones.contenido.${s.tipo}`);
}
// En la zona horaria del negocio (ADR 0099), aunque quien mira esté en otra.
function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}

function tomar(d: DatosSitio): void {
  // Un API anterior a las páginas (ADR 0146) no las manda: se completan vacías.
  d.borrador.seo ??= { titulo: null, descripcion: null, imagen_url: null };
  d.borrador.paginas ??= [];
  d.borrador.pie ??= { texto: null };
  d.borrador.estilo ??= {
    color: null,
    tipografia: "predeterminada",
    esquinas: "suaves",
    tema: "automatico",
  };
  d.borrador.encabezado ??= { boton_texto: null, boton_enlace: null };
  datos.value = d;
  // Una copia para editar: lo guardado queda como referencia de «sin guardar».
  guardadoComo.value = JSON.stringify(d.borrador);
  borrador.value = JSON.parse(guardadoComo.value) as Borrador;
  errores.value = {};
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: DatosSitio }>(base.value);
    tomar(data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

/** Guarda el borrador; `true` si quedó guardado. */
async function guardar(avisar = true): Promise<boolean> {
  if (!borrador.value || ocupado.value) {
    return false;
  }
  ocupado.value = true;
  errores.value = {};
  try {
    const { data } = await api.put<{ data: DatosSitio }>(
      base.value,
      borrador.value,
    );
    tomar(data.data);
    vuelta.value++;
    if (avisar) {
      toast.exito(t("sitioWeb.avisos.guardado"));
    }
    return true;
  } catch (e) {
    errores.value = erroresDe(e);
    toast.error(mensajeDeError(e));
    return false;
  } finally {
    ocupado.value = false;
  }
}

async function publicar(): Promise<void> {
  if (
    !(await confirmar(t("sitioWeb.confirmar.publicar"), {
      aceptar: t("sitioWeb.acciones.publicar"),
    }))
  ) {
    return;
  }
  // Lo que se ve en pantalla es lo que se publica.
  if (sinGuardar.value && !(await guardar(false))) {
    return;
  }
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: DatosSitio }>(
      `${base.value}/publicar`,
    );
    tomar(data.data);
    vuelta.value++;
    toast.exito(t("sitioWeb.avisos.publicado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function descartar(): Promise<void> {
  if (
    !(await confirmar(t("sitioWeb.confirmar.descartar"), {
      aceptar: t("sitioWeb.acciones.descartar"),
      peligro: true,
    }))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: DatosSitio }>(
      `${base.value}/descartar`,
    );
    tomar(data.data);
    vuelta.value++;
    toast.exito(t("sitioWeb.avisos.descartado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function usarPlantilla(clave: PlantillaSitio): Promise<void> {
  const actual = borrador.value;
  const plantilla = datos.value?.catalogo.plantillas.find(
    (p) => p.clave === clave,
  );
  if (!actual || !plantilla || actual.plantilla === clave) {
    return;
  }
  if (
    !(await confirmar(
      t("sitioWeb.confirmar.plantilla", {
        plantilla: t(`sitioWeb.plantillas.${clave}`),
      }),
      { aceptar: t("sitioWeb.confirmar.usar") },
    ))
  ) {
    return;
  }
  // Su orden; cada sección conserva su título, texto, foto y visibilidad. Los bloques
  // propios se quedan, en su orden, antes del contacto.
  const porTipo = new Map(
    actual.secciones.filter((s) => !esBloque(s.tipo)).map((s) => [s.tipo, s]),
  );
  const bloques = actual.secciones.filter((s) => esBloque(s.tipo));
  actual.plantilla = clave;
  actual.secciones = [
    ...(["inicio", ...plantilla.orden] as TipoSeccion[])
      .map((tipo) => porTipo.get(tipo))
      .filter((s): s is Borrador["secciones"][number] => s !== undefined),
    ...bloques,
    ...[porTipo.get("contacto")].filter(
      (s): s is Borrador["secciones"][number] => s !== undefined,
    ),
  ];
}

// ---- Estilo del sitio (ADR 0146, fase 3) ----
// El color que se ve: el elegido o el de su marca (o el de siempre).
const colorDelSitio = computed(
  () => borrador.value?.estilo.color ?? datos.value?.color_marca ?? "#007e91",
);
function elegirColor(e: Event): void {
  if (borrador.value) {
    borrador.value.estilo.color = (e.target as HTMLInputElement).value;
  }
}
// Cada tipografía se muestra con su letra.
watch(
  () => datos.value?.catalogo.tipografias,
  (lista) => {
    (lista ?? []).forEach((t) => {
      const familia = FAMILIAS_SITIO[t];
      if (familia) {
        cargarFuente(familia);
      }
    });
  },
);
function letraDe(tipografia: TipografiaSitio): string | undefined {
  const familia = FAMILIAS_SITIO[tipografia];
  return familia ? pilaDeFuente(familia) : undefined;
}

// ---- Versiones publicadas: volver a una (queda como borrador) ----
async function restaurarVersion(v: VersionSitio): Promise<void> {
  if (
    !(await confirmar(
      t("sitioWeb.versiones.confirmar", {
        fecha: v.publicada_en ? fecha(v.publicada_en) : "",
      }),
      { aceptar: t("sitioWeb.versiones.volver") },
    ))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: DatosSitio }>(
      `${base.value}/versiones/${v.id}/restaurar`,
    );
    tomar(data.data);
    vuelta.value++;
    elegirPagina(null);
    toast.exito(t("sitioWeb.versiones.restaurada"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

// ---- Páginas (ADR 0146, fase 2) ----
// La que se edita en «Secciones»: Inicio (null) o una propia (su id).
const paginaSel = ref<string | null>(null);
const indicePagina = computed(() =>
  paginaSel.value === null
    ? -1
    : (borrador.value?.paginas ?? []).findIndex(
        (p) => p.id === paginaSel.value,
      ),
);
const paginaEditada = computed(() =>
  indicePagina.value < 0
    ? null
    : (borrador.value?.paginas[indicePagina.value] ?? null),
);
// Las secciones de la que se edita y dónde van sus errores.
const seccionesActuales = computed<SeccionBorrador[]>(
  () => paginaEditada.value?.secciones ?? borrador.value?.secciones ?? [],
);
const prefijo = computed(() =>
  paginaEditada.value ? `paginas.${indicePagina.value}.secciones` : "secciones",
);
function fijarSecciones(lista: SeccionBorrador[]): void {
  if (paginaEditada.value) {
    paginaEditada.value.secciones = lista;
  } else if (borrador.value) {
    borrador.value.secciones = lista;
  }
}
function elegirPagina(id: string | null): void {
  paginaSel.value = id;
  abierta.value = null;
}
const cabenPaginas = computed(
  () =>
    (borrador.value?.paginas.length ?? 0) <
    (datos.value?.catalogo.paginas_maximas ?? 0),
);
// La de configuración abierta: «inicio» o el id de una página.
const configAbierta = ref<string | null>(null);
const agregandoPagina = ref(false);
const tituloNueva = ref("");
function crearPagina(): void {
  const titulo = tituloNueva.value.trim();
  if (!borrador.value || titulo === "" || !cabenPaginas.value) {
    return;
  }
  const pagina = nuevaPagina(
    titulo,
    borrador.value.paginas.map((p) => p.slug),
  ) as Borrador["paginas"][number];
  borrador.value.paginas.push(pagina);
  tituloNueva.value = "";
  agregandoPagina.value = false;
  elegirPagina(pagina.id ?? null);
  configAbierta.value = pagina.id ?? null;
}
function moverPagina(indice: number, paso: -1 | 1): void {
  const lista = borrador.value?.paginas;
  const destino = indice + paso;
  if (!lista || destino < 0 || destino >= lista.length) {
    return;
  }
  [lista[indice], lista[destino]] = [lista[destino]!, lista[indice]!];
}
async function quitarPagina(indice: number): Promise<void> {
  const pagina = borrador.value?.paginas[indice];
  if (!pagina) {
    return;
  }
  if (
    !(await confirmar(
      t("sitioWeb.paginas.confirmarQuitar", { pagina: pagina.titulo }),
      { aceptar: t("sitioWeb.paginas.quitar"), peligro: true },
    ))
  ) {
    return;
  }
  borrador.value?.paginas.splice(indice, 1);
  if (paginaSel.value === pagina.id) {
    elegirPagina(null);
  }
}
// La dirección se escribe como queda en el enlace: minúsculas, números y guiones.
function cambiarDireccion(pagina: PaginaSitio, valor: string): void {
  pagina.slug = direccionDe(valor, datos.value?.catalogo.max_direccion ?? 40);
}
// En una página propia: las secciones del sistema que aún no tiene.
const seccionesDisponibles = computed<TipoSeccion[]>(() => {
  if (!paginaEditada.value) {
    return [];
  }
  const usadas = new Set(paginaEditada.value.secciones.map((s) => s.tipo));
  return (datos.value?.catalogo.movibles ?? []).filter((t) => !usadas.has(t));
});
function agregarSeccion(tipo: TipoSeccion): void {
  paginaEditada.value?.secciones.push({
    tipo,
    visible: true,
    titulo: null,
    texto: null,
    foto_url: null,
  });
  abierta.value = tipo;
  eligiendoBloque.value = false;
}
function quitarSeccion(indice: number): void {
  paginaEditada.value?.secciones.splice(indice, 1);
}

// ---- Bloques propios (ADR 0146) ----
const eligiendoBloque = ref(false);
const cuantosBloques = computed(
  () => seccionesActuales.value.filter((s) => esBloque(s.tipo)).length,
);
const caben = computed(
  () => cuantosBloques.value < (datos.value?.catalogo.bloques_maximos ?? 0),
);
// Antes del contacto, ya abierto para editarlo.
function agregarBloque(tipo: TipoBloque): void {
  const lista = borrador.value ? seccionesActuales.value : null;
  if (!lista || !caben.value) {
    return;
  }
  const bloque = nuevoBloque(tipo);
  const contacto = lista.findIndex((s) => s.tipo === "contacto");
  lista.splice(contacto < 0 ? lista.length : contacto, 0, bloque);
  abierta.value = bloque.id;
  eligiendoBloque.value = false;
}
// La copia queda justo después del original.
function duplicar(indice: number): void {
  const lista = seccionesActuales.value;
  const original = lista?.[indice];
  if (!lista || !original || !caben.value) {
    return;
  }
  const copia = duplicarBloque(original);
  lista.splice(indice + 1, 0, copia);
  abierta.value = copia.id ?? null;
}
async function quitarBloque(indice: number): Promise<void> {
  const lista = seccionesActuales.value;
  const bloque = lista[indice];
  if (!bloque) {
    return;
  }
  if (
    !(await confirmar(
      t("sitioWeb.bloques.confirmarQuitar", {
        bloque: bloque.titulo ?? nombreSeccion(bloque.tipo),
      }),
      { aceptar: t("sitioWeb.bloques.quitar"), peligro: true },
    ))
  ) {
    return;
  }
  lista.splice(indice, 1);
}

function mover(indice: number, paso: -1 | 1): void {
  fijarSecciones(moverSeccion(seccionesActuales.value, indice, paso));
}
function puedeMover(indice: number, paso: -1 | 1): boolean {
  const lista = seccionesActuales.value;
  const destino = lista[indice + paso];
  return (
    destino !== undefined &&
    !esFija(lista[indice].tipo) &&
    !esFija(destino.tipo)
  );
}

function agregarBanner(): void {
  borrador.value?.banners.push({
    titulo: t("sitioWeb.banners.nuevo", {
      n: (borrador.value?.banners.length ?? 0) + 1,
    }),
    texto: null,
    enlace_texto: null,
    enlace_url: null,
    desde: null,
    hasta: null,
    foto_url: null,
  });
}
function quitarBanner(indice: number): void {
  borrador.value?.banners.splice(indice, 1);
}

// Un campo de texto vacío se guarda como null (el de siempre).
function texto(valor: string): string | null {
  return valor.trim() === "" ? null : valor;
}

/** Los errores de validación por campo (`meta.errors` del API). */
function erroresDe(e: unknown): Record<string, string[]> {
  const respuesta = (
    e as { response?: { data?: { meta?: { errors?: unknown } } } }
  ).response;
  const lista = respuesta?.data?.meta?.errors;
  return lista && typeof lista === "object"
    ? (lista as Record<string, string[]>)
    : {};
}
function errorDe(clave: string): string | null {
  return errores.value[clave]?.[0] ?? null;
}

// ---- Editor visual (ADR 0146, fase 3) ----
// La vista previa muestra en vivo el borrador sin guardar (lo revisa y lo arma el API,
// sin guardarlo) y un clic en una de sus secciones la abre aquí para editarla.
const marcoPrevia = ref<HTMLIFrameElement | null>(null);
const previaLista = ref(false);
const previaValida = ref(true);
// La que se abrió desde la vista previa ya está a la vista: no hay que llevarla.
let abiertaDesdePrevia = false;
let esperaBorrador: ReturnType<typeof setTimeout> | undefined;

function mandarBorrador(): void {
  if (!previaLista.value || !borrador.value) {
    return;
  }
  avisarEditorSitio(marcoPrevia.value?.contentWindow, {
    tipo: "borrador",
    borrador: JSON.parse(JSON.stringify(borrador.value)) as unknown,
  });
}
// Otra página u otra carga del marco: espera a que avise que está lista.
watch([urlPrevia, vuelta], () => {
  previaLista.value = false;
  previaValida.value = true;
});
watch(
  borrador,
  () => {
    clearTimeout(esperaBorrador);
    esperaBorrador = setTimeout(mandarBorrador, 350);
  },
  { deep: true },
);
watch(abierta, (clave) => {
  if (clave && !abiertaDesdePrevia) {
    avisarEditorSitio(marcoPrevia.value?.contentWindow, {
      tipo: "enfocar",
      seccion: clave,
    });
  }
  abiertaDesdePrevia = false;
});
async function abrirDesdePrevia(
  pagina: string | null,
  seccion: string,
): Promise<void> {
  const destino =
    pagina === null
      ? null
      : borrador.value?.paginas.find((p) => p.slug === pagina)?.id;
  if (destino === undefined || !/^[A-Za-z0-9_-]+$/.test(seccion)) {
    return;
  }
  if (destino !== paginaSel.value) {
    elegirPagina(destino);
  }
  abiertaDesdePrevia = true;
  abierta.value = seccion;
  await nextTick();
  document
    .querySelector(`[data-prueba="seccion-${seccion}"]`)
    ?.scrollIntoView?.({ behavior: "smooth", block: "center" });
}
function alMensajeDePrevia(evento: MessageEvent): void {
  const mensaje = mensajeEditorSitio(evento, marcoPrevia.value?.contentWindow);
  if (mensaje?.tipo === "lista") {
    previaLista.value = true;
    previaValida.value = true;
    if (sinGuardar.value) {
      mandarBorrador();
    }
  } else if (mensaje?.tipo === "estado") {
    previaValida.value = mensaje.valido;
  } else if (mensaje?.tipo === "editar") {
    void abrirDesdePrevia(mensaje.pagina, mensaje.seccion);
  }
}

// Desde «Reservas y ventas»: mostrar u ocultar la sección de algo del negocio (es
// parte del borrador: se publica con lo demás).
function mostrarSeccion(tipo: TipoSeccion, visible: boolean): void {
  const seccion = borrador.value?.secciones.find((s) => s.tipo === tipo);
  if (seccion) {
    seccion.visible = visible;
  }
}
// Abrir o cerrar la página se aplica al momento (no es del borrador).
function publicacionActualizada(p: {
  publicado: boolean;
  privado: boolean;
}): void {
  if (datos.value) {
    datos.value.publicacion = p;
    datos.value.pagina_publica = p.publicado;
  }
}

onMounted(() => {
  window.addEventListener("message", alMensajeDePrevia);
  if (puedeEditar.value) {
    void cargar();
  } else {
    cargando.value = false;
  }
});
onBeforeUnmount(() => {
  window.removeEventListener("message", alMensajeDePrevia);
  clearTimeout(esperaBorrador);
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('sitioWeb.titulo')"
      :descripcion="$t('sitioWeb.descripcion')"
    />

    <SitioCompartir v-if="!puedeEditar" class="mt-6" />
    <p
      v-else-if="cargando"
      class="mt-8"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="error" class="mt-6 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-else-if="borrador && datos">
      <!-- Siempre a la vista: si hay borrador o está publicado, la vista previa, ver el
           sitio y publicar. Guardar el borrador no lo publica. -->
      <div class="sw-barra tu-card mt-6 p-4" data-prueba="estado-sitio">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="flex items-center gap-2 font-medium">
              <span
                class="sw-punto"
                :class="hayCambios ? 'sw-punto-aviso' : 'sw-punto-exito'"
                aria-hidden="true"
              ></span>
              {{ etiquetaEstado }}
            </p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{
                datos.publicado_en
                  ? $t("sitioWeb.estado.publicadoEl", {
                      fecha: fecha(datos.publicado_en),
                    })
                  : $t("sitioWeb.estado.nuncaPublicado")
              }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <a
              :href="urlPrevia"
              target="_blank"
              rel="noopener"
              class="tu-btn tu-btn-fantasma"
              data-prueba="abrir-vista-previa"
              >{{ $t("sitioWeb.acciones.vistaPrevia") }}</a
            >
            <a
              v-if="datos.pagina_publica"
              :href="urlSitio"
              target="_blank"
              rel="noopener"
              class="tu-btn tu-btn-fantasma"
              data-prueba="ver-sitio"
              >{{ $t("sitioWeb.barra.verSitio") }}</a
            >
            <button
              v-if="datos.cambios_sin_publicar && !sinGuardar"
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="ocupado"
              @click="descartar"
            >
              {{ $t("sitioWeb.acciones.descartar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="ocupado || !sinGuardar"
              data-prueba="guardar-sitio"
              @click="guardar()"
            >
              {{ $t("sitioWeb.acciones.guardar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="ocupado || !hayCambios"
              data-prueba="publicar-sitio"
              @click="publicar"
            >
              {{ $t("sitioWeb.acciones.publicar") }}
            </button>
          </div>
        </div>
        <p
          v-if="!datos.pagina_publica"
          class="mt-3 text-sm"
          data-prueba="pagina-oculta"
        >
          {{ $t("sitioWeb.estado.paginaOculta") }}
          <button
            type="button"
            class="tu-enlace"
            @click="elegirPestana('dominio')"
          >
            {{ $t("sitioWeb.estado.abrirPagina") }}
          </button>
        </p>
      </div>

      <div
        class="tu-pestanas mt-6"
        role="group"
        :aria-label="$t('sitioWeb.pestanas.etiqueta')"
      >
        <button
          v-for="p in pestanas"
          :key="p"
          type="button"
          :aria-pressed="pestana === p"
          :data-prueba="`pestana-${p}`"
          @click="elegirPestana(p)"
        >
          {{ $t(`sitioWeb.pestanas.${p}`) }}
        </button>
      </div>

      <!-- Contenido: su página pública y el constructor (se conserva al cambiar de
           pestaña: la vista previa y lo que se edita siguen ahí). -->
      <div v-show="pestana === 'contenido'" data-prueba="sitio-contenido">
        <div class="mt-6">
          <PerfilPublicoNegocio />
        </div>
        <div class="sw-columnas mt-6">
          <div class="min-w-0 space-y-6">
            <!-- Plantilla -->
            <div class="tu-card p-5">
              <h2 class="text-lg font-medium">
                {{ $t("sitioWeb.plantillas.titulo") }}
              </h2>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("sitioWeb.plantillas.ayuda") }}
              </p>
              <div class="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup">
                <button
                  v-for="p in datos.catalogo.plantillas"
                  :key="p.clave"
                  type="button"
                  role="radio"
                  :aria-checked="borrador.plantilla === p.clave"
                  class="sw-plantilla"
                  :class="{
                    'sw-plantilla-activa': borrador.plantilla === p.clave,
                  }"
                  :data-prueba="`plantilla-${p.clave}`"
                  @click="usarPlantilla(p.clave)"
                >
                  <span class="font-medium">{{
                    $t(`sitioWeb.plantillas.${p.clave}`)
                  }}</span>
                  <span
                    class="mt-1 block text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t(`sitioWeb.plantillas.${p.clave}Desc`) }}</span
                  >
                  <span
                    v-if="borrador.plantilla === p.clave"
                    class="mt-2 flex items-center gap-1.5 text-xs"
                  >
                    <span
                      class="sw-punto sw-punto-exito"
                      aria-hidden="true"
                    ></span>
                    {{ $t("sitioWeb.plantillas.elegida") }}
                  </span>
                </button>
              </div>
              <p
                v-if="borrador.plantilla === 'portada' && !portadaSubida"
                class="mt-3 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("sitioWeb.plantillas.portadaSinFoto") }}
              </p>
            </div>

            <!-- Estilo del sitio (ADR 0146, fase 3) -->
            <div class="tu-card p-5" data-prueba="estilo-sitio">
              <h2 class="flex items-center gap-2 text-lg font-medium">
                {{ $t("sitioWeb.estilo.titulo") }}
                <span class="tu-badge">{{ $t("sitioWeb.plus.etiqueta") }}</span>
              </h2>
              <p class="mt-1 text-sm sw-suave">
                {{ $t("sitioWeb.estilo.ayuda") }}
              </p>
              <p v-if="!conPlus" class="mt-3 text-sm" data-prueba="plus-estilo">
                {{ $t("sitioWeb.plus.ayuda") }}
                <RouterLink :to="{ name: 'renta' }" class="tu-enlace">{{
                  $t("sitioWeb.plus.cambiarPlan")
                }}</RouterLink>
              </p>
              <div
                class="mt-4 space-y-5"
                :class="{ 'sw-bloqueado': !conPlus }"
                :inert="!conPlus || undefined"
              >
                <!-- El color de las páginas públicas, aparte del de la app del negocio
                   (color_marca, en Configuración › Perfil público). -->
                <div data-prueba="color-sitio">
                  <span class="tu-label">{{
                    $t("sitioWeb.estilo.color")
                  }}</span>
                  <div class="flex flex-wrap items-center gap-3">
                    <input
                      type="color"
                      class="sw-color"
                      :value="colorDelSitio"
                      :aria-label="$t('sitioWeb.estilo.color')"
                      aria-describedby="sw-color-ayuda"
                      data-prueba="estilo-color"
                      @change="elegirColor"
                    />
                    <span class="text-sm sw-suave">{{
                      borrador.estilo.color
                        ? borrador.estilo.color
                        : $t("sitioWeb.estilo.deTuMarca")
                    }}</span>
                    <button
                      v-if="borrador.estilo.color"
                      type="button"
                      class="tu-enlace text-sm"
                      data-prueba="estilo-color-marca"
                      @click="borrador.estilo.color = null"
                    >
                      {{ $t("sitioWeb.estilo.usarMarca") }}
                    </button>
                  </div>
                  <p id="sw-color-ayuda" class="sw-ayuda">
                    {{ $t("sitioWeb.estilo.colorAyuda") }}
                  </p>
                </div>
                <div class="sw-color-app" data-prueba="color-app">
                  <span class="tu-label">{{
                    $t("sitioWeb.estilo.colorApp")
                  }}</span>
                  <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span
                      v-if="datos.color_marca"
                      class="sw-muestra"
                      :style="{ background: datos.color_marca }"
                      aria-hidden="true"
                    />
                    <span>{{
                      datos.color_marca ?? $t("sitioWeb.estilo.colorAppSin")
                    }}</span>
                  </div>
                  <p class="sw-ayuda">
                    {{ $t("sitioWeb.estilo.colorAppAyuda") }}
                    <RouterLink
                      :to="{
                        name: 'sitio-web',
                        query: { pestana: 'contenido' },
                      }"
                      class="tu-enlace"
                      data-prueba="ir-color-app"
                      >{{ $t("sitioWeb.estilo.colorAppDonde") }}</RouterLink
                    >.
                  </p>
                </div>
                <div>
                  <span class="tu-label">{{
                    $t("sitioWeb.estilo.tipografia")
                  }}</span>
                  <div
                    class="sw-tipografias"
                    role="radiogroup"
                    :aria-label="$t('sitioWeb.estilo.tipografia')"
                  >
                    <button
                      v-for="tipo in datos.catalogo.tipografias ?? []"
                      :key="tipo"
                      type="button"
                      role="radio"
                      class="sw-tipografia"
                      :class="{
                        'sw-tipografia-activa':
                          borrador.estilo.tipografia === tipo,
                      }"
                      :aria-checked="borrador.estilo.tipografia === tipo"
                      :style="{ fontFamily: letraDe(tipo) }"
                      :data-prueba="`tipografia-${tipo}`"
                      @click="borrador.estilo.tipografia = tipo"
                    >
                      <span class="block text-base">Aa</span>
                      <span class="block text-xs">{{
                        $t(`sitioWeb.estilo.tipografias.${tipo}`)
                      }}</span>
                    </button>
                  </div>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-4">
                  <div>
                    <span class="tu-label">{{
                      $t("sitioWeb.estilo.esquinas")
                    }}</span>
                    <div class="tu-segmentado" role="group">
                      <button
                        v-for="e in datos.catalogo.esquinas ?? []"
                        :key="e"
                        type="button"
                        :aria-pressed="borrador.estilo.esquinas === e"
                        :data-prueba="`esquinas-${e}`"
                        @click="borrador.estilo.esquinas = e"
                      >
                        {{ $t(`sitioWeb.estilo.esquinasOpciones.${e}`) }}
                      </button>
                    </div>
                  </div>
                  <div>
                    <span class="tu-label">{{
                      $t("sitioWeb.estilo.tema")
                    }}</span>
                    <div class="tu-segmentado" role="group">
                      <button
                        v-for="tm2 in datos.catalogo.temas ?? []"
                        :key="tm2"
                        type="button"
                        :aria-pressed="borrador.estilo.tema === tm2"
                        :data-prueba="`tema-${tm2}`"
                        @click="borrador.estilo.tema = tm2"
                      >
                        {{ $t(`sitioWeb.estilo.temas.${tm2}`) }}
                      </button>
                    </div>
                  </div>
                </div>
                <!-- El botón principal del encabezado -->
                <div class="grid gap-3 sm:grid-cols-2">
                  <div>
                    <label class="tu-label" for="sw-boton-principal">{{
                      $t("sitioWeb.estilo.botonTexto")
                    }}</label>
                    <input
                      id="sw-boton-principal"
                      class="tu-input"
                      maxlength="40"
                      :placeholder="$t('sitioWeb.estilo.botonEjemplo')"
                      :value="borrador.encabezado.boton_texto ?? ''"
                      data-prueba="boton-principal-texto"
                      @input="
                        borrador.encabezado.boton_texto = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                  <div>
                    <label class="tu-label" for="sw-boton-enlace">{{
                      $t("sitioWeb.estilo.botonEnlace")
                    }}</label>
                    <input
                      id="sw-boton-enlace"
                      class="tu-input"
                      maxlength="300"
                      placeholder="#agenda"
                      :value="borrador.encabezado.boton_enlace ?? ''"
                      data-prueba="boton-principal-enlace"
                      @input="
                        borrador.encabezado.boton_enlace = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                  <p class="sw-ayuda sm:col-span-2">
                    {{ $t("sitioWeb.estilo.botonAyuda") }}
                  </p>
                  <p
                    v-if="
                      errorDe('encabezado.boton_texto') ||
                      errorDe('encabezado.boton_enlace') ||
                      errorDe('estilo.color')
                    "
                    class="text-sm sm:col-span-2"
                    style="color: var(--error)"
                  >
                    {{
                      errorDe("encabezado.boton_texto") ??
                      errorDe("encabezado.boton_enlace") ??
                      errorDe("estilo.color")
                    }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Páginas (ADR 0146): Inicio y las propias -->
            <div class="tu-card p-5" data-prueba="paginas-sitio">
              <h2 class="text-lg font-medium">
                {{ $t("sitioWeb.paginas.titulo") }}
              </h2>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("sitioWeb.paginas.ayuda") }}
              </p>
              <ol class="mt-4 space-y-2">
                <li
                  class="sw-seccion"
                  :class="{ 'sw-pagina-activa': paginaSel === null }"
                  data-prueba="pagina-inicio"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      class="min-w-0 flex-1 basis-40 text-left"
                      :aria-pressed="paginaSel === null"
                      @click="elegirPagina(null)"
                    >
                      <span class="block font-medium">{{
                        $t("sitioWeb.paginas.inicio")
                      }}</span>
                      <span class="block text-xs sw-suave">/</span>
                    </button>
                    <button
                      type="button"
                      class="tu-btn tu-btn-fantasma shrink-0 text-sm"
                      :aria-expanded="configAbierta === 'inicio'"
                      data-prueba="config-inicio"
                      @click="
                        configAbierta =
                          configAbierta === 'inicio' ? null : 'inicio'
                      "
                    >
                      {{ $t("sitioWeb.paginas.seo") }}
                    </button>
                  </div>
                  <div v-if="configAbierta === 'inicio'" class="mt-4">
                    <SeoPaginaSitio
                      v-model="borrador.seo"
                      :catalogo="datos.catalogo"
                      prefijo="seo"
                      :errores="errores"
                      :placeholder-titulo="sesion.estudio?.nombre ?? ''"
                    />
                  </div>
                </li>
                <li
                  v-for="(p, n) in borrador.paginas"
                  :key="p.id ?? p.slug"
                  class="sw-seccion"
                  :class="{ 'sw-pagina-activa': paginaSel === p.id }"
                  :data-prueba="`pagina-${p.slug}`"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      class="min-w-0 flex-1 basis-40 text-left"
                      :aria-pressed="paginaSel === p.id"
                      @click="elegirPagina(p.id ?? null)"
                    >
                      <span class="block font-medium">{{ p.titulo }}</span>
                      <span class="block text-xs sw-suave"
                        >/p/{{ p.slug }} ·
                        {{
                          p.en_menu
                            ? $t("sitioWeb.paginas.enMenu")
                            : $t("sitioWeb.paginas.fueraMenu")
                        }}</span
                      >
                    </button>
                    <button
                      type="button"
                      class="tu-icono-btn shrink-0"
                      :disabled="n === 0"
                      :aria-label="
                        $t('sitioWeb.paginas.subir', { pagina: p.titulo })
                      "
                      @click="moverPagina(n, -1)"
                    >
                      <IconoNav nombre="arriba" :tam="16" />
                    </button>
                    <button
                      type="button"
                      class="tu-icono-btn shrink-0"
                      :disabled="n === borrador.paginas.length - 1"
                      :aria-label="
                        $t('sitioWeb.paginas.bajar', { pagina: p.titulo })
                      "
                      @click="moverPagina(n, 1)"
                    >
                      <IconoNav nombre="abajo" :tam="16" />
                    </button>
                    <button
                      type="button"
                      class="tu-btn tu-btn-fantasma shrink-0 text-sm"
                      :aria-expanded="configAbierta === p.id"
                      :data-prueba="`config-${p.slug}`"
                      @click="
                        configAbierta =
                          configAbierta === p.id ? null : (p.id ?? null)
                      "
                    >
                      {{ $t("sitioWeb.paginas.configurar") }}
                    </button>
                  </div>
                  <div v-if="configAbierta === p.id" class="mt-4 space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                      <div>
                        <label class="tu-label" :for="`sw-pag-titulo-${n}`">{{
                          $t("sitioWeb.paginas.campos.titulo")
                        }}</label>
                        <input
                          :id="`sw-pag-titulo-${n}`"
                          v-model="p.titulo"
                          class="tu-input"
                          :maxlength="datos.catalogo.max_titulo_pagina"
                        />
                      </div>
                      <div>
                        <label class="tu-label" :for="`sw-pag-dir-${n}`">{{
                          $t("sitioWeb.paginas.campos.direccion")
                        }}</label>
                        <div class="sw-direccion">
                          <span class="sw-suave">/p/</span>
                          <input
                            :id="`sw-pag-dir-${n}`"
                            class="tu-input"
                            :value="p.slug"
                            :maxlength="datos.catalogo.max_direccion"
                            :data-prueba="`direccion-${n}`"
                            @change="
                              cambiarDireccion(
                                p,
                                ($event.target as HTMLInputElement).value,
                              )
                            "
                          />
                        </div>
                      </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                      <input v-model="p.en_menu" type="checkbox" />
                      {{ $t("sitioWeb.paginas.campos.enMenu") }}
                    </label>
                    <SeoPaginaSitio
                      v-model="p.seo"
                      :catalogo="datos.catalogo"
                      :prefijo="`paginas.${n}.seo`"
                      :errores="errores"
                      :placeholder-titulo="p.titulo"
                    />
                    <p
                      v-if="
                        errorDe(`paginas.${n}.slug`) ||
                        errorDe(`paginas.${n}.titulo`)
                      "
                      class="text-sm"
                      style="color: var(--error)"
                    >
                      {{
                        errorDe(`paginas.${n}.slug`) ??
                        errorDe(`paginas.${n}.titulo`)
                      }}
                    </p>
                    <button
                      type="button"
                      class="tu-btn tu-btn-contorno tu-tono-peligro text-sm"
                      :data-prueba="`quitar-pagina-${p.slug}`"
                      @click="quitarPagina(n)"
                    >
                      {{ $t("sitioWeb.paginas.quitar") }}
                    </button>
                  </div>
                </li>
              </ol>
              <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  :disabled="!cabenPaginas || !conPlus"
                  data-prueba="agregar-pagina"
                  @click="agregandoPagina = true"
                >
                  {{ $t("sitioWeb.paginas.agregar") }}
                </button>
                <span class="tu-badge">{{ $t("sitioWeb.plus.etiqueta") }}</span>
                <p
                  v-if="!conPlus"
                  class="text-sm sw-suave"
                  data-prueba="plus-paginas"
                >
                  {{ $t("sitioWeb.plus.paginas") }}
                </p>
                <p v-if="!cabenPaginas" class="text-sm sw-suave">
                  {{
                    $t("sitioWeb.paginas.tope", {
                      n: datos.catalogo.paginas_maximas,
                    })
                  }}
                </p>
              </div>
              <p
                v-if="errorDe('paginas')"
                class="mt-2 text-sm"
                style="color: var(--error)"
              >
                {{ errorDe("paginas") }}
              </p>
            </div>

            <!-- Nueva página: su título (la dirección sale de él) -->
            <ModalDialogo
              :abierto="agregandoPagina"
              :titulo="$t('sitioWeb.paginas.nueva')"
              tam="md"
              @cerrar="agregandoPagina = false"
            >
              <form @submit.prevent="crearPagina">
                <label class="tu-label" for="sw-nueva-pagina">{{
                  $t("sitioWeb.paginas.campos.titulo")
                }}</label>
                <input
                  id="sw-nueva-pagina"
                  v-model="tituloNueva"
                  class="tu-input"
                  :maxlength="datos.catalogo.max_titulo_pagina"
                  :placeholder="$t('sitioWeb.paginas.ejemplo')"
                  data-prueba="titulo-pagina-nueva"
                />
                <p class="sw-ayuda">
                  {{
                    $t("sitioWeb.paginas.direccionAyuda", {
                      direccion: direccionDe(tituloNueva) || "…",
                    })
                  }}
                </p>
                <button
                  type="submit"
                  class="tu-btn tu-btn-primario mt-4"
                  :disabled="tituloNueva.trim() === ''"
                  data-prueba="crear-pagina"
                >
                  {{ $t("sitioWeb.paginas.crear") }}
                </button>
              </form>
            </ModalDialogo>

            <!-- Secciones -->
            <div class="tu-card p-5">
              <h2 class="text-lg font-medium" data-prueba="titulo-secciones">
                {{
                  paginaEditada
                    ? $t("sitioWeb.paginas.seccionesDe", {
                        pagina: paginaEditada.titulo,
                      })
                    : $t("sitioWeb.secciones.titulo")
                }}
              </h2>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{
                  paginaEditada
                    ? $t("sitioWeb.paginas.seccionesAyuda")
                    : $t("sitioWeb.secciones.ayuda")
                }}
              </p>
              <p
                v-if="paginaEditada && paginaEditada.secciones.length === 0"
                class="mt-4 text-sm sw-suave"
                data-prueba="pagina-vacia"
              >
                {{ $t("sitioWeb.paginas.vacia") }}
              </p>
              <ol class="mt-4 space-y-2" data-prueba="secciones">
                <li
                  v-for="(s, i) in seccionesActuales"
                  :key="claveSeccion(s)"
                  class="sw-seccion"
                  :class="{ 'sw-seccion-propia': esBloque(s.tipo) }"
                  :data-prueba="`seccion-${claveSeccion(s)}`"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-0 flex-1 basis-40">
                      <p class="font-medium">{{ nombreSeccion(s.tipo) }}</p>
                      <p
                        class="truncate text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ detalleSeccion(s) }}
                      </p>
                    </div>
                    <label
                      v-if="!esFija(s.tipo)"
                      class="flex shrink-0 items-center gap-1.5 text-sm"
                    >
                      <input
                        v-model="s.visible"
                        type="checkbox"
                        :data-prueba="`visible-${s.tipo}`"
                      />
                      {{
                        s.visible
                          ? $t("sitioWeb.secciones.visible")
                          : $t("sitioWeb.secciones.oculta")
                      }}
                    </label>
                    <button
                      type="button"
                      class="tu-icono-btn shrink-0"
                      :disabled="!puedeMover(i, -1)"
                      :aria-label="
                        $t('sitioWeb.secciones.subir', {
                          seccion: nombreSeccion(s.tipo),
                        })
                      "
                      :data-prueba="`subir-${s.tipo}`"
                      @click="mover(i, -1)"
                    >
                      <IconoNav nombre="arriba" :tam="16" />
                    </button>
                    <button
                      type="button"
                      class="tu-icono-btn shrink-0"
                      :disabled="!puedeMover(i, 1)"
                      :aria-label="
                        $t('sitioWeb.secciones.bajar', {
                          seccion: nombreSeccion(s.tipo),
                        })
                      "
                      :data-prueba="`bajar-${s.tipo}`"
                      @click="mover(i, 1)"
                    >
                      <IconoNav nombre="abajo" :tam="16" />
                    </button>
                    <button
                      type="button"
                      class="tu-btn tu-btn-fantasma shrink-0 text-sm"
                      :aria-expanded="abierta === claveSeccion(s)"
                      :data-prueba="`editar-${claveSeccion(s)}`"
                      @click="
                        abierta =
                          abierta === claveSeccion(s) ? null : claveSeccion(s)
                      "
                    >
                      {{
                        abierta === claveSeccion(s)
                          ? $t("sitioWeb.secciones.cerrar")
                          : $t("sitioWeb.secciones.editar")
                      }}
                    </button>
                  </div>

                  <!-- Un bloque propio: sus campos, duplicarlo o quitarlo -->
                  <div
                    v-if="esBloque(s.tipo) && abierta === claveSeccion(s)"
                    class="mt-4"
                  >
                    <EditorBloqueSitio
                      v-model="seccionesActuales[i]"
                      :catalogo="datos.catalogo"
                      :prefijo="`${prefijo}.${i}`"
                      :errores="errores"
                    />
                    <div class="mt-4 flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="tu-btn tu-btn-fantasma text-sm"
                        :disabled="!caben"
                        :data-prueba="`duplicar-${claveSeccion(s)}`"
                        @click="duplicar(i)"
                      >
                        {{ $t("sitioWeb.bloques.duplicar") }}
                      </button>
                      <button
                        type="button"
                        class="tu-btn tu-btn-contorno tu-tono-peligro text-sm"
                        :data-prueba="`quitar-${claveSeccion(s)}`"
                        @click="quitarBloque(i)"
                      >
                        {{ $t("sitioWeb.bloques.quitar") }}
                      </button>
                    </div>
                  </div>

                  <div
                    v-else-if="abierta === claveSeccion(s)"
                    class="mt-4 space-y-4"
                  >
                    <div>
                      <label class="tu-label" :for="`sw-titulo-${s.tipo}`">{{
                        s.tipo === "inicio"
                          ? $t("sitioWeb.secciones.campos.titular")
                          : $t("sitioWeb.secciones.campos.titulo")
                      }}</label>
                      <input
                        :id="`sw-titulo-${s.tipo}`"
                        class="tu-input"
                        :value="s.titulo ?? ''"
                        :maxlength="datos.catalogo.max_titulo"
                        :placeholder="
                          s.tipo === 'inicio'
                            ? (sesion.estudio?.nombre ?? '')
                            : nombreSeccion(s.tipo)
                        "
                        @input="
                          s.titulo = texto(
                            ($event.target as HTMLInputElement).value,
                          )
                        "
                      />
                      <p class="sw-ayuda">
                        {{
                          s.tipo === "inicio"
                            ? $t("sitioWeb.secciones.campos.titularAyuda")
                            : $t("sitioWeb.secciones.campos.tituloAyuda")
                        }}
                      </p>
                    </div>
                    <div
                      v-if="
                        datos.catalogo.con_texto.includes(s.tipo as TipoSeccion)
                      "
                    >
                      <label class="tu-label" :for="`sw-texto-${s.tipo}`">{{
                        s.tipo === "inicio"
                          ? $t("sitioWeb.secciones.campos.textoInicio")
                          : $t("sitioWeb.secciones.campos.texto")
                      }}</label>
                      <textarea
                        :id="`sw-texto-${s.tipo}`"
                        class="tu-input"
                        rows="5"
                        :value="s.texto ?? ''"
                        :maxlength="datos.catalogo.max_texto"
                        @input="
                          s.texto = texto(
                            ($event.target as HTMLTextAreaElement).value,
                          )
                        "
                      ></textarea>
                      <p v-if="s.tipo === 'inicio'" class="sw-ayuda">
                        {{ $t("sitioWeb.secciones.campos.textoInicioAyuda") }}
                      </p>
                    </div>
                    <div
                      v-if="
                        datos.catalogo.con_foto.includes(s.tipo as TipoSeccion)
                      "
                    >
                      <span class="tu-label">{{
                        $t("sitioWeb.secciones.campos.foto")
                      }}</span>
                      <CargadorImagen
                        :url="s.foto_url"
                        ruta="sitio/imagenes"
                        campo="imagen"
                        clave="url"
                        quitar-sin-borrar
                        proporcion="4 / 3"
                        :arrastra="$t('sitioWeb.secciones.campos.fotoArrastra')"
                        @update:url="s.foto_url = $event"
                      />
                    </div>
                    <p
                      v-if="
                        errorDe(`${prefijo}.${i}.titulo`) ||
                        errorDe(`${prefijo}.${i}.texto`)
                      "
                      class="text-sm"
                      style="color: var(--error)"
                    >
                      {{
                        errorDe(`${prefijo}.${i}.titulo`) ??
                        errorDe(`${prefijo}.${i}.texto`)
                      }}
                    </p>
                    <!-- En una página propia, la sección del sistema se quita -->
                    <button
                      v-if="paginaEditada"
                      type="button"
                      class="tu-btn tu-btn-contorno tu-tono-peligro text-sm"
                      :data-prueba="`quitar-${claveSeccion(s)}`"
                      @click="quitarSeccion(i)"
                    >
                      {{ $t("sitioWeb.paginas.quitarSeccion") }}
                    </button>
                  </div>
                </li>
              </ol>
              <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                  type="button"
                  class="tu-btn tu-btn-primario"
                  :disabled="!caben || !conPlus"
                  data-prueba="agregar-bloque"
                  @click="eligiendoBloque = true"
                >
                  {{ $t("sitioWeb.bloques.agregar") }}
                </button>
                <span class="tu-badge">{{ $t("sitioWeb.plus.etiqueta") }}</span>
                <p
                  v-if="!conPlus"
                  class="text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  data-prueba="plus-bloques"
                >
                  {{ $t("sitioWeb.plus.bloques") }}
                </p>
                <p
                  v-if="!caben"
                  class="text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{
                    $t("sitioWeb.bloques.tope", {
                      n: datos.catalogo.bloques_maximos,
                    })
                  }}
                </p>
              </div>
              <p
                v-if="errorDe(prefijo)"
                class="mt-2 text-sm"
                style="color: var(--error)"
              >
                {{ errorDe(prefijo) }}
              </p>
            </div>

            <!-- Versiones publicadas: volver a una -->
            <div
              v-if="(datos.versiones ?? []).length > 0"
              class="tu-card p-5"
              data-prueba="versiones-sitio"
            >
              <h2 class="text-lg font-medium">
                {{ $t("sitioWeb.versiones.titulo") }}
              </h2>
              <p class="mt-1 text-sm sw-suave">
                {{ $t("sitioWeb.versiones.ayuda") }}
              </p>
              <ul
                class="mt-3 divide-y"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <li
                  v-for="v in datos.versiones"
                  :key="v.id"
                  class="flex flex-wrap items-center justify-between gap-2 py-2.5"
                  data-prueba="version"
                >
                  <span class="min-w-0 text-sm">
                    <span class="block">{{
                      v.publicada_en ? fecha(v.publicada_en) : "—"
                    }}</span>
                    <span
                      v-if="v.publicada_por"
                      class="block text-xs sw-suave"
                      >{{
                        $t("sitioWeb.versiones.por", {
                          nombre: v.publicada_por,
                        })
                      }}</span
                    >
                  </span>
                  <span
                    v-if="v.actual"
                    class="tu-estado text-sm"
                    :style="{ '--tono': 'var(--exito)' }"
                    >{{ $t("sitioWeb.versiones.actual") }}</span
                  >
                  <button
                    v-else
                    type="button"
                    class="tu-btn tu-btn-fantasma text-sm"
                    :disabled="ocupado"
                    :data-prueba="`restaurar-${v.id}`"
                    @click="restaurarVersion(v)"
                  >
                    {{ $t("sitioWeb.versiones.volver") }}
                  </button>
                </li>
              </ul>
            </div>

            <!-- Pie de página: en todas las páginas -->
            <div class="tu-card p-5" data-prueba="pie-editor">
              <h2 class="text-lg font-medium">
                {{ $t("sitioWeb.pie.titulo") }}
              </h2>
              <p class="mt-1 text-sm sw-suave">
                {{ $t("sitioWeb.pie.ayuda") }}
              </p>
              <textarea
                v-model="borrador.pie.texto"
                class="tu-input mt-3"
                rows="3"
                :maxlength="datos.catalogo.max_pie"
                :aria-label="$t('sitioWeb.pie.titulo')"
                :placeholder="$t('sitioWeb.pie.ejemplo')"
              ></textarea>
            </div>

            <!-- Qué bloque agregar -->
            <ModalDialogo
              :abierto="eligiendoBloque"
              :titulo="$t('sitioWeb.bloques.elegir')"
              tam="lg"
              @cerrar="eligiendoBloque = false"
            >
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("sitioWeb.bloques.elegirAyuda") }}
              </p>
              <!-- En una página propia, también las secciones de tu negocio -->
              <template v-if="seccionesDisponibles.length > 0">
                <h3 class="mt-4 text-sm font-medium">
                  {{ $t("sitioWeb.paginas.delNegocio") }}
                </h3>
                <ul class="sw-catalogo mt-2" data-prueba="catalogo-secciones">
                  <li v-for="tipo in seccionesDisponibles" :key="tipo">
                    <button
                      type="button"
                      class="sw-opcion-bloque"
                      :data-prueba="`nueva-seccion-${tipo}`"
                      @click="agregarSeccion(tipo)"
                    >
                      <span class="font-medium">{{ nombreSeccion(tipo) }}</span>
                      <span class="mt-1 block text-sm sw-suave">{{
                        tm(`sitioWeb.secciones.contenido.${tipo}`)
                      }}</span>
                    </button>
                  </li>
                </ul>
                <h3 class="mt-5 text-sm font-medium">
                  {{ $t("sitioWeb.paginas.propios") }}
                </h3>
              </template>
              <ul class="sw-catalogo mt-4" data-prueba="catalogo-bloques">
                <li v-for="tipo in datos.catalogo.bloques" :key="tipo">
                  <button
                    type="button"
                    class="sw-opcion-bloque"
                    :data-prueba="`nuevo-${tipo}`"
                    @click="agregarBloque(tipo)"
                  >
                    <span class="font-medium">{{
                      $t(`sitioWeb.bloques.nombres.${tipo}`)
                    }}</span>
                    <span
                      class="mt-1 block text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ $t(`sitioWeb.bloques.descripciones.${tipo}`) }}</span
                    >
                  </button>
                </li>
              </ul>
            </ModalDialogo>

            <!-- Banners -->
            <div class="tu-card p-5" data-prueba="banners">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 class="text-lg font-medium">
                    {{ $t("sitioWeb.banners.titulo") }}
                  </h2>
                  <p
                    class="mt-1 text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ $t("sitioWeb.banners.ayuda") }}
                  </p>
                </div>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma text-sm"
                  :disabled="
                    borrador.banners.length >= datos.catalogo.banners_maximos
                  "
                  data-prueba="agregar-banner"
                  @click="agregarBanner"
                >
                  {{ $t("sitioWeb.banners.agregar") }}
                </button>
              </div>
              <p
                v-if="borrador.banners.length === 0"
                class="mt-4 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("sitioWeb.banners.vacio") }}
              </p>
              <p
                v-else-if="
                  borrador.banners.length >= datos.catalogo.banners_maximos
                "
                class="mt-2 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  $t("sitioWeb.banners.tope", {
                    n: datos.catalogo.banners_maximos,
                  })
                }}
              </p>
              <ul class="mt-4 space-y-4">
                <li
                  v-for="(b, i) in borrador.banners"
                  :key="b.id ?? `nuevo-${i}`"
                  class="sw-seccion space-y-4"
                  :data-prueba="`banner-${i}`"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                      <label class="tu-label" :for="`sw-banner-titulo-${i}`">{{
                        $t("sitioWeb.banners.campos.titulo")
                      }}</label>
                      <input
                        :id="`sw-banner-titulo-${i}`"
                        v-model="b.titulo"
                        class="tu-input"
                        :maxlength="datos.catalogo.max_titulo"
                      />
                    </div>
                    <button
                      type="button"
                      class="tu-icono-btn mt-6 shrink-0"
                      :aria-label="$t('sitioWeb.banners.quitar')"
                      @click="quitarBanner(i)"
                    >
                      <IconoNav nombre="cerrar" :tam="16" />
                    </button>
                  </div>
                  <div>
                    <label class="tu-label" :for="`sw-banner-texto-${i}`">{{
                      $t("sitioWeb.banners.campos.texto")
                    }}</label>
                    <input
                      :id="`sw-banner-texto-${i}`"
                      class="tu-input"
                      :value="b.texto ?? ''"
                      maxlength="240"
                      @input="
                        b.texto = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label
                        class="tu-label"
                        :for="`sw-banner-enlace-texto-${i}`"
                        >{{ $t("sitioWeb.banners.campos.enlaceTexto") }}</label
                      >
                      <input
                        :id="`sw-banner-enlace-texto-${i}`"
                        class="tu-input"
                        :value="b.enlace_texto ?? ''"
                        maxlength="40"
                        @input="
                          b.enlace_texto = texto(
                            ($event.target as HTMLInputElement).value,
                          )
                        "
                      />
                    </div>
                    <div>
                      <label class="tu-label" :for="`sw-banner-enlace-${i}`">{{
                        $t("sitioWeb.banners.campos.enlaceUrl")
                      }}</label>
                      <input
                        :id="`sw-banner-enlace-${i}`"
                        class="tu-input"
                        :value="b.enlace_url ?? ''"
                        maxlength="300"
                        inputmode="url"
                        @input="
                          b.enlace_url = texto(
                            ($event.target as HTMLInputElement).value,
                          )
                        "
                      />
                    </div>
                  </div>
                  <p class="sw-ayuda -mt-2">
                    {{ $t("sitioWeb.banners.campos.enlaceAyuda") }}
                  </p>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label class="tu-label" :for="`sw-banner-desde-${i}`">{{
                        $t("sitioWeb.banners.campos.desde")
                      }}</label>
                      <input
                        :id="`sw-banner-desde-${i}`"
                        type="date"
                        class="tu-input"
                        :value="b.desde ?? ''"
                        @input="
                          b.desde = texto(
                            ($event.target as HTMLInputElement).value,
                          )
                        "
                      />
                    </div>
                    <div>
                      <label class="tu-label" :for="`sw-banner-hasta-${i}`">{{
                        $t("sitioWeb.banners.campos.hasta")
                      }}</label>
                      <input
                        :id="`sw-banner-hasta-${i}`"
                        type="date"
                        class="tu-input"
                        :value="b.hasta ?? ''"
                        @input="
                          b.hasta = texto(
                            ($event.target as HTMLInputElement).value,
                          )
                        "
                      />
                    </div>
                  </div>
                  <p class="sw-ayuda -mt-2">
                    {{ $t("sitioWeb.banners.campos.vigenciaAyuda") }}
                  </p>
                  <div>
                    <span class="tu-label">{{
                      $t("sitioWeb.banners.campos.foto")
                    }}</span>
                    <CargadorImagen
                      :url="b.foto_url"
                      ruta="sitio/imagenes"
                      campo="imagen"
                      clave="url"
                      quitar-sin-borrar
                      proporcion="16 / 7"
                      :arrastra="$t('sitioWeb.secciones.campos.fotoArrastra')"
                      @update:url="b.foto_url = $event"
                    />
                  </div>
                  <p
                    v-for="campo in [
                      'titulo',
                      'texto',
                      'enlace_texto',
                      'enlace_url',
                      'desde',
                      'hasta',
                      'foto_url',
                    ]"
                    v-show="errorDe(`banners.${i}.${campo}`)"
                    :key="campo"
                    class="text-sm"
                    style="color: var(--error)"
                  >
                    {{ errorDe(`banners.${i}.${campo}`) }}
                  </p>
                </li>
              </ul>
            </div>
          </div>

          <!-- Vista previa (en pantallas angostas, en otra pestaña) -->
          <aside class="sw-previa" data-prueba="vista-previa">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="text-lg font-medium">
                {{ $t("sitioWeb.acciones.vistaPrevia") }}
              </h2>
              <div class="flex flex-wrap items-center gap-2">
                <div class="tu-segmentado" role="group">
                  <button
                    type="button"
                    :aria-pressed="anchoPrevia === 'telefono'"
                    @click="anchoPrevia = 'telefono'"
                  >
                    {{ $t("sitioWeb.acciones.telefono") }}
                  </button>
                  <button
                    type="button"
                    :aria-pressed="anchoPrevia === 'computadora'"
                    @click="anchoPrevia = 'computadora'"
                  >
                    {{ $t("sitioWeb.acciones.computadora") }}
                  </button>
                </div>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma text-sm"
                  @click="vuelta++"
                >
                  {{ $t("sitioWeb.acciones.actualizar") }}
                </button>
                <a
                  :href="urlPrevia"
                  target="_blank"
                  rel="noopener"
                  class="tu-enlace text-sm"
                  >{{ $t("sitioWeb.acciones.abrirPestana") }}</a
                >
              </div>
            </div>
            <p
              class="mt-2 text-sm"
              :style="{
                color: previaValida ? 'var(--texto-suave)' : 'var(--aviso)',
              }"
              data-prueba="previa-estado"
            >
              {{
                !previaValida
                  ? $t("sitioWeb.editor.incompleto")
                  : sinGuardar
                    ? $t("sitioWeb.editor.enVivo")
                    : $t("sitioWeb.editor.ayuda")
              }}
            </p>
            <div class="sw-marco mt-3" :class="`sw-marco-${anchoPrevia}`">
              <iframe
                ref="marcoPrevia"
                :key="vuelta"
                :src="urlPrevia"
                :title="$t('sitioWeb.acciones.vistaPrevia')"
                loading="lazy"
              ></iframe>
            </div>
          </aside>
        </div>
      </div>

      <SitioReservasVentas
        v-if="pestana === 'reservas'"
        class="mt-6"
        :secciones="borrador.secciones"
        @mostrar="mostrarSeccion"
      />
      <SitioCompartir v-if="pestana === 'compartir'" class="mt-6" />
      <SitioDominio
        v-if="pestana === 'dominio'"
        v-model:seo="borrador.seo"
        class="mt-6"
        :catalogo="datos.catalogo"
        :errores="errores"
        :publicacion="
          datos.publicacion ?? {
            publicado: datos.pagina_publica,
            privado: false,
          }
        "
        :paginas="borrador.paginas.length"
        :con-plus="conPlus"
        @publicacion="publicacionActualizada"
        @ir-a-paginas="elegirPestana('contenido')"
      />
    </template>
  </section>
</template>

<style scoped>
/* La barra del estado y publicar queda a la vista al bajar (debajo de la barra
   superior del panel). En el teléfono no: con sus botones ocuparía media pantalla. */
@media (min-width: 768px) {
  .sw-barra {
    position: sticky;
    top: 4.5rem;
    z-index: 5;
  }
}
.sw-bloqueado {
  opacity: 0.6;
}
.sw-columnas {
  display: grid;
  gap: 1.5rem;
}
@media (min-width: 1280px) {
  .sw-columnas {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }
  .sw-previa {
    position: sticky;
    top: 5rem;
  }
}
.sw-ayuda {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.sw-punto {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
}
.sw-punto-exito {
  background: var(--exito);
}
.sw-punto-aviso {
  background: var(--aviso);
}
.sw-plantilla {
  display: block;
  width: 100%;
  padding: 0.875rem;
  text-align: left;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.sw-plantilla-activa {
  border-color: var(--primario);
  box-shadow: 0 0 0 1px var(--primario);
}
.sw-seccion {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
}
/* Un bloque propio se distingue de las secciones del sistema por su borde. */
.sw-seccion-propia {
  border-left: 3px solid var(--primario);
}
.sw-suave {
  color: var(--texto-suave);
}
.sw-color {
  width: 3rem;
  height: 2.25rem;
  padding: 0;
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  background: transparent;
  cursor: pointer;
}
/* El color de la app, solo para consultarlo: se cambia en Configuración. */
.sw-color-app {
  padding: 0.75rem 0.9rem;
  border: 1px dashed var(--borde);
  border-radius: 0.6rem;
}
.sw-muestra {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  border: 1px solid var(--borde);
  border-radius: 0.3rem;
}
.sw-tipografias {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
  gap: 0.5rem;
}
.sw-tipografia {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
  text-align: left;
}
.sw-tipografia-activa {
  border-color: var(--primario);
  box-shadow: 0 0 0 1px var(--primario);
}
.sw-pagina-activa {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 5%, var(--superficie));
}
.sw-direccion {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.sw-catalogo {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
  gap: 0.75rem;
}
.sw-opcion-bloque {
  display: block;
  width: 100%;
  height: 100%;
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
  text-align: left;
}
.sw-opcion-bloque:hover,
.sw-opcion-bloque:focus-visible {
  border-color: var(--primario);
}
.sw-marco {
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  overflow: hidden;
  background: var(--fondo);
}
.sw-marco iframe {
  display: block;
  width: 100%;
  height: 70vh;
  border: 0;
}
.sw-marco-telefono {
  max-width: 390px;
  margin-left: auto;
  margin-right: auto;
}
/* En pantallas angostas el marco no cabe: se abre en otra pestaña. */
@media (max-width: 767px) {
  .sw-marco {
    display: none;
  }
}
</style>
