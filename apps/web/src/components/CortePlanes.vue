<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { fechaCorta } from "@/lib/planes";

/**
 * Corte de planes (ADR 0050): por cada paquete o membresía, qué incluía, sus clases
 * extra, en qué clases se usó (asistió, no asistió, cancelación tardía, próximas),
 * y lo que queda, se reservó o venció. Los vigentes arriba, completos; los
 * anteriores, plegados en una línea cada uno (qué fue, cuándo y cuánto se usó) y
 * se abren uno por uno. Lo ve el alumno en su cuenta y el equipo en su ficha
 * (`equipo`).
 */
interface Uso {
  clase: string | null;
  inicia_en: string;
  zona_horaria: string;
  estado:
    "asistio" | "no_asistio" | "cancelacion_tardia" | "proxima" | "tomada";
  unidades: number;
  extra: boolean;
}
interface PlanCorte {
  todas_sucursales?: boolean;
  sucursales?: { id: string; nombre: string }[];
  id: string;
  derecho_id: string;
  producto: string | null;
  tipo: string | null;
  comprado: string | null;
  desde: string | null;
  hasta: string | null;
  estado: string;
  ilimitado: boolean;
  aplica_a: string[];
  unidades: Record<
    | "incluidas"
    | "extras"
    | "usadas"
    | "devueltas"
    | "vencidas"
    | "ajustes"
    | "apartadas"
    | "disponibles",
    number
  >;
  extras: {
    producto: string | null;
    comprado: string | null;
    unidades: number;
    usadas: number;
  }[];
  usos: Uso[];
}

const props = defineProps<{ url: string; equipo?: boolean }>();
const { t } = useI18n();

const planes = ref<PlanCorte[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const abiertos = ref(new Set<string>());
// Planes anteriores: la lista plegada y, dentro, los que se abrieron.
const verAnteriores = ref(false);
const expandidos = ref(new Set<string>());

const ACTIVOS = ["vigente", "por_empezar", "pausado", "suspendido"];
const actuales = computed(() =>
  planes.value.filter((p) => ACTIVOS.includes(p.estado)),
);
const anteriores = computed(() =>
  planes.value.filter((p) => !ACTIVOS.includes(p.estado)),
);
const visibles = computed(() =>
  verAnteriores.value
    ? [...actuales.value, ...anteriores.value]
    : actuales.value,
);
function esAnterior(p: PlanCorte): boolean {
  return !ACTIVOS.includes(p.estado);
}
// Un plan anterior se ve en una línea hasta que se abre.
function plegado(p: PlanCorte): boolean {
  return esAnterior(p) && !expandidos.value.has(p.id);
}
// Las clases a las que sirve: las primeras y cuántas más (la lista completa
// puede ser larga).
const MAX_CLASES = 3;
function aplicaA(p: PlanCorte): string {
  const lista = p.aplica_a;
  return lista.length > MAX_CLASES
    ? t("planes.corte.aplicaAMas", {
        clases: lista.slice(0, MAX_CLASES).join(", "),
        n: lista.length - MAX_CLASES,
      })
    : t("planes.corte.aplicaA", { clases: lista.join(", ") });
}
// Resumen de un plan anterior: cuánto se usó.
function resumen(p: PlanCorte): string {
  const u = p.unidades;
  if (p.ilimitado) {
    return t("planes.corte.resumenIlimitado", { usadas: clases(u.usadas) });
  }
  return t("planes.corte.resumenUso", {
    usadas: clases(u.usadas),
    total: clases(u.incluidas + u.extras),
  });
}

function clases(unidades: number): string {
  return new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(
    unidades / 1000,
  );
}
function cuando(u: Uso): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: u.zona_horaria,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(u.inicia_en));
}
// Color solo para lo que pide atención: pausas y suspensiones; lo vigente en verde.
function colorEstado(estado: string): string {
  if (estado === "vigente") {
    return "var(--exito)";
  }
  if (estado === "pausado" || estado === "suspendido") {
    return "var(--aviso)";
  }
  return "var(--texto-suave)";
}
function numeros(p: PlanCorte): { clave: string; valor: string }[] {
  const u = p.unidades;
  const lista: { clave: string; valor: number; siempre?: boolean }[] = [
    { clave: "incluidas", valor: u.incluidas, siempre: !p.ilimitado },
    { clave: "extras", valor: u.extras },
    { clave: "usadas", valor: u.usadas, siempre: true },
    { clave: "apartadas", valor: u.apartadas },
    { clave: "devueltas", valor: u.devueltas },
    { clave: "vencidas", valor: u.vencidas },
    // Lo que quedó en un plan vencido o cancelado ya no se puede usar: no se
    // presenta como «disponible».
    {
      clave:
        p.estado === "vencido"
          ? "sinUsarVencido"
          : p.estado === "cancelado"
            ? "sinUsarCancelado"
            : "disponibles",
      valor: u.disponibles,
      siempre:
        !p.ilimitado && p.estado !== "vencido" && p.estado !== "cancelado",
    },
  ];
  return lista
    .filter((n) => n.siempre || n.valor > 0)
    .map((n) => ({ clave: n.clave, valor: clases(n.valor) }));
}
function alternarPlan(id: string): void {
  const s = new Set(expandidos.value);
  if (s.has(id)) {
    s.delete(id);
  } else {
    s.add(id);
  }
  expandidos.value = s;
}
function alternar(id: string): void {
  const s = new Set(abiertos.value);
  if (s.has(id)) {
    s.delete(id);
  } else {
    s.add(id);
  }
  abiertos.value = s;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: PlanCorte[] }>(props.url);
    planes.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
