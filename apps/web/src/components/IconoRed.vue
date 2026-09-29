<script setup lang="ts">
/**
 * Íconos de redes y contacto para las páginas públicas: trazo simple, un solo color
 * (el del texto), sin logotipos a color.
 */
withDefaults(
  defineProps<{
    red:
      | "instagram"
      | "facebook"
      | "tiktok"
      | "youtube"
      | "sitio_web"
      | "whatsapp"
      | "mapa"
      | "telefono"
      | "calendario";
    tamano?: number;
  }>(),
  { tamano: 20 },
);
</script>

<template>
  <svg
    :width="tamano"
    :height="tamano"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="red === 'instagram'">
      <rect x="3.5" y="3.5" width="17" height="17" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.2" cy="6.8" r="0.6" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="red === 'facebook'">
      <path
        d="M14.5 8H16V4.5h-2.2C11.6 4.5 10.5 6 10.5 8.2V10H8.5v3.5h2V20h3.5v-6.5h2.2L16.7 10H14v-1.4c0-.4.2-.6.5-.6z"
      />
    </template>
    <template v-else-if="red === 'tiktok'">
      <path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5" />
      <path d="M14 4c.4 2.4 2.1 4 4.5 4.2" />
    </template>
    <template v-else-if="red === 'youtube'">
      <rect x="2.8" y="6" width="18.4" height="12" rx="3.5" />
      <path d="M10.5 9.6v4.8l4-2.4z" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="red === 'sitio_web'">
      <circle cx="12" cy="12" r="8.5" />
      <path
        d="M3.5 12h17M12 3.5c2.4 2.4 3.5 5.3 3.5 8.5s-1.1 6.1-3.5 8.5c-2.4-2.4-3.5-5.3-3.5-8.5s1.1-6.1 3.5-8.5z"
      />
    </template>
    <template v-else-if="red === 'whatsapp'">
      <path d="M4.5 19.5l1.2-3.6A7.8 7.8 0 1 1 8.4 18.6z" />
      <path
        d="M9.3 8.9c.2 2.4 2.4 4.6 4.8 4.9l1-1.1-1.6-.9-.7.6c-.9-.4-1.7-1.2-2.1-2.1l.6-.7-.9-1.6z"
      />
    </template>
    <template v-else-if="red === 'mapa'">
      <path d="M12 20.5s6-5.4 6-10.5a6 6 0 1 0-12 0c0 5.1 6 10.5 6 10.5z" />
      <circle cx="12" cy="10" r="2.2" />
    </template>
    <template v-else-if="red === 'telefono'">
      <path
        d="M6.5 3.8h2.8l1.3 3.6-1.8 1.4a10 10 0 0 0 4.4 4.4l1.4-1.8 3.6 1.3v2.8a1.8 1.8 0 0 1-1.9 1.8A14.6 14.6 0 0 1 4.7 5.7a1.8 1.8 0 0 1 1.8-1.9z"
      />
    </template>
    <template v-else>
      <rect x="4" y="5.5" width="16" height="14" rx="2" />
      <path d="M4 10h16M8.5 3.5v4M15.5 3.5v4" />
    </template>
  </svg>
</template>
