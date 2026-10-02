<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, type RouteLocationRaw } from "vue-router";

/**
 * «Pon tu negocio en marcha» en el Inicio del dueño (R36): los MISMOS pasos que la
 * configuración guiada (cada «Ir» abre ese paso) y el mismo «listo para operar»
 * (ADR 0090): configurado, página publicada y recibe reservas. Lo opcional va debajo.
 * El estado sale de los datos reales del negocio (`GET …/onboarding/quickstart`).
 */
export interface Quickstart {
  tareas: Array<{
    clave: string;
    hecho: boolean;
    requerido: boolean;
    ruta: string;
  }>;
  progreso: { hechas: number; total: number };
  listo: boolean;
  estado?: {
    configurado: boolean;
    publicado: boolean;
    reservable: boolean;
    listo: boolean;
    primera_fecha: {
      inicia_en: string;
      zona_horaria: string | null;
      sucursal: string | null;
      que: string | null;
    } | null;
    motivo: string | null;
  };
}

const props = defineProps<{ quickstart: Quickstart }>();

const requeridas = computed(() =>
  props.quickstart.tareas.filter((t) => t.requerido),
);
const opcionales = computed(() =>
  props.quickstart.tareas.filter((t) => !t.requerido),
);
const { t: traducir } = useI18n();
// Un paso de la configuración se abre en el asistente, justo en ese paso.
function destino(tarea: Quickstart["tareas"][number]): RouteLocationRaw {
  return tarea.ruta === "onboarding"
    ? { name: "onboarding", query: { paso: tarea.clave } }
    : { name: tarea.ruta };
}
const estados = computed(() => {
  const e = props.quickstart.estado;
  if (!e) {
    return [];
  }
  return [
    { clave: "configurado", si: e.configurado },
    { clave: "publicado", si: e.publicado },
    { clave: "reservable", si: e.reservable },
  ];
});
const detalleReservable = computed(() => {
  const e = props.quickstart.estado;
  if (!e) {
    return "";
  }
  if (e.primera_fecha) {
    const fecha = new Intl.DateTimeFormat("es-MX", {
      weekday: "short",
      day: "numeric",
      month: "short",
      hour: "numeric",
      minute: "2-digit",
      timeZone: e.primera_fecha.zona_horaria ?? undefined,
    }).format(new Date(e.primera_fecha.inicia_en));
    return traducir("configuracionInicial.estados.primera", {
      fecha,
      que: e.primera_fecha.que ?? "",
      sucursal: e.primera_fecha.sucursal ?? "",
    });
  }
  return e.motivo
    ? traducir(`configuracionInicial.estados.motivo.${e.motivo}`)
    : "";
});
const avance = computed(() =>
  Math.round(
    (props.quickstart.progreso.hechas /
      Math.max(props.quickstart.progreso.total, 1)) *
      100,
  ),
);
</script>

