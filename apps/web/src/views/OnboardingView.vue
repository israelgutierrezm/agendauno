<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRouter } from "vue-router";

import CargadorLogo from "@/components/CargadorLogo.vue";
import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Configuración inicial por tipo de negocio (ADR 0088). Con citas: tu negocio →
 * servicios → quién atiende y cuándo → publicar. Con clases: tu negocio → clases →
 * horario → planes → publicar. Un servicio se da de alta en una línea («Corte de
 * cabello · 30 min · $250»): la estructura del catálogo se arma por dentro. Cobro en
 * línea, equipo administrativo, productos y reglas quedan «para cuando lo necesites».
 * Cada paso guarda lo suyo; el avance sale de los datos reales del negocio.
 */
type Paso =
  | "negocio"
  | "servicios"
  | "equipo"
  | "clases"
  | "horario"
  | "planes"
  | "publicacion";

interface Sugerencias {
  servicios: {
    nombre: string;
    duracion_minutos: number;
    precio_minor: number;
  }[];
  clases: { nombre: string; duracion_minutos: number; capacidad: number }[];
  planes: {
    clave: string;
    nombre: string;
    precio_minor: number;
    clases: number | null;
  }[];
}
interface Oferta {
  id: string;
  nombre: string;
  modalidad?: string;
  duracion_minutos?: number | null;
  precio_clase_minor?: number | null;
  capacidad?: number | null;
}
interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria?: string;
  direccion?: string | null;
}
interface Profesional {
  id: string;
  nombre: string;
}

const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const ICONOS: Record<Paso, string> = {
  negocio: "ubicacion",
  servicios: "etiqueta",
  equipo: "instructores",
  clases: "agenda",
  horario: "reloj",
  planes: "dinero",
  publicacion: "contenido",
};
const ZONAS = [
  "America/Mexico_City",
  "America/Tijuana",
  "America/Monterrey",
  "America/Cancun",
  "America/Bogota",
  "America/Lima",
  "America/Santiago",
  "America/Argentina/Buenos_Aires",
];
const DURACIONES = [15, 20, 30, 45, 60, 75, 90, 120, 150, 180];
const DIAS = [1, 2, 3, 4, 5, 6, 7] as const;

const pasos = ref<Paso[]>([]);
const completados = ref<Set<string>>(new Set());
const completo = ref(false);
const indice = ref(0);
const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);

const pasoActual = computed<Paso>(() => pasos.value[indice.value] ?? "negocio");
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const esCitas = computed(() => pasos.value.includes("servicios"));

// ---- Lo que ya existe en el negocio ----
const sucursales = ref<Sucursal[]>([]);
const ofertas = ref<Oferta[]>([]);
const profesionales = ref<Profesional[]>([]);
const plantillas = ref<
  {
    id: string;
    oferta?: string | null;
    dias_semana: number[];
    hora_local: string;
  }[]
>([]);
const productos = ref<{ id: string; nombre: string; precio_minor: number }[]>(
  [],
);

// ---- Lo que se captura en cada paso ----
const logoUrl = ref<string | null>(sesion.estudio?.logo_url ?? null);
const negocio = ref({
  sucursal: "",
  direccion: "",
  zona: "America/Mexico_City",
});
interface Fila {
  nombre: string;
  duracion: number;
  precio: string;
  cupo: string;
}
const filas = ref<Fila[]>([]);
const equipo = ref({
  yo: true,
  otros: [] as { nombre: string; email: string }[],
  dias: [1, 2, 3, 4, 5, 6] as number[],
  abre: "10:00",
  cierra: "19:00",
});
const horario = ref<
  Record<string, { dias: number[]; hora: string; instructor: string }>
>({});
const planes = ref<
  {
    clave: string;
    incluir: boolean;
    nombre: string;
    precio: string;
    clases: string;
  }[]
>([]);
const publicacion = ref({ publicado: true, privado: false });

function pesos(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    maximumFractionDigits: 0,
  }).format(minor / 100);
}
const aCentavos = (texto: string): number =>
  Math.round(Number(texto.replace(/[^\d.]/g, "")) * 100);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: {
        pasos: Paso[];
        completados: string[];
        completo: boolean;
        sugerencias: Sugerencias;
      };
    }>(`${base.value}/onboarding`);
    pasos.value = data.data.pasos;
    completados.value = new Set(data.data.completados);
    completo.value = data.data.completo;
    await cargarReferencias();
    prellenar(data.data.sugerencias);
    // Empieza en el primer paso pendiente.
    const pendiente = pasos.value.findIndex((p) => !completados.value.has(p));
    indice.value = pendiente === -1 ? pasos.value.length - 1 : pendiente;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cargarReferencias(): Promise<void> {
  const pedir = async <T,>(ruta: string, destino: { value: T }) => {
    try {
      destino.value = (
        await api.get<{ data: T }>(`${base.value}${ruta}`)
      ).data.data;
    } catch {
      // Sin permiso o sin datos: el paso funciona igual.
    }
  };
  await Promise.all([
    pedir("/sucursales", sucursales),
    pedir("/ofertas", ofertas),
    pedir("/instructores", profesionales),
    esCitas.value
      ? Promise.resolve()
      : pedir("/plantillas-horario", plantillas),
    esCitas.value ? Promise.resolve() : pedir("/productos", productos),
  ]);
}

