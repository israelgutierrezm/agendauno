<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  abierto,
  MOTIVOS_CIERRE,
  whatsappDe,
  type EstadoProspecto,
  type EventoProspecto,
  type MotivoCierre,
  type Prospecto,
} from "@/lib/captacion";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Prospectos (ADR 0117): quien todavía no es cliente y dejó sus datos, aparte de
 * Miembros. Primero lo que pide atención (nuevos y seguimientos vencidos); en el detalle,
 * contactarlo, darle seguimiento, descartarlo con un motivo o darle acceso (su ficha en
 * Miembros y la invitación a su cuenta). No reserva hasta que es miembro.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("prospectos.gestionar"));
const puedeConvertir = computed(() => sesion.puede("prospectos.convertir"));
const puedeEliminar = computed(() => sesion.puede("prospectos.eliminar"));

const lista = ref<Prospecto[]>([]);
const resumen = ref({ nuevos: 0, seguimientos_vencidos: 0, abiertos: 0 });
const cargando = ref(true);
const error = ref<string | null>(null);
// El inicio abre las solicitudes nuevas ya filtradas (`?estado=nuevo`; M08).
const estadoInicial = useRoute()?.query.estado;
const filtros = reactive({
  estado: typeof estadoInicial === "string" ? estadoInicial : "abiertos",
  responsable: "",
  q: "",
});
const ESTADOS: EstadoProspecto[] = [
  "nuevo",
  "en_seguimiento",
  "aprobado",
  "invitacion_enviada",
  "convertido",
  "descartado",
];

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Prospecto[];
      meta: { resumen: typeof resumen.value };
    }>(`${base.value}/prospectos`, {
      params: {
        estado: filtros.estado,
        responsable: filtros.responsable || undefined,
        q: filtros.q.trim() || undefined,
      },
    });
    lista.value = data.data;
    resumen.value = data.meta.resumen;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

let espera: number | undefined;
watch(
  () => filtros.q,
  () => {
    window.clearTimeout(espera);
    espera = window.setTimeout(cargar, 300);
  },
);
watch(() => [filtros.estado, filtros.responsable], cargar);
onMounted(cargar);

// ---------------------------------------------------------------- formato
function fecha(iso: string | null, conHora = false): string {
  if (!iso) {
    return "";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    ...(conHora ? { hour: "numeric", minute: "2-digit" } : {}),
    timeZone: sesion.zonaHoraria ?? undefined,
  }).format(new Date(iso));
}
function tono(estado: EstadoProspecto): string {
  return {
    nuevo: "var(--acento)",
    en_seguimiento: "var(--aviso)",
    aprobado: "var(--aviso)",
    invitacion_enviada: "var(--aviso)",
    convertido: "var(--exito)",
    descartado: "var(--texto-suave)",
  }[estado];
}
function interes(p: Prospecto): string {
  const i = p.interes;
  if (i.sesion) {
    return t("captacion.prospectos.detalle.clase", {
      clase: i.sesion.clase ?? "",
      fecha: fecha(i.sesion.inicia_en, true),
    });
  }
  if (i.producto) {
    return t("captacion.prospectos.detalle.plan", {
      nombre: i.producto.nombre,
    });
  }
  if (i.actividad) {
    return i.actividad.nombre;
  }
  return i.sucursal
    ? t("captacion.prospectos.detalle.sede", { nombre: i.sucursal.nombre })
    : "";
}
function vencido(p: Prospecto): boolean {
  return (
    abierto(p.estado) &&
    p.proximo_seguimiento_en !== null &&
    new Date(p.proximo_seguimiento_en).getTime() <= Date.now()
  );
}
function textoEvento(e: EventoProspecto): string {
  const d = e.detalle;
  switch (e.tipo) {
    case "estado":
      return t("captacion.prospectos.eventos.estado", {
        estado: t(`captacion.prospectos.estados.${String(d.a)}`),
      });
    case "asignado":
      return d.responsable
        ? t("captacion.prospectos.eventos.asignado", {
            responsable: String(d.responsable),
          })
        : t("captacion.prospectos.eventos.sinAsignar");
    case "seguimiento":
      return d.cuando
        ? t("captacion.prospectos.eventos.seguimiento", {
            fecha: fecha(String(d.cuando), true),
          })
        : t("captacion.prospectos.eventos.seguimientoQuitado");
    case "nota":
      return String(d.texto ?? "");
    default:
      return t(`captacion.prospectos.eventos.${e.tipo}`);
  }
}

