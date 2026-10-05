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
import IconoNav from "@/components/IconoNav.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelClase from "@/components/PanelClase.vue";
import { useRecargarAlVolver } from "@/lib/alVolver";
import { estadoCita } from "@/lib/agenda";
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
const busqueda = ref("");
const sucursal = ref("");
const sucursales = computed(() =>
  [
    ...new Set(
      clases.value
        .map((c) => c.sucursal)
        .filter((s): s is string => Boolean(s)),
    ),
  ].sort(),
);
const normalizar = (s: string) =>
  s
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase();
const filtradas = computed(() =>
  clases.value.filter(
    (c) =>
      (!sucursal.value || c.sucursal === sucursal.value) &&
      normalizar([titulo(c), segunda(c)].join(" ")).includes(
        normalizar(busqueda.value.trim()),
      ),
  ),
);

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
  filtradas.value.filter((c) => new Date(c.termina_en).getTime() > ahora.value),
);

function detalle(c: ClaseMia): string {
  const lugar = [c.sucursal, c.sala].filter(Boolean).join(" · ");
  if (c.tipo === "cita") {
    return [c.oferta, lugar].filter(Boolean).join(" · ");
  }
  return lugar;
}
function cupo(c: ClaseMia): string {
  if (c.tipo === "cita") {
    return t(
      `agendaVisual.estadosCita.${estadoCita(c, new Date(ahora.value))}`,
    );
  }
  return c.capacidad !== null
    ? `${c.ocupados}/${c.capacidad}`
    : t("portal.instructor.inicio.inscritos", { n: c.ocupados }, c.ocupados);
}