function prellenar(s: Sugerencias): void {
  const sede = sucursales.value[0];
  negocio.value = {
    sucursal: sede?.nombre ?? "",
    direccion: sede?.direccion ?? "",
    zona: sede?.zona_horaria ?? "America/Mexico_City",
  };
  // Con lo más común del giro, si aún no hay nada.
  if (ofertas.value.length === 0) {
    filas.value = esCitas.value
      ? s.servicios.map((x) => ({
          nombre: x.nombre,
          duracion: x.duracion_minutos,
          precio: String(x.precio_minor / 100),
          cupo: "",
        }))
      : s.clases.map((x) => ({
          nombre: x.nombre,
          duracion: x.duracion_minutos,
          precio: "",
          cupo: String(x.capacidad),
        }));
  }
  equipo.value.yo = profesionales.value.length === 0;
  planes.value = s.planes.map((p) => ({
    clave: p.clave,
    incluir: productos.value.length === 0,
    nombre: p.nombre,
    precio: String(p.precio_minor / 100),
    clases: p.clases !== null ? String(p.clases) : "",
  }));
  prepararHorario();
}

function prepararHorario(): void {
  const actual = horario.value;
  horario.value = Object.fromEntries(
    clasesGrupales.value.map((o) => [
      o.id,
      actual[o.id] ?? { dias: [], hora: "19:00", instructor: "" },
    ]),
  );
}
const clasesGrupales = computed(() =>
  ofertas.value.filter((o) => o.modalidad !== "individual"),
);

// ---- Pasos ----
async function marcar(paso: Paso): Promise<void> {
  const { data } = await api.put<{
    data: { completados: string[]; completo: boolean };
  }>(`${base.value}/onboarding`, { paso });
  completados.value = new Set(data.data.completados);
  completo.value = data.data.completo;
  trackEvent("onboarding_step_completed", {
    step: paso,
    onboarding_complete: data.data.completo,
  });
}

