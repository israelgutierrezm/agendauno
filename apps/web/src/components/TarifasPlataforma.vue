<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { confirmar } from "@/lib/confirmar";
import { usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import { dinero } from "@/lib/formato";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { ETIQUETAS_FUNCION, NIVELES_PLAN } from "@/lib/suscripcion";
import { useToastStore } from "@/stores/toast";

/**
 * Tarifas del SaaS por modalidad (superadmin, ADR 0019 y 0107). Muestra la versión
 * vigente de cada modalidad y permite publicar una versión NUEVA (las publicadas no
 * se editan: cada cargo guarda la versión con que se calculó). Precios sin IVA, en la
 * moneda de la tarifa (USD): a los negocios de México se les cobra en pesos al tipo
 * de cambio del día, que también se ve y se captura aquí.
 *
 * - Clases: escalera por alumnos activos, mes vencido.
 * - Citas: precio al mes por nivel y profesionales contratados, por adelantado.
 *
 * Una tarifa a la vez, en pestañas (`?tarifa=citas`); las escaleras van en columnas y
 * el reparto de funciones, plegado, para que la sección no baje varias pantallas. Las
 * versiones publicadas de cada tarifa (las últimas 10) van plegadas al final. Un
 * borrador editado sin publicar pregunta antes de salir.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();
const toast = useToastStore();

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

// La tarifa a la vista, en la dirección. Clave propia: `pestana` es la sección del
// superadmin y `vista`, la del menú.
const modalidad = usePestanaEnUrl<Modalidad>(MODALIDADES, { clave: "tarifa" });
function textoModalidad(m: Modalidad): string {
  return t(`cobro.tarifas.${m}`, {
    marca: PRODUCTOS[productoDeModalidad(m)].nombre,
  });
}
const opcionesModalidad = computed(() =>
  MODALIDADES.map((m) => ({ clave: m, texto: textoModalidad(m) })),
);

const vigentes = ref<Partial<Record<Modalidad, Tarifa | null>>>({});
// Las últimas versiones publicadas de cada tarifa (la más nueva primero).
const historiales = ref<Partial<Record<Modalidad, Tarifa[]>>>({});
const borradores = ref<Partial<Record<Modalidad, Borrador>>>({});
const publicando = ref<Modalidad | null>(null);
// Tras intentar publicar con campos vacíos: se marcan y se explica dentro de su tarjeta.
const incompleta = ref<Partial<Record<Modalidad, boolean>>>({});
const cargando = ref(true);
const errorCarga = ref<string | null>(null);

const banxico = ref(false);
const ultimoCambio = ref<TipoCambio | null>(null);
const nuevoCambio = ref("");
const guardandoCambio = ref(false);
// El error del valor capturado va debajo de su campo.
const errorCambio = ref<string | null>(null);

function cliente() {
  return crearClientePlataforma({
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
function aMinor(valor: string | number): number {
  return Math.round(Number(valor) * 100);
}
// Un campo numérico vacío o que no es un número (antes se publicaba como 0).
function vacio(valor: string | number | null | undefined): boolean {
  const texto = String(valor ?? "").trim();
  return texto === "" || !Number.isFinite(Number(texto)) || Number(texto) < 0;
}
function faltantes(m: Modalidad, b: Borrador): boolean {
  const comunes = [
    b.dias_prueba,
    b.iva_porcentaje,
    b.iva_porcentaje_extranjero,
  ];
  if (m === "clases") {
    const ultimo = b.escalones.length - 1;
    return (
      comunes.some(vacio) ||
      b.escalones.some(
        (e, i) => vacio(e.precio) || (i < ultimo && vacio(e.hasta)),
      )
    );
  }
  return (
    [...comunes, b.meses_anual, b.individual].some(vacio) ||
    b.filas.some((f) => vacio(f.premium) || vacio(f.pro))
  );
}
// Marca el campo vacío solo después de intentar publicar.
function marcar(
  m: Modalidad,
  valor: string | number | null | undefined,
): "true" | undefined {
  return incompleta.value[m] === true && vacio(valor) ? "true" : undefined;
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

/**
 * Las filas de una escalera en columnas (hasta tres, de al menos cinco filas): en
 * escritorio caben a lo ancho en vez de bajar varias pantallas; en el teléfono quedan
 * una debajo de otra. Cada fila conserva su índice real.
 */
function columnas<T>(filas: T[]): { fila: T; i: number }[][] {
  const porColumna = Math.max(5, Math.ceil(filas.length / 3));
  const trozos: { fila: T; i: number }[][] = [];
  for (let inicio = 0; inicio < filas.length; inicio += porColumna) {
    trozos.push(
      filas
        .slice(inicio, inicio + porColumna)
        .map((fila, j) => ({ fila, i: inicio + j })),
    );
  }
  return trozos;
}

// El reparto plegado, en una línea: cuántas funciones abre cada nivel.
function resumenFunciones(b: Borrador): string {
  const elegidos = Object.values(b.funciones);
  const reparto = NIVELES_PLAN.map((n) => ({
    n,
    cuantas: elegidos.filter((v) => v === n).length,
  }))
    .filter((r) => r.cuantas > 0)
    .map((r) => `${t(`suscripcion.niveles.${r.n}`)} ${r.cuantas}`);
  return [t("suscripcion.plataforma.tarifas.funciones"), ...reparto].join(
    " · ",
  );
}

/**
 * `conservar`: la modalidad cuyo borrador se queda como está (tras publicar la otra,
 * no se pierde lo que se editaba en su pestaña).
 */
async function cargar(conservar?: Modalidad): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const { data } = await cliente().get<{
      data: Record<Modalidad, { vigente: Tarifa | null; historial?: Tarifa[] }>;
    }>("/api/v1/plataforma/tarifas");
    for (const m of MODALIDADES) {
      vigentes.value[m] = data.data[m]?.vigente ?? null;
      historiales.value[m] = data.data[m]?.historial ?? [];
      if (m !== conservar || borradores.value[m] === undefined) {
        borradores.value[m] = borradorDe(vigentes.value[m] ?? null);
      }
    }
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
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
    // Si la API falla, ya lo avisan las tarifas (con su «Reintentar»).
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
  incompleta.value[m] = faltantes(m, b);
  if (incompleta.value[m]) {
    await nextTick();
    document
      .querySelector<HTMLElement>(
        `[data-prueba="tarifa-${m}"] [aria-invalid="true"]`,
      )
      ?.focus();
    return;
  }
  // Una versión publicada no se edita ni se borra: se confirma antes.
  if (
    !(await confirmar(
      t("cobro.tarifas.confirmarPublicar", {
        modalidad: t(`cobro.modalidad.${m}`).toLowerCase(),
      }),
      { aceptar: t("cobro.tarifas.publicar") },
    ))
  ) {
    return;
  }
  publicando.value = m;
  try {
    const { data } = await cliente().post<{ data: Tarifa }>(
      `/api/v1/plataforma/tarifas/${m}`,
      cuerpoDe(m, b),
    );
    toast.exito(t("cobro.tarifas.publicada", { n: data.data.version }));
    await cargar(MODALIDADES.find((otra) => otra !== m));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    publicando.value = null;
  }
}