const eventos = computed<EventoPeriodo[]>(() =>
  filtradas.value.map((c) => ({
    id: c.id,
    titulo: titulo(c),
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
function fin(c: ClaseMia): string {
  return hora({ ...c, inicia_en: c.termina_en });
}
function fecha(c: ClaseMia, parte: "day" | "month"): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: c.zona_horaria,
    ...(parte === "day" ? { day: "numeric" } : { month: "short" }),
  }).format(new Date(c.inicia_en));
}
function ocupacion(c: ClaseMia): number {
  return c.capacidad && c.capacidad > 0
    ? Math.min(100, (c.ocupados / c.capacidad) * 100)
    : 0;
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
    <EncabezadoSeccion
      :titulo="$t('portal.instructor.calendario.titulo')"
      :subtitulo="
        $t(
          sesion.esCitas
            ? 'portal.instructor.calendario.descripcionCitas'
            : 'portal.instructor.calendario.descripcionClases',
        )
      "
    >
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :disabled="cargando"
          @click="recargar"
        >
          <IconoNav nombre="reloj" :tam="16" />{{
            $t("portal.instructor.inicio.actualizar")
          }}
        </button>
        <RouterLink :to="{ name: 'mi-perfil' }" class="tu-enlace text-sm">{{
          $t("portal.instructor.calendario.sincronizarCorto")
        }}</RouterLink>
      </template>
    </EncabezadoSeccion>

    <div class="mc-filtros tu-card">
      <label class="mc-busqueda">
        <span class="sr-only">{{
          $t("portal.instructor.calendario.buscar")
        }}</span>
        <IconoNav nombre="lista" :tam="18" />
        <input
          v-model="busqueda"
          type="search"
          class="tu-input"
          :placeholder="
            $t(
              sesion.esCitas
                ? 'portal.instructor.calendario.buscarCitas'
                : 'portal.instructor.calendario.buscarClases',
            )
          "
        />
      </label>
      <label v-if="sucursales.length > 1 || sucursal" class="mc-sucursal">
        <span class="sr-only">{{
          $t("portal.instructor.calendario.sucursal")
        }}</span>
        <select v-model="sucursal" class="tu-input">
          <option value="">
            {{ $t("portal.instructor.calendario.todasSucursales") }}
          </option>
          <option
            v-for="s in [...new Set([...sucursales, sucursal].filter(Boolean))]"
            :key="s"
            :value="s"
          >
            {{ s }}
          </option>
        </select>
      </label>
      <button
        v-if="busqueda || sucursal"
        type="button"
        class="tu-enlace text-sm"
        @click="
          busqueda = '';
          sucursal = '';
        "
      >
        {{ $t("portal.instructor.calendario.limpiar") }}
      </button>
    </div>

    <CalendarioVistas
      class="mt-4"
      clave="tu.instructor.vista"
      :vista-inicial="vistaInicial"
      :dias-lista="diasLista"
      :eventos="eventos"
      :cargando="cargando"
      :error="error"
      detallado
      @abrir="abrir"
      @rango="alCambiarRango"
      @reintentar="recargar"
    >
      <template #barra>
        <span class="text-sm" style="color: var(--texto-suave)">{{
          $t("portal.instructor.calendario.rangoLista", { n: diasLista })
        }}</span>
      </template>

      <!-- LISTA: próximos 30 días -->
      <template #lista>
        <div class="mc-lista">
          <h2 class="font-semibold">
            {{
              diasLista === 7
                ? $t("portal.instructor.calendario.proximos7")
                : $t("portal.instructor.calendario.proximas")
            }}
          </h2>
          <template v-if="porDia.length > 0">
            <section v-for="g in porDia" :key="g.dia" class="mc-grupo">
              <div class="mc-fecha" aria-hidden="true">
                <strong>{{ fecha(g.clases[0]!, "day") }}</strong
                ><span>{{ fecha(g.clases[0]!, "month") }}</span>
              </div>
              <div class="mc-jornada">
                <h3 class="mc-dia first-letter:uppercase">{{ g.dia }}</h3>
                <ul class="mc-sesiones">
                  <li v-for="c in g.clases" :key="c.id">
                    <button type="button" class="mc-fila" @click="abrir(c.id)">
                      <span class="mc-hora tabular-nums"
                        >{{ hora(c) }}<small>{{ fin(c) }}</small></span
                      >
                      <span class="min-w-0 flex-1">
                        <span class="block font-medium" :title="titulo(c)">{{
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
                        class="mc-cupo tabular-nums"
                        :style="{
                          color:
                            c.en_espera > 0
                              ? 'var(--aviso)'
                              : 'var(--texto-suave)',
                        }"
                        ><span>{{ cupo(c) }}</span
                        ><span
                          v-if="c.tipo !== 'cita' && c.capacidad"
                          class="mc-ocupacion"
                          aria-hidden="true"
                          ><i :style="{ width: `${ocupacion(c)}%` }"
                        /></span>
                        <small v-if="c.en_espera">{{
                          $t("portal.instructor.inicio.espera", {
                            n: c.en_espera,
                          })
                        }}</small></span
                      >
                      <IconoNav nombre="chevron" :tam="18" class="mc-abrir" />
                    </button>
                  </li>
                </ul>
              </div>
            </section>
          </template>
          <p
            v-else
            class="mc-vacio tu-card"
            :style="{ color: 'var(--texto-suave)' }"
          >
            <IconoNav nombre="agenda" :tam="36" />
            {{
              busqueda || sucursal
                ? $t("portal.instructor.calendario.sinCoincidencias")
                : $t("portal.instructor.calendario.sinClases")
            }}
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
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 12px;
  background: var(--superficie);
  text-align: left;
}
.mc-fila:hover .font-medium {
  color: var(--primario);
}
.mc-dia {
  margin-bottom: 0.6rem;
  font-size: 0.9rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.mc-hora {
  width: 3.4rem;
  flex-shrink: 0;
  font-weight: 500;
}
.mc-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 0.85rem;
  margin-top: 1.25rem;
}
.mc-busqueda {
  flex: 1 1 260px;
  position: relative;
}
.mc-busqueda > svg {
  position: absolute;
  left: 0.8rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--texto-suave);
  pointer-events: none;
}
.mc-busqueda input {
  padding-left: 2.5rem;
}
.mc-sucursal {
  flex: 0 1 230px;
}
.mc-lista > h2 {
  margin-bottom: 1.1rem;
}
.mc-grupo {
  display: grid;
  grid-template-columns: 3.6rem minmax(0, 1fr);
  gap: 1rem;
  margin-top: 1.3rem;
}
.mc-fecha {
  align-self: start;
  text-align: center;
  padding: 0.6rem 0.2rem;
  border-radius: 12px;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.mc-fecha strong {
  display: block;
  font-size: 1.55rem;
  line-height: 1.2;
}
.mc-fecha span {
  font-size: 0.75rem;
  text-transform: uppercase;
}
.mc-sesiones {
  display: grid;
  gap: 0.55rem;
}
.mc-fila:hover {
  border-color: var(--primario);
}
.mc-hora small {
  display: block;
  margin-top: 0.15rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
  font-weight: 400;
}
.mc-cupo {
  display: grid;
  gap: 0.3rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.8rem;
}
.mc-cupo small {
  font-size: 0.7rem;
}
.mc-ocupacion {
  display: block;
  height: 4px;
  width: 72px;
  background: var(--borde);
  border-radius: 3px;
  overflow: hidden;
  margin-left: auto;
}
.mc-ocupacion i {
  display: block;
  height: 100%;
  background: var(--primario);
}
.mc-abrir {
  color: var(--texto-suave);
  flex-shrink: 0;
}
.mc-vacio {
  display: flex;
  gap: 1rem;
  align-items: center;
  padding: 2rem;
  margin-top: 1rem;
}
@media (max-width: 600px) {
  .mc-grupo {
    grid-template-columns: 2.8rem minmax(0, 1fr);
    gap: 0.6rem;
  }
  .mc-fila {
    flex-wrap: wrap;
    gap: 0.6rem;
    padding: 0.75rem;
  }
  .mc-cupo {
    width: 100%;
    padding-left: 4rem;
    text-align: left;
  }
  .mc-ocupacion {
    margin-left: 0;
  }
  .mc-abrir {
    display: none;
  }
  .mc-sucursal {
    flex: 1 1 100%;
  }
}
</style>
