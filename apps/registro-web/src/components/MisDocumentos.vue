<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Mis documentos" del alumno: lo que pide el negocio y cómo va cada uno (falta, en
 * revisión, aprobado o rechazado con su motivo). Sube el suyo desde aquí; queda en
 * revisión hasta que el equipo lo valida.
 */
interface DocumentoMio {
  id: string;
  nombre: string | null;
  estado: "pendiente" | "aprobado" | "rechazado";
  motivo: string | null;
  subido_en: string | null;
}
interface Requisito {
  tipo: {
    id: string;
    nombre: string;
    descripcion: string | null;
    obligatorio: boolean;
  };
  documento: DocumentoMio | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const requisitos = ref<Requisito[]>([]);
const subiendo = ref<string | null>(null);

const COLOR: Record<string, string> = {
  pendiente: "var(--aviso)",
  aprobado: "var(--exito)",
  rechazado: "var(--error)",
};

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { requisitos: Requisito[] } }>(
      `${base.value}/mi/documentos`,
    );
    requisitos.value = data.data.requisitos;
  } catch {
    requisitos.value = [];
  }
}

async function subir(r: Requisito, e: Event): Promise<void> {
  const entrada = e.target as HTMLInputElement;
  const archivo = entrada.files?.[0];
  if (!archivo) {
    return;
  }
  subiendo.value = r.tipo.id;
  try {
    const datos = new FormData();
    datos.append("tipo_documento_id", r.tipo.id);
    datos.append("archivo", archivo);
    await api.post(`${base.value}/mi/documentos`, datos);
    toast.exito(t("misDocumentos.subido"));
    await cargar();
  } catch (err) {
    toast.error(mensajeDeError(err, t("misDocumentos.error")));
  } finally {
    subiendo.value = null;
    entrada.value = "";
  }
}

async function ver(d: DocumentoMio): Promise<void> {
  try {
    const { data } = await api.get<Blob>(
      `${base.value}/mi/documentos/${d.id}`,
      { responseType: "blob" },
    );
    const url = URL.createObjectURL(data);
    window.open(url, "_blank", "noopener");
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (err) {
    toast.error(mensajeDeError(err, t("misDocumentos.error")));
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="requisitos.length > 0" class="tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("misDocumentos.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("misDocumentos.ayuda") }}
    </p>
    <ul class="mt-3">
      <li
        v-for="r in requisitos"
        :key="r.tipo.id"
        class="flex flex-wrap items-center justify-between gap-3 border-t py-3 first:border-t-0"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <div class="min-w-0">
          <p class="font-medium">
            {{ r.tipo.nombre }}
            <span
              v-if="r.tipo.obligatorio"
              class="text-xs font-normal"
              :style="{ color: 'var(--texto-suave)' }"
              >· {{ $t("misDocumentos.obligatorio") }}</span
            >
          </p>
          <p
            class="mt-0.5 flex items-center gap-1.5 text-xs"
            :style="{
              color: r.documento
                ? COLOR[r.documento.estado]
                : 'var(--texto-suave)',
            }"
          >
            {{
              r.documento
                ? $t(`misDocumentos.estados.${r.documento.estado}`)
                : $t("misDocumentos.falta")
            }}
            <template
              v-if="r.documento?.estado === 'rechazado' && r.documento.motivo"
            >
              · {{ r.documento.motivo }}
            </template>
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-3 text-sm">
          <button
            v-if="r.documento"
            type="button"
            class="tu-enlace"
            @click="ver(r.documento)"
          >
            {{ $t("misDocumentos.ver") }}
          </button>
          <label
            v-if="r.documento?.estado !== 'aprobado'"
            class="tu-btn tu-btn-fantasma cursor-pointer text-sm"
            :aria-disabled="subiendo === r.tipo.id"
          >
            {{
              subiendo === r.tipo.id
                ? $t("misDocumentos.subiendo")
                : r.documento
                  ? $t("misDocumentos.reemplazar")
                  : $t("misDocumentos.subir")
            }}
            <input
              type="file"
              accept="image/jpeg,image/png,application/pdf"
              class="sr-only"
              :disabled="subiendo !== null"
              @change="subir(r, $event)"
            />
          </label>
        </div>
      </li>
    </ul>
  </div>
</template>
