<script setup lang="ts">
import { computed, useId } from "vue";

import FotoAmpliable from "@/components/FotoAmpliable.vue";
import IconoNav from "@/components/IconoNav.vue";

/**
 * «Ver horarios de» al agendar una cita: todo el equipo o alguien específico,
 * elegido por su foto (la lupa la muestra en grande) por si no recuerdan su nombre.
 * `v-model`: "" = todo el equipo; si no, el id de la persona. Al elegir «alguien
 * específico» queda el primero. Lo usan la página pública y la cuenta del cliente;
 * la app tiene el mismo diseño.
 */
export interface Profesional {
  id: string;
  nombre: string;
  foto_url?: string | null;
}

const props = defineProps<{
  profesionales: Profesional[];
  modelValue: string;
}>();
const emit = defineEmits<{ "update:modelValue": [string] }>();

// Nombres únicos de los grupos de opciones (puede haber más de uno en la página).
const id = useId();
const conAlguien = computed(() => props.modelValue !== "");

function verTodos(): void {
  emit("update:modelValue", "");
}
function verAlguien(): void {
  if (props.modelValue === "") {
    emit("update:modelValue", props.profesionales[0]?.id ?? "");
  }
}

// En su tarjeta, el primer nombre; el completo si dos lo comparten.
function nombreTarjeta(p: Profesional): string {
  const primero = (n: string): string => n.trim().split(/\s+/)[0] ?? n;
  const iguales = props.profesionales.filter(
    (o) => primero(o.nombre) === primero(p.nombre),
  ).length;
  return iguales > 1 ? p.nombre : primero(p.nombre);
}
</script>

<template>
  <fieldset data-prueba="ver-horarios-de">
    <legend class="tu-label">
      {{ $t("perfilPublico.agendar.verHorariosDe") }}
    </legend>
    <!-- Selector compacto de dos opciones, sin repetir las instrucciones. -->
    <div class="ep-modo">
      <label
        class="ep-opcion"
        :class="{ 'ep-opcion--activa': !conAlguien }"
        data-prueba="filtro-todos"
      >
        <span class="ep-icono" aria-hidden="true">
          <IconoNav nombre="personas" :tam="22" />
        </span>
        <span class="ep-texto">
          <strong>{{ $t("perfilPublico.agendar.todoElEquipo") }}</strong>
        </span>
        <input
          type="radio"
          :name="`${id}-modo`"
          class="ep-oculto"
          :checked="!conAlguien"
          @change="verTodos"
        />
      </label>
      <label
        class="ep-opcion"
        :class="{ 'ep-opcion--activa': conAlguien }"
        data-prueba="filtro-alguien"
      >
        <span class="ep-icono" aria-hidden="true">
          <IconoNav nombre="miembros" :tam="22" />
        </span>
        <span class="ep-texto">
          <strong>{{ $t("perfilPublico.agendar.alguienEspecifico") }}</strong>
        </span>
        <input
          type="radio"
          :name="`${id}-modo`"
          class="ep-oculto"
          :checked="conAlguien"
          @change="verAlguien"
        />
      </label>
    </div>

    <template v-if="conAlguien">
      <p :id="`${id}-titulo`" class="tu-label mt-4">
        {{ $t("perfilPublico.agendar.seleccionaProfesionista") }}
      </p>
      <div
        class="ep-profesionales"
        role="radiogroup"
        :aria-labelledby="`${id}-titulo`"
      >
        <label
          v-for="p in profesionales"
          :key="p.id"
          class="ep-profesional"
          :class="{ 'ep-profesional--activo': modelValue === p.id }"
          :data-prueba="`filtro-${p.id}`"
          :title="p.nombre"
        >
          <input
            type="radio"
            :name="`${id}-profesional`"
            :value="p.id"
            class="ep-oculto"
            :aria-label="p.nombre"
            :checked="modelValue === p.id"
            @change="emit('update:modelValue', p.id)"
          />
          <FotoAmpliable :nombre="p.nombre" :foto="p.foto_url" tam="xl" />
          <span class="ep-nombre">{{ nombreTarjeta(p) }}</span>
        </label>
      </div>
    </template>
  </fieldset>
</template>

<style scoped>
.ep-modo {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0;
}
.ep-opcion {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  min-height: 3.6rem;
  padding: 0.65rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0 10px 10px 0;
  background: var(--superficie);
  cursor: pointer;
}
.ep-opcion:first-child {
  border-radius: 10px 0 0 10px;
}
.ep-opcion:hover {
  border-color: var(--primario);
}
.ep-opcion--activa {
  border-color: var(--primario);
  background: var(--primario-suave);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.ep-opcion:has(:focus-visible),
.ep-profesional:has(:focus-visible) {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.ep-icono {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.6rem;
  height: 1.6rem;
  flex-shrink: 0;
  color: var(--texto-suave);
}
.ep-opcion--activa .ep-icono {
  color: var(--primario);
}
.ep-texto {
  min-width: 0;
  overflow-wrap: anywhere;
  text-align: center;
}
.ep-texto strong {
  display: block;
  font-weight: 500;
  font-size: 0.95rem;
}
.ep-profesionales {
  display: flex;
  gap: 0.6rem;
  overflow-x: auto;
  padding: 0.25rem 0.2rem 0.65rem;
  scroll-snap-type: x proximity;
  scrollbar-width: thin;
  scrollbar-color: var(--borde) transparent;
}
.ep-profesional {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 0.6rem;
  flex: 1 0 8.5rem;
  min-width: 0;
  max-width: 13rem;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 12px;
  background: var(--superficie);
  cursor: pointer;
  text-align: center;
  scroll-snap-align: start;
  transition:
    border-color 150ms ease,
    background-color 150ms ease;
}
.ep-profesional:hover {
  border-color: var(--primario);
}
.ep-profesional--activo {
  border-color: var(--primario);
  background: var(--primario-suave);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.ep-nombre {
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
  font-size: 0.95rem;
  line-height: 1.3;
}
/* El círculo de la opción no se ve: toda la tarjeta es la opción. */
.ep-oculto {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}
@media (max-width: 520px) {
  .ep-opcion {
    padding: 0.65rem 0.5rem;
    gap: 0.4rem;
  }
  .ep-texto strong {
    font-size: 0.8rem;
  }
  .ep-profesional {
    flex-basis: 6.5rem;
    padding: 0.75rem 0.5rem;
  }
  .ep-profesional :deep(.fa-foto--xl > img),
  .ep-profesional :deep(.fa-foto--xl > span) {
    width: 3.5rem;
    height: 3.5rem;
  }
}
</style>
