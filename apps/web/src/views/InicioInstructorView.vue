<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, type RouteLocationRaw } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelClase from "@/components/PanelClase.vue";
import TarjetaOperacion, {
  type Ilustracion,
} from "@/components/TarjetaOperacion.vue";
import TarjetaPrincipal from "@/components/TarjetaPrincipal.vue";
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

const hoy = isoLocal(new Date());
const en7 = (() => {
  const d = new Date();
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
            valor: t("portal.instructor.inicio.tarjetas.agendaValor"),
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
        $t("portal.instructor.inicio.resumen", {
          estudio: sesion.estudio?.nombre ?? "",
        })
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

    <template v-else>
      <!-- La tarjeta principal: siempre, con o sin algo agendado -->
      <TarjetaPrincipal
        class="mt-6"
        :etiqueta="etiqueta"
        :etiqueta-viva="enCurso"
        :foto="foto"
        :clima="clima"
        :clima-lugar="climaLugar"
      >
        <template v-if="proxima">
          <p class="mt-2 text-2xl font-semibold sm:text-3xl">
            {{ proxima.oferta ?? "—" }}
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
            {{ detalle(proxima) }}
          </p>
          <div class="mt-6 flex flex-wrap items-center gap-3">
            <button
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
        class="mt-5 tu-card overflow-hidden"
        data-prueba="hoy-instructor"
      >
        <header class="pi-hoy-cabecera">
          <h2 class="font-medium">
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
            <button type="button" class="pi-hoy-fila" @click="abierta = c">
              <span class="pi-hoy-hora tabular-nums">{{ horaDe(c) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{
                  lineaHoy(c).titulo
                }}</span>
                <span
                  class="block truncate text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ lineaHoy(c).detalle }}</span
                >
              </span>
              <span
                v-if="faltaLista(c)"
                class="tu-pildora shrink-0 text-xs"
                :style="{ '--tono': 'var(--aviso)' }"
                >{{
                  c.tipo === "cita"
                    ? $t("portal.instructor.inicio.faltaLlegada")
                    : $t("portal.instructor.inicio.faltaLista")
                }}</span
              >
            </button>
          </li>
        </ul>
        <p
          v-else
          class="px-5 pb-5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("portal.instructor.inicio.sinHoy") }}
        </p>
      </section>

      <!-- Accesos directos -->
      <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="a in accesos" :key="a.clave">
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
</style>
