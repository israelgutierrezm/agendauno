<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, type RouteLocationRaw } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelClase from "@/components/PanelClase.vue";
import TarjetaOperacion, {
  type Ilustracion,
} from "@/components/TarjetaOperacion.vue";
import TarjetaPrincipal from "@/components/TarjetaPrincipal.vue";
import { puedeEntrar } from "@/lib/acceso";
import { useRecargarAlVolver } from "@/lib/alVolver";
import { lugarDelClima, useClima } from "@/lib/clima";
import { fotoNegocio } from "@/lib/fotoNegocio";
import { cuandoCorto } from "@/lib/miCuenta";
import {
  fechaEnZona,
  isoLocal,
  useMisClases,
  type ClaseMia,
} from "@/lib/misClases";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Inicio de quien imparte (con el mismo estilo que el del alumno): un saludo, la
 * tarjeta grande con su próxima clase o cita —o la que está en curso— con pase de
 * lista, la foto del giro y el clima de esa sede; luego lo de hoy (con los pases de
 * lista que faltan) y accesos directos con su color. Se actualiza al volver a la
 * pestaña y con «Actualizar»: el reloj avanza, pero las reservas también cambian.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const { base, clases, cargando, error, cargar } = useMisClases();
const abierta = ref<ClaseMia | null>(null);

const nombre = computed(() => {
  const u = sesion.usuario;
  return (u?.nombre_pila ?? u?.nombre ?? "").trim().split(/\s+/)[0] ?? "";
});
const foto = computed(() => fotoNegocio(sesion.estudio?.perfil));
// El pronóstico de su próxima clase o cita en su sede (GET /clima).
const { clima, cargar: cargarClima } = useClima(
  () => `/api/v1/app/${sesion.slug}/clima`,
);

// La hora avanza: la próxima pasa a "en curso" y luego a la siguiente.
const ahora = ref(Date.now());
let reloj: ReturnType<typeof setInterval> | undefined;

// «Hoy» del negocio (su zona horaria) y los siete días que siguen.
const hoy = hoyEnNegocio(sesion.zonaHoraria);
const en7 = (() => {
  const d = new Date(`${hoy}T12:00:00`);
  d.setDate(d.getDate() + 6);
  return isoLocal(d);
})();

const pendientes = computed(() =>
  clases.value.filter((c) => new Date(c.termina_en).getTime() > ahora.value),
);
const proxima = computed(() => pendientes.value[0] ?? null);
const enCurso = computed(
  () =>
    proxima.value !== null &&
    new Date(proxima.value.inicia_en).getTime() <= ahora.value,
);
const deHoy = computed(() =>
  clases.value.filter((c) => fechaEnZona(c.inicia_en, c.zona_horaria) === hoy),
);
const alumnosHoy = computed(() =>
  deHoy.value.reduce((n, c) => n + c.ocupados, 0),
);
const pendientesHoy = computed(() => deHoy.value.filter(faltaLista).length);
const climaLugar = computed(() =>
  lugarDelClima(clima.value, t, proxima.value?.tipo),
);
// Por lo que es: en curso, su próxima cita o clase; sin nada, su agenda.
const etiqueta = computed(() => {
  if (enCurso.value) {
    return t("portal.instructor.inicio.enCurso");
  }
  if (!proxima.value) {
    return t("portal.instructor.inicio.proximaGeneral");
  }
  return proxima.value.tipo === "cita"
    ? t("portal.instructor.inicio.proximaCita")
    : t("portal.instructor.inicio.proxima");
});

