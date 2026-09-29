<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { useRetornoPago } from "@/lib/retornoPago";
import { recordarNegocio } from "@/lib/negociosRecientes";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import IconoNav from "@/components/IconoNav.vue";
import ServicioIncluye from "@/components/ServicioIncluye.vue";

interface Servicio {
  id: string;
  nombre: string;
  // Qué incluye y en qué grupo va (catálogo del negocio).
  descripcion?: string | null;
  categoria?: string | null;
  // Paquete: qué incluye y cuánto costaría por separado.
  incluye?: string[];
  precio_por_separado_minor?: number | null;
  precio_minor: number | null;
  moneda: string;
  duracion_minutos: number | null;
}
interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string | null;
  region?: string | null;
  // Para reconocerla (foto, dirección) y llegar a la correcta.
  direccion?: string | null;
  foto_url?: string | null;
  mapa_url?: string | null;
}
interface Persona {
  id: string;
  nombre: string;
  foto_url?: string | null;
}
interface Opciones {
  estudio: { slug: string; nombre: string; logo_url: string | null };
  servicios: Servicio[];
  sucursales: Sucursal[];
  instructores: Persona[];
}
interface Slot {
  inicia: string;
  termina: string;
  // Con los horarios de todo el equipo: quiénes están libres en ese hueco.
  profesionales?: string[];
}
// Sin preferencia: el negocio asigna a quien esté libre a esa hora.
const CUALQUIERA = "cualquiera";
// Pasos del asistente; la sucursal solo se pregunta si hay más de una.
type Paso = "sucursal" | "servicio" | "horario" | "confirmar";

const route = useRoute();
// Al volver de la página de pago: avisa cómo quedó.
const retornoPago = useRetornoPago();
const slug = computed(() => String(route.params.slug));

const opciones = ref<Opciones | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);
const error = ref<string | null>(null);

// Selección del asistente.
const paso = ref<Paso>("sucursal");
const servicioId = ref("");
// Servicios agrupados por su categoría (la actividad del catálogo).
const gruposServicios = computed(() => {
  const grupos = new Map<string, Servicio[]>();
  for (const s of opciones.value?.servicios ?? []) {
    const clave = s.categoria ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), s]);
  }
  return [...grupos.entries()].map(([nombre, lista]) => ({ nombre, lista }));
});
const sucursalId = ref("");
// Primero la hora: se ven los horarios de todo el equipo ("") o, si el cliente ya
// tiene a alguien de preferencia, solo los de esa persona.
const filtro = ref("");
// Con quién: CUALQUIERA (lo asigna el negocio) o un profesional libre a esa hora.
const barberoId = ref("");
const fecha = ref("");
const slotSel = ref<string>("");
const datos = ref({ nombre: "", celular: "", email: "" });

const slots = ref<Slot[]>([]);
const buscandoSlots = ref(false);
const slotsCargados = ref(false);

const agendando = ref(false);
const pagando = ref(false);
// Resultado de agendar (cita creada, pendiente de pago).
const resultado = ref<{
  orden_id: string | null;
  total_minor: number | null;
  moneda: string | null;
  // Quién atenderá (el elegido o el que asignó el negocio).
  profesional?: { id: string; nombre: string } | null;
} | null>(null);
const pendientePago = ref(false);

const servicioSel = computed(
  () =>
    opciones.value?.servicios.find((s) => s.id === servicioId.value) ?? null,
);
const sucursalSel = computed(
  () =>
    opciones.value?.sucursales.find((s) => s.id === sucursalId.value) ?? null,
);
const barberoSel = computed(
  () =>
    opciones.value?.instructores.find((b) => b.id === barberoId.value) ?? null,
);
const variasSedes = computed(
  () => (opciones.value?.sucursales.length ?? 0) > 1,
);
// Si ninguna sede tiene foto, las tarjetas van compactas (sin hueco de imagen).
const sedesConFoto = computed(() =>
  (opciones.value?.sucursales ?? []).some((s) => s.foto_url),
);
const variosProfesionales = computed(
  () => (opciones.value?.instructores.length ?? 0) > 1,
);
// Tras elegir la hora se elige con quién, salvo que ya se filtró por alguien.
const eligeConQuien = computed(
  () => variosProfesionales.value && filtro.value === "",
);
const slotActual = computed(
  () => slots.value.find((s) => s.inicia === slotSel.value) ?? null,
);
// Quienes están libres a la hora elegida.
const libresEnHora = computed(() => {
  const ids = slotActual.value?.profesionales ?? [];
  return (opciones.value?.instructores ?? []).filter((b) => ids.includes(b.id));
});
const zona = computed(
  () => sucursalSel.value?.zona_horaria ?? "America/Mexico_City",
);
// Duración del servicio; respaldo de 60 min si el servicio no la definió.
const duracion = computed(() => servicioSel.value?.duracion_minutos ?? 60);

