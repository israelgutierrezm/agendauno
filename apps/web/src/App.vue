<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import MenuPerfil from "@/components/MenuPerfil.vue";
import BotonPantallaCompleta from "@/components/BotonPantallaCompleta.vue";
import SelectorSucursal from "@/components/SelectorSucursal.vue";
import { agendaAmpliada } from "@/lib/pantallaCompleta";
import PublicShell from "@/components/PublicShell.vue";
import LayoutConfiguracion from "@/components/LayoutConfiguracion.vue";
import NavLateral from "@/components/NavLateral.vue";
import PestanasArea from "@/components/PestanasArea.vue";
import AppToaster from "@/components/AppToaster.vue";
import AvisoSinSucursal from "@/components/AvisoSinSucursal.vue";
import CuentaLateral from "@/components/CuentaLateral.vue";
import DialogoConfirmar from "@/components/DialogoConfirmar.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import PanelRoles from "@/components/PanelRoles.vue";
import { ISOTIPO_AGENDAUNO } from "@/lib/marca";
import { ubicacion } from "@/lib/menu";
import { useUbicacionActual } from "@/lib/ubicacionActual";
import { identidadDeSesion, reiniciarMiCuenta } from "@/lib/miCuenta";
import { esMiembro } from "@/lib/roles";
import { useSucursales } from "@/lib/sucursalOperativa";
import { slugDeContexto } from "@/lib/tenant";
import { pausarTerminologia } from "@/i18n";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useAparienciaStore } from "@/stores/apariencia";
import { useTemaStore } from "@/stores/tema";

const tema = useTemaStore();
const sesion = useSesionTenantStore();

// Al cambiar de cuenta o de negocio (o al salir), el portal del alumno se vacía en
// ese mismo instante: en un equipo compartido no queda en memoria nada de la
// sesión anterior, aunque la carga de la nueva falle.
watch(
  () => identidadDeSesion(sesion.slug, sesion.bearer),
  (nueva, anterior) => {
    if (nueva !== anterior) {
      reiniciarMiCuenta();
    }
  },
  { flush: "sync" },
);
const router = useRouter();
const route = useRoute();

const esAcceso = computed(() => route.name === "entrar");

const RUTAS_PUBLICAS_DE_NEGOCIO = new Set([
  "estudio-publico",
  "estudio-corto",
  "agendar-cita",
  "sucursales-estudio",
  "enlaces-estudio",
  "enlaces-subdominio",
]);
const esRutaPublicaDeNegocio = computed(
  () =>
    RUTAS_PUBLICAS_DE_NEGOCIO.has(String(route.name)) ||
    (String(route.name) === "entrar" &&
      (slugDeContexto() !== null || typeof route.query.estudio === "string")),
);

tema.inicializar();
// Con sesión guardada, su tema se pinta desde el primer cuadro (lo confirma /yo).
if (sesion.bearer !== null) {
  useAparienciaStore().restaurar();
}

const hogar = computed(() => ({ name: sesion.rutaInicio }));
// Barra superior: el área donde se está y la ruta de ubicación.
const lugar = useUbicacionActual();
// Con varias sucursales, la barra dice con cuál se trabaja en lugar del título (el
// título sigue en la ruta de ubicación).
const sucursalesSesion = useSucursales();
const selectorEnBarra = computed(
  () => sucursalesSesion.varias.value && !esMiembro(sesion.usuario),
);
// Para el personal, la barra dice con qué sucursal se trabaja en lugar del título
// (que ya está en las pestañas de abajo): con varias, el selector; con una, su nombre.
const sucursalUnicaEnBarra = computed(
  () => !esMiembro(sesion.usuario) && sucursalesSesion.lista.value.length === 1,
);

// El panel es para las pantallas privadas. Las públicas (la página del negocio,
// agendar, el directorio…) se ven como las ve cualquier visitante aunque haya
// sesión: quien abre su enlace de agendar ve lo que ven sus clientes.
const enPanel = computed(
  () => sesion.autenticado && route.meta.requiereSesion === true,
);
watch(
  enPanel,
  (panel) => {
    useAparienciaStore().pausar(!panel);
    pausarTerminologia(!panel);
  },
  { immediate: true },
);

// Una opción de Configuración (no su portada) va con la navegación secundaria.
const enConfiguracion = computed(() => {
  const u = ubicacion(route);
  return u?.area.clave === "configuracion" && u.vista.clave !== "portada";
});

