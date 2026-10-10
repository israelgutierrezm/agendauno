<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  type NivelPlan,
  type PlanCitas,
  NIVELES_PLAN,
  funcionesPorNivel,
  precioPlan,
} from "@/lib/suscripcion";

/**
 * El plan de un negocio de citas (ADR 0107): nivel, profesionales y mensual o anual,
 * por adelantado. Muestra el plan y, al cambiarlo, los tres niveles con su precio
 * en dólares (y lo aproximado en pesos al tipo de cambio de hoy).
 */
const props = defineProps<{
  plan: PlanCitas;
  base: string;
  ventas: { correo: string | null; whatsapp: string | null };
}>();
const emit = defineEmits<{ cambiado: [mensaje: string] }>();

const { t } = useI18n();

const abierto = ref(false);
const periodicidad = ref<"mensual" | "anual">(props.plan.periodicidad);
const profesionales = ref(Math.max(2, props.plan.profesionales));
const guardando = ref<NivelPlan | null>(null);
const error = ref<string | null>(null);

watch(
  () => props.plan,
  (p) => {
    periodicidad.value = p.periodicidad;
    profesionales.value = Math.max(2, p.profesionales);
  },
);

// No se contratan menos profesionales de los que ya tiene.
const minimo = computed(() => Math.max(2, props.plan.profesionales_actuales));

function usd(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "USD",
    currencyDisplay: "code",
    maximumFractionDigits: minor % 100 === 0 ? 0 : 2,
  }).format(minor / 100);
}
function pesos(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    maximumFractionDigits: 0,
  }).format(minor / 100);
}
function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
    new Date(a, m - 1, d),
  );
}

function cantidadDe(nivel: NivelPlan): number {
  return nivel === "individual" ? 1 : profesionales.value;
}
function precioDe(nivel: NivelPlan): number | null {
  const mensual = precioPlan(props.plan, nivel, cantidadDe(nivel));
  if (mensual === null) {
    return null;
  }
  return periodicidad.value === "anual"
    ? mensual * props.plan.meses_anual
    : mensual;
}
function aproximado(minor: number): string | null {
  const tipo = props.plan.tipo_cambio;
  if (props.plan.moneda_cobro !== "MXN" || tipo === null) {
    return null;
  }
  return pesos(Math.round(minor * Number(tipo.valor)));
}
function esActual(nivel: NivelPlan): boolean {
  return (
    props.plan.elegido &&
    props.plan.nivel === nivel &&
    props.plan.profesionales === cantidadDe(nivel) &&
    props.plan.periodicidad === periodicidad.value
  );
}
// Individual no sirve con más de un profesional.
function disponible(nivel: NivelPlan): boolean {
  if (nivel === "individual") {
    return props.plan.profesionales_actuales <= 1;
  }
  return precioDe(nivel) !== null;
}

// Lo que incluye cada nivel: el reparto que fija el superadmin en la tarifa.
const funciones = computed(() =>
  funcionesPorNivel(props.plan.funciones ?? {}, false),
);

const resumen = computed(() =>
  t(
    "suscripcion.plan.resumen",
    {
      nivel: t(`suscripcion.niveles.${props.plan.nivel}`),
      n: props.plan.profesionales,
    },
    props.plan.profesionales,
  ),
);

// Lo que pasará al elegir, como lo decide el servidor: con un periodo pagado, subir
// (cuesta más al mes) aplica hoy y cobra la diferencia; bajar, desde el siguiente.
function confirmacion(nivel: NivelPlan): string {
  const n = cantidadDe(nivel);
  const nuevo = `${t(
    "suscripcion.plan.resumen",
    { nivel: t(`suscripcion.niveles.${nivel}`), n },
    n,
  )} · ${t(`suscripcion.periodicidad.${periodicidad.value}`).toLowerCase()}`;
  const hoy = new Date().toLocaleDateString("en-CA");
  const cubierto =
    props.plan.cubierto_hasta !== null && props.plan.cubierto_hasta >= hoy;
  if (!cubierto) {
    return t("suscripcion.plan.confirmarAhora", { plan: nuevo });
  }
  const actual =
    precioPlan(props.plan, props.plan.nivel, props.plan.profesionales) ?? 0;
  const siguiente = precioPlan(props.plan, nivel, n) ?? 0;
  return siguiente > actual
    ? t("suscripcion.plan.confirmarSubir", { plan: nuevo })
    : t("suscripcion.plan.confirmarSiguiente", { plan: nuevo });
}