async function ejecutar(accion: () => Promise<void>): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    await accion();
    await marcar(pasoActual.value);
    if (indice.value < pasos.value.length - 1) {
      indice.value += 1;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function guardarNegocio(): Promise<void> {
  const datos = {
    nombre: negocio.value.sucursal.trim(),
    direccion: negocio.value.direccion.trim() || null,
    zona_horaria: negocio.value.zona,
  };
  const sede = sucursales.value[0];
  if (sede !== undefined) {
    await api.put(`${base.value}/sucursales/${sede.id}`, datos);
  } else {
    const org = await api.post<{ data: { id: string } }>(
      `${base.value}/organizaciones`,
      { nombre: sesion.estudio?.nombre ?? datos.nombre },
    );
    const { data } = await api.post<{ data: Sucursal }>(
      `${base.value}/organizaciones/${org.data.data.id}/sucursales`,
      datos,
    );
    sucursales.value = [data.data];
  }
}

const filasValidas = computed(() =>
  filas.value.filter(
    (f) =>
      f.nombre.trim() !== "" &&
      (esCitas.value ? f.precio.trim() !== "" : Number(f.cupo) > 0),
  ),
);
function agregarFila(): void {
  filas.value.push({
    nombre: "",
    duracion: esCitas.value ? 30 : 60,
    precio: "",
    cupo: esCitas.value ? "" : "10",
  });
}
async function guardarCatalogo(): Promise<void> {
  if (filasValidas.value.length > 0) {
    const { data } = await api.post<{ data: Oferta[] }>(
      `${base.value}/onboarding/catalogo`,
      {
        items: filasValidas.value.map((f) =>
          esCitas.value
            ? {
                nombre: f.nombre.trim(),
                duracion_minutos: f.duracion,
                precio_minor: aCentavos(f.precio),
              }
            : {
                nombre: f.nombre.trim(),
                duracion_minutos: f.duracion,
                capacidad: Number(f.cupo),
              },
        ),
      },
    );
    ofertas.value = [
      ...ofertas.value,
      ...data.data.map((o) => ({
        ...o,
        modalidad: esCitas.value ? "individual" : "grupal",
        precio_clase_minor:
          (o as { precio_minor?: number | null }).precio_minor ?? null,
      })),
    ];
    filas.value = [];
    prepararHorario();
  }
}

// Quién atiende: el dueño (si atiende) y a quien invite, con un horario común.
const soyProfesional = computed(() =>
  (sesion.usuario?.roles ?? []).includes("instructor"),
);
async function guardarEquipo(): Promise<void> {
  const sede = sucursales.value[0];
  if (sede === undefined) {
    throw new Error(t("configuracionInicial.desc.negocio"));
  }
  const ids: string[] = [];
  const yo = sesion.usuario;
  if (equipo.value.yo && yo) {
    if (!soyProfesional.value) {
      await api.put(`${base.value}/usuarios/${yo.ulid}/roles`, {
        roles: [...(yo.roles ?? [yo.rol]), "instructor"],
      });
      await sesion.cargarYo();
    }
    ids.push(yo.ulid);
  }
  for (const otro of equipo.value.otros) {
    if (otro.nombre.trim() === "" || otro.email.trim() === "") {
      continue;
    }
    const { data } = await api.post<{ data: { usuario: { id: string } } }>(
      `${base.value}/usuarios/invitar`,
      {
        nombre: otro.nombre.trim(),
        email: otro.email.trim(),
        rol: "instructor",
        sucursal_id: sede.id,
      },
    );
    ids.push(data.data.usuario.id);
  }
  const horarios = [...equipo.value.dias]
    .sort((a, b) => a - b)
    .map((dia) => ({
      dia_semana: dia,
      hora_inicio: equipo.value.abre,
      hora_fin: equipo.value.cierra,
    }));
  for (const id of ids) {
    await api.put(`${base.value}/horarios-atencion`, {
      instructor_id: id,
      sucursal_id: sede.id,
      horarios,
    });
  }
  equipo.value.otros = [];
}
const equipoListo = computed(
  () =>
    (equipo.value.yo ||
      equipo.value.otros.some(
        (o) => o.nombre.trim() !== "" && o.email.trim() !== "",
      )) &&
    equipo.value.dias.length > 0 &&
    equipo.value.cierra > equipo.value.abre,
);

// Horario de clases: cada clase, sus días y su hora; se repite cada semana.
const filasHorario = computed(() =>
  clasesGrupales.value.filter(
    (o) => (horario.value[o.id]?.dias.length ?? 0) > 0,
  ),
);
async function guardarHorario(): Promise<void> {
  const sede = sucursales.value[0];
  if (sede === undefined) {
    return;
  }
  const hoy = new Date();
  const iso = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const hasta = new Date(hoy.getTime() + 28 * 24 * 3600 * 1000);
  for (const o of filasHorario.value) {
    const h = horario.value[o.id]!;
    const { data } = await api.post<{ data: { id: string } }>(
      `${base.value}/plantillas-horario`,
      {
        oferta_id: o.id,
        sucursal_id: sede.id,
        instructor_id: h.instructor || null,
        dias_semana: [...h.dias].sort((a, b) => a - b),
        hora_local: h.hora,
        duracion_minutos: o.duracion_minutos ?? 60,
        capacidad: o.capacidad ?? null,
        vigente_desde: iso(hoy),
      },
    );
    await api.post(`${base.value}/plantillas-horario/${data.data.id}/generar`, {
      desde: iso(hoy),
      hasta: iso(hasta),
    });
    plantillas.value.push({
      id: data.data.id,
      oferta: o.nombre,
      dias_semana: h.dias,
      hora_local: h.hora,
    });
    horario.value[o.id] = { dias: [], hora: h.hora, instructor: h.instructor };
  }
}

async function guardarPlanes(): Promise<void> {
  for (const p of planes.value.filter((x) => x.incluir)) {
    const comun = {
      nombre: p.nombre.trim(),
      precio_minor: aCentavos(p.precio),
      moneda: "MXN",
    };
    const datos =
      p.clave === "suelta"
        ? {
            ...comun,
            tipo: "sesion_individual",
            ilimitado: false,
            creditos_incluidos: 1000,
            vigencia_tipo: "dias",
            vigencia_cantidad: 30,
          }
        : p.clave === "paquete"
          ? {
              ...comun,
              tipo: "paquete",
              ilimitado: false,
              creditos_incluidos: Number(p.clases) * 1000,
              vigencia_tipo: "meses",
              vigencia_cantidad: 1,
            }
          : {
              ...comun,
              tipo: "membresia",
              ilimitado: true,
              creditos_incluidos: null,
              vigencia_tipo: "meses",
              vigencia_cantidad: 1,
            };
    const { data } = await api.post<{
      data: { id: string; nombre: string; precio_minor: number };
    }>(`${base.value}/productos`, datos);
    productos.value.push(data.data);
    p.incluir = false;
  }
}

async function guardarPublicacion(): Promise<void> {
  await api.put(`${base.value}/publicacion`, publicacion.value);
  trackEvent("studio_publication_updated", {
    published: publicacion.value.publicado,
    private: publicacion.value.privado,
  });
}
const enlace = computed(() =>
  esCitas.value
    ? `${window.location.origin}/agendar/${sesion.slug}`
    : `${window.location.origin}/estudio/${sesion.slug}`,
);
async function copiarEnlace(): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlace.value);
    toast.exito(t("configuracionInicial.publicacion.copiado"));
  } catch {
    // Sin portapapeles: el enlace queda a la vista para copiarlo a mano.
  }
}