async function guardarCambio(): Promise<void> {
  guardandoCambio.value = true;
  errorCambio.value = null;
  try {
    const { data } = await cliente().put<{
      data: { banxico_configurado: boolean; ultimo: TipoCambio | null };
    }>("/api/v1/plataforma/tipo-cambio", { valor: nuevoCambio.value });
    ultimoCambio.value = data.data.ultimo;
    nuevoCambio.value = "";
    toast.exito(t("suscripcion.plataforma.tipoCambio.guardado"));
  } catch (e) {
    errorCambio.value = mensajeDeError(e);
  } finally {
    guardandoCambio.value = false;
  }
}

function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(a, m - 1, d),
  );
}

// Un borrador es distinto de su versión vigente (los campos numéricos pueden volver
// como número o como texto: se comparan escritos).
function escrito(b: Borrador): string {
  return JSON.stringify(b, (_clave, v: unknown) =>
    typeof v === "number" ? String(v) : v,
  );
}
function sucio(m: Modalidad): boolean {
  const b = borradores.value[m];
  return (
    b !== undefined &&
    escrito(b) !== escrito(borradorDe(vigentes.value[m] ?? null))
  );
}
// Salir con un borrador sin publicar (o un tipo de cambio escrito sin guardar)
// pregunta antes (ADR 0166). Cambiar entre Clases y Citas no: los dos se conservan.
useCambiosPendientes(
  () =>
    !cargando.value &&
    (MODALIDADES.some(sucio) || String(nuevoCambio.value).trim() !== ""),
);