// Un color por acceso, de la misma paleta de la agenda (como el Inicio del alumno).
const TONOS: Record<string, string> = {
  hoy: "azul",
  alumnos: "morado",
  semana: "verde",
  calendario: "cielo",
  agenda: "naranja",
  perfil: "rosa",
};
// Su imagen, si se agregó (Hoy y la agenda del negocio reutilizan las del negocio).
const IMAGENES = computed<Record<string, string[]>>(() => ({
  hoy: ["acceso-hoy", `acceso-agenda-${sesion.modalidad}`, "acceso-agenda"],
  alumnos: ["acceso-alumnos"],
  semana: ["acceso-semana"],
  calendario: ["acceso-calendario"],
  agenda: ["acceso-agenda-negocio", "acceso-horarios"],
  perfil: ["acceso-perfil"],
}));
const ILUSTRACIONES: Record<string, Ilustracion> = {
  hoy: "agenda",
  alumnos: "alumnos",
  semana: "semana",
  calendario: "calendario",
  agenda: "horarios",
  perfil: "perfil",
};

interface Acceso {
  clave: string;
  titulo: string;
  valor: string;
  icono: string;
  to: RouteLocationRaw;
}
const accesos = computed<Acceso[]>(() => {
  const n = (clave: string, cuantos: number) =>
    t(`portal.instructor.inicio.tarjetas.${clave}`, { n: cuantos }, cuantos);
  return [
    {
      clave: "hoy",
      titulo: t("portal.instructor.inicio.tarjetas.hoy"),
      valor: n("hoyValor", deHoy.value.length),
      icono: "agenda",
      to: { name: "mis-clases", query: { vista: "dia" } },
    },
    {
      clave: "alumnos",
      titulo: t("portal.instructor.inicio.tarjetas.alumnos"),
      valor: n("alumnosValor", alumnosHoy.value),
      icono: "miembros",
      to: { name: "mis-clases", query: { vista: "dia" } },
    },
    {
      clave: "semana",
      titulo: t("portal.instructor.inicio.tarjetas.semana"),
      valor: n("semanaValor", clases.value.length),
      icono: "lista",
      // Los próximos 7 días, no la lista de un mes.
      to: { name: "mis-clases", query: { vista: "lista", dias: "7" } },
    },
    {
      clave: "calendario",
      titulo: t("portal.instructor.inicio.tarjetas.calendario"),
      valor: t("portal.instructor.inicio.tarjetas.calendarioValor"),
      icono: "cuadricula",
      to: { name: "mis-clases", query: { vista: "mes" } },
    },
    ...(sesion.puede("agenda.ver")
      ? [
          {
            clave: "agenda",
            titulo: t("portal.instructor.inicio.tarjetas.agenda"),
            valor: t(
              sesion.esCitas
                ? "portal.instructor.inicio.tarjetas.agendaCitasValor"
                : "portal.instructor.inicio.tarjetas.agendaValor",
            ),
            icono: "reloj",
            to: { name: "agenda" },
          },
        ]
      : []),
    {
      clave: "perfil",
      titulo: t("portal.instructor.inicio.tarjetas.perfil"),
      valor: t("portal.instructor.inicio.tarjetas.perfilValor"),
      icono: "mi-cuenta",
      to: { name: "mi-perfil" },
    },
  ];
});

function detalle(c: ClaseMia): string {
  if (c.tipo === "cita") {
    return c.cita?.cliente
      ? t("portal.instructor.inicio.con", { nombre: c.cita.cliente })
      : "";
  }
  const partes = [
    c.capacidad !== null
      ? t("portal.instructor.inicio.cupo", {
          ocupados: c.ocupados,
          capacidad: c.capacidad,
        })
      : t("portal.instructor.inicio.inscritos", { n: c.ocupados }, c.ocupados),
  ];
  if (c.en_espera > 0) {
    partes.push(t("portal.instructor.inicio.espera", { n: c.en_espera }));
  }
  return partes.join(" · ");
}

async function recargar(): Promise<void> {
  await cargar(hoy, en7);
  // Si el panel sigue abierto, que muestre lo nuevo.
  if (abierta.value) {
    abierta.value =
      clases.value.find((c) => c.id === abierta.value?.id) ?? null;
  }
}

