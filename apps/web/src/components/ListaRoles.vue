<script setup lang="ts">
import type { RolDisponible } from "@/lib/roles";

/**
 * Los roles con los que la persona puede entrar, como tarjetas: nombre del rol y qué
 * verá con él. Marca el activo (o el de la última vez, al entrar). La usan la pantalla
 * «¿Cómo quieres entrar?» y el panel «Cambiar de rol».
 */
defineProps<{
  roles: RolDisponible[];
  // Clave del rol marcado.
  marcado: string | null;
  // Cómo se llama la marca: «Activo» en el panel, «La última vez» al entrar.
  etiquetaMarca: string;
  // Clave del rol que se está aplicando (deshabilita todo mientras tanto).
  aplicando: string | null;
}>();
const emit = defineEmits<{ elegir: [clave: string] }>();
</script>

<template>
  <ul class="lr-lista">
    <li v-for="rol in roles" :key="rol.clave">
      <button
        type="button"
        class="lr-rol"
        :class="{ 'lr-marcado': rol.clave === marcado }"
        :aria-pressed="rol.clave === marcado"
        :disabled="aplicando !== null"
        @click="emit('elegir', rol.clave)"
      >
        <span class="lr-texto">
          <span class="lr-nombre">
            {{
              rol.nombre ??
              ($te(`usuarios.rol.${rol.clave}`)
                ? $t(`usuarios.rol.${rol.clave}`)
                : rol.clave)
            }}
          </span>
          <span class="lr-detalle">
            {{ $t(`operacion.rolActivo.faceta.${rol.faceta}`) }}
          </span>
        </span>
        <span v-if="aplicando === rol.clave" class="lr-marca">…</span>
        <span v-else-if="rol.clave === marcado" class="lr-marca">
          <span class="lr-punto" aria-hidden="true"></span>
          {{ etiquetaMarca }}
        </span>
      </button>
    </li>
  </ul>
</template>

<style scoped>
.lr-lista {
  display: grid;
  gap: 0.6rem;
}
.lr-rol {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.9rem 1rem;
  border-radius: 0.85rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto);
  text-align: left;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}
.lr-rol:hover:not(:disabled) {
  border-color: var(--acento);
}
.lr-rol:disabled {
  cursor: default;
  opacity: 0.7;
}
.lr-marcado {
  border-color: var(--acento);
  box-shadow: 0 0 0 1px var(--acento);
}
.lr-texto {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
}
.lr-nombre {
  font-weight: 600;
}
.lr-detalle {
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.lr-marca {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.lr-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--acento);
}
</style>
