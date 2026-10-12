<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import CalendarioPublico from "@/components/CalendarioPublico.vue";
import FormularioCaptacion from "@/components/FormularioCaptacion.vue";
import TarjetaPlanPublico, {
  type PlanPublico,
} from "@/components/TarjetaPlanPublico.vue";
import { api } from "@/lib/api";
import type { TerminosNegocio } from "@/lib/terminologia";
import {
  contrasteDe,
  hostDeQuienInserta,
  permitidoEn,
  type WidgetPublico,
} from "@/lib/widgets";

/**
 * Un widget dentro de la web del negocio (ADR 0141): su calendario de clases o sus
 * planes, sin encabezado ni pie, con su tema y su color. Reutiliza lo público: el
 * calendario (con sus filtros), las tarjetas de planes y el formulario de captación
 * para pedir lugar o un plan (no es otro motor de reservas). Avisa su altura a la web
 * que lo inserta. En el teléfono, una sola columna y todo con toques.
 */
const props = defineProps<{ slug: string; widget: string }>();
const { t } = useI18n();
const route = useRoute();

const config = ref<WidgetPublico | null>(null);
const estado = ref<"cargando" | "listo" | "no_disponible">("cargando");
const formulario = ref<string | null>(null);
const planes = ref<PlanPublico[]>([]);
const terminos = ref<TerminosNegocio | null>(null);
// Lo que eligió (una clase o un plan): deja sus datos para eso.
const pidiendo = ref<{
  sesion?: string | null;
  producto?: string | null;
  etiqueta: string;
} | null>(null);

// En la vista previa de la plataforma se muestra siempre.
const permitido = computed(
  () =>
    route.query.vista_previa === "1" ||
    config.value === null ||
    permitidoEn(hostDeQuienInserta(), config.value.dominios),
);
const variasSedes = computed(() =>
  planes.value.some((p) => p.todas_sucursales === false),
);
const estiloAcento = computed(() => {
  const acento = config.value?.acento;
  return acento
    ? {
        "--acento": acento,
        "--primario": acento,
        "--primario-fuerte": acento,
        "--marketing-cta": acento,
        "--primario-contraste": contrasteDe(acento),
        "--marketing-cta-contraste": contrasteDe(acento),
      }
    : {};
});
const enlaceEntrar = computed(
  () => `/entrar?estudio=${encodeURIComponent(props.slug)}`,
);

function aplicarTema(tema: WidgetPublico["tema"]): void {
  const oscuro =
    tema === "oscuro" ||
    (tema === "auto" &&
      window.matchMedia?.("(prefers-color-scheme: dark)").matches === true);
  document.documentElement.classList.toggle("dark", oscuro);
}

async function cargar(): Promise<void> {
  const base = `/api/v1/app/${encodeURIComponent(props.slug)}`;
  try {
    const { data } = await api.get<{ data: WidgetPublico }>(
      `${base}/widgets/${encodeURIComponent(props.widget)}`,
    );
    config.value = data.data;
    aplicarTema(data.data.tema);
  } catch {
    estado.value = "no_disponible";
    return;
  }
  // El formulario para pedir lugar o un plan (si el negocio capta prospectos).
  try {
    const { data } = await api.get<{ data: { id: string } }>(
      `${base}/captacion`,
    );
    formulario.value = data.data.id;
  } catch {
    formulario.value = null;
  }
  if (config.value.tipo === "planes") {
    try {
      const { data } = await api.get<{
        data: {
          productos: PlanPublico[];
          estudio: {
            perfil_config: { terminologia?: Partial<TerminosNegocio> };
          };
        };
      }>(`${base}/escaparate`);
      const sede = config.value.filtros.sucursal;
      planes.value = data.data.productos.filter(
        (p) =>
          !sede ||
          p.todas_sucursales !== false ||
          (p.sucursales ?? []).some((s) => s.id === sede),
      );
      const tr = data.data.estudio.perfil_config.terminologia;
      terminos.value =
        tr?.sesion && tr.miembro && tr.instructor
          ? (tr as TerminosNegocio)
          : null;
    } catch {
      planes.value = [];
    }
  }
  estado.value = "listo";
}

