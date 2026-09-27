<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import PublicShell from "@/components/PublicShell.vue";
import NavArbol from "@/components/NavArbol.vue";
import AppToaster from "@/components/AppToaster.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import type { MenuItem, NavEstado } from "@/components/nav";
import { ISOTIPO_AGENDAUNO } from "@/lib/marca";
import { esVisible, hojas, MENU, TITULOS_FUERA_DEL_MENU } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { slugDeContexto } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useAparienciaStore } from "@/stores/apariencia";
import { useTemaStore } from "@/stores/tema";

const { t } = useI18n();
const tema = useTemaStore();
const sesion = useSesionTenantStore();
const router = useRouter();
const route = useRoute();

const esAcceso = computed(() => route.name === "entrar");

const RUTAS_PUBLICAS_DE_NEGOCIO = new Set([
  "estudio-publico",
  "estudio-corto",
  "agendar-cita",
  "sucursales-estudio",
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

function visible(item: MenuItem): boolean {
  return esVisible(item, sesion);
}

// Filtra el arbol por permisos: una hoja se ve si pasa su permiso; un grupo, si le
// queda al menos un hijo visible.
function filtrar(items: MenuItem[]): MenuItem[] {
  return items
    .map((item): MenuItem | null => {
      if (item.hijos !== undefined) {
        const hijos = filtrar(item.hijos);
        // Un grupo con una sola opción visible es esa opción (sin carpeta de más).
        if (hijos.length === 1) {
          return hijos[0];
        }
        return hijos.length > 0 ? { ...item, hijos } : null;
      }
      if (!visible(item)) {
        return null;
      }
      // Rótulo con la terminología del perfil (p. ej. Barberos en vez de Instructores).
      return item.termino !== undefined
        ? { ...item, texto: plural(sesion.terminologia[item.termino]) }
        : item;
    })
    .filter((item): item is MenuItem => item !== null);
}

const menuVisible = computed(() => filtrar(MENU));

const hogar = computed(() => ({ name: sesion.rutaInicio }));

const puedeConfigurar = computed(() => sesion.puede("estudio.gestionar"));

const enlaceActivo = computed(() => {
  const exacta = hojas(MENU).find((e) => e.ruta === route.name);
  if (exacta !== undefined) {
    return exacta;
  }
  // Subpáginas (p. ej. la ficha /miembros/:id): su sección es la del primer tramo.
  const seccion = router.resolve(`/${route.path.split("/")[1] ?? ""}`).name;
  return hojas(MENU).find((e) => e.ruta === seccion) ?? null;
});
const tituloSeccion = computed(() => {
  if (enlaceActivo.value !== null) {
    const item = enlaceActivo.value;
    return item.termino !== undefined
      ? plural(sesion.terminologia[item.termino])
      : t(item.etiqueta);
  }
  const propio = TITULOS_FUERA_DEL_MENU[String(route.name)];
  return propio !== undefined ? t(propio) : (sesion.estudio?.nombre ?? "");
});

// ---- Estado del arbol (expandir/colapsar grupos) ----
const abiertos = ref<Set<string>>(new Set());

// La clave del grupo que contiene la ruta activa (para auto-expandirlo).
function grupoDe(ruta: string, items: MenuItem[] = MENU): string | null {
  for (const item of items) {
    if (item.hijos !== undefined) {
      if (
        item.hijos.some((h) => h.ruta === ruta) ||
        grupoDe(ruta, item.hijos) !== null
      ) {
        return item.clave;
      }
    }
  }
  return null;
}

function alternar(clave: string): void {
  // En modo rail, expandir un grupo primero descompacta la barra.
  if (compacto.value) {
    compacto.value = false;
  }
  const s = new Set(abiertos.value);
  if (s.has(clave)) {
    s.delete(clave);
  } else {
    s.add(clave);
  }
  abiertos.value = s;
}

function abrirGrupoActivo(): void {
  const g = grupoDe(String(route.name));
  if (g !== null) {
    abiertos.value = new Set(abiertos.value).add(g);
  }
}

watch(() => route.name, abrirGrupoActivo);

const navEstado: NavEstado = {
  abiertos,
  compacto: computed(() => compactoEfectivo.value),
  alternar,
  cerrarCajon: () => {
    menuLateral.value = false;
  },
};
provide("navEstado", navEstado);

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
  abrirGrupoActivo();
});
</script>

<template>
  <!-- ===================== APP AUTENTICADA (panel con barra lateral) ===================== -->
  <div v-if="sesion.autenticado" class="flex min-h-screen">
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

      <!-- Navegación (árbol de 3 niveles: grupos por área → secciones → sub-secciones) -->
      <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1">
        <NavArbol :items="menuVisible" :nivel="1" />
      </nav>

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
        class="sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 border-b backdrop-blur"
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
          <p class="text-base font-semibold truncate">{{ tituloSeccion }}</p>
        </div>

        <div class="flex items-center gap-1 sm:gap-2 shrink-0">
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
                    $te(`usuarios.rol.${sesion.usuario?.rol}`)
                      ? $t(`usuarios.rol.${sesion.usuario?.rol}`)
                      : sesion.usuario?.rol
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
                :to="{ name: 'configuracion' }"
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
          <RouterView />
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
    v-else-if="sesion.validando"
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
  <PanelApariencia :abierto="menuApariencia" @cerrar="menuApariencia = false" />
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
.tu-side-link.router-link-active {
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
