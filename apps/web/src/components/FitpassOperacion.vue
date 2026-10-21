<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Fitpass sin API (ADR 0197 y 0202): sus horarios se capturan a mano en Fitpass Admin y
 * su asistencia se marca allá. Aquí: qué clases se ofrecen en Fitpass (con el nombre de
 * su «disciplina» allá), sus horarios de los próximos días para capturarlos (y las
 * canceladas, para quitarlas) y lo que falta marcar en su panel antes de 48 horas.
 */
interface ClaseFitpass {
  oferta_id: string;
  nombre: string;
  actividad: string | null;
  ofrecida: boolean;
  disciplina: string | null;
}
interface HorarioFitpass {
  id: string;
  fecha: string;
  hora: string;
  duracion_minutos: number;
  clase: string;
  disciplina: string | null;
  instructor: string | null;
  sucursal: string | null;
  estado: "programada" | "cancelada";
  lugares: number | null;
}
interface PorMarcar {
  id: string;
  reservacion: string;
  nombre: string | null;
  clase: string | null;
  inicia_en: string | null;
  asistio: boolean;
  marcar_antes_de: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/integraciones/fitpass`);

const clases = ref<ClaseFitpass[]>([]);
const horarios = ref<HorarioFitpass[]>([]);
const porMarcar = ref<PorMarcar[]>([]);
const guardando = ref(false);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const [c, h, p] = await Promise.all([
      api.get<{ data: ClaseFitpass[] }>(`${base.value}/clases`),
      api.get<{ data: HorarioFitpass[] }>(`${base.value}/horarios`, {
        params: { dias: 7 },
      }),
      api.get<{ data: PorMarcar[] }>(`${base.value}/por-marcar`),
    ]);
    clases.value = c.data.data;
    horarios.value = h.data.data;
    porMarcar.value = p.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function guardarClases(): Promise<void> {
  guardando.value = true;
  try {
    const { data } = await api.put<{ data: ClaseFitpass[] }>(
      `${base.value}/clases`,
      {
        clases: clases.value.map((c) => ({
          oferta_id: c.oferta_id,
          ofrecida: c.ofrecida,
          disciplina: c.disciplina?.trim() || null,
        })),
      },
    );
    clases.value = data.data;
    toast.exito(t("fitpass.clasesGuardadas"));
    const h = await api.get<{ data: HorarioFitpass[] }>(
      `${base.value}/horarios`,
      { params: { dias: 7 } },
    );
    horarios.value = h.data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

async function yaMarcado(p: PorMarcar): Promise<void> {
  try {
    await api.post(
      `/api/v1/app/${sesion.slug}/lugares-plataforma/${p.id}/marcado`,
      {},
    );
    porMarcar.value = porMarcar.value.filter((x) => x.id !== p.id);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

/** Una celda de CSV: entre comillas y sin fórmulas (que no las ejecute la hoja). */
function celda(valor: string | number | null): string {
  let texto = valor === null ? "" : String(valor);
  if (/^[=+\-@]/.test(texto)) {
    texto = `'${texto}`;
  }
  return `"${texto.replace(/"/g, '""')}"`;
}

function descargar(): void {
  const encabezado = [
    t("fitpass.columnas.fecha"),
    t("fitpass.columnas.hora"),
    t("fitpass.columnas.duracion"),
    t("fitpass.columnas.disciplina"),
    t("fitpass.columnas.clase"),
    t("fitpass.columnas.instructor"),
    t("fitpass.columnas.sede"),
    t("fitpass.columnas.lugares"),
    t("fitpass.columnas.estado"),
  ];
  const filas = horarios.value.map((h) =>
    [
      h.fecha,
      h.hora,
      h.duracion_minutos,
      h.disciplina,
      h.clase,
      h.instructor,
      h.sucursal,
      h.lugares,
      t(`fitpass.estados.${h.estado}`),
    ]
      .map(celda)
      .join(","),
  );
  const contenido =
    "﻿" + [encabezado.map(celda).join(","), ...filas].join("\r\n");
  const url = URL.createObjectURL(
    new Blob([contenido], { type: "text/csv;charset=utf-8" }),
  );
  const enlace = document.createElement("a");
  enlace.href = url;
  enlace.download = "horarios-fitpass.csv";
  enlace.click();
  URL.revokeObjectURL(url);
}

