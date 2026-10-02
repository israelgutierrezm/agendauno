<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import BotonPantallaCompleta from "@/components/BotonPantallaCompleta.vue";
import SelectorSucursal from "@/components/SelectorSucursal.vue";
import { agendaAmpliada } from "@/lib/pantallaCompleta";
import PublicShell from "@/components/PublicShell.vue";
import LayoutConfiguracion from "@/components/LayoutConfiguracion.vue";
import NavLateral from "@/components/NavLateral.vue";
import PestanasArea from "@/components/PestanasArea.vue";
import AppToaster from "@/components/AppToaster.vue";
import DialogoConfirmar from "@/components/DialogoConfirmar.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import PanelRoles from "@/components/PanelRoles.vue";
import { ISOTIPO_AGENDAUNO } from "@/lib/marca";
import { puedeEntrar } from "@/lib/acceso";
import { ubicacion } from "@/lib/menu";
import { useUbicacionActual } from "@/lib/ubicacionActual";
import { identidadDeSesion, reiniciarMiCuenta } from "@/lib/miCuenta";
import { nombreDeRol } from "@/lib/roles";
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

// Configuración del negocio en el menú del usuario: si alguna opción se puede abrir.
const puedeConfigurar = computed(() => puedeEntrar("ajustes", sesion));

// Una opción de Configuración (no su portada) va con la navegación secundaria.
const enConfiguracion = computed(() => {
  const u = ubicacion(route);
  return u?.area.clave === "configuracion" && u.vista.clave !== "portada";
});

function siglas(nombre: string | undefined): string {
  return (
    (nombre ?? "")
      .split(" ")
      .slice(0, 2)
      .map((p) => p.charAt(0))
      .join("")
      .toUpperCase() || "·"
  );
}
const inicialesUsuario = computed(() => siglas(sesion.usuario?.nombre));

// Estado de la interfaz.
const menuLateral = ref(false); // cajón en móvil
const compacto = ref(false); // barra contraída (solo iconos) en escritorio
const menuPerfil = ref(false);
const menuApariencia = ref(false);
// Panel «Cambiar de rol» (quien tiene más de un rol en el negocio).
const menuRoles = ref(false);

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
        <div class="flex items-center gap-3 min-w-0">
          <button
            type="button"
            class="tu-icono-btn tu-barra-menu"
            :aria-label="$t('nav.menu')"
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
          </button>
          <!-- Dónde está: el área (su ícono y su nombre). -->
          <div
            v-if="lugar.titulo.value"
            class="tu-barra-titulo"
            data-prueba="titulo-barra"
          >
            <span v-if="lugar.icono.value" class="tu-barra-icono">
              <IconoNav :nombre="lugar.icono.value" :tam="18" />
            </span>
            <span class="truncate">{{ lugar.titulo.value }}</span>
          </div>
        </div>

        <div class="flex items-center gap-1 sm:gap-2 shrink-0">
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
              <RouterLink v-if="m.destino" :to="m.destino" class="tu-miga">{{
                m.texto
              }}</RouterLink>
              <span
                v-else
                class="tu-miga"
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
          <!-- Con qué sucursal se trabaja (con varias); con una, solo su nombre -->
          <SelectorSucursal class="hidden sm:inline-flex" />
          <!-- Cambiar de rol: solo si puede entrar con más de uno -->
          <button
            v-if="sesion.tieneVariosRoles"
            type="button"
            class="tu-icono-btn"
            :aria-label="$t('operacion.rolActivo.cambiar')"
            :title="$t('operacion.rolActivo.cambiar')"
            @click="
              menuRoles = true;
              menuPerfil = false;
            "
          >
            <IconoNav nombre="intercambio" :tam="18" />
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
            <IconoNav nombre="configuracion" :tam="18" />
          </button>

          <BotonPantallaCompleta />

          <!-- Perfil -->
          <div class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-xl p-1 pr-2 hover:bg-black/5"
              :aria-expanded="menuPerfil"
              @click="
                menuPerfil = !menuPerfil;
                menuApariencia = false;
              "
            >
              <img
                v-if="sesion.usuario?.foto_url"
                :src="sesion.usuario.foto_url"
                alt=""
                class="h-8 w-8 rounded-full object-cover shrink-0"
              />
              <span
                v-else
                class="h-8 w-8 rounded-full inline-flex items-center justify-center text-xs font-semibold shrink-0"
                :style="{
                  background: 'var(--superficie-2)',
                  color: 'var(--texto)',
                }"
                aria-hidden="true"
                >{{ inicialesUsuario }}</span
              >
              <span class="hidden sm:block text-left leading-tight">
                <span
                  class="block text-[13px] font-semibold truncate max-w-[8rem]"
                  >{{
                    sesion.usuario?.nombre_corto ?? sesion.usuario?.nombre
                  }}</span
                >
                <span
                  class="block text-[11px] truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{
                    nombreDeRol(
                      sesion.usuario?.rol ?? "",
                      sesion.usuario?.roles_disponibles,
                      (llave) => ($te(llave) ? $t(llave) : null),
                    )
                  }}</span
                >
              </span>
            </button>
            <div
              v-if="menuPerfil"
              class="absolute right-0 top-full mt-2 w-60 tu-card p-1.5 z-50"
            >
              <div
                class="px-2.5 py-2 border-b"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <p class="text-sm font-semibold truncate">
                  {{ sesion.usuario?.nombre }}
                </p>
                <p
                  class="text-xs truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ sesion.usuario?.email }}
                </p>
              </div>
              <RouterLink
                class="tu-menu-item mt-1"
                :to="{ name: 'mi-perfil' }"
                @click="menuPerfil = false"
              >
                <IconoNav nombre="miembros" :tam="16" />
                {{ $t("miPerfil.titulo") }}
              </RouterLink>
              <RouterLink
                v-if="puedeConfigurar"
                class="tu-menu-item"
                :to="{ name: 'ajustes' }"
                @click="menuPerfil = false"
              >
                <IconoNav nombre="configuracion" :tam="16" />
                {{ $t("nav.configuracion") }}
              </RouterLink>
              <button
                type="button"
                class="tu-menu-item"
                style="color: var(--error)"
                @click="salir"
              >
                <IconoNav nombre="salir" :tam="16" />
                {{ $t("panel.salir") }}
              </button>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1" :style="{ background: 'var(--fondo)' }">
        <!-- Las vistas del área (Agenda: Calendario, Recepción…), bajo la barra -->
        <PestanasArea v-show="!agendaAmpliada" />
        <div
          class="tu-lienzo"
          :class="{ 'tu-lienzo-ampliado': agendaAmpliada }"
        >
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
  <PanelRoles :abierto="menuRoles" @cerrar="menuRoles = false" />
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
}
.tu-migas-sep {
  opacity: 0.5;
}
.tu-miga {
  padding: 0.2rem 0.35rem;
  border-radius: 0.4rem;
  color: var(--texto-suave);
  text-decoration: none;
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
/* El menú móvil (cajón) no existe en escritorio: ahí el lateral siempre se ve.
   Va aquí y no como `lg:hidden` porque `.tu-icono-btn` no está en una capa. */
@media (min-width: 1024px) {
  .tu-barra-menu {
    display: none;
  }
}

/* Elementos de menús flotantes (perfil, apariencia) sobre fondo claro. */
.tu-menu-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 0.6rem;
  border-radius: 0.6rem;
  font-weight: 600;
  font-size: 0.88rem;
  color: var(--texto);
  text-decoration: none;
  cursor: pointer;
  text-align: left;
}
.tu-menu-item:hover {
  background: var(--superficie-2);
}
</style>
