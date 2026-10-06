<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import type { Indicador } from "@/components/TarjetasIndicadores.vue";

/**
 * Indicadores de la agenda (del día en citas, de la semana en clases) en UNA línea:
 * la cifra y su etiqueta, sin tarjetas, para que el calendario quede arriba. Lo que
 * pide atención va en el color de aviso; la tendencia, discreta al lado. Los valores
 * los calcula la vista según la modalidad.
 */
defineProps<{ tarjetas: Indicador[] }>();
</script>

<template>
  <ul class="ak" data-prueba="agenda-kpis">
    <li
      v-for="k in tarjetas"
      :key="k.clave"
      class="ak-dato"
      :data-prueba="`indicador-${k.clave}`"
    >
      <strong
        class="ak-valor"
        :style="k.aviso ? { color: 'var(--aviso)' } : undefined"
        >{{ k.valor }}</strong
      >
      <span class="ak-etiqueta">{{ k.etiqueta }}</span>
      <span
        v-if="k.tendencia"
        class="ak-tendencia"
        :class="
          k.tendencia.buena === true
            ? 'ak-buena'
            : k.tendencia.buena === false
              ? 'ak-mala'
              : ''
        "
        :title="k.tendencia.titulo"
        data-prueba="tendencia"
      >
        <IconoNav
          v-if="k.tendencia.direccion !== 'igual'"
          :nombre="k.tendencia.direccion === 'sube' ? 'arriba' : 'abajo'"
          :tam="11"
        />
        {{ k.tendencia.texto }}
      </span>
    </li>
  </ul>
</template>

<style scoped>
/* Cifras en línea separadas por un punto medio; si no caben, bajan de renglón. */
.ak {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 1.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.85rem;
}
.ak-dato {
  display: inline-flex;
  align-items: baseline;
  gap: 0.35rem;
  white-space: nowrap;
}
.ak-valor {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.ak-etiqueta {
  color: var(--texto-suave);
}
.ak-tendencia {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.ak-buena {
  color: var(--exito-texto, var(--exito));
}
.ak-mala {
  color: var(--error);
}
</style>