function fechaHora(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
        timeZone: sesion.zonaHoraria ?? undefined,
      }).format(new Date(iso))
    : "—";
}

onMounted(cargar);
</script>

<template>
  <div class="mt-5 space-y-5" data-prueba="fitpass-operacion">
    <p v-if="error" class="tu-error-campo" role="alert">{{ error }}</p>

    <section>
      <h3 class="font-medium">{{ $t("fitpass.clasesTitulo") }}</h3>
      <p class="tu-hint">{{ $t("fitpass.clasesAyuda") }}</p>
      <ul class="mt-2 space-y-2">
        <li
          v-for="c in clases"
          :key="c.oferta_id"
          class="flex flex-wrap items-center gap-3"
        >
          <label class="flex min-w-0 flex-1 items-center gap-2 text-sm">
            <input v-model="c.ofrecida" type="checkbox" />
            <span class="truncate"
              >{{ c.actividad ? `${c.actividad} · ` : "" }}{{ c.nombre }}</span
            >
          </label>
          <input
            v-model="c.disciplina"
            class="tu-input max-w-[14rem]"
            maxlength="40"
            :disabled="!c.ofrecida"
            :placeholder="$t('fitpass.disciplina')"
            :aria-label="$t('fitpass.disciplinaDe', { clase: c.nombre })"
          />
        </li>
      </ul>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-2 text-sm"
        :disabled="guardando"
        @click="guardarClases"
      >
        {{ $t("fitpass.guardarClases") }}
      </button>
    </section>

    <section>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-medium">{{ $t("fitpass.horariosTitulo") }}</h3>
        <button
          v-if="horarios.length > 0"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          data-prueba="descargar-horarios-fitpass"
          @click="descargar"
        >
          {{ $t("fitpass.descargar") }}
        </button>
      </div>
      <p class="tu-hint">{{ $t("fitpass.horariosAyuda") }}</p>
      <p v-if="horarios.length === 0" class="mt-2 text-sm">
        {{ $t("fitpass.sinHorarios") }}
      </p>
      <div v-else class="mt-2 overflow-x-auto">
        <table class="tu-tabla text-sm">
          <thead>
            <tr>
              <th>{{ $t("fitpass.columnas.fecha") }}</th>
              <th>{{ $t("fitpass.columnas.disciplina") }}</th>
              <th>{{ $t("fitpass.columnas.instructor") }}</th>
              <th>{{ $t("fitpass.columnas.lugares") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="h in horarios"
              :key="h.id"
              :style="
                h.estado === 'cancelada'
                  ? { textDecoration: 'line-through' }
                  : undefined
              "
            >
              <td>{{ h.fecha }} · {{ h.hora }}</td>
              <td>{{ h.disciplina ?? h.clase }}</td>
              <td>{{ h.instructor ?? "—" }}</td>
              <td>
                {{
                  h.estado === "cancelada"
                    ? $t("fitpass.estados.cancelada")
                    : (h.lugares ?? $t("fitpass.sinTope"))
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section>
      <h3 class="font-medium">
        {{ $t("fitpass.porMarcarTitulo") }}
        <span class="tabular-nums">{{ porMarcar.length }}</span>
      </h3>
      <p class="tu-hint">{{ $t("fitpass.porMarcarAyuda") }}</p>
      <p v-if="porMarcar.length === 0" class="mt-2 text-sm">
        {{ $t("fitpass.nadaPorMarcar") }}
      </p>
      <ul v-else class="mt-2 space-y-2 text-sm">
        <li
          v-for="p in porMarcar"
          :key="p.id"
          class="flex flex-wrap items-center gap-2"
          data-prueba="por-marcar-fitpass"
        >
          <span class="min-w-0 flex-1">
            {{ p.nombre ?? p.reservacion }} · {{ p.clase ?? "—" }} ·
            {{ fechaHora(p.inicia_en) }} ·
            <strong>{{
              p.asistio ? $t("fitpass.asistio") : $t("fitpass.noAsistio")
            }}</strong>
            <span class="tu-hint">
              ·
              {{
                $t("fitpass.antesDe", { cuando: fechaHora(p.marcar_antes_de) })
              }}</span
            >
          </span>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            @click="yaMarcado(p)"
          >
            {{ $t("fitpass.yaMarcado") }}
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
