<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Bloqueos de agenda (fase 2, punto 2.2) de la persona, la sede o una sala de la sede
 * elegidas en Horarios: comida, vacaciones, ausencia, mantenimiento o cierre, por
 * horas o días completos, con su motivo. Antes de guardar avisa qué citas o clases ya agendadas caen ahí (no se
 * cancelan solas).
 */
interface Bloqueo {
  id: string;
  ambito: "profesional" | "sede" | "sala";
  instructor_id: string | null;
  sucursal_id: string | null;
  recurso_id: string | null;
  desde: string;
  hasta: string;
  todo_el_dia: boolean;
  zona_horaria: string;
  motivo: string;
  creado_por: string | null;
}
interface Sala {
  id: string;
  nombre: string;
  sucursal_id: string | null;
  activo: boolean;
}
interface Afectada {
  sesion: string;
  oferta: string | null;
  inicia_en: string;
  zona_horaria: string | null;
  reservas: number;
}

const props = defineProps<{
  base: string;
  proveedorId: string;
  proveedorNombre: string;
  sucursalId: string;
  sucursalNombre: string;
  zona: string;
  puedeGestionar: boolean;
  // Quitar un bloqueo es borrarlo (ADR 0077).
  puedeEliminar?: boolean;
}>();

const bloqueos = ref<Bloqueo[]>([]);
// Salas (y cabinas o equipos) de la sede elegida.
const salas = ref<Sala[]>([]);
const error = ref<string | null>(null);
const guardando = ref(false);
// Lo ya agendado que caería en el bloqueo (null = aún no se revisa).
const afectadas = ref<Afectada[] | null>(null);

function hoy(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: props.zona }).format(
    new Date(),
  );
}
const form = ref({
  ambito: "profesional" as "profesional" | "sede" | "sala",
  salaId: "",
  todoElDia: false,
  fecha: hoy(),
  fechaHasta: "",
  horaDesde: "14:00",
  horaHasta: "15:00",
  motivo: "",
});

const visibles = computed(() =>
  bloqueos.value.filter((b) =>
    b.ambito === "profesional"
      ? b.instructor_id === props.proveedorId
      : b.ambito === "sede"
        ? b.sucursal_id === props.sucursalId
        : salas.value.some((s) => s.id === b.recurso_id),
  ),
);
function nombreSala(b: Bloqueo): string {
  return salas.value.find((s) => s.id === b.recurso_id)?.nombre ?? "";
}

function cuando(b: Bloqueo): string {
  const fmt = (iso: string, conHora: boolean): string =>
    new Intl.DateTimeFormat("es-MX", {
      timeZone: b.zona_horaria,
      day: "numeric",
      month: "short",
      hour: conHora ? "2-digit" : undefined,
      minute: conHora ? "2-digit" : undefined,
    }).format(new Date(iso));
  if (b.todo_el_dia) {
    // `hasta` es el inicio del día siguiente al último.
    const ultimo = new Date(new Date(b.hasta).getTime() - 60_000).toISOString();
    const a = fmt(b.desde, false);
    const z = fmt(ultimo, false);
    return a === z ? a : `${a} – ${z}`;
  }
  return `${fmt(b.desde, true)} – ${new Intl.DateTimeFormat("es-MX", {
    timeZone: b.zona_horaria,
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(b.hasta))}`;
}
function horaDe(a: Afectada): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: a.zona_horaria ?? props.zona,
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(a.inicia_en));
}

