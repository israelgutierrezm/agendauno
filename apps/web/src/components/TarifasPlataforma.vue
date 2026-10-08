<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { ETIQUETAS_FUNCION, NIVELES_PLAN } from "@/lib/suscripcion";

/**
 * Tarifas del SaaS por modalidad (superadmin, ADR 0019 y 0107). Muestra la versión
 * vigente de cada modalidad y permite publicar una versión NUEVA (las publicadas no
 * se editan: cada cargo guarda la versión con que se calculó). Precios sin IVA, en la
 * moneda de la tarifa (USD): a los negocios de México se les cobra en pesos al tipo
 * de cambio del día, que también se ve y se captura aquí.
 *
 * - Clases: escalera por alumnos activos, mes vencido.
 * - Citas: precio al mes por nivel y profesionales contratados, por adelantado.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();

type Nivel = "individual" | "premium" | "pro";
interface Definicion {
  moneda?: string;
  dias_prueba: number;
  iva_porcentaje: number;
  iva_porcentaje_extranjero?: number;
  meses_anual?: number;
  bandas?: { hasta: number | null; monto_minor: number }[];
  niveles?: Partial<Record<Nivel, Record<string, number>>>;
  /** Qué nivel abre cada función (ADR 0107). */
  funciones?: Record<string, Nivel>;
}
interface Tarifa {
  id: string;
  modalidad: "clases" | "citas";
  version: number;
  vigente_desde: string;
  definicion: Definicion;
}
interface Borrador {
  moneda: string;
  dias_prueba: number;
  iva_porcentaje: number;
  iva_porcentaje_extranjero: number;
  meses_anual: number;
  escalones: { hasta: string; precio: string }[];
  individual: string;
  /** Una fila por profesionales (desde 2): precio de Premium y de Pro. */
  filas: { premium: string; pro: string }[];
  /** Qué nivel abre cada función. */
  funciones: Record<string, Nivel>;
}
interface TipoCambio {
  valor: string;
  fecha: string;
  fuente: string;
}

const MODALIDADES = ["clases", "citas"] as const;
type Modalidad = (typeof MODALIDADES)[number];

const vigentes = ref<Partial<Record<Modalidad, Tarifa | null>>>({});
const borradores = ref<Partial<Record<Modalidad, Borrador>>>({});
const publicando = ref<Modalidad | null>(null);
const aviso = ref<string | null>(null);
const error = ref<string | null>(null);

const banxico = ref(false);
const ultimoCambio = ref<TipoCambio | null>(null);
const nuevoCambio = ref("");
const guardandoCambio = ref(false);
const avisoCambio = ref<string | null>(null);
const errorCambio = ref<string | null>(null);

function cliente() {
  return axios.create({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });
}

function decimal(minor: number): string {
  return String(minor / 100);
}
function aMinor(valor: string): number {
  return Math.round(Number(valor) * 100);
}

function borradorDe(tarifa: Tarifa | null): Borrador {
  const d = tarifa?.definicion;
  const premium = d?.niveles?.premium ?? {};
  const pro = d?.niveles?.pro ?? {};
  const cantidades = Object.keys(premium)
    .map(Number)
    .sort((a, b) => a - b);
  const escalones = (d?.bandas ?? []).map((b) => ({
    hasta: b.hasta === null ? "" : String(b.hasta),
    precio: decimal(b.monto_minor),
  }));
  return {
    moneda: d?.moneda ?? "MXN",
    dias_prueba: d?.dias_prueba ?? 30,
    iva_porcentaje: d?.iva_porcentaje ?? 16,
    iva_porcentaje_extranjero: d?.iva_porcentaje_extranjero ?? 0,
    meses_anual: d?.meses_anual ?? 10,
    escalones: escalones.length > 0 ? escalones : [{ hasta: "", precio: "0" }],
    individual: decimal(d?.niveles?.individual?.["1"] ?? 900),
    filas:
      cantidades.length > 0
        ? cantidades.map((n) => ({
            premium: decimal(premium[String(n)] ?? 0),
            pro: decimal(pro[String(n)] ?? 0),
          }))
        : [{ premium: "0", pro: "0" }],
    funciones: { ...(d?.funciones ?? {}) },
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
      borradores.value[m] = borradorDe(vigentes.value[m] ?? null);
    }
  } catch {
    error.value = t("plataforma.tokenInvalido");
  }
}

async function cargarCambio(): Promise<void> {
  try {
    const { data } = await cliente().get<{
      data: { banxico_configurado: boolean; ultimo: TipoCambio | null };
    }>("/api/v1/plataforma/tipo-cambio");
    banxico.value = data.data.banxico_configurado;
    ultimoCambio.value = data.data.ultimo;
  } catch {
    // El token inválido ya lo avisan las tarifas.
  }
}

function agregar(m: Modalidad): void {
  const b = borradores.value[m];
  if (b === undefined) {
    return;
  }
  if (m === "citas") {
    const ultima = b.filas[b.filas.length - 1] ?? { premium: "0", pro: "0" };
    b.filas.push({ ...ultima });
    return;
  }
  // El nuevo escalón entra antes del techo (el último queda sin tope).
  b.escalones.splice(Math.max(0, b.escalones.length - 1), 0, {
    hasta: "",
    precio: "0",
  });
}
function quitar(m: Modalidad, i: number): void {
  const b = borradores.value[m];
  if (m === "citas") {
    b?.filas.pop();
    return;
  }
  b?.escalones.splice(i, 1);
}

