<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";
import LogoProducto from "@/components/LogoProducto.vue";
import { trackEvent } from "@/lib/analytics";
import { PRODUCTOS, productoActual } from "@/lib/producto";
import { useRegistroDelProducto } from "@/marketing/registroProducto";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const props = defineProps<{
  pagina?: string;
  esOscuro?: boolean;
  esAcceso?: boolean;
  esRutaPublicaDeNegocio?: boolean;
}>();

// Página comercial según su ruta (`meta.marketing`), igual en la app y en el
// prerender: el HTML sin JS y la app montada muestran el mismo menú.
const route = useRoute();
const comercial = computed(
  () =>
    !props.esAcceso &&
    !props.esRutaPublicaDeNegocio &&
    route.meta.marketing === true,
);
const modo = computed(() => route.meta.modo ?? null);
const giro = computed(() => route.meta.giro ?? null);
// Cada dominio es de un producto (ADR 0108): su marca, su menú y su registro.
const producto = productoActual();
const marca = PRODUCTOS[producto].nombre;
const { abierto: registroAbierto } = useRegistroDelProducto(producto);
// Funciones · Precios · Preguntas: secciones de la portada del producto
// (`nav.funciones`, `nav.precios`, `nav.preguntas`).
const secciones = computed(() =>
  (["funciones", "precios", "preguntas"] as const).map((clave) => ({
    clave,
    to: {
      name: "inicio",
      hash: clave === "funciones" ? "#soluciones" : `#${clave}`,
    },
  })),
);
// «Probar gratis» desde una página de una modalidad (o de uno de sus giros) llega al
// registro con esa modalidad; desde la página de un solo giro, también con el giro
// (`?giro=`), como los demás «Probar gratis» de esa página.
const destinoRegistro = computed(() => {
  if (modo.value === null) return { name: "registro" };
  return {
    name: "registro",
    query:
      giro.value === null
        ? { modo: modo.value }
        : { modo: modo.value, giro: giro.value },
  };
});
// Sin registro abierto, el botón lleva a la lista de interesados: `waitlist`.
function medirRegistro(): void {
  trackEvent("marketing_cta_clicked", {
    placement: "navigation",
    destination: registroAbierto.value ? "register" : "waitlist",
    ...(modo.value !== null ? { mode: modo.value } : {}),
    ...(giro.value !== null ? { business_profile: giro.value } : {}),
  });
}
const mostrarAcceso = computed(
  () => !props.esAcceso && props.pagina !== "entrar",
);
// Con sesión no se ofrece «Iniciar sesión»: se ofrece volver a su panel.
const sesion = useSesionTenantStore();
const acceso = computed(() =>
  sesion.autenticado
    ? {
        to: { name: sesion.rutaInicio },
        largo: "nav.irAMiPanel",
        corto: "nav.miPanel",
      }
    : { to: { name: "entrar" }, largo: "nav.entrar", corto: "nav.entrarCorto" },
);
defineEmits<{ alternarTema: [] }>();
</script>

