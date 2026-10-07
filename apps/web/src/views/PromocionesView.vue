<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Promo {
  id: string;
  codigo: string;
  descripcion: string | null;
  tipo: string;
  valor: number;
  monto_minimo_minor: number | null;
  usos_maximos: number | null;
  usos: number;
  vence_en: string | null;
  activa: boolean;
  vigente: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const puedeEliminar = computed(() => sesion.puede("promociones.eliminar"));
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const promos = ref<Promo[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref(false);

const mostrarForm = ref(false);
const editandoId = ref<string | null>(null);
const form = ref({
  codigo: "",
  descripcion: "",
  tipo: "porcentaje",
  valor: 10,
  minimo: "",
  usosMaximos: "",
  vence: "",
  activa: true,
});

// En la moneda del negocio y con los números de su país.
function dinero(minor: number): string {
  return dineroDelPais(minor, sesion.moneda, sesion.pais);
}

function valorLegible(p: Promo): string {
  return p.tipo === "porcentaje" ? `${p.valor / 100}%` : dinero(p.valor);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Promo[] }>(
      `${base.value}/promociones`,
    );
    promos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function nueva(): void {
  editandoId.value = null;
  form.value = {
    codigo: "",
    descripcion: "",
    tipo: "porcentaje",
    valor: 10,
    minimo: "",
    usosMaximos: "",
    vence: "",
    activa: true,
  };
  mostrarForm.value = true;
}

function editar(p: Promo): void {
  editandoId.value = p.id;
  form.value = {
    codigo: p.codigo,
    descripcion: p.descripcion ?? "",
    tipo: p.tipo,
    valor: p.valor / 100,
    minimo:
      p.monto_minimo_minor !== null ? String(p.monto_minimo_minor / 100) : "",
    usosMaximos: p.usos_maximos !== null ? String(p.usos_maximos) : "",
    vence: p.vence_en ?? "",
    activa: p.activa,
  };
  mostrarForm.value = true;
}

async function guardar(): Promise<void> {
  if (form.value.codigo.trim() === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  // % (15) -> 1500 bps; monto ($100) -> 10000 minor: en ambos casos x100.
  const carga = {
    codigo: form.value.codigo,
    descripcion: form.value.descripcion !== "" ? form.value.descripcion : null,
    tipo: form.value.tipo,
    valor: Math.round(Number(form.value.valor) * 100),
    monto_minimo_minor:
      form.value.minimo !== ""
        ? Math.round(Number(form.value.minimo) * 100)
        : null,
    usos_maximos:
      form.value.usosMaximos !== "" ? Number(form.value.usosMaximos) : null,
    vence_en: form.value.vence !== "" ? form.value.vence : null,
    activa: form.value.activa,
  };
  try {
    if (editandoId.value !== null) {
      await api.put(`${base.value}/promociones/${editandoId.value}`, carga);
    } else {
      await api.post(`${base.value}/promociones`, carga);
    }
    mostrarForm.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function eliminar(p: Promo): Promise<void> {
  if (
    !(await confirmar(t("promociones.confirmarEliminar"), { peligro: true }))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/promociones/${p.id}`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

// ---- Indicadores, búsqueda y filtro (patrón de los listados) ----
const busqueda = ref("");
const filtro = ref<"" | "vigentes" | "no_vigentes">("");
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return promos.value.filter(
    (p) =>
      (q === "" ||
        p.codigo.toLowerCase().includes(q) ||
        (p.descripcion ?? "").toLowerCase().includes(q)) &&
      (filtro.value === "" || (filtro.value === "vigentes") === p.vigente),
  );
});
const indicadores = computed<Indicador[]>(() => {
  const vigentes = promos.value.filter((p) => p.vigente);
  const hoy = new Date();
  const enSieteDias = new Date(hoy.getTime() + 7 * 86_400_000);
  const porVencer = vigentes.filter(
    (p) =>
      p.vence_en !== null && new Date(`${p.vence_en}T23:59:59`) <= enSieteDias,
  ).length;
  return [
    {
      clave: "vigentes",
      etiqueta: t("promocionesVisual.kpi.vigentes"),
      valor: String(vigentes.length),
      icono: "promociones",
    },
    {
      clave: "usos",
      etiqueta: t("promocionesVisual.kpi.usos"),
      valor: String(promos.value.reduce((s, p) => s + p.usos, 0)),
      icono: "hecho",
    },
    {
      clave: "porVencer",
      etiqueta: t("promocionesVisual.kpi.porVencer"),
      valor: String(porVencer),
      icono: "reloj",
      aviso: porVencer > 0,
    },
    {
      clave: "noVigentes",
      etiqueta: t("promocionesVisual.kpi.noVigentes"),
      valor: String(promos.value.length - vigentes.length),
      icono: "cerrar",
    },
  ];
});
function usos(p: Promo): string {
  return p.usos_maximos !== null
    ? `${p.usos} / ${p.usos_maximos}`
    : `${p.usos} (${t("promociones.sinLimite")})`;
}
function fechaCorta(iso: string | null): string {
  if (!iso) {
    return t("promociones.sinVence");
  }
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(`${iso}T12:00:00`),
  );
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('promociones.titulo')"
      :subtitulo="$t('promocionesVisual.subtitulo')"
    >
      <template #acciones>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          data-prueba="nueva-promo"
          @click="nueva"
        >
          {{ $t("promociones.nueva") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <!-- Alta / edición (drawer lateral) -->
    <PanelLateral
      :abierto="mostrarForm"
      :titulo="
        editandoId !== null ? $t('promociones.editar') : $t('promociones.nueva')
      "
      @cerrar="mostrarForm = false"
    >
      <form class="grid gap-4" @submit.prevent="guardar">
        <div>
          <label class="tu-label" for="p-codigo">{{
            $t("promociones.campos.codigo")
          }}</label>
          <input
            id="p-codigo"
            v-model="form.codigo"
            class="tu-input uppercase"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="p-tipo">{{
            $t("promociones.campos.tipo")
          }}</label>
          <select id="p-tipo" v-model="form.tipo" class="tu-input">
            <option value="porcentaje">
              {{ $t("promociones.tipos.porcentaje") }}
            </option>
            <option value="monto_fijo">
              {{ $t("promociones.tipos.monto_fijo") }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="p-valor">
            {{
              form.tipo === "porcentaje"
                ? $t("promociones.campos.valorPorcentaje")
                : $t("promociones.campos.valorMonto")
            }}
          </label>
          <input
            id="p-valor"
            v-model.number="form.valor"
            type="number"
            min="1"
            step="0.01"
            class="tu-input"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="p-min">{{
            $t("promociones.campos.minimo")
          }}</label>
          <input
            id="p-min"
            v-model="form.minimo"
            type="number"
            min="0"
            step="0.01"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-usos">{{
            $t("promociones.campos.usosMaximos")
          }}</label>
          <input
            id="p-usos"
            v-model="form.usosMaximos"
            type="number"
            min="1"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-vence">{{
            $t("promociones.campos.vence")
          }}</label>
          <input
            id="p-vence"
            v-model="form.vence"
            type="date"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-desc">{{
            $t("promociones.campos.descripcion")
          }}</label>
          <input id="p-desc" v-model="form.descripcion" class="tu-input" />
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.activa" type="checkbox" />
          {{ $t("promociones.campos.activa") }}
        </label>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="mostrarForm = false"
          >
            {{ $t("promociones.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="accionando || form.codigo.trim() === ''"
            @click="guardar"
          >
            {{ $t("promociones.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <EstadoVacio
      v-else-if="promos.length === 0"
      class="mt-10"
      icono="promociones"
      :titulo="$t('promociones.sinPromos')"
    />

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

      <div class="relative tu-card mt-5 overflow-x-auto">
        <div class="tu-filtros">
          <label class="tu-buscar">
            <IconoNav nombre="buscar" :tam="16" />
            <input
              v-model="busqueda"
              type="search"
              class="tu-input"
              :placeholder="$t('promocionesVisual.buscar')"
              :aria-label="$t('promocionesVisual.buscar')"
              data-prueba="buscar-promo"
            />
          </label>
          <div class="tu-segmentado" role="group">
            <button
              v-for="f in ['', 'vigentes', 'no_vigentes'] as const"
              :key="f"
              type="button"
              :aria-pressed="filtro === f"
              @click="filtro = f"
            >
              {{ $t(`promocionesVisual.filtro.${f || "todas"}`) }}
            </button>
          </div>
        </div>
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>{{ $t("promocionesVisual.col.codigo") }}</th>
              <th>{{ $t("promocionesVisual.col.descuento") }}</th>
              <th class="hidden sm:table-cell">
                {{ $t("promociones.usos") }}
              </th>
              <th class="hidden md:table-cell">
                {{ $t("promociones.vence") }}
              </th>
              <th>{{ $t("promocionesVisual.col.estado") }}</th>
              <th>
                <span class="sr-only">{{
                  $t("promocionesVisual.col.acciones")
                }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in visibles" :key="p.id" data-prueba="promo">
              <td>
                <span class="font-semibold tracking-wide">{{ p.codigo }}</span>
                <span v-if="p.descripcion" class="tu-sub">{{
                  p.descripcion
                }}</span>
              </td>
              <td class="tabular-nums">
                {{ valorLegible(p) }}
                <span v-if="p.monto_minimo_minor" class="tu-sub">{{
                  $t("promocionesVisual.desde", {
                    monto: dinero(p.monto_minimo_minor),
                  })
                }}</span>
              </td>
              <td class="hidden sm:table-cell tabular-nums">{{ usos(p) }}</td>
              <td
                class="hidden md:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fechaCorta(p.vence_en) }}
              </td>
              <td>
                <span
                  class="tu-pildora"
                  :style="{
                    '--tono': p.vigente ? 'var(--exito)' : 'var(--texto-suave)',
                  }"
                  >{{
                    p.vigente
                      ? $t("promociones.vigente")
                      : $t("promociones.noVigente")
                  }}</span
                >
              </td>
              <td class="text-right whitespace-nowrap">
                <button
                  class="tu-enlace text-sm"
                  type="button"
                  :disabled="accionando"
                  @click="editar(p)"
                >
                  {{ $t("promociones.editar") }}
                </button>
                <button
                  v-if="puedeEliminar"
                  class="tu-enlace text-sm ml-4"
                  style="color: var(--error)"
                  type="button"
                  :disabled="accionando"
                  @click="eliminar(p)"
                >
                  {{ $t("promociones.eliminar") }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="visibles.length === 0" class="tu-sin-resultados">
          {{ $t("promocionesVisual.sinResultados") }}
        </p>
      </div>
    </template>
  </section>
</template>
