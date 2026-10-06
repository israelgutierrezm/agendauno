<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { puedeEntrar } from "@/lib/acceso";
import { facetaActiva, nombreDeRol } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Menú de perfil de la barra superior: quién entró y con qué rol; abre su perfil,
 * la configuración del negocio (si la puede ver) y cierra la sesión. Sin negros
 * pesados: el texto principal va un poco más suave que el de la página.
 */
defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ alternar: []; cerrar: []; salir: [] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();

const iniciales = computed(
  () =>
    (sesion.usuario?.nombre ?? "")
      .split(" ")
      .slice(0, 2)
      .map((parte) => parte.charAt(0))
      .join("")
      .toUpperCase() || "·",
);
const rol = computed(() =>
  nombreDeRol(
    sesion.usuario?.rol ?? "",
    sesion.usuario?.roles_disponibles,
    (llave) => (te(llave) ? t(llave) : null),
  ),
);
// Configuración del negocio: si alguna opción se puede abrir. Quien entra como
// instructor no administra el negocio: su único permiso de documentos le abriría
// una portada con «Documentos requeridos», sin nada que configurar.
const puedeConfigurar = computed(
  () =>
    puedeEntrar("ajustes", sesion) &&
    facetaActiva(sesion.usuario) !== "instructor",
);
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="tu-perfil-boton"
      :aria-expanded="abierto"
      aria-haspopup="menu"
      @click="emit('alternar')"
    >
      <img
        v-if="sesion.usuario?.foto_url"
        :src="sesion.usuario.foto_url"
        alt=""
        class="tu-perfil-avatar"
      />
      <span v-else class="tu-perfil-avatar" aria-hidden="true">{{
        iniciales
      }}</span>
      <span class="hidden sm:block text-left leading-tight">
        <span class="tu-perfil-nombre block truncate max-w-[9rem]">{{
          sesion.usuario?.nombre_corto ?? sesion.usuario?.nombre
        }}</span>
        <span class="tu-perfil-suave block text-[11px] truncate">{{
          rol
        }}</span>
      </span>
      <span class="tu-perfil-flecha hidden sm:inline-flex">
        <IconoNav nombre="chevron" :tam="16" />
      </span>
    </button>
    <div v-if="abierto" class="tu-perfil-menu" role="menu">
      <div class="tu-perfil-cabecera">
        <img
          v-if="sesion.usuario?.foto_url"
          :src="sesion.usuario.foto_url"
          alt=""
          class="tu-perfil-avatar tu-perfil-avatar-grande"
        />
        <span
          v-else
          class="tu-perfil-avatar tu-perfil-avatar-grande"
          aria-hidden="true"
          >{{ iniciales }}</span
        >
        <div class="min-w-0">
          <p class="tu-perfil-nombre truncate">
            {{ sesion.usuario?.nombre }}
          </p>
          <p class="tu-perfil-suave text-sm truncate">
            {{ sesion.usuario?.email }}
          </p>
          <span class="tu-perfil-rol">{{ rol }}</span>
        </div>
      </div>
      <div class="tu-perfil-opciones">
        <RouterLink
          class="tu-perfil-item"
          role="menuitem"
          :to="{ name: 'mi-perfil' }"
          @click="emit('cerrar')"
        >
          <IconoNav nombre="miembros" :tam="18" />
          <span class="flex-1">{{ $t("miPerfil.titulo") }}</span>
          <IconoNav nombre="chevron" :tam="16" class="tu-perfil-ir" />
        </RouterLink>
        <RouterLink
          v-if="puedeConfigurar"
          class="tu-perfil-item"
          role="menuitem"
          :to="{ name: 'ajustes' }"
          @click="emit('cerrar')"
        >
          <IconoNav nombre="configuracion" :tam="18" />
          <span class="flex-1">{{ $t("nav.configuracion") }}</span>
          <IconoNav nombre="chevron" :tam="16" class="tu-perfil-ir" />
        </RouterLink>
      </div>
      <div class="tu-perfil-opciones">
        <button
          type="button"
          class="tu-perfil-item tu-perfil-salir"
          role="menuitem"
          @click="emit('salir')"
        >
          <IconoNav nombre="salir" :tam="18" />
          <span class="flex-1">{{ $t("panel.salir") }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Menú de perfil (barra superior): sin negros pesados; el texto principal va un
   poco más suave que el de la página y el peso es medio. */
.tu-perfil-boton {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0.55rem 0.3rem 0.3rem;
  border-radius: 0.9rem;
  transition: background-color 0.15s ease;
}
.tu-perfil-boton:hover,
.tu-perfil-boton[aria-expanded="true"] {
  background: color-mix(in srgb, var(--superficie-2) 70%, transparent);
}
.tu-perfil-avatar {
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
  background: color-mix(in srgb, var(--acento) 9%, var(--superficie));
  color: color-mix(in srgb, var(--texto) 78%, var(--superficie));
}
.tu-perfil-avatar-grande {
  width: 3.5rem;
  height: 3.5rem;
  font-size: 1.15rem;
}
.tu-perfil-nombre {
  font-size: 0.875rem;
  font-weight: 500;
  color: color-mix(in srgb, var(--texto) 86%, var(--superficie));
}
.tu-perfil-suave {
  color: var(--texto-suave);
}
.tu-perfil-flecha {
  color: var(--texto-suave);
  transform: rotate(90deg);
  transition: transform 0.15s ease;
}
.tu-perfil-boton[aria-expanded="true"] .tu-perfil-flecha {
  transform: rotate(-90deg);
}
.tu-perfil-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 0.6rem);
  z-index: 50;
  /* En teléfono cabe en la pantalla. */
  width: min(19rem, calc(100vw - 1.5rem));
  padding: 0.4rem;
  border: 1px solid var(--borde);
  border-radius: 1rem;
  background: var(--superficie);
  box-shadow: 0 16px 40px rgb(15 23 42 / 10%);
}
/* Pico hacia el botón. */
.tu-perfil-menu::before {
  content: "";
  position: absolute;
  top: -0.4rem;
  right: 1.4rem;
  width: 0.75rem;
  height: 0.75rem;
  border-top: 1px solid var(--borde);
  border-left: 1px solid var(--borde);
  background: var(--superficie);
  transform: rotate(45deg);
}
.tu-perfil-cabecera {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1rem;
  overflow: hidden;
  border-radius: 0.75rem;
  background: linear-gradient(
    135deg,
    var(--superficie) 0%,
    color-mix(in srgb, var(--acento) 6%, var(--superficie)) 100%
  );
}
/* Un arco muy tenue de adorno. */
.tu-perfil-cabecera::after {
  content: "";
  position: absolute;
  right: -3.5rem;
  bottom: -5rem;
  width: 9rem;
  height: 9rem;
  border-radius: 9999px;
  border: 1.5rem solid color-mix(in srgb, var(--acento) 5%, transparent);
  pointer-events: none;
}
.tu-perfil-cabecera .tu-perfil-nombre {
  font-size: 1rem;
}
.tu-perfil-rol {
  display: inline-block;
  margin-top: 0.4rem;
  padding: 0.12rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 500;
  color: var(--texto-suave);
  background: var(--superficie-2);
}
.tu-perfil-opciones {
  margin-top: 0.35rem;
  padding-top: 0.35rem;
  border-top: 1px solid var(--borde);
}
.tu-perfil-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.65rem 0.75rem;
  border-radius: 0.6rem;
  font-size: 0.9rem;
  font-weight: 500;
  text-align: left;
  color: color-mix(in srgb, var(--texto) 86%, var(--superficie));
  text-decoration: none;
  cursor: pointer;
}
.tu-perfil-item > svg:first-child {
  color: var(--texto-suave);
}
.tu-perfil-item:hover {
  background: var(--superficie-2);
}
.tu-perfil-ir {
  color: var(--texto-suave);
}
/* Rojo de «salir», un poco más suave que el de los errores. */
.tu-perfil-salir,
.tu-perfil-salir > svg:first-child {
  color: color-mix(in srgb, var(--error) 82%, var(--superficie));
}
.tu-perfil-salir:hover {
  background: color-mix(in srgb, var(--error) 7%, var(--superficie));
}
</style>