// Botón principal según el paso.
const accion = computed<{
  deshabilitado: boolean;
  ejecutar: () => void;
  texto: string;
}>(() => {
  const g = guardando.value;
  const siguiente = t("configuracionInicial.siguiente");
  switch (pasoActual.value) {
    case "negocio":
      return {
        texto: siguiente,
        deshabilitado: g || negocio.value.sucursal.trim() === "",
        ejecutar: () => void ejecutar(guardarNegocio),
      };
    case "servicios":
    case "clases":
      return {
        texto: siguiente,
        deshabilitado:
          g || (ofertas.value.length === 0 && filasValidas.value.length === 0),
        ejecutar: () => void ejecutar(guardarCatalogo),
      };
    case "equipo":
      return {
        texto: siguiente,
        deshabilitado:
          g || (!completados.value.has("equipo") && !equipoListo.value),
        ejecutar: () =>
          void ejecutar(
            equipoListo.value ? guardarEquipo : () => Promise.resolve(),
          ),
      };
    case "horario":
      return {
        texto: siguiente,
        deshabilitado:
          g ||
          (plantillas.value.length === 0 && filasHorario.value.length === 0),
        ejecutar: () => void ejecutar(guardarHorario),
      };
    case "planes":
      return {
        texto: siguiente,
        deshabilitado:
          g ||
          (productos.value.length === 0 &&
            !planes.value.some((p) => p.incluir && p.precio.trim() !== "")),
        ejecutar: () => void ejecutar(guardarPlanes),
      };
    default:
      return {
        texto: t("configuracionInicial.terminar"),
        deshabilitado: g,
        ejecutar: () => void ejecutar(guardarPublicacion),
      };
  }
});