<template>
  <section class="tu-card pm" data-prueba="pon-en-marcha">
    <div class="pm-cabeza">
      <svg
        class="pm-cohete"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path
          d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.493 4.493 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"
        />
      </svg>
      <h2 class="pm-titulo">{{ $t("quickstart.titulo") }}</h2>
      <span class="pm-cuenta tabular-nums" data-prueba="avance"
        >{{ quickstart.progreso.hechas }}/{{ quickstart.progreso.total }}</span
      >
      <RouterLink :to="{ name: 'onboarding' }" class="tu-enlace pm-guiada">
        {{ $t("quickstart.guiada") }}
      </RouterLink>
    </div>

    <div
      class="pm-barra"
      role="progressbar"
      :aria-label="$t('quickstart.titulo')"
      aria-valuemin="0"
      :aria-valuemax="quickstart.progreso.total"
      :aria-valuenow="quickstart.progreso.hechas"
    >
      <div class="pm-relleno" :style="{ width: `${avance}%` }" />
    </div>

    <!-- Configurado · publicado · recibe reservas -->
    <ul v-if="estados.length > 0" class="pm-estados" data-prueba="estados">
      <li
        v-for="e in estados"
        :key="e.clave"
        :data-prueba="`estado-${e.clave}`"
        :data-si="e.si"
      >
        <span
          class="pm-punto"
          :class="{ 'pm-punto-si': e.si }"
          aria-hidden="true"
        />
        {{ $t(`configuracionInicial.estados.${e.clave}`) }}
        <span class="sr-only">{{
          e.si ? $t("quickstart.hecho") : $t("quickstart.pendiente")
        }}</span>
      </li>
      <li v-if="detalleReservable" class="pm-detalle">
        {{ detalleReservable }}
      </li>
    </ul>

    <ol class="pm-pasos">
      <li
        v-for="t in requeridas"
        :key="t.clave"
        class="pm-paso"
        :data-prueba="`paso-${t.clave}`"
      >
        <span v-if="t.hecho" class="pm-marca pm-hecha" aria-hidden="true">
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M5 12.5l4.5 4.5L19 7.5" />
          </svg>
        </span>
        <span v-else class="pm-marca pm-pendiente" aria-hidden="true" />
        <span class="pm-texto">
          {{ $t(`configuracionInicial.pasos.${t.clave}`) }}
          <span class="sr-only">{{
            t.hecho ? $t("quickstart.hecho") : $t("quickstart.pendiente")
          }}</span>
        </span>
        <RouterLink
          v-if="!t.hecho"
          :to="destino(t)"
          class="pm-ir"
          data-prueba="ir"
          >{{ $t("quickstart.ir") }}</RouterLink
        >
      </li>
    </ol>

    <div v-if="opcionales.length > 0" class="pm-opcionales">
      <span class="pm-etiqueta">{{ $t("quickstart.opcionales") }}</span>
      <ul class="pm-lista-opcional">
        <li
          v-for="t in opcionales"
          :key="t.clave"
          class="pm-opcional"
          :data-prueba="`opcional-${t.clave}`"
        >
          <span
            v-if="t.hecho"
            class="pm-marca pm-marca-chica pm-hecha"
            aria-hidden="true"
          >
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="3"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M5 12.5l4.5 4.5L19 7.5" />
            </svg>
          </span>
          <span
            v-else
            class="pm-marca pm-marca-chica pm-pendiente"
            aria-hidden="true"
          />
          <span v-if="t.hecho"
            >{{ $t(`quickstart.tareas.${t.clave}`) }}
            <span class="sr-only">{{ $t("quickstart.hecho") }}</span></span
          >
          <RouterLink v-else :to="destino(t)" class="pm-opcional-enlace">{{
            $t(`quickstart.tareas.${t.clave}`)
          }}</RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.pm {
  padding: 1.25rem 1.5rem;
}
.pm-cabeza {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.35rem 0.6rem;
}
.pm-cohete {
  width: 1.4rem;
  height: 1.4rem;
  flex-shrink: 0;
  color: var(--texto-suave);
}
.pm-titulo {
  font-size: 1.05rem;
  font-weight: 600;
}
.pm-cuenta {
  margin-left: 0.35rem;
  color: var(--texto-suave);
}
.pm-guiada {
  margin-left: auto;
  font-size: 0.875rem;
}

.pm-barra {
  margin-top: 0.9rem;
  height: 0.5rem;
  border-radius: 999px;
  overflow: hidden;
  background: var(--superficie-2);
}
.pm-relleno {
  height: 100%;
  border-radius: 999px;
  background: var(--primario);
  transition: width 0.3s ease;
}

.pm-estados {
  margin-top: 0.85rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 1.25rem;
  font-size: 0.85rem;
}
.pm-estados > li {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.pm-detalle {
  color: var(--texto-suave);
}
.pm-punto {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--texto-suave);
  opacity: 0.45;
}
.pm-punto-si {
  background: var(--exito);
  opacity: 1;
}

/* Los pasos del asistente, en tantas columnas como quepan. */
.pm-pasos {
  margin-top: 1.1rem;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
  gap: 0.6rem;
}
.pm-paso {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  font-size: 0.875rem;
}
.pm-texto {
  flex: 1;
  min-width: 0;
  line-height: 1.35;
}

.pm-marca {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.4rem;
  height: 1.4rem;
  flex-shrink: 0;
  border-radius: 999px;
}
.pm-marca svg {
  width: 62%;
  height: 62%;
}
.pm-marca-chica {
  width: 1.1rem;
  height: 1.1rem;
}
.pm-hecha {
  background: var(--exito);
  color: #fff;
}
.pm-pendiente {
  border: 1.5px solid var(--texto-suave);
  opacity: 0.6;
}

.pm-ir {
  flex-shrink: 0;
  color: var(--primario);
  font-size: 0.85rem;
  font-weight: 600;
}
.pm-ir:hover {
  text-decoration: underline;
}

.pm-opcionales {
  margin-top: 1.1rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--borde);
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem 1.25rem;
  font-size: 0.875rem;
}
.pm-etiqueta {
  color: var(--texto-suave);
}
.pm-lista-opcional {
  display: flex;
  flex-wrap: wrap;
  row-gap: 0.5rem;
}
.pm-opcional {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0 1.25rem;
  border-left: 1px solid var(--borde);
}
.pm-opcional:first-child {
  padding-left: 0;
  border-left: 0;
}
.pm-opcional-enlace {
  color: var(--texto-suave);
}
.pm-opcional-enlace:hover {
  color: var(--texto);
  text-decoration: underline;
}

/* Angosto: los pasos uno bajo otro y lo opcional en lista. */
@media (max-width: 899px) {
  .pm {
    padding: 1.1rem 1rem;
  }
  .pm-pasos {
    grid-template-columns: 1fr;
  }
  .pm-opcionales {
    align-items: flex-start;
    flex-direction: column;
  }
  .pm-lista-opcional {
    flex-direction: column;
  }
  .pm-opcional,
  .pm-opcional:first-child {
    padding: 0.3rem 0;
    border-left: 0;
  }
}
</style>
