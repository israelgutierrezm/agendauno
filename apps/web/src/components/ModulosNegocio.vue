<script setup lang="ts">
import axios from "axios";
import { ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * En la ficha de un negocio, para el superadmin: los módulos que se encienden por
 * negocio (ADR 0123; por ahora, Familias). Apagados por omisión; solo el superadmin los
 * cambia. Apagado, el módulo no existe para el negocio ni para sus clientes; lo que ya
 * registró se conserva para cuando se vuelva a encender.
 */
export interface ModuloDelNegocio {
  clave: string;
  etiqueta: string;
  descripcion: string;
  habilitado: boolean;
}

const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  nombre: string;
  inicial: ModuloDelNegocio[];
}>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const modulos = ref<ModuloDelNegocio[]>(props.inicial.map((m) => ({ ...m })));
const guardando = ref<string | null>(null);

async function cambiar(m: ModuloDelNegocio): Promise<void> {
  const habilitado = !m.habilitado;
  if (
    !(await confirmar(
      t(
        habilitado
          ? "plataformaAdmin.modulos.confirmarEncender"
          : "plataformaAdmin.modulos.confirmarApagar",
        { modulo: m.etiqueta, estudio: props.nombre },
      ),
      { peligro: !habilitado },
    ))
  ) {
    return;
  }
  guardando.value = m.clave;
  try {
    const { data } = await cliente.put<{ data: ModuloDelNegocio[] }>(
      `/api/v1/plataforma/estudios/${props.slug}/modulos`,
      { modulo: m.clave, habilitado },
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    modulos.value = data.data;
    toast.exito(
      t(
        habilitado
          ? "plataformaAdmin.modulos.encendido"
          : "plataformaAdmin.modulos.apagado",
        { modulo: m.etiqueta },
      ),
    );
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = null;
  }
}
</script>

<template>
  <section data-prueba="modulos-negocio">
    <h3
      class="text-xs font-medium uppercase tracking-wide"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plataformaAdmin.modulos.titulo") }}
    </h3>
    <ul class="mt-2 space-y-3">
      <li v-for="m in modulos" :key="m.clave" :data-modulo="m.clave">
        <div class="flex items-center justify-between gap-3">
          <span class="mn-estado text-sm">
            <span
              class="mn-punto"
              :class="m.habilitado ? 'mn-exito' : 'mn-gris'"
              aria-hidden="true"
            ></span>
            {{ m.etiqueta }} ·
            {{
              m.habilitado
                ? $t("plataformaAdmin.modulos.estadoEncendido")
                : $t("plataformaAdmin.modulos.estadoApagado")
            }}
          </span>
          <button
            class="tu-btn tu-btn-fantasma text-sm"
            type="button"
            :disabled="guardando !== null"
            @click="cambiar(m)"
          >
            {{
              m.habilitado
                ? $t("plataformaAdmin.modulos.apagar")
                : $t("plataformaAdmin.modulos.encender")
            }}
          </button>
        </div>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ m.descripcion }}
        </p>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.mn-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.mn-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
}
.mn-exito {
  background: var(--exito);
}
.mn-gris {
  background: var(--texto-suave);
}
</style>
