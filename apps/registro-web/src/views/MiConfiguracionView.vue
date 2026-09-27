<script setup lang="ts">
import { onMounted } from "vue";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import MiPrivacidad from "@/components/MiPrivacidad.vue";
import { useMiCuenta } from "@/lib/miCuenta";

/**
 * Configuración del portal: su privacidad (promociones, descargar sus datos, pedir su
 * baja) y el acceso a su perfil (nombre, foto, correo, contraseña y la liga para ver
 * sus reservas en el calendario del teléfono).
 */
const cuenta = useMiCuenta();

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="mx-auto max-w-3xl px-4 py-8">
    <EncabezadoSeccion :titulo="$t('portal.configuracion.titulo')" />

    <div class="mt-5 tu-card p-5">
      <h2 class="font-semibold">{{ $t("portal.configuracion.perfil") }}</h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("portal.configuracion.perfilAyuda") }}
      </p>
      <RouterLink
        :to="{ name: 'mi-perfil' }"
        class="tu-enlace mt-3 inline-block text-sm"
      >
        {{ $t("portal.configuracion.perfilIr") }}
      </RouterLink>
    </div>

    <MiPrivacidad v-if="cuenta.personaId.value !== null" class="mt-5" />
  </section>
</template>
