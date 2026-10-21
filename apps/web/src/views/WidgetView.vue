<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import CalendarioPublico from "@/components/CalendarioPublico.vue";
import FormularioCaptacion from "@/components/FormularioCaptacion.vue";
import HechoCon from "@/components/HechoCon.vue";
import TarjetaPlanPublico, {
  type PlanPublico,
} from "@/components/TarjetaPlanPublico.vue";
import { api } from "@/lib/api";
import { volverConInteres } from "@/lib/destinoSeguro";
import { dinero } from "@/lib/formato";
import type { TerminosNegocio } from "@/lib/terminologia";
import {
  contrasteDe,
  hostDeQuienInserta,
  permitidoEn,
  type WidgetPublico,
} from "@/lib/widgets";

/**
 * Un widget dentro de la web del negocio (ADR 0141): su calendario de clases, sus
 * servicios para agendar o sus planes, sin encabezado ni pie, con su tema y su color.
 * Reutiliza lo público (no es otro motor de reservas) y termina en la reserva o la
 * compra:
 * - **Agendar (citas)**: el servicio elegido abre la página para agendar del negocio,
 *   en otra pestaña, con el servicio y la sede ya elegidos: agenda y paga sin cuenta.
 * - **Calendario y planes**: quien ya es cliente reserva o compra con su cuenta (otra
 *   pestaña, con la clase o el plan elegidos, ADR 0142); quien no, deja sus datos.
 * Avisa su altura a la web que lo inserta. En el teléfono, una sola columna.
 */
const props = defineProps<{ slug: string; widget: string }>();
const { t } = useI18n();
const route = useRoute();

const config = ref<WidgetPublico | null>(null);
const estado = ref<"cargando" | "listo" | "no_disponible">("cargando");
const formulario = ref<string | null>(null);
const planes = ref<PlanPublico[]>([]);
// Los precios no se pudieron consultar: no es «aún no hay precios».
const errorPlanes = ref(false);
const terminos = ref<TerminosNegocio | null>(null);
// Los servicios para agendar (citas).
interface ServicioWidget {
  id: string;
  nombre: string;
  descripcion?: string | null;
  categoria?: string | null;
  precio_minor: number | null;
  moneda: string;
  duracion_minutos: number | null;
  anticipo_minor?: number | null;
}
const servicios = ref<ServicioWidget[]>([]);
const errorServicios = ref(false);
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
// Si ya tiene cuenta, entra y sigue con el plan que eligió (ADR 0142).
const enlaceEntrar = computed(() => {
  const query = new URLSearchParams({ estudio: props.slug });
  const volver = volverConInteres(pidiendo.value);
  if (volver !== null) query.set("volver", volver);
  return `/entrar?${query.toString()}`;
});

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
    await cargarPlanes();
  } else if (config.value.tipo === "agendar") {
    await cargarServicios();
  }
  estado.value = "listo";
}

async function cargarServicios(): Promise<void> {
  errorServicios.value = false;
  try {
    const { data } = await api.get<{ data: { servicios: ServicioWidget[] } }>(
      `/api/v1/app/${encodeURIComponent(props.slug)}/citas/opciones`,
    );
    servicios.value = data.data.servicios;
  } catch {
    servicios.value = [];
    errorServicios.value = true;
  }
}

/** La página para agendar del negocio, con el servicio (y la sede) ya elegidos. */
function enlaceAgendar(s: ServicioWidget): string {
  const query = new URLSearchParams({ servicio: s.id });
  const sede = config.value?.filtros.sucursal;
  if (sede) query.set("sucursal", sede);
  return `/agendar/${encodeURIComponent(props.slug)}?${query.toString()}`;
}

async function cargarPlanes(): Promise<void> {
  if (config.value === null) {
    return;
  }
  const base = `/api/v1/app/${encodeURIComponent(props.slug)}`;
  errorPlanes.value = false;
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
    errorPlanes.value = true;
  }
}

