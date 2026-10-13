<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Reportes → Plataformas (ADR 0148): quién vino por Wellhub y TotalPass y sus visitas.
 * Son clientes de la plataforma, no del negocio: se ven aquí, aparte de los alumnos, con
 * lo que la plataforma manda. Si el negocio ya registró a alguien con el mismo correo,
 * se dice que ya es alumno.
 */
type Proveedor = "wellhub" | "totalpass";
interface Resumen {
  resumen: {
    visitas: number;
    validadas: number;
    sin_verificar: number;
    excepciones: number;
    automaticas: number;
    sin_clase: number;
    visitantes: number;
    nuevos: number;
  };
  por_proveedor: {
    proveedor: Proveedor;
    visitas: number;
    visitantes: number;
  }[];
  por_dia: { fecha: string; visitas: number }[];
  por_clase: { clase: string; visitas: number }[];
}
interface Visitante {
  id: string;
  proveedor: Proveedor;
  nombre: string | null;
  email: string | null;
  telefono: string | null;
  producto: string | null;
  id_externo: string;
  visitas: number;
  primera_visita_en: string | null;
  ultima_visita_en: string | null;
  es_alumno: boolean;
}
interface Detalle extends Visitante {
  historial: {
    id: string;
    fecha: string;
    clase: string | null;
    sesion_inicia_en: string | null;
    estado: string;
    origen: string;
  }[];
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const zona = computed(() => sesion.zonaHoraria || undefined);

// El periodo, en días del negocio: este mes hasta hoy.
function diaDelNegocio(fecha: Date): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona.value,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(fecha);
}
const hoy = diaDelNegocio(new Date());
const desde = ref(`${hoy.slice(0, 8)}01`);
const hasta = ref(hoy);
const proveedor = ref<"" | Proveedor>("");

const resumen = ref<Resumen | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargarResumen(): Promise<void> {
  if (desde.value === "" || hasta.value === "" || hasta.value < desde.value) {
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen }>(
      `${base.value}/reportes/plataformas`,
      {
        params: {
          desde: desde.value,
          hasta: hasta.value,
          ...(proveedor.value !== "" ? { proveedor: proveedor.value } : {}),
        },
      },
    );
    resumen.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// ---- Visitantes ----
const visitantes = ref<Visitante[]>([]);
const meta = ref<Meta | null>(null);
const busqueda = ref("");
const pagina = ref(1);
const cargandoLista = ref(true);
let peticion = 0;
async function cargarVisitantes(): Promise<void> {
  const mia = ++peticion;
  cargandoLista.value = true;
  const q = busqueda.value.trim();
  try {
    const { data } = await api.get<{ data: Visitante[]; meta: Meta }>(
      `${base.value}/visitantes-plataforma`,
      {
        params: {
          page: pagina.value,
          ...(q !== "" ? { q } : {}),
          ...(proveedor.value !== "" ? { proveedor: proveedor.value } : {}),
        },
      },
    );
    if (mia === peticion) {
      visitantes.value = data.data;
      meta.value = data.meta;
    }
  } catch (e) {
    if (mia === peticion) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mia === peticion) {
      cargandoLista.value = false;
    }
  }
}
function irAPagina(n: number): void {
  pagina.value = n;
  void cargarVisitantes();
}
let espera: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargarVisitantes();
  }, 300);
});
watch([desde, hasta], () => void cargarResumen());
watch(proveedor, () => {
  pagina.value = 1;
  void cargarResumen();
  void cargarVisitantes();
});
onBeforeUnmount(() => clearTimeout(espera));

