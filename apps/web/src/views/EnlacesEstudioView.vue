<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import IconoRed from "@/components/IconoRed.vue";
import { api } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import {
  capacidadesDeNegocio,
  type Capacidades,
  type ModalidadServicio,
} from "@/lib/modalidad";
import { updateSeo } from "@/lib/seo";
import { slugDeContexto, urlCanonicaEstudio } from "@/lib/tenant";

/**
 * Página de enlaces del negocio (la del link en la bio de Instagram): logo, nombre,
 * calificación y un botón por cada cosa que busca quien llega desde redes (agendar o
 * reservar, horario de clases, precios, sitio web, WhatsApp, cómo llegar), más sus
 * redes. Sale del escaparate público; en su subdominio vive en `/enlaces`.
 */
type NombreRed = "instagram" | "facebook" | "tiktok" | "youtube" | "sitio_web";
interface Red {
  red: NombreRed;
  url: string;
}
interface Datos {
  estudio: {
    slug: string;
    nombre: string;
    logo_url: string | null;
    descripcion?: string | null;
    redes?: Red[];
    whatsapp_url?: string | null;
    // Solo clases o solo citas (ADR 0104): lo dice el servidor, no las ofertas.
    modalidad?: ModalidadServicio;
    capacidades?: Capacidades;
    perfil_config?: { modalidad?: ModalidadServicio };
  };
  sucursales: {
    nombre: string;
    mapa_url?: string | null;
    whatsapp_url?: string | null;
  }[];
  horario_clases?: unknown[];
  productos: unknown[];
  resenas?: { promedio: number | null; total: number };
}
interface Boton {
  // Identifica el botón (y el evento de medición).
  clave: string;
  // Llave del texto en perfilPublico.enlaces y sus parámetros.
  texto: string;
  params?: Record<string, string>;
  icono: "calendario" | NombreRed | "whatsapp" | "mapa";
  destino: { interno: Record<string, unknown> } | { externo: string };
}

const route = useRoute();
const slug = computed(() =>
  typeof route.params.slug === "string" && route.params.slug !== ""
    ? route.params.slug
    : (slugDeContexto(window.location.hostname, window.location.search) ?? ""),
);
const datos = ref<Datos | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);

const redesSociales = computed(() =>
  (datos.value?.estudio.redes ?? []).filter((r) => r.red !== "sitio_web"),
);
const resumen = computed(() => {
  const d = datos.value?.estudio.descripcion ?? "";
  return d.length > 140 ? `${d.slice(0, 137).trimEnd()}…` : d;
});

// Un botón por cada cosa útil que el negocio tenga capturada.
const botones = computed<Boton[]>(() => {
  const d = datos.value;
  if (d === null) {
    return [];
  }
  const lista: Boton[] = [];
  const pagina = { name: "estudio-publico", params: { slug: slug.value } };
  // Agendar (citas) o reservar (clases): lo que ofrece el negocio, nunca ambas.
  const capacidades = capacidadesDeNegocio(d.estudio);
  if (capacidades.citas) {
    lista.push({
      clave: "agendar",
      texto: "agendar",
      icono: "calendario",
      destino: {
        interno: { name: "sucursales-estudio", params: { slug: slug.value } },
      },
    });
  }
  if (capacidades.clases) {
    lista.push({
      clave: "reservar",
      texto: "reservarClase",
      icono: "calendario",
      destino: { interno: pagina },
    });
  }
  if ((d.horario_clases ?? []).length > 0) {
    lista.push({
      clave: "horario",
      texto: "horario",
      icono: "calendario",
      destino: { interno: { ...pagina, hash: "#horario" } },
    });
  }
  if (d.productos.length > 0) {
    lista.push({
      clave: "precios",
      texto: "precios",
      icono: "calendario",
      destino: { interno: { ...pagina, hash: "#precios" } },
    });
  }
  const web = (d.estudio.redes ?? []).find((r) => r.red === "sitio_web");
  if (web) {
    lista.push({
      clave: "web",
      texto: "web",
      icono: "sitio_web",
      destino: { externo: web.url },
    });
  }
  const whatsapp =
    d.estudio.whatsapp_url ??
    d.sucursales.find((s) => s.whatsapp_url)?.whatsapp_url ??
    null;
  if (whatsapp) {
    lista.push({
      clave: "whatsapp",
      texto: "whatsapp",
      icono: "whatsapp",
      destino: { externo: whatsapp },
    });
  }
  const conMapa = d.sucursales.filter((s) => s.mapa_url);
  for (const s of conMapa) {
    lista.push({
      clave: `mapa-${s.nombre}`,
      texto: conMapa.length > 1 ? "ubicacionDe" : "ubicacion",
      params: { sede: s.nombre },
      icono: "mapa",
      destino: { externo: s.mapa_url as string },
    });
  }
  lista.push({
    clave: "conocer",
    texto: "conocer",
    icono: "calendario",
    destino: { interno: pagina },
  });
  return lista;
});