// ---------------------------------------------------------------- detalle
const actual = ref<Prospecto | null>(null);
const accionando = ref(false);
const nota = ref("");
const seguimiento = ref("");
const descartando = reactive({
  abierto: false,
  motivo: "" as MotivoCierre | "",
  comentario: "",
});
const acceso = reactive({
  abierto: false,
  invitar: true,
  buscar: "",
  fichas: [] as { id: string; nombre: string; email: string | null }[],
  ficha: "" as string,
});

async function abrir(p: Prospecto): Promise<void> {
  actual.value = p;
  descartando.abierto = false;
  acceso.abierto = false;
  nota.value = "";
  try {
    const { data } = await api.get<{ data: Prospecto }>(
      `${base.value}/prospectos/${p.id}`,
    );
    actual.value = data.data;
    seguimiento.value = data.data.proximo_seguimiento_en
      ? data.data.proximo_seguimiento_en.slice(0, 10)
      : "";
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function aplicar(
  peticion: () => Promise<{ data: { data: Prospecto } }>,
  exito?: string,
): Promise<void> {
  accionando.value = true;
  try {
    const { data } = await peticion();
    actual.value = data.data;
    if (exito) {
      toast.exito(exito);
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}

function cambiarEstado(estado: EstadoProspecto): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/estado`, { estado }),
  );
}
async function descartar(): Promise<void> {
  const p = actual.value;
  if (!p || descartando.motivo === "") {
    return;
  }
  await aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/estado`, {
      estado: "descartado",
      motivo_cierre: descartando.motivo,
      comentario: descartando.comentario || null,
    }),
  );
  descartando.abierto = false;
}
function asignarme(): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/responsable`, {
      responsable_id: sesion.usuario?.ulid ?? null,
    }),
  );
}
function guardarSeguimiento(quitar = false): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/seguimiento`, {
      cuando:
        quitar || seguimiento.value === ""
          ? null
          : `${seguimiento.value}T10:00:00`,
    }),
  );
}
async function anotar(): Promise<void> {
  const p = actual.value;
  if (!p || nota.value.trim() === "") {
    return;
  }
  await aplicar(() =>
    api.post(`${base.value}/prospectos/${p.id}/notas`, { texto: nota.value }),
  );
  nota.value = "";
}

