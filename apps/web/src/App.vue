<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
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
        class="tu-barra-superior sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 border-b backdrop-blur"
        :style="{
          background: 'color-mix(in srgb, var(--superficie) 85%, transparent)',
          borderColor: 'var(--borde)',
        }"
      >
        <div class="flex items-center gap-3 min-w-0">
          <button
            type="button"
            class="lg:hidden tu-icono-btn"
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
        </div>

        <div class="flex items-center gap-1 sm:gap-2 shrink-0">
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
        <div class="mx-auto max-w-7xl md:px-4 lg:px-8">
          <!-- Las vistas del área (Agenda: Calendario, Recepción…) -->
          <PestanasArea />
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
