<script setup lang="ts">
import { inject } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import type { MenuItem, NavEstado } from "@/components/nav";

defineProps<{ items: MenuItem[]; nivel: number }>();

const { t } = useI18n();
const estado = inject<NavEstado>("navEstado");

function abierto(clave: string): boolean {
  return estado?.abiertos.value.has(clave) ?? false;
}
const compacto = (): boolean => estado?.compacto.value ?? false;
</script>

<template>
  <template v-for="item in items" :key="item.clave">
    <!-- Grupo (nivel 1/2): boton que expande/colapsa sus hijos -->
    <div v-if="item.hijos && item.hijos.length > 0">
      <button
        type="button"
        class="tu-side-link w-full"
        :class="{ 'lg:justify-center': compacto() }"
        :title="compacto() ? (item.texto ?? t(item.etiqueta)) : undefined"
        @click="estado?.alternar(item.clave)"
      >
        <IconoNav :nombre="item.icono ?? 'punto'" :tam="20" class="shrink-0" />
        <span v-show="!compacto()" class="truncate flex-1 text-left">{{
          item.texto ?? t(item.etiqueta)
        }}</span>
        <IconoNav
          v-show="!compacto()"
          nombre="chevron"
          :tam="14"
          class="shrink-0 transition-transform"
          :class="{ 'rotate-90': abierto(item.clave) }"
        />
      </button>
      <div
        v-show="!compacto() && abierto(item.clave)"
        class="ml-3.5 pl-2 border-l space-y-1 mt-1"
        :style="{ borderColor: 'var(--barra-borde)' }"
      >
        <NavArbol :items="item.hijos" :nivel="nivel + 1" />
      </div>
    </div>

    <!-- Hoja: enlace navegable -->
    <RouterLink
      v-else-if="item.ruta"
      class="tu-side-link"
      :class="{ 'lg:justify-center': compacto() }"
      :to="{ name: item.ruta }"
      :title="compacto() ? (item.texto ?? t(item.etiqueta)) : undefined"
      @click="estado?.cerrarCajon()"
    >
      <IconoNav
        :nombre="item.icono ?? 'punto'"
        :tam="nivel > 1 ? 18 : 20"
        class="shrink-0"
      />
      <span v-show="!compacto()" class="truncate">{{
        item.texto ?? t(item.etiqueta)
      }}</span>
    </RouterLink>
  </template>
</template>
