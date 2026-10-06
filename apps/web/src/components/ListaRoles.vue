<script setup lang="ts">
import { useI18n } from "vue-i18n";

import type { RolDisponible } from "@/lib/roles";
import { plural, terminoParaPersona } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

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

// Con las palabras del negocio (Barbero, citas, clientes…): al entrar aún rigen los
// textos base, así que el nombre y lo que verá se arman aquí.
const { t, te } = useI18n();
const sesion = useSesionTenantStore();
function nombre(rol: RolDisponible): string {
  if (rol.nombre) {
    return rol.nombre;
  }
  // En el género de quien entra, si su ficha lo dice (Alumno o Alumna, Dueña).
  const genero = sesion.usuario?.genero;
  if (rol.clave === "instructor") {
    return terminoParaPersona(sesion.terminologia.instructor, genero);
  }
  if (rol.clave === "miembro") {
    return terminoParaPersona(sesion.terminologia.miembro, genero);
  }
  return te(`usuarios.rol.${rol.clave}`)
    ? terminoParaPersona(t(`usuarios.rol.${rol.clave}`), genero)
    : rol.clave;
}
function detalle(rol: RolDisponible): string {
  const terminos = {
    sesiones: plural(sesion.terminologia.sesion).toLowerCase(),
    miembros: plural(sesion.terminologia.miembro).toLowerCase(),
  };
  const modalidad = sesion.esCitas ? "Citas" : "Clases";
  if (rol.faceta === "instructor") {
    return t(`operacion.rolActivo.detalle.instructor${modalidad}`, terminos);
  }
  if (rol.faceta === "miembro") {
    return t(`operacion.rolActivo.detalle.miembro${modalidad}`, terminos);
  }
  return t("operacion.rolActivo.detalle.equipo", terminos);
}
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
          <span class="lr-nombre">{{ nombre(rol) }}</span>
          <span class="lr-detalle">{{ detalle(rol) }}</span>
        </span>
        <span
          v-if="aplicando === rol.clave"
          class="lr-marca"
          role="status"
          :aria-label="$t('comun.cargando')"
          ><span class="lr-spinner" aria-hidden="true"
        /></span>
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
.lr-spinner {
  width: 1.1rem;
  height: 1.1rem;
  border: 2px solid var(--borde);
  border-top-color: var(--acento);
  border-radius: 50%;
  animation: lr-girar 0.7s linear infinite;
}
@keyframes lr-girar {
  to {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .lr-spinner {
    animation: none;
  }
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