// ---- Detalle de un visitante ----
const detalle = ref<Detalle | null>(null);
const abierto = ref(false);
async function ver(v: Visitante): Promise<void> {
  detalle.value = { ...v, historial: [] };
  abierto.value = true;
  try {
    const { data } = await api.get<{ data: Detalle }>(
      `${base.value}/visitantes-plataforma/${v.id}`,
    );
    detalle.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

function fecha(iso: string | null, conHora = false): string {
  if (!iso) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona.value,
    day: "numeric",
    month: "short",
    year: "numeric",
    ...(conHora ? { hour: "2-digit", minute: "2-digit" } : {}),
  }).format(new Date(iso));
}
function diaCorto(dia: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  }).format(new Date(`${dia}T12:00:00Z`));
}
const maxDia = computed(() =>
  Math.max(1, ...(resumen.value?.por_dia.map((d) => d.visitas) ?? [1])),
);
const indicadores = computed(() => {
  const r = resumen.value?.resumen;
  if (!r) {
    return [];
  }
  return [
    {
      clave: "visitas",
      valor: r.visitas,
      detalle: t("operacion.plataformas.indicadores.automaticas", {
        n: r.automaticas,
      }),
      aviso: false,
    },
    { clave: "visitantes", valor: r.visitantes, detalle: "", aviso: false },
    { clave: "nuevos", valor: r.nuevos, detalle: "", aviso: false },
    {
      clave: "sinVerificar",
      valor: r.sin_verificar,
      detalle: "",
      aviso: r.sin_verificar > 0,
    },
    {
      clave: "sinClase",
      valor: r.sin_clase,
      detalle: "",
      aviso: r.sin_clase > 0,
    },
  ];
});

