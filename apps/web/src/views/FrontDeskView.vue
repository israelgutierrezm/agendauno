<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EscanerPase from "@/components/EscanerPase.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelClase from "@/components/PanelClase.vue";
import RecepcionCitas, {
  type ResumenCitas,
} from "@/components/RecepcionCitas.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useAnchoMinimo } from "@/lib/pantalla";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Sucursal {
  id: string;
  nombre: string;
}
interface Metricas {
  sesiones: number;
  capacidad_total: number;
  confirmadas: number;
  en_espera: number;
  presentes: number;
  ausentes: number;
  ocupacion_pct: number | null;
}
interface SesionDia {
  id: string;
  oferta: string | null;
  instructor: string | null;
  inicia_en: string;
  zona_horaria: string;
  estado: string;
  capacidad: number | null;
  confirmadas: number;
  ofrecidas: number;
  en_espera: number;
  presentes: number;
  ausentes: number;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
// En citas, la jornada es una lista de clientes (RecepcionCitas); sus números.
const resumenCitas = ref<ResumenCitas | null>(null);
const base = computed(() => `/api/v1/app/${sesion.slug}`);
// En escritorio el detalle de la clase va junto a la lista; en móvil, como panel.
const esEscritorio = useAnchoMinimo(1024);

function iso(d: Date): string {
  const p = (n: number): string => (n < 10 ? `0${n}` : `${n}`);
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function isoHoy(): string {
  return iso(new Date());
}

const fecha = ref(isoHoy());
const sucursalFiltro = ref("");
// Con una sucursal fija (la de la barra o la única), su filtro sobra.
const { mostrarSelect: elegirSucursal } = useSucursalOperativa({
  filtro: sucursalFiltro,
});
const sucursales = ref<Sucursal[]>([]);
const metricas = ref<Metricas | null>(null);
const sesiones = ref<SesionDia[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const sesionActiva = ref<SesionDia | null>(null);

function abrir(s: SesionDia): void {
  sesionActiva.value = s;
}

/** "Jueves, 24 de septiembre" */
const diaTexto = computed(() => {
  const texto = new Intl.DateTimeFormat("es-MX", {
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(`${fecha.value}T12:00:00`));
  return texto.charAt(0).toUpperCase() + texto.slice(1);
});
function moverDia(dias: number): void {
  const d = new Date(`${fecha.value}T12:00:00`);
  d.setDate(d.getDate() + dias);
  fecha.value = iso(d);
}
// El día en texto abre el calendario nativo (el <input type="date"> va oculto).
const selectorFecha = ref<HTMLInputElement | null>(null);
function elegirFecha(): void {
  const campo = selectorFecha.value;
  if (campo === null) {
    return;
  }
  if (typeof campo.showPicker === "function") {
    campo.showPicker();
  } else {
    campo.focus();
  }
}

// Buscador global de alumno (server-side): encuentra a cualquiera, no solo a los
// primeros 100. Al elegir uno se abre su panel con alertas.
interface MiembroResultado {
  id: string;
  nombre_completo: string;
  email: string | null;
}
const busqueda = ref("");
const directorioAbierto = ref(false);
const resultados = ref<MiembroResultado[]>([]);
const buscando = ref(false);
const miembroActivo = ref<{ id: string; nombre: string } | null>(null);
let tempBusqueda: ReturnType<typeof setTimeout> | undefined;

// Pase QR del alumno: el lector USB lo "escribe" en el buscador (y da Enter) o se
// escanea con la cámara. Se registra la entrada y se abre su ficha.
const PREFIJO_PASE = "AU1.";
const escanerAbierto = ref(false);
const camaraDisponible =
  typeof window !== "undefined" &&
  "BarcodeDetector" in window &&
  !!navigator.mediaDevices?.getUserMedia;
const registrandoPase = ref(false);
const avisoPase = ref<{ texto: string; permitido: boolean } | null>(null);

function esPase(texto: string): boolean {
  return texto.trim().startsWith(PREFIJO_PASE);
}

async function registrarPase(codigo: string): Promise<void> {
  escanerAbierto.value = false;
  busqueda.value = "";
  resultados.value = [];
  registrandoPase.value = true;
  try {
    const { data } = await api.post<{
      data: {
        permitido: boolean;
        codigo: string;
        persona_id: string | null;
        persona_nombre: string | null;
      };
    }>(`${base.value}/accesos`, {
      codigo: codigo.trim(),
      sucursal_id: sucursalFiltro.value || null,
    });
    const r = data.data;
    const resultado = `${t(
      r.permitido ? "accesoRecepcion.permitido" : "accesoRecepcion.denegado",
    )} · ${t(`accesoRecepcion.codigos.${r.codigo}`)}`;
    avisoPase.value = {
      texto: t("paseEntrada.entrada", {
        nombre: r.persona_nombre ?? "—",
        resultado,
      }),
      permitido: r.permitido,
    };
    if (r.persona_id) {
      miembroActivo.value = {
        id: r.persona_id,
        nombre: r.persona_nombre ?? "",
      };
    }
  } catch (e) {
    avisoPase.value = { texto: mensajeDeError(e), permitido: false };
  } finally {
    registrandoPase.value = false;
  }
}

function alEnter(): void {
  if (esPase(busqueda.value)) {
    void registrarPase(busqueda.value);
  }
}

async function buscar(): Promise<void> {
  const q = busqueda.value.trim();
  if (esPase(q)) {
    return;
  }
  if (q.length < 2) {
    resultados.value = [];
    return;
  }
  buscando.value = true;
  try {
    const { data } = await api.get<{ data: MiembroResultado[] }>(
      `${base.value}/miembros`,
      { params: { q } },
    );
    resultados.value = data.data;
  } catch {
    resultados.value = [];
  } finally {
    buscando.value = false;
  }
}
watch(busqueda, () => {
  clearTimeout(tempBusqueda);
  tempBusqueda = setTimeout(() => void buscar(), 300);
});
function abrirMiembro(m: MiembroResultado): void {
  miembroActivo.value = { id: m.id, nombre: m.nombre_completo };
  resultados.value = [];
  busqueda.value = "";
}

function horaCorta(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

function lugares(s: SesionDia): string {
  return s.capacidad !== null
    ? t("recepcionVisual.lugares", {
        ocupados: s.confirmadas,
        capacidad: s.capacidad,
      })
    : t("recepcionVisual.reservados", { n: s.confirmadas });
}
function progreso(s: SesionDia): number {
  return s.confirmadas > 0
    ? Math.min(
        100,
        Math.max(0, ((s.presentes + s.ausentes) / s.confirmadas) * 100),
      )
    : 0;
}

/** La clase en curso o la siguiente; si ya pasaron todas, la primera del día. */
function claseInicial(): SesionDia | null {
  const hace1h = Date.now() - 60 * 60 * 1000;
  return (
    sesiones.value.find(
      (s) =>
        s.estado === "programada" && new Date(s.inicia_en).getTime() >= hace1h,
    ) ??
    sesiones.value[0] ??
    null
  );
}

async function cargar(): Promise<void> {
  // En citas, la lista y sus números los carga RecepcionCitas.
  if (sesion.esCitas) {
    cargando.value = false;
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = { fecha: fecha.value };
    if (sucursalFiltro.value !== "") {
      params.sucursal_id = sucursalFiltro.value;
    }
    const { data } = await api.get<{
      metricas: Metricas;
      sesiones: SesionDia[];
    }>(`${base.value}/front-desk`, { params });
    metricas.value = data.metricas;
    sesiones.value = data.sesiones;
    // En escritorio siempre hay una clase a la vista (la que sigue).
    if (esEscritorio.value) {
      const misma = sesiones.value.find((s) => s.id === sesionActiva.value?.id);
      sesionActiva.value = misma ?? claseInicial();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Las métricas del día en tarjetas, cada una con su ícono y su color.
const tarjetas = computed<Indicador[]>(() => {
  // Citas: cuántas, quiénes llegaron, cuántas faltan por atender y por cobrar (la
  // ocupación de «lugares» no dice si la jornada está llena).
  if (sesion.esCitas) {
    const r = resumenCitas.value;
    if (r === null) {
      return [];
    }
    const citas: Omit<Indicador, "etiqueta">[] = [
      { clave: "citas", valor: String(r.citas), icono: "agenda", tono: "azul" },
      {
        clave: "llegaron",
        valor: String(r.llegaron),
        icono: "hecho",
        tono: "verde",
      },
      {
        clave: "porAtender",
        valor: String(r.porAtender),
        icono: "reloj",
        tono: "cielo",
      },
      {
        clave: "porCobrar",
        valor: String(r.porCobrar),
        icono: "dinero",
        tono: "naranja",
        aviso: r.porCobrar > 0,
      },
    ];
    return citas.map((k) => ({
      ...k,
      etiqueta: t(`recepcionVisual.citas.kpi.${k.clave}`),
    }));
  }
  const m = metricas.value;
  if (m === null) {
    return [];
  }
  const lista: Omit<Indicador, "etiqueta">[] = [
    {
      clave: "sesiones",
      valor: String(m.sesiones),
      icono: "agenda",
      tono: "azul",
    },
    {
      clave: "ocupacion",
      valor: m.ocupacion_pct !== null ? `${m.ocupacion_pct}%` : "—",
      icono: "pulso",
      tono: "morado",
    },
    {
      clave: "confirmadas",
      valor: String(m.confirmadas),
      icono: "hecho",
      tono: "cielo",
    },
    {
      clave: "enEspera",
      valor: String(m.en_espera),
      icono: "reloj",
      tono: "naranja",
      aviso: m.en_espera > 0,
    },
    {
      clave: "presentes",
      valor: String(m.presentes),
      icono: "personas",
      tono: "verde",
    },
    {
      clave: "ausentes",
      valor: String(m.ausentes),
      icono: "ausente",
      tono: "rosa",
    },
  ];
  return lista.map((k) => ({
    ...k,
    etiqueta: t(`recepcion.metricas.${k.clave}`),
  }));
});

watch([fecha, sucursalFiltro], cargar);

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Sucursal[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data;
  } catch {
    // el filtro de sucursal es opcional
  }
  await cargar();
});
</script>

<template>
  <section class="tu-pagina">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <EncabezadoSeccion :titulo="$t('recepcion.titulo')" />
      <RouterLink class="tu-btn tu-btn-fantasma" :to="{ name: 'agenda' }">{{
        $t("recepcion.abrirAgenda")
      }}</RouterLink>
    </div>

    <!-- En citas, la búsqueda principal es la jornada; el directorio queda aparte. -->
    <button
      v-if="sesion.esCitas && sesion.puede('miembros.ver')"
      type="button"
      class="tu-btn tu-btn-fantasma mt-4"
      :aria-expanded="directorioAbierto"
      aria-controls="fd-directorio"
      @click="directorioAbierto = !directorioAbierto"
    >
      <IconoNav nombre="miembros" :tam="18" />{{
        $t("operacion.admin.buscarDirectorio")
      }}
    </button>
    <div v-show="!sesion.esCitas || directorioAbierto" id="fd-directorio">
      <!-- Buscador global de alumno -->
      <div class="relative mt-5">
        <IconoNav
          nombre="buscar"
          :tam="18"
          class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2"
          :style="{ color: 'var(--texto-suave)' }"
        />
        <div class="flex gap-2">
          <input
            v-model="busqueda"
            type="search"
            class="tu-input pl-10"
            :placeholder="$t('recepcion.buscarMiembro')"
            :aria-label="$t('recepcion.buscarMiembro')"
            :disabled="registrandoPase"
            @keydown.enter.prevent="alEnter"
          />
          <button
            v-if="camaraDisponible"
            type="button"
            class="tu-btn tu-btn-fantasma shrink-0"
            @click="escanerAbierto = true"
          >
            {{ $t("paseEntrada.escanear") }}
          </button>
        </div>
        <div
          v-if="busqueda.trim().length >= 2 && !esPase(busqueda)"
          class="absolute z-20 mt-1 w-full tu-card overflow-hidden"
        >
          <p
            v-if="buscando"
            class="px-4 py-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comun.cargando") }}
          </p>
          <p
            v-else-if="resultados.length === 0"
            class="px-4 py-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("recepcion.sinResultados") }}
          </p>
          <ul v-else class="max-h-72 overflow-y-auto">
            <li v-for="m in resultados" :key="m.id">
              <button
                type="button"
                class="w-full border-t px-4 py-2.5 text-left first:border-t-0 hover:brightness-95"
                :style="{ borderColor: 'var(--borde)' }"
                @click="abrirMiembro(m)"
              >
                <span class="block font-medium">{{ m.nombre_completo }}</span>
                <span
                  v-if="m.email"
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ m.email }}</span
                >
              </button>
            </li>
          </ul>
        </div>
      </div>

