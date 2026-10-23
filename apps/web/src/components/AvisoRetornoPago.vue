<script setup lang="ts">
import {
  claveDeRetorno,
  colorDeRetorno,
  type EstadoRetornoPago,
} from "@/lib/retornoPago";

/**
 * Cómo quedó el pago al volver de la pasarela: «Estamos confirmando» (neutro) hasta que
 * el servidor lo confirma, y solo entonces «Pago confirmado», en verde. Lo que sigue
 * después de pagar va en el hueco `confirmado`.
 */
defineProps<{ estado: EstadoRetornoPago }>();
</script>

<template>
  <p
    class="text-sm"
    role="status"
    data-prueba="retorno-pago"
    :data-estado="estado"
    :style="{ color: colorDeRetorno(estado) }"
  >
    {{ $t(claveDeRetorno(estado)) }}
    <slot v-if="estado === 'confirmado'" name="confirmado" />
  </p>
</template>