// Mapa de pasos: los que ya pasaron se pueden volver a abrir.
const pasos = computed<Paso[]>(() =>
  variasSedes.value
    ? ["sucursal", "servicio", "horario", "confirmar"]
    : ["servicio", "horario", "confirmar"],
);
const indicePaso = computed(() => pasos.value.indexOf(paso.value));
const pasoTitulo = ref<HTMLElement | null>(null);
function ir(p: Paso): void {
  paso.value = p;
  error.value = null;
  // Al cambiar de paso, arriba (el título del paso recibe el foco).
  pasoTitulo.value?.scrollIntoView?.({ block: "start", behavior: "smooth" });
}
function volverA(p: Paso): void {
  if (pasos.value.indexOf(p) < indicePaso.value) ir(p);
}
const puedeContinuar = computed(
  () => slotSel.value !== "" && barberoId.value !== "",
);

const listoParaAgendar = computed(
  () =>
    servicioId.value !== "" &&
    sucursalId.value !== "" &&
    barberoId.value !== "" &&
    slotSel.value !== "" &&
    datos.value.nombre.trim() !== "",
);

function dinero(minor: number | null, moneda: string | null): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda ?? "MXN",
  }).format((minor ?? 0) / 100);
}
function horaLocal(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona.value,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
// "Jueves, 1 de octubre" en la zona de la sede.
function diaLocal(iso: string): string {
  const texto = new Intl.DateTimeFormat("es-MX", {
    timeZone: zona.value,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(iso));
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}
// Reloj de pared local de la sucursal ("YYYY-MM-DD HH:MM:SS"), como lo espera el backend.
function relojLocal(iso: string): string {
  const d = new Date(iso);
  const f = new Intl.DateTimeFormat("en-CA", {
    timeZone: zona.value,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(d);
  const h = new Intl.DateTimeFormat("en-GB", {
    timeZone: zona.value,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(d);
  return `${f} ${h}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: Opciones }>(
      `/api/v1/app/${slug.value}/citas/opciones`,
    );
    opciones.value = data.data;
    recordarNegocio({
      slug: data.data.estudio.slug,
      nombre: data.data.estudio.nombre,
      logo_url: data.data.estudio.logo_url,
      ciudad: null,
      pais: null,
    });
    // Sede pre-seleccionada desde el selector de sucursal (?sucursal=<ulid>), o
    // la única si solo hay una: entonces se empieza por el servicio.
    const preSuc = String(route.query.sucursal ?? "");
    if (preSuc !== "" && data.data.sucursales.some((s) => s.id === preSuc)) {
      sucursalId.value = preSuc;
    } else if (data.data.sucursales.length === 1) {
      sucursalId.value = data.data.sucursales[0].id;
    }
    paso.value = sucursalId.value !== "" ? "servicio" : "sucursal";
  } catch {
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

let busquedaActual = 0;
async function buscarSlots(): Promise<void> {
  const consulta = ++busquedaActual;
  slotSel.value = "";
  slots.value = [];
  slotsCargados.value = false;
  buscandoSlots.value = false;
  if (sucursalId.value === "" || fecha.value === "") {
    return;
  }
  buscandoSlots.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { slots: Slot[] } }>(
      `/api/v1/app/${slug.value}/citas/disponibilidad`,
      {
        params: {
          // Sin filtro, los horarios de todo el equipo.
          ...(filtro.value !== "" ? { instructor_id: filtro.value } : {}),
          sucursal_id: sucursalId.value,
          fecha: fecha.value,
          duracion_minutos: duracion.value,
          // Con el servicio cuentan sus márgenes y la sala o equipo que requiere.
          ...(servicioId.value !== "" ? { oferta_id: servicioId.value } : {}),
        },
      },
    );
    if (consulta !== busquedaActual) return;
    slots.value = data.data.slots;
    slotsCargados.value = true;
  } catch (e) {
    if (consulta === busquedaActual) error.value = mensajeDeError(e);
  } finally {
    if (consulta === busquedaActual) buscandoSlots.value = false;
  }
}

// Con quién de partida: el del filtro, el único profesional o «cualquiera».
function profesionalDePartida(): string {
  const lista = opciones.value?.instructores ?? [];
  if (filtro.value !== "") return filtro.value;
  return lista.length === 1 ? lista[0].id : CUALQUIERA;
}
watch(sucursalId, () => {
  filtro.value = "";
  barberoId.value = profesionalDePartida();
  fecha.value = "";
});
watch(filtro, () => {
  barberoId.value = profesionalDePartida();
});
// Al cambiar de hora, quien se eligió sigue solo si está libre a la nueva hora.
watch(slotSel, () => {
  if (
    slotSel.value !== "" &&
    eligeConQuien.value &&
    barberoId.value !== CUALQUIERA &&
    !libresEnHora.value.some((b) => b.id === barberoId.value)
  ) {
    barberoId.value = CUALQUIERA;
  }
});
// Recalcula huecos al cambiar filtro, sucursal, fecha o servicio (por su duración).
watch([filtro, sucursalId, fecha, servicioId], buscarSlots);

// Elegir sede o servicio lleva al paso siguiente (también si se vuelve a tocar la
// que ya estaba elegida).
function elegirSede(id: string): void {
  sucursalId.value = id;
  ir("servicio");
}
function elegirServicio(id: string): void {
  servicioId.value = id;
  ir("horario");
}

async function agendar(): Promise<void> {
  if (!listoParaAgendar.value) {
    error.value = null;
    return;
  }
  agendando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: {
        orden_id: string | null;
        total_minor: number | null;
        moneda: string | null;
        profesional?: { id: string; nombre: string } | null;
      };
    }>(`/api/v1/app/${slug.value}/citas`, {
      nombre: datos.value.nombre.trim(),
      celular:
        datos.value.celular.trim() !== "" ? datos.value.celular.trim() : null,
      email: datos.value.email.trim() !== "" ? datos.value.email.trim() : null,
      oferta_id: servicioId.value,
      sucursal_id: sucursalId.value,
      ...(barberoId.value !== CUALQUIERA
        ? { instructor_id: barberoId.value }
        : {}),
      inicia_en_local: relojLocal(slotSel.value),
      duracion_minutos: duracion.value,
    });
    resultado.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    agendando.value = false;
  }
}

async function pagar(): Promise<void> {
  if (resultado.value?.orden_id == null) {
    return;
  }
  pagando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout?: { tipo?: string; url?: string } | null };
    }>(`/api/v1/app/${slug.value}/citas/pagar`, {
      // Sin proveedor: el API usa la pasarela en línea con la que cobra el negocio.
      orden_id: resultado.value.orden_id,
      metodo: "tarjeta",
    });
    const checkout = data.data.checkout ?? {};
    // Pasarela de redirección (p. ej. Mercado Pago): al checkout externo.
    if (
      checkout.tipo === "redirect" &&
      typeof checkout.url === "string" &&
      checkout.url !== ""
    ) {
      window.location.href = checkout.url;
      return;
    }
    // Otras pasarelas (p. ej. Stripe con tarjeta): el lugar queda apartado.
    pendientePago.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pagando.value = false;
  }
}

// Otra cita: misma sede y servicio, se vuelve a elegir el horario.
function otra(): void {
  resultado.value = null;
  pendientePago.value = false;
  slotSel.value = "";
  fecha.value = "";
  slots.value = [];
  slotsCargados.value = false;
  datos.value = { nombre: "", celular: "", email: "" };
  ir("horario");
}

function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join("")
    .toUpperCase();
}

onMounted(cargar);
</script>

<template>
  <div class="min-h-screen" :style="{ background: 'var(--fondo)' }">
    <p
      v-if="cargando"
      class="text-center py-20"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("reservar.cargando") }}
    </p>

    <section
      v-else-if="noDisponible"
      class="mx-auto max-w-md px-4 py-20 text-center"
    >
      <p class="text-lg font-semibold">{{ $t("reservar.noDisponible") }}</p>
      <RouterLink
        :to="{ name: 'directorio' }"
        class="tu-enlace mt-3 inline-block"
        >{{ $t("reservar.volverDirectorio") }}</RouterLink
      >
    </section>

    <section v-else-if="opciones" class="mx-auto max-w-2xl px-4 py-10">
      <p
        v-if="retornoPago"
        class="mb-4 rounded-xl p-3 text-sm"
        role="status"
        :style="{
          background:
            retornoPago === 'exito'
              ? 'var(--exito-suave)'
              : 'var(--superficie-2)',
          color:
            retornoPago === 'exito' ? 'var(--exito-texto)' : 'var(--texto)',
        }"
      >
        {{
          retornoPago === "exito"
            ? $t("pagoEnLinea.citaExito")
            : $t("pagoEnLinea.cancelado")
        }}
      </p>
      <!-- Encabezado con marca del estudio -->
      <header class="flex items-center gap-3">
        <img
          v-if="opciones.estudio.logo_url"
          :src="opciones.estudio.logo_url"
          :alt="opciones.estudio.nombre"
          class="h-12 w-12 rounded-xl object-cover"
        />
        <span
          v-else
          class="h-12 w-12 rounded-xl inline-flex items-center justify-center font-bold"
          :style="{
            background: 'var(--primario)',
            color: 'var(--primario-contraste)',
          }"
          >{{ iniciales(opciones.estudio.nombre) }}</span
        >
        <div>
          <h1 class="text-xl font-light">{{ opciones.estudio.nombre }}</h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("reservar.titulo") }}
          </p>
        </div>
      </header>

      <!-- Sin servicios de cita -->
      <p
        v-if="opciones.servicios.length === 0"
        class="mt-10 tu-card p-6 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reservar.sinServicios") }}
      </p>

      <!-- ===== Confirmación ===== -->
      <div
        v-else-if="resultado"
        class="mt-8 tu-card p-6 text-center"
        role="status"
        aria-live="polite"
      >
        <div
          class="mx-auto h-12 w-12 rounded-full inline-flex items-center justify-center text-white"
          :style="{ background: 'var(--exito)' }"
        >
          <IconoNav nombre="hecho" :tam="24" />
        </div>
        <h2 class="mt-3 text-2xl font-semibold text-success">
          {{ $t("reservar.listoTitulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("reservar.listoResumen", {
              servicio: servicioSel?.nombre ?? "",
              barbero:
                resultado.profesional?.nombre ?? barberoSel?.nombre ?? "",
            })
          }}
        </p>
        <p class="mt-1 font-medium">
          {{ diaLocal(slotSel) }} · {{ horaLocal(slotSel) }} ·
          {{ dinero(resultado.total_minor, resultado.moneda) }}
        </p>
        <!-- Dónde: para no llegar a otra sede. -->
        <p
          v-if="sucursalSel"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="donde-listo"
        >
          {{ sucursalSel.nombre
          }}<template v-if="sucursalSel.direccion">
            · {{ sucursalSel.direccion }}</template
          >
          <a
            v-if="sucursalSel.mapa_url"
            :href="sucursalSel.mapa_url"
            target="_blank"
            rel="noopener"
            class="tu-enlace ml-1"
            >{{ $t("perfilPublico.agendar.comoLlegar") }}</a
          >
        </p>

        <template v-if="pendientePago">
          <p
            class="mt-4 text-sm rounded-lg p-3"
            :style="{
              background: 'var(--primario-suave)',
              color: 'var(--primario-fuerte)',
            }"
          >
            {{ $t("reservar.pendientePago") }}
          </p>
        </template>
        <template v-else>
          <p class="mt-4 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("reservar.apartado") }}
          </p>
          <button
            class="tu-btn tu-btn-primario mt-3 w-full"
            type="button"
            :disabled="pagando"
            @click="pagar"
          >
            {{
              pagando
                ? $t("reservar.pagando")
                : `${$t("reservar.pagar")} · ${dinero(resultado.total_minor, resultado.moneda)}`
            }}
          </button>
        </template>

        <button class="tu-enlace mt-4 text-sm" type="button" @click="otra">
          {{ $t("reservar.otra") }}
        </button>
        <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
      </div>

      <!-- ===== Asistente por pasos ===== -->
      <div v-else class="mt-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reservar.intro") }}
        </p>

        <!-- Mapa de pasos: hechos (se pueden reabrir), actual y pendientes. -->
        <ol
          class="rc-pasos mt-5"
          :aria-label="$t('perfilPublico.agendar.pasosEtiqueta')"
          data-prueba="pasos"
        >
          <li
            v-for="(p, i) in pasos"
            :key="p"
            class="rc-paso"
            :class="{
              'rc-paso--hecho': i < indicePaso,
              'rc-paso--actual': i === indicePaso,
            }"
            :aria-current="i === indicePaso ? 'step' : undefined"
          >
            <button
              v-if="i < indicePaso"
              type="button"
              class="rc-paso-marca"
              :data-paso="p"
              @click="volverA(p)"
            >
              <span class="rc-paso-num"
                ><IconoNav nombre="hecho" :tam="14"
              /></span>
              <span class="rc-paso-texto">{{
                $t(`perfilPublico.agendar.pasos.${p}`)
              }}</span>
            </button>
            <span v-else class="rc-paso-marca" :data-paso="p">
              <span class="rc-paso-num">{{ i + 1 }}</span>
              <span class="rc-paso-texto">{{
                $t(`perfilPublico.agendar.pasos.${p}`)
              }}</span>
            </span>
          </li>
        </ol>

        <div ref="pasoTitulo" class="mt-5 space-y-4 scroll-mt-4">
          <!-- Paso: sucursal (con foto si la tiene) -->
          <fieldset
            v-if="paso === 'sucursal'"
            class="tu-card p-5 reserva-opciones"
          >
            <legend class="tu-label">{{ $t("sucursalesPub.titulo") }}</legend>
            <p class="reserva-ayuda">{{ $t("sucursalesPub.subtitulo") }}</p>
            <div class="reserva-tarjetas">
              <label
                v-for="s in opciones.sucursales"
                :key="s.id"
                class="rc-sede"
                :class="{
                  'rc-sede--activa': sucursalId === s.id,
                  'rc-sede--compacta': !sedesConFoto,
                }"
              >
                <input
                  :checked="sucursalId === s.id"
                  type="radio"
                  name="sucursal"
                  :value="s.id"
                  class="sr-only"
                  @change="elegirSede(s.id)"
                  @click="sucursalId === s.id && elegirSede(s.id)"
                />
                <img
                  v-if="s.foto_url"
                  :src="s.foto_url"
                  alt=""
                  class="rc-sede-foto"
                />
                <span v-else class="rc-sede-foto rc-sede-sinfoto">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" />
                    <circle cx="12" cy="10" r="2.5" />
                  </svg>
                </span>
                <span class="rc-sede-texto">
                  <strong>{{ s.nombre }}</strong>
                  <small v-if="s.direccion">{{ s.direccion }}</small>
                  <small v-else-if="s.region">{{ s.region }}</small>
                </span>
              </label>
            </div>
          </fieldset>

          <!-- Paso: servicio -->
          <div v-else-if="paso === 'servicio'" class="tu-card p-5">
            <p
              v-if="variasSedes && sucursalSel"
              class="rc-contexto"
              data-prueba="contexto"
            >
              {{ sucursalSel.nombre }} ·
              <button
                type="button"
                class="tu-enlace"
                @click="volverA('sucursal')"
              >
                {{ $t("perfilPublico.agendar.cambiar") }}
              </button>
            </p>
            <label class="tu-label">{{ $t("reservar.servicio") }}</label>
            <div
              v-for="g in gruposServicios"
              :key="g.nombre"
              class="mt-1 space-y-2"
            >
              <p
                v-if="g.nombre && gruposServicios.length > 1"
                class="pt-2 text-xs font-semibold"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ g.nombre }}
              </p>
              <label
                v-for="s in g.lista"
                :key="s.id"
                class="flex items-center justify-between gap-3 rounded-lg p-3 cursor-pointer border"
                :style="{
                  borderColor:
                    servicioId === s.id ? 'var(--primario)' : 'var(--borde)',
                  background:
                    servicioId === s.id
                      ? 'var(--primario-suave)'
                      : 'transparent',
                }"
              >
                <span class="flex items-center gap-2 min-w-0">
                  <input
                    :checked="servicioId === s.id"
                    type="radio"
                    name="servicio"
                    :value="s.id"
                    class="shrink-0"
                    @change="elegirServicio(s.id)"
                    @click="servicioId === s.id && elegirServicio(s.id)"
                  />
                  <span class="min-w-0">
                    <span class="font-medium block truncate">{{
                      s.nombre
                    }}</span>
                    <span
                      v-if="s.duracion_minutos"
                      class="text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        $t("reservar.duracionMin", { n: s.duracion_minutos })
                      }}</span
                    >
                    <span
                      v-if="s.descripcion"
                      class="block text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ s.descripcion }}</span
                    >
                    <ServicioIncluye
                      :incluye="s.incluye"
                      :precio-minor="s.precio_minor"
                      :por-separado-minor="s.precio_por_separado_minor"
                      :moneda="s.moneda"
                    />
                  </span>
                </span>
                <span class="font-semibold shrink-0">{{
                  dinero(s.precio_minor, s.moneda)
                }}</span>
              </label>
            </div>
          </div>

          <!-- Paso: fecha y hora (de todo el equipo o de quien se prefiera) -->
          <template v-else-if="paso === 'horario'">
            <p
              v-if="opciones.instructores.length === 0"
              class="tu-card p-5 reserva-ayuda"
            >
              {{ $t("reservar.sinProfesionales") }}
            </p>
            <div v-else class="tu-card p-5">
              <p class="rc-contexto" data-prueba="contexto">
                {{ servicioSel?.nombre
                }}<template v-if="variasSedes && sucursalSel">
                  · {{ sucursalSel.nombre }}</template
                >
                ·
                <button
                  type="button"
                  class="tu-enlace"
                  @click="volverA('servicio')"
                >
                  {{ $t("perfilPublico.agendar.cambiar") }}
                </button>
              </p>
              <div
                class="grid gap-3"
                :class="{ 'sm:grid-cols-2': variosProfesionales }"
              >
                <div>
                  <label class="tu-label" for="rc-fecha">{{
                    $t("reservar.cuando")
                  }}</label>
                  <input
                    id="rc-fecha"
                    v-model="fecha"
                    type="date"
                    class="tu-input"
                  />
                </div>
                <div v-if="variosProfesionales">
                  <label class="tu-label" for="rc-filtro">{{
                    $t("perfilPublico.agendar.verHorariosDe")
                  }}</label>
                  <select id="rc-filtro" v-model="filtro" class="tu-input">
                    <option value="">
                      {{ $t("perfilPublico.agendar.todoElEquipo") }}
                    </option>
                    <option
                      v-for="b in opciones.instructores"
                      :key="b.id"
                      :value="b.id"
                    >
                      {{ b.nombre }}
                    </option>
                  </select>
                </div>
              </div>

              <p
                v-if="error && !slotSel"
                class="mt-3 text-sm"
                role="alert"
                style="color: var(--error)"
              >
                {{ error }}
              </p>

              <p
                v-if="fecha === ''"
                class="mt-3 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reservar.sinHorario") }}
              </p>
              <p
                v-else-if="buscandoSlots"
                class="mt-3 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reservar.calculando") }}
              </p>
              <template v-else-if="slotsCargados">
                <p
                  v-if="slots.length === 0"
                  class="mt-3 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("reservar.sinHuecos") }}
                </p>
                <div v-else class="mt-3">
                  <label class="tu-label">{{ $t("reservar.hora") }}</label>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="s in slots"
                      :key="s.inicia"
                      type="button"
                      class="px-3 py-1.5 rounded-lg text-sm font-medium border"
                      :style="
                        slotSel === s.inicia
                          ? {
                              background: 'var(--primario)',
                              color: 'var(--primario-contraste)',
                              borderColor: 'var(--primario)',
                            }
                          : { borderColor: 'var(--borde)' }
                      "
                      @click="slotSel = s.inicia"
                    >
                      {{ horaLocal(s.inicia) }}
                    </button>
                  </div>
                </div>
              </template>
            </div>

            <!-- Con quién: tras la hora, solo quienes están libres entonces. -->
            <fieldset
              v-if="slotSel !== '' && eligeConQuien"
              class="tu-card p-5 reserva-opciones"
            >
              <legend class="tu-label">{{ $t("reservar.barbero") }}</legend>
              <p class="reserva-ayuda">
                {{
                  $t("perfilPublico.agendar.libresALas", {
                    hora: horaLocal(slotSel),
                  })
                }}
              </p>
              <div class="reserva-tarjetas">
                <label
                  class="reserva-eleccion"
                  :class="{
                    'reserva-eleccion--activa': barberoId === CUALQUIERA,
                  }"
                  data-prueba="cualquiera"
                >
                  <input
                    v-model="barberoId"
                    type="radio"
                    name="profesional"
                    :value="CUALQUIERA"
                  />
                  <AvatarIniciales :nombre="null" tam="md" />
                  <span class="reserva-eleccion-texto"
                    ><strong>{{
                      $t("perfilPublico.agendar.cualquiera")
                    }}</strong>
                    <span class="block text-xs font-normal">{{
                      $t("perfilPublico.agendar.cualquieraDesc")
                    }}</span></span
                  >
                </label>
                <label
                  v-for="b in libresEnHora"
                  :key="b.id"
                  class="reserva-eleccion"
                  :class="{ 'reserva-eleccion--activa': barberoId === b.id }"
                >
                  <input
                    v-model="barberoId"
                    type="radio"
                    name="profesional"
                    :value="b.id"
                  />
                  <AvatarIniciales
                    :nombre="b.nombre"
                    :foto="b.foto_url"
                    tam="md"
                  />
                  <span class="reserva-eleccion-texto"
                    ><strong>{{ b.nombre }}</strong></span
                  >
                </label>
              </div>
            </fieldset>

            <div class="flex items-center justify-between gap-3">
              <button
                type="button"
                class="tu-enlace text-sm"
                @click="volverA('servicio')"
              >
                {{ $t("perfilPublico.agendar.atras") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="!puedeContinuar"
                data-prueba="continuar"
                @click="ir('confirmar')"
              >
                {{ $t("perfilPublico.agendar.continuar") }}
              </button>
            </div>
          </template>

          <!-- Paso: confirmación (lo elegido, dónde es y tus datos) -->
          <template v-else-if="paso === 'confirmar'">
            <div class="tu-card p-5" data-prueba="resumen">
              <h2 class="font-semibold">
                {{ $t("perfilPublico.agendar.revisa") }}
              </h2>
              <dl class="rc-resumen mt-3">
                <div>
                  <dt>{{ $t("perfilPublico.agendar.servicio") }}</dt>
                  <dd>
                    <span class="font-medium">{{ servicioSel?.nombre }}</span>
                    ·
                    {{
                      dinero(
                        servicioSel?.precio_minor ?? null,
                        servicioSel?.moneda ?? null,
                      )
                    }}
                    <ServicioIncluye
                      :incluye="servicioSel?.incluye"
                      :precio-minor="servicioSel?.precio_minor"
                      :por-separado-minor="
                        servicioSel?.precio_por_separado_minor
                      "
                      :moneda="servicioSel?.moneda"
                    />
                  </dd>
                  <button
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('servicio')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
                <div>
                  <dt>{{ $t("perfilPublico.agendar.cuando") }}</dt>
                  <dd>
                    <span class="font-medium">{{ diaLocal(slotSel) }}</span>
                    · {{ horaLocal(slotSel) }}
                    <span
                      class="block text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        barberoId === CUALQUIERA
                          ? $t("perfilPublico.agendar.cualquiera")
                          : barberoSel?.nombre
                      }}</span
                    >
                  </dd>
                  <button
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('horario')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
                <div v-if="sucursalSel" data-prueba="donde">
                  <dt>{{ $t("perfilPublico.agendar.donde") }}</dt>
                  <dd>
                    <span class="flex items-start gap-3">
                      <img
                        v-if="sucursalSel.foto_url"
                        :src="sucursalSel.foto_url"
                        alt=""
                        class="h-14 w-20 shrink-0 rounded-lg object-cover"
                      />
                      <span class="min-w-0">
                        <span class="font-medium block">{{
                          sucursalSel.nombre
                        }}</span>
                        <span
                          v-if="sucursalSel.direccion"
                          class="block text-sm"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ sucursalSel.direccion }}</span
                        >
                        <a
                          v-if="sucursalSel.mapa_url"
                          :href="sucursalSel.mapa_url"
                          target="_blank"
                          rel="noopener"
                          class="tu-enlace text-sm"
                          data-prueba="como-llegar"
                          >{{ $t("perfilPublico.agendar.comoLlegar") }}</a
                        >
                      </span>
                    </span>
                  </dd>
                  <button
                    v-if="variasSedes"
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('sucursal')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
              </dl>
            </div>

            <div class="tu-card p-5">
              <label class="tu-label">{{ $t("reservar.datos") }}</label>
              <div class="space-y-3">
                <div>
                  <label class="tu-label" for="rc-nom">{{
                    $t("reservar.nombre")
                  }}</label>
                  <input
                    id="rc-nom"
                    v-model="datos.nombre"
                    class="tu-input"
                    autocomplete="name"
                    required
                  />
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                  <div>
                    <label class="tu-label" for="rc-cel">{{
                      $t("reservar.celular")
                    }}</label>
                    <input
                      id="rc-cel"
                      v-model="datos.celular"
                      class="tu-input"
                      inputmode="tel"
                      autocomplete="tel"
                    />
                  </div>
                  <div>
                    <label class="tu-label" for="rc-email">{{
                      $t("reservar.email")
                    }}</label>
                    <input
                      id="rc-email"
                      v-model="datos.email"
                      type="email"
                      class="tu-input"
                      autocomplete="email"
                    />
                  </div>
                </div>
              </div>

              <button
                class="tu-btn tu-btn-primario mt-4 w-full"
                type="button"
                :disabled="agendando || !listoParaAgendar"
                @click="agendar"
              >
                {{
                  agendando
                    ? $t("reservar.agendando")
                    : $t("reservar.agendarYPagar")
                }}
              </button>
              <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
                {{ error }}
              </p>
            </div>

            <button
              type="button"
              class="tu-enlace text-sm"
              @click="volverA('horario')"
            >
              {{ $t("perfilPublico.agendar.atras") }}
            </button>
          </template>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.reserva-opciones legend {
  float: left;
  width: 100%;
}
.reserva-opciones legend + * {
  clear: both;
}
.reserva-ayuda {
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
  margin-bottom: 1rem;
}
.reserva-tarjetas {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
.reserva-eleccion {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 76px;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 12px;
  cursor: pointer;
  background: var(--superficie);
}
.reserva-eleccion:hover {
  border-color: var(--primario);
}
.reserva-eleccion--activa {
  border-color: var(--primario);
  background: var(--primario-suave);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.reserva-eleccion:focus-within {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.reserva-eleccion input {
  accent-color: var(--primario);
  flex-shrink: 0;
}
.reserva-eleccion-texto {
  min-width: 0;
  overflow-wrap: anywhere;
}
.reserva-eleccion-texto strong {
  display: block;
  font-weight: 500;
  font-size: 0.9rem;
}

/* Tarjeta de sede: foto arriba (o el pin), nombre y dirección. */
.rc-sede {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: 12px;
  cursor: pointer;
  background: var(--superficie);
}
.rc-sede:hover {
  border-color: var(--primario);
}
.rc-sede--activa {
  border-color: var(--primario);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.rc-sede:focus-within {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.rc-sede-foto {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 9;
  object-fit: cover;
  background: var(--fondo);
}
.rc-sede-sinfoto {
  display: grid;
  place-items: center;
  color: var(--texto-suave);
}
.rc-sede-sinfoto svg {
  width: 28px;
  height: 28px;
}
.rc-sede-texto {
  padding: 0.75rem 1rem 1rem;
  min-width: 0;
  overflow-wrap: anywhere;
}
.rc-sede-texto strong {
  display: block;
  font-weight: 500;
}
.rc-sede--compacta {
  flex-direction: row;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
}
.rc-sede--compacta .rc-sede-foto {
  width: 36px;
  height: 36px;
  aspect-ratio: auto;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--primario-suave);
  color: var(--enlace);
}
.rc-sede--compacta .rc-sede-sinfoto svg {
  width: 21px;
  height: 21px;
}
.rc-sede--compacta .rc-sede-texto {
  padding: 0;
}
.rc-sede-texto small {
  display: block;
  margin-top: 0.2rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  line-height: 1.4;
}

/* Mapa de pasos */
.rc-pasos {
  display: flex;
  gap: 0.5rem;
}
.rc-paso {
  flex: 1;
  min-width: 0;
  padding-top: 0.6rem;
  border-top: 3px solid var(--borde);
}
.rc-paso--hecho,
.rc-paso--actual {
  border-top-color: var(--primario);
}
.rc-paso-marca {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  font-size: 0.8rem;
  color: var(--texto-suave);
  text-align: left;
}
button.rc-paso-marca {
  cursor: pointer;
}
button.rc-paso-marca:hover .rc-paso-texto {
  text-decoration: underline;
}
.rc-paso--actual .rc-paso-marca {
  color: var(--texto);
  font-weight: 600;
}
.rc-paso-num {
  display: inline-grid;
  place-items: center;
  width: 1.4rem;
  height: 1.4rem;
  flex-shrink: 0;
  border-radius: 999px;
  border: 1px solid var(--borde);
  font-size: 0.72rem;
}
.rc-paso--actual .rc-paso-num {
  border-color: var(--primario);
  color: var(--primario);
}
.rc-paso--hecho .rc-paso-num {
  border-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.rc-paso-texto {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rc-contexto {
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}

/* Resumen de la confirmación */
.rc-resumen > div {
  display: grid;
  grid-template-columns: 6.5rem minmax(0, 1fr) auto;
  gap: 0.75rem;
  align-items: start;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.rc-resumen dt {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.rc-resumen dd {
  min-width: 0;
  overflow-wrap: anywhere;
}

@media (max-width: 520px) {
  .reserva-tarjetas {
    grid-template-columns: 1fr;
  }
  /* En pantallas angostas solo se nombra el paso actual, que toma el espacio. */
  .rc-paso--actual {
    flex: 4;
  }
  .rc-paso--actual .rc-paso-texto {
    white-space: normal;
  }
  .rc-paso:not(.rc-paso--actual) .rc-paso-texto {
    display: none;
  }
  /* El título y «Cambiar» en una línea; lo elegido abajo, a todo lo ancho. */
  .rc-resumen > div {
    grid-template-columns: minmax(0, 1fr) auto;
    row-gap: 0.25rem;
  }
  .rc-resumen dt {
    grid-column: 1;
    grid-row: 1;
  }
  .rc-resumen > div > button {
    grid-column: 2;
    grid-row: 1;
  }
  .rc-resumen dd {
    grid-column: 1 / -1;
    grid-row: 2;
  }
}
</style>