      <p
        v-if="avisoPase"
        class="mt-3 text-sm font-medium"
        role="status"
        :style="{
          color: avisoPase.permitido ? 'var(--exito)' : 'var(--error)',
        }"
      >
        {{ avisoPase.texto }}
      </p>
      <p
        v-else-if="!sesion.esCitas"
        class="mt-2 text-xs"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("paseEntrada.pistaLector") }}
      </p>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Métricas del día -->
    <TarjetasIndicadores
      v-if="tarjetas.length > 0"
      class="mt-5"
      :tarjetas="tarjetas"
      compacta
    />

    <!-- El día: navegación y clases con su detalle a un lado -->
    <div class="mt-5 tu-card overflow-hidden">
      <div
        class="flex flex-wrap items-center gap-1.5 px-4 py-3 border-b"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <button
          type="button"
          class="tu-btn tu-btn-fantasma fd-paso"
          :aria-label="$t('recepcionVisual.diaAnterior')"
          @click="moverDia(-1)"
        >
          <IconoNav nombre="chevron" :tam="18" class="rotate-180" />
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma fd-hoy text-sm"
          @click="fecha = isoHoy()"
        >
          {{ $t("recepcion.hoy") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma fd-paso"
          :aria-label="$t('recepcionVisual.diaSiguiente')"
          @click="moverDia(1)"
        >
          <IconoNav nombre="chevron" :tam="18" />
        </button>
        <button
          type="button"
          class="fd-dia"
          :title="$t('recepcionVisual.elegirFecha')"
          @click="elegirFecha"
        >
          {{ diaTexto }}
        </button>
        <input
          ref="selectorFecha"
          v-model="fecha"
          type="date"
          class="sr-only"
          tabindex="-1"
          :aria-label="$t('recepcion.fecha')"
        />
        <span
          v-if="elegirSucursal && sucursales.length > 1"
          class="tu-select-icono ml-auto"
        >
          <IconoNav nombre="ubicacion" :tam="16" />
          <select
            v-model="sucursalFiltro"
            class="tu-input w-auto py-1.5 text-sm"
            :aria-label="$t('recepcion.todasSucursales')"
          >
            <option value="">{{ $t("recepcion.todasSucursales") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </span>
      </div>

      <!-- Citas: la jornada como lista de clientes, con su cita al tocarla -->
      <RecepcionCitas
        v-if="sesion.esCitas"
        :fecha="fecha"
        :sucursal-id="sucursalFiltro"
        @resumen="resumenCitas = $event"
      />
      <div
        v-else
        class="lg:grid lg:grid-cols-[minmax(0,1fr)_19rem] xl:grid-cols-[minmax(0,1fr)_24rem]"
      >
        <!-- Clases del día -->
        <div class="min-w-0">
          <p
            v-if="cargando"
            class="px-5 py-6 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comun.cargando") }}
          </p>
          <!-- Si falló la consulta, el error ya se muestra arriba: no es "no hay nada". -->
          <p v-else-if="error" class="px-5 py-12 text-center text-sm">
            <button type="button" class="tu-enlace" @click="cargar">
              {{ $t("comun.reintentar") }}
            </button>
          </p>
          <EstadoVacio
            v-else-if="sesiones.length === 0"
            class="py-12"
            icono="agenda"
            :titulo="
              sucursalFiltro !== ''
                ? $t('operacion.recepcion.sinActividadFiltros')
                : $t('operacion.recepcion.sinActividad')
            "
          >
            <RouterLink
              :to="{ name: 'agenda' }"
              class="tu-btn tu-btn-fantasma"
              >{{ $t("operacion.recepcion.irAgenda") }}</RouterLink
            >
          </EstadoVacio>
          <ul v-else class="fd-lista">
            <li v-for="s in sesiones" :key="s.id">
              <button
                type="button"
                class="fd-clase"
                :class="{
                  'fd-activa': sesionActiva?.id === s.id,
                  'fd-cancelada': s.estado !== 'programada',
                }"
                :aria-pressed="sesionActiva?.id === s.id"
                @click="abrir(s)"
              >
                <span class="fd-hora">{{
                  horaCorta(s.inicia_en, s.zona_horaria)
                }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block font-semibold fd-nombre"
                    >{{ s.oferta ?? "—"
                    }}<span
                      v-if="s.estado !== 'programada'"
                      class="ml-1.5 text-xs font-normal"
                      :style="{ color: 'var(--texto-suave)' }"
                      >· {{ $t("recepcion.cancelada") }}</span
                    ></span
                  >
                  <span
                    class="block text-xs fd-detalle"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{
                      [s.instructor, lugares(s)].filter(Boolean).join(" · ")
                    }}</span
                  >
                </span>
                <span class="shrink-0 text-right text-xs">
                  <span
                    v-if="s.estado === 'programada' && s.confirmadas > 0"
                    class="fd-progreso"
                    :title="$t('operacion.admin.progresoAsistencia')"
                    aria-hidden="true"
                    ><span :style="{ width: `${progreso(s)}%` }"
                  /></span>
                  <span
                    v-if="s.en_espera > 0"
                    class="block font-medium"
                    :style="{ color: 'var(--aviso)' }"
                    >{{
                      $t("recepcionVisual.enEspera", { n: s.en_espera })
                    }}</span
                  >
                  <span
                    v-if="s.confirmadas > 0"
                    class="block"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{
                      $t("recepcionVisual.llegaron", {
                        n: s.presentes,
                        total: s.confirmadas,
                      })
                    }}</span
                  >
                </span>
              </button>
            </li>
          </ul>
        </div>

        <!-- Detalle de la clase (escritorio): al lado, sobre fondo gris -->
        <div
          v-if="esEscritorio"
          class="border-l"
          :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
        >
          <PanelClase
            v-if="sesionActiva"
            :sesion="sesionActiva"
            incrustado
            @cerrar="sesionActiva = null"
            @cambio="cargar"
          />
          <!-- Pedir que elija una clase solo tiene sentido si hay alguna. -->
          <EstadoVacio
            v-else-if="!cargando && !error && sesiones.length > 0"
            class="py-14"
            icono="recepcion"
            :titulo="$t('recepcionVisual.seleccionaClase')"
          />
        </div>
      </div>
    </div>

    <!-- Móvil: el detalle como panel -->
    <PanelClase
      v-if="sesionActiva && !esEscritorio"
      :sesion="sesionActiva"
      @cerrar="sesionActiva = null"
      @cambio="cargar"
    />
    <PanelMiembro
      v-if="miembroActivo"
      :key="miembroActivo.id"
      :persona-id="miembroActivo.id"
      :nombre="miembroActivo.nombre"
      :sucursal-id="sucursalFiltro || undefined"
      @cerrar="miembroActivo = null"
    />
    <EscanerPase
      v-if="camaraDisponible"
      :abierto="escanerAbierto"
      @codigo="registrarPase"
      @cerrar="escanerAbierto = false"
    />
  </section>
