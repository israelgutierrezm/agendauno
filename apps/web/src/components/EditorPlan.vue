<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import { RouterLink } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import {
  UNIDADES_POR_CLASE,
  fechaLarga,
  vigenteHasta,
  type Plan,
  type TipoPlan,
  type TipoVigencia,
} from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Alta y edición de un plan (ADR 0050): qué es (paquete, membresía, clase suelta,
 * clases extra u otro), su precio, cuántas clases incluye, cuánto dura (con el
 * ejemplo de "hasta cuándo" si se comprara hoy) y para qué clases sirve.
 *
 * Con citas (ADR 0091) solo hay dos: bono de sesiones (varias visitas prepagadas) y
 * membresía (una cuota con servicios o beneficios); un servicio suelto o un combo se
 * dan de alta en Catálogo. Un bono sirve para los servicios que se toman «con bono o
 * membresía», que son los que se pueden elegir aquí.
 */
const props = defineProps<{ plan: Plan | null }>();
const emit = defineEmits<{ guardado: []; cerrar: [] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

interface Opcion {
  id: string;
  nombre: string;
  actividad?: string | null;
  politica_reserva?: string;
}
const ofertas = ref<Opcion[]>([]);
const sucursales = ref<Opcion[]>([]);

const p = props.plan;
const form = reactive({
  tipo: (p?.tipo ?? "paquete") as TipoPlan,
  nombre: p?.nombre ?? "",
  precio: p ? String(p.precio_minor / 100) : "",
  clases:
    p?.creditos_incluidos != null
      ? String(p.creditos_incluidos / UNIDADES_POR_CLASE)
      : "8",
  ilimitado: p?.ilimitado ?? false,
  clasesPorMes:
    p?.unidades_por_ciclo != null
      ? String(p.unidades_por_ciclo / UNIDADES_POR_CLASE)
      : "8",
  ciclo: p && p.politica_reset !== "ninguno" ? p.politica_reset : "calendario",
  vigencia: (p?.vigencia_tipo ?? (p ? "ninguna" : "meses")) as
    TipoVigencia | "ninguna",
  cantidad: String(p?.vigencia_cantidad ?? 1),
  todas: (p?.ofertas.length ?? 0) === 0,
  ofertas: new Set(p?.ofertas.map((o) => o.id) ?? []),
  sucursal: p?.sucursal_id ?? "",
  rollover: p?.politica_rollover ?? "ninguno",
  rolloverMax:
    p?.rollover_max != null ? String(p.rollover_max / UNIDADES_POR_CLASE) : "",
  archivado: p?.archivado ?? false,
});
const guardando = ref(false);
const error = ref<string | null>(null);

const esCitas = computed(() => sesion.esCitas === true);
const TIPOS_PRINCIPALES = computed<TipoPlan[]>(() =>
  esCitas.value
    ? ["paquete", "membresia"]
    : ["paquete", "membresia", "sesion_individual", "add_on"],
);
// Los textos del editor en citas hablan de servicios y sesiones, no de clases.
function tx(clave: string): string {
  return esCitas.value && te(`planes.editorCitas.${clave}`)
    ? t(`planes.editorCitas.${clave}`)
    : t(`planes.editor.${clave}`);
}
function nombreTipo(tipo: TipoPlan): string {
  return esCitas.value && te(`planes.tiposCitas.${tipo}`)
    ? t(`planes.tiposCitas.${tipo}`)
    : t(`planes.tipos.${tipo}`);
}
function ayudaTipo(tipo: TipoPlan): string {
  return esCitas.value && te(`planes.tiposAyudaCitas.${tipo}`)
    ? t(`planes.tiposAyudaCitas.${tipo}`)
    : t(`planes.tiposAyuda.${tipo}`);
}
const esOtro = computed(
  () => form.tipo === "pase_dia" || form.tipo === "taller",
);
const esMembresia = computed(() => form.tipo === "membresia");
const esExtra = computed(() => form.tipo === "add_on");
const esSuelta = computed(() => form.tipo === "sesion_individual");
const sinVigencia = computed(
  () => esExtra.value || esMembresia.value || form.vigencia === "ninguna",
);

function numero(valor: string): number | null {
  const n = Number(String(valor).trim());
  return String(valor).trim() !== "" && Number.isFinite(n) ? n : null;
}

// Ejemplo en vivo: "si se compra hoy, se puede usar hasta el …".
const ejemplo = computed(() => {
  const cantidad = numero(form.cantidad);
  if (form.vigencia === "ninguna" || cantidad === null || cantidad < 1) {
    return null;
  }
  const hoy = new Date();
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  return t("planes.editor.ejemplo", {
    hoy: fechaLarga(iso(hoy)),
    hasta: fechaLarga(vigenteHasta(form.vigencia, cantidad, hoy)),
  });
});

// Con citas, un bono solo sirve para lo que se toma con bono o membresía.
const ofertasElegibles = computed(() =>
  esCitas.value
    ? ofertas.value.filter((o) => o.politica_reserva !== "pago")
    : ofertas.value,
);
// Clases agrupadas por actividad para elegir a cuáles aplica.
const ofertasPorActividad = computed(() => {
  const grupos = new Map<string, Opcion[]>();
  for (const o of ofertasElegibles.value) {
    const clave = o.actividad ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), o]);
  }
  return [...grupos.entries()];
});
function alternar(id: string): void {
  const s = new Set(form.ofertas);
  if (s.has(id)) {
    s.delete(id);
  } else {
    s.add(id);
  }
  form.ofertas = s;
}

