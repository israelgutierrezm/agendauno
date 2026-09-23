<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Bitácora del estudio: los cambios sensibles (quién, qué y el antes/después) y
 * los accesos registrados en recepción (entradas permitidas o denegadas).
 */
const { t, te } = useI18n();

type Valor = string | number | boolean | null;
interface Cambio {
  id: string;
  actor: string | null;
  accion: string;
  entidad_tipo: string;
  motivo: string | null;
  antes: Record<string, Valor> | null;
  despues: Record<string, Valor> | null;
  fecha: string | null;
}
interface Acceso {
  id: string;
  persona: string | null;
  metodo: string;
  permitido: boolean;
  codigo: string;
  registrado_en: string;
}
type Pestana = "cambios" | "accesos";

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeCambios = computed(() => sesion.puede("auditoria.ver"));
const puedeAccesos = computed(() => sesion.puede("checkins.registrar"));

const pestana = ref<Pestana>(puedeCambios.value ? "cambios" : "accesos");
const cambios = ref<Cambio[]>([]);
const accesos = ref<Acceso[]>([]);
const abierto = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

function accion(c: Cambio): string {
  const clave = `bitacora.acciones.${c.accion}`;
  return te(clave) ? t(clave) : c.accion;
}

/** Campos que cambiaron, con su valor antes y después. */
function diferencias(
  c: Cambio,
): { campo: string; antes: string; despues: string }[] {
  const campos = new Set([
    ...Object.keys(c.antes ?? {}),
    ...Object.keys(c.despues ?? {}),
  ]);
  const texto = (v: Valor | undefined): string =>
    v === undefined || v === null || v === "" ? "—" : String(v);
  return [...campos]
    .filter((k) => (c.antes ?? {})[k] !== (c.despues ?? {})[k])
    .map((k) => ({
      campo: k,
      antes: texto((c.antes ?? {})[k]),
      despues: texto((c.despues ?? {})[k]),
    }));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    if (pestana.value === "cambios") {
      const { data } = await api.get<{ data: Cambio[] }>(
        `${base.value}/auditorias`,
      );
      cambios.value = data.data;
    } else {
      const { data } = await api.get<{ data: Acceso[] }>(
        `${base.value}/accesos`,
      );
      accesos.value = data.data;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function irPestana(p: Pestana): void {
  pestana.value = p;
  void cargar();
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('bitacora.titulo')" />

    <div
      v-if="puedeCambios && puedeAccesos"
      class="tu-segmentado mt-6"
      role="group"
    >
      <button
        type="button"
        :aria-pressed="pestana === 'cambios'"
        @click="irPestana('cambios')"
      >
        {{ $t("bitacora.cambios") }}
      </button>
      <button
        type="button"
        :aria-pressed="pestana === 'accesos'"
        @click="irPestana('accesos')"
      >
        {{ $t("bitacora.accesos") }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div class="mt-5 tu-card p-5">
      <p
        v-if="cargando"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>

      <!-- Cambios -->
      <template v-else-if="pestana === 'cambios'">
        <p
          v-if="cambios.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("bitacora.sinCambios") }}
        </p>
        <ul v-else>
          <li v-for="c in cambios" :key="c.id" class="bi-fila text-sm">
            <div class="flex w-full items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-medium">{{ accion(c) }}</p>
                <p
                  class="mt-0.5 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ fecha(c.fecha) }} ·
                  {{
                    $t("bitacora.por", {
                      actor: c.actor ?? $t("bitacora.sistema"),
                    })
                  }}
                  <template v-if="c.motivo"> · {{ c.motivo }}</template>
                </p>
              </div>
              <button
                v-if="diferencias(c).length > 0"
                type="button"
                class="tu-enlace shrink-0"
                :aria-expanded="abierto === c.id"
                @click="abierto = abierto === c.id ? null : c.id"
              >
                {{ $t("bitacora.detalle") }}
              </button>
            </div>
            <table
              v-if="abierto === c.id"
              class="mt-3 w-full rounded-xl text-xs"
              :style="{ background: 'var(--fondo)' }"
            >
              <thead>
                <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                  <th class="px-3 py-2 font-medium"></th>
                  <th class="px-3 py-2 font-medium">
                    {{ $t("bitacora.antes") }}
                  </th>
                  <th class="px-3 py-2 font-medium">
                    {{ $t("bitacora.despues") }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="d in diferencias(c)"
                  :key="d.campo"
                  class="border-t"
                  :style="{ borderColor: 'var(--borde)' }"
                >
                  <td class="px-3 py-2 font-medium">{{ d.campo }}</td>
                  <td class="px-3 py-2">{{ d.antes }}</td>
                  <td class="px-3 py-2">{{ d.despues }}</td>
                </tr>
              </tbody>
            </table>
          </li>
        </ul>
      </template>

      <!-- Accesos -->
      <template v-else>
        <p
          v-if="accesos.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("bitacora.sinAccesos") }}
        </p>
        <ul v-else>
          <li v-for="a in accesos" :key="a.id" class="bi-fila text-sm">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ a.persona ?? "—" }}</p>
              <p
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fecha(a.registrado_en) }} ·
                {{ $t(`bitacora.metodos.${a.metodo}`) }} ·
                {{ $t(`accesoRecepcion.codigos.${a.codigo}`) }}
              </p>
            </div>
            <span
              class="tu-badge shrink-0"
              :class="a.permitido ? 'tu-badge-exito' : 'tu-badge-aviso'"
              >{{
                a.permitido
                  ? $t("accesoRecepcion.permitido")
                  : $t("accesoRecepcion.denegado")
              }}</span
            >
          </li>
        </ul>
      </template>
    </div>
  </section>
</template>

<style scoped>
.bi-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.bi-fila:first-child {
  border-top: 0;
}
</style>