async function elegir(nivel: NivelPlan): Promise<void> {
  if (
    !(await confirmar(confirmacion(nivel), {
      aceptar: t("suscripcion.plan.cambiar"),
    }))
  ) {
    return;
  }
  guardando.value = nivel;
  error.value = null;
  try {
    const { data } = await api.put<{
      data: {
        aplica: "ahora" | "siguiente";
        ajuste: { monto_minor: number; moneda: string; estado: string } | null;
      };
    }>(`${props.base}/renta/plan`, {
      nivel,
      profesionales: cantidadDe(nivel),
      periodicidad: periodicidad.value,
    });
    const { aplica, ajuste } = data.data;
    let mensaje = t("suscripcion.plan.aplicaSiguiente");
    if (aplica === "ahora") {
      mensaje =
        ajuste === null
          ? t("suscripcion.plan.aplicaAhora")
          : t(
              ajuste.estado === "pagado"
                ? "suscripcion.plan.aplicaAhoraPagado"
                : "suscripcion.plan.aplicaAhoraCobro",
              {
                monto: new Intl.NumberFormat("es-MX", {
                  style: "currency",
                  currency: ajuste.moneda,
                }).format(ajuste.monto_minor / 100),
              },
            );
    }
    abierto.value = false;
    emit("cambiado", mensaje);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = null;
  }
}

const enlaceWhatsApp = computed(() =>
  props.ventas.whatsapp
    ? `https://wa.me/${props.ventas.whatsapp.replace(/\D/g, "")}`
    : null,
);
</script>