const valido = computed(
  () =>
    form.nombre.trim() !== "" &&
    numero(form.precio) !== null &&
    (esExtra.value || form.todas || form.ofertas.size > 0),
);

async function guardar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  const unidades = (v: string) =>
    Math.round((numero(v) ?? 0) * UNIDADES_POR_CLASE);
  const recurrente = esMembresia.value;
  const carga: Record<string, unknown> = {
    nombre: form.nombre.trim(),
    tipo: form.tipo,
    precio_minor: Math.round((numero(form.precio) ?? 0) * 100),
    moneda: p?.moneda ?? "MXN",
    ilimitado: recurrente && form.ilimitado,
    creditos_incluidos:
      recurrente || esSuelta.value ? null : unidades(form.clases),
    politica_reset: recurrente ? form.ciclo : "ninguno",
    unidades_por_ciclo:
      recurrente && !form.ilimitado ? unidades(form.clasesPorMes) : null,
    politica_rollover:
      recurrente && !form.ilimitado ? form.rollover : "ninguno",
    rollover_max:
      recurrente && form.rollover === "limitado"
        ? unidades(form.rolloverMax)
        : null,
    // Las extras vencen con el paquete y una membresía dura mientras se paga: no
    // llevan vigencia propia.
    vigencia_tipo: sinVigencia.value ? null : form.vigencia,
    vigencia_cantidad: sinVigencia.value ? null : numero(form.cantidad),
    // Las extras sirven para lo mismo que el paquete al que se suman.
    ofertas: esExtra.value || form.todas ? [] : [...form.ofertas],
    sucursal_id: form.sucursal || null,
  };
  try {
    if (p) {
      await api.put(`${base.value}/productos/${p.id}`, {
        ...carga,
        archivado: form.archivado,
      });
    } else {
      await api.post(`${base.value}/productos`, carga);
    }
    emit("guardado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(async () => {
  try {
    const [o, s] = await Promise.all([
      api.get<{ data: Opcion[] }>(`${base.value}/ofertas`),
      api.get<{ data: Opcion[] }>(`${base.value}/sucursales`),
    ]);
    ofertas.value = o.data.data;
    sucursales.value = s.data.data;
  } catch {
    // Sin catálogo o sin sedes, el plan sirve para todas (no bloquea).
  }
});
</script>