<template>
  <div
    class="tu-public-shell min-h-screen flex flex-col"
    :class="{
      'tu-marketing': !esRutaPublicaDeNegocio,
      'tu-public-business': esRutaPublicaDeNegocio,
    }"
  >
    <header v-if="esAcceso || !esRutaPublicaDeNegocio" class="tu-public-nav">
      <div
        class="tu-public-container min-h-18 py-2 flex items-center justify-between gap-4"
      >
        <RouterLink
          :to="{ name: 'inicio' }"
          class="tu-public-brand flex items-center shrink-0"
        >
          <LogoProducto
            :producto="producto"
            variante="horizontal"
            :ancho="192"
          />
        </RouterLink>

        <nav
          v-if="comercial"
          class="tu-public-sections"
          :aria-label="$t('nav.marketing')"
        >
          <!-- Secciones de la portada: anclas, sin aria-current. -->
          <RouterLink
            v-for="s in secciones"
            :key="s.clave"
            v-slot="{ href, navigate }"
            :to="s.to"
            custom
          >
            <a :href="href" @click="navigate">{{ $t(`nav.${s.clave}`) }}</a>
          </RouterLink>
        </nav>

        <nav
          class="tu-public-actions flex items-center gap-1 sm:gap-2 shrink-0"
          :aria-label="`Acceso a ${marca}`"
        >
          <RouterLink
            v-if="!comercial"
            class="tu-public-back"
            :to="{ name: 'inicio' }"
            >Inicio</RouterLink
          >
          <RouterLink
            v-if="mostrarAcceso"
            class="tu-btn tu-btn-fantasma"
            :to="acceso.to"
          >
            <span class="tu-public-login-full">{{ $t(acceso.largo) }}</span>
            <span class="tu-public-login-short">{{ $t(acceso.corto) }}</span>
          </RouterLink>

          <RouterLink
            v-if="comercial"
            class="tu-btn tu-btn-primario tu-public-register"
            :to="destinoRegistro"
            @click="medirRegistro"
          >
            <span class="tu-public-register-full">{{
              registroAbierto ? $t("nav.probar") : $t("nav.avisarme")
            }}</span>
            <span class="tu-public-register-short">{{
              registroAbierto ? $t("nav.probarCorto") : $t("nav.avisarmeCorto")
            }}</span>
          </RouterLink>
        </nav>
      </div>
      <button
        type="button"
        class="tu-public-theme"
        :aria-label="esOscuro ? $t('tema.claro') : $t('tema.oscuro')"
        @click="$emit('alternarTema')"
      >
        <svg
          width="21"
          height="21"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.7"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path
            v-if="esOscuro"
            d="M20.5 15.2A8.5 8.5 0 0 1 8.8 3.5 8.5 8.5 0 1 0 20.5 15.2Z"
          />
          <template v-else>
            <circle cx="12" cy="12" r="4" />
            <path
              d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"
            />
          </template>
        </svg>
      </button>
    </header>
    <!-- En pantallas angostas, el menú comercial va en una segunda fila bajo la
         barra (solo CSS: sirve igual en el HTML prerenderizado sin JS). -->
    <nav
      v-if="comercial"
      class="tu-public-sections tu-public-sections-fila"
      :aria-label="$t('nav.marketing')"
    >
      <RouterLink
        v-for="s in secciones"
        :key="s.clave"
        v-slot="{ href, navigate }"
        :to="s.to"
        custom
      >
        <a :href="href" @click="navigate">{{ $t(`nav.${s.clave}`) }}</a>
      </RouterLink>
    </nav>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="tu-public-footer text-sm">
      <div class="tu-public-footer-inner mx-auto max-w-7xl px-4">
        <div class="tu-public-footer-brand">
          <span>{{
            esRutaPublicaDeNegocio
              ? $t("nav.reservasPor")
              : "© " + new Date().getFullYear() + " " + marca
          }}</span>
          <p v-if="!esRutaPublicaDeNegocio" class="tu-public-footer-note">
            {{
              PRODUCTOS[producto].modalidad === "citas"
                ? "Software para organizar citas."
                : "Software para organizar clases."
            }}
            Cada negocio presta y administra sus propios servicios.
          </p>
        </div>
        <nav
          v-if="!esRutaPublicaDeNegocio"
          class="tu-public-footer-links"
          aria-label="Enlaces"
        >
          <RouterLink class="tu-public-footer-link" to="/aviso-de-privacidad">
            Aviso de privacidad
          </RouterLink>
          <RouterLink class="tu-public-footer-link" to="/terminos">
            Términos y condiciones
          </RouterLink>
          <RouterLink
            v-if="!esRutaPublicaDeNegocio"
            class="tu-public-footer-link"
            :to="acceso.to"
          >
            {{ $t(acceso.largo) }}
          </RouterLink>
          <!-- Un producto que aún no recibe registros no tiene negocios que buscar. -->
          <RouterLink
            v-if="!esRutaPublicaDeNegocio && registroAbierto"
            class="tu-public-footer-link"
            :to="{ name: 'directorio' }"
          >
            {{ $t("nav.encontrarNegocio") }}
          </RouterLink>
        </nav>
      </div>
    </footer>
  </div>
</template>

