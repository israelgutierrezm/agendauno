<script setup lang="ts">
import { onMounted } from "vue";

import { useParticipantes } from "@/lib/participantes";

/**
 * De quién es la cuenta que se está viendo (familias, ADR 0123): la suya o la de una
 * persona a su cargo con la autorización vigente. Solo aparece si tiene a alguien
 * autorizado; al elegir, reservas, planes, pagos y documentos son de esa persona y lo
 * dice siempre a la vista, para no reservar por la persona equivocada.
 */
const participantes = useParticipantes();

onMounted(() => {
  if (!participantes.cargado) {
    void participantes.cargar().catch(() => undefined);
  }
});

function elegir(evento: Event): void {
  const valor = (evento.target as HTMLSelectElement).value;
  participantes.elegir(valor !== "" ? valor : null);
}
</script>

<template>
  <div
    v-if="participantes.autorizados.length > 0"
    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm border-b"
    :style="{ borderColor: 'var(--borde)', background: 'var(--superficie)' }"
    data-prueba="barra-participante"
  >
    <label for="cuenta-de" class="font-medium">{{
      $t("familias.cuentaDe")
    }}</label>
    <select
      id="cuenta-de"
      class="tu-input w-auto py-1"
      :value="participantes.actual ?? ''"
      @change="elegir"
    >
      <option value="">{{ $t("familias.miCuenta") }}</option>
      <option
        v-for="p in participantes.autorizados"
        :key="p.persona.id"
        :value="p.persona.id"
      >
        {{ p.persona.nombre }}
      </option>
    </select>
    <span
      v-if="participantes.nombreActual"
      class="inline-flex items-center gap-1.5"
      :style="{ color: 'var(--texto-suave)' }"
      role="status"
    >
      <span
        class="inline-block size-1.5 rounded-full"
        :style="{ background: 'var(--primario)' }"
        aria-hidden="true"
      />
      {{ $t("familias.gestionando", { nombre: participantes.nombreActual }) }}
    </span>
  </div>
</template>
