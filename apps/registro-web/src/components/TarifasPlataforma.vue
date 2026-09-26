<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Tarifas del SaaS por modalidad (superadmin). Muestra la versión vigente de cada
 * modalidad y permite publicar una versión NUEVA (las publicadas no se editan: cada
 * cargo guarda la versión con que se calculó). Precios en pesos, sin IVA.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();

interface Definicion {
  dias_prueba: number;
  iva_porcentaje: number;
  bandas?: { hasta: number | null; monto_minor: number }[];
  tramos?: { hasta: number | null; unitario_minor: number }[];
  personas_incluidas_por_profesional?: number;
  tope_personas_incluidas?: number;
  extra_por_persona_minor?: number;
  horas_medio_tiempo?: number;
}
interface Tarifa {
  id: string;
  modalidad: "clases" | "citas";
  version: number;
  vigente_desde: string;
  definicion: Definicion;
}
interface Borrador {
  dias_prueba: number;
  iva_porcentaje: number;
  escalones: { hasta: string; precio: string }[];
  incluidas: number;
  tope: number;
  extra: string;
  horas: number;
}

const MODALIDADES = ["clases", "citas"] as const;
type Modalidad = (typeof MODALIDADES)[number];

const vigentes = ref<Partial<Record<Modalidad, Tarifa | null>>>({});
const borradores = ref<Partial<Record<Modalidad, Borrador>>>({});
const publicando = ref<Modalidad | null>(null);
const aviso = ref<string | null>(null);
const error = ref<string | null>(null);

function cliente() {
  return axios.create({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });
}

function pesos(minor: number): string {
  return String(minor / 100);
}
function aMinor(valor: string): number {
  return Math.round(Number(valor) * 100);
}

function borradorDe(tarifa: Tarifa | null, modalidad: Modalidad): Borrador {
  const d = tarifa?.definicion;
  const escalones =
    modalidad === "clases"
      ? (d?.bandas ?? []).map((b) => ({
          hasta: b.hasta === null ? "" : String(b.hasta),
          precio: pesos(b.monto_minor),
        }))
      : (d?.tramos ?? []).map((b) => ({
          hasta: b.hasta === null ? "" : String(b.hasta),
          precio: pesos(b.unitario_minor),
        }));
  return {
    dias_prueba: d?.dias_prueba ?? 30,
    iva_porcentaje: d?.iva_porcentaje ?? 16,
    escalones: escalones.length > 0 ? escalones : [{ hasta: "", precio: "0" }],
    incluidas: d?.personas_incluidas_por_profesional ?? 10,
    tope: d?.tope_personas_incluidas ?? 100,
    extra: pesos(d?.extra_por_persona_minor ?? 900),
    horas: d?.horas_medio_tiempo ?? 20,
  };
}

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await cliente().get<{
      data: Record<Modalidad, { vigente: Tarifa | null }>;
    }>("/api/v1/plataforma/tarifas");
    for (const m of MODALIDADES) {
      vigentes.value[m] = data.data[m]?.vigente ?? null;
      borradores.value[m] = borradorDe(vigentes.value[m] ?? null, m);
    }
  } catch {
    error.value = t("plataforma.tokenInvalido");
  }
}

function agregar(m: Modalidad): void {
  const b = borradores.value[m];
  if (b === undefined) {
    return;
  }
  // El nuevo escalón entra antes del techo (el último queda sin tope).
  b.escalones.splice(Math.max(0, b.escalones.length - 1), 0, {
    hasta: "",
    precio: "0",
  });
}
function quitar(m: Modalidad, i: number): void {
  borradores.value[m]?.escalones.splice(i, 1);
}

async function publicar(m: Modalidad): Promise<void> {
  const b = borradores.value[m];
  if (b === undefined) {
    return;
  }
  publicando.value = m;
  aviso.value = null;
  error.value = null;
  const ultimo = b.escalones.length - 1;
  const escalones = b.escalones.map((e, i) => {
    const hasta = i === ultimo || e.hasta === "" ? null : Number(e.hasta);
    return m === "clases"
      ? { hasta, monto_minor: aMinor(e.precio) }
      : { hasta, unitario_minor: aMinor(e.precio) };
  });
  const cuerpo =
    m === "clases"
      ? {
          dias_prueba: b.dias_prueba,
          iva_porcentaje: b.iva_porcentaje,
          bandas: escalones,
        }
      : {
          dias_prueba: b.dias_prueba,
          iva_porcentaje: b.iva_porcentaje,
          tramos: escalones,
          personas_incluidas_por_profesional: b.incluidas,
          tope_personas_incluidas: b.tope,
          extra_por_persona_minor: aMinor(b.extra),
          horas_medio_tiempo: b.horas,
        };
  try {
    const { data } = await cliente().post<{ data: Tarifa }>(
      `/api/v1/plataforma/tarifas/${m}`,
      cuerpo,
    );
    aviso.value = t("cobro.tarifas.publicada", { n: data.data.version });
    await cargar();
  } catch (e) {
    const datos = axios.isAxiosError(e) ? e.response?.data : null;
    const errores = datos?.meta?.errors as Record<string, string[]> | undefined;
    error.value =
      (errores ? Object.values(errores)[0]?.[0] : null) ??
      datos?.message ??
      t("plataforma.tokenInvalido");
  } finally {
    publicando.value = null;
  }
}

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(iso),
  );
}