function abrirAcceso(): void {
  acceso.abierto = true;
  acceso.invitar = !!actual.value?.email;
  acceso.buscar = actual.value?.email ?? actual.value?.nombre ?? "";
  acceso.fichas = [];
  acceso.ficha = "";
  void buscarFichas();
}
async function buscarFichas(): Promise<void> {
  if (acceso.buscar.trim().length < 2) {
    acceso.fichas = [];
    return;
  }
  try {
    const { data } = await api.get<{
      data: {
        id: string;
        nombre: string;
        nombre_completo?: string;
        email: string | null;
      }[];
    }>(`${base.value}/miembros`, { params: { q: acceso.buscar.trim() } });
    acceso.fichas = data.data.slice(0, 5).map((m) => ({
      id: m.id,
      nombre: m.nombre_completo ?? m.nombre,
      email: m.email,
    }));
  } catch {
    acceso.fichas = [];
  }
}
async function darAcceso(): Promise<void> {
  const p = actual.value;
  if (!p) {
    return;
  }
  accionando.value = true;
  try {
    const { data } = await api.post<{ data: Prospecto }>(
      `${base.value}/prospectos/${p.id}/convertir`,
      { invitar: acceso.invitar, persona_id: acceso.ficha || null },
    );
    actual.value = data.data;
    acceso.abierto = false;
    toast.exito(
      acceso.invitar && p.email
        ? t("captacion.prospectos.acceso.invitado", { email: p.email })
        : t("captacion.prospectos.acceso.listo"),
    );
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}
async function eliminar(): Promise<void> {
  const p = actual.value;
  if (
    !p ||
    !(await confirmar(t("captacion.prospectos.eliminar.texto"), {
      aceptar: t("captacion.prospectos.eliminar.confirmar"),
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/prospectos/${p.id}`);
    actual.value = null;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---------------------------------------------------------------- alta manual
const alta = reactive({ abierto: false, nombre: "", email: "", telefono: "" });
async function crear(): Promise<void> {
  if (
    alta.nombre.trim() === "" ||
    (alta.email.trim() === "" && alta.telefono.trim() === "")
  ) {
    toast.error(t("captacion.prospectos.alta.requiereContacto"));
    return;
  }
  accionando.value = true;
  try {
    const { data } = await api.post<{ data: Prospecto }>(
      `${base.value}/prospectos`,
      {
        nombre: alta.nombre,
        email: alta.email || null,
        telefono: alta.telefono || null,
      },
    );
    Object.assign(alta, {
      abierto: false,
      nombre: "",
      email: "",
      telefono: "",
    });
    await cargar();
    await abrir(data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('captacion.prospectos.titulo')"
      :subtitulo="$t('captacion.prospectos.descripcion')"
    />

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
      <div
        class="flex flex-wrap gap-2 text-sm"
        data-prueba="resumen-prospectos"
      >
        <button
          type="button"
          class="tu-chip"
          :aria-pressed="filtros.estado === 'nuevo'"
          @click="
            filtros.estado = filtros.estado === 'nuevo' ? 'abiertos' : 'nuevo'
          "
        >
          {{ $t("captacion.prospectos.resumen.nuevos") }}:
          <strong>{{ resumen.nuevos }}</strong>
        </button>
        <span class="tu-chip">
          {{ $t("captacion.prospectos.resumen.vencidos") }}:
          <strong>{{ resumen.seguimientos_vencidos }}</strong>
        </span>
        <span class="tu-chip">
          {{ $t("captacion.prospectos.resumen.abiertos") }}:
          <strong>{{ resumen.abiertos }}</strong>
        </span>
      </div>
      <button
        v-if="puedeGestionar"
        type="button"
        class="tu-btn tu-btn-primario tu-btn-crear"
        @click="alta.abierto = true"
      >
        {{ $t("captacion.prospectos.nuevo") }}
      </button>
    </div>

    <div class="tu-card mt-4">
      <div class="tu-filtros">
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="filtros.q"
            class="tu-input"
            type="search"
            :placeholder="$t('captacion.prospectos.buscar')"
            :aria-label="$t('captacion.prospectos.buscar')"
          />
        </label>
        <label class="text-sm">
          <span class="sr-only">{{
            $t("captacion.prospectos.filtros.estado")
          }}</span>
          <select v-model="filtros.estado" class="tu-input">
            <option value="abiertos">
              {{ $t("captacion.prospectos.filtros.abiertos") }}
            </option>
            <option value="todos">
              {{ $t("captacion.prospectos.filtros.todos") }}
            </option>
            <option v-for="e in ESTADOS" :key="e" :value="e">
              {{ $t(`captacion.prospectos.estados.${e}`) }}
            </option>
          </select>
        </label>
        <label class="text-sm">
          <span class="sr-only">{{
            $t("captacion.prospectos.filtros.responsable")
          }}</span>
          <select v-model="filtros.responsable" class="tu-input">
            <option value="">
              {{ $t("captacion.prospectos.filtros.cualquiera") }}
            </option>
            <option value="yo">
              {{ $t("captacion.prospectos.filtros.yo") }}
            </option>
            <option value="sin">
              {{ $t("captacion.prospectos.filtros.sin") }}
            </option>
          </select>
        </label>
      </div>

      <p
        v-if="error"
        class="p-4 text-sm"
        style="color: var(--error)"
        role="alert"
      >
        {{ error }}
      </p>
      <p
        v-else-if="cargando"
        class="p-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <EstadoVacio
        v-else-if="lista.length === 0"
        icono="captacion"
        :titulo="
          filtros.q || filtros.estado !== 'abiertos' || filtros.responsable
            ? $t('captacion.prospectos.vacioFiltro')
            : $t('captacion.prospectos.vacio')
        "
        compacto
      />
      <ul v-else class="divide-y" :style="{ borderColor: 'var(--borde)' }">
        <li v-for="p in lista" :key="p.id">
          <button
            type="button"
            class="prospecto-fila"
            data-prueba="prospecto"
            @click="abrir(p)"
          >
            <span class="min-w-0">
              <span class="block font-medium truncate">{{ p.nombre }}</span>
              <span class="tu-sub truncate">
                {{ [p.email, p.telefono].filter(Boolean).join(" · ") }}
              </span>
              <span v-if="interes(p)" class="tu-sub truncate">{{
                interes(p)
              }}</span>
            </span>
            <span class="prospecto-meta">
              <span
                class="tu-estado text-sm"
                :style="{ '--tono': tono(p.estado) }"
              >
                {{ $t(`captacion.prospectos.estados.${p.estado}`) }}
              </span>
              <span
                v-if="vencido(p)"
                class="tu-sub"
                style="color: var(--aviso)"
              >
                {{ $t("captacion.prospectos.vencido") }}
              </span>
              <span class="tu-sub">{{ fecha(p.creado_en) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>

    <!-- Detalle -->
    <PanelLateral
      :abierto="actual !== null"
      :titulo="actual?.nombre ?? ''"
      @cerrar="actual = null"
    >
      <div
        v-if="actual"
        class="grid gap-5 text-sm"
        data-prueba="detalle-prospecto"
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="tu-estado" :style="{ '--tono': tono(actual.estado) }">
            {{ $t(`captacion.prospectos.estados.${actual.estado}`) }}
          </span>
          <span :style="{ color: 'var(--texto-suave)' }">
            ·
            {{
              actual.origen === "manual"
                ? $t("captacion.prospectos.origen.manual")
                : $t("captacion.prospectos.origen.formulario", {
                    nombre: actual.formulario ?? "",
                  })
            }}
            · {{ fecha(actual.creado_en, true) }}
          </span>
        </div>

        <section>
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.contacto") }}
          </h3>
          <p v-if="actual.email">{{ actual.email }}</p>
          <p v-if="actual.telefono">{{ actual.telefono }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <a
              v-if="whatsappDe(actual.telefono)"
              class="tu-btn"
              :href="whatsappDe(actual.telefono) ?? undefined"
              target="_blank"
              rel="noopener"
              >{{ $t("captacion.prospectos.acciones.escribir") }}</a
            >
            <a
              v-if="actual.telefono"
              class="tu-btn"
              :href="`tel:${actual.telefono}`"
              >{{ $t("captacion.prospectos.acciones.llamar") }}</a
            >
            <a
              v-if="actual.email"
              class="tu-btn"
              :href="`mailto:${actual.email}`"
              >{{ $t("captacion.prospectos.acciones.correo") }}</a
            >
          </div>
          <p
            v-if="actual.fuente"
            class="mt-2"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("captacion.prospectos.detalle.fuente", {
                fuente: actual.fuente,
              })
            }}
          </p>
        </section>

        <section>
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.interes") }}
          </h3>
          <p>
            {{
              interes(actual) || $t("captacion.prospectos.detalle.sinInteres")
            }}
          </p>
        </section>

        <section v-if="actual.respuestas && actual.respuestas.length > 0">
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.respuestas") }}
          </h3>
          <dl class="grid gap-1">
            <div v-for="r in actual.respuestas" :key="r.campo">
              <dt :style="{ color: 'var(--texto-suave)' }">{{ r.etiqueta }}</dt>
              <dd>
                {{
                  typeof r.valor === "boolean"
                    ? r.valor
                      ? $t("captacion.publico.si")
                      : $t("captacion.publico.no")
                    : String(r.valor)
                }}
              </dd>
            </div>
          </dl>
        </section>

        <section v-if="actual.persona">
          <RouterLink
            class="tu-enlace"
            :to="{ name: 'miembros', query: { q: actual.persona.nombre } }"
          >
            {{ actual.persona.nombre }}
          </RouterLink>
        </section>

        <template v-if="puedeGestionar && abierto(actual.estado)">
          <section>
            <h3 class="tu-label">
              {{ $t("captacion.prospectos.detalle.responsable") }}
            </h3>
            <p>
              {{
                actual.responsable?.nombre ??
                $t("captacion.prospectos.sinResponsable")
              }}
            </p>
            <button
              v-if="actual.responsable?.id !== sesion.usuario?.ulid"
              type="button"
              class="tu-enlace mt-1"
              :disabled="accionando"
              @click="asignarme"
            >
              {{ $t("captacion.prospectos.acciones.asignarme") }}
            </button>
          </section>

          <section>
            <label class="tu-label" for="p-seguimiento">{{
              $t("captacion.prospectos.detalle.seguimiento")
            }}</label>
            <div class="flex flex-wrap items-center gap-2">
              <input
                id="p-seguimiento"
                v-model="seguimiento"
                type="date"
                class="tu-input w-auto"
              />
              <button
                type="button"
                class="tu-btn"
                :disabled="accionando"
                @click="guardarSeguimiento()"
              >
                {{ $t("captacion.prospectos.acciones.guardarFecha") }}
              </button>
              <button
                v-if="actual.proximo_seguimiento_en"
                type="button"
                class="tu-enlace"
                :disabled="accionando"
                @click="guardarSeguimiento(true)"
              >
                {{ $t("captacion.prospectos.acciones.quitarFecha") }}
              </button>
            </div>
          </section>

          <section class="flex flex-wrap gap-2">
            <button
              v-if="actual.estado === 'nuevo'"
              type="button"
              class="tu-btn"
              :disabled="accionando"
              @click="cambiarEstado('en_seguimiento')"
            >
              {{ $t("captacion.prospectos.acciones.seguimiento") }}
            </button>
            <button
              v-if="
                actual.estado !== 'aprobado' &&
                actual.estado !== 'invitacion_enviada'
              "
              type="button"
              class="tu-btn"
              :disabled="accionando"
              @click="cambiarEstado('aprobado')"
            >
              {{ $t("captacion.prospectos.acciones.aprobar") }}
            </button>
            <button
              v-if="puedeConvertir && actual.estado !== 'invitacion_enviada'"
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="accionando"
              @click="abrirAcceso"
            >
              {{ $t("captacion.prospectos.acciones.darAcceso") }}
            </button>
            <button
              type="button"
              class="tu-btn"
              :disabled="accionando"
              @click="descartando.abierto = !descartando.abierto"
            >
              {{ $t("captacion.prospectos.acciones.descartar") }}
            </button>
          </section>

          <form
            v-if="descartando.abierto"
            class="tu-card p-4 grid gap-3"
            @submit.prevent="descartar"
          >
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.descartar.motivo")
              }}</span>
              <select v-model="descartando.motivo" class="tu-input" required>
                <option value="" disabled />
                <option v-for="m in MOTIVOS_CIERRE" :key="m" :value="m">
                  {{ $t(`captacion.prospectos.motivos.${m}`) }}
                </option>
              </select>
            </label>
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.descartar.comentario")
              }}</span>
              <input
                v-model="descartando.comentario"
                class="tu-input"
                maxlength="300"
              />
            </label>
            <button
              type="submit"
              class="tu-btn tu-btn-primario justify-self-start"
              :disabled="accionando || descartando.motivo === ''"
            >
              {{ $t("captacion.prospectos.descartar.confirmar") }}
            </button>
          </form>

          <form
            v-if="acceso.abierto"
            class="tu-card p-4 grid gap-3"
            data-prueba="dar-acceso"
            @submit.prevent="darAcceso"
          >
            <h3 class="font-medium">
              {{ $t("captacion.prospectos.acceso.titulo") }}
            </h3>
            <p :style="{ color: 'var(--texto-suave)' }">
              {{ $t("captacion.prospectos.acceso.texto") }}
            </p>
            <label v-if="actual.email" class="flex items-start gap-2">
              <input v-model="acceso.invitar" type="checkbox" class="mt-1" />
              <span>{{ $t("captacion.prospectos.acceso.invitar") }}</span>
            </label>
            <p v-else :style="{ color: 'var(--texto-suave)' }">
              {{ $t("captacion.prospectos.acceso.sinCorreo") }}
            </p>
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.acceso.buscarFicha")
              }}</span>
              <input
                v-model="acceso.buscar"
                class="tu-input"
                type="search"
                @input="buscarFichas"
              />
            </label>
            <div v-if="acceso.fichas.length > 0" class="grid gap-1">
              <span class="tu-label">{{
                $t("captacion.prospectos.acceso.fichaExistente")
              }}</span>
              <label
                v-for="f in acceso.fichas"
                :key="f.id"
                class="flex items-center gap-2"
              >
                <input v-model="acceso.ficha" type="radio" :value="f.id" />
                <span
                  >{{ f.nombre
                  }}<span v-if="f.email" class="tu-sub">{{
                    f.email
                  }}</span></span
                >
              </label>
              <label class="flex items-center gap-2">
                <input v-model="acceso.ficha" type="radio" value="" />
                <span>{{ $t("captacion.prospectos.acceso.fichaNueva") }}</span>
              </label>
            </div>
            <button
              type="submit"
              class="tu-btn tu-btn-primario justify-self-start"
              :disabled="accionando"
            >
              {{ $t("captacion.prospectos.acceso.confirmar") }}
            </button>
          </form>

          <form class="grid gap-2" @submit.prevent="anotar">
            <label class="tu-label" for="p-nota">{{
              $t("captacion.prospectos.nota")
            }}</label>
            <textarea
              id="p-nota"
              v-model="nota"
              class="tu-input"
              rows="2"
              maxlength="1000"
              :placeholder="$t('captacion.prospectos.notaPlaceholder')"
            />
            <button
              type="submit"
              class="tu-btn justify-self-start"
              :disabled="accionando || nota.trim() === ''"
            >
              {{ $t("captacion.prospectos.acciones.anotar") }}
            </button>
          </form>
        </template>
        <button
          v-else-if="puedeGestionar && actual.estado === 'descartado'"
          type="button"
          class="tu-btn justify-self-start"
          :disabled="accionando"
          @click="cambiarEstado('en_seguimiento')"
        >
          {{ $t("captacion.prospectos.acciones.reabrir") }}
        </button>

        <section v-if="actual.eventos && actual.eventos.length > 0">
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.historial") }}
          </h3>
          <ol class="grid gap-2">
            <li v-for="(e, i) in actual.eventos" :key="i">
              <span>{{ textoEvento(e) }}</span>
              <span class="tu-sub">
                {{ fecha(e.en, true)
                }}<template v-if="e.autor"> · {{ e.autor }}</template>
              </span>
            </li>
          </ol>
        </section>

        <button
          v-if="puedeEliminar"
          type="button"
          class="tu-enlace justify-self-start"
          style="color: var(--error)"
          @click="eliminar"
        >
          {{ $t("captacion.prospectos.acciones.eliminar") }}
        </button>
      </div>
    </PanelLateral>

    <!-- Alta manual -->
    <PanelLateral
      :abierto="alta.abierto"
      :titulo="$t('captacion.prospectos.alta.titulo')"
      @cerrar="alta.abierto = false"
    >
      <form class="grid gap-4" @submit.prevent="crear">
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.nombre")
          }}</span>
          <input
            v-model="alta.nombre"
            class="tu-input"
            required
            maxlength="160"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.email")
          }}</span>
          <input
            v-model="alta.email"
            class="tu-input"
            type="email"
            maxlength="190"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.telefono")
          }}</span>
          <input
            v-model="alta.telefono"
            class="tu-input"
            type="tel"
            maxlength="40"
          />
        </label>
      </form>
      <template #pie>
        <div class="flex justify-end">
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="accionando"
            @click="crear"
          >
            {{ $t("captacion.prospectos.alta.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.prospecto-fila {
  display: flex;
  width: 100%;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  text-align: left;
}
.prospecto-fila:hover,
.prospecto-fila:focus-visible {
  background: var(--superficie-2);
}
.prospecto-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.15rem;
  flex-shrink: 0;
  text-align: right;
}
.tu-sub {
  display: block;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.tu-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: var(--superficie);
}
.tu-chip[aria-pressed="true"] {
  border-color: var(--acento);
}
</style>
