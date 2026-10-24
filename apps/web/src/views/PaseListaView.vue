<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRouter } from "vue-router";

import AgregarAClase from "@/components/AgregarAClase.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import IconoNav from "@/components/IconoNav.vue";
import { asistenciaAbierta } from "@/lib/agenda";
import { useRecargarAlVolver } from "@/lib/alVolver";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  confirmarAsistencia,
  leerEfectoInasistencia,
  textoInasistencia,
} from "@/lib/confirmarAsistencia";
import {
  colorEstado,
  estadoEnLista,
  filtrarLista,
  resumenLista,
  type EstadoEnLista,
  type FiltroLista,
  type ReservaLista,
} from "@/lib/paseLista";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Pase de lista de una clase, en su propia pantalla (ADR 0101): quién reservó, en
 * orden alfabético y fijo; un toque por persona (Llegó, Tarde, No vino) que se ve al
 * momento sin recargar la lista; filtros y búsqueda; agregar a quien llegó sin
 * reservar; la lista de espera; y «Terminar lista» siempre a la mano abajo. Pensada
 * para la tableta en la puerta o el teléfono de quien imparte.
 */
interface ClaseLista {
  id: string;
  oferta: string | null;
  instructor: string | null;
  sala: string | null;
  sucursal: string | null;
  inicia_en: string;
  termina_en: string;
  zona_horaria: string;
  capacidad: number | null;
  estado: string;
}

const props = defineProps<{ id: string }>();

const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const lista = ref<ReservaLista[]>([]);
const clase = ref<ClaseLista | null>(null);
const desde = ref<string | null>(null);
const empezo = ref(false);
const cargando = ref(true);
const error = ref<string | null>(null);
const errorAccion = ref<string | null>(null);
// Reservas con un registro en camino (su botón se deshabilita; el resto no).
const marcando = ref(new Set<string>());
const ocupado = ref(false);
// Reserva cuyo registro se está corrigiendo (muestra otra vez los tres botones).
const corrigiendo = ref<string | null>(null);
const filtro = ref<FiltroLista>("todos");
const busqueda = ref("");
const agregando = ref(false);

// La hora avanza sola: la lista se abre y la clase empieza sin recargar.
const ahora = ref(Date.now());

const cancelada = computed(() => clase.value?.estado === "cancelada");
const puedeMarcar = computed(
  () => sesion.puede("asistencia.marcar") && !cancelada.value,
);
const puedeGestionar = computed(
  () => sesion.puede("reservas.gestionar") && !cancelada.value,
);
const abierta = computed(() =>
  asistenciaAbierta(desde.value, new Date(ahora.value)),
);
const yaEmpezo = computed(
  () =>
    empezo.value ||
    (clase.value !== null &&
      ahora.value >= new Date(clase.value.inicia_en).getTime()),
);
const resumen = computed(() =>
  resumenLista(lista.value, clase.value?.capacidad ?? null),
);
const visibles = computed(() =>
  filtrarLista(lista.value, filtro.value, busqueda.value),
);
const enEspera = computed(() =>
  lista.value.filter((r) => r.estado === "en_espera"),
);
const FILTROS: FiltroLista[] = [
  "todos",
  "por_marcar",
  "llegaron",
  "no_vinieron",
];
const cuantos = computed(
  () =>
    Object.fromEntries(
      FILTROS.map((f) => [f, filtrarLista(lista.value, f).length]),
    ) as Record<FiltroLista, number>,
);
// Avance de la lista: cuántos ya tienen registro (llegaron o no vinieron).
const avance = computed(() =>
  resumen.value.enSala === 0
    ? 0
    : Math.round(
        ((resumen.value.llegaron + resumen.value.noVinieron) /
          resumen.value.enSala) *
          100,
      ),
);

function formatoHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: clase.value?.zona_horaria,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
const subtitulo = computed(() => {
  const c = clase.value;
  if (!c) {
    return "";
  }
  const dia = new Intl.DateTimeFormat("es-MX", {
    timeZone: c.zona_horaria,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(c.inicia_en));
  return [
    dia.charAt(0).toUpperCase() + dia.slice(1),
    `${formatoHora(c.inicia_en)}–${formatoHora(c.termina_en)}`,
    c.instructor,
    [c.sucursal, c.sala].filter(Boolean).join(" · "),
  ]
    .filter(Boolean)
    .join(" · ");
});

// Cómo va la lista, en una línea con su punto de color.
const estadoGeneral = computed<{ texto: string; color: string }>(() => {
  const c = clase.value;
  if (!c) {
    return { texto: "", color: "var(--texto-suave)" };
  }
  if (cancelada.value) {
    return { texto: t("paseLista.estado.cancelada"), color: "var(--error)" };
  }
  if (!abierta.value) {
    return {
      texto: t("paseLista.estado.abre", { hora: formatoHora(desde.value!) }),
      color: "var(--texto-suave)",
    };
  }
  if (resumen.value.enSala === 0) {
    return { texto: t("paseLista.estado.vacia"), color: "var(--texto-suave)" };
  }
  if (resumen.value.porMarcar === 0) {
    return { texto: t("paseLista.estado.completa"), color: "var(--exito)" };
  }
  if (yaEmpezo.value) {
    const n = resumen.value.porMarcar;
    return {
      texto: t("paseLista.estado.enCurso", { n }, n),
      color: "var(--aviso)",
    };
  }
  return {
    texto: t("paseLista.estado.abierta", { hora: formatoHora(c.inicia_en) }),
    color: "var(--exito)",
  };
});

/** Lo que conviene saber de cada quien, en una línea: sin píldoras. */
function avisos(
  r: ReservaLista,
): { texto: string; color: string; ayuda?: string }[] {
  const out: { texto: string; color: string; ayuda?: string }[] = [];
  if (r.adeudo) {
    out.push({ texto: t("agenda.roster.adeudo"), color: "var(--error)" });
  }
  if (r.documentos_pendientes > 0) {
    out.push({
      texto: t("agenda.roster.documentos"),
      color: "var(--aviso)",
      ayuda: t("agenda.roster.documentosAyuda"),
    });
  }
  if (r.primera_vez) {
    out.push({
      texto: t("agenda.roster.primeraVez"),
      color: "var(--aviso)",
      ayuda: t("agenda.roster.primeraVezAyuda"),
    });
  }
  if (r.lugar) {
    out.push({
      texto: t("agenda.lugares.lugarN", { n: r.lugar }),
      color: "var(--texto-suave)",
    });
  }
  return out;
}

function textoEstado(r: ReservaLista, e: EstadoEnLista): string {
  if (e === "sin_confirmar") {
    return t(
      r.estado === "ofrecida"
        ? "paseLista.estados.ofrecida"
        : "paseLista.estados.pendiente_pago",
    );
  }
  return t(`paseLista.estados.${e}`);
}

// Se marca solo a quien tiene su lugar confirmado; el resto, su estado.
function conBotones(r: ReservaLista): boolean {
  return (
    puedeMarcar.value &&
    r.estado === "confirmada" &&
    (!r.asistencia || corrigiendo.value === r.id)
  );
}

// Invitados de los clientes (ADR 0182): ocupan lugar; se marca si llegaron.
interface InvitadoLista {
  id: string;
  nombre: string;
  anfitrion: string | null;
  estado: "reservado" | "asistio" | "no_asistio";
}
const invitados = ref<InvitadoLista[]>([]);
// Quien reservó desde la app de Wellhub, TotalPass o Fitpass (ADR 0202): visitantes de
// la plataforma, no clientes; TotalPass valida la llegada sola en su app.
interface LugarPlataformaLista {
  id: string;
  proveedor: "wellhub" | "totalpass" | "fitpass";
  nombre: string | null;
  estado: "reservado" | "asistio" | "no_asistio";
  puede_marcar: boolean;
}
const plataforma = ref<LugarPlataformaLista[]>([]);
// Fitpass no tiene API: recepción captura aquí sus reservas desde Fitpass Admin.
const fitpassActivo = ref(false);
const capturandoFitpass = ref(false);
const textoFitpass = ref("");
const erroresFitpass = ref<string[]>([]);
/**
 * Un renglón por reserva: «ID, nombre, correo» (también separados por tabulador o
 * punto y coma, como al copiar la tabla de Fitpass Admin).
 */
function reservasFitpass(): { id: string; nombre: string; correo?: string }[] {
  return textoFitpass.value
    .split(/\r?\n/)
    .map((linea) => linea.split(/[\t;,]/).map((c) => c.trim()))
    .filter((partes) => (partes[0] ?? "") !== "")
    .map(([id = "", nombre = "", correo = ""]) => ({
      id,
      nombre,
      ...(correo.includes("@") ? { correo } : {}),
    }));
}
async function capturarFitpass(): Promise<void> {
  const reservas = reservasFitpass();
  if (reservas.length === 0) {
    return;
  }
  ocupado.value = true;
  erroresFitpass.value = [];
  try {
    const { data } = await api.post<{
      data: { id: string; error: string | null }[];
    }>(`${base.value}/sesiones/${props.id}/lugares-plataforma`, { reservas });
    erroresFitpass.value = data.data
      .filter((r) => r.error !== null)
      .map((r) => `${r.id}: ${r.error}`);
    if (erroresFitpass.value.length === 0) {
      textoFitpass.value = "";
      capturandoFitpass.value = false;
    }
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
async function llegoDePlataforma(l: LugarPlataformaLista): Promise<void> {
  // Fitpass pide revisar la identificación oficial de quien llega.
  if (
    l.proveedor === "fitpass" &&
    !(await confirmar(t("paseLista.plataforma.identificacion"), {
      aceptar: t("paseLista.plataforma.identificacionSi"),
    }))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    await api.post(
      `${base.value}/lugares-plataforma/${l.id}/llego`,
      l.proveedor === "fitpass" ? { identificacion_revisada: true } : {},
    );
    l.estado = "asistio";
    l.puede_marcar = false;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
async function cancelarDePlataforma(l: LugarPlataformaLista): Promise<void> {
  if (
    !(await confirmar(t("paseLista.plataforma.cancelarConfirmar"), {
      peligro: true,
      aceptar: t("paseLista.plataforma.cancelar"),
    }))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    await api.post(`${base.value}/lugares-plataforma/${l.id}/cancelar`, {});
    plataforma.value = plataforma.value.filter((p) => p.id !== l.id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
// Lo que los clientes apartaron para la clase (ADR 0194), por persona, para cobrarlo
// en el mostrador de la sede.
interface ApartadoLista {
  id: string;
  articulo: { id: string | null; nombre: string | null };
  cantidad: number;
  total_minor: number;
  moneda: string;
  persona: { id: string | null; nombre: string | null };
}
const apartados = ref<ApartadoLista[]>([]);
const sedeApartados = ref<string | null>(null);
const apartadosPorPersona = computed(() => {
  const grupos = new Map<string, ApartadoLista[]>();
  for (const a of apartados.value) {
    const clave = a.persona.id ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), a]);
  }
  return [...grupos.values()];
});
function aMostrador(grupo: ApartadoLista[]) {
  const primero = grupo[0]!;
  return {
    name: "pos",
    query: {
      apartados: grupo.map((a) => a.id).join(","),
      articulos: grupo.map((a) => `${a.articulo.id}:${a.cantidad}`).join(","),
      comprador: `persona:${primero.persona.id}`,
      nombre: primero.persona.nombre ?? "",
      ...(sedeApartados.value ? { sucursal: sedeApartados.value } : {}),
    },
  };
}
async function cargarApartados(): Promise<void> {
  if (sesion.esCitas || !sesion.puede("pos.vender")) {
    return;
  }
  try {
    const { data } = await api.get<{
      data: ApartadoLista[];
      meta?: { sucursal_id?: string | null };
    }>(`${base.value}/sesiones/${props.id}/apartados`);
    apartados.value = data.data;
    sedeApartados.value = data.meta?.sucursal_id ?? null;
  } catch {
    apartados.value = [];
  }
}
async function marcarInvitado(
  i: InvitadoLista,
  asistio: boolean,
): Promise<void> {
  ocupado.value = true;
  try {
    await api.put(`${base.value}/invitados/${i.id}/asistencia`, { asistio });
    i.estado = asistio ? "asistio" : "no_asistio";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function cargar(silencioso = false): Promise<void> {
  if (!silencioso) {
    cargando.value = true;
    error.value = null;
  }
  try {
    const { data } = await api.get<{
      data: ReservaLista[];
      meta?: {
        asistencia_desde?: string;
        empezo?: boolean;
        sesion?: ClaseLista;
        invitados?: InvitadoLista[];
        plataforma?: LugarPlataformaLista[];
        fitpass?: boolean;
      };
    }>(`${base.value}/sesiones/${props.id}/reservas`);
    lista.value = data.data;
    invitados.value = data.meta?.invitados ?? [];
    plataforma.value = data.meta?.plataforma ?? [];
    fitpassActivo.value = data.meta?.fitpass ?? false;
    void cargarApartados();
    desde.value = data.meta?.asistencia_desde ?? null;
    empezo.value = data.meta?.empezo ?? false;
    clase.value = data.meta?.sesion ?? null;
    error.value = null;
  } catch (e) {
    // Una actualización en segundo plano que falla no borra lo que ya se ve.
    if (!silencioso) {
      error.value = mensajeDeError(e);
    }
  } finally {
    cargando.value = false;
  }
}

async function marcar(
  r: ReservaLista,
  estado: "presente" | "ausente",
  retardo = false,
): Promise<void> {
  // Solo cambiar si llegó tarde no mueve créditos: no hace falta confirmarlo.
  const soloRetardo = r.asistencia === "presente" && estado === "presente";
  // «No vino» dice qué pasa con su crédito según su política (no un «puede»).
  const efecto =
    estado === "ausente" && !r.asistencia
      ? textoInasistencia(t, await leerEfectoInasistencia(base.value, r.id))
      : null;
  if (
    !soloRetardo &&
    !(await confirmarAsistencia(
      t,
      r.persona ?? "",
      r.asistencia,
      estado,
      efecto,
    ))
  ) {
    return;
  }
  marcando.value.add(r.id);
  errorAccion.value = null;
  try {
    const { data } = await api.post<{
      data: { estado: string; retardo: boolean };
    }>(`${base.value}/reservas/${r.id}/asistencia`, { estado, retardo });
    // Se ve al momento, sin recargar ni mover a nadie de su lugar en la lista.
    r.asistencia = data.data.estado;
    r.retardo = data.data.retardo;
    r.asistencia_automatica = false;
    if (corrigiendo.value === r.id) {
      corrigiendo.value = null;
    }
  } catch (e) {
    errorAccion.value = `${r.persona ?? "—"}: ${mensajeDeError(e)}`;
  } finally {
    marcando.value.delete(r.id);
  }
}

// Al terminar, quien sigue sin registro «no vino» (con la política del negocio).
async function terminarLista(): Promise<void> {
  if (
    !(await confirmar(
      t("recepcion.panel.terminarListaConfirmar", {
        n: resumen.value.porMarcar,
      }),
      { aceptar: t("recepcion.panel.terminarLista"), peligro: true },
    ))
  ) {
    return;
  }
  await accion(async () => {
    const { data } = await api.post<{ data: { no_se_presentaron: number } }>(
      `${base.value}/sesiones/${props.id}/terminar-lista`,
      {},
    );
    const n = data.data.no_se_presentaron;
    toast.exito(t("recepcion.panel.listaTerminada", n));
  });
}

// Aceptar a nombre de la persona confirma su lugar y usa su plan: se confirma.
async function aceptar(r: ReservaLista): Promise<void> {
  if (
    !(await confirmar(
      t("confirmaciones.aceptarLugar", { persona: r.persona ?? "" }),
      { aceptar: t("confirmaciones.aceptarLugarAceptar") },
    ))
  ) {
    return;
  }
  await accion(() => api.post(`${base.value}/reservas/${r.id}/aceptar`, {}));
}

async function promover(): Promise<void> {
  if (
    !(await confirmar(t("confirmaciones.promover"), {
      aceptar: t("confirmaciones.promoverAceptar"),
    }))
  ) {
    return;
  }
  await accion(async () => {
    const { data } = await api.post<{ data: { ofrecidas: number } }>(
      `${base.value}/sesiones/${props.id}/promover`,
      {},
    );
    const n = data.data.ofrecidas;
    toast.exito(
      n > 0
        ? t("oportunidades.ofrecidas", { n }, n)
        : t("oportunidades.sinPromover"),
    );
  });
}

async function accion(fn: () => Promise<unknown>): Promise<void> {
  ocupado.value = true;
  errorAccion.value = null;
  try {
    await fn();
    await cargar(true);
  } catch (e) {
    errorAccion.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function agregado(nombre: string, aEspera: boolean): Promise<void> {
  agregando.value = false;
  toast.exito(
    t(
      aEspera
        ? "paseLista.agregar.agregadoEspera"
        : "paseLista.agregar.agregado",
      { nombre },
    ),
  );
  await cargar(true);
}

// Volver a donde venía (Recepción, Agenda, Mis clases); sin historial, a su inicio.
function volver(): void {
  const previa = (window.history.state as { back?: unknown } | null)?.back;
  if (typeof previa === "string" && previa !== "") {
    router.back();
    return;
  }
  void router.push({ name: sesion.rutaInicio });
}

// Cada 30 s avanza la hora y, con la pantalla a la vista, se trae lo nuevo (otra
// persona del equipo pudo marcar o agregar a alguien desde otro dispositivo).
let reloj: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  reloj = setInterval(() => {
    ahora.value = Date.now();
    if (
      document.visibilityState === "visible" &&
      marcando.value.size === 0 &&
      !ocupado.value
    ) {
      void cargar(true);
    }
  }, 30_000);
});
onBeforeUnmount(() => clearInterval(reloj));
useRecargarAlVolver(() => cargar(true));

watch(
  () => props.id,
  () => {
    corrigiendo.value = null;
    agregando.value = false;
    void cargar();
  },
  { immediate: true },
);
</script>

<template>
  <section class="tu-pagina pl" data-prueba="pase-lista">
    <div class="pl-barra">
      <button type="button" class="tu-volver" @click="volver">
        <IconoNav nombre="atras" :tam="16" />
        {{ $t("paseLista.volver") }}
      </button>
    </div>

    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div v-else-if="error" class="mt-6 tu-card p-5">
      <p class="text-sm" role="alert" :style="{ color: 'var(--error)' }">
        {{ error }}
      </p>
      <button
        type="button"
        class="tu-btn tu-btn-secundario mt-3"
        @click="cargar()"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else-if="clase">
      <header class="pl-encabezado">
        <p class="pl-etiqueta">{{ $t("paseLista.titulo") }}</p>
        <h1 class="pl-titulo">{{ clase.oferta ?? "—" }}</h1>
        <p class="pl-subtitulo">{{ subtitulo }}</p>
        <p
          class="pl-estado pl-estado-general"
          :style="{ '--punto': estadoGeneral.color }"
          data-prueba="estado-lista"
        >
          {{ estadoGeneral.texto }}
        </p>
      </header>

      <!-- Cómo va: cuántos llegaron, cuántos faltan y si queda lugar -->
      <div class="pl-resumen tu-card" data-prueba="resumen-lista">
        <div class="pl-avance">
          <p>
            <strong class="tabular-nums">{{ resumen.llegaron }}</strong>
            <span>{{
              $t("paseLista.resumen.llegaron", { total: resumen.enSala })
            }}</span>
          </p>
          <div
            class="pl-barra-avance"
            role="progressbar"
            :aria-valuenow="avance"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="$t('paseLista.resumen.avance')"
          >
            <span :style="{ width: `${avance}%` }" />
          </div>
        </div>
        <dl class="pl-cifras">
          <div>
            <dt>{{ $t("paseLista.filtros.por_marcar") }}</dt>
            <dd class="tabular-nums">{{ resumen.porMarcar }}</dd>
          </div>
          <div>
            <dt>{{ $t("paseLista.filtros.no_vinieron") }}</dt>
            <dd class="tabular-nums">{{ resumen.noVinieron }}</dd>
          </div>
          <div v-if="resumen.libres !== null">
            <dt>{{ $t("paseLista.resumen.libres") }}</dt>
            <dd class="tabular-nums">
              {{
                resumen.libres === 0
                  ? $t("paseLista.resumen.llena")
                  : resumen.libres
              }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- Buscar, filtrar y agregar a quien llegó sin reservar -->
      <div class="pl-herramientas">
        <label class="tu-campo-icono pl-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="busqueda"
            type="search"
            class="tu-input"
            :placeholder="$t('paseLista.buscar')"
            :aria-label="$t('paseLista.buscar')"
          />
        </label>
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('paseLista.filtrar')"
        >
          <button
            v-for="f in FILTROS"
            :key="f"
            type="button"
            :aria-pressed="filtro === f"
            :data-prueba="`filtro-${f}`"
            @click="filtro = f"
          >
            {{ $t(`paseLista.filtros.${f}`) }}
            <span class="pl-cuenta tabular-nums">{{ cuantos[f] }}</span>
          </button>
        </div>
        <button
          v-if="puedeGestionar && !agregando"
          type="button"
          class="tu-btn tu-btn-secundario pl-agregar"
          data-prueba="abrir-agregar"
          @click="agregando = true"
        >
          <IconoNav nombre="mas" :tam="16" />
          <span class="pl-largo">{{ $t("paseLista.agregar.boton") }}</span>
          <span class="pl-corto">{{ $t("paseLista.agregar.corto") }}</span>
        </button>
      </div>
      <AgregarAClase
        v-if="agregando && puedeGestionar"
        class="mt-3"
        :base="base"
        :sesion-id="clase.id"
        :llena="resumen.libres === 0"
        @agregado="agregado"
        @cerrar="agregando = false"
      />

      <AvisoTono v-if="errorAccion" tono="peligro" class="mt-3" role="alert">
        {{ errorAccion }}
      </AvisoTono>

      <!-- La lista: en orden alfabético; marcar a alguien no lo mueve de lugar -->
      <ul
        v-if="visibles.length > 0"
        class="pl-lista tu-card"
        aria-live="polite"
      >
        <li
          v-for="r in visibles"
          :key="r.id"
          class="pl-fila"
          :data-prueba="`fila-${r.id}`"
        >
          <div class="pl-persona">
            <AvatarIniciales :nombre="r.persona" tam="md" />
            <div class="min-w-0">
              <RouterLink
                v-if="r.persona_id && sesion.puede('miembros.ver')"
                :to="{ name: 'ficha-miembro', params: { id: r.persona_id } }"
                class="pl-nombre hover:underline"
                >{{ r.persona ?? "—" }}</RouterLink
              >
              <span v-else class="pl-nombre">{{ r.persona ?? "—" }}</span>
              <p v-if="avisos(r).length > 0" class="pl-avisos">
                <span
                  v-for="(a, i) in avisos(r)"
                  :key="a.texto"
                  :style="{ color: a.color }"
                  :title="a.ayuda"
                  >{{ i > 0 ? " · " : "" }}{{ a.texto }}</span
                >
              </p>
            </div>
          </div>

          <div v-if="conBotones(r)" class="pl-botones">
            <!-- Los tres iguales; el elegido se resalta (aria-pressed). -->
            <button
              type="button"
              class="tu-btn tu-btn-secundario pl-boton"
              :aria-pressed="r.asistencia === 'presente' && !r.retardo"
              :aria-label="`${$t('paseLista.acciones.llego')}: ${r.persona ?? ''}`"
              :disabled="!abierta || marcando.has(r.id)"
              data-prueba="llego"
              @click="marcar(r, 'presente')"
            >
              <IconoNav nombre="hecho" :tam="18" />
              {{ $t("paseLista.acciones.llego") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-secundario pl-boton"
              :aria-pressed="r.asistencia === 'presente' && !!r.retardo"
              :aria-label="`${$t('paseLista.acciones.tarde')}: ${r.persona ?? ''}`"
              :disabled="!abierta || marcando.has(r.id)"
              data-prueba="tarde"
              @click="marcar(r, 'presente', true)"
            >
              <IconoNav nombre="reloj" :tam="18" />
              {{ $t("paseLista.acciones.tarde") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-secundario pl-boton"
              :aria-pressed="r.asistencia === 'ausente'"
              :aria-label="`${$t('paseLista.acciones.noVino')}: ${r.persona ?? ''}`"
              :disabled="!abierta || marcando.has(r.id)"
              data-prueba="no-vino"
              @click="marcar(r, 'ausente')"
            >
              <IconoNav nombre="ausente" :tam="18" />
              {{ $t("paseLista.acciones.noVino") }}
            </button>
            <button
              v-if="corrigiendo === r.id"
              type="button"
              class="tu-btn tu-btn-discreto tu-btn-chico pl-dejar"
              @click="corrigiendo = null"
            >
              {{ $t("paseLista.acciones.dejar") }}
            </button>
          </div>
          <div v-else class="pl-registro">
            <span
              class="pl-estado"
              :style="{ '--punto': colorEstado(estadoEnLista(r)) }"
              data-prueba="estado"
              >{{ textoEstado(r, estadoEnLista(r)) }}</span
            >
            <button
              v-if="puedeMarcar && r.estado === 'confirmada' && r.asistencia"
              type="button"
              class="tu-btn tu-btn-discreto tu-btn-chico"
              :aria-label="`${$t('paseLista.acciones.cambiar')}: ${r.persona ?? ''}`"
              data-prueba="cambiar"
              @click="corrigiendo = r.id"
            >
              {{ $t("paseLista.acciones.cambiar") }}
            </button>
            <button
              v-if="r.estado === 'ofrecida' && puedeGestionar"
              type="button"
              class="tu-btn tu-btn-secundario"
              :disabled="ocupado"
              @click="aceptar(r)"
            >
              {{ $t("paseLista.acciones.aceptar") }}
            </button>
          </div>
        </li>
      </ul>
      <p
        v-else
        class="pl-vacio tu-card"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{
          resumen.enSala === 0
            ? $t("paseLista.estado.vacia")
            : busqueda.trim() !== ""
              ? $t("paseLista.sinCoincidencias")
              : $t("paseLista.nadieEn", {
                  filtro: $t(`paseLista.filtros.${filtro}`),
                })
        }}
      </p>

      <!-- Invitados de los clientes (ADR 0182): no son clientes, ocupan lugar -->
      <section
        v-if="invitados.length > 0"
        class="pl-espera tu-card"
        data-prueba="invitados-clase"
      >
        <header>
          <div>
            <h2>
              {{ $t("paseLista.invitados.titulo") }}
              <span class="tabular-nums">{{ invitados.length }}</span>
            </h2>
            <p>{{ $t("paseLista.invitados.ayuda") }}</p>
          </div>
        </header>
        <ul>
          <li v-for="i in invitados" :key="i.id">
            <AvatarIniciales :nombre="i.nombre" tam="sm" />
            <span class="min-w-0 flex-1 truncate">
              {{ i.nombre }}
              <span
                v-if="i.anfitrion"
                class="text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >·
                {{
                  $t("paseLista.invitados.de", { nombre: i.anfitrion })
                }}</span
              >
            </span>
            <span class="text-xs">{{
              $t(`paseLista.invitados.estados.${i.estado}`)
            }}</span>
            <template v-if="puedeMarcar">
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="ocupado || i.estado === 'asistio'"
                @click="marcarInvitado(i, true)"
              >
                {{ $t("paseLista.acciones.llego") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="ocupado || i.estado === 'no_asistio'"
                @click="marcarInvitado(i, false)"
              >
                {{ $t("paseLista.acciones.noVino") }}
              </button>
            </template>
          </li>
        </ul>
      </section>

      <!-- Reservas desde la app de una plataforma (ADR 0202) -->
      <section
        v-if="
          plataforma.length > 0 ||
          (fitpassActivo && sesion.puede('reservas.gestionar'))
        "
        class="pl-espera tu-card"
        data-prueba="plataforma-clase"
      >
        <header>
          <div>
            <h2>
              {{ $t("paseLista.plataforma.titulo") }}
              <span class="tabular-nums">{{ plataforma.length }}</span>
            </h2>
            <p>{{ $t("paseLista.plataforma.ayuda") }}</p>
          </div>
          <button
            v-if="fitpassActivo && sesion.puede('reservas.gestionar')"
            type="button"
            class="tu-btn tu-btn-secundario"
            data-prueba="capturar-fitpass"
            @click="capturandoFitpass = !capturandoFitpass"
          >
            {{ $t("paseLista.plataforma.capturarFitpass") }}
          </button>
        </header>
        <form
          v-if="capturandoFitpass"
          class="mb-3 space-y-2"
          @submit.prevent="capturarFitpass"
        >
          <label class="tu-label" for="reservas-fitpass">{{
            $t("paseLista.plataforma.reservasFitpass")
          }}</label>
          <textarea
            id="reservas-fitpass"
            v-model="textoFitpass"
            class="tu-input"
            rows="3"
            :placeholder="$t('paseLista.plataforma.reservasFitpassEjemplo')"
          />
          <p class="tu-hint">
            {{ $t("paseLista.plataforma.reservasFitpassAyuda") }}
          </p>
          <ul
            v-if="erroresFitpass.length > 0"
            class="text-sm"
            :style="{ color: 'var(--peligro)' }"
            role="alert"
          >
            <li v-for="e in erroresFitpass" :key="e">{{ e }}</li>
          </ul>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="ocupado || textoFitpass.trim() === ''"
          >
            {{ $t("paseLista.plataforma.guardarFitpass") }}
          </button>
        </form>
        <ul>
          <li v-for="l in plataforma" :key="l.id">
            <AvatarIniciales :nombre="l.nombre ?? ''" tam="sm" />
            <span class="min-w-0 flex-1 truncate">
              {{ l.nombre ?? $t("paseLista.plataforma.sinNombre") }}
              <span class="text-xs" :style="{ color: 'var(--texto-suave)' }"
                >·
                {{
                  $t(`paseLista.plataforma.proveedores.${l.proveedor}`)
                }}</span
              >
            </span>
            <span class="text-xs">{{
              $t(`paseLista.invitados.estados.${l.estado}`)
            }}</span>
            <template v-if="puedeMarcar && l.estado === 'reservado'">
              <button
                v-if="l.puede_marcar"
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="ocupado"
                @click="llegoDePlataforma(l)"
              >
                {{ $t("paseLista.acciones.llego") }}
              </button>
              <span v-else class="tu-hint">{{
                $t("paseLista.plataforma.enSuApp")
              }}</span>
              <button
                v-if="sesion.puede('reservas.gestionar')"
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="ocupado"
                @click="cancelarDePlataforma(l)"
              >
                {{ $t("paseLista.plataforma.cancelar") }}
              </button>
            </template>
          </li>
        </ul>
      </section>

      <!-- Apartados para recoger (ADR 0194): se cobran en el mostrador -->
      <section
        v-if="apartados.length > 0"
        class="pl-espera tu-card"
        data-prueba="apartados-clase"
      >
        <header>
          <div>
            <h2>
              {{ $t("apartados.lista.titulo") }}
              <span class="tabular-nums">{{ apartadosPorPersona.length }}</span>
            </h2>
            <p>{{ $t("apartados.lista.ayuda") }}</p>
          </div>
        </header>
        <ul>
          <li v-for="grupo in apartadosPorPersona" :key="grupo[0]!.id">
            <AvatarIniciales
              :nombre="grupo[0]!.persona.nombre ?? ''"
              tam="sm"
            />
            <span class="min-w-0 flex-1">
              {{ grupo[0]!.persona.nombre }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{
                  grupo
                    .map((a) => `${a.cantidad} × ${a.articulo.nombre}`)
                    .join(" · ")
                }}</span
              >
            </span>
            <RouterLink
              :to="aMostrador(grupo)"
              class="tu-btn tu-btn-secundario"
              data-prueba="cobrar-apartados"
            >
              {{ $t("apartados.lista.cobrar") }}
            </RouterLink>
          </li>
        </ul>
      </section>

      <!-- Lista de espera: en su orden; al liberarse un lugar se ofrece así -->
      <section v-if="enEspera.length > 0" class="pl-espera tu-card">
        <header>
          <div>
            <h2>
              {{ $t("recepcion.panel.listaEspera") }}
              <span class="tabular-nums">{{ enEspera.length }}</span>
            </h2>
            <p>{{ $t("paseLista.espera.ayuda") }}</p>
          </div>
          <button
            v-if="puedeGestionar && (resumen.libres ?? 0) > 0"
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="ocupado"
            @click="promover"
          >
            {{ $t("recepcion.panel.promover") }}
          </button>
        </header>
        <ol>
          <li v-for="r in enEspera" :key="r.id">
            <AvatarIniciales :nombre="r.persona" tam="sm" />
            <span class="truncate">{{ r.persona ?? "—" }}</span>
          </li>
        </ol>
      </section>

      <!-- Terminar la lista: a la mano, abajo, cuando ya empezó y falta alguien -->
      <div
        v-if="puedeMarcar && yaEmpezo && resumen.porMarcar > 0"
        class="pl-pie"
        data-prueba="pie-lista"
      >
        <p class="text-sm">
          {{
            $t(
              "paseLista.terminar.ayuda",
              { n: resumen.porMarcar },
              resumen.porMarcar,
            )
          }}
        </p>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="ocupado"
          data-prueba="terminar-lista"
          @click="terminarLista"
        >
          {{ $t("recepcion.panel.terminarLista") }}
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.pl {
  max-width: 60rem;
  margin-inline: auto;
}
.pl-barra {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.pl-encabezado {
  margin-top: 0.25rem;
}
.pl-etiqueta {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.pl-titulo {
  margin-top: 0.15rem;
  font-size: 1.6rem;
  font-weight: 600;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.pl-subtitulo {
  margin-top: 0.3rem;
  font-size: 0.9rem;
  color: var(--texto-suave);
}
/* Estados: punto de color + texto (sin caja). */
.pl-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.875rem;
  font-weight: 500;
}
.pl-estado::before {
  content: "";
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  flex-shrink: 0;
  background: var(--punto);
}
.pl-estado-general {
  margin-top: 0.6rem;
}

.pl-resumen {
  display: grid;
  gap: 1rem;
  margin-top: 1.25rem;
  padding: 1rem 1.1rem;
}
@media (min-width: 640px) {
  .pl-resumen {
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    align-items: center;
  }
}
.pl-avance p {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
}
.pl-avance strong {
  font-size: clamp(1.5rem, 5vw, 1.9rem);
  font-weight: 600;
  line-height: 1;
}
.pl-avance span {
  color: var(--texto-suave);
  font-size: 0.9rem;
}
.pl-barra-avance {
  height: 0.5rem;
  margin-top: 0.7rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--superficie-2);
}
.pl-barra-avance > span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--exito);
  transition: width 0.25s ease;
}
.pl-cifras {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}
.pl-cifras > div {
  padding-left: 0.75rem;
  border-left: 1px solid var(--borde);
}
.pl-cifras dt {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.pl-cifras dd {
  margin-top: 0.15rem;
  font-size: 1.15rem;
  font-weight: 600;
}

.pl-herramientas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
  margin-top: 1.25rem;
}
.pl-buscar {
  flex: 1 1 12rem;
  min-width: 0;
}
.pl-corto {
  display: none;
}
/* En el teléfono: buscar y agregar en una fila; los filtros, debajo a lo ancho. */
@media (max-width: 719px) {
  .pl-buscar {
    flex-basis: 8rem;
  }
  .pl-herramientas .tu-segmentado {
    order: 3;
    flex-basis: 100%;
  }
  .pl-largo {
    display: none;
  }
  .pl-corto {
    display: inline;
  }
}
.pl-cuenta {
  margin-left: 0.3rem;
  color: var(--texto-suave);
  font-weight: 400;
}
.pl-agregar {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.pl-lista {
  margin-top: 0.9rem;
  padding: 0;
  overflow: hidden;
}
.pl-fila {
  display: grid;
  gap: 0.7rem;
  padding: 0.85rem 1rem;
}
.pl-fila + .pl-fila {
  border-top: 1px solid var(--borde);
}
@media (min-width: 720px) {
  .pl-fila {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
  }
}
.pl-persona {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}
.pl-nombre {
  display: block;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.pl-avisos {
  margin-top: 0.1rem;
  font-size: 0.8rem;
}
/* Tres botones grandes: en el teléfono, a lo ancho y del mismo tamaño. */
.pl-botones {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.4rem;
}
@media (min-width: 720px) {
  .pl-botones {
    display: flex;
    align-items: center;
  }
}
.pl-boton {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 44px;
  padding-inline: 0.8rem;
  font-size: 0.9rem;
  white-space: nowrap;
}
/* En un teléfono angosto, solo el texto: que «No vino» quepa en una línea. */
@media (max-width: 420px) {
  .pl-boton {
    padding-inline: 0.4rem;
  }
  .pl-boton :deep(svg) {
    display: none;
  }
}
/* El estado elegido resalta: borde y fondo suave del color de acción. */
.pl-boton[aria-pressed="true"] {
  border-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario-fuerte);
  font-weight: 600;
}
.pl-dejar {
  grid-column: 1 / -1;
  justify-self: start;
}
.pl-registro {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 1rem;
  min-height: 44px;
}
@media (min-width: 720px) {
  .pl-registro {
    justify-content: flex-end;
  }
}
.pl-vacio {
  margin-top: 0.9rem;
  padding: 1.25rem 1rem;
  font-size: 0.9rem;
}

.pl-espera {
  margin-top: 1rem;
  padding: 1rem 1.1rem;
}
.pl-espera header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
}
.pl-espera h2 {
  font-weight: 500;
}
.pl-espera h2 span {
  margin-left: 0.3rem;
  color: var(--texto-suave);
  font-weight: 400;
}
.pl-espera header p {
  margin-top: 0.1rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.pl-espera ol {
  display: grid;
  gap: 0.5rem;
  margin-top: 0.8rem;
  counter-reset: espera;
}
.pl-espera li {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
  font-size: 0.9rem;
}
.pl-espera li::before {
  counter-increment: espera;
  content: counter(espera);
  width: 1.2rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  text-align: right;
}

/* Siempre a la vista al bajar por la lista. */
.pl-pie {
  position: sticky;
  bottom: 0;
  z-index: 5;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem 1rem;
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta) var(--radio-tarjeta) 0 0;
  background: var(--superficie);
  box-shadow: 0 -4px 12px rgb(16 24 40 / 6%);
}
.pl-pie p {
  flex: 1 1 14rem;
  color: var(--texto-suave);
}
.pl-pie .tu-btn {
  white-space: nowrap;
}
@media (max-width: 480px) {
  .pl-pie {
    flex-wrap: nowrap;
    padding: 0.6rem 0.75rem;
  }
  .pl-pie p {
    font-size: 0.8rem;
  }
}
</style>
