<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from "vue";

import PanelLateral from "@/components/PanelLateral.vue";

/**
 * Escanea el pase QR del alumno con la cámara (recepción en tableta o celular). Usa
 * el detector de códigos del navegador; donde no existe, el botón no se muestra y
 * se usa un lector USB, que escribe el código en el buscador.
 */
interface DetectorCodigos {
  detect(fuente: HTMLVideoElement): Promise<Array<{ rawValue: string }>>;
}
type ConstructorDetector = new (opciones?: {
  formats?: string[];
}) => DetectorCodigos;

const props = defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ codigo: [valor: string]; cerrar: [] }>();

const video = ref<HTMLVideoElement | null>(null);
const error = ref(false);
let flujo: MediaStream | null = null;
let temporizador: ReturnType<typeof setInterval> | undefined;

function detector(): ConstructorDetector | undefined {
  return (window as unknown as { BarcodeDetector?: ConstructorDetector })
    .BarcodeDetector;
}

async function iniciar(): Promise<void> {
  const Detector = detector();
  error.value = false;
  if (!Detector || !navigator.mediaDevices?.getUserMedia) {
    error.value = true;
    return;
  }
  try {
    flujo = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: "environment" },
    });
    await nextTick();
    if (!video.value) {
      return;
    }
    video.value.srcObject = flujo;
    await video.value.play();
    const lector = new Detector({ formats: ["qr_code"] });
    temporizador = setInterval(() => {
      if (!video.value) {
        return;
      }
      void lector
        .detect(video.value)
        .then((codigos) => {
          const valor = codigos[0]?.rawValue;
          if (valor) {
            detener();
            emit("codigo", valor);
          }
        })
        .catch(() => undefined);
    }, 300);
  } catch {
    error.value = true;
    detener();
  }
}

function detener(): void {
  clearInterval(temporizador);
  flujo?.getTracks().forEach((pista) => pista.stop());
  flujo = null;
}

watch(
  () => props.abierto,
  (abierto) => {
    if (abierto) {
      void iniciar();
    } else {
      detener();
    }
  },
  { immediate: true },
);

onBeforeUnmount(detener);
</script>

<template>
  <PanelLateral
    :abierto="abierto"
    :titulo="$t('paseEntrada.escanear')"
    @cerrar="emit('cerrar')"
  >
    <div class="p-5">
      <p v-if="error" class="text-sm" role="alert" style="color: var(--error)">
        {{ $t("paseEntrada.sinCamara") }}
      </p>
      <template v-else>
        <video
          ref="video"
          class="w-full rounded-xl bg-black"
          muted
          playsinline
        />
        <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("paseEntrada.apunta") }}
        </p>
      </template>
    </div>
  </PanelLateral>
</template>