</template>

<style scoped>
.fd-lista {
  display: grid;
  gap: 0.6rem;
  padding: 1rem;
}
.fd-nombre,
.fd-detalle {
  overflow-wrap: anywhere;
}
.fd-detalle {
  margin-top: 0.2rem;
}
.fd-progreso {
  display: block;
  width: 4rem;
  height: 0.25rem;
  margin: 0.35rem 0 0.5rem auto;
  border-radius: 1rem;
  overflow: hidden;
  background: var(--superficie-2);
}
.fd-progreso > span {
  display: block;
  height: 100%;
  background: var(--exito);
}
/* Navegación de días: botones de borde, como en la Agenda. */
.fd-paso {
  padding: 0.6rem;
  min-width: 44px;
  min-height: 44px;
}
.fd-hoy {
  padding: 0.45rem 0.9rem;
}
.fd-dia {
  padding: 0.3rem 0.5rem;
  border-radius: 0.5rem;
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
}
.fd-dia:hover {
  background: var(--superficie-2);
}
.fd-clase {
  display: flex;
  align-items: center;
  gap: 1rem;
  width: 100%;
  padding: 0.95rem;
  border: 1px solid var(--borde);
  border-radius: 0.85rem;
  background: transparent;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.fd-clase:hover {
  background: color-mix(in srgb, var(--primario) 4%, var(--superficie));
}
.fd-activa,
.fd-activa:hover {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 7%, var(--superficie));
  box-shadow: inset 3px 0 0 var(--primario);
}
.fd-cancelada {
  opacity: 0.55;
}
.fd-hora {
  padding: 0.4rem 0.5rem;
  border-radius: 0.55rem;
  color: var(--primario);
  background: var(--primario-suave);
  flex-shrink: 0;
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
@media (max-width: 600px) {
  .fd-clase {
    flex-wrap: wrap;
    gap: 0.65rem 0.75rem;
    align-items: flex-start;
  }
  .fd-clase > span:last-child {
    display: flex;
    flex-basis: 100%;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-left: 4rem;
    text-align: left;
  }
  .fd-clase > span:last-child:empty {
    display: none;
  }
  .fd-progreso {
    margin: 0;
  }
}
</style>
