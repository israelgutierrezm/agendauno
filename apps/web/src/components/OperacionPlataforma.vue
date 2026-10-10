<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";

/**
 * Operación de la plataforma, para el superadmin: lo mismo que dicen la consola
 * (agendauno:verificar-produccion) y los correos de alertas. Versión en marcha,
 * programador y cola, la verificación completa, los últimos respaldos con el
 * último simulacro, y las alertas recientes. Solo lee.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

type EstadoProceso = "ok" | "atrasado" | "sin_datos" | "otra_version";
interface Punto {
  seccion: string;
  punto: string;
  estado: "ok" | "aviso" | "falta";
  detalle: string;
}
interface Comprobacion {
  nombre: string;
  ok: boolean;
  detalle: string;
}
interface Prueba {
  respaldo: string;
  ok: boolean;
  detalle: string;
  comprobaciones: Comprobacion[];
}
interface Alerta {
  tipo: string;
  clave: string;
  estudio: string | null;
  mensaje: string;
  veces: number;
  primera_en: string;
  ultima_en: string;
  avisada: boolean;
}
interface Operacion {
  version: string;
  entorno: string;
  mantenimiento: boolean;
  procesos: Record<
    "programador" | "cola",
    { estado: EstadoProceso; ultimo: string | null }
  >;
  verificacion: Punto[];
  respaldos: {
    plataforma: string | null;
    archivos: string | null;
    simulacro: {
      fecha: string;
      ok: boolean;
      pruebas: Prueba[];
    } | null;
  };
  alertas: Alerta[];
  revisado_en: string;
}

const { t, te } = useI18n();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const datos = ref<Operacion | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.get<{ data: Operacion }>(
      "/api/v1/plataforma/operacion",
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    datos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// La verificación agrupada por sección, en el orden en que llega.
const secciones = computed(() => {
  const grupos = new Map<string, Punto[]>();
  for (const p of datos.value?.verificacion ?? []) {
    grupos.set(p.seccion, [...(grupos.get(p.seccion) ?? []), p]);
  }
  return [...grupos.entries()].map(([nombre, puntos]) => ({ nombre, puntos }));
});
const faltan = computed(
  () =>
    datos.value?.verificacion.filter((p) => p.estado === "falta").length ?? 0,
);
const avisos = computed(
  () =>
    datos.value?.verificacion.filter((p) => p.estado === "aviso").length ?? 0,
);

function fechaHora(iso: string | null): string {
  if (iso === null) {
    return t("plataformaAdmin.operacion.nunca");
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}
function hora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { timeStyle: "short" }).format(
    new Date(iso),
  );
}
// Una alerta de un tipo nuevo, sin nombre todavía: genérica (su mensaje la explica).
function tipoAlerta(tipo: string): string {
  const llave = `plataformaAdmin.operacion.tipos.${tipo}`;
  return te(llave) ? t(llave) : t("plataformaAdmin.operacion.tipoOtro");
}
// APP_ENV en palabras (production → Producción); uno desconocido, tal cual.
function entorno(valor: string): string {
  const llave = `plataformaAdmin.operacion.entornos.${valor}`;
  return te(llave)
    ? t(llave)
    : t("plataformaAdmin.operacion.entornoOtro", { entorno: valor });
}
// El nombre del archivo de un respaldo, sin la carpeta.
function nombreRespaldo(ruta: string): string {
  return ruta.split("/").pop() ?? ruta;
}
// Punto de color solo para lo que pide atención; lo que está bien, en gris.
const GRIS = { "--tono": "var(--texto-suave)" };
const AVISO = { "--tono": "var(--aviso)" };
const ERROR = { "--tono": "var(--error)" };
function tonoProceso(e: EstadoProceso): Record<string, string> {
  return e === "ok" ? GRIS : e === "atrasado" ? AVISO : ERROR;
}
function tonoPunto(e: Punto["estado"]): Record<string, string> {
  return e === "ok" ? GRIS : e === "aviso" ? AVISO : ERROR;
}

onMounted(cargar);
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        <template v-if="datos">
          {{
            $t("plataformaAdmin.operacion.revisado", {
              hora: hora(datos.revisado_en),
            })
          }}
        </template>
      </p>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="cargando"
        @click="cargar"
      >
        {{ $t("plataformaAdmin.operacion.actualizar") }}
      </button>
    </div>

    <p v-if="error" class="text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando && datos === null"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plataformaAdmin.operacion.cargando") }}
    </p>

    <template v-if="datos">
      <!-- Lo esencial en una franja -->
      <div
        class="tu-card grid gap-4 p-4 grid-cols-2 lg:grid-cols-5"
        data-prueba="franja"
      >
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.version") }}
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums">
            {{ datos.version }}
          </p>
        </div>
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.entorno") }}
          </p>
          <p class="mt-1 text-xl font-semibold" data-prueba="entorno">
            {{ entorno(datos.entorno) }}
          </p>
        </div>
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.servicio") }}
          </p>
          <p class="mt-1 tu-estado" :style="datos.mantenimiento ? AVISO : GRIS">
            {{
              datos.mantenimiento
                ? $t("plataformaAdmin.operacion.mantenimiento")
                : $t("plataformaAdmin.operacion.abierto")
            }}
          </p>
        </div>
        <div
          v-for="nombre in ['programador', 'cola'] as const"
          :key="nombre"
          :data-prueba="`proceso-${nombre}`"
        >
          <p class="op-etiqueta">
            {{ $t(`plataformaAdmin.operacion.${nombre}`) }}
          </p>
          <p
            class="mt-1 tu-estado"
            :style="tonoProceso(datos.procesos[nombre].estado)"
          >
            {{
              $t(
                `plataformaAdmin.operacion.procesos.${datos.procesos[nombre].estado}`,
              )
            }}
          </p>
          <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              $t("plataformaAdmin.operacion.ultimoLatido", {
                cuando: fechaHora(datos.procesos[nombre].ultimo),
              })
            }}
          </p>
        </div>
      </div>

      <!-- Verificación de producción -->
      <div class="tu-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="font-medium text-lg">
            {{ $t("plataformaAdmin.operacion.verificacion") }}
          </h2>
          <p class="text-sm" data-prueba="resumen-verificacion">
            <template v-if="faltan === 0 && avisos === 0">
              {{ $t("plataformaAdmin.operacion.todoEnOrden") }}
            </template>
            <template v-else>
              <span v-if="faltan > 0" style="color: var(--error)">
                {{ $t("plataformaAdmin.operacion.porResolver", faltan) }}
              </span>
              <span v-if="faltan > 0 && avisos > 0"> · </span>
              <span v-if="avisos > 0" style="color: var(--aviso)">
                {{ $t("plataformaAdmin.operacion.avisos", avisos) }}
              </span>
            </template>
          </p>
        </div>
        <div
          v-for="s in secciones"
          :key="s.nombre"
          class="mt-4 border-t pt-3"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <h3 class="text-sm font-medium">{{ s.nombre }}</h3>
          <ul class="mt-2 space-y-1.5">
            <li v-for="p in s.puntos" :key="p.punto" class="text-sm">
              <span class="tu-estado" :style="tonoPunto(p.estado)">
                {{ p.punto }}
              </span>
              <p
                v-if="p.detalle"
                class="ml-4 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ p.detalle }}
              </p>
            </li>
          </ul>
        </div>
      </div>

      <!-- Respaldos y simulacro -->
      <div class="tu-card p-5">
        <h2 class="font-medium text-lg">
          {{ $t("plataformaAdmin.operacion.respaldos") }}
        </h2>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
          <div>
            <dt class="op-etiqueta">
              {{ $t("plataformaAdmin.operacion.baseCentral") }}
            </dt>
            <dd class="mt-0.5">
              {{
                datos.respaldos.plataforma
                  ? fechaHora(datos.respaldos.plataforma)
                  : $t("plataformaAdmin.operacion.sinRespaldo")
              }}
            </dd>
          </div>
          <div>
            <dt class="op-etiqueta">
              {{ $t("plataformaAdmin.operacion.archivos") }}
            </dt>
            <dd class="mt-0.5">
              {{
                datos.respaldos.archivos
                  ? fechaHora(datos.respaldos.archivos)
                  : $t("plataformaAdmin.operacion.sinRespaldo")
              }}
            </dd>
          </div>
        </dl>

        <div
          class="mt-4 border-t pt-3"
          :style="{ borderColor: 'var(--borde)' }"
          data-prueba="simulacro"
        >
          <h3 class="text-sm font-medium">
            {{ $t("plataformaAdmin.operacion.simulacro") }}
          </h3>
          <p
            v-if="datos.respaldos.simulacro === null"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("plataformaAdmin.operacion.sinSimulacro") }}
          </p>
          <template v-else>
            <p
              class="mt-1 text-sm tu-estado"
              :style="datos.respaldos.simulacro.ok ? GRIS : ERROR"
            >
              {{
                datos.respaldos.simulacro.ok
                  ? $t("plataformaAdmin.operacion.simulacroOk")
                  : $t("plataformaAdmin.operacion.simulacroFallo")
              }}
              ·
              {{ fechaHora(datos.respaldos.simulacro.fecha) }}
            </p>
            <ul class="mt-2 space-y-2">
              <li
                v-for="p in datos.respaldos.simulacro.pruebas"
                :key="p.respaldo"
                class="text-sm"
              >
                <span class="tu-estado" :style="p.ok ? GRIS : ERROR">
                  {{ nombreRespaldo(p.respaldo) }}
                </span>
                <p
                  class="ml-4 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ p.detalle }}
                </p>
                <ul v-if="!p.ok" class="ml-4 mt-1 space-y-0.5">
                  <li
                    v-for="c in p.comprobaciones.filter((x) => !x.ok)"
                    :key="c.nombre"
                    class="text-xs"
                    style="color: var(--error)"
                  >
                    {{ c.nombre }}: {{ c.detalle }}
                  </li>
                </ul>
              </li>
            </ul>
          </template>
        </div>
      </div>

      <!-- Alertas -->
      <div class="tu-card p-5">
        <h2 class="font-medium text-lg">
          {{ $t("plataformaAdmin.operacion.alertas") }}
        </h2>
        <p
          v-if="datos.alertas.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("plataformaAdmin.operacion.sinAlertas") }}
        </p>
        <div v-else class="mt-3 overflow-x-auto">
          <table class="tu-tabla" data-prueba="alertas">
            <thead>
              <tr>
                <th class="pr-3">
                  {{ $t("plataformaAdmin.operacion.colAlerta") }}
                </th>
                <th class="pr-3">
                  {{ $t("plataformaAdmin.operacion.colNegocio") }}
                </th>
                <th class="pr-3 text-right">
                  {{ $t("plataformaAdmin.operacion.colVeces") }}
                </th>
                <th class="pr-3">
                  {{ $t("plataformaAdmin.operacion.colUltima") }}
                </th>
                <th>
                  {{ $t("plataformaAdmin.operacion.colAviso") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in datos.alertas" :key="`${a.tipo}-${a.clave}`">
                <td class="pr-3">
                  <p class="font-medium">{{ tipoAlerta(a.tipo) }}</p>
                  <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                    {{ a.mensaje }}
                  </p>
                </td>
                <td class="pr-3">{{ a.estudio ?? "—" }}</td>
                <td class="pr-3 text-right tabular-nums">{{ a.veces }}</td>
                <td class="pr-3 whitespace-nowrap">
                  {{ fechaHora(a.ultima_en) }}
                </td>
                <td class="whitespace-nowrap">
                  <span v-if="!a.avisada" class="tu-estado" :style="AVISO">
                    {{ $t("plataformaAdmin.operacion.porAvisar") }}
                  </span>
                  <span v-else :style="{ color: 'var(--texto-suave)' }">
                    {{ $t("plataformaAdmin.operacion.avisada") }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.op-etiqueta {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
</style>