<template>
  <div class="tu-card p-5" data-prueba="plan-citas">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div>
        <h2 class="font-medium">{{ $t("suscripcion.plan.titulo") }}</h2>
        <p class="mt-2 text-lg font-semibold">{{ resumen }}</p>
        <p class="mt-1 text-sm pc-suave">
          {{ $t(`suscripcion.periodicidad.${plan.periodicidad}`) }} ·
          {{ $t("suscripcion.plan.porAdelantado") }}
        </p>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        data-prueba="cambiar-plan"
        :aria-expanded="abierto"
        @click="abierto = !abierto"
      >
        {{
          abierto
            ? $t("suscripcion.plan.cerrar")
            : $t("suscripcion.plan.cambiar")
        }}
      </button>
    </div>

    <ul class="mt-3 text-sm pc-suave space-y-1">
      <li v-if="plan.en_prueba">{{ $t("suscripcion.plan.pruebaPro") }}</li>
      <li v-if="!plan.elegido && plan.en_prueba">
        {{
          $t(
            "suscripcion.plan.sinElegir",
            {
              nivel: $t(`suscripcion.niveles.${plan.nivel}`),
              n: plan.profesionales,
            },
            plan.profesionales,
          )
        }}
      </li>
      <li v-if="plan.cubierto_hasta">
        {{
          $t("suscripcion.plan.cubiertoHasta", {
            fecha: fecha(plan.cubierto_hasta),
          })
        }}
      </li>
      <li v-if="plan.siguiente">
        {{
          $t("suscripcion.plan.siguiente", {
            nivel: $t(`suscripcion.niveles.${plan.siguiente.nivel}`),
            n: plan.siguiente.profesionales,
            periodicidad: $t(
              `suscripcion.periodicidad.${plan.siguiente.periodicidad}`,
            ).toLowerCase(),
          })
        }}
      </li>
      <li v-if="plan.limite_profesionales !== null">
        {{
          $t("suscripcion.plan.profesionales", {
            actuales: plan.profesionales_actuales,
            limite: plan.limite_profesionales,
          })
        }}
      </li>
    </ul>

    <div v-if="abierto" class="mt-5" data-prueba="selector-plan">
      <div class="flex items-center gap-4 flex-wrap">
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('suscripcion.plan.formaPago')"
        >
          <button
            v-for="p in ['mensual', 'anual'] as const"
            :key="p"
            type="button"
            :aria-pressed="periodicidad === p"
            @click="periodicidad = p"
          >
            {{ $t(`suscripcion.periodicidad.${p}`) }}
          </button>
        </div>
        <span v-if="periodicidad === 'anual'" class="text-sm pc-suave">
          {{
            plan.meses_anual < 12
              ? $t("suscripcion.periodicidad.anualAyuda", {
                  meses: plan.meses_anual,
                  cortesia: 12 - plan.meses_anual,
                })
              : $t("suscripcion.periodicidad.anualSinCortesia", {
                  meses: plan.meses_anual,
                })
          }}
        </span>
      </div>

      <div class="mt-4 flex items-center gap-3">
        <span class="text-sm">{{ $t("suscripcion.plan.porProfesional") }}</span>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma pc-paso"
          :aria-label="$t('suscripcion.plan.menos')"
          :disabled="profesionales <= minimo"
          @click="profesionales--"
        >
          <IconoNav nombre="menos" :tam="16" />
        </button>
        <span class="font-semibold pc-numero" data-prueba="profesionales">{{
          profesionales
        }}</span>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma pc-paso"
          :aria-label="$t('suscripcion.plan.mas')"
          :disabled="profesionales >= plan.max_profesionales"
          @click="profesionales++"
        >
          <IconoNav nombre="mas" :tam="16" />
        </button>
      </div>

      <div class="mt-4 grid gap-3 md:grid-cols-3">
        <article
          v-for="nivel in NIVELES_PLAN"
          :key="nivel"
          class="pc-nivel"
          :class="{ 'pc-nivel-actual': esActual(nivel) }"
          :data-prueba="`nivel-${nivel}`"
        >
          <h3 class="font-medium">{{ $t(`suscripcion.niveles.${nivel}`) }}</h3>
          <p class="mt-1 text-xs pc-suave">
            {{
              $t(
                "suscripcion.plan.resumen",
                {
                  nivel: $t(`suscripcion.niveles.${nivel}`),
                  n: cantidadDe(nivel),
                },
                cantidadDe(nivel),
              )
            }}
          </p>
          <template v-if="precioDe(nivel) !== null">
            <p class="mt-3 text-2xl font-semibold">
              {{ usd(precioDe(nivel) ?? 0) }}
            </p>
            <p class="text-xs pc-suave">
              {{
                periodicidad === "anual"
                  ? $t("suscripcion.plan.porAno")
                  : $t("suscripcion.plan.porMes")
              }}
              <template v-if="aproximado(precioDe(nivel) ?? 0)">
                ·
                {{
                  $t("suscripcion.plan.aproximado", {
                    monto: aproximado(precioDe(nivel) ?? 0),
                  })
                }}
              </template>
            </p>
          </template>
          <ul class="mt-3 space-y-1 text-sm">
            <li v-for="f in funciones[nivel]" :key="f">{{ f }}</li>
          </ul>
          <!-- Por qué no se puede elegir (Individual con más de un profesional). -->
          <p
            v-if="nivel === 'individual' && !disponible(nivel)"
            class="mt-3 text-xs pc-suave"
          >
            {{
              $t("suscripcion.plan.individualUno", {
                n: plan.profesionales_actuales,
              })
            }}
          </p>
          <button
            type="button"
            class="tu-btn mt-4 w-full"
            :class="esActual(nivel) ? 'tu-btn-fantasma' : 'tu-btn-primario'"
            :disabled="
              esActual(nivel) || !disponible(nivel) || guardando !== null
            "
            @click="elegir(nivel)"
          >
            {{
              guardando === nivel
                ? $t("suscripcion.plan.guardando")
                : esActual(nivel)
                  ? $t("suscripcion.plan.actual")
                  : $t("suscripcion.plan.elegir")
            }}
          </button>
        </article>
      </div>

      <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <p class="mt-3 text-xs pc-suave">
        {{
          plan.moneda_cobro === "MXN" && plan.tipo_cambio
            ? $t("suscripcion.plan.tipoCambio", {
                valor: plan.tipo_cambio.valor,
              })
            : $t("suscripcion.plan.enDolares")
        }}
        {{ $t("suscripcion.plan.subir") }}
      </p>
      <p class="mt-1 text-xs pc-suave">
        {{ $t("suscripcion.plan.masDe", { n: plan.max_profesionales }) }}
        <a
          v-if="ventas.correo"
          class="tu-enlace"
          :href="`mailto:${ventas.correo}`"
          >{{
            $t("suscripcion.plan.cotizarCorreo", { correo: ventas.correo })
          }}</a
        >
        <template v-if="enlaceWhatsApp">
          {{ " " }}
          <a
            class="tu-enlace"
            :href="enlaceWhatsApp"
            target="_blank"
            rel="noopener"
            >{{ $t("suscripcion.plan.cotizarWhatsApp") }}</a
          >
        </template>
      </p>
    </div>
  </div>
</template>

<style scoped>
.pc-suave {
  color: var(--texto-suave);
}
.pc-nivel {
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.pc-nivel-actual {
  border-color: var(--acento);
}
.pc-paso {
  min-width: 2.25rem;
  justify-content: center;
}
.pc-numero {
  min-width: 1.75rem;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
</style>