onMounted(() => {
  void cargarResumen();
  void cargarVisitantes();
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('operacion.plataformas.titulo')"
      :subtitulo="$t('operacion.plataformas.subtitulo')"
    />

    <!-- Periodo y plataforma -->
    <div class="rp-filtros mt-5">
      <fieldset class="rp-periodo">
        <legend class="sr-only">
          {{ $t("operacion.plataformas.periodo") }}
        </legend>
        <input
          v-model="desde"
          type="date"
          class="tu-input"
          :max="hasta || undefined"
          :aria-label="$t('operacion.plataformas.desde')"
          data-prueba="plataformas-desde"
        />
        <span aria-hidden="true">–</span>
        <input
          v-model="hasta"
          type="date"
          class="tu-input"
          :min="desde || undefined"
          :aria-label="$t('operacion.plataformas.hasta')"
          data-prueba="plataformas-hasta"
        />
      </fieldset>
      <div class="tu-segmentado" role="group">
        <button
          v-for="p in ['', 'wellhub', 'totalpass'] as const"
          :key="p"
          type="button"
          :aria-pressed="proveedor === p"
          :data-prueba="`plataforma-${p || 'todas'}`"
          @click="proveedor = p"
        >
          {{
            p === ""
              ? $t("operacion.plataformas.todas")
              : $t(`operacion.plataformas.proveedores.${p}`)
          }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-4 text-sm" :style="{ color: 'var(--error)' }">
      {{ error }}
    </p>

    <!-- Indicadores del periodo -->
    <div class="rp-indicadores mt-5" :aria-busy="cargando">
      <div
        v-for="i in indicadores"
        :key="i.clave"
        class="rp-indicador"
        :class="{ 'rp-aviso': i.aviso }"
        :data-prueba="`plataformas-${i.clave}`"
      >
        <span class="rp-valor">{{ i.valor }}</span>
        <span class="rp-etiqueta">{{
          $t(`operacion.plataformas.indicadores.${i.clave}`)
        }}</span>
        <span v-if="i.detalle" class="rp-detalle">{{ i.detalle }}</span>
      </div>
    </div>

    <div v-if="resumen" class="rp-columnas mt-5">
      <div class="tu-card p-4 sm:p-5">
        <h2 class="rp-titulo">
          {{ $t("operacion.plataformas.porPlataforma") }}
        </h2>
        <ul class="mt-3 space-y-2">
          <li
            v-for="p in resumen.por_proveedor"
            :key="p.proveedor"
            class="flex items-center justify-between gap-3 text-sm"
          >
            <span class="font-medium">{{
              $t(`operacion.plataformas.proveedores.${p.proveedor}`)
            }}</span>
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t("operacion.plataformas.visitasDe", {
                n: p.visitas,
                v: p.visitantes,
              })
            }}</span>
          </li>
        </ul>
      </div>
      <div class="tu-card p-4 sm:p-5">
        <h2 class="rp-titulo">{{ $t("operacion.plataformas.porClase") }}</h2>
        <ul v-if="resumen.por_clase.length > 0" class="mt-3 space-y-2">
          <li
            v-for="c in resumen.por_clase"
            :key="c.clase"
            class="flex items-center justify-between gap-3 text-sm"
          >
            <span class="min-w-0 truncate">{{ c.clase }}</span>
            <span class="font-medium">{{ c.visitas }}</span>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.plataformas.sinVisitas") }}
        </p>
      </div>
      <div class="tu-card p-4 sm:p-5 rp-ancha">
        <h2 class="rp-titulo">{{ $t("operacion.plataformas.porDia") }}</h2>
        <ul v-if="resumen.por_dia.length > 0" class="mt-3 space-y-1.5">
          <li v-for="d in resumen.por_dia" :key="d.fecha" class="rp-dia">
            <span class="rp-dia-fecha">{{ diaCorto(d.fecha) }}</span>
            <span class="rp-barra" aria-hidden="true">
              <span
                class="rp-barra-relleno"
                :style="{ width: `${(d.visitas / maxDia) * 100}%` }"
              />
            </span>
            <span class="rp-dia-valor">{{ d.visitas }}</span>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.plataformas.sinVisitas") }}
        </p>
      </div>
    </div>

    <!-- Visitantes -->
    <div class="tu-card mt-5 overflow-hidden">
      <div class="rp-lista-cabecera">
        <h2 class="rp-titulo">
          {{ $t("operacion.plataformas.lista.titulo") }}
        </h2>
        <input
          v-model="busqueda"
          type="search"
          class="tu-input rp-buscar"
          :placeholder="$t('operacion.plataformas.lista.buscar')"
          :aria-label="$t('operacion.plataformas.lista.buscar')"
          data-prueba="buscar-visitante"
        />
      </div>

      <EstadoVacio
        v-if="!cargandoLista && visitantes.length === 0"
        :icono="busqueda.trim() !== '' ? 'buscar' : 'personas'"
        :titulo="
          busqueda.trim() !== ''
            ? $t('operacion.plataformas.lista.sinResultados')
            : $t('operacion.plataformas.lista.vacio')
        "
        compacto
      />
      <ul v-else class="rp-lista" :aria-busy="cargandoLista">
        <li v-for="v in visitantes" :key="v.id">
          <button
            type="button"
            class="rp-fila"
            :aria-label="
              $t('operacion.plataformas.lista.ver', {
                nombre: v.nombre ?? v.id_externo,
              })
            "
            data-prueba="visitante"
            @click="ver(v)"
          >
            <span class="min-w-0 flex-1 text-left">
              <span class="block truncate font-medium">{{
                v.nombre ?? $t("operacion.plataformas.lista.sinNombre")
              }}</span>
              <span
                class="block truncate text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{
                  [v.email, v.telefono].filter(Boolean).join(" · ") ||
                  v.id_externo
                }}</span
              >
            </span>
            <span class="rp-celda">{{
              $t(`operacion.plataformas.proveedores.${v.proveedor}`)
            }}</span>
            <span class="rp-celda rp-num">{{ v.visitas }}</span>
            <span class="rp-celda rp-fecha">{{
              fecha(v.ultima_visita_en)
            }}</span>
            <span
              v-if="v.es_alumno"
              class="tu-estado rp-celda text-xs"
              :style="{ '--tono': 'var(--exito)' }"
              data-prueba="es-alumno"
              >{{ $t("operacion.plataformas.lista.esAlumno") }}</span
            >
          </button>
        </li>
      </ul>

      <PaginacionListado
        v-if="meta && meta.ultima_pagina > 1"
        :page="meta.page"
        :ultima-pagina="meta.ultima_pagina"
        :total="meta.total"
        :per-page="meta.per_page"
        @ir="irAPagina"
      />
    </div>

    <!-- Un visitante y sus visitas -->
    <PanelLateral
      :abierto="abierto"
      :titulo="detalle?.nombre ?? $t('operacion.plataformas.lista.sinNombre')"
      @cerrar="abierto = false"
    >
      <div v-if="detalle" class="space-y-4">
        <dl class="rp-datos">
          <dt>
            {{
              $t("operacion.plataformas.detalle.idPlataforma", {
                plataforma: $t(
                  `operacion.plataformas.proveedores.${detalle.proveedor}`,
                ),
              })
            }}
          </dt>
          <dd>{{ detalle.id_externo }}</dd>
          <template v-if="detalle.email">
            <dt>{{ $t("operacion.plataformas.detalle.correo") }}</dt>
            <dd>{{ detalle.email }}</dd>
          </template>
          <template v-if="detalle.telefono">
            <dt>{{ $t("operacion.plataformas.detalle.telefono") }}</dt>
            <dd>{{ detalle.telefono }}</dd>
          </template>
          <template v-if="detalle.producto">
            <dt>{{ $t("operacion.plataformas.detalle.producto") }}</dt>
            <dd>{{ detalle.producto }}</dd>
          </template>
          <dt>{{ $t("operacion.plataformas.detalle.primera") }}</dt>
          <dd>{{ fecha(detalle.primera_visita_en) }}</dd>
        </dl>
        <p
          v-if="detalle.es_alumno"
          class="tu-estado text-sm"
          :style="{ '--tono': 'var(--exito)' }"
        >
          {{ $t("operacion.plataformas.detalle.esAlumnoAyuda") }}
        </p>
        <div>
          <h3 class="rp-titulo">
            {{ $t("operacion.plataformas.detalle.historial") }}
          </h3>
          <ul class="mt-2 space-y-2" data-prueba="historial-visitante">
            <li v-for="h in detalle.historial" :key="h.id" class="rp-visita">
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium">{{
                  h.clase ?? $t("operacion.plataformas.detalle.sinClase")
                }}</span>
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ fecha(h.fecha, true) }} ·
                  {{ $t(`operacion.plataformas.origen.${h.origen}`) }}</span
                >
              </span>
              <span
                class="tu-estado shrink-0 text-xs"
                :style="{
                  '--tono':
                    h.estado === 'validado'
                      ? 'var(--exito)'
                      : h.estado === 'excepcion'
                        ? 'var(--primario)'
                        : 'var(--aviso)',
                }"
                >{{ $t(`operacion.plataformas.estados.${h.estado}`) }}</span
              >
            </li>
          </ul>
        </div>
      </div>
    </PanelLateral>
  </section>