onMounted(cargar);
</script>

<template>
  <div>
    <h2 class="font-light text-lg">{{ $t("cobro.tarifas.titulo") }}</h2>
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("cobro.tarifas.ayuda") }}
    </p>
    <div class="mt-3 grid gap-4 lg:grid-cols-2">
      <form
        v-for="m in MODALIDADES"
        :key="m"
        class="tu-card p-4 space-y-3"
        @submit.prevent="publicar(m)"
      >
        <div>
          <h3 class="font-light">{{ $t(`cobro.tarifas.${m}`) }}</h3>
          <p
            v-if="vigentes[m]"
            class="text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("cobro.tarifas.version", {
                n: vigentes[m]?.version,
                fecha: fecha(vigentes[m]?.vigente_desde ?? ""),
              })
            }}
          </p>
        </div>

        <template v-if="borradores[m]">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                <th class="py-1 font-medium">
                  {{ $t("cobro.tarifas.hasta") }}
                </th>
                <th class="py-1 font-medium">
                  {{
                    m === "clases"
                      ? $t("cobro.tarifas.monto")
                      : $t("cobro.tarifas.unitario")
                  }}
                </th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in borradores[m]!.escalones" :key="i">
                <td class="py-1 pr-2">
                  <span
                    v-if="i === borradores[m]!.escalones.length - 1"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("cobro.tarifas.sinTope") }}</span
                  >
                  <input
                    v-else
                    v-model="e.hasta"
                    class="tu-input"
                    type="number"
                    min="1"
                    :aria-label="$t('cobro.tarifas.hasta')"
                  />
                </td>
                <td class="py-1 pr-2">
                  <input
                    v-model="e.precio"
                    class="tu-input"
                    type="number"
                    min="0"
                    step="0.01"
                    :aria-label="
                      m === 'clases'
                        ? $t('cobro.tarifas.monto')
                        : $t('cobro.tarifas.unitario')
                    "
                  />
                </td>
                <td class="py-1 text-right">
                  <button
                    v-if="borradores[m]!.escalones.length > 1"
                    type="button"
                    class="tu-enlace text-xs"
                    @click="quitar(m, i)"
                  >
                    {{ $t("cobro.tarifas.quitar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="tu-enlace text-sm" @click="agregar(m)">
            {{ $t("cobro.tarifas.agregar") }}
          </button>

          <div class="grid gap-2 grid-cols-2">
            <label class="block">
              <span class="tu-label">{{ $t("cobro.tarifas.diasPrueba") }}</span>
              <input
                v-model.number="borradores[m]!.dias_prueba"
                class="tu-input"
                type="number"
                min="0"
              />
            </label>
            <label class="block">
              <span class="tu-label">{{ $t("cobro.tarifas.iva") }}</span>
              <input
                v-model.number="borradores[m]!.iva_porcentaje"
                class="tu-input"
                type="number"
                min="0"
              />
            </label>
            <template v-if="m === 'citas'">
              <label class="block">
                <span class="tu-label">{{
                  $t("cobro.tarifas.incluidas")
                }}</span>
                <input
                  v-model.number="borradores[m]!.incluidas"
                  class="tu-input"
                  type="number"
                  min="0"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{ $t("cobro.tarifas.tope") }}</span>
                <input
                  v-model.number="borradores[m]!.tope"
                  class="tu-input"
                  type="number"
                  min="0"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{ $t("cobro.tarifas.extra") }}</span>
                <input
                  v-model="borradores[m]!.extra"
                  class="tu-input"
                  type="number"
                  min="0"
                  step="0.01"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{
                  $t("cobro.tarifas.medioTiempo")
                }}</span>
                <input
                  v-model.number="borradores[m]!.horas"
                  class="tu-input"
                  type="number"
                  min="1"
                />
              </label>
            </template>
          </div>

          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="publicando === m"
          >
            {{
              publicando === m
                ? $t("cobro.tarifas.publicando")
                : $t("cobro.tarifas.publicar")
            }}
          </button>
        </template>
      </form>
    </div>
    <p v-if="aviso" class="mt-2 text-sm" style="color: var(--exito)">
      {{ aviso }}
    </p>
    <p v-if="error" class="mt-2 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
  </div>
</template>
