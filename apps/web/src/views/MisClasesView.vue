<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import CalendarioVistas, {
  type EventoPeriodo,
  type Vista,
} from "@/components/CalendarioVistas.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelClase from "@/components/PanelClase.vue";
import { useRecargarAlVolver } from "@/lib/alVolver";
import { useMisClases, type ClaseMia } from "@/lib/misClases";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Calendario de quien imparte: solo sus clases o citas, en lista (próximos 30
 * días), día, semana o mes. Tocar una abre su pase de lista (clase) o su detalle
 * (cita), con "agregar a mi calendario".
 */
const { t } = useI18n();
const route = useRoute();
const sesion = useSesionTenantStore();
const { base, clases, cargando, error, cargar } = useMisClases();
const abierta = ref<ClaseMia | null>(null);
const rango = ref<{ desde: string; hasta: string } | null>(null);

const vistaInicial = computed(() =>
  typeof route.query.vista === "string" ? (route.query.vista as Vista) : null,
);
// «Próximos 7 días» abre la lista de 7 días (`?dias=7`); si no, 30.
const diasLista = computed(() => {
  const n = Number(route.query.dias);
  return Number.isInteger(n) && n > 0 && n <= 62 ? n : 30;
});
// En la lista, lo que sigue: lo que ya terminó hoy no es «próximo».
const ahora = ref(Date.now());
const proximas = computed(() =>
  clases.value.filter((c) => new Date(c.termina_en).getTime() > ahora.value),
);

function detalle(c: ClaseMia): string {
  const lugar = [c.sucursal, c.sala].filter(Boolean).join(" · ");
  if (c.tipo === "cita") {
    return [
      c.cita?.cliente
        ? t("portal.instructor.inicio.con", { nombre: c.cita.cliente })
        : null,
      lugar,
    ]
      .filter(Boolean)
      .join(" · ");
  }
  return lugar;
}
function cupo(c: ClaseMia): string {
  if (c.tipo === "cita") {
    return "";
  }
  return c.capacidad !== null
    ? `${c.ocupados}/${c.capacidad}`
    : t("portal.instructor.inicio.inscritos", { n: c.ocupados }, c.ocupados);
}

const eventos = computed<EventoPeriodo[]>(() =>
  clases.value.map((c) => ({
    id: c.id,
    titulo: c.oferta ?? "—",
    inicia: c.inicia_en,
    termina: c.termina_en,
    zona: c.zona_horaria,
    detalle: detalle(c),
    estado: cupo(c),
    tono: c.en_espera > 0 ? "aviso" : "suave",
    destacado: true,
  })),
);

async function alCambiarRango(r: { desde: string; hasta: string }) {
  rango.value = r;
  ahora.value = Date.now();
  await cargar(r.desde, r.hasta);
}
// Las próximas, por día (la lista ya no repite la fecha en cada fila).
const porDia = computed(() => {
  const grupos: { dia: string; clases: ClaseMia[] }[] = [];
  for (const c of proximas.value) {
    const dia = new Intl.DateTimeFormat("es-MX", {
      timeZone: c.zona_horaria,
      weekday: "long",
      day: "numeric",
      month: "long",
    }).format(new Date(c.inicia_en));
    const ultimo = grupos.at(-1);
    if (ultimo?.dia === dia) {
      ultimo.clases.push(c);
    } else {
      grupos.push({ dia, clases: [c] });
    }
  }
  return grupos;
});
function hora(c: ClaseMia): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: c.zona_horaria,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(c.inicia_en));
}
// En citas, a quién atiende va primero; en clases, la actividad.
function titulo(c: ClaseMia): string {
  return c.tipo === "cita"
    ? c.cita?.asiste || c.cita?.cliente || (c.oferta ?? "—")
    : (c.oferta ?? "—");
}
function segunda(c: ClaseMia): string {
  const lugar = [c.sucursal, c.sala].filter(Boolean).join(" · ");
  return c.tipo === "cita"
    ? [c.oferta, lugar].filter(Boolean).join(" · ")
    : lugar;
}

async function recargar(): Promise<void> {
  ahora.value = Date.now();
  if (rango.value) {
    await cargar(rango.value.desde, rango.value.hasta);
  }
  if (abierta.value) {
    abierta.value =
      clases.value.find((c) => c.id === abierta.value?.id) ?? null;
  }
}
useRecargarAlVolver(recargar);

