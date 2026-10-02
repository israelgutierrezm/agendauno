<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import IconoNav from "@/components/IconoNav.vue";
import { usePantallaCompleta } from "@/lib/pantallaCompleta";
import { useToastStore } from "@/stores/toast";

const props = defineProps<{ agenda?: boolean }>();
const { t } = useI18n();
const { activa, disponible, pendiente, alternar } = usePantallaCompleta(
  props.agenda,
);
const etiqueta = computed(() =>
  t(activa.value ? "pantallaCompleta.salir" : "pantallaCompleta.ver"),
);
async function cambiar() {
  if (!(await alternar())) {
    useToastStore().info(
      t(
        props.agenda
          ? "pantallaCompleta.agendaAlternativa"
          : "pantallaCompleta.noDisponible",
      ),
    );
  }
}
</script>

<template>
  <button
    type="button"
    :class="agenda ? 'tu-btn tu-btn-fantasma' : 'tu-icono-btn'"
    :disabled="pendiente || (!agenda && !disponible)"
    :aria-label="etiqueta"
    :aria-pressed="activa"
    :title="
      !agenda && !disponible ? $t('pantallaCompleta.noDisponible') : etiqueta
    "
    @click="cambiar"
  >
    <IconoNav
      :nombre="activa ? 'reducir-pantalla' : 'ampliar-pantalla'"
      :tam="18"
    />
    <span v-if="agenda">{{ etiqueta }}</span>
  </button>
</template>