<style>
/* Navegación comercial persistente, con contenido centrado. */
.tu-public-nav {
  position: sticky;
  top: 0;
  z-index: 40;
  background: var(--superficie);
  border-bottom: 1px solid color-mix(in srgb, var(--borde) 70%, transparent);
}
.tu-public-container {
  width: calc(100% - 7rem);
  max-width: 80rem;
  margin-inline: auto;
  padding-inline: 1rem;
}
.dark .tu-public-nav {
  background: var(--superficie);
}
.tu-public-footer {
  background: var(--superficie);
  color: var(--texto-suave);
  border-top: 1px solid var(--borde);
}
.tu-public-footer-link {
  color: var(--texto-suave);
  font-weight: 400;
  text-decoration: none;
}
.tu-public-footer-link:hover {
  text-decoration: underline;
}
.tu-public-footer-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
  padding-block: 2rem;
  flex-wrap: wrap;
}
.tu-public-footer-brand {
  display: grid;
  gap: 0.75rem;
  font-size: 0.75rem;
}
.tu-public-footer-note {
  max-width: 30rem;
  line-height: 1.7;
}
.tu-public-footer-links {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1.25rem;
  font-size: 0.8rem;
}
.tu-public-theme {
  position: absolute;
  right: 0.75rem;
  /* Centro de la barra (min-h-18 = 4.5rem). */
  top: 2.25rem;
  transform: translateY(-50%);
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  width: 2.75rem;
  height: 2.75rem;
  margin-left: 0;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--texto-suave);
  cursor: pointer;
}
.tu-public-theme:hover {
  color: var(--texto);
}
.tu-public-theme:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 2px;
  border-radius: 0.5rem;
}
.tu-public-register {
  border-radius: 11px;
  padding-inline: 1.25rem;
}
.tu-public-sections {
  display: none;
  align-items: center;
  gap: 0.15rem;
}
.tu-public-sections a {
  padding: 0.45rem 0.65rem;
  border-radius: 0.65rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  font-weight: 600;
  text-decoration: none;
}
.tu-public-sections a:hover,
.tu-public-sections a[aria-current="page"] {
  background: var(--superficie-2);
  color: var(--texto);
}
.tu-public-sections a:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 2px;
}
/* Segunda fila del menú comercial (pantallas angostas): no es fija, se va al bajar. */
.tu-public-sections.tu-public-sections-fila {
  display: flex;
  justify-content: center;
  gap: 0.25rem;
  padding: 0.25rem 1rem;
  background: var(--superficie);
  border-bottom: 1px solid color-mix(in srgb, var(--borde) 70%, transparent);
}
.tu-public-sections-fila a {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding-inline: 0.9rem;
}
.tu-public-register-short {
  display: none;
}
.tu-public-login-short {
  display: none;
}
@media (max-width: 639px) {
  .tu-public-container {
    width: calc(100% - 3.25rem);
    margin-inline: 0;
    padding-inline: 0.75rem;
  }
  .tu-public-theme {
    right: 0.25rem;
  }
}
@media (max-width: 479px) {
  .tu-public-container {
    gap: 0.25rem;
    padding-right: 0.75rem;
    padding-left: 0.75rem;
  }
  .tu-public-brand .agendauno-logo {
    width: 132px !important;
  }
  .tu-public-theme {
    width: 2.25rem;
    margin-left: 0;
  }
  .tu-public-register {
    padding-right: 0.8rem;
    padding-left: 0.8rem;
  }
  .tu-public-register-full {
    display: none;
  }
  .tu-public-register-short {
    display: inline;
  }
  .tu-public-login-full {
    display: none;
  }
  .tu-public-login-short {
    display: inline;
  }
}
@media (max-width: 379px) {
  .tu-public-brand .agendauno-logo {
    width: 104px !important;
  }
  .tu-public-container .tu-btn {
    font-size: 0.75rem;
    padding-inline: 0.55rem;
  }
}
@media (min-width: 1100px) {
  .tu-public-sections {
    display: flex;
  }
  .tu-public-sections.tu-public-sections-fila {
    display: none;
  }
}
</style>
