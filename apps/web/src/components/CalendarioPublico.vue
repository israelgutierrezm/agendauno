<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { api } from "@/lib/api";

/**
 * El calendario público de clases (plan de mejoras M02): una semana a la vez, con la
 * tira de días, filtros solo si hay de dónde elegir, y la lista del día con sus lugares
 * (la misma regla de cupo que al reservar, ADR 0116). Sin clases ese día, ofrece el
 * siguiente con clases sin cambiar solo la fecha. Elegir una clase avisa a la página
 * (`pedir`) para que la persona deje sus datos con esa clase.
 */
const props = withDefaults(
  // `sucursal`: la sede que eligió el visitante (v-model), para que la página la
  // conserve en sus planes (M06).
  defineProps<{ slug: string; puedePedir?: boolean; sucursal?: string }>(),
  { puedePedir: false, sucursal: "" },
);
const emit = defineEmits<{
  pedir: [{ sesion: string; etiqueta: string }];
  error: [];
  "update:sucursal": [string];
}>();

interface SesionCalendario {
  id: string;
  clase: string | null;
  actividad: { id: string; nombre: string } | null;
  sucursal: { id: string; nombre: string } | null;
  instructor: string | null;
  inicia_en: string;
  termina_en: string;
  zona_horaria: string;
  duracion_minutos: number;
  capacidad: number | null;
  lugares_libres: number | null;
}
interface Opcion {
  id: string;
  nombre: string;
}
interface Calendario {
  desde: string;
  hasta: string;
  zona_horaria: string;
  sesiones: SesionCalendario[];
  filtros: {
    sucursales: Opcion[];
    actividades: Opcion[];
    instructores: Opcion[];
  };
  siguiente: string | null;
}

const { t, locale } = useI18n();
const datos = ref<Calendario | null>(null);
const cargando = ref(true);
const inicioSemana = ref<string | null>(null);
const diaElegido = ref<string | null>(null);
const filtros = reactive({
  sucursal: props.sucursal,
  actividad: "",
  instructor: "",
});
watch(
  () => props.sucursal,
  (valor) => {
    filtros.sucursal = valor;
  },
);
watch(
  () => filtros.sucursal,
  (valor) => emit("update:sucursal", valor),
);