// Eligió un plan o una clase: el cliente lo compra o reserva con su cuenta; quien no
// es cliente deja sus datos. Sin formulario de captación, directo a su cuenta.
function elegir(interes: {
  producto?: string | null;
  sesion?: string | null;
  etiqueta: string;
}): void {
  pidiendo.value = interes;
  if (formulario.value === null) {
    window.open(enlaceEntrar.value, "_blank", "noopener");
  }
}
function elegirPlan(p: PlanPublico): void {
  elegir({ producto: p.id ?? null, etiqueta: p.nombre });
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
      <!-- Eligió una clase o un plan: el cliente sigue con su cuenta (reserva o
           compra); quien no es cliente deja sus datos para eso. -->
      <section v-if="pidiendo && formulario" class="grid gap-3">
        <button
          type="button"
          class="tu-enlace justify-self-start"
          @click="pidiendo = null"
        >
          {{ t("captacion.widgets.publico.volver") }}
        </button>
        <div class="wg-cliente">
          <p class="font-medium">{{ pidiendo.etiqueta }}</p>
          <a
            class="tu-btn tu-btn-primario"
            :href="enlaceEntrar"
            target="_blank"
            rel="noopener"
            data-prueba="widget-reservar-cuenta"
            >{{
              pidiendo.producto
                ? t("captacion.widgets.publico.comprarConCuenta")
                : t("captacion.widgets.publico.reservarConCuenta")
            }}</a
          >
        </div>
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
          canal="widget"
          compacto
        />
      </section>
      <template v-else>
        <CalendarioPublico
          v-if="config.tipo === 'calendario'"
          :slug="slug"
          :sucursal="config.filtros.sucursal ?? ''"
          :actividad="config.filtros.actividad ?? ''"
          puede-pedir
          @pedir="(c) => elegir({ sesion: c.sesion, etiqueta: c.etiqueta })"
        />
        <!-- Citas: cada servicio abre la página para agendar (y pagar) sin cuenta. -->
        <template v-else-if="config.tipo === 'agendar'">
          <p
            v-if="errorServicios"
            class="py-8 text-center text-sm"
            role="alert"
            data-prueba="widget-error-servicios"
          >
            <span style="color: var(--error)">{{
              t("captacion.widgets.publico.errorServicios")
            }}</span>
            <button
              type="button"
              class="tu-enlace ml-2"
              @click="cargarServicios"
            >
              {{ t("comun.reintentar") }}
            </button>
          </p>
          <p
            v-else-if="servicios.length === 0"
            class="py-8 text-center text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ t("captacion.widgets.publico.sinServicios") }}
          </p>
          <ul v-else class="wg-servicios" data-prueba="widget-servicios">
            <li v-for="s in servicios" :key="s.id" class="tu-card p-4">
              <div class="min-w-0">
                <p class="font-medium">{{ s.nombre }}</p>
                <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                  <template v-if="s.duracion_minutos"
                    >{{
                      t("captacion.widgets.publico.minutos", {
                        n: s.duracion_minutos,
                      })
                    }}
                    ·
                  </template>
                  <template v-if="s.precio_minor !== null">{{
                    dinero(s.precio_minor, s.moneda)
                  }}</template>
                </p>
                <p
                  v-if="s.anticipo_minor"
                  class="text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{
                    t("captacion.widgets.publico.anticipo", {
                      monto: dinero(s.anticipo_minor, s.moneda),
                    })
                  }}
                </p>
              </div>
              <a
                class="tu-btn tu-btn-primario shrink-0"
                :href="enlaceAgendar(s)"
                target="_blank"
                rel="noopener"
                data-prueba="widget-agendar"
                >{{ t("captacion.widgets.publico.agendar") }}</a
              >
            </li>
          </ul>
        </template>
        <template v-else>
          <p
            v-if="errorPlanes"
            class="py-8 text-center text-sm"
            role="alert"
            data-prueba="widget-error-planes"
          >
            <span style="color: var(--error)">{{
              t("escaparate.errorPrecios")
            }}</span>
            <button type="button" class="tu-enlace ml-2" @click="cargarPlanes">
              {{ t("comun.reintentar") }}
            </button>
          </p>
          <p
            v-else-if="planes.length === 0"
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
                :boton="t('captacion.widgets.publico.loQuiero')"
                @elegir="elegirPlan(p)"
              />
            </li>
          </ul>
        </template>
      </template>
      <!-- Quien ya es cliente entra y sigue con lo que eligió (ADR 0142): también
           desde el formulario, con la clase o el plan en el regreso. -->
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
    <!-- La leyenda fija de la plataforma, también dentro de otra web (ADR 0167). -->
    <p class="mt-4 text-center">
      <HechoCon />
    </p>
  </div>
</template>

<style scoped>
/* Una columna en el teléfono; dos o tres si cabe (sin depender del mouse). */
.wg-servicios {
  display: grid;
  gap: 0.75rem;
}
.wg-servicios > li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.wg-cliente {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
}
.wg-planes {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
}
</style>