function abrir(id: string): void {
  abierta.value = clases.value.find((c) => c.id === id) ?? null;
}
const eventoCalendario = computed(() =>
  abierta.value
    ? {
        uid: `sesion-${abierta.value.id}`,
        titulo: abierta.value.oferta ?? sesion.estudio?.nombre ?? "",
        inicio: abierta.value.inicia_en,
        fin: abierta.value.termina_en,
        lugar: [sesion.estudio?.nombre, abierta.value.sucursal]
          .filter(Boolean)
          .join(" · "),
      }
    : null,
);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('portal.instructor.calendario.titulo')" />

    <CalendarioVistas
      class="mt-4"
      clave="tu.instructor.vista"
      :vista-inicial="vistaInicial"
      :dias-lista="diasLista"
      :eventos="eventos"
      :cargando="cargando"
      :error="error"
      @abrir="abrir"
      @rango="alCambiarRango"
      @reintentar="recargar"
    >
      <template #barra>
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            :disabled="cargando"
            @click="recargar"
          >
            {{ $t("portal.instructor.inicio.actualizar") }}
          </button>
          <RouterLink :to="{ name: 'mi-perfil' }" class="tu-enlace text-sm">{{
            $t("portal.instructor.calendario.sincronizar")
          }}</RouterLink>
        </div>
      </template>

      <!-- LISTA: próximos 30 días -->
      <template #lista>
        <div class="tu-card p-5">
          <h2 class="font-semibold">
            {{
              diasLista === 7
                ? $t("portal.instructor.calendario.proximos7")
                : $t("portal.instructor.calendario.proximas")
            }}
          </h2>
          <template v-if="porDia.length > 0">
            <section v-for="g in porDia" :key="g.dia" class="mt-3">
              <h3 class="mc-dia first-letter:uppercase">{{ g.dia }}</h3>
              <ul class="divide-y divide-[var(--borde)]">
                <li v-for="c in g.clases" :key="c.id">
                  <button type="button" class="mc-fila" @click="abrir(c.id)">
                    <span class="mc-hora tabular-nums">{{ hora(c) }}</span>
                    <span class="min-w-0 flex-1">
                      <span class="block truncate font-medium">{{
                        titulo(c)
                      }}</span>
                      <span
                        v-if="segunda(c)"
                        class="block truncate text-sm"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ segunda(c) }}</span
                      >
                    </span>
                    <span
                      v-if="cupo(c)"
                      class="shrink-0 text-xs tabular-nums"
                      :style="{
                        color:
                          c.en_espera > 0
                            ? 'var(--aviso)'
                            : 'var(--texto-suave)',
                      }"
                      >{{ cupo(c) }}</span
                    >
                  </button>
                </li>
              </ul>
            </section>
          </template>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("portal.instructor.calendario.sinClases") }}
          </p>
        </div>
      </template>
    </CalendarioVistas>

    <!-- Pase de lista de la clase / detalle de la cita -->
    <PanelClase
      v-if="abierta && abierta.tipo !== 'cita'"
      :sesion="abierta"
      @cerrar="abierta = null"
      @cambio="recargar"
    >
      <template #acciones>
        <AgregarCalendario v-if="eventoCalendario" :evento="eventoCalendario" />
      </template>
    </PanelClase>
    <PanelCita
      :abierto="abierta !== null && abierta.tipo === 'cita'"
      :base="base"
      :sesion="abierta && abierta.tipo === 'cita' ? abierta : null"
      :catalogo="[]"
      :puede-marcar="sesion.puede('asistencia.marcar')"
      :puede-cobrar="sesion.puede('ordenes.gestionar')"
      :puede-cancelar="sesion.puede('reservas.gestionar')"
      @cerrar="abierta = null"
      @cambiada="recargar"
    >
      <template #acciones>
        <AgregarCalendario v-if="eventoCalendario" :evento="eventoCalendario" />
      </template>
    </PanelCita>
  </section>
</template>

<style scoped>
.mc-fila {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 0;
  text-align: left;
}
.mc-fila:hover .font-medium {
  color: var(--primario);
}
.mc-dia {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.mc-hora {
  width: 3.2rem;
  flex-shrink: 0;
  font-weight: 500;
}
</style>