watch(() => props.url, cargar);
defineExpose({ cargar });
</script>

<template>
  <section class="tu-card p-5">
    <h2 class="font-semibold">
      {{ equipo ? $t("planes.corte.tituloEquipo") : $t("planes.corte.titulo") }}
    </h2>
    <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="planes.length === 0"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("planes.corte.vacio") }}
    </p>

    <template v-else>
      <p
        v-if="actuales.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("planes.corte.sinVigentes") }}
      </p>
      <template v-for="p in visibles" :key="p.id">
        <!-- Anterior y plegado: una línea -->
        <button
          v-if="plegado(p)"
          type="button"
          class="cp-plan cp-linea"
          :aria-expanded="false"
          data-prueba="plan-anterior"
          @click="alternarPlan(p.id)"
        >
          <span class="min-w-0">
            <span class="block truncate font-medium">{{
              p.producto ?? "—"
            }}</span>
            <span class="block text-sm" :style="{ color: 'var(--texto-suave)' }"
              >{{
                p.hasta
                  ? $t("planes.corte.vigencia", {
                      desde: fechaCorta(p.desde),
                      hasta: fechaCorta(p.hasta),
                    })
                  : $t("planes.corte.sinVencimiento", {
                      desde: fechaCorta(p.desde),
                    })
              }}
              · {{ resumen(p) }}</span
            >
          </span>
          <span
            class="tu-pildora shrink-0"
            :style="{ '--tono': colorEstado(p.estado) }"
            >{{ $t(`planes.corte.estados.${p.estado}`) }}</span
          >
        </button>
        <article
          v-else
          class="cp-plan"
          :class="{ 'cp-anterior': esAnterior(p) }"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-medium">{{ p.producto ?? "—" }}</h3>
            <span
              v-if="p.todas_sucursales !== undefined"
              class="text-xs"
              style="color: var(--texto-suave)"
              >{{
                p.todas_sucursales
                  ? $t("sucursalOperativa.todas")
                  : p.sucursales?.map((s) => s.nombre).join(" · ")
              }}</span
            >
            <span class="inline-flex items-center gap-1.5 text-sm">
              <span
                class="h-2 w-2 rounded-full"
                :style="{ background: colorEstado(p.estado) }"
                aria-hidden="true"
              />{{ $t(`planes.corte.estados.${p.estado}`) }}
            </span>
          </div>
          <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
            <template v-if="p.hasta">{{
              $t("planes.corte.vigencia", {
                desde: fechaCorta(p.desde),
                hasta: fechaCorta(p.hasta),
              })
            }}</template>
            <template v-else>{{
              $t("planes.corte.sinVencimiento", { desde: fechaCorta(p.desde) })
            }}</template>
            <template v-if="p.aplica_a.length > 0">
              · {{ aplicaA(p) }}</template
            >
          </p>
          <button
            v-if="esAnterior(p)"
            type="button"
            class="tu-enlace mt-1 text-sm"
            :aria-expanded="true"
            @click="alternarPlan(p.id)"
          >
            {{ $t("planes.corte.plegar") }}
          </button>

          <!-- Los números del plan -->
          <dl class="cp-numeros">
            <div v-if="p.ilimitado">
              <dt>{{ $t("planes.resumen.ilimitado") }}</dt>
              <dd>∞</dd>
            </div>
            <div
              v-for="n in numeros(p)"
              :key="n.clave"
              :class="{ 'cp-destacado': n.clave === 'disponibles' }"
            >
              <dt>{{ $t(`planes.corte.numeros.${n.clave}`) }}</dt>
              <dd>{{ n.valor }}</dd>
            </div>
          </dl>

          <!-- Clases extra -->
          <div v-if="p.extras.length > 0" class="mt-3 text-sm">
            <p class="font-medium">{{ $t("planes.corte.extras") }}</p>
            <ul
              class="mt-1 space-y-0.5"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <li v-for="(x, i) in p.extras" :key="i">
                {{
                  $t("planes.corte.extra", {
                    producto: x.producto ?? "—",
                    fecha: fechaCorta(x.comprado),
                    n: clases(x.usadas),
                    total: clases(x.unidades),
                  })
                }}
              </li>
            </ul>
          </div>

          <!-- Cómo se usó -->
          <button
            v-if="p.usos.length > 0"
            type="button"
            class="tu-enlace mt-3 text-sm"
            :aria-expanded="abiertos.has(p.id)"
            @click="alternar(p.id)"
          >
            {{
              equipo ? $t("planes.corte.usosEquipo") : $t("planes.corte.usos")
            }}
            ({{ p.usos.length }})
          </button>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("planes.corte.sinUsos") }}
          </p>
          <ul
            v-if="abiertos.has(p.id)"
            class="mt-2 divide-y divide-[var(--borde)] text-sm"
          >
            <li
              v-for="(u, i) in p.usos"
              :key="i"
              class="flex items-baseline justify-between gap-3 py-1.5"
            >
              <span class="min-w-0">
                <span class="first-letter:uppercase">{{ cuando(u) }}</span>
                <span :style="{ color: 'var(--texto-suave)' }">
                  · {{ u.clase ?? "—" }}</span
                >
                <span
                  v-if="u.extra"
                  class="ml-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >({{ $t("planes.corte.deExtra") }})</span
                >
              </span>
              <span
                class="shrink-0 text-xs"
                :style="{
                  color:
                    u.estado === 'no_asistio' ||
                    u.estado === 'cancelacion_tardia'
                      ? 'var(--aviso)'
                      : 'var(--texto-suave)',
                }"
                >{{ $t(`planes.corte.usoEstados.${u.estado}`) }}</span
              >
            </li>
          </ul>
        </article>
      </template>
      <button
        v-if="anteriores.length > 0"
        type="button"
        class="tu-enlace mt-5 block text-sm"
        :aria-expanded="verAnteriores"
        data-prueba="ver-anteriores"
        @click="verAnteriores = !verAnteriores"
      >
        {{
          verAnteriores
            ? $t("planes.corte.ocultarAnteriores")
            : $t("planes.corte.verAnteriores", { n: anteriores.length })
        }}
      </button>
    </template>
  </section>
</template>

<style scoped>
.cp-plan {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
}
.cp-plan:first-of-type {
  border-top: 0;
  padding-top: 0;
}
.cp-anterior {
  opacity: 0.85;
}
/* Un plan anterior plegado: una línea que se abre al tocarla. */
.cp-linea {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  text-align: left;
}
.cp-linea:hover .font-medium {
  color: var(--primario);
}
.cp-numeros {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1.5rem;
  margin-top: 0.75rem;
}
.cp-numeros dt {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.cp-numeros dd {
  font-size: 1.15rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.cp-destacado dd {
  color: var(--primario);
}
</style>