// Estado de la interfaz.
const menuLateral = ref(false); // cajón en móvil
const compacto = ref(false); // barra contraída (solo iconos) en escritorio
const menuPerfil = ref(false);
const menuApariencia = ref(false);
// Panel «Cambiar de rol» (quien tiene más de un rol en el negocio).
const menuRoles = ref(false);
// Entró (o recargó) sin elegir con qué rol: el panel lateral se lo pregunta. En la
// pantalla de entrar lo pregunta la propia pantalla.
const rolPorElegir = computed(
  () =>
    sesion.autenticado && sesion.requiereElegirRol && route.name !== "entrar",
);

// La contracción solo aplica en escritorio; con el cajón abierto se ve completo.
const compactoEfectivo = computed(() => compacto.value && !menuLateral.value);

function alternarCompacto(): void {
  compacto.value = !compacto.value;
  try {
    localStorage.setItem("tu.barra.compacta", compacto.value ? "1" : "0");
  } catch {
    // Ignora si no hay localStorage.
  }
}

async function salir(): Promise<void> {
  menuPerfil.value = false;
  await sesion.cerrarSesion();
  void router.push({ name: "inicio" });
}

onMounted(() => {
  try {
    compacto.value = localStorage.getItem("tu.barra.compacta") === "1";
  } catch {
    // Ignora.
  }
});
</script>