// Una versión publicada, en una línea: sus precios principales.
function resumenVersion(v: Tarifa): string {
  const moneda = v.definicion.moneda ?? "USD";
  const precio = (minor: number): string => dinero(minor, moneda);
  if (v.modalidad === "clases") {
    const montos = (v.definicion.bandas ?? []).map((b) => b.monto_minor);
    if (montos.length === 0) {
      return "";
    }
    return t(
      "cobro.tarifas.resumenClases",
      {
        n: montos.length,
        desde: precio(Math.min(...montos)),
        hasta: precio(Math.max(...montos)),
      },
      montos.length,
    );
  }
  const desde = (nivel: Nivel): string => {
    const montos = Object.values(v.definicion.niveles?.[nivel] ?? {});
    return montos.length > 0 ? precio(Math.min(...montos)) : "—";
  };
  return t("cobro.tarifas.resumenCitas", {
    individual: desde("individual"),
    premium: desde("premium"),
    pro: desde("pro"),
  });
}
// Vigente, programada (empieza más adelante) o anterior.
function estadoVersion(
  m: Modalidad,
  v: Tarifa,
): "vigente" | "programada" | "anterior" {
  if (v.id === vigentes.value[m]?.id) {
    return "vigente";
  }
  return new Date(v.vigente_desde).getTime() > Date.now()
    ? "programada"
    : "anterior";
}
const TONO_VERSION = {
  vigente: "var(--exito)",
  programada: "var(--aviso)",
  anterior: "var(--texto-suave)",
} as const;

function reintentar(): void {
  void cargar();
  void cargarCambio();
}

onMounted(reintentar);
</script>

