<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { useRetornoPago } from "@/lib/retornoPago";
import { recordarNegocio } from "@/lib/negociosRecientes";
import AvatarIniciales from "@/components/AvatarIniciales.vue";

interface Servicio {
  id: string;
  nombre: string;
  // Qué incluye y en qué grupo va (catálogo del negocio).
  descripcion?: string | null;
  categoria?: string | null;
  precio_minor: number | null;
  moneda: string;
  duracion_minutos: number | null;
}
interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string | null;
  region?: string | null;
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
  // Con «cualquier profesional»: quiénes están libres en ese hueco.
  profesionales?: string[];
}
// Sin preferencia: el negocio asigna a quien esté libre a esa hora.
const CUALQUIERA = "cualquiera";

const route = useRoute();
// Al volver de la página de pago: avisa cómo quedó.
const retornoPago = useRetornoPago();
const slug = computed(() => String(route.params.slug));

const opciones = ref<Opciones | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);
const error = ref<string | null>(null);

// Selección del wizard.
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
const zona = computed(
  () => sucursalSel.value?.zona_horaria ?? "America/Mexico_City",
);
// Duración del servicio; respaldo de 60 min si el servicio no la definió.
const duracion = computed(() => servicioSel.value?.duracion_minutos ?? 60);

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
    // la única si solo hay una.
    const preSuc = String(route.query.sucursal ?? "");
    if (preSuc !== "" && data.data.sucursales.some((s) => s.id === preSuc)) {
      sucursalId.value = preSuc;
    } else if (data.data.sucursales.length === 1) {
      sucursalId.value = data.data.sucursales[0].id;
    }
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
  if (barberoId.value === "" || sucursalId.value === "" || fecha.value === "") {
    return;
  }
  buscandoSlots.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { slots: Slot[] } }>(
      `/api/v1/app/${slug.value}/citas/disponibilidad`,
      {
        params: {
          ...(barberoId.value !== CUALQUIERA
            ? { instructor_id: barberoId.value }
            : {}),
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

// Recalcula huecos al cambiar barbero, sucursal, fecha o servicio (por su duración).
// Con más de un profesional se parte de «cualquiera»: se puede elegir a alguien.
watch(sucursalId, () => {
  barberoId.value =
    (opciones.value?.instructores.length ?? 0) > 1 ? CUALQUIERA : "";
  fecha.value = "";
});
watch([barberoId, sucursalId, fecha, servicioId], buscarSlots);

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

function otra(): void {
  resultado.value = null;
  pendientePago.value = false;
  slotSel.value = "";
  fecha.value = "";
  slots.value = [];
  slotsCargados.value = false;
  datos.value = { nombre: "", celular: "", email: "" };
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
          class="mx-auto h-12 w-12 rounded-full inline-flex items-center justify-center text-white text-xl"
          :style="{ background: 'var(--exito)' }"
        >
          ✓
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
          {{ horaLocal(slotSel) }} ·
          {{ dinero(resultado.total_minor, resultado.moneda) }}
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

      <!-- ===== Wizard ===== -->
      <div v-else class="mt-6 space-y-4">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reservar.intro") }}
        </p>

        <!-- Si hay varias sedes, elegir primero el lugar de la cita. -->
        <fieldset
          v-if="opciones.sucursales.length > 1"
          class="tu-card p-5 reserva-opciones"
        >
          <legend class="tu-label">{{ $t("sucursalesPub.titulo") }}</legend>
          <p class="reserva-ayuda">{{ $t("sucursalesPub.subtitulo") }}</p>
          <div class="reserva-tarjetas">
            <label
              v-for="s in opciones.sucursales"
              :key="s.id"
              class="reserva-eleccion"
              :class="{ 'reserva-eleccion--activa': sucursalId === s.id }"
            >
              <input
                v-model="sucursalId"
                type="radio"
                name="sucursal"
                :value="s.id"
              />
              <span class="reserva-sede-icono" aria-hidden="true">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                >
                  <path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" />
                  <circle cx="12" cy="10" r="2.5" />
                </svg>
              </span>
              <span class="reserva-eleccion-texto"
                ><strong>{{ s.nombre }}</strong
                ><small v-if="s.region">{{ s.region }}</small></span
              >
            </label>
          </div>
        </fieldset>

        <!-- Servicio: conserva su presentación. -->
        <div v-if="sucursalId !== ''" class="tu-card p-5">
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
                  servicioId === s.id ? 'var(--primario-suave)' : 'transparent',
              }"
            >
              <span class="flex items-center gap-2 min-w-0">
                <input
                  v-model="servicioId"
                  type="radio"
                  :value="s.id"
                  class="shrink-0"
                />
                <span class="min-w-0">
                  <span class="font-medium block truncate">{{ s.nombre }}</span>
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
                </span>
              </span>
              <span class="font-semibold shrink-0">{{
                dinero(s.precio_minor, s.moneda)
              }}</span>
            </label>
          </div>
        </div>

        <!-- Profesional con su foto real o inicial de respaldo. -->
        <fieldset
          v-if="servicioId !== '' && sucursalId !== ''"
          class="tu-card p-5 reserva-opciones"
        >
          <legend class="tu-label">{{ $t("reservar.barbero") }}</legend>
          <p v-if="opciones.instructores.length === 0" class="reserva-ayuda">
            {{ $t("reservar.sinProfesionales") }}
          </p>
          <div v-else class="reserva-tarjetas">
            <label
              v-if="opciones.instructores.length > 1"
              class="reserva-eleccion"
              :class="{ 'reserva-eleccion--activa': barberoId === CUALQUIERA }"
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
                ><strong>{{ $t("perfilPublico.agendar.cualquiera") }}</strong>
                <span class="block text-xs font-normal">{{
                  $t("perfilPublico.agendar.cualquieraDesc")
                }}</span></span
              >
            </label>
            <label
              v-for="b in opciones.instructores"
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
              <AvatarIniciales :nombre="b.nombre" :foto="b.foto_url" tam="md" />
              <span class="reserva-eleccion-texto"
                ><strong>{{ b.nombre }}</strong></span
              >
            </label>
          </div>
        </fieldset>

        <!-- Día + hora -->
        <div
          v-if="barberoId !== '' && sucursalId !== '' && servicioId !== ''"
          class="tu-card p-5"
        >
          <label class="tu-label" for="rc-fecha">{{
            $t("reservar.cuando")
          }}</label>
          <input id="rc-fecha" v-model="fecha" type="date" class="tu-input" />

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

        <!-- Datos -->
        <div v-if="slotSel !== ''" class="tu-card p-5">
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
                />
              </div>
            </div>
          </div>

          <!-- Resumen -->
          <div
            class="mt-4 border-t pt-4 text-sm"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <div class="flex items-center justify-between">
              <span :style="{ color: 'var(--texto-suave)' }">{{
                servicioSel?.nombre
              }}</span>
              <span class="font-semibold">{{
                dinero(
                  servicioSel?.precio_minor ?? null,
                  servicioSel?.moneda ?? null,
                )
              }}</span>
            </div>
            <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{
                barberoId === CUALQUIERA
                  ? $t("perfilPublico.agendar.cualquiera")
                  : barberoSel?.nombre
              }}
              · {{ horaLocal(slotSel) }}
            </p>
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
.reserva-eleccion-texto small {
  display: block;
  color: var(--texto-suave);
  margin-top: 0.2rem;
}
.reserva-sede-icono {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--primario-suave);
  color: var(--enlace);
}
.reserva-sede-icono svg {
  width: 21px;
  height: 21px;
}
@media (max-width: 480px) {
  .reserva-tarjetas {
    grid-template-columns: 1fr;
  }
}
</style>