const porcentaje = computed(() =>
  pasos.value.length === 0
    ? 0
    : Math.round((completados.value.size / pasos.value.length) * 100),
);
function alternarDia(lista: number[], dia: number): void {
  const i = lista.indexOf(dia);
  if (i === -1) {
    lista.push(dia);
  } else {
    lista.splice(i, 1);
  }
}
const diasCortos = (dias: number[]): string =>
  [...dias]
    .sort((a, b) => a - b)
    .map((d) => t(`configuracionInicial.diasCortos.${d}`))
    .join(", ");

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-8 sm:py-10">
    <!-- Encabezado y avance -->
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold sm:text-3xl">
          {{ $t("configuracionInicial.titulo") }}
        </h1>
        <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("configuracionInicial.subtitulo") }}
        </p>
      </div>
      <div v-if="!cargando && pasos.length > 0" class="ci-avance">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("configuracionInicial.paso", {
              n: indice + 1,
              total: pasos.length,
            })
          }}
        </p>
        <div class="mt-2 flex items-center gap-3">
          <div
            class="ci-barra"
            role="progressbar"
            :aria-valuenow="porcentaje"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <span :style="{ width: `${porcentaje}%` }" />
          </div>
          <span
            class="text-sm tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ porcentaje }}%</span
          >
        </div>
      </div>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="pasos.length === 0" class="mt-8" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-else>
      <!-- Pasos numerados -->
      <ol class="ci-pasos" :aria-label="$t('configuracionInicial.titulo')">
        <li
          v-for="(p, i) in pasos"
          :key="p"
          class="ci-paso"
          :class="{
            'ci-actual': i === indice,
            'ci-hecho': completados.has(p) && i !== indice,
          }"
        >
          <button
            type="button"
            class="ci-paso-boton"
            :aria-current="i === indice ? 'step' : undefined"
            @click="indice = i"
          >
            <span class="ci-circulo">
              <IconoNav
                v-if="completados.has(p) && i !== indice"
                nombre="hecho"
                :tam="16"
              />
              <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="ci-etiqueta">{{
              $t(`configuracionInicial.pasos.${p}`)
            }}</span>
          </button>
        </li>
      </ol>

      <div
        v-if="completo"
        class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
        :style="{
          background: 'color-mix(in srgb, var(--exito) 10%, transparent)',
          color: 'var(--exito)',
        }"
      >
        <span>{{ $t("configuracionInicial.completo") }}</span>
        <button
          class="tu-btn tu-btn-primario"
          @click="router.push({ name: 'panel' })"
        >
          {{ $t("configuracionInicial.irPanel") }}
        </button>
      </div>

      <!-- Paso actual -->
      <article class="tu-card mt-6 p-5 sm:p-8" :data-paso="pasoActual">
        <header class="flex items-start gap-4">
          <span class="ci-icono" aria-hidden="true">
            <IconoNav :nombre="ICONOS[pasoActual]" :tam="26" />
          </span>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-xl font-semibold sm:text-2xl">
                {{ $t(`configuracionInicial.pasos.${pasoActual}`) }}
              </h2>
              <span
                v-if="completados.has(pasoActual)"
                class="tu-badge tu-badge-exito"
                >{{ $t("configuracionInicial.hecho") }}</span
              >
            </div>
            <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`configuracionInicial.desc.${pasoActual}`) }}
            </p>
          </div>
        </header>

        <p v-if="error" class="mt-5 text-sm" style="color: var(--error)">
          {{ error }}
        </p>

        <div class="mt-6 space-y-5">
          <!-- Tu negocio -->
          <template v-if="pasoActual === 'negocio'">
            <div>
              <p class="tu-label">
                {{ $t("configuracionInicial.negocio.logo") }}
                <span :style="{ color: 'var(--texto-suave)' }">{{
                  $t("configuracionInicial.negocio.opcional")
                }}</span>
              </p>
              <div class="mt-1">
                <CargadorLogo
                  :logo-url="logoUrl"
                  @update:logo-url="logoUrl = $event"
                />
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="tu-label">{{
                  $t("configuracionInicial.negocio.sucursal")
                }}</span>
                <input
                  v-model="negocio.sucursal"
                  class="tu-input"
                  data-campo="sucursal"
                  maxlength="120"
                />
                <span
                  class="mt-1 block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t("configuracionInicial.negocio.sucursalAyuda") }}</span
                >
              </label>
              <label class="block">
                <span class="tu-label">{{
                  $t("configuracionInicial.negocio.zona")
                }}</span>
                <select v-model="negocio.zona" class="tu-input">
                  <option v-for="z in ZONAS" :key="z" :value="z">
                    {{ z.replace("America/", "").replace(/_/g, " ") }}
                  </option>
                </select>
              </label>
              <label class="block sm:col-span-2">
                <span class="tu-label"
                  >{{ $t("configuracionInicial.negocio.direccion") }}
                  <span :style="{ color: 'var(--texto-suave)' }">{{
                    $t("configuracionInicial.negocio.opcional")
                  }}</span></span
                >
                <input
                  v-model="negocio.direccion"
                  class="tu-input"
                  maxlength="255"
                />
              </label>
            </div>
          </template>

          <!-- Servicios o clases, en una línea -->
          <template
            v-else-if="pasoActual === 'servicios' || pasoActual === 'clases'"
          >
            <div v-if="ofertas.length > 0" class="ci-existentes">
              <p class="text-sm font-medium">
                {{ $t("configuracionInicial.catalogo.yaTienes") }}
              </p>
              <ul>
                <li v-for="o in ofertas" :key="o.id">
                  <span class="font-medium">{{ o.nombre }}</span>
                  <span v-if="o.duracion_minutos" class="ci-suave">
                    ·
                    {{
                      $t("configuracionInicial.catalogo.minutos", {
                        n: o.duracion_minutos,
                      })
                    }}</span
                  >
                  <span v-if="esCitas && o.precio_clase_minor" class="ci-suave">
                    · {{ pesos(o.precio_clase_minor) }}</span
                  >
                  <span v-else-if="!esCitas && o.capacidad" class="ci-suave">
                    ·
                    {{
                      $t("configuracionInicial.catalogo.lugares", {
                        n: o.capacidad,
                      })
                    }}</span
                  >
                </li>
              </ul>
              <RouterLink :to="{ name: 'catalogo' }" class="tu-enlace text-sm"
                >{{
                  $t("configuracionInicial.catalogo.editarEnCatalogo")
                }}
                →</RouterLink
              >
            </div>
            <p v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("configuracionInicial.catalogo.sugerencia") }}
            </p>

            <div v-if="filas.length > 0" class="ci-filas" data-prueba="filas">
              <div class="ci-fila ci-fila-cabeza" aria-hidden="true">
                <span>{{ $t("configuracionInicial.catalogo.nombre") }}</span>
                <span>{{ $t("configuracionInicial.catalogo.duracion") }}</span>
                <span>{{
                  esCitas
                    ? $t("configuracionInicial.catalogo.precio")
                    : $t("configuracionInicial.catalogo.cupo")
                }}</span>
                <span></span>
              </div>
              <div v-for="(f, i) in filas" :key="i" class="ci-fila">
                <input
                  v-model="f.nombre"
                  class="tu-input"
                  :aria-label="$t('configuracionInicial.catalogo.nombre')"
                  maxlength="120"
                />
                <div class="tu-select-icono w-full">
                  <IconoNav nombre="reloj" :tam="16" />
                  <select
                    v-model.number="f.duracion"
                    class="tu-input w-full"
                    :aria-label="$t('configuracionInicial.catalogo.duracion')"
                  >
                    <option v-for="d in DURACIONES" :key="d" :value="d">
                      {{
                        $t("configuracionInicial.catalogo.minutos", { n: d })
                      }}
                    </option>
                  </select>
                </div>
                <div v-if="esCitas" class="ci-precio">
                  <span aria-hidden="true">$</span>
                  <input
                    v-model="f.precio"
                    class="tu-input"
                    inputmode="decimal"
                    :aria-label="$t('configuracionInicial.catalogo.precio')"
                  />
                </div>
                <input
                  v-else
                  v-model="f.cupo"
                  class="tu-input"
                  type="number"
                  min="1"
                  :aria-label="$t('configuracionInicial.catalogo.cupo')"
                />
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="$t('configuracionInicial.catalogo.quitar')"
                  @click="filas.splice(i, 1)"
                >
                  <IconoNav nombre="cerrar" :tam="16" />
                </button>
              </div>
            </div>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              @click="agregarFila"
            >
              <IconoNav nombre="mas" :tam="16" />
              {{
                esCitas
                  ? $t("configuracionInicial.catalogo.agregarServicio")
                  : $t("configuracionInicial.catalogo.agregarClase")
              }}
            </button>
          </template>

          <!-- Quién atiende y cuándo (citas) -->
          <template v-else-if="pasoActual === 'equipo'">
            <section class="space-y-3">
              <h3 class="font-medium">
                {{ $t("configuracionInicial.equipo.quien") }}
              </h3>
              <p
                v-if="profesionales.length > 0"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("configuracionInicial.equipo.yaAtienden") }}:
                {{ profesionales.map((p) => p.nombre).join(", ") }}
              </p>
              <label
                v-if="!soyProfesional"
                class="ci-opcion"
                :class="{ 'ci-opcion-activa': equipo.yo }"
              >
                <input v-model="equipo.yo" type="checkbox" />
                <span>
                  <span class="block font-medium">{{
                    $t("configuracionInicial.equipo.yo")
                  }}</span>
                  <span class="ci-suave block text-sm">{{
                    $t("configuracionInicial.equipo.yoAyuda", {
                      profesional: sesion.terminologia.instructor.toLowerCase(),
                    })
                  }}</span>
                </span>
              </label>
              <div
                v-for="(o, i) in equipo.otros"
                :key="i"
                class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
              >
                <input
                  v-model="o.nombre"
                  class="tu-input"
                  :placeholder="$t('configuracionInicial.equipo.nombre')"
                  :aria-label="$t('configuracionInicial.equipo.nombre')"
                />
                <input
                  v-model="o.email"
                  class="tu-input"
                  type="email"
                  :placeholder="$t('configuracionInicial.equipo.correo')"
                  :aria-label="$t('configuracionInicial.equipo.correo')"
                />
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="$t('configuracionInicial.catalogo.quitar')"
                  @click="equipo.otros.splice(i, 1)"
                >
                  <IconoNav nombre="cerrar" :tam="16" />
                </button>
              </div>
              <p
                v-if="equipo.otros.length > 0"
                class="text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("configuracionInicial.equipo.correoAyuda") }}
              </p>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                @click="equipo.otros.push({ nombre: '', email: '' })"
              >
                <IconoNav nombre="mas" :tam="16" />
                {{ $t("configuracionInicial.equipo.otro") }}
              </button>
            </section>

            <section class="space-y-3">
              <h3 class="font-medium">
                {{ $t("configuracionInicial.equipo.cuando") }}
              </h3>
              <div
                class="ci-dias"
                role="group"
                :aria-label="$t('configuracionInicial.equipo.dias')"
              >
                <button
                  v-for="d in DIAS"
                  :key="d"
                  type="button"
                  :aria-pressed="equipo.dias.includes(d)"
                  @click="alternarDia(equipo.dias, d)"
                >
                  {{ $t(`configuracionInicial.diasCortos.${d}`) }}
                </button>
              </div>
              <div class="flex flex-wrap items-end gap-3">
                <label class="block">
                  <span class="tu-label">{{
                    $t("configuracionInicial.equipo.abre")
                  }}</span>
                  <input
                    v-model="equipo.abre"
                    class="tu-input w-36"
                    type="time"
                    step="900"
                  />
                </label>
                <label class="block">
                  <span class="tu-label">{{
                    $t("configuracionInicial.equipo.cierra")
                  }}</span>
                  <input
                    v-model="equipo.cierra"
                    class="tu-input w-36"
                    type="time"
                    step="900"
                  />
                </label>
              </div>
              <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("configuracionInicial.equipo.ajustaDespues") }}
              </p>
            </section>
          </template>

          <!-- Horario semanal de clases -->
          <template v-else-if="pasoActual === 'horario'">
            <div v-if="plantillas.length > 0" class="ci-existentes">
              <p class="text-sm font-medium">
                {{ $t("configuracionInicial.horario.yaProgramadas") }}
              </p>
              <ul>
                <li v-for="p in plantillas" :key="p.id">
                  <span class="font-medium">{{ p.oferta }}</span>
                  <span class="ci-suave">
                    · {{ diasCortos(p.dias_semana) }} ·
                    {{ p.hora_local.slice(0, 5) }}</span
                  >
                </li>
              </ul>
            </div>
            <p
              v-if="clasesGrupales.length === 0"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("configuracionInicial.horario.sinClases") }}
            </p>
            <div
              v-for="o in clasesGrupales"
              :key="o.id"
              class="ci-clase"
              data-prueba="clase-horario"
            >
              <p class="font-medium">{{ o.nombre }}</p>
              <div
                class="ci-dias"
                role="group"
                :aria-label="$t('configuracionInicial.horario.dias')"
              >
                <button
                  v-for="d in DIAS"
                  :key="d"
                  type="button"
                  :aria-pressed="horario[o.id]?.dias.includes(d) ?? false"
                  @click="alternarDia(horario[o.id]!.dias, d)"
                >
                  {{ $t(`configuracionInicial.diasCortos.${d}`) }}
                </button>
              </div>
              <div class="flex flex-wrap items-end gap-3">
                <label class="block">
                  <span class="tu-label">{{
                    $t("configuracionInicial.horario.hora")
                  }}</span>
                  <input
                    v-model="horario[o.id]!.hora"
                    class="tu-input w-36"
                    type="time"
                    step="900"
                  />
                </label>
                <label class="block">
                  <span class="tu-label">{{
                    $t("configuracionInicial.horario.quien")
                  }}</span>
                  <div class="tu-select-icono">
                    <IconoNav nombre="instructores" :tam="16" />
                    <select
                      v-model="horario[o.id]!.instructor"
                      class="tu-input"
                    >
                      <option value="">
                        {{ $t("configuracionInicial.horario.sinAsignar") }}
                      </option>
                      <option
                        v-for="p in profesionales"
                        :key="p.id"
                        :value="p.id"
                      >
                        {{ p.nombre }}
                      </option>
                    </select>
                  </div>
                </label>
              </div>
            </div>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("configuracionInicial.horario.ayuda") }}
            </p>
          </template>

          <!-- Planes y precios (clases) -->
          <template v-else-if="pasoActual === 'planes'">
            <div v-if="productos.length > 0" class="ci-existentes">
              <p class="text-sm font-medium">
                {{ $t("configuracionInicial.planes.yaTienes") }}
              </p>
              <ul>
                <li v-for="p in productos" :key="p.id">
                  <span class="font-medium">{{ p.nombre }}</span>
                  <span class="ci-suave"> · {{ pesos(p.precio_minor) }}</span>
                </li>
              </ul>
            </div>
            <div
              v-for="p in planes"
              :key="p.clave"
              class="ci-plan"
              :class="{ 'ci-opcion-activa': p.incluir }"
            >
              <label class="ci-plan-casilla">
                <input v-model="p.incluir" type="checkbox" />
                <span class="sr-only">{{
                  $t("configuracionInicial.planes.incluir")
                }}</span>
              </label>
              <label class="block min-w-0 flex-1">
                <span class="tu-label">{{
                  $t(`configuracionInicial.planes.vigencia.${p.clave}`)
                }}</span>
                <input
                  v-model="p.nombre"
                  class="tu-input"
                  :aria-label="$t('configuracionInicial.catalogo.nombre')"
                />
              </label>
              <label v-if="p.clave === 'paquete'" class="block w-24">
                <span class="tu-label">{{
                  $t("configuracionInicial.planes.clases")
                }}</span>
                <input
                  v-model="p.clases"
                  class="tu-input"
                  type="number"
                  min="1"
                />
              </label>
              <label class="block w-32">
                <span class="tu-label">{{
                  $t("configuracionInicial.catalogo.precio")
                }}</span>
                <div class="ci-precio">
                  <span aria-hidden="true">$</span>
                  <input
                    v-model="p.precio"
                    class="tu-input"
                    inputmode="decimal"
                  />
                </div>
              </label>
            </div>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("configuracionInicial.planes.ayuda") }}
            </p>
          </template>

          <!-- Publicar -->
          <template v-else>
            <div class="ci-existentes">
              <p class="text-sm font-medium">
                {{ $t("configuracionInicial.publicacion.tuPagina") }}
              </p>
              <div class="flex flex-wrap items-center gap-3">
                <code class="ci-enlace">{{ enlace }}</code>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  @click="copiarEnlace"
                >
                  {{ $t("configuracionInicial.publicacion.copiar") }}
                </button>
              </div>
            </div>
            <label
              class="ci-opcion"
              :class="{ 'ci-opcion-activa': publicacion.publicado }"
            >
              <input v-model="publicacion.publicado" type="checkbox" />
              <span>
                <span class="block font-medium">{{
                  $t("configuracionInicial.publicacion.directorio")
                }}</span>
                <span class="ci-suave block text-sm">{{
                  $t("configuracionInicial.publicacion.directorioAyuda")
                }}</span>
              </span>
            </label>
            <label
              class="ci-opcion"
              :class="{ 'ci-opcion-activa': publicacion.privado }"
            >
              <input v-model="publicacion.privado" type="checkbox" />
              <span>
                <span class="block font-medium">{{
                  $t("configuracionInicial.publicacion.privado")
                }}</span>
                <span class="ci-suave block text-sm">{{
                  $t("configuracionInicial.publicacion.privadoAyuda")
                }}</span>
              </span>
            </label>
            <div>
              <p class="text-sm font-medium">
                {{ $t("configuracionInicial.publicacion.despues") }}
              </p>
              <ul class="ci-despues">
                <li v-if="esCitas">
                  <RouterLink :to="{ name: 'pasarelas' }" class="tu-enlace"
                    >{{
                      $t("configuracionInicial.publicacion.tareas.pasarelas")
                    }}
                    →</RouterLink
                  >
                </li>
                <li>
                  <RouterLink :to="{ name: 'usuarios' }" class="tu-enlace"
                    >{{
                      $t("configuracionInicial.publicacion.tareas.usuarios")
                    }}
                    →</RouterLink
                  >
                </li>
                <li>
                  <RouterLink :to="{ name: 'pos' }" class="tu-enlace"
                    >{{
                      $t("configuracionInicial.publicacion.tareas.pos")
                    }}
                    →</RouterLink
                  >
                </li>
                <li>
                  <RouterLink :to="{ name: 'reglas-agenda' }" class="tu-enlace"
                    >{{
                      $t("configuracionInicial.publicacion.tareas.reglas")
                    }}
                    →</RouterLink
                  >
                </li>
              </ul>
            </div>
          </template>
        </div>

        <!-- Pie: omitir · anterior · guardar y continuar -->
        <footer class="ci-pie">
          <button
            v-if="indice < pasos.length - 1"
            type="button"
            class="tu-enlace text-sm"
            @click="indice += 1"
          >
            {{ $t("configuracionInicial.omitir") }}
          </button>
          <span v-else />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="indice === 0"
              @click="indice -= 1"
            >
              {{ $t("configuracionInicial.anterior") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              data-prueba="accion"
              :disabled="accion.deshabilitado"
              @click="accion.ejecutar"
            >
              {{ accion.texto }}
              <span aria-hidden="true">→</span>
            </button>
          </div>
        </footer>
      </article>
    </template>
  </section>
</template>

<style scoped>
.ci-suave {
  color: var(--texto-suave);
}
.ci-avance {
  width: min(100%, 24rem);
}
.ci-barra {
  flex: 1;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.ci-barra > span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--primario);
  transition: width 0.3s ease;
}
/* Pasos: círculos numerados unidos por una línea; en el teléfono se desplazan. */
.ci-pasos {
  display: flex;
  margin-top: 2rem;
  overflow-x: auto;
  padding-bottom: 0.25rem;
}
.ci-paso {
  position: relative;
  flex: 1 0 5.5rem;
}
.ci-paso + .ci-paso::before {
  content: "";
  position: absolute;
  top: 1.25rem;
  right: calc(50% + 1.5rem);
  left: calc(-50% + 1.5rem);
  height: 1px;
  background: var(--borde);
}
.ci-paso.ci-hecho::before,
.ci-paso.ci-actual::before {
  background: color-mix(in srgb, var(--primario) 45%, var(--borde));
}
.ci-paso-boton {
  display: flex;
  width: 100%;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
}
.ci-circulo {
  display: inline-flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.ci-etiqueta {
  font-size: 0.875rem;
  color: var(--texto-suave);
  white-space: nowrap;
}
.ci-hecho .ci-circulo {
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.ci-actual .ci-circulo {
  background: var(--primario);
  color: var(--primario-contraste, #fff);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--primario) 18%, transparent);
}
.ci-actual .ci-etiqueta {
  color: var(--primario);
  font-weight: 600;
}
.ci-icono {
  display: inline-flex;
  height: 3.25rem;
  width: 3.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  color: var(--texto-suave);
}
.ci-existentes {
  display: grid;
  gap: 0.4rem;
  padding: 0.9rem 1rem;
  border-radius: 0.75rem;
  background: var(--superficie-2);
}
.ci-existentes ul {
  display: grid;
  gap: 0.2rem;
  font-size: 0.9rem;
}
/* Una línea por servicio o clase: nombre, duración, precio o lugares, quitar. */
.ci-filas {
  display: grid;
  gap: 0.6rem;
}
.ci-fila {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 8rem 8rem auto;
  gap: 0.6rem;
  align-items: center;
}
.ci-fila-cabeza {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
@media (max-width: 639px) {
  .ci-fila {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  }
  .ci-fila > :first-child {
    grid-column: 1 / -1;
  }
  .ci-fila-cabeza {
    display: none;
  }
}
.ci-precio {
  position: relative;
}
.ci-precio > span {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
  color: var(--texto-suave);
}
.ci-precio > input {
  padding-left: 1.6rem;
}
.ci-opcion {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  cursor: pointer;
}
.ci-opcion > input {
  margin-top: 0.25rem;
}
.ci-opcion-activa {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 5%, var(--superficie));
}
.ci-dias {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.ci-dias > button {
  min-width: 3.1rem;
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.ci-dias > button[aria-pressed="true"] {
  border-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.ci-clase {
  display: grid;
  gap: 0.6rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
}
.ci-plan {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
}
/* La casilla, a la altura de los campos (bajo sus etiquetas). */
.ci-plan-casilla {
  display: flex;
  align-items: center;
  height: 2.75rem;
}
.ci-enlace {
  padding: 0.4rem 0.6rem;
  border-radius: 0.5rem;
  background: var(--superficie);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}
.ci-despues {
  display: grid;
  gap: 0.35rem;
  margin-top: 0.4rem;
  font-size: 0.9rem;
}
.ci-pie {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--borde);
}
</style>