async function cargar(): Promise<void> {
  try {
    const [b, r] = await Promise.all([
      api.get<{ data: Bloqueo[] }>(`${props.base}/bloqueos`, {
        params: { desde: hoy() },
      }),
      api.get<{ data: Sala[] }>(`${props.base}/recursos`),
    ]);
    bloqueos.value = b.data.data.filter(
      (x) => new Date(x.hasta).getTime() > Date.now(),
    );
    salas.value = r.data.data.filter(
      (s) => s.activo && s.sucursal_id === props.sucursalId,
    );
    if (!salas.value.some((s) => s.id === form.value.salaId)) {
      form.value.salaId = salas.value[0]?.id ?? "";
      if (form.value.ambito === "sala" && form.value.salaId === "") {
        form.value.ambito = "profesional";
      }
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

function carga(): Record<string, string> {
  const f = form.value;
  const quien: Record<string, string> =
    f.ambito === "profesional"
      ? { instructor_id: props.proveedorId }
      : f.ambito === "sala"
        ? { recurso_id: f.salaId }
        : { sucursal_id: props.sucursalId };
  const cuando: Record<string, string> = f.todoElDia
    ? {
        fecha_desde: f.fecha,
        ...(f.fechaHasta ? { fecha_hasta: f.fechaHasta } : {}),
      }
    : {
        desde_local: `${f.fecha} ${f.horaDesde}`,
        hasta_local: `${f.fecha} ${f.horaHasta}`,
      };
  return { ...quien, ...cuando, motivo: f.motivo.trim() };
}

// Primero revisa qué ya está agendado ahí; si no hay nada, bloquea de una vez.
async function bloquear(): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    if (afectadas.value === null) {
      const { data } = await api.post<{ data: { afectadas: Afectada[] } }>(
        `${props.base}/bloqueos/previsualizar`,
        carga(),
      );
      if (data.data.afectadas.length > 0) {
        afectadas.value = data.data.afectadas;
        return;
      }
    }
    await api.post(`${props.base}/bloqueos`, carga());
    afectadas.value = null;
    form.value.motivo = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function quitar(b: Bloqueo): Promise<void> {
  error.value = null;
  try {
    await api.delete(`${props.base}/bloqueos/${b.id}`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Si cambia lo que se va a bloquear, hay que volver a revisar.
watch(form, () => (afectadas.value = null), { deep: true });
watch(() => [props.proveedorId, props.sucursalId], cargar, { immediate: true });
</script>

<template>
  <div class="mt-8 tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("bloqueosAgenda.titulo") }}</h2>
    <p class="text-sm mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("bloqueosAgenda.ayuda") }}
    </p>

    <ul
      v-if="visibles.length > 0"
      class="mt-4 divide-y divide-[var(--borde)] border-y text-sm"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <li
        v-for="b in visibles"
        :key="b.id"
        class="flex items-center justify-between gap-3 py-2"
      >
        <span class="min-w-0">
          <span class="block font-medium">{{ b.motivo }}</span>
          <span class="block" :style="{ color: 'var(--texto-suave)' }"
            >{{ cuando(b)
            }}<template v-if="b.ambito === 'sede'">
              · {{ $t("bloqueosAgenda.todaLaSede") }}</template
            ><template v-else-if="b.ambito === 'sala'">
              · {{ nombreSala(b) }}</template
            ><template v-if="b.creado_por">
              · {{ b.creado_por }}</template
            ></span
          >
        </span>
        <button
          v-if="puedeEliminar ?? puedeGestionar"
          type="button"
          class="tu-enlace text-xs shrink-0"
          @click="quitar(b)"
        >
          {{ $t("bloqueosAgenda.quitar") }}
        </button>
      </li>
    </ul>
    <p v-else class="mt-4 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("bloqueosAgenda.vacio") }}
    </p>

    <form
      v-if="puedeGestionar"
      class="mt-5 space-y-3"
      @submit.prevent="bloquear"
    >
      <div class="tu-segmentado" role="group">
        <button
          type="button"
          :aria-pressed="form.ambito === 'profesional'"
          @click="form.ambito = 'profesional'"
        >
          {{ proveedorNombre }}
        </button>
        <button
          type="button"
          :aria-pressed="form.ambito === 'sede'"
          @click="form.ambito = 'sede'"
        >
          {{ $t("bloqueosAgenda.sede", { sede: sucursalNombre }) }}
        </button>
        <button
          v-if="salas.length > 0"
          type="button"
          :aria-pressed="form.ambito === 'sala'"
          @click="form.ambito = 'sala'"
        >
          {{ $t("bloqueosAgenda.unaSala") }}
        </button>
      </div>
      <div v-if="form.ambito === 'sala'">
        <label class="tu-label" for="bl-sala">{{
          $t("bloqueosAgenda.sala")
        }}</label>
        <select
          id="bl-sala"
          v-model="form.salaId"
          class="tu-input w-auto"
          required
        >
          <option v-for="s in salas" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="tu-label" for="bl-fecha">{{
            form.todoElDia
              ? $t("bloqueosAgenda.desdeDia")
              : $t("bloqueosAgenda.fecha")
          }}</label>
          <input
            id="bl-fecha"
            v-model="form.fecha"
            type="date"
            class="tu-input w-auto"
            required
          />
        </div>
        <template v-if="form.todoElDia">
          <div>
            <label class="tu-label" for="bl-hasta-dia">{{
              $t("bloqueosAgenda.hastaDia")
            }}</label>
            <input
              id="bl-hasta-dia"
              v-model="form.fechaHasta"
              type="date"
              :min="form.fecha"
              class="tu-input w-auto"
            />
          </div>
        </template>
        <template v-else>
          <div>
            <label class="tu-label" for="bl-desde">{{
              $t("bloqueosAgenda.desde")
            }}</label>
            <input
              id="bl-desde"
              v-model="form.horaDesde"
              type="time"
              class="tu-input w-auto"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="bl-hasta">{{
              $t("bloqueosAgenda.hasta")
            }}</label>
            <input
              id="bl-hasta"
              v-model="form.horaHasta"
              type="time"
              class="tu-input w-auto"
              required
            />
          </div>
        </template>
        <label class="flex items-center gap-2 text-sm pb-2">
          <input v-model="form.todoElDia" type="checkbox" />
          {{ $t("bloqueosAgenda.todoElDia") }}
        </label>
      </div>
      <div>
        <label class="tu-label" for="bl-motivo">{{
          $t("bloqueosAgenda.motivo")
        }}</label>
        <input
          id="bl-motivo"
          v-model="form.motivo"
          class="tu-input"
          maxlength="255"
          :placeholder="$t('bloqueosAgenda.motivoEjemplo')"
          required
        />
      </div>

      <!-- Lo que ya está agendado ahí sigue en pie: se avisa antes de bloquear. -->
      <div
        v-if="afectadas !== null && afectadas.length > 0"
        class="text-sm"
        role="status"
      >
        <p style="color: var(--aviso)">
          {{ $t("bloqueosAgenda.afectadas", { n: afectadas.length }) }}
        </p>
        <ul class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          <li v-for="a in afectadas" :key="a.sesion">
            {{ horaDe(a) }} · {{ a.oferta ?? "—" }}
            <template v-if="a.reservas > 0">
              · {{ $t("bloqueosAgenda.conReservas", { n: a.reservas }) }}
            </template>
          </li>
        </ul>
      </div>
      <p v-if="error" class="text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || form.motivo.trim() === ''"
      >
        {{
          afectadas !== null && afectadas.length > 0
            ? $t("bloqueosAgenda.bloquearIgual")
            : $t("bloqueosAgenda.bloquear")
        }}
      </button>
    </form>
  </div>
</template>
