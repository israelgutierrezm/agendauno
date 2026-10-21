<script setup lang="ts">
/**
 * El distintivo de cada plataforma con que se conecta el negocio (bienestar y pasarelas
 * de pago): su color y una marca simple, no su logotipo oficial. Wellhub, una flor en
 * su rosa; TotalPass, sus iniciales sobre negro; Stripe, Mercado Pago y OpenPay, sus
 * iniciales en su color; la ventanilla (depósito), un banco.
 */
withDefaults(
  defineProps<{
    proveedor: string;
    /** Lado del distintivo, en píxeles. */
    tam?: number;
  }>(),
  { tam: 44 },
);

// Seis pétalos alrededor del centro.
const PETALOS = [0, 60, 120, 180, 240, 300];

// Las que se muestran con sus iniciales.
const INICIALES: Record<string, string> = {
  totalpass: "TP",
  fitpass: "FP",
  stripe: "S",
  mercadopago: "MP",
  openpay: "OP",
};
</script>

<template>
  <span
    class="logo-plataforma"
    :class="`logo-${proveedor}`"
    :style="{ width: `${tam}px`, height: `${tam}px` }"
    aria-hidden="true"
  >
    <svg
      v-if="proveedor === 'wellhub'"
      viewBox="0 0 24 24"
      :width="tam * 0.62"
      :height="tam * 0.62"
    >
      <ellipse
        v-for="g in PETALOS"
        :key="g"
        cx="12"
        cy="6.6"
        rx="2.6"
        ry="4.6"
        :transform="`rotate(${g} 12 12)`"
        fill="currentColor"
      />
      <circle cx="12" cy="12" r="2.3" fill="#fff" />
    </svg>
    <svg
      v-else-if="proveedor === 'ventanilla'"
      viewBox="0 0 24 24"
      :width="tam * 0.55"
      :height="tam * 0.55"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M3.5 9.5 12 4l8.5 5.5z" />
      <path d="M6 10v7M10 10v7M14 10v7M18 10v7" />
      <path d="M4 20h16" />
    </svg>
    <span v-else class="logo-iniciales">{{
      INICIALES[proveedor] ?? proveedor.slice(0, 2).toUpperCase()
    }}</span>
  </span>
</template>

<style scoped>
.logo-plataforma {
  display: inline-grid;
  flex-shrink: 0;
  place-items: center;
  border-radius: 0.7rem;
  background: var(--superficie-2);
  color: var(--texto);
}
.logo-wellhub {
  background: #fde8ee;
  color: #e5336a;
}
.logo-totalpass {
  background: #111111;
  color: #ffffff;
}
.logo-stripe {
  background: #635bff;
  color: #ffffff;
}
.logo-mercadopago {
  background: #0090d6;
  color: #ffffff;
}
.logo-openpay {
  background: #13325b;
  color: #ffffff;
}
.logo-ventanilla {
  background: var(--primario-suave);
  color: var(--primario);
}
.dark .logo-wellhub {
  background: color-mix(in srgb, #e5336a 18%, var(--superficie));
}
.logo-iniciales {
  font-size: 0.95rem;
  font-weight: 800;
  font-style: italic;
  letter-spacing: -0.04em;
}
.logo-stripe .logo-iniciales {
  font-size: 1.15rem;
  font-style: normal;
}
</style>
