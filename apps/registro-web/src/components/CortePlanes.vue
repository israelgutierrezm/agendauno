<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import { fechaCorta } from "@/lib/planes";

/**
 * Corte de planes (ADR 0050): por cada paquete o membresía, qué incluía, sus clases
 * extra, en qué clases se usó (asistió, no asistió, cancelación tardía, próximas),
 * y lo que queda, se reservó o venció. Los vigentes arriba; los anteriores,
 * plegados. Lo ve el alumno en su cuenta y el equipo en su ficha (`equipo`).
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

const planes = ref<PlanCorte[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const abiertos = ref(new Set<string>());

const ACTIVOS = ["vigente", "por_empezar", "pausado", "suspendido"];
const actuales = computed(() =>
  planes.value.filter((p) => ACTIVOS.includes(p.estado)),
);
const anteriores = computed(() =>
  planes.value.filter((p) => !ACTIVOS.includes(p.estado)),
);

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
    { clave: "disponibles", valor: u.disponibles, siempre: !p.ilimitado },
  ];
  return lista
    .filter((n) => n.siempre || n.valor > 0)
    .map((n) => ({ clave: n.clave, valor: clases(n.valor) }));
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
      <template v-for="(grupo, g) in [actuales, anteriores]" :key="g">
        <p
          v-if="g === 1 && grupo.length > 0"
          class="mt-6 text-sm font-medium"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("planes.corte.anteriores") }}
        </p>
        <article
          v-for="p in grupo"
          :key="p.id"
          class="cp-plan"
          :class="{ 'cp-anterior': g === 1 }"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-medium">{{ p.producto ?? "—" }}</h3>
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
              ·
              {{
                $t("planes.corte.aplicaA", { clases: p.aplica_a.join(", ") })
              }}</template
            >
          </p>

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
  opacity: 0.75;
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