function fechaLocal(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}
function sumarDias(fecha: string, n: number): string {
  const d = new Date(`${fecha}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const { data } = await api.get<{ data: Calendario }>(
      `/api/v1/app/${encodeURIComponent(props.slug)}/calendario`,
      {
        params: {
          desde: inicioSemana.value ?? undefined,
          dias: 7,
          sucursal: filtros.sucursal || undefined,
          actividad: filtros.actividad || undefined,
          instructor: filtros.instructor || undefined,
        },
      },
    );
    // Una respuesta sin periodo no es un calendario: se queda la lista de siempre.
    if (
      !data?.data ||
      typeof data.data.desde !== "string" ||
      !Array.isArray(data.data.sesiones)
    ) {
      throw new Error("calendario");
    }
    datos.value = data.data;
    inicioSemana.value = data.data.desde;
    // El día que se ve: el elegido si sigue en la semana; si no, el primero con clases.
    const dias = diasSemana.value.map((d) => d.fecha);
    if (!diaElegido.value || !dias.includes(diaElegido.value)) {
      diaElegido.value =
        diasSemana.value.find((d) => d.total > 0)?.fecha ?? dias[0] ?? null;
    }
  } catch {
    datos.value = null;
    emit("error");
  } finally {
    cargando.value = false;
  }
}

const zona = computed(() => datos.value?.zona_horaria ?? "America/Mexico_City");
const diasSemana = computed(() => {
  const desde = datos.value?.desde ?? inicioSemana.value;
  if (!desde) {
    return [];
  }
  return Array.from({ length: 7 }, (_, i) => {
    const fecha = sumarDias(desde, i);
    const d = new Date(`${fecha}T12:00:00Z`);
    return {
      fecha,
      dia: new Intl.DateTimeFormat(locale.value, {
        weekday: "short",
        timeZone: "UTC",
      }).format(d),
      numero: d.getUTCDate(),
      total: (datos.value?.sesiones ?? []).filter(
        (s) => fechaLocal(s.inicia_en, s.zona_horaria || zona.value) === fecha,
      ).length,
    };
  });
});
const delDia = computed(() =>
  (datos.value?.sesiones ?? []).filter(
    (s) =>
      fechaLocal(s.inicia_en, s.zona_horaria || zona.value) ===
      diaElegido.value,
  ),
);
const otroDiaConClases = computed(
  () =>
    diasSemana.value.find((d) => d.total > 0 && d.fecha !== diaElegido.value) ??
    null,
);
const rango = computed(() => {
  if (!datos.value) {
    return "";
  }
  const f = (x: string) =>
    new Intl.DateTimeFormat(locale.value, {
      day: "numeric",
      month: "short",
      timeZone: "UTC",
    }).format(new Date(`${x}T12:00:00Z`));
  return `${f(datos.value.desde)} – ${f(datos.value.hasta)}`;
});

function hora(s: SesionCalendario): string {
  return new Intl.DateTimeFormat(locale.value, {
    hour: "numeric",
    minute: "2-digit",
    timeZone: s.zona_horaria || zona.value,
  }).format(new Date(s.inicia_en));
}
function fechaLarga(fecha: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone: "UTC",
  }).format(new Date(`${fecha}T12:00:00Z`));
}
function lugares(s: SesionCalendario): string {
  if (s.lugares_libres === null) {
    return t("calendarioPublico.cupoAbierto");
  }
  if (s.lugares_libres === 0) {
    return t("calendarioPublico.llena");
  }
  return t("calendarioPublico.lugares", {
    libres: s.lugares_libres,
    total: s.capacidad ?? s.lugares_libres,
  });
}

function semana(delta: number): void {
  if (!inicioSemana.value) {
    return;
  }
  inicioSemana.value = sumarDias(inicioSemana.value, delta * 7);
  diaElegido.value = null;
  void cargar();
}
function irA(fecha: string): void {
  inicioSemana.value = fecha;
  diaElegido.value = fecha;
  void cargar();
}
function pedir(s: SesionCalendario): void {
  emit("pedir", {
    sesion: s.id,
    etiqueta:
      `${s.clase ?? ""} · ${fechaLarga(diaElegido.value ?? "")} ${hora(s)}`.trim(),
  });
}

watch(() => [filtros.sucursal, filtros.actividad, filtros.instructor], cargar);
onMounted(cargar);
</script>

<template>
  <div class="calendario-publico" data-prueba="calendario-publico">
    <div class="flex items-center justify-between gap-2">
      <button
        type="button"
        class="tu-icono-btn"
        :aria-label="$t('calendarioPublico.semanaAnterior')"
        @click="semana(-1)"
      >
        <IconoNav nombre="atras" :tam="16" />
      </button>
      <p class="text-sm font-medium" aria-live="polite">{{ rango }}</p>
      <button
        type="button"
        class="tu-icono-btn"
        :aria-label="$t('calendarioPublico.semanaSiguiente')"
        @click="semana(1)"
      >
        <IconoNav nombre="flecha" :tam="16" />
      </button>
    </div>

    <div
      class="cp-dias mt-3"
      role="tablist"
      :aria-label="$t('calendarioPublico.dias')"
    >
      <button
        v-for="d in diasSemana"
        :key="d.fecha"
        type="button"
        role="tab"
        class="cp-dia"
        :class="{ 'cp-dia-vacio': d.total === 0 }"
        :aria-selected="d.fecha === diaElegido"
        @click="diaElegido = d.fecha"
      >
        <span class="cp-dia-nombre">{{ d.dia }}</span>
        <span class="cp-dia-numero">{{ d.numero }}</span>
      </button>
    </div>

    <div
      v-if="
        datos &&
        (datos.filtros.sucursales.length > 1 ||
          datos.filtros.actividades.length > 1 ||
          datos.filtros.instructores.length > 1 ||
          filtros.sucursal ||
          filtros.actividad ||
          filtros.instructor)
      "
      class="mt-3 flex flex-wrap gap-2"
    >
      <select
        v-if="datos.filtros.sucursales.length > 1 || filtros.sucursal"
        v-model="filtros.sucursal"
        class="tu-input w-auto text-sm"
        :aria-label="$t('calendarioPublico.sede')"
      >
        <option value="">{{ $t("calendarioPublico.todasSedes") }}</option>
        <option v-for="o in datos.filtros.sucursales" :key="o.id" :value="o.id">
          {{ o.nombre }}
        </option>
      </select>
      <select
        v-if="datos.filtros.actividades.length > 1 || filtros.actividad"
        v-model="filtros.actividad"
        class="tu-input w-auto text-sm"
        :aria-label="$t('calendarioPublico.actividad')"
      >
        <option value="">{{ $t("calendarioPublico.todasActividades") }}</option>
        <option
          v-for="o in datos.filtros.actividades"
          :key="o.id"
          :value="o.id"
        >
          {{ o.nombre }}
        </option>
      </select>
      <select
        v-if="datos.filtros.instructores.length > 1 || filtros.instructor"
        v-model="filtros.instructor"
        class="tu-input w-auto text-sm"
        :aria-label="$t('calendarioPublico.instructor')"
      >
        <option value="">
          {{ $t("calendarioPublico.todosInstructores") }}
        </option>
        <option
          v-for="o in datos.filtros.instructores"
          :key="o.id"
          :value="o.id"
        >
          {{ o.nombre }}
        </option>
      </select>
    </div>

    <p
      v-if="cargando && !datos"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <template v-else-if="datos">
      <h3 v-if="diaElegido" class="cp-titulo-dia mt-5 text-sm font-medium">
        {{ fechaLarga(diaElegido) }}
      </h3>
      <div
        v-if="delDia.length === 0"
        class="mt-3 text-sm"
        role="status"
        :style="{ color: 'var(--texto-suave)' }"
      >
        <p>{{ $t("calendarioPublico.sinClasesDia") }}</p>
        <button
          v-if="otroDiaConClases"
          type="button"
          class="tu-enlace mt-2"
          @click="diaElegido = otroDiaConClases.fecha"
        >
          {{
            $t("calendarioPublico.verDia", {
              fecha: fechaLarga(otroDiaConClases.fecha),
            })
          }}
        </button>
        <button
          v-else-if="datos.siguiente"
          type="button"
          class="tu-enlace mt-2"
          @click="irA(datos.siguiente)"
        >
          {{
            $t("calendarioPublico.siguiente", {
              fecha: fechaLarga(datos.siguiente),
            })
          }}
        </button>
      </div>
      <ul v-else class="cp-lista mt-3">
        <li
          v-for="s in delDia"
          :key="s.id"
          class="tu-card cp-clase"
          data-prueba="clase-calendario"
        >
          <div class="cp-hora">
            <span class="font-medium">{{ hora(s) }}</span>
            <span class="cp-sub">{{
              $t("calendarioPublico.minutos", { n: s.duracion_minutos })
            }}</span>
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-medium truncate">{{ s.clase ?? "—" }}</p>
            <p class="cp-sub truncate">
              {{
                [s.instructor, s.sucursal?.nombre].filter(Boolean).join(" · ")
              }}
            </p>
            <p
              class="cp-sub mt-1"
              :style="{
                color:
                  s.lugares_libres === 0
                    ? 'var(--aviso)'
                    : 'var(--texto-suave)',
              }"
            >
              {{ lugares(s) }}
            </p>
          </div>
          <button
            v-if="puedePedir && s.lugares_libres !== 0"
            type="button"
            class="tu-btn tu-btn-primario cp-accion"
            data-prueba="pedir-lugar"
            @click="pedir(s)"
          >
            {{ $t("escaparate.pedirLugar") }}
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.cp-dias {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.35rem;
}
.cp-dia {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
  padding: 0.45rem 0;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  background: var(--superficie);
}
.cp-dia[aria-selected="true"] {
  border-color: var(--primario);
  background: var(--primario-suave);
}
.cp-dia-vacio {
  opacity: 0.55;
}
.cp-dia-nombre {
  font-size: 0.72rem;
  color: var(--texto-suave);
  text-transform: capitalize;
}
/* «Domingo, 11 de octubre»: mayúscula solo al inicio. */
.cp-titulo-dia::first-letter {
  text-transform: uppercase;
}
.cp-dia-numero {
  font-weight: 500;
}
.cp-lista {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.5rem;
}
.cp-clase {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.85rem;
  align-items: flex-start;
  min-width: 0;
  padding: 0.85rem 1rem;
}
.cp-accion {
  flex-shrink: 0;
  align-self: center;
}
/* En el teléfono, la acción va abajo y a todo lo ancho. */
@media (max-width: 30rem) {
  .cp-accion {
    flex-basis: 100%;
  }
}
.cp-hora {
  display: flex;
  flex-direction: column;
  min-width: 3.6rem;
}
.cp-sub {
  display: block;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
</style>