// Una clase abre directo su pase de lista (si su rol puede pasarla); una cita, o
// quien no puede pasar lista, su detalle.
const listaDirecta = computed(() => puedeEntrar("pase-lista", sesion));
function paseDeLista(c: ClaseMia): RouteLocationRaw | null {
  return c.tipo !== "cita" && listaDirecta.value
    ? { name: "pase-lista", params: { id: c.id } }
    : null;
}

// Lo de hoy, en orden: lo que ya empezó y no tiene lista completa la pide.
function faltaLista(c: ClaseMia): boolean {
  if (new Date(c.inicia_en).getTime() > ahora.value) {
    return false;
  }
  if (c.tipo === "cita") {
    return !c.cita?.asistencia;
  }
  return c.ocupados > 0 && (c.marcadas ?? 0) < c.ocupados;
}
function horaDe(c: ClaseMia): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: c.zona_horaria,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(c.inicia_en));
}
// En citas, a quién atiende; en clases, la actividad y el cupo.
function lineaHoy(c: ClaseMia): { titulo: string; detalle: string } {
  if (c.tipo === "cita") {
    return {
      titulo: c.cita?.asiste || c.cita?.cliente || (c.oferta ?? "—"),
      detalle: [c.oferta, c.sala].filter(Boolean).join(" · "),
    };
  }
  return {
    titulo: c.oferta ?? "—",
    detalle: [
      c.capacidad !== null
        ? t("portal.instructor.inicio.cupo", {
            ocupados: c.ocupados,
            capacidad: c.capacidad,
          })
        : t(
            "portal.instructor.inicio.inscritos",
            { n: c.ocupados },
            c.ocupados,
          ),
      c.sala,
    ]
      .filter(Boolean)
      .join(" · "),
  };
}
const actualizando = ref(false);
async function actualizar(): Promise<void> {
  actualizando.value = true;
  ahora.value = Date.now();
  await recargar();
  actualizando.value = false;
}
useRecargarAlVolver(actualizar);

onMounted(() => {
  void recargar();
  void cargarClima();
  reloj = setInterval(() => (ahora.value = Date.now()), 60_000);
});
onUnmounted(() => clearInterval(reloj));
</script>