</template>

<style scoped>
.rp-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.rp-periodo {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  color: var(--texto-suave);
}
.rp-periodo .tu-input {
  width: auto;
  min-height: 2.5rem;
  padding-block: 0.35rem;
  font-size: 0.85rem;
}
.rp-indicadores {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
@media (min-width: 768px) {
  .rp-indicadores {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
}
.rp-indicador {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
.rp-aviso {
  border-color: color-mix(in srgb, var(--aviso) 45%, var(--borde));
}
.rp-valor {
  font-size: 1.5rem;
  font-weight: 600;
  line-height: 1.1;
}
.rp-etiqueta {
  color: var(--texto-suave);
  font-size: 0.82rem;
}
.rp-detalle {
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.rp-columnas {
  display: grid;
  gap: 0.75rem;
}
@media (min-width: 900px) {
  .rp-columnas {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .rp-ancha {
    grid-column: 1 / -1;
  }
}
.rp-titulo {
  font-size: 0.95rem;
  font-weight: 600;
}
.rp-dia {
  display: grid;
  grid-template-columns: 7.5rem minmax(0, 1fr) 2.5rem;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.82rem;
}
.rp-dia-fecha {
  color: var(--texto-suave);
}
.rp-dia-valor {
  text-align: right;
  font-weight: 500;
}
.rp-barra {
  height: 0.5rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.rp-barra-relleno {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: var(--primario);
}
.rp-lista-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--borde);
}
.rp-buscar {
  width: 100%;
  max-width: 22rem;
}
.rp-lista > li + li {
  border-top: 1px solid var(--borde);
}
.rp-fila {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1.25rem;
  text-align: left;
}
.rp-fila:hover {
  background: color-mix(in srgb, var(--primario) 5%, var(--superficie));
}
.rp-celda {
  flex-shrink: 0;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.rp-num {
  width: 3rem;
  text-align: right;
  color: var(--texto);
  font-weight: 500;
}
.rp-fecha {
  width: 7rem;
}
@media (max-width: 639.98px) {
  .rp-celda:not(.rp-num) {
    display: none;
  }
}
.rp-datos {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.4rem 1rem;
  font-size: 0.875rem;
}
.rp-datos dt {
  color: var(--texto-suave);
}
.rp-datos dd {
  overflow-wrap: anywhere;
}
.rp-visita {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
}
</style>
