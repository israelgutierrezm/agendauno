<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Superadmin (ADR 0202): los gimnasios que eligieron a AgendaUno en Wellhub y no se supo
 * de qué negocio son (no traían su código). Se asignan a un negocio por su código (se
 * le pone el Gym ID) o se descartan (acción de peligro: se confirma).
 */
interface Solicitud {
  id: string;
  gym_id: string;
  gimnasio: string | null;
  campos: { key?: string; value?: string }[];
  estado: "pendiente" | "asignada" | "descartada";
  nota: string | null;
  recibida_en: string;
}
const props = defineProps<{ apiUrl: string; token: string }>();
const { t } = useI18n();
const toast = useToastStore();
const solicitudes = ref<Solicitud[]>([]);
const codigos = ref<Record<string, string>>({});
// Qué solicitud se está guardando y con qué acción (su botón muestra el aro).
const ocupado = ref<{ id: string; accion: "asignar" | "descartar" } | null>(
  null,
);
function ocupadoCon(s: Solicitud, accion?: "asignar" | "descartar"): boolean {
  return (
    ocupado.value?.id === s.id &&
    (accion === undefined || ocupado.value.accion === accion)
  );
}

function cliente() {
  return crearClientePlataforma({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });
}

async function cargar(): Promise<void> {
  try {
    const { data } = await cliente().get<{ data: Solicitud[] }>(
      "/api/v1/plataforma/wellhub/solicitudes",
    );
    solicitudes.value = data.data;
  } catch {
    solicitudes.value = [];
  }
}

async function asignar(s: Solicitud): Promise<void> {
  ocupado.value = { id: s.id, accion: "asignar" };
  try {
    await cliente().post(
      `/api/v1/plataforma/wellhub/solicitudes/${s.id}/asignar`,
      { estudio: (codigos.value[s.id] ?? "").trim() },
    );
    toast.exito(t("plataformaAdmin.wellhub.asignada"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

async function descartar(s: Solicitud): Promise<void> {
  if (
    !(await confirmar(
      t("plataformaAdmin.wellhub.confirmarDescartar", {
        gimnasio: s.gimnasio ?? t("plataformaAdmin.wellhub.sinNombre"),
      }),
      { aceptar: t("plataformaAdmin.wellhub.descartar"), peligro: true },
    ))
  ) {
    return;
  }
  ocupado.value = { id: s.id, accion: "descartar" };
  try {
    await cliente().post(
      `/api/v1/plataforma/wellhub/solicitudes/${s.id}/descartar`,
    );
    toast.exito(t("plataformaAdmin.wellhub.descartada"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section
    v-if="solicitudes.length > 0"
    class="tu-card p-5"
    data-prueba="solicitudes-wellhub"
  >
    <!-- Va en Configuración › «Wellhub y TotalPass», debajo de los países: un
         título de sección como el de su hermana, no el nombre de la pestaña. -->
    <h2 class="tu-titulo-seccion">
      {{ $t("plataformaAdmin.wellhub.titulo") }}
    </h2>
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("plataformaAdmin.wellhub.ayuda") }}
    </p>
    <ul class="mt-4 space-y-3">
      <li
        v-for="s in solicitudes"
        :key="s.id"
        class="flex flex-wrap items-end gap-3"
      >
        <div class="min-w-0 flex-1 text-sm">
          <strong>{{
            s.gimnasio ?? $t("plataformaAdmin.wellhub.sinNombre")
          }}</strong>
          · Gym ID {{ s.gym_id }}
          <span
            v-if="s.campos.length > 0"
            class="block text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ s.campos.map((c) => `${c.key}: ${c.value}`).join(" · ") }}</span
          >
          <span v-if="s.nota" class="tu-error-campo block">{{ s.nota }}</span>
        </div>
        <label class="sr-only" :for="`negocio-${s.id}`">{{
          $t("plataformaAdmin.wellhub.codigo")
        }}</label>
        <input
          :id="`negocio-${s.id}`"
          v-model="codigos[s.id]"
          class="tu-input max-w-[12rem]"
          autocomplete="off"
          :placeholder="$t('plataformaAdmin.wellhub.codigo')"
        />
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="ocupadoCon(s) || !(codigos[s.id] ?? '').trim()"
          :aria-busy="ocupadoCon(s, 'asignar') || undefined"
          :data-prueba="`asignar-${s.id}`"
          @click="asignar(s)"
        >
          {{ $t("plataformaAdmin.wellhub.asignar") }}
        </button>
        <!-- Descartar la quita de aquí: acción de peligro, con confirmación. -->
        <button
          type="button"
          class="tu-btn tu-btn-peligro"
          :disabled="ocupadoCon(s)"
          :aria-busy="ocupadoCon(s, 'descartar') || undefined"
          :data-prueba="`descartar-${s.id}`"
          @click="descartar(s)"
        >
          {{ $t("plataformaAdmin.wellhub.descartar") }}
        </button>
      </li>
    </ul>
  </section>
</template>