<template>
  <form class="space-y-5" @submit.prevent="guardar">
    <!-- ¿Qué es? -->
    <fieldset>
      <legend class="tu-label">{{ $t("planes.editor.queEs") }}</legend>
      <div class="mt-1 grid gap-2 sm:grid-cols-2">
        <button
          v-for="tipo in TIPOS_PRINCIPALES"
          :key="tipo"
          type="button"
          class="ep-tipo"
          :aria-pressed="form.tipo === tipo"
          :data-prueba="`tipo-${tipo}`"
          @click="form.tipo = tipo"
        >
          <span class="font-medium">{{ nombreTipo(tipo) }}</span>
          <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
            ayudaTipo(tipo)
          }}</span>
        </button>
      </div>
      <p
        v-if="esCitas"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="servicio-o-combo"
      >
        {{ $t("planes.editorCitas.servicioOCombo") }}
        <RouterLink :to="{ name: 'catalogo' }" class="tu-enlace">{{
          $t("planes.editorCitas.irCatalogo")
        }}</RouterLink>
      </p>
      <label v-else class="mt-2 flex items-center gap-2 text-sm">
        <span :style="{ color: 'var(--texto-suave)' }">{{
          $t("planes.editor.otro")
        }}</span>
        <select
          class="tu-input w-auto"
          :value="esOtro ? form.tipo : ''"
          @change="
            form.tipo =
              (($event.target as HTMLSelectElement).value as TipoPlan) ||
              form.tipo
          "
        >
          <option value="">—</option>
          <option value="pase_dia">{{ $t("planes.tipos.pase_dia") }}</option>
          <option value="taller">{{ $t("planes.tipos.taller") }}</option>
        </select>
      </label>
    </fieldset>

    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label class="tu-label" for="ep-nombre">{{
          $t("planes.editor.nombre")
        }}</label>
        <input
          id="ep-nombre"
          v-model="form.nombre"
          class="tu-input"
          :placeholder="tx('nombrePh')"
          required
          maxlength="255"
        />
      </div>
      <div>
        <label class="tu-label" for="ep-precio">{{
          $t("planes.editor.precio")
        }}</label>
        <input
          id="ep-precio"
          v-model="form.precio"
          class="tu-input"
          type="number"
          min="0"
          step="0.01"
          inputmode="decimal"
          required
        />
      </div>
    </div>

    <!-- Cuántas clases -->
    <div v-if="esMembresia" class="grid gap-3 sm:grid-cols-2">
      <label class="flex items-center gap-2 text-sm sm:col-span-2">
        <input v-model="form.ilimitado" type="checkbox" />
        {{ $t("planes.editor.ilimitada") }}
      </label>
      <div v-if="!form.ilimitado">
        <label class="tu-label" for="ep-pormes">{{ tx("clasesPorMes") }}</label>
        <input
          id="ep-pormes"
          v-model="form.clasesPorMes"
          class="tu-input"
          type="number"
          min="1"
        />
      </div>
      <div>
        <label class="tu-label" for="ep-ciclo">{{
          $t("planes.editor.ciclo")
        }}</label>
        <select id="ep-ciclo" v-model="form.ciclo" class="tu-input">
          <option value="calendario">
            {{ $t("planes.editor.cicloCalendario") }}
          </option>
          <option value="aniversario">
            {{ $t("planes.editor.cicloAniversario") }}
          </option>
        </select>
      </div>
    </div>
    <p
      v-else-if="esSuelta"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("planes.editor.unaClase") }}
    </p>
    <div v-else class="sm:w-1/2">
      <label class="tu-label" for="ep-clases">{{
        esExtra ? $t("planes.editor.clasesExtra") : tx("clases")
      }}</label>
      <input
        id="ep-clases"
        v-model="form.clases"
        class="tu-input"
        type="number"
        min="1"
        required
      />
    </div>

    <!-- Cuánto dura -->
    <p
      v-if="esExtra"
      class="rounded-lg p-3 text-sm"
      :style="{ background: 'var(--superficie-2)' }"
    >
      {{ $t("planes.editor.extraVence") }}
    </p>
    <fieldset v-else-if="!esMembresia">
      <legend class="tu-label">{{ $t("planes.editor.vigencia") }}</legend>
      <div class="mt-1 flex flex-wrap gap-2">
        <label
          v-for="v in ['ninguna', 'dias', 'meses', 'fin_de_mes'] as const"
          :key="v"
          class="ep-opcion"
        >
          <input v-model="form.vigencia" type="radio" :value="v" />
          {{ $t(`planes.editor.vigencias.${v}`) }}
        </label>
      </div>
      <div v-if="form.vigencia !== 'ninguna'" class="mt-3 sm:w-1/2">
        <template v-if="form.vigencia === 'fin_de_mes'">
          <label class="tu-label" for="ep-cant">{{
            $t("planes.editor.cantidadFinDeMes")
          }}</label>
          <select id="ep-cant" v-model="form.cantidad" class="tu-input">
            <option value="1">{{ $t("planes.editor.finDeEsteMes") }}</option>
            <option value="2">
              {{ $t("planes.editor.finDelSiguiente") }}
            </option>
            <option v-for="n in [3, 6, 12]" :key="n" :value="String(n)">
              {{ $t("planes.editor.finDeMasMeses", { n }) }}
            </option>
          </select>
        </template>
        <template v-else>
          <label class="tu-label" for="ep-cant">{{
            form.vigencia === "dias"
              ? $t("planes.editor.cantidadDias")
              : $t("planes.editor.cantidadMeses")
          }}</label>
          <input
            id="ep-cant"
            v-model="form.cantidad"
            class="tu-input"
            type="number"
            min="1"
            :max="form.vigencia === 'dias' ? 366 : 24"
          />
        </template>
      </div>
      <p
        v-if="ejemplo"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        role="status"
      >
        {{ ejemplo }}
      </p>
    </fieldset>

    <!-- Para qué clases -->
    <fieldset v-if="!esExtra">
      <legend class="tu-label">{{ tx("aplicaA") }}</legend>
      <div class="mt-1 flex flex-wrap gap-2">
        <label class="ep-opcion">
          <input v-model="form.todas" type="radio" :value="true" />
          {{ tx("todas") }}
        </label>
        <label class="ep-opcion">
          <input v-model="form.todas" type="radio" :value="false" />
          {{ tx("algunas") }}
        </label>
      </div>
      <p
        v-if="esCitas && ofertasElegibles.length === 0"
        class="mt-2 text-sm"
        :style="{ color: 'var(--aviso)' }"
        data-prueba="sin-servicios-con-bono"
      >
        {{ $t("planes.editorCitas.sinServiciosConBono") }}
        <RouterLink :to="{ name: 'catalogo' }" class="tu-enlace">{{
          $t("planes.editorCitas.irCatalogo")
        }}</RouterLink>
      </p>
      <div v-if="!form.todas" class="mt-3 space-y-3">
        <p
          v-if="ofertasElegibles.length === 0 && !esCitas"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ tx("sinClases") }}
        </p>
        <div v-for="[actividad, lista] in ofertasPorActividad" :key="actividad">
          <p
            v-if="actividad"
            class="text-xs font-medium"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ actividad }}
          </p>
          <div class="mt-1 flex flex-wrap gap-2">
            <label v-for="o in lista" :key="o.id" class="ep-opcion">
              <input
                type="checkbox"
                :checked="form.ofertas.has(o.id)"
                @change="alternar(o.id)"
              />
              {{ o.nombre }}
            </label>
          </div>
        </div>
        <p
          v-if="ofertasElegibles.length > 0 && form.ofertas.size === 0"
          class="text-sm"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ tx("eligeClases") }}
        </p>
      </div>
    </fieldset>

    <!-- Más opciones -->
    <details class="text-sm">
      <summary class="cursor-pointer" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("planes.editor.avanzado") }}
      </summary>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div v-if="sucursales.length > 1">
          <label class="tu-label" for="ep-sede">{{
            $t("planes.editor.sede")
          }}</label>
          <select id="ep-sede" v-model="form.sucursal" class="tu-input">
            <option value="">{{ $t("planes.editor.todasSedes") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <template v-if="esMembresia && !form.ilimitado">
          <div>
            <label class="tu-label" for="ep-acumula">{{
              $t("planes.editor.acumula")
            }}</label>
            <select id="ep-acumula" v-model="form.rollover" class="tu-input">
              <option value="ninguno">
                {{ $t("planes.editor.acumulaNinguno") }}
              </option>
              <option value="completo">
                {{ $t("planes.editor.acumulaTodo") }}
              </option>
              <option value="limitado">
                {{ $t("planes.editor.acumulaLimitado") }}
              </option>
            </select>
          </div>
          <div v-if="form.rollover === 'limitado'">
            <label class="tu-label" for="ep-acmax">{{
              $t("planes.editor.acumulaMax")
            }}</label>
            <input
              id="ep-acmax"
              v-model="form.rolloverMax"
              class="tu-input"
              type="number"
              min="1"
            />
          </div>
        </template>
        <!-- Archivar es la baja del plan (ADR 0077); reactivarlo es editar. -->
        <label
          v-if="plan && (plan.archivado || sesion.puede('productos.eliminar'))"
          class="flex items-center gap-2 sm:col-span-2"
        >
          <input v-model="form.archivado" type="checkbox" />
          {{ $t("planes.editor.archivar") }}
        </label>
      </div>
    </details>

    <p v-if="plan" class="text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("planes.editor.soloFuturo") }}
    </p>
    <p v-if="error" class="text-sm" style="color: var(--error)" role="alert">
      {{ error }}
    </p>
    <div class="flex justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        @click="emit('cerrar')"
      >
        {{ $t("planes.editor.cancelar") }}
      </button>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || !valido"
      >
        {{
          guardando
            ? $t("planes.editor.guardando")
            : $t("planes.editor.guardar")
        }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.ep-tipo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  text-align: left;
  background: var(--superficie);
}
.ep-tipo[aria-pressed="true"] {
  border-color: var(--primario);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.ep-opcion {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  font-size: 0.875rem;
  cursor: pointer;
}
.ep-opcion:has(input:checked) {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 8%, var(--superficie));
}
</style>
