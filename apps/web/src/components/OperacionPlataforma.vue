<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { mensajeDeError } from "@/lib/api";
import { usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import { useToastStore } from "@/stores/toast";

/**
 * Operación de la plataforma, para el superadmin: lo mismo que dicen la consola
 * (agendauno:verificar-produccion) y los correos de alertas. Arriba, una franja con
 * el estado de todo (servicio, programador, cola, respaldos, verificación y alertas);
 * debajo, el detalle en tres pestañas (`?operacion_ver=`): la verificación completa
 * (lo que falla primero, por sección y en dos columnas; lo que está en orden, plegado),
 * los respaldos con el último simulacro y las alertas recientes por páginas. Solo lee.
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
  // Atendida y si pide algo a alguien (ADR 0216).
  atendida_en?: string | null;
  atendida_por?: string | null;
  pide_atencion?: boolean;
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
    // El peor estado de los puntos de respaldos de la verificación (lo juzga el API).
    estado: Punto["estado"];
    plataforma: string | null;
    archivos: string | null;
    simulacro: {
      fecha: string;
      ok: boolean;
      pruebas: Prueba[];
    } | null;
  };
  // Las más recientes (hasta 50); los conteos son de todo el historial.
  alertas: Alerta[];
  alertas_total: number;
  alertas_por_avisar: number;
  alertas_sin_atender?: number;
  revisado_en: string;
}

const { t, te } = useI18n();
const cliente = crearClientePlataforma({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const toast = useToastStore();
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

// Marcar atendida una alerta que pide algo (ADR 0216).
const atendiendo = ref<string | null>(null);
async function atender(a: Alerta): Promise<void> {
  atendiendo.value = `${a.tipo}-${a.clave}`;
  try {
    const { data } = await cliente.post<{
      data: { atendida_en: string; atendida_por: string };
    }>(
      "/api/v1/plataforma/alertas/atender",
      { tipo: a.tipo, clave: a.clave },
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    a.atendida_en = data.data.atendida_en;
    a.atendida_por = data.data.atendida_por;
    if (datos.value && (datos.value.alertas_sin_atender ?? 0) > 0) {
      datos.value.alertas_sin_atender =
        (datos.value.alertas_sin_atender ?? 1) - 1;
    }
    toast.exito(t("plataformaAdmin.operacion.atendidaOk"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    atendiendo.value = null;
  }
}

// Segundo nivel, en la dirección. `pestana` ya es la sección del superadmin y
// `vista` la del menú: esta lleva su propia clave.
const VISTAS = ["verificacion", "respaldos", "alertas"] as const;
type Vista = (typeof VISTAS)[number];
const ver = usePestanaEnUrl<Vista>(VISTAS, { clave: "operacion_ver" });
const opcionesVer = computed(() =>
  VISTAS.map((clave) => ({
    clave,
    texto: t(`plataformaAdmin.operacion.pestanas.${clave}`),
  })),
);

// Lo que falla va primero y a la vista (lo que falta, luego los avisos); lo que
// está en orden, plegado. Los dos, agrupados por sección.
const problemas = computed(() => {
  const puntos = datos.value?.verificacion ?? [];
  return [
    ...puntos.filter((p) => p.estado === "falta"),
    ...puntos.filter((p) => p.estado === "aviso"),
  ];
});
const faltan = computed(
  () => problemas.value.filter((p) => p.estado === "falta").length,
);
const avisos = computed(
  () => problemas.value.filter((p) => p.estado === "aviso").length,
);
const enOrden = computed(
  () => datos.value?.verificacion.filter((p) => p.estado === "ok") ?? [],
);
// Por sección, en el orden de su primer punto: con `problemas` (ya ordenados), las
// secciones con algo que falta van primero y, dentro de cada una, lo que falta y luego
// los avisos; con lo que está en orden, en el orden en que llega.
function porSeccion(puntos: Punto[]): { nombre: string; puntos: Punto[] }[] {
  const grupos = new Map<string, Punto[]>();
  for (const p of puntos) {
    grupos.set(p.seccion, [...(grupos.get(p.seccion) ?? []), p]);
  }
  return [...grupos.entries()].map(([nombre, puntos]) => ({ nombre, puntos }));
}
const seccionesProblemas = computed(() => porSeccion(problemas.value));
const seccionesEnOrden = computed(() => porSeccion(enOrden.value));

// Simulacro: las pruebas que fallaron a la vista; las que pasaron, plegadas.
const pruebasFallidas = computed(
  () => datos.value?.respaldos.simulacro?.pruebas.filter((p) => !p.ok) ?? [],
);
const pruebasOk = computed(
  () => datos.value?.respaldos.simulacro?.pruebas.filter((p) => p.ok) ?? [],
);

// Alertas por enviar de todo el historial (no solo de las que llegan en la lista).
const alertasPorEnviar = computed(() => datos.value?.alertas_por_avisar ?? 0);
// La lista llega recortada a las más recientes: se dice cuando faltan algunas.
const alertasRecortadas = computed(
  () =>
    datos.value !== null &&
    datos.value.alertas_total > datos.value.alertas.length,
);
// Llegan de la más reciente a la más vieja.
const ultimaAlerta = computed(() => datos.value?.alertas[0] ?? null);

// Alertas por páginas (llegan las 50 más recientes, la más reciente primero).
const POR_PAGINA_ALERTAS = 10;
const paginaAlertas = ref(1);
const paginasAlertas = computed(() =>
  Math.max(
    1,
    Math.ceil((datos.value?.alertas.length ?? 0) / POR_PAGINA_ALERTAS),
  ),
);
// Al actualizar puede haber menos alertas: nunca una página vacía.
const paginaAlertasSegura = computed(() =>
  Math.min(paginaAlertas.value, paginasAlertas.value),
);
const alertasPagina = computed(() =>
  (datos.value?.alertas ?? []).slice(
    (paginaAlertasSegura.value - 1) * POR_PAGINA_ALERTAS,
    paginaAlertasSegura.value * POR_PAGINA_ALERTAS,
  ),
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
        class="tu-btn tu-btn-secundario"
        :disabled="cargando"
        :aria-busy="cargando || undefined"
        @click="cargar"
      >
        {{ $t("plataformaAdmin.operacion.actualizar") }}
      </button>
    </div>

    <AvisoTono v-if="error" tono="peligro" role="alert">
      {{ error }}
    </AvisoTono>
    <p
      v-else-if="cargando && datos === null"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plataformaAdmin.operacion.cargando") }}
    </p>

    <template v-if="datos">
      <!-- El estado de todo en una franja: punto + texto, lo que más se mira primero -->
      <div
        class="tu-card grid grid-cols-2 gap-x-4 gap-y-3 p-4 sm:grid-cols-4"
        data-prueba="franja"
      >
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.servicio") }}
          </p>
          <p
            class="mt-1 text-sm tu-estado"
            :style="datos.mantenimiento ? AVISO : GRIS"
          >
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
            class="mt-1 text-sm tu-estado"
            :style="tonoProceso(datos.procesos[nombre].estado)"
          >
            {{
              $t(
                `plataformaAdmin.operacion.procesos.${datos.procesos[nombre].estado}`,
              )
            }}
          </p>
          <p class="op-sub">
            {{
              $t("plataformaAdmin.operacion.ultimoLatido", {
                cuando: fechaHora(datos.procesos[nombre].ultimo),
              })
            }}
          </p>
        </div>
        <!-- El tono, del mismo juicio que la verificación (antigüedad, simulacro
             vigente, copias fuera, cifrado), no solo de si hay o no respaldo -->
        <div data-prueba="franja-respaldos">
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.respaldos") }}
          </p>
          <p
            class="mt-1 text-sm tu-estado"
            :style="tonoPunto(datos.respaldos.estado)"
          >
            {{
              datos.respaldos.plataforma === null ||
              datos.respaldos.archivos === null
                ? $t("plataformaAdmin.operacion.faltaRespaldo")
                : fechaHora(datos.respaldos.plataforma)
            }}
          </p>
          <p class="op-sub">
            {{
              datos.respaldos.simulacro === null
                ? $t("plataformaAdmin.operacion.sinSimulacroCorto")
                : $t("plataformaAdmin.operacion.simulacroCorto", {
                    estado: datos.respaldos.simulacro.ok
                      ? $t("plataformaAdmin.operacion.simulacroOk")
                      : $t("plataformaAdmin.operacion.simulacroFallo"),
                  })
            }}
          </p>
        </div>
        <div data-prueba="franja-verificacion">
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.pestanas.verificacion") }}
          </p>
          <p
            class="mt-1 text-sm tu-estado"
            :style="faltan > 0 ? ERROR : avisos > 0 ? AVISO : GRIS"
          >
            {{
              faltan > 0
                ? $t("plataformaAdmin.operacion.porResolver", faltan)
                : avisos > 0
                  ? $t("plataformaAdmin.operacion.avisos", avisos)
                  : $t("plataformaAdmin.operacion.enOrden")
            }}
          </p>
          <p v-if="faltan > 0 && avisos > 0" class="op-sub">
            {{ $t("plataformaAdmin.operacion.avisos", avisos) }}
          </p>
        </div>
        <div data-prueba="franja-alertas">
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.pestanas.alertas") }}
          </p>
          <p
            class="mt-1 text-sm tu-estado"
            :style="alertasPorEnviar > 0 ? AVISO : GRIS"
          >
            {{
              $t("plataformaAdmin.operacion.alertasCuenta", datos.alertas_total)
            }}
          </p>
          <p v-if="ultimaAlerta" class="op-sub">
            {{
              $t("plataformaAdmin.operacion.ultimaAlerta", {
                cuando: fechaHora(ultimaAlerta.ultima_en),
              })
            }}
          </p>
        </div>
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.version") }}
          </p>
          <p class="mt-1 text-sm font-semibold tabular-nums">
            {{ datos.version }}
          </p>
        </div>
        <div>
          <p class="op-etiqueta">
            {{ $t("plataformaAdmin.operacion.entorno") }}
          </p>
          <p class="mt-1 text-sm font-semibold" data-prueba="entorno">
            {{ entorno(datos.entorno) }}
          </p>
        </div>
      </div>

      <PestanasSeccion
        v-model="ver"
        :opciones="opcionesVer"
        :etiqueta="$t('plataformaAdmin.operacion.pestanas.etiqueta')"
      />

      <!-- Verificación de producción: lo que falla, a la vista -->
      <div
        v-if="ver === 'verificacion'"
        class="tu-card p-5"
        data-prueba="panel-verificacion"
      >
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
        <!-- Por sección (su nombre como título, ya no en cada renglón) y en dos
             columnas desde lg; cada punto en un párrafo: el nombre y, a continuación,
             el estado y qué hacer -->
        <div
          v-if="problemas.length > 0"
          class="op-columnas-problemas mt-3"
          data-prueba="por-resolver"
        >
          <div
            v-for="s in seccionesProblemas"
            :key="s.nombre"
            class="op-grupo"
            data-prueba="seccion-por-resolver"
          >
            <h3 class="text-sm font-medium">{{ s.nombre }}</h3>
            <ul class="mt-0.5">
              <li
                v-for="(p, i) in s.puntos"
                :key="`${p.punto}-${i}`"
                class="op-problema tu-estado"
                :style="tonoPunto(p.estado)"
              >
                <p>
                  {{ p.punto }}
                  <span class="op-detalle">
                    {{ $t(`plataformaAdmin.operacion.estados.${p.estado}`) }}
                    <template v-if="p.detalle">· {{ p.detalle }}</template>
                  </span>
                </p>
              </li>
            </ul>
          </div>
        </div>

        <!-- Lo que está en orden: de consulta, plegado y por sección -->
        <details
          v-if="enOrden.length > 0"
          class="tu-avanzado mt-4"
          data-prueba="puntos-en-orden"
        >
          <summary>
            {{
              $t("plataformaAdmin.operacion.puntosEnOrden", {
                n: enOrden.length,
              })
            }}
          </summary>
          <div class="op-columnas">
            <div v-for="s in seccionesEnOrden" :key="s.nombre" class="op-grupo">
              <h3 class="text-sm font-medium">{{ s.nombre }}</h3>
              <ul class="mt-1 space-y-1">
                <li
                  v-for="(p, i) in s.puntos"
                  :key="`${p.punto}-${i}`"
                  class="text-sm"
                >
                  <span class="tu-estado" :style="GRIS">{{ p.punto }}</span>
                </li>
              </ul>
            </div>
          </div>
        </details>
      </div>

      <!-- Respaldos y simulacro -->
      <div
        v-else-if="ver === 'respaldos'"
        class="tu-card p-5"
        data-prueba="panel-respaldos"
      >
        <dl class="grid gap-3 sm:grid-cols-2 text-sm">
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
            <!-- Las que fallaron, a la vista y solo con lo que falló -->
            <ul v-if="pruebasFallidas.length > 0" class="mt-2 space-y-2">
              <li
                v-for="p in pruebasFallidas"
                :key="p.respaldo"
                class="text-sm"
              >
                <span class="tu-estado" :style="ERROR">
                  {{ nombreRespaldo(p.respaldo) }}
                </span>
                <p class="op-sub ml-4">{{ p.detalle }}</p>
                <ul class="ml-4 mt-1 space-y-0.5">
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
            <!-- Las que pasaron, plegadas -->
            <details
              v-if="pruebasOk.length > 0"
              class="tu-avanzado mt-3"
              data-prueba="pruebas-ok"
            >
              <summary>
                {{
                  $t("plataformaAdmin.operacion.pruebasOk", {
                    n: pruebasOk.length,
                  })
                }}
              </summary>
              <ul class="space-y-2">
                <li v-for="p in pruebasOk" :key="p.respaldo" class="text-sm">
                  <span class="tu-estado" :style="GRIS">
                    {{ nombreRespaldo(p.respaldo) }}
                  </span>
                  <p v-if="p.detalle" class="op-sub ml-4">{{ p.detalle }}</p>
                </li>
              </ul>
            </details>
          </template>
        </div>
      </div>

      <!-- Alertas, por páginas -->
      <div v-else class="tu-card p-5" data-prueba="panel-alertas">
        <p
          v-if="datos.alertas.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("plataformaAdmin.operacion.sinAlertas") }}
        </p>
        <template v-else>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataformaAdmin.operacion.alertas") }}
          </p>
          <p
            v-if="alertasRecortadas"
            class="op-sub"
            data-prueba="alertas-recortadas"
          >
            {{
              $t("plataformaAdmin.operacion.alertasRecortadas", {
                n: datos.alertas.length,
                total: datos.alertas_total,
              })
            }}
          </p>
          <div class="relative mt-3 overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla op-alertas" data-prueba="alertas">
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
                <tr v-for="a in alertasPagina" :key="`${a.tipo}-${a.clave}`">
                  <td
                    class="pr-3"
                    :data-etiqueta="$t('plataformaAdmin.operacion.colAlerta')"
                  >
                    <span class="font-medium">{{ tipoAlerta(a.tipo) }}</span>
                    <span class="tu-sub op-mensaje" :title="a.mensaje">
                      {{ a.mensaje }}
                    </span>
                  </td>
                  <td
                    class="pr-3"
                    :data-etiqueta="$t('plataformaAdmin.operacion.colNegocio')"
                  >
                    {{ a.estudio ?? "—" }}
                  </td>
                  <td
                    class="pr-3 text-right tabular-nums"
                    :data-etiqueta="$t('plataformaAdmin.operacion.colVeces')"
                  >
                    {{ a.veces }}
                  </td>
                  <td
                    class="pr-3 whitespace-nowrap"
                    :data-etiqueta="$t('plataformaAdmin.operacion.colUltima')"
                  >
                    {{ fechaHora(a.ultima_en) }}
                  </td>
                  <td
                    class="whitespace-nowrap"
                    :data-etiqueta="$t('plataformaAdmin.operacion.colAviso')"
                  >
                    <span v-if="!a.avisada" class="tu-estado" :style="AVISO">
                      {{ $t("plataformaAdmin.operacion.porAvisar") }}
                    </span>
                    <span v-else :style="{ color: 'var(--texto-suave)' }">
                      {{ $t("plataformaAdmin.operacion.avisada") }}
                    </span>
                    <span
                      v-if="a.atendida_en"
                      class="tu-sub block"
                      data-prueba="alerta-atendida"
                    >
                      {{
                        $t("plataformaAdmin.operacion.atendida", {
                          quien: a.atendida_por ?? "—",
                        })
                      }}
                    </span>
                    <button
                      v-else-if="a.pide_atencion"
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico mt-1"
                      :disabled="atendiendo !== null"
                      data-prueba="atender-alerta"
                      @click="atender(a)"
                    >
                      {{ $t("plataformaAdmin.operacion.atender") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginacionListado
            v-if="paginasAlertas > 1"
            class="mt-3"
            :page="paginaAlertasSegura"
            :ultima-pagina="paginasAlertas"
            :total="datos.alertas.length"
            :per-page="POR_PAGINA_ALERTAS"
            data-prueba="paginacion-alertas"
            @ir="paginaAlertas = $event"
          />
        </template>
      </div>
    </template>
  </section>
</template>

<style scoped>
.op-etiqueta {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.op-sub {
  display: block;
  margin-top: 0.125rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
/* Lo que falla: por sección, en dos columnas desde lg (cada sección entera en una). */
.op-columnas-problemas {
  columns: 1;
  column-gap: 2rem;
}
@media (min-width: 1024px) {
  .op-columnas-problemas {
    columns: 2;
  }
}
/* Un punto por resolver: el punto de color a la altura de la primera línea y el
   texto en un párrafo que, al partirse, sigue alineado con el nombre. */
.op-problema {
  display: flex;
  align-items: flex-start;
  padding-block: 0.1875rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
}
.op-problema::before {
  margin-top: 0.4rem;
}
.op-problema > p {
  min-width: 0;
  overflow-wrap: anywhere;
}
.op-detalle {
  margin-left: 0.25rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
/* Lo que está en orden, en columnas para no alargar la pantalla. */
.op-columnas {
  columns: 1;
  column-gap: 2rem;
}
@media (min-width: 640px) {
  .op-columnas {
    columns: 2;
  }
}
@media (min-width: 1024px) {
  .op-columnas {
    columns: 3;
  }
}
.op-grupo {
  break-inside: avoid;
  padding-bottom: 0.75rem;
}
/* Alertas: el mensaje en dos líneas como mucho (completo en el title; un lector de
   pantalla lo lee entero) y renglones más bajos en la tabla de escritorio. */
.op-alertas td {
  line-height: 1.35;
}
.op-mensaje {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
}
@media (min-width: 640px) {
  .op-alertas td {
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
  }
}
</style>