<template>
  <!-- ===================== APP AUTENTICADA (panel con barra lateral) ===================== -->
  <div v-if="enPanel" class="flex min-h-screen">
    <!-- Velo del cajón (móvil) -->
    <div
      v-if="menuLateral"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="menuLateral = false"
    />

    <!-- Barra lateral (oscura) -->
    <aside
      v-show="!agendaAmpliada"
      class="fixed lg:sticky top-0 z-50 h-screen w-64 shrink-0 flex flex-col transition-all duration-200"
      :class="[
        compacto ? 'lg:w-16' : 'lg:w-64',
        menuLateral ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
      :style="{
        background: 'var(--barra)',
        color: 'var(--barra-texto)',
        borderRight: '1px solid var(--barra-borde)',
      }"
    >
      <!-- Marca -->
      <RouterLink
        :to="hogar"
        class="flex items-center gap-3 h-16 px-4 shrink-0 border-b"
        :style="{ borderColor: 'var(--barra-borde)' }"
        @click="menuLateral = false"
      >
        <img
          v-if="sesion.estudio?.logo_url"
          :src="sesion.estudio.logo_url"
          :alt="sesion.estudio?.nombre"
          class="h-9 w-9 rounded-xl object-cover shrink-0"
        />
        <!-- Sin logo propio: el isotipo de AgendaUno. -->
        <img
          v-else
          :src="ISOTIPO_AGENDAUNO"
          alt=""
          class="h-9 w-9 object-contain shrink-0"
        />
        <span v-show="!compactoEfectivo" class="min-w-0">
          <span
            class="block text-sm font-semibold truncate"
            :style="{ color: 'var(--barra-titulo, #ffffff)' }"
            >{{ sesion.estudio?.nombre ?? $t("marca") }}</span
          >
          <span class="block text-[11px] opacity-60 truncate">{{
            $t("marca")
          }}</span>
        </span>
      </RouterLink>

      <!-- Navegación: grupos con un enlace por área -->
      <div class="flex-1 overflow-y-auto px-3 py-4">
        <NavLateral
          :compacto="compactoEfectivo"
          @navegar="menuLateral = false"
        />
        <!-- En el teléfono, la cuenta y sus opciones (la barra de arriba solo lleva la
             sucursal). -->
        <div
          class="sm:hidden mt-4 pt-3 border-t"
          :style="{ borderColor: 'var(--barra-borde)' }"
        >
          <CuentaLateral
            @navegar="menuLateral = false"
            @roles="
              menuLateral = false;
              menuRoles = true;
            "
            @apariencia="
              menuLateral = false;
              menuApariencia = true;
            "
            @salir="
              menuLateral = false;
              salir();
            "
          />
        </div>
      </div>

      <!-- Contraer (solo escritorio) -->
      <div
        class="hidden lg:block p-3 border-t"
        :style="{ borderColor: 'var(--barra-borde)' }"
      >
        <button
          type="button"
          class="tu-side-link w-full"
          :class="{ 'lg:justify-center': compactoEfectivo }"
          :title="$t('nav.contraer')"
          @click="alternarCompacto"
        >
          <IconoNav
            nombre="chevron"
            :tam="18"
            class="shrink-0 transition-transform"
            :class="{ 'rotate-180': !compacto }"
          />
          <span v-show="!compactoEfectivo">{{ $t("nav.contraer") }}</span>
        </button>
      </div>
    </aside>

    <!-- Columna principal -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Encabezado (claro, translúcido) -->
      <header
        v-show="!agendaAmpliada"
        class="tu-barra-superior sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 border-b backdrop-blur"
        :style="{
          background: 'color-mix(in srgb, var(--superficie) 85%, transparent)',
          borderColor: 'var(--borde)',
        }"
      >
        <div class="flex flex-1 items-center gap-3 min-w-0">
          <button
            type="button"
            class="tu-icono-btn tu-barra-menu relative"
            :aria-label="$t('nav.menu')"
            data-prueba="boton-menu"
            @click="menuLateral = true"
          >
            <svg
              class="h-5 w-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              aria-hidden="true"
            >
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            <!-- En el teléfono «Cambiar de rol» está en el menú: el punto lo avisa. -->
            <span
              v-if="sesion.tieneVariosRoles"
              class="tu-rol-punto tu-rol-punto-esquina tu-rol-punto-menu"
              aria-hidden="true"
            />
          </button>
          <!-- Con varias sucursales: con cuál se trabaja (en lugar del título). -->
          <SelectorSucursal v-if="selectorEnBarra" />
          <!-- Con una sola sucursal: con cuál se trabaja (sin poder cambiarla). -->
          <SelectorSucursal v-else-if="sucursalUnicaEnBarra" variante="unica" />
          <!-- Clientes y personal sin sucursal: dónde está (el área). -->
          <div
            v-else-if="lugar.titulo.value"
            class="tu-barra-titulo"
            data-prueba="titulo-barra"
          >
            <span v-if="lugar.icono.value" class="tu-barra-icono">
              <IconoNav :nombre="lugar.icono.value" :tam="18" />
            </span>
            <span class="truncate">{{ lugar.titulo.value }}</span>
          </div>
        </div>

        <!-- En el teléfono van al pie del menú lateral (CuentaLateral). -->
        <div
          class="tu-barra-acciones flex items-center gap-1 sm:gap-2 shrink-0"
        >
          <!-- Ruta de ubicación, al final (con ancho de sobra). -->
          <nav
            v-if="lugar.migas.value.length > 1"
            class="tu-migas"
            :aria-label="$t('configNegocio.ubicacion')"
            data-prueba="migas"
          >
            <template v-for="(m, i) in lugar.migas.value" :key="i">
              <IconoNav
                v-if="i > 0"
                nombre="chevron"
                :tam="12"
                class="tu-migas-sep"
                aria-hidden="true"
              />
              <RouterLink
                v-if="m.destino"
                :to="m.destino"
                class="tu-miga"
                :title="m.texto"
                >{{ m.texto }}</RouterLink
              >
              <span
                v-else
                class="tu-miga"
                :title="m.texto"
                :class="{
                  'tu-miga-actual': i === lugar.migas.value.length - 1,
                }"
                :aria-current="
                  i === lugar.migas.value.length - 1 ? 'page' : undefined
                "
                >{{ m.texto }}</span
              >
            </template>
          </nav>
          <span
            v-if="lugar.migas.value.length > 1"
            class="tu-barra-division"
            aria-hidden="true"
          />
          <!-- Cambiar de rol: solo si puede entrar con más de uno; las flechas se
               mueven para avisar que tiene otro rol con el cual entrar. -->
          <button
            v-if="sesion.tieneVariosRoles"
            type="button"
            class="tu-icono-btn relative"
            data-prueba="boton-cambiar-rol"
            :aria-label="$t('operacion.rolActivo.cambiar')"
            :title="$t('operacion.rolActivo.cambiar')"
            @click="
              menuRoles = true;
              menuPerfil = false;
            "
          >
            <IconoNav nombre="intercambio" :tam="18" class="tu-rol-flechas" />
          </button>

          <!-- Apariencia: tema y colores propios (panel lateral) -->
          <button
            type="button"
            class="tu-icono-btn"
            :aria-label="$t('tema.apariencia')"
            :title="$t('tema.apariencia')"
            @click="
              menuApariencia = true;
              menuPerfil = false;
            "
          >
            <IconoNav nombre="apariencia" :tam="18" />
          </button>

          <BotonPantallaCompleta />

          <!-- Perfil: quién entró y con qué rol; su perfil y salir. -->
          <MenuPerfil
            :abierto="menuPerfil"
            @alternar="
              menuPerfil = !menuPerfil;
              menuApariencia = false;
            "
            @cerrar="menuPerfil = false"
            @salir="salir"
          />
        </div>
      </header>

      <main class="flex-1" :style="{ background: 'var(--fondo)' }">
        <!-- Las vistas del área (Agenda: Calendario, Recepción…), bajo la barra -->
        <PestanasArea v-show="!agendaAmpliada" />
        <div
          class="tu-lienzo"
          :class="{ 'tu-lienzo-ampliado': agendaAmpliada }"
        >
          <!-- Personal sin sucursal (con varias en el negocio): no ve nada aún. -->
          <AvisoSinSucursal v-if="sesion.usuario?.sin_sucursal" />
          <LayoutConfiguracion v-if="enConfiguracion">
            <RouterView />
          </LayoutConfiguracion>
          <RouterView v-else />
        </div>
      </main>
    </div>

    <!-- Cierra menús flotantes al hacer clic fuera -->
    <div
      v-if="menuPerfil || menuApariencia"
      class="fixed inset-0 z-20"
      @click="
        menuPerfil = false;
        menuApariencia = false;
      "
    />
  </div>

  <!-- Sesión guardada aún sin confirmar (recarga): ni panel ni cara pública. -->
  <div
    v-else-if="sesion.validando && route.meta.requiereSesion === true"
    class="min-h-screen"
    :style="{ background: 'var(--fondo)' }"
    aria-busy="true"
  />

  <!-- ===================== APP PÚBLICA ===================== -->
  <PublicShell
    v-else
    :pagina="String(route.name ?? '')"
    :es-oscuro="tema.esOscuro"
    :es-acceso="esAcceso"
    :es-ruta-publica-de-negocio="esRutaPublicaDeNegocio"
    @alternar-tema="tema.alternarModo()"
  >
    <!-- No volver a montar una ruta privada mientras se cierra la sesión. -->
    <RouterView v-if="route.meta.requiereSesion !== true" />
  </PublicShell>

  <!-- Notificaciones flotantes (toasts), montadas una sola vez para toda la app. -->
  <AppToaster />
  <!-- Confirmaciones dentro de la app (lib/confirmar.ts). -->
  <DialogoConfirmar />
  <PanelApariencia :abierto="menuApariencia" @cerrar="menuApariencia = false" />
  <PanelRoles
    :abierto="menuRoles || rolPorElegir"
    :al-entrar="rolPorElegir && !menuRoles"
    @cerrar="menuRoles = false"
  />
