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

import ArchivoDeMensaje, {
  type ArchivoMensaje,
} from "@/components/ArchivoDeMensaje.vue";
import BuscarPersona, {
  type PersonaBuscable,
} from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PlantillaBandeja from "@/components/PlantillaBandeja.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  registrarWhatsApp,
  type DatosRegistroWhatsApp,
} from "@/lib/registroWhatsApp";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * La bandeja de WhatsApp e Instagram (ADR 0200): las conversaciones del negocio a la
 * izquierda y la elegida a la derecha (en el teléfono, una a la vez). Se contesta en
 * las 24 horas que da Meta desde el último mensaje de esa persona; se archiva y se
 * vincula con la ficha de un cliente. Quien configura las integraciones conecta aquí
 * el número de WhatsApp y la cuenta de Instagram. Se refresca sola cada 20 segundos.
 */
type Canal = "whatsapp" | "instagram" | "messenger";
interface Conversacion {
  id: string;
  canal: Canal;
  contacto: string | null;
  telefono: string | null;
  persona: { id: string; nombre: string } | null;
  estado: "abierta" | "archivada";
  no_leidos: number;
  ultimo_texto: string | null;
  ultimo_mensaje_en: string | null;
  puede_contestar: boolean;
  contestar_hasta: string | null;
  // Instagram o Messenger después de 24 horas (etiqueta HUMAN_AGENT).
  como_agente?: boolean;
}
interface Mensaje {
  id: string;
  direccion: "entrante" | "saliente";
  tipo: string;
  texto: string | null;
  estado: string;
  enviado_en: string;
  enviado_por: string | null;
  archivo?: ArchivoMensaje | null;
}
interface Detalle extends Conversacion {
  // Los más recientes; los anteriores se piden por tandas.
  mensajes: Mensaje[];
  hay_anteriores?: boolean;
}
interface ConexionCanal {
  id: string;
  canal: Canal;
  nombre: string | null;
  estado: "activo" | "reconectar";
  ultimo_error: string | null;
}
interface Canales {
  whatsapp: {
    disponible: boolean;
    registro: DatosRegistroWhatsApp | null;
    conexion: ConexionCanal | null;
  };
  instagram: { disponible: boolean; conexion: ConexionCanal | null };
  messenger?: { disponible: boolean; conexion: ConexionCanal | null };
}
interface PaginaFacebook {
  id: string;
  nombre: string;
  ocupada: boolean;
}

const REFRESCO_MS = 20000;

const { t, te, locale } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const ruta = useRoute() as ReturnType<typeof useRoute> | undefined;
const router = useRouter() as ReturnType<typeof useRouter> | undefined;
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeCanales = computed(() => sesion.puede("integraciones.configurar"));

// ---- Lista ----
const estado = ref<"abierta" | "archivada">("abierta");
const canal = ref<"" | Canal>("");
const q = ref("");
const pagina = ref(1);
const lista = ref<Conversacion[]>([]);
const meta = ref({ page: 1, ultima_pagina: 1, total: 0, per_page: 30 });
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargarLista(silencioso = false): Promise<void> {
  if (!silencioso) {
    cargando.value = true;
  }
  try {
    const { data } = await api.get<{
      data: Conversacion[];
      meta: typeof meta.value;
    }>(`${base.value}/bandeja/conversaciones`, {
      params: {
        estado: estado.value,
        canal: canal.value || undefined,
        q: q.value.trim() || undefined,
        page: pagina.value,
      },
    });
    lista.value = data.data;
    meta.value = data.meta;
    error.value = null;
  } catch (err) {
    if (!silencioso) {
      error.value = mensajeDeError(err, t("bandejaMensajes.error"));
    }
  } finally {
    cargando.value = false;
  }
}

let busqueda: ReturnType<typeof setTimeout> | undefined;
watch([estado, canal], () => {
  pagina.value = 1;
  void cargarLista();
});
watch(q, () => {
  clearTimeout(busqueda);
  busqueda = setTimeout(() => {
    pagina.value = 1;
    void cargarLista();
  }, 300);
});
function irA(n: number): void {
  pagina.value = n;
  void cargarLista();
}