<template>
  <div>
    <!-- La sección «Tarifas» ya la nombra: sin título propio, solo su ayuda. -->
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("cobro.tarifas.ayuda") }}
    </p>

    <!-- Una tarifa a la vez (en la dirección): antes iban las dos seguidas. -->
    <PestanasSeccion
      v-model="modalidad"
      :opciones="opcionesModalidad"
      :etiqueta="$t('cobro.tarifas.titulo')"
    />

    <p
      v-if="cargando && !vigentes.clases && !vigentes.citas"
      class="mt-4 text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div
      v-else-if="errorCarga"
      class="mt-4 flex flex-wrap items-center gap-3 text-sm"
      role="alert"
      data-prueba="tarifas-error"
    >
      <span style="color: var(--error)">{{ errorCarga }}</span>
      <button type="button" class="tu-btn tu-btn-discreto" @click="reintentar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>
    <template v-else>
      <template v-for="m in MODALIDADES" :key="m">
        <form
          v-if="m === modalidad"
          class="tu-card p-4 mt-4 space-y-4"
          :data-prueba="`tarifa-${m}`"
          :aria-label="textoModalidad(m)"
          @submit.prevent="publicar(m)"
        >
          <!-- La pestaña nombra la tarifa: aquí solo la versión vigente. -->
          <p
            v-if="vigentes[m]"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("cobro.tarifas.version", {
                n: vigentes[m]?.version,
                fecha: fecha(vigentes[m]?.vigente_desde ?? ""),
              })
            }}
          </p>

          <template v-if="borradores[m]">
            <!-- Clases: escalera por alumnos activos, en columnas. -->
            <template v-if="m === 'clases'">
              <div class="tp-columnas tp-compacto">
                <table
                  v-for="(trozo, c) in columnas(borradores[m]!.escalones)"
                  :key="c"
                  class="tu-tabla tp-tabla"
                >
                  <thead>
                    <tr>
                      <th>{{ $t("cobro.tarifas.hasta") }}</th>
                      <th>{{ $t("cobro.tarifas.monto") }}</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="e in trozo" :key="e.i">
                      <td>
                        <span
                          v-if="e.i === borradores[m]!.escalones.length - 1"
                          class="text-xs"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ $t("cobro.tarifas.sinTope") }}</span
                        >
                        <input
                          v-else
                          v-model="e.fila.hasta"
                          class="tu-input"
                          type="number"
                          min="1"
                          required
                          :aria-label="$t('cobro.tarifas.hasta')"
                          :aria-invalid="marcar(m, e.fila.hasta)"
                        />
                      </td>
                      <td>
                        <input
                          v-model="e.fila.precio"
                          class="tu-input"
                          type="number"
                          min="0"
                          step="0.01"
                          required
                          :aria-label="$t('cobro.tarifas.monto')"
                          :aria-invalid="marcar(m, e.fila.precio)"
                        />
                      </td>
                      <td class="text-right">
                        <!-- Un renglón que aún no se publica: discreto. -->
                        <button
                          v-if="borradores[m]!.escalones.length > 1"
                          type="button"
                          class="tu-btn tu-btn-discreto tu-btn-chico"
                          @click="quitar(m, e.i)"
                        >
                          {{ $t("cobro.tarifas.quitar") }}
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico tu-btn-crear"
                @click="agregar(m)"
              >
                {{ $t("cobro.tarifas.agregar") }}
              </button>
            </template>

            <!-- Citas: precio al mes por nivel y profesionales, en columnas. -->
            <template v-else>
              <label class="block sm:max-w-xs">
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
                  required
                  :aria-invalid="marcar(m, borradores[m]!.individual)"
                />
              </label>
              <div class="tp-columnas tp-compacto">
                <table
                  v-for="(trozo, c) in columnas(borradores[m]!.filas)"
                  :key="c"
                  class="tu-tabla tp-tabla"
                >
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
                    <tr v-for="f in trozo" :key="f.i">
                      <td class="font-semibold">{{ f.i + 2 }}</td>
                      <td>
                        <input
                          v-model="f.fila.premium"
                          class="tu-input"
                          type="number"
                          min="0"
                          step="0.01"
                          required
                          :aria-label="`${$t('suscripcion.niveles.premium')} ${f.i + 2}`"
                          :aria-invalid="marcar(m, f.fila.premium)"
                        />
                      </td>
                      <td>
                        <input
                          v-model="f.fila.pro"
                          class="tu-input"
                          type="number"
                          min="0"
                          step="0.01"
                          required
                          :aria-label="`${$t('suscripcion.niveles.pro')} ${f.i + 2}`"
                          :aria-invalid="marcar(m, f.fila.pro)"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario tu-btn-chico tu-btn-crear"
                  @click="agregar(m)"
                >
                  {{ $t("suscripcion.plataforma.tarifas.agregarFila") }}
                </button>
                <button
                  v-if="borradores[m]!.filas.length > 1"
                  type="button"
                  class="tu-btn tu-btn-discreto tu-btn-chico"
                  @click="quitar(m, 0)"
                >
                  {{ $t("suscripcion.plataforma.tarifas.quitarFila") }}
                </button>
              </div>
            </template>

            <!-- Lo común, en una fila: moneda, prueba, IVA (y el anual en citas). -->
            <div
              class="grid grid-cols-2 items-end gap-3 sm:grid-cols-3"
              :class="m === 'citas' ? 'xl:grid-cols-5' : 'xl:grid-cols-4'"
            >
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
                <span class="tu-label">{{
                  $t("cobro.tarifas.diasPrueba")
                }}</span>
                <input
                  v-model.number="borradores[m]!.dias_prueba"
                  class="tu-input"
                  type="number"
                  min="0"
                  required
                  :aria-invalid="marcar(m, borradores[m]!.dias_prueba)"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{ $t("cobro.tarifas.iva") }}</span>
                <input
                  v-model.number="borradores[m]!.iva_porcentaje"
                  class="tu-input"
                  type="number"
                  min="0"
                  required
                  :aria-invalid="marcar(m, borradores[m]!.iva_porcentaje)"
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
                  required
                  :aria-invalid="
                    marcar(m, borradores[m]!.iva_porcentaje_extranjero)
                  "
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
                  required
                  :aria-invalid="marcar(m, borradores[m]!.meses_anual)"
                />
              </label>
            </div>

            <!-- Qué nivel abre cada función: se cambia poco, va plegado con su
                 reparto a la vista. -->
            <details v-if="m === 'citas'" class="tu-avanzado">
              <summary>{{ resumenFunciones(borradores[m]!) }}</summary>
              <fieldset
                class="space-y-3"
                data-prueba="funciones-por-nivel"
                :aria-label="$t('suscripcion.plataforma.tarifas.funciones')"
              >
                <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ $t("suscripcion.plataforma.tarifas.funcionesAyuda") }}
                </p>
                <div
                  class="tp-compacto grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3"
                >
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
                </div>
              </fieldset>
            </details>

            <AvisoTono
              v-if="incompleta[m] && faltantes(m, borradores[m]!)"
              tono="peligro"
              role="alert"
              :data-prueba="`tarifa-${m}-incompleta`"
            >
              {{ $t("cobro.tarifas.incompleta") }}
            </AvisoTono>

            <!-- «Publicar» siempre a la vista al bajar por la tarjeta. -->
            <div class="tu-pie-acciones">
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="publicando === m"
                :aria-busy="publicando === m || undefined"
              >
                {{
                  publicando === m
                    ? $t("cobro.tarifas.publicando")
                    : $t("cobro.tarifas.publicar")
                }}
              </button>
            </div>
          </template>
        </form>
        <!-- Las versiones publicadas: de consulta, plegadas. -->
        <details
          v-if="m === modalidad && (historiales[m]?.length ?? 0) > 0"
          class="tu-avanzado mt-4"
          :data-prueba="`historial-${m}`"
        >
          <summary>
            {{
              $t("cobro.tarifas.historial", { n: historiales[m]?.length ?? 0 })
            }}
          </summary>
          <ul class="tp-historial mt-2">
            <li
              v-for="v in historiales[m]"
              :key="v.id"
              class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2 text-sm"
              :data-version="v.version"
            >
              <span class="min-w-0">
                <span class="block font-medium">{{
                  $t("cobro.tarifas.version", {
                    n: v.version,
                    fecha: fecha(v.vigente_desde),
                  })
                }}</span>
                <span class="tu-sub block">{{ resumenVersion(v) }}</span>
              </span>
              <span
                class="tu-estado text-xs"
                :style="{ '--tono': TONO_VERSION[estadoVersion(m, v)] }"
                >{{ $t(`cobro.tarifas.estados.${estadoVersion(m, v)}`) }}</span
              >
            </li>
          </ul>
        </details>
      </template>
    </template>

    <!-- Tipo de cambio para cobrar en pesos la renta en dólares (ADR 0107): vale para
         las dos tarifas; compacto, con el valor vigente y su captura en una fila. -->
    <form
      class="tu-card p-4 mt-4"
      data-prueba="tipo-cambio"
      @submit.prevent="guardarCambio"
    >
      <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 flex-1 space-y-1">
          <h2 class="tu-titulo-seccion">
            {{ $t("suscripcion.plataforma.tipoCambio.titulo") }}
          </h2>
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
          <p class="text-sm">
            {{
              banxico
                ? $t("suscripcion.plataforma.tipoCambio.banxico")
                : $t("suscripcion.plataforma.tipoCambio.sinBanxico")
            }}
          </p>
        </div>
        <div class="flex items-end gap-2">
          <label class="block">
            <span class="tu-label">{{
              $t("suscripcion.plataforma.tipoCambio.valor")
            }}</span>
            <input
              v-model="nuevoCambio"
              class="tu-input w-36"
              inputmode="decimal"
              placeholder="17.2345"
              :aria-invalid="errorCambio ? 'true' : undefined"
              aria-describedby="tipo-cambio-error"
            />
          </label>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="guardandoCambio || nuevoCambio.trim() === ''"
            :aria-busy="guardandoCambio || undefined"
          >
            {{ $t("suscripcion.plataforma.tipoCambio.guardar") }}
          </button>
        </div>
      </div>
      <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("suscripcion.plataforma.tipoCambio.ayuda") }}
      </p>
      <AvisoTono
        v-if="errorCambio"
        id="tipo-cambio-error"
        class="mt-3"
        tono="peligro"
        role="alert"
      >
        {{ errorCambio }}
      </AvisoTono>
    </form>
  </div>
</template>

<style scoped>
/* Escaleras en columnas: hasta tres a lo ancho; en el teléfono, una debajo de otra. */
.tp-columnas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr));
  column-gap: 1.5rem;
  row-gap: 0.75rem;
  align-items: start;
}
/* Filas más bajas que las de un listado: son muchas y solo llevan campos. */
.tp-tabla th {
  padding: 0.35rem 0.5rem;
}
.tp-tabla td {
  padding: 0.3rem 0.5rem;
}
.tp-tabla th:first-child,
.tp-tabla td:first-child {
  padding-left: 0;
}
.tp-tabla th:last-child,
.tp-tabla td:last-child {
  padding-right: 0;
}
.tp-compacto .tu-input {
  padding-top: 0.35rem;
  padding-bottom: 0.35rem;
}
/* Versiones publicadas: una por renglón, separadas por una línea. */
.tp-historial > li + li {
  border-top: 1px solid var(--borde-fila);
}
</style>