</template>

<style>
.tu-lienzo.tu-lienzo-ampliado {
  padding-inline: 0;
}
.tu-lienzo.tu-lienzo-ampliado > section {
  max-width: none;
  width: 100%;
}
/* Barra superior: el área donde se está, con su ícono en un cuadro neutro. */
.tu-barra-titulo {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-width: 0;
  overflow: hidden;
  color: var(--texto);
  font-size: 1.05rem;
  font-weight: 600;
  letter-spacing: -0.01em;
}
.tu-barra-icono {
  display: grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  flex-shrink: 0;
  border: 1px solid var(--borde);
  border-radius: 0.65rem;
  background: var(--superficie);
  box-shadow: 0 1px 2px rgb(15 23 42 / 0.06);
}
/* Ruta de ubicación: discreta, al final de la barra (con ancho de sobra). */
.tu-migas {
  display: none;
  align-items: center;
  gap: 0.15rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  white-space: nowrap;
  min-width: 0;
  max-width: clamp(10rem, calc(100vw - 66rem), 32rem);
  overflow: hidden;
}
.tu-migas-sep {
  opacity: 0.5;
  flex-shrink: 0;
}
.tu-miga {
  padding: 0.2rem 0.35rem;
  border-radius: 0.4rem;
  color: var(--texto-suave);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
a.tu-miga:hover {
  background: var(--fondo);
  color: var(--texto);
}
.tu-miga-actual {
  color: var(--texto);
  font-weight: 500;
}
.tu-barra-division {
  display: none;
  width: 1px;
  height: 1.5rem;
  margin: 0 0.4rem;
  background: var(--borde);
}
@media (min-width: 1280px) {
  .tu-migas {
    display: flex;
  }
  .tu-barra-division {
    display: block;
  }
}
/* Con menos espacio, el área ya está a la izquierda: solo muestra la sección. */
@media (min-width: 1280px) and (max-width: 1535px) {
  .tu-migas > .tu-miga:not(.tu-miga-actual),
  .tu-migas > .tu-migas-sep {
    display: none;
  }
}

/*
 * El lienzo del panel: poco margen a los lados, sin quedar pegado. Cada pantalla
 * deja la mitad del margen que le daba su ancho máximo: su ancho + la mitad de lo
 * que sobraba (calc(50% + ancho/2)). Los formularios angostos (2xl, 3xl) y los de
 * Configuración, que van junto a su navegación, conservan su ancho.
 */
.tu-lienzo {
  padding: 0 0.25rem;
}
.tu-lienzo > :not([class*="max-w-"]),
.tu-lienzo > .mx-auto.max-w-7xl {
  max-width: calc(50% + 40rem);
  margin-inline: auto;
}
.tu-lienzo > .mx-auto.max-w-6xl {
  max-width: calc(50% + 36rem);
}
.tu-lienzo > .mx-auto.max-w-5xl {
  max-width: calc(50% + 32rem);
}
.tu-lienzo > .mx-auto.max-w-4xl {
  max-width: calc(50% + 28rem);
}
/* En Configuración, la pantalla va junto a su navegación, no centrada en lo que sobra. */
.lc-contenido > .mx-auto {
  margin-inline-start: 0;
}
@media (min-width: 768px) {
  .tu-lienzo {
    padding: 0 0.75rem;
  }
}
@media (min-width: 1024px) {
  .tu-lienzo {
    padding: 0 1rem;
  }
}

/* Enlaces de la barra lateral (clara u oscura según el tema). */
.tu-side-link {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.6rem 0.7rem;
  border-radius: 0.75rem;
  font-weight: 500;
  font-size: 0.9rem;
  color: var(--barra-texto);
  text-decoration: none;
  cursor: pointer;
  white-space: nowrap;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.tu-side-link:hover {
  background: var(--barra-suave);
  color: var(--barra-titulo, #ffffff);
}
.tu-side-link.tu-side-activo {
  background: var(--barra-activo);
  color: var(--barra-activo-texto, #ffffff);
  font-weight: 600;
}

/* Botón de icono del encabezado. */
.tu-icono-btn {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  height: 2.25rem;
  width: 2.25rem;
  border-radius: 980px;
  color: var(--texto-suave);
  cursor: pointer;
}
.tu-icono-btn:hover {
  background: var(--superficie-2);
  color: var(--texto);
}
/* En el teléfono la barra solo lleva el menú y la sucursal activa: el perfil, el rol,
   la apariencia y la pantalla completa van al pie del menú lateral. */
@media (max-width: 639.98px) {
  .tu-barra-acciones {
    display: none !important;
  }
}
@media (min-width: 640px) {
  .tu-barra-menu .tu-rol-punto-menu {
    display: none;
  }
}
/* «Cambiar de rol»: sus flechas van y vienen, siempre, para que se note que se puede
   entrar con otro rol (en la barra y en el menú lateral). En el teléfono, el botón
   del menú lleva un punto que late (ahí está «Cambiar de rol»). */
.tu-rol-flechas path:nth-of-type(-n + 2) {
  animation: tu-rol-flecha-izq 2.4s ease-in-out infinite;
}
.tu-rol-flechas path:nth-of-type(n + 3) {
  animation: tu-rol-flecha-der 2.4s ease-in-out infinite;
}
@keyframes tu-rol-flecha-izq {
  0%,
  55%,
  100% {
    transform: translateX(0);
  }
  25% {
    transform: translateX(-3px);
  }
}
@keyframes tu-rol-flecha-der {
  0%,
  55%,
  100% {
    transform: translateX(0);
  }
  25% {
    transform: translateX(3px);
  }
}
.tu-rol-punto {
  position: relative;
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--primario);
}
.tu-rol-punto::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: var(--primario);
  animation: tu-rol-latido 1.8s ease-out infinite;
}
.tu-rol-punto-esquina {
  position: absolute;
  top: 0.3rem;
  right: 0.3rem;
  box-shadow: 0 0 0 2px var(--superficie);
}
@keyframes tu-rol-latido {
  0% {
    transform: scale(1);
    opacity: 0.6;
  }
  80%,
  100% {
    transform: scale(2.6);
    opacity: 0;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-rol-punto::after,
  .tu-rol-flechas path {
    animation: none;
  }
}
/* Alto contraste del sistema: los fondos se quitan; el punto se pinta como texto. */
@media (forced-colors: active) {
  .tu-rol-punto,
  .tu-rol-punto::after {
    forced-color-adjust: none;
    background: CanvasText;
  }
}
/* El menú móvil (cajón) no existe en escritorio: ahí el lateral siempre se ve.
   Va aquí y no como `lg:hidden` porque `.tu-icono-btn` no está en una capa. */
@media (min-width: 1024px) {
  .tu-barra-menu {
    display: none;
  }
}
</style>