// ---- Conversación ----
const seleccion = ref<Detalle | null>(null);
const texto = ref("");
const enviando = ref(false);
const hilo = ref<HTMLElement | null>(null);

async function abrir(id: string, silencioso = false): Promise<void> {
  try {
    const { data } = await api.get<{ data: Detalle }>(
      `${base.value}/bandeja/conversaciones/${id}`,
    );
    const previa = seleccion.value?.id === id ? seleccion.value : null;
    const ultimo = previa?.mensajes.at(-1)?.id;
    // Al refrescar, los anteriores que ya se cargaron se quedan.
    const desde =
      previa?.mensajes.findIndex((m) => m.id === data.data.mensajes[0]?.id) ??
      -1;
    if (previa !== null && desde > 0) {
      data.data.mensajes = [
        ...previa.mensajes.slice(0, desde),
        ...data.data.mensajes,
      ];
      data.data.hay_anteriores = previa.hay_anteriores;
    }
    const nuevos = previa === null || ultimo !== data.data.mensajes.at(-1)?.id;
    seleccion.value = data.data;
    const enLista = lista.value.find((c) => c.id === id);
    if (enLista) {
      enLista.no_leidos = 0;
    }
    if (nuevos) {
      await nextTick();
      hilo.value?.scrollTo?.({ top: hilo.value.scrollHeight });
    }
  } catch (err) {
    if (!silencioso) {
      toast.error(mensajeDeError(err, t("bandejaMensajes.error")));
    }
  }
}

// «Cargar mensajes anteriores»: la tanda antes del primero, sin mover lo que se lee.
const cargandoAnteriores = ref(false);
async function cargarAnteriores(): Promise<void> {
  const conversacion = seleccion.value;
  const primero = conversacion?.mensajes[0];
  if (
    conversacion === null ||
    primero === undefined ||
    cargandoAnteriores.value
  ) {
    return;
  }
  cargandoAnteriores.value = true;
  try {
    const { data } = await api.get<{
      data: Mensaje[];
      meta: { hay_anteriores: boolean };
    }>(`${base.value}/bandeja/conversaciones/${conversacion.id}/mensajes`, {
      params: { antes: primero.id },
    });
    const actual = seleccion.value;
    if (
      actual?.id !== conversacion.id ||
      actual.mensajes[0]?.id !== primero.id
    ) {
      return;
    }
    const alto = hilo.value?.scrollHeight ?? 0;
    const arriba = hilo.value?.scrollTop ?? 0;
    actual.mensajes = [...data.data, ...actual.mensajes];
    actual.hay_anteriores = data.meta.hay_anteriores;
    await nextTick();
    if (hilo.value) {
      hilo.value.scrollTop = hilo.value.scrollHeight - alto + arriba;
    }
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.error")));
  } finally {
    cargandoAnteriores.value = false;
  }
}

async function enviar(): Promise<void> {
  const conversacion = seleccion.value;
  const cuerpo = texto.value.trim();
  if (conversacion === null || cuerpo === "" || enviando.value) {
    return;
  }
  enviando.value = true;
  try {
    const { data } = await api.post<{ data: Mensaje }>(
      `${base.value}/bandeja/conversaciones/${conversacion.id}/mensajes`,
      { texto: cuerpo },
    );
    conversacion.mensajes.push(data.data);
    texto.value = "";
    await nextTick();
    hilo.value?.scrollTo?.({ top: hilo.value.scrollHeight });
    void cargarLista(true);
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.error")));
  } finally {
    enviando.value = false;
  }
}

// Fuera de las 24 horas, por WhatsApp: una plantilla aprobada del número.
const conPlantilla = ref(false);
watch(
  () => seleccion.value?.id,
  () => {
    conPlantilla.value = false;
  },
);
async function plantillaEnviada(): Promise<void> {
  conPlantilla.value = false;
  toast.exito(t("bandejaMensajes.plantillas.enviada"));
  if (seleccion.value !== null) {
    await abrir(seleccion.value.id, true);
  }
  void cargarLista(true);
}