function elegirPlan(p: PlanPublico): void {
  if (formulario.value === null) {
    window.open(
      `/${encodeURIComponent(props.slug)}#precios`,
      "_blank",
      "noopener",
    );
    return;
  }
  pidiendo.value = { producto: p.id ?? null, etiqueta: p.nombre };
}

// La altura, para que la web que lo inserta no muestre barras de desplazamiento.
const caja = ref<HTMLElement | null>(null);
let observador: ResizeObserver | null = null;
function avisarAltura(): void {
  if (window.parent === window || caja.value === null) {
    return;
  }
  window.parent.postMessage(
    {
      tipo: "agendauno:altura",
      altura: Math.ceil(caja.value.scrollHeight) + 8,
    },
    "*",
  );
}
onMounted(() => {
  void cargar();
  if (typeof ResizeObserver !== "undefined" && caja.value) {
    observador = new ResizeObserver(avisarAltura);
    observador.observe(caja.value);
  }
});
onBeforeUnmount(() => observador?.disconnect());
</script>

<template>
  <div
    ref="caja"
    class="wg p-3"
    :style="estiloAcento"
    data-prueba="widget"
    :data-tipo="config?.tipo"
  >
    <p
      v-if="estado === 'cargando'"
      class="py-8 text-center text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("captacion.widgets.publico.cargando") }}
    </p>
    <p
      v-else-if="estado === 'no_disponible'"
      class="py-8 text-center text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="widget-no-disponible"
    >
      {{ t("captacion.widgets.publico.noDisponible") }}
    </p>
    <p
      v-else-if="!permitido"
      class="py-8 text-center text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="widget-no-autorizado"
    >
      {{ t("captacion.widgets.publico.noAutorizado") }}
    </p>
    <template v-else-if="config">
      <!-- Eligió una clase o un plan: deja sus datos para eso. -->
      <section v-if="pidiendo && formulario" class="grid gap-3">
        <button
          type="button"
          class="tu-enlace justify-self-start"
          @click="pidiendo = null"
        >
          {{ t("captacion.widgets.publico.volver") }}
        </button>
        <p class="font-medium">
          {{
            t("captacion.widgets.publico.pidiendo", {
              etiqueta: pidiendo.etiqueta,
            })
          }}
        </p>
        <FormularioCaptacion
          :slug="slug"
          :formulario="formulario"
          :interes="pidiendo"
          compacto
        />
      </section>
      <template v-else>
        <CalendarioPublico
          v-if="config.tipo === 'calendario'"
          :slug="slug"
          :sucursal="config.filtros.sucursal ?? ''"
          :actividad="config.filtros.actividad ?? ''"
          :puede-pedir="formulario !== null"
          @pedir="
            (c) => (pidiendo = { sesion: c.sesion, etiqueta: c.etiqueta })
          "
        />
        <template v-else>
          <p
            v-if="planes.length === 0"
            class="py-8 text-center text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ t("escaparate.sinPrecios") }}
          </p>
          <ul v-else class="wg-planes" data-prueba="widget-planes">
            <li v-for="(p, i) in planes" :key="p.id ?? i">
              <TarjetaPlanPublico
                :plan="p"
                :terminos="terminos"
                :varias-sedes="variasSedes"
                :boton="t('captacion.widgets.publico.comprar')"
                @elegir="elegirPlan(p)"
              />
            </li>
          </ul>
        </template>
        <p class="mt-4 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ t("captacion.widgets.publico.yaCuenta") }}
          <a
            class="tu-enlace"
            :href="enlaceEntrar"
            target="_blank"
            rel="noopener"
            data-prueba="widget-entrar"
            >{{ t("captacion.widgets.publico.entrar") }}</a
          >
        </p>
      </template>
    </template>
  </div>
</template>

<style scoped>
/* Una columna en el teléfono; dos o tres si cabe (sin depender del mouse). */
.wg-planes {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
}
</style>