function cuerpoDe(m: Modalidad, b: Borrador): Record<string, unknown> {
  const comun = {
    moneda: b.moneda,
    dias_prueba: b.dias_prueba,
    iva_porcentaje: b.iva_porcentaje,
    iva_porcentaje_extranjero: b.iva_porcentaje_extranjero,
  };
  if (m === "clases") {
    const ultimo = b.escalones.length - 1;
    return {
      ...comun,
      bandas: b.escalones.map((e, i) => ({
        hasta: i === ultimo || e.hasta === "" ? null : Number(e.hasta),
        monto_minor: aMinor(e.precio),
      })),
    };
  }
  const premium: Record<string, number> = {};
  const pro: Record<string, number> = {};
  b.filas.forEach((f, i) => {
    premium[String(i + 2)] = aMinor(f.premium);
    pro[String(i + 2)] = aMinor(f.pro);
  });
  return {
    ...comun,
    meses_anual: b.meses_anual,
    niveles: { individual: { "1": aMinor(b.individual) }, premium, pro },
    funciones: b.funciones,
  };
}

async function publicar(m: Modalidad): Promise<void> {
  const b = borradores.value[m];
  if (b === undefined) {
    return;
  }
  publicando.value = m;
  aviso.value = null;
  error.value = null;
  try {
    const { data } = await cliente().post<{ data: Tarifa }>(
      `/api/v1/plataforma/tarifas/${m}`,
      cuerpoDe(m, b),
    );
    aviso.value = t("cobro.tarifas.publicada", { n: data.data.version });
    await cargar();
  } catch (e) {
    error.value = mensaje(e);
  } finally {
    publicando.value = null;
  }
}

async function guardarCambio(): Promise<void> {
  guardandoCambio.value = true;
  avisoCambio.value = null;
  errorCambio.value = null;
  try {
    const { data } = await cliente().put<{
      data: { banxico_configurado: boolean; ultimo: TipoCambio | null };
    }>("/api/v1/plataforma/tipo-cambio", { valor: nuevoCambio.value });
    ultimoCambio.value = data.data.ultimo;
    nuevoCambio.value = "";
    avisoCambio.value = t("suscripcion.plataforma.tipoCambio.guardado");
  } catch (e) {
    errorCambio.value = mensaje(e);
  } finally {
    guardandoCambio.value = false;
  }
}

function mensaje(e: unknown): string {
  const datos = axios.isAxiosError(e) ? e.response?.data : null;
  const errores = datos?.meta?.errors as Record<string, string[]> | undefined;
  return (
    (errores ? Object.values(errores)[0]?.[0] : null) ??
    datos?.message ??
    t("plataforma.tokenInvalido")
  );
}

function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(a, m - 1, d),
  );
}

onMounted(() => {
  void cargar();
  void cargarCambio();
});
</script>