// Una imagen o un PDF (por Instagram, solo imágenes); lo escrito va como pie.
const elegirArchivo = ref<HTMLInputElement | null>(null);
async function adjuntar(evento: Event): Promise<void> {
  const conversacion = seleccion.value;
  const entrada = evento.target as HTMLInputElement;
  const archivo = entrada.files?.[0];
  entrada.value = "";
  if (conversacion === null || archivo === undefined || enviando.value) {
    return;
  }
  const datos = new FormData();
  datos.append("archivo", archivo);
  if (texto.value.trim() !== "") {
    datos.append("texto", texto.value.trim());
  }
  enviando.value = true;
  try {
    await api.post<{ data: Mensaje }>(
      `${base.value}/bandeja/conversaciones/${conversacion.id}/archivos`,
      datos,
    );
    texto.value = "";
    // Por Instagram el pie sale como otro mensaje: se vuelve a leer la conversación.
    await abrir(conversacion.id, true);
    void cargarLista(true);
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.error")));
  } finally {
    enviando.value = false;
  }
}

async function actualizar(
  cambios: { estado?: string; persona_id?: string | null },
  aviso: string,
): Promise<void> {
  const conversacion = seleccion.value;
  if (conversacion === null) {
    return;
  }
  try {
    const { data } = await api.put<{ data: Conversacion }>(
      `${base.value}/bandeja/conversaciones/${conversacion.id}`,
      cambios,
    );
    Object.assign(conversacion, data.data);
    toast.exito(aviso);
    if (cambios.estado !== undefined) {
      seleccion.value = null;
    }
    void cargarLista(true);
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.error")));
  }
}

const eligiendoFicha = ref(false);
const fichaElegida = ref("");
function vincular(persona: PersonaBuscable): void {
  eligiendoFicha.value = false;
  void actualizar({ persona_id: persona.id }, t("bandejaMensajes.vinculada"));
}

function nombreDe(c: Conversacion): string {
  return (
    c.persona?.nombre ??
    c.contacto ??
    c.telefono ??
    t("bandejaMensajes.sinNombre")
  );
}
function textoDe(m: Mensaje): string {
  if (m.texto) {
    return m.texto;
  }
  const llave = `bandejaMensajes.tipos.${m.tipo}`;
  return `[${te(llave) ? t(llave) : t("bandejaMensajes.tipos.adjunto")}]`;
}
function hora(iso: string | null, conFecha = false): string {
  if (!iso) {
    return "";
  }
  return new Intl.DateTimeFormat(locale.value, {
    timeZone: sesion.zonaHoraria,
    ...(conFecha
      ? { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }
      : { hour: "2-digit", minute: "2-digit" }),
  }).format(new Date(iso));
}
function etiquetaEstado(m: Mensaje): string | null {
  if (m.direccion !== "saliente") {
    return null;
  }
  const llave = `bandejaMensajes.estados.${m.estado}`;
  return te(llave) ? t(llave) : null;
}

// ---- Canales ----
const canales = ref<Canales | null>(null);
const conectando = ref<Canal | null>(null);

async function cargarCanales(): Promise<void> {
  if (!puedeCanales.value) {
    return;
  }
  try {
    const { data } = await api.get<{ data: Canales }>(
      `${base.value}/bandeja/canales`,
    );
    canales.value = data.data;
  } catch {
    canales.value = null;
  }
}

async function conectarWhatsApp(): Promise<void> {
  const registro = canales.value?.whatsapp.registro;
  if (!registro) {
    return;
  }
  conectando.value = "whatsapp";
  try {
    const resultado = await registrarWhatsApp(registro);
    if (resultado === null) {
      toast.error(t("bandejaMensajes.canales.cancelado"));
      return;
    }
    await api.post(`${base.value}/bandeja/canales/whatsapp`, resultado);
    toast.exito(t("bandejaMensajes.canales.whatsappListo"));
    await cargarCanales();
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
  } finally {
    conectando.value = null;
  }
}

async function conectarInstagram(): Promise<void> {
  conectando.value = "instagram";
  try {
    const { data } = await api.post<{ data: { url: string } }>(
      `${base.value}/bandeja/canales/instagram/conectar`,
    );
    window.location.assign(data.data.url);
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
    conectando.value = null;
  }
}

