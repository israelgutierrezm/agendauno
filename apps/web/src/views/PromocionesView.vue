<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
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
// El de la carga, aparte del de borrar: sin lista no es «aún no hay promociones».
const errorCarga = ref<string | null>(null);
const accionando = ref(false);

const mostrarForm = ref(false);
const editandoId = ref<string | null>(null);
// El código con que se abrió (el título dice cuál se edita, aunque se cambie).
const editandoCodigo = ref("");
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
// El patrón común de los formularios: errores junto al campo, no se cierra mientras
// guarda, pregunta antes de descartar y confirma lo guardado.
const {
  guardando,
  errores,
  error: errorForm,
  empezar,
  cerrar: cerrarForm,
  marcar,
  guardar: guardarForm,
} = useEditorFormulario({
  abierto: mostrarForm,
  valor: () => form.value,
  campos: [
    "codigo",
    "tipo",
    "valor",
    "monto_minimo_minor",
    "usos_maximos",
    "vence_en",
    "descripcion",
  ],
  formulario: "#form-promo",
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
  errorCarga.value = null;
  try {
    const { data } = await api.get<{ data: Promo[] }>(
      `${base.value}/promociones`,
    );
    promos.value = data.data;
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
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
  empezar();
  mostrarForm.value = true;
}

function editar(p: Promo): void {
  editandoId.value = p.id;
  editandoCodigo.value = p.codigo;
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
  empezar();
  mostrarForm.value = true;
}

async function guardar(): Promise<void> {
  if (form.value.codigo.trim() === "") {
    return;
  }
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
  const id = editandoId.value;
  const listo = await guardarForm(
    () =>
      id !== null
        ? api.put(`${base.value}/promociones/${id}`, carga)
        : api.post(`${base.value}/promociones`, carga),
    t("promociones.guardada", { codigo: carga.codigo.trim().toUpperCase() }),
  );
  if (listo !== undefined) {
    mostrarForm.value = false;
    await cargar();
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
// En la dirección: recargar o compartir el enlace conserva lo que se buscaba.
useFiltrosEnUrl({
  q: busqueda,
  vigencia: opcionEnUrl(filtro, ["", "vigentes", "no_vigentes"] as const),
});
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return (
    promos.value
      .filter(
        (p) =>
          (q === "" ||
            p.codigo.toLowerCase().includes(q) ||
            (p.descripcion ?? "").toLowerCase().includes(q)) &&
          (filtro.value === "" || (filtro.value === "vigentes") === p.vigente),
      )
      // Las vigentes primero: las vencidas se juntan con el tiempo.
      .sort((a, b) => Number(b.vigente) - Number(a.vigente))
  );
});
// Por páginas: las promociones de años se juntan.
const POR_PAGINA = 20;
const pagina = ref(1);
const ultimaPagina = computed(() =>
  Math.max(1, Math.ceil(visibles.value.length / POR_PAGINA)),
);
const enPagina = computed(() =>
  visibles.value.slice(
    (pagina.value - 1) * POR_PAGINA,
    pagina.value * POR_PAGINA,
  ),
);
watch([busqueda, filtro], () => {
  pagina.value = 1;
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
        editandoId !== null
          ? $t('promocionesVisual.editarTitulo', { codigo: editandoCodigo })
          : $t('promociones.nueva')
      "
      :ocupado="guardando"
      @cerrar="cerrarForm"
    >
      <form id="form-promo" class="grid gap-4" @submit.prevent="guardar">
        <AvisoTono v-if="errorForm" tono="peligro" role="alert">
          {{ errorForm }}
        </AvisoTono>
        <div>
          <label class="tu-label" for="p-codigo">{{
            $t("promociones.campos.codigo")
          }}</label>
          <input
            id="p-codigo"
            v-bind="marcar('codigo', 'p-codigo-error')"
            v-model="form.codigo"
            class="tu-input uppercase"
            required
          />
          <span
            v-if="errores.codigo"
            id="p-codigo-error"
            class="tu-error-campo"
            >{{ errores.codigo }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="p-tipo">{{
            $t("promociones.campos.tipo")
          }}</label>
          <select
            id="p-tipo"
            v-bind="marcar('tipo', 'p-tipo-error')"
            v-model="form.tipo"
            class="tu-input"
          >
            <option value="porcentaje">
              {{ $t("promociones.tipos.porcentaje") }}
            </option>
            <option value="monto_fijo">
              {{ $t("promociones.tipos.monto_fijo") }}
            </option>
          </select>
          <span v-if="errores.tipo" id="p-tipo-error" class="tu-error-campo">{{
            errores.tipo
          }}</span>
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
            v-bind="marcar('valor', 'p-valor-error')"
            v-model.number="form.valor"
            type="number"
            min="1"
            :max="form.tipo === 'porcentaje' ? 100 : undefined"
            step="0.01"
            class="tu-input"
            required
          />
          <span
            v-if="errores.valor"
            id="p-valor-error"
            class="tu-error-campo"
            >{{ errores.valor }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="p-min">{{
            $t("promociones.campos.minimo")
          }}</label>
          <input
            id="p-min"
            v-bind="marcar('monto_minimo_minor', 'p-min-error')"
            v-model="form.minimo"
            type="number"
            min="0"
            step="0.01"
            class="tu-input"
          />
          <span
            v-if="errores.monto_minimo_minor"
            id="p-min-error"
            class="tu-error-campo"
            >{{ errores.monto_minimo_minor }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="p-usos">{{
            $t("promociones.campos.usosMaximos")
          }}</label>
          <input
            id="p-usos"
            v-bind="marcar('usos_maximos', 'p-usos-error')"
            v-model="form.usosMaximos"
            type="number"
            min="1"
            class="tu-input"
          />
          <span
            v-if="errores.usos_maximos"
            id="p-usos-error"
            class="tu-error-campo"
            >{{ errores.usos_maximos }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="p-vence">{{
            $t("promociones.campos.vence")
          }}</label>
          <input
            id="p-vence"
            v-bind="marcar('vence_en', 'p-vence-error')"
            v-model="form.vence"
            type="date"
            class="tu-input"
          />
          <span
            v-if="errores.vence_en"
            id="p-vence-error"
            class="tu-error-campo"
            >{{ errores.vence_en }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="p-desc">{{
            $t("promociones.campos.descripcion")
          }}</label>
          <input
            id="p-desc"
            v-bind="marcar('descripcion', 'p-desc-error')"
            v-model="form.descripcion"
            class="tu-input"
          />
          <span
            v-if="errores.descripcion"
            id="p-desc-error"
            class="tu-error-campo"
            >{{ errores.descripcion }}</span
          >
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.activa" type="checkbox" />
          {{ $t("promociones.campos.activa") }}
        </label>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-secundario"
            type="button"
            :disabled="guardando"
            @click="cerrarForm"
          >
            {{ $t("promociones.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            form="form-promo"
            :disabled="guardando || form.codigo.trim() === ''"
            :aria-busy="guardando || undefined"
          >
            {{ $t("promociones.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div
      v-else-if="errorCarga"
      class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
      role="alert"
      data-prueba="error-carga-promociones"
    >
      <span style="color: var(--error)">{{ errorCarga }}</span>
      <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>
    <EstadoVacio
      v-else-if="promos.length === 0"
      class="mt-10"
      icono="promociones"
      :titulo="$t('promociones.sinPromos')"
    />

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

      <div class="tu-card mt-5">
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
        <div class="relative overflow-x-auto tu-tabla-adaptable">
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
              <tr v-for="p in enPagina" :key="p.id" data-prueba="promo">
                <td :data-etiqueta="$t('promocionesVisual.col.codigo')">
                  <span class="font-semibold tracking-wide">{{
                    p.codigo
                  }}</span>
                  <span v-if="p.descripcion" class="tu-sub">{{
                    p.descripcion
                  }}</span>
                </td>
                <td
                  class="tabular-nums"
                  :data-etiqueta="$t('promocionesVisual.col.descuento')"
                >
                  <span>
                    {{ valorLegible(p) }}
                    <span v-if="p.monto_minimo_minor" class="tu-sub">{{
                      $t("promocionesVisual.desde", {
                        monto: dinero(p.monto_minimo_minor),
                      })
                    }}</span>
                  </span>
                </td>
                <td
                  class="hidden sm:table-cell tabular-nums"
                  :data-etiqueta="$t('promociones.usos')"
                >
                  {{ usos(p) }}
                </td>
                <td
                  class="hidden md:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                  :data-etiqueta="$t('promociones.vence')"
                >
                  {{ fechaCorta(p.vence_en) }}
                </td>
                <td :data-etiqueta="$t('promocionesVisual.col.estado')">
                  <span
                    class="tu-estado"
                    :style="{
                      '--tono': p.vigente
                        ? 'var(--exito)'
                        : 'var(--texto-suave)',
                    }"
                    >{{
                      p.vigente
                        ? $t("promociones.vigente")
                        : $t("promociones.noVigente")
                    }}</span
                  >
                </td>
                <td class="tu-celda-acciones">
                  <div class="inline-flex flex-wrap items-center gap-2">
                    <button
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      type="button"
                      :disabled="accionando"
                      :data-prueba="`editar-promo-${p.id}`"
                      @click="editar(p)"
                    >
                      {{ $t("promociones.editar") }}
                    </button>
                    <button
                      v-if="puedeEliminar"
                      class="tu-btn tu-btn-peligro tu-btn-chico"
                      type="button"
                      :disabled="accionando"
                      :data-prueba="`eliminar-promo-${p.id}`"
                      @click="eliminar(p)"
                    >
                      {{ $t("promociones.eliminar") }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="visibles.length === 0" class="tu-sin-resultados">
          {{ $t("promocionesVisual.sinResultados") }}
        </p>
        <PaginacionListado
          v-if="ultimaPagina > 1"
          :page="pagina"
          :ultima-pagina="ultimaPagina"
          :total="visibles.length"
          :per-page="POR_PAGINA"
          @ir="pagina = $event"
        />
      </div>
    </template>
  </section>
</template>