<template>
  <section class="tu-pagina">
    <h1 class="text-2xl font-semibold">
      {{
        nombre
          ? $t("portal.inicio.saludo", { nombre })
          : $t("portal.inicio.saludoSinNombre")
      }}
    </h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{
        $t(
          sesion.esCitas
            ? "portal.instructor.inicio.resumenCitas"
            : "portal.instructor.inicio.resumenClases",
          {
            estudio: sesion.estudio?.nombre ?? "",
          },
        )
      }}
    </p>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
      <button type="button" class="tu-enlace ml-2" @click="actualizar">
        {{ $t("comun.reintentar") }}
      </button>
    </p>
    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="!error || clases.length > 0">
      <div class="pi-resumen">
        <RouterLink
          v-for="a in accesos.slice(0, 3)"
          :key="a.clave"
          :to="a.to"
          class="pi-metrica tu-card"
        >
          <span class="pi-metrica-icono"
            ><IconoNav :nombre="a.icono" :tam="22"
          /></span>
          <span
            ><small>{{ a.titulo }}</small
            ><strong>{{ a.valor }}</strong></span
          >
        </RouterLink>
        <a
          href="#mi-dia"
          class="pi-metrica tu-card"
          :class="{ 'pi-metrica-pendiente': pendientesHoy > 0 }"
          :title="$t('portal.instructor.inicio.pendientesAyuda')"
        >
          <span class="pi-metrica-icono"
            ><IconoNav :nombre="pendientesHoy ? 'reloj' : 'hecho'" :tam="22"
          /></span>
          <span
            ><small>{{ $t("portal.instructor.inicio.pendientesHoy") }}</small
            ><strong>{{
              pendientesHoy
                ? $t(
                    "portal.instructor.inicio.pendientesValor",
                    { n: pendientesHoy },
                    pendientesHoy,
                  )
                : $t("portal.instructor.inicio.alDia")
            }}</strong></span
          >
        </a>
      </div>
      <!-- La tarjeta principal: siempre, con o sin algo agendado -->
      <TarjetaPrincipal
        class="mt-5"
        compacta
        :etiqueta="etiqueta"
        :etiqueta-viva="enCurso"
        :foto="foto"
        :clima="clima"
        :clima-lugar="climaLugar"
      >
        <template v-if="proxima">
          <p class="mt-2 text-2xl font-semibold sm:text-3xl">
            {{
              proxima.tipo === "cita"
                ? lineaHoy(proxima).titulo
                : (proxima.oferta ?? "—")
            }}
          </p>
          <p class="mt-2 first-letter:uppercase">
            {{ cuandoCorto(proxima.inicia_en, proxima.zona_horaria) }}
            <span
              v-if="proxima.sucursal || proxima.sala"
              :style="{ color: 'var(--texto-suave)' }"
            >
              ·
              {{ [proxima.sucursal, proxima.sala].filter(Boolean).join(" · ") }}
            </span>
          </p>
          <p
            v-if="detalle(proxima)"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ proxima.tipo === "cita" ? proxima.oferta : detalle(proxima) }}
          </p>
          <div class="mt-6 flex flex-wrap items-center gap-3">
            <RouterLink
              v-if="paseDeLista(proxima)"
              :to="paseDeLista(proxima)!"
              class="tu-btn tu-btn-primario"
              data-prueba="pasar-lista"
            >
              {{ $t("portal.instructor.inicio.pasarLista") }}
            </RouterLink>
            <button
              v-else
              type="button"
              class="tu-btn tu-btn-primario"
              @click="abierta = proxima"
            >
              {{
                proxima.tipo === "cita"
                  ? $t("portal.instructor.inicio.verCita")
                  : $t("portal.instructor.inicio.pasarLista")
              }}
            </button>
            <AgregarCalendario
              :evento="{
                uid: `sesion-${proxima.id}`,
                titulo: proxima.oferta ?? sesion.estudio?.nombre ?? '',
                inicio: proxima.inicia_en,
                fin: proxima.termina_en,
                lugar: [sesion.estudio?.nombre, proxima.sucursal]
                  .filter(Boolean)
                  .join(' · '),
              }"
            />
            <RouterLink :to="{ name: 'mis-clases' }" class="tu-enlace text-sm">
              {{ $t("portal.instructor.inicio.verCalendario") }}
            </RouterLink>
          </div>
        </template>
        <template v-else>
          <p class="mt-2 text-2xl font-semibold sm:text-3xl">
            {{ $t("portal.inicio.sinReservas") }}
          </p>
          <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("portal.instructor.inicio.sinProxima") }}
          </p>
          <div class="mt-6">
            <RouterLink
              :to="{ name: 'mis-clases' }"
              class="tu-btn tu-btn-primario inline-flex"
            >
              {{ $t("portal.instructor.inicio.tarjetas.calendario") }}
            </RouterLink>
          </div>
        </template>
      </TarjetaPrincipal>

      <!-- Hoy: sus clases o citas, con los pases de lista que faltan -->
      <section
        id="mi-dia"
        class="mt-5 tu-card overflow-hidden"
        data-prueba="hoy-instructor"
      >
        <header class="pi-hoy-cabecera">
          <h2 class="font-medium">
            <IconoNav nombre="agenda" :tam="20" class="inline-block mr-2" />
            {{ $t("portal.instructor.inicio.tarjetas.hoy") }}
          </h2>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            :disabled="actualizando"
            data-prueba="actualizar"
            @click="actualizar"
          >
            {{ $t("portal.instructor.inicio.actualizar") }}
          </button>
        </header>
        <ul v-if="deHoy.length > 0">
          <li v-for="c in deHoy" :key="c.id">
            <component
              :is="paseDeLista(c) ? RouterLink : 'button'"
              v-bind="
                paseDeLista(c) ? { to: paseDeLista(c) } : { type: 'button' }
              "
              class="pi-hoy-fila"
              @click="paseDeLista(c) ? undefined : (abierta = c)"
            >
              <span class="pi-hoy-hora tabular-nums">{{ horaDe(c) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ lineaHoy(c).titulo }}</span>
                <span
                  class="block truncate text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ lineaHoy(c).detalle }}</span
                >
              </span>
              <span
                v-if="faltaLista(c)"
                class="tu-badge tu-badge-aviso shrink-0 text-xs"
                >{{
                  c.tipo === "cita"
                    ? $t("portal.instructor.inicio.faltaLlegada")
                    : $t("portal.instructor.inicio.faltaLista")
                }}</span
              >
              <IconoNav nombre="chevron" :tam="18" class="pi-hoy-abrir" />
            </component>
          </li>
        </ul>
        <p
          v-else
          class="px-5 pb-5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("portal.instructor.inicio.sinHoy") }}
        </p>
        <footer v-if="deHoy.length" class="pi-hoy-pie">
          <RouterLink
            :to="{ name: 'mis-clases', query: { vista: 'dia' } }"
            class="tu-enlace text-sm"
            >{{ $t("portal.instructor.inicio.diaCompleto") }} →</RouterLink
          >
        </footer>
      </section>

      <!-- Accesos directos -->
      <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="a in accesos.slice(3)" :key="a.clave">
          <TarjetaOperacion
            :to="a.to"
            :icono="a.icono"
            :titulo="a.titulo"
            :texto="a.valor"
            :tono="TONOS[a.clave] ?? 'azul'"
            :ilustracion="ILUSTRACIONES[a.clave] ?? 'agenda'"
            :imagen="IMAGENES[a.clave]"
          />
        </li>
      </ul>
    </template>

    <!-- Pase de lista de la clase / detalle de la cita -->
    <PanelClase
      v-if="abierta && abierta.tipo !== 'cita'"
      :sesion="abierta"
      @cerrar="abierta = null"
      @cambio="recargar"
    />
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
    />
  </section>