<template>
  <div>
    <h2 class="font-medium text-lg">{{ $t("cobro.tarifas.titulo") }}</h2>
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("cobro.tarifas.ayuda") }}
    </p>
    <div class="mt-3 grid gap-4 lg:grid-cols-2">
      <form
        v-for="m in MODALIDADES"
        :key="m"
        class="tu-card p-4 space-y-3"
        :data-prueba="`tarifa-${m}`"
        @submit.prevent="publicar(m)"
      >
        <div>
          <h3 class="font-medium">{{ $t(`cobro.tarifas.${m}`) }}</h3>
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
          <!-- Clases: escalera por alumnos activos -->
          <template v-if="m === 'clases'">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>{{ $t("cobro.tarifas.hasta") }}</th>
                  <th>{{ $t("cobro.tarifas.monto") }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(e, i) in borradores[m]!.escalones" :key="i">
                  <td class="pr-2">
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
                  <td class="pr-2">
                    <input
                      v-model="e.precio"
                      class="tu-input"
                      type="number"
                      min="0"
                      step="0.01"
                      :aria-label="$t('cobro.tarifas.monto')"
                    />
                  </td>
                  <td class="text-right">
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
          </template>

          <!-- Citas: precio al mes por nivel y profesionales -->
          <template v-else>
            <label class="block">
              <span class="tu-label">
                {{ $t("suscripcion.niveles.individual") }} ·
                {{ $t("suscripcion.plataforma.tarifas.precioMensual") }}
              </span>
              <input
                v-model="borradores[m]!.individual"
                class="tu-input"
                type="number"
                min="0"
                step="0.01"
              />
            </label>
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("suscripcion.plataforma.tarifas.profesionales") }}
                  </th>
                  <th>{{ $t("suscripcion.niveles.premium") }}</th>
                  <th>{{ $t("suscripcion.niveles.pro") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(f, i) in borradores[m]!.filas" :key="i">
                  <td class="pr-2 font-semibold">{{ i + 2 }}</td>
                  <td class="pr-2">
                    <input
                      v-model="f.premium"
                      class="tu-input"
                      type="number"
                      min="0"
                      step="0.01"
                      :aria-label="`${$t('suscripcion.niveles.premium')} ${i + 2}`"
                    />
                  </td>
                  <td>
                    <input
                      v-model="f.pro"
                      class="tu-input"
                      type="number"
                      min="0"
                      step="0.01"
                      :aria-label="`${$t('suscripcion.niveles.pro')} ${i + 2}`"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="flex gap-4">
              <button
                type="button"
                class="tu-enlace text-sm"
                @click="agregar(m)"
              >
                {{ $t("suscripcion.plataforma.tarifas.agregarFila") }}
              </button>
              <button
                v-if="borradores[m]!.filas.length > 1"
                type="button"
                class="tu-enlace text-sm"
                @click="quitar(m, 0)"
              >
                {{ $t("suscripcion.plataforma.tarifas.quitarFila") }}
              </button>
            </div>
            <fieldset class="space-y-2" data-prueba="funciones-por-nivel">
              <legend class="font-medium text-sm">
                {{ $t("suscripcion.plataforma.tarifas.funciones") }}
              </legend>
              <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("suscripcion.plataforma.tarifas.funcionesAyuda") }}
              </p>
              <label
                v-for="(etiqueta, funcion) in ETIQUETAS_FUNCION"
                :key="funcion"
                class="flex items-center justify-between gap-3 text-sm"
              >
                <span>{{ etiqueta.replace("*", "") }}</span>
                <select
                  v-model="borradores[m]!.funciones[funcion]"
                  class="tu-input w-auto"
                >
                  <option v-for="n in NIVELES_PLAN" :key="n" :value="n">
                    {{ $t(`suscripcion.niveles.${n}`) }}
                  </option>
                </select>
              </label>
            </fieldset>
          </template>

          <div class="grid gap-2 grid-cols-2">
            <label class="block">
              <span class="tu-label">{{
                $t("suscripcion.plataforma.tarifas.moneda")
              }}</span>
              <select v-model="borradores[m]!.moneda" class="tu-input">
                <option value="USD">USD</option>
                <option value="MXN">MXN</option>
              </select>
            </label>
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
            <label class="block">
              <span class="tu-label">{{
                $t("suscripcion.plataforma.tarifas.ivaExtranjero")
              }}</span>
              <input
                v-model.number="borradores[m]!.iva_porcentaje_extranjero"
                class="tu-input"
                type="number"
                min="0"
              />
            </label>
            <label v-if="m === 'citas'" class="block">
              <span class="tu-label">{{
                $t("suscripcion.plataforma.tarifas.mesesAnual")
              }}</span>
              <input
                v-model.number="borradores[m]!.meses_anual"
                class="tu-input"
                type="number"
                min="1"
                max="12"
              />
            </label>
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

    <!-- Tipo de cambio para cobrar en pesos la renta en dólares (ADR 0107) -->
    <form
      class="tu-card p-4 mt-4 space-y-2"
      data-prueba="tipo-cambio"
      @submit.prevent="guardarCambio"
    >
      <h3 class="font-medium">
        {{ $t("suscripcion.plataforma.tipoCambio.titulo") }}
      </h3>
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("suscripcion.plataforma.tipoCambio.ayuda") }}
      </p>
      <p class="text-sm">
        {{
          banxico
            ? $t("suscripcion.plataforma.tipoCambio.banxico")
            : $t("suscripcion.plataforma.tipoCambio.sinBanxico")
        }}
      </p>
      <p class="font-semibold">
        <template v-if="ultimoCambio">
          {{
            $t("suscripcion.plataforma.tipoCambio.ultimo", {
              valor: ultimoCambio.valor,
              fecha: fecha(ultimoCambio.fecha),
            })
          }}
          <span
            class="text-sm font-normal"
            :style="{ color: 'var(--texto-suave)' }"
          >
            ·
            {{
              ultimoCambio.fuente === "banxico"
                ? $t("suscripcion.plataforma.tipoCambio.fuenteBanxico")
                : $t("suscripcion.plataforma.tipoCambio.fuenteManual")
            }}
          </span>
        </template>
        <template v-else>{{
          $t("suscripcion.plataforma.tipoCambio.sinValor")
        }}</template>
      </p>
      <div class="flex items-end gap-2 flex-wrap">
        <label class="block">
          <span class="tu-label">{{
            $t("suscripcion.plataforma.tipoCambio.valor")
          }}</span>
          <input
            v-model="nuevoCambio"
            class="tu-input"
            inputmode="decimal"
            placeholder="17.2345"
          />
        </label>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardandoCambio || nuevoCambio.trim() === ''"
        >
          {{ $t("suscripcion.plataforma.tipoCambio.guardar") }}
        </button>
      </div>
      <p v-if="avisoCambio" class="text-sm" style="color: var(--exito)">
        {{ avisoCambio }}
      </p>
      <p v-if="errorCambio" class="text-sm" style="color: var(--error)">
        {{ errorCambio }}
      </p>
    </form>
  </div>
</template>