function registrarClic(boton: Boton): void {
  trackEvent("link_page_clicked", { link: boton.clave.split("-")[0] });
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const { data } = await api.get<{ data: Datos }>(
      `/api/v1/app/${slug.value}/escaparate`,
    );
    datos.value = data.data;
    updateSeo({
      title: `${data.data.estudio.nombre} | Enlaces`,
      description:
        data.data.estudio.descripcion ??
        `Enlaces de ${data.data.estudio.nombre}.`,
      path: urlCanonicaEstudio(data.data.estudio.slug, "/enlaces"),
      image: data.data.estudio.logo_url ?? undefined,
      type: "profile",
    });
  } catch {
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="ee-pagina">
    <p
      v-if="cargando"
      class="text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("perfilPublico.enlaces.cargando") }}
    </p>
    <p v-else-if="noDisponible" class="text-center font-semibold">
      {{ $t("perfilPublico.enlaces.noDisponible") }}
    </p>

    <div v-else-if="datos" class="ee-columna">
      <img
        v-if="datos.estudio.logo_url"
        :src="datos.estudio.logo_url"
        :alt="datos.estudio.nombre"
        class="ee-logo"
      />
      <h1 class="mt-4 text-xl font-semibold">{{ datos.estudio.nombre }}</h1>
      <p
        v-if="resumen"
        class="mt-1 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ resumen }}
      </p>
      <p
        v-if="(datos.resenas?.total ?? 0) > 0"
        class="mt-2 text-sm"
        data-prueba="calificacion"
      >
        <span :style="{ color: 'var(--aviso)' }" aria-hidden="true">★</span>
        {{ datos.resenas?.promedio?.toFixed(1) }}
        <span :style="{ color: 'var(--texto-suave)' }"
          >({{
            $t("escaparate.totalResenas", datos.resenas?.total ?? 0)
          }})</span
        >
      </p>

      <ul class="mt-6 w-full space-y-3" data-prueba="botones">
        <li v-for="b in botones" :key="b.clave">
          <RouterLink
            v-if="'interno' in b.destino"
            :to="b.destino.interno"
            class="ee-boton"
            @click="registrarClic(b)"
          >
            <IconoRed :red="b.icono" :tamano="18" />
            <span>{{
              $t(`perfilPublico.enlaces.${b.texto}`, b.params ?? {})
            }}</span>
          </RouterLink>
          <a
            v-else
            :href="b.destino.externo"
            target="_blank"
            rel="noopener"
            class="ee-boton"
            @click="registrarClic(b)"
          >
            <IconoRed :red="b.icono" :tamano="18" />
            <span>{{
              $t(`perfilPublico.enlaces.${b.texto}`, b.params ?? {})
            }}</span>
          </a>
        </li>
      </ul>

      <ul
        v-if="redesSociales.length > 0"
        class="mt-6 flex justify-center gap-3"
        data-prueba="redes"
      >
        <li v-for="r in redesSociales" :key="r.red">
          <a
            :href="r.url"
            target="_blank"
            rel="noopener"
            class="ee-red"
            :aria-label="$t(`perfilPublico.redes.${r.red}`)"
          >
            <IconoRed :red="r.red" />
          </a>
        </li>
      </ul>

      <p class="mt-10 text-xs">
        <RouterLink :to="{ name: 'registro' }" class="tu-enlace">{{
          $t("perfilPublico.enlaces.crearTuya")
        }}</RouterLink>
      </p>
    </div>
  </section>
</template>

<style scoped>
.ee-pagina {
  min-height: 100vh;
  padding: 2.5rem 1rem 3rem;
  background: var(--fondo);
}
.ee-columna {
  display: flex;
  max-width: 28rem;
  margin: 0 auto;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.ee-logo {
  width: 5.5rem;
  height: 5.5rem;
  border-radius: 999px;
  object-fit: cover;
  box-shadow: var(--sombra);
}
.ee-boton {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  padding: 0.85rem 1rem;
  border-radius: 0.9rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto);
  font-weight: 600;
  box-shadow: var(--sombra-tarjeta);
}
.ee-boton:hover {
  border-color: var(--primario);
}
.ee-red {
  display: inline-flex;
  width: 2.6rem;
  height: 2.6rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  border: 1px solid var(--borde);
  color: var(--texto);
  background: var(--superficie);
}
</style>