</template>

<style scoped>
/* Lo de hoy: la hora, quién o qué, y si falta pasar lista. */
.pi-hoy-cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem 0.75rem;
}
.pi-hoy-fila {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.9rem;
  padding: 0.75rem 1.25rem;
  border-top: 1px solid var(--borde);
  text-align: left;
}
.pi-hoy-fila:hover .font-medium {
  color: var(--primario);
}
.pi-hoy-hora {
  width: 3.2rem;
  flex-shrink: 0;
  font-weight: 500;
}
.pi-resumen {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.85rem;
  margin-top: 1.5rem;
}
.pi-metrica {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 1rem;
  min-width: 0;
  text-decoration: none;
}
.pi-metrica:hover {
  border-color: var(--primario);
}
.pi-metrica-icono {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.pi-metrica small {
  display: block;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.pi-metrica strong {
  display: block;
  margin-top: 0.2rem;
  font-size: 1.1rem;
}
.pi-metrica-pendiente .pi-metrica-icono {
  background: color-mix(in srgb, var(--aviso) 12%, var(--superficie));
  color: var(--aviso);
}
.pi-hoy-fila:hover {
  background: var(--superficie-2);
}
.pi-hoy-abrir {
  flex-shrink: 0;
  color: var(--texto-suave);
}
.pi-hoy-pie {
  border-top: 1px solid var(--borde);
  padding: 0.85rem 1.25rem;
}
#mi-dia {
  scroll-margin-top: 6rem;
}
@media (max-width: 1100px) {
  .pi-resumen {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .pi-metrica {
    gap: 0.5rem;
    padding: 0.8rem;
  }
  .pi-metrica-icono {
    width: 30px;
    height: 30px;
  }
  .pi-metrica strong {
    font-size: 0.95rem;
  }
  .pi-hoy-fila {
    flex-wrap: wrap;
    gap: 0.6rem;
  }
  .pi-hoy-fila .tu-badge {
    margin-left: 4.1rem;
    white-space: normal;
  }
  .pi-hoy-abrir {
    display: none;
  }
}
</style>