async function desconectar(conexion: ConexionCanal): Promise<void> {
  if (
    !(await confirmar(
      t("bandejaMensajes.canales.desconectarConfirmar", {
        nombre: conexion.nombre ?? t(`bandejaMensajes.canal.${conexion.canal}`),
      }),
      { peligro: true, aceptar: t("bandejaMensajes.canales.desconectar") },
    ))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/bandeja/canales/${conexion.canal}`);
    toast.exito(t("bandejaMensajes.canales.desconectado"));
    await cargarCanales();
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
  }
}

async function conectarMessenger(): Promise<void> {
  conectando.value = "messenger";
  try {
    const { data } = await api.post<{ data: { url: string } }>(
      `${base.value}/bandeja/canales/messenger/conectar`,
    );
    window.location.assign(data.data.url);
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
    conectando.value = null;
  }
}

function conectar(clave: Canal): void {
  if (clave === "whatsapp") {
    void conectarWhatsApp();
  } else if (clave === "instagram") {
    void conectarInstagram();
  } else {
    void conectarMessenger();
  }
}

// Con varias páginas de Facebook, quien conecta elige una (unos minutos).
const eleccionPagina = ref<{
  seleccion: string;
  paginas: PaginaFacebook[];
} | null>(null);
const paginaElegida = ref("");
async function elegirPagina(): Promise<void> {
  const eleccion = eleccionPagina.value;
  if (eleccion === null || paginaElegida.value === "") {
    return;
  }
  conectando.value = "messenger";
  try {
    await api.post(`${base.value}/bandeja/canales/messenger/pagina`, {
      seleccion: eleccion.seleccion,
      pagina_id: paginaElegida.value,
    });
    eleccionPagina.value = null;
    toast.exito(t("bandejaMensajes.canales.messengerListo"));
    await cargarCanales();
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
  } finally {
    conectando.value = null;
  }
}

/**
 * La vuelta de Instagram o de Facebook (Messenger): confirma el código con la sesión y
 * lo quita de la URL. Con varias páginas de Facebook, pide elegir una.
 */
async function procesarVuelta(): Promise<void> {
  const consulta = ruta?.query ?? {};
  const clave: "instagram" | "messenger" | null =
    typeof consulta.instagram === "string"
      ? "instagram"
      : typeof consulta.messenger === "string"
        ? "messenger"
        : null;
  if (clave === null) {
    return;
  }
  const resultado = consulta[clave];
  const { codigo, estado: estadoOauth } = consulta;
  const resto = { ...consulta };
  delete resto[clave];
  delete resto.codigo;
  delete resto.estado;
  void router?.replace({ query: resto });
  if (
    resultado !== "conectar" ||
    typeof codigo !== "string" ||
    typeof estadoOauth !== "string"
  ) {
    toast.error(
      t(
        resultado === "cancelado"
          ? "bandejaMensajes.canales.cancelado"
          : "bandejaMensajes.canales.error",
      ),
    );
    return;
  }
  try {
    const respuesta = await api.post<{
      data: { seleccion?: string; paginas?: PaginaFacebook[] };
    }>(`${base.value}/bandeja/canales/${clave}/confirmar`, {
      codigo,
      estado: estadoOauth,
    });
    const datos = respuesta.data.data;
    if (clave === "messenger" && datos.seleccion && datos.paginas) {
      eleccionPagina.value = {
        seleccion: datos.seleccion,
        paginas: datos.paginas,
      };
      paginaElegida.value = "";
      return;
    }
    toast.exito(
      t(
        clave === "instagram"
          ? "bandejaMensajes.canales.instagramListo"
          : "bandejaMensajes.canales.messengerListo",
      ),
    );
    await cargarCanales();
  } catch (err) {
    toast.error(mensajeDeError(err, t("bandejaMensajes.canales.error")));
  }
}

const filasCanales = computed(() => {
  if (canales.value === null) {
    return [];
  }
  return (["whatsapp", "instagram", "messenger"] as const).map((clave) => ({
    clave,
    disponible: canales.value![clave]?.disponible ?? false,
    conexion: canales.value![clave]?.conexion ?? null,
  }));
});

let temporizador: ReturnType<typeof setInterval> | undefined;
onMounted(async () => {
  await Promise.all([cargarLista(), cargarCanales()]);
  await procesarVuelta();
  temporizador = setInterval(() => {
    if (document.visibilityState !== "visible") {
      return;
    }
    void cargarLista(true);
    if (seleccion.value !== null) {
      void abrir(seleccion.value.id, true);
    }
  }, REFRESCO_MS);
});
onBeforeUnmount(() => {
  clearInterval(temporizador);
  clearTimeout(busqueda);
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('bandejaMensajes.titulo')"
      :subtitulo="$t('bandejaMensajes.subtitulo')"
    />

    <section
      v-if="puedeCanales && canales"
      class="tu-tarjeta bm-canales"
      data-prueba="canales"
    >
      <div>
        <h2 class="bm-subtitulo">{{ $t("bandejaMensajes.canales.titulo") }}</h2>
        <p class="tu-hint">{{ $t("bandejaMensajes.canales.ayuda") }}</p>
      </div>
      <ul class="bm-lista-canales">
        <li
          v-for="fila in filasCanales"
          :key="fila.clave"
          :data-prueba="`canal-${fila.clave}`"
        >
          <span class="bm-canal-nombre">{{
            $t(`bandejaMensajes.canal.${fila.clave}`)
          }}</span>
          <span v-if="fila.conexion" class="bm-estado">
            <span
              class="bm-punto"
              :data-estado="fila.conexion.estado"
              aria-hidden="true"
            />{{
              fila.conexion.estado === "activo"
                ? $t("bandejaMensajes.canales.conectado")
                : $t("bandejaMensajes.canales.reconectar")
            }}<template v-if="fila.conexion.nombre">
              · {{ fila.conexion.nombre }}</template
            >
          </span>
          <span v-else-if="!fila.disponible" class="tu-hint">{{
            $t("bandejaMensajes.canales.noDisponible")
          }}</span>
          <span class="bm-acciones">
            <button
              v-if="
                fila.disponible &&
                (!fila.conexion || fila.conexion.estado === 'reconectar')
              "
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="conectando !== null"
              @click="conectar(fila.clave)"
            >
              {{
                conectando === fila.clave
                  ? $t("bandejaMensajes.canales.conectando")
                  : $t("bandejaMensajes.canales.conectar")
              }}
            </button>
            <button
              v-if="fila.conexion"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="desconectar(fila.conexion)"
            >
              {{ $t("bandejaMensajes.canales.desconectar") }}
            </button>
          </span>
        </li>
      </ul>
      <form
        v-if="eleccionPagina"
        class="bm-elegir-pagina"
        data-prueba="elegir-pagina"
        @submit.prevent="elegirPagina"
      >
        <label class="tu-label" for="bm-pagina">{{
          $t("bandejaMensajes.canales.elegirPagina")
        }}</label>
        <select id="bm-pagina" v-model="paginaElegida" class="tu-input">
          <option
            v-for="p in eleccionPagina.paginas"
            :key="p.id"
            :value="p.id"
            :disabled="p.ocupada"
          >
            {{
              p.ocupada
                ? $t("bandejaMensajes.canales.paginaOcupada", {
                    nombre: p.nombre,
                  })
                : p.nombre
            }}
          </option>
        </select>
        <button
          type="submit"
          class="tu-btn tu-btn-primario text-sm"
          :disabled="paginaElegida === '' || conectando !== null"
        >
          {{ $t("bandejaMensajes.canales.elegir") }}
        </button>
      </form>
    </section>

    <div class="bm-cuerpo" :class="{ 'bm-con-detalle': seleccion !== null }">
      <aside class="tu-tarjeta bm-columna-lista">
        <div class="bm-filtros">
          <div class="bm-segmentos" role="group">
            <button
              v-for="opcion in ['abierta', 'archivada'] as const"
              :key="opcion"
              type="button"
              class="bm-segmento"
              :aria-pressed="estado === opcion"
              @click="estado = opcion"
            >
              {{
                opcion === "abierta"
                  ? $t("bandejaMensajes.abiertas")
                  : $t("bandejaMensajes.archivadas")
              }}
            </button>
          </div>
          <select
            v-model="canal"
            class="tu-input text-sm"
            :aria-label="$t('bandejaMensajes.todos')"
          >
            <option value="">{{ $t("bandejaMensajes.todos") }}</option>
            <option value="whatsapp">
              {{ $t("bandejaMensajes.canal.whatsapp") }}
            </option>
            <option value="instagram">
              {{ $t("bandejaMensajes.canal.instagram") }}
            </option>
            <option value="messenger">
              {{ $t("bandejaMensajes.canal.messenger") }}
            </option>
          </select>
          <input
            v-model="q"
            type="search"
            class="tu-input text-sm"
            :placeholder="$t('bandejaMensajes.buscar')"
            :aria-label="$t('bandejaMensajes.buscar')"
          />
        </div>

        <p v-if="error" class="tu-hint bm-error" role="alert">{{ error }}</p>
        <ul
          v-else-if="lista.length > 0"
          class="bm-conversaciones"
          data-prueba="conversaciones"
        >
          <li v-for="c in lista" :key="c.id">
            <button
              type="button"
              class="bm-conversacion"
              :aria-current="seleccion?.id === c.id ? 'true' : undefined"
              @click="abrir(c.id)"
            >
              <span class="bm-fila">
                <strong class="bm-nombre">{{ nombreDe(c) }}</strong>
                <span class="bm-cuando">{{
                  hora(c.ultimo_mensaje_en, true)
                }}</span>
              </span>
              <span class="bm-fila">
                <span class="bm-vista-previa">{{ c.ultimo_texto }}</span>
                <span
                  v-if="c.no_leidos > 0"
                  class="bm-sin-leer"
                  :aria-label="
                    $t('bandejaMensajes.sinLeer', { n: c.no_leidos })
                  "
                  >{{ c.no_leidos }}</span
                >
              </span>
              <span class="bm-canal">{{
                $t(`bandejaMensajes.canal.${c.canal}`)
              }}</span>
            </button>
          </li>
        </ul>
        <EstadoVacio
          v-else-if="!cargando"
          compacto
          icono="mensaje"
          :titulo="
            estado === 'archivada'
              ? $t('bandejaMensajes.vacioArchivadas')
              : $t('bandejaMensajes.vacio')
          "
          :texto="
            estado === 'archivada'
              ? undefined
              : $t('bandejaMensajes.vacioTexto')
          "
        />
        <PaginacionListado
          v-if="meta.ultima_pagina > 1"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="irA"
        />
      </aside>

      <article
        v-if="seleccion"
        class="tu-tarjeta bm-columna-hilo"
        data-prueba="conversacion"
      >
        <header class="bm-cabecera">
          <button
            type="button"
            class="tu-enlace text-sm bm-volver"
            @click="seleccion = null"
          >
            {{ $t("bandejaMensajes.volver") }}
          </button>
          <div class="bm-quien">
            <strong>{{ nombreDe(seleccion) }}</strong>
            <span class="tu-hint"
              >{{ $t(`bandejaMensajes.canal.${seleccion.canal}`)
              }}<template v-if="seleccion.telefono">
                · {{ seleccion.telefono }}</template
              ></span
            >
          </div>
          <div class="bm-acciones">
            <RouterLink
              v-if="seleccion.persona"
              class="tu-btn tu-btn-fantasma text-sm"
              :to="{
                name: 'ficha-miembro',
                params: { id: seleccion.persona.id },
              }"
            >
              {{ $t("bandejaMensajes.verFicha") }}
            </RouterLink>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="eligiendoFicha = !eligiendoFicha"
            >
              {{
                seleccion.persona
                  ? $t("bandejaMensajes.cambiarFicha")
                  : $t("bandejaMensajes.vincular")
              }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="
                seleccion.estado === 'abierta'
                  ? actualizar(
                      { estado: 'archivada' },
                      $t('bandejaMensajes.archivada'),
                    )
                  : actualizar(
                      { estado: 'abierta' },
                      $t('bandejaMensajes.reabierta'),
                    )
              "
            >
              {{
                seleccion.estado === "abierta"
                  ? $t("bandejaMensajes.archivar")
                  : $t("bandejaMensajes.reabrir")
              }}
            </button>
          </div>
          <div v-if="eligiendoFicha" class="bm-ficha">
            <BuscarPersona
              v-model="fichaElegida"
              :buscar-en="`${base}/miembros`"
              @elegir="vincular"
            />
            <button
              v-if="seleccion.persona"
              type="button"
              class="tu-enlace text-sm"
              @click="
                eligiendoFicha = false;
                actualizar(
                  { persona_id: null },
                  $t('bandejaMensajes.vinculada'),
                );
              "
            >
              {{ $t("bandejaMensajes.quitarFicha") }}
            </button>
          </div>
        </header>

        <ol ref="hilo" class="bm-hilo" aria-live="polite">
          <li v-if="seleccion.hay_anteriores" class="bm-anteriores">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="cargandoAnteriores"
              data-prueba="anteriores"
              @click="cargarAnteriores"
            >
              {{
                cargandoAnteriores
                  ? $t("bandejaMensajes.cargandoAnteriores")
                  : $t("bandejaMensajes.cargarAnteriores")
              }}
            </button>
          </li>
          <li
            v-for="m in seleccion.mensajes"
            :key="m.id"
            class="bm-burbuja"
            :data-direccion="m.direccion"
          >
            <ArchivoDeMensaje
              v-if="m.archivo"
              :base="base"
              :mensaje-id="m.id"
              :archivo="m.archivo"
            />
            <p v-if="m.texto || !m.archivo" class="bm-texto">
              {{ textoDe(m) }}
            </p>
            <span class="bm-meta"
              >{{ hora(m.enviado_en, true)
              }}<template v-if="m.enviado_por"> · {{ m.enviado_por }}</template
              ><template v-if="etiquetaEstado(m)">
                · {{ etiquetaEstado(m) }}</template
              ></span
            >
          </li>
        </ol>

        <form
          v-if="seleccion.puede_contestar"
          class="bm-redactar"
          @submit.prevent="enviar"
        >
          <textarea
            v-model="texto"
            class="tu-input"
            rows="2"
            :maxlength="
              seleccion.canal === 'instagram'
                ? 1000
                : seleccion.canal === 'messenger'
                  ? 2000
                  : 4096
            "
            :placeholder="$t('bandejaMensajes.escribir')"
            :aria-label="$t('bandejaMensajes.escribir')"
            @keydown.enter.exact.prevent="enviar"
          />
          <div class="bm-fila">
            <span class="tu-hint">{{
              $t(
                seleccion.como_agente
                  ? "bandejaMensajes.comoAgente"
                  : "bandejaMensajes.contestarHasta",
                {
                  hora: hora(seleccion.contestar_hasta, true),
                },
              )
            }}</span>
            <input
              ref="elegirArchivo"
              type="file"
              class="hidden"
              :accept="
                seleccion.canal === 'instagram'
                  ? 'image/jpeg,image/png,image/webp'
                  : 'image/jpeg,image/png,image/webp,application/pdf'
              "
              data-prueba="archivo-elegir"
              @change="adjuntar"
            />
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="enviando"
              :title="
                seleccion.canal === 'instagram'
                  ? $t('bandejaMensajes.adjuntarAyudaInstagram')
                  : $t('bandejaMensajes.adjuntarAyuda')
              "
              data-prueba="adjuntar"
              @click="elegirArchivo?.click()"
            >
              {{ $t("bandejaMensajes.adjuntar") }}
            </button>
            <button
              type="submit"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="enviando || texto.trim() === ''"
            >
              {{
                enviando
                  ? $t("bandejaMensajes.enviando")
                  : $t("bandejaMensajes.enviar")
              }}
            </button>
          </div>
        </form>
        <div v-else class="bm-fuera">
          <p class="tu-hint" data-prueba="fuera-de-plazo">
            {{ $t("bandejaMensajes.fueraDePlazo") }}
          </p>
          <template v-if="seleccion.canal === 'whatsapp'">
            <PlantillaBandeja
              v-if="conPlantilla"
              :base="base"
              :conversacion-id="seleccion.id"
              @enviada="plantillaEnviada"
            />
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              data-prueba="con-plantilla"
              @click="conPlantilla = !conPlantilla"
            >
              {{
                conPlantilla
                  ? $t("bandejaMensajes.plantillas.cerrar")
                  : $t("bandejaMensajes.plantillas.abrir")
              }}
            </button>
          </template>
        </div>
      </article>
      <div v-else class="tu-tarjeta bm-columna-hilo bm-elegir">
        <p class="tu-hint">{{ $t("bandejaMensajes.elegir") }}</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.bm-canales {
  display: grid;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.bm-subtitulo {
  font-weight: 600;
}
.bm-lista-canales {
  display: grid;
  gap: 0.5rem;
}
.bm-lista-canales li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.bm-canal-nombre {
  min-width: 6rem;
  font-weight: 500;
}
.bm-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  overflow-wrap: anywhere;
  color: var(--texto-suave);
  font-size: 0.875rem;
}
.bm-punto {
  width: 0.5rem;
  height: 0.5rem;
  flex: none;
  border-radius: 999px;
  background: var(--exito);
}
.bm-punto[data-estado="reconectar"] {
  background: var(--aviso);
}
.bm-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-left: auto;
}
.bm-cuerpo {
  display: grid;
  grid-template-columns: minmax(0, 22rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}
.bm-columna-lista,
.bm-columna-hilo {
  display: grid;
  gap: 0.75rem;
  min-width: 0;
}
.bm-filtros {
  display: grid;
  gap: 0.5rem;
}
.bm-segmentos {
  display: flex;
  gap: 0.25rem;
}
.bm-segmento {
  flex: 1;
  padding: 0.375rem 0.5rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton, 0.5rem);
  font-size: 0.875rem;
}
.bm-segmento[aria-pressed="true"] {
  border-color: var(--primario);
  color: var(--primario-fuerte, var(--primario));
  font-weight: 600;
}
.bm-conversaciones {
  display: grid;
  max-height: 65vh;
  overflow-y: auto;
}
.bm-conversacion {
  display: grid;
  width: 100%;
  gap: 0.125rem;
  padding: 0.625rem 0.5rem;
  border-bottom: 1px solid var(--borde);
  text-align: left;
}
.bm-conversacion[aria-current="true"] {
  background: var(--primario-suave, var(--superficie));
}
.bm-fila {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  min-width: 0;
}
.bm-nombre,
.bm-vista-previa {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bm-vista-previa,
.bm-cuando,
.bm-canal {
  color: var(--texto-suave);
  font-size: 0.8125rem;
}
.bm-cuando {
  flex: none;
}
.bm-sin-leer {
  flex: none;
  min-width: 1.25rem;
  padding: 0 0.375rem;
  border-radius: 999px;
  background: var(--primario);
  color: var(--primario-contraste, #fff);
  font-size: 0.75rem;
  font-weight: 600;
  text-align: center;
}
.bm-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--borde);
}
.bm-quien {
  display: grid;
  min-width: 0;
}
.bm-volver {
  display: none;
}
.bm-ficha {
  display: flex;
  flex-basis: 100%;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.bm-hilo {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-height: 55vh;
  overflow-y: auto;
  padding: 0.25rem;
}
.bm-anteriores {
  align-self: center;
}
.bm-burbuja {
  display: grid;
  max-width: min(32rem, 85%);
  gap: 0.25rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.bm-burbuja[data-direccion="saliente"] {
  align-self: flex-end;
  border-color: transparent;
  background: var(--primario-suave, var(--superficie));
}
.bm-texto {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.bm-meta {
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.bm-redactar,
.bm-fuera,
.bm-elegir-pagina {
  display: grid;
  gap: 0.5rem;
}
.bm-fuera > button {
  justify-self: start;
}
.bm-elegir {
  place-items: center;
  min-height: 12rem;
}
.bm-error {
  color: var(--error);
}
@media (max-width: 767px) {
  .bm-cuerpo {
    grid-template-columns: minmax(0, 1fr);
  }
  .bm-con-detalle .bm-columna-lista,
  .bm-cuerpo:not(.bm-con-detalle) .bm-columna-hilo {
    display: none;
  }
  .bm-volver {
    display: inline;
    flex-basis: 100%;
    text-align: left;
  }
  .bm-acciones {
    margin-left: 0;
  }
}
</style>
