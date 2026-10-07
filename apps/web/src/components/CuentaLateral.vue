<script setup lang="ts">
import { RouterLink } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { usePantallaCompleta } from "@/lib/pantallaCompleta";
import { usePerfilActual } from "@/lib/perfilActual";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * La cuenta al pie del menú lateral en el teléfono: ahí la barra superior solo dice
 * con qué sucursal se trabaja, así que quién entró y sus opciones (perfil, cambiar de
 * rol, apariencia, configuración, pantalla completa y salir) viven aquí. En pantallas
 * más anchas están en la barra superior ({@link MenuPerfil}).
 */
const emit = defineEmits<{
  navegar: [];
  roles: [];
  apariencia: [];
  salir: [];
}>();

const sesion = useSesionTenantStore();
const { iniciales, rol, puedeConfigurar } = usePerfilActual();
const pantalla = usePantallaCompleta();
</script>

<template>
  <div class="cl" data-prueba="cuenta-lateral">
    <RouterLink
      :to="{ name: 'mi-perfil' }"
      class="cl-quien"
      @click="emit('navegar')"
    >
      <img
        v-if="sesion.usuario?.foto_url"
        :src="sesion.usuario.foto_url"
        alt=""
        class="cl-avatar"
      />
      <span v-else class="cl-avatar" aria-hidden="true">{{ iniciales }}</span>
      <span class="min-w-0">
        <span class="cl-nombre">{{
          sesion.usuario?.nombre_corto ?? sesion.usuario?.nombre
        }}</span>
        <span class="cl-rol">{{ rol }}</span>
      </span>
    </RouterLink>

    <button
      v-if="sesion.tieneVariosRoles"
      type="button"
      class="tu-side-link w-full"
      @click="emit('roles')"
    >
      <!-- Tiene otro rol con el cual entrar: las flechas se mueven (como en la barra). -->
      <IconoNav nombre="intercambio" :tam="18" class="tu-rol-flechas" />
      <span>{{ $t("operacion.rolActivo.cambiar") }}</span>
    </button>
    <button
      type="button"
      class="tu-side-link w-full"
      @click="emit('apariencia')"
    >
      <IconoNav nombre="apariencia" :tam="18" />
      <span>{{ $t("tema.apariencia") }}</span>
    </button>
    <RouterLink
      v-if="puedeConfigurar"
      :to="{ name: 'ajustes' }"
      class="tu-side-link"
      @click="emit('navegar')"
    >
      <IconoNav nombre="ajustes" :tam="18" />
      <span>{{ $t("nav.configuracion") }}</span>
    </RouterLink>
    <!-- Solo donde el navegador lo permite (en iPhone, no). -->
    <button
      v-if="pantalla.disponible.value"
      type="button"
      class="tu-side-link w-full"
      :disabled="pantalla.pendiente.value"
      @click="pantalla.alternar()"
    >
      <IconoNav
        :nombre="
          pantalla.activa.value ? 'reducir-pantalla' : 'ampliar-pantalla'
        "
        :tam="18"
      />
      <span>{{
        $t(
          pantalla.activa.value
            ? "pantallaCompleta.salir"
            : "pantallaCompleta.ver",
        )
      }}</span>
    </button>
    <button type="button" class="tu-side-link w-full" @click="emit('salir')">
      <IconoNav nombre="salir" :tam="18" />
      <span>{{ $t("panel.salir") }}</span>
    </button>
  </div>
</template>

<style scoped>
.cl {
  display: grid;
  gap: 0.15rem;
}
.cl-quien {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 0.35rem;
  padding: 0.5rem 0.55rem;
  border-radius: 0.75rem;
  color: var(--barra-texto);
  text-decoration: none;
}
.cl-quien:hover {
  background: var(--barra-suave);
}
.cl-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  object-fit: cover;
  font-size: 0.78rem;
  font-weight: 600;
  background: var(--barra-suave);
  color: var(--barra-titulo, #ffffff);
}
.cl-nombre {
  display: block;
  overflow: hidden;
  color: var(--barra-titulo, #ffffff);
  font-size: 0.875rem;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cl-rol {
  display: block;
  font-size: 0.72rem;
  opacity: 0.7;
}
</style>
