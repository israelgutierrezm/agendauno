<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Comunicación con los alumnos: difusiones a un segmento, mensajes automáticos
 * que se disparan con los eventos del negocio (plantillas por evento y canal) y la
 * bandeja de salida con el estado de cada mensaje.
 */
const { t } = useI18n();
const toast = useToastStore();

interface Segmento {
  clave: string;
  etiqueta: string;
  descripcion: string;
  total: number;
}
interface Difusion {
  id: string;
  segmento: string;
  segmento_etiqueta: string;
  canal: string;
  asunto: string;
  total: number;
  enviada_en: string | null;
}

type Canal = "interno" | "email" | "push" | "whatsapp";
// A quién va el mensaje automático: la persona del evento, el profesional de la cita
// o el equipo del negocio (a quien puede atenderlo).
type Destinatario = "persona" | "profesional" | "equipo";
interface Plantilla {
  id: string;
  clave: string;
  canal: Canal;
  destinatario: Destinatario;
  asunto: string;
  cuerpo: string;
  activo: boolean;
}
interface Mensaje {
  id: string;
  persona: string | null;
  canal: Canal;
  destinatario: string | null;
  asunto: string;
  estado: "encolado" | "enviado" | "fallido" | "descartado";
  intentos: number;
  enviado_en: string | null;
  // Lo que Meta avisa de un WhatsApp (ADR 0074).
  entregado_en?: string | null;
  leido_en?: string | null;
  ultimo_error?: string | null;
}
type Pestana = "difusion" | "automaticos" | "salida";

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeAutomaticos = computed(() =>
  sesion.puede("comunicaciones.gestionar"),
);
const puedeSalida = computed(() => sesion.puede("comunicaciones.ver"));
const puedeEliminar = computed(() => sesion.puede("comunicaciones.eliminar"));
const pestana = ref<Pestana>("difusion");

const segmentos = ref<Segmento[]>([]);
const difusiones = ref<Difusion[]>([]);
const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
const exito = ref<number | null>(null);

const form = ref({
  segmento: "",
  canal: "interno" as Canal,
  asunto: "",
  cuerpo: "",
});

const segmentoSel = computed(() =>
  segmentos.value.find((s) => s.clave === form.value.segmento),
);
const destinatarios = computed(() => segmentoSel.value?.total ?? 0);

// Antes de un envío masivo: a cuántos, por qué canal y cómo se verá (revisión).
const revisando = ref(false);
const EJEMPLO: Record<string, string> = {
  persona_nombre: "Ana López",
  persona_email: "ana@correo.mx",
};
function conEjemplo(texto: string): string {
  return texto.replace(
    /\{\{\s*(persona_nombre|persona_email)\s*\}\}/g,
    (_, clave: string) => EJEMPLO[clave] ?? "",
  );
}
const puedeEnviar = computed(
  () =>
    form.value.segmento !== "" &&
    form.value.asunto.trim() !== "" &&
    form.value.cuerpo.trim() !== "",
);

// Ayuda de marcadores: se arma en el script para no meter `{{ }}` en el template
// (Vue lo interpretaría como interpolación anidada). El backend reemplaza estos
// tokens por los datos de cada alumno.
const marcadoresTexto = computed(() =>
  t("comunicaciones.marcadores", {
    a: "{{persona_nombre}}",
    b: "{{persona_email}}",
  }),
);

// Un WhatsApp enviado dice además si llegó o si ya lo leyeron.
function estadoDeMensaje(m: Mensaje): string {
  if (m.estado === "enviado" && m.leido_en) {
    return t("comunicacionesAuto.estados.leido");
  }
  if (m.estado === "enviado" && m.entregado_en) {
    return t("comunicacionesAuto.estados.entregado");
  }
  return t(`comunicacionesAuto.estados.${m.estado}`);
}

function canalTexto(canal: string): string {
  if (canal === "push") {
    return t("comunicacionesAuto.canalPush");
  }
  if (canal === "whatsapp") {
    return t("comunicacionesAuto.canalWhatsApp");
  }
  return canal === "email"
    ? t("comunicaciones.canalEmail")
    : t("comunicaciones.canalInterno");
}

// Canales que ofrece el servidor: push solo si la plataforma tiene FCM configurado.
const canalesDifusion = ref<Canal[]>(["interno", "email"]);
const canalesAuto = ref<Canal[]>(["interno", "email"]);

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [seg, dif] = await Promise.all([
      api.get<{ data: Segmento[]; canales?: Canal[] }>(
        `${base.value}/comunicaciones/segmentos`,
      ),
      api.get<{ data: Difusion[] }>(`${base.value}/comunicaciones/difusiones`),
    ]);
    segmentos.value = seg.data.data;
    canalesDifusion.value = seg.data.canales ?? ["interno", "email"];
    difusiones.value = dif.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function enviar(): Promise<void> {
  if (!puedeEnviar.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  exito.value = null;
  try {
    const { data } = await api.post<{ data: Difusion }>(
      `${base.value}/comunicaciones/difusiones`,
      { ...form.value },
    );
    exito.value = data.data.total;
    revisando.value = false;
    form.value.asunto = "";
    form.value.cuerpo = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}

// ---- Mensajes automáticos (plantillas por evento y canal) ----
const plantillas = ref<Plantilla[]>([]);
const eventos = ref<string[]>([]);
const editor = ref<{ abierto: boolean; clave: string }>({
  abierto: false,
  clave: "",
});
const borrador = ref<{
  destinatario: Destinatario;
  canal: Canal;
  asunto: string;
  cuerpo: string;
  activo: boolean;
}>({
  destinatario: "persona",
  canal: "interno",
  asunto: "",
  cuerpo: "",
  activo: true,
});

// WhatsApp (si la plataforma lo encendió): el texto fijo de cada aviso que lo admite.
const textosWhatsApp = ref<Record<string, string>>({});
// Al equipo se le avisa por correo o en la app (no tiene bandeja en la app). WhatsApp
// solo al cliente y solo en los avisos con plantilla aprobada.
const canalesDelBorrador = computed(() =>
  canalesAuto.value.filter((c) =>
    c === "whatsapp"
      ? borrador.value.destinatario === "persona" &&
        editor.value.clave in textosWhatsApp.value
      : borrador.value.destinatario === "persona" || c !== "interno",
  ),
);
// Qué eventos admiten avisar al profesional de la cita o al equipo (del servidor).
const eventosPorDestinatario = ref<Record<"profesional" | "equipo", string[]>>({
  profesional: [],
  equipo: [],
});
function destinatariosDe(clave: string): Destinatario[] {
  return [
    "persona",
    ...(["profesional", "equipo"] as const).filter((d) =>
      eventosPorDestinatario.value[d].includes(clave),
    ),
  ];
}
function textoDestinatario(d: Destinatario): string {
  return d === "profesional"
    ? t("comunicacionesAuto.paraProfesional")
    : d === "equipo"
      ? t("comunicacionesAuto.paraEquipo")
      : t("comunicacionesAuto.paraPersona");
}
// Lo configurado de un evento, en orden: al alumno, al profesional y al equipo.
function configuradas(clave: string): Plantilla[] {
  return (["persona", "profesional", "equipo"] as const).flatMap((d) =>
    canalesAuto.value
      .map((c) => plantillaDe(clave, c, d))
      .filter((p): p is Plantilla => p !== undefined),
  );
}
const guardandoPlantilla = ref(false);

// Marcadores de los correos que traen datos legibles (confirmación, recordatorios,
// recibo, bienvenida); el resto usa los datos del evento.
const MARCADORES_SESION = [
  "persona_nombre",
  "actividad",
  "fecha",
  "hora",
  "sucursal",
  "con",
  "negocio",
];
const MARCADORES_POR_EVENTO: Record<string, string[]> = {
  "reserva.confirmada": MARCADORES_SESION,
  "reserva.apartada": [...MARCADORES_SESION, "total", "vence", "enlace"],
  "reserva.recordatorio_24h": MARCADORES_SESION,
  "reserva.recordatorio_2h": MARCADORES_SESION,
  "reserva.cancelada": [...MARCADORES_SESION, "credito"],
  "reserva.sesion_cancelada": [...MARCADORES_SESION, "credito", "enlace"],
  "reserva.reprogramada": [...MARCADORES_SESION, "antes_fecha", "antes_hora"],
  "orden.pagada": [
    "persona_nombre",
    "detalle",
    "total",
    "metodo",
    "fecha",
    "folio",
    "negocio",
  ],
  "cuenta.creada": ["persona_nombre", "enlace", "negocio"],
  "membresia.pausada": ["persona_nombre", "producto", "hasta", "negocio"],
  "membresia.reanudada": ["persona_nombre", "producto", "negocio"],
  "membresia.renovacion_proxima": [
    "persona_nombre",
    "producto",
    "fecha",
    "monto",
    "como_pagar",
    "enlace",
    "negocio",
  ],
  "cobro.fallido": [
    "persona_nombre",
    "producto",
    "motivo",
    "enlace",
    "negocio",
  ],
  "pago_automatico.solicitado": [
    "persona_nombre",
    "producto",
    "enlace",
    "negocio",
  ],
};

const marcadoresAuto = computed(() => {
  const propios = MARCADORES_POR_EVENTO[editor.value.clave];
  return propios
    ? t("comunicacionesAuto.marcadoresLista", {
        lista: propios.map((m) => `{{${m}}}`).join(", "),
      })
    : t("comunicacionesAuto.marcadores", {
        a: "{{persona_nombre}}",
        b: "{{persona_email}}",
        c: "{{estado}}",
        d: "{{negocio}}",
      });
});

function plantillaDe(
  clave: string,
  canal: Canal,
  destinatario: Destinatario = "persona",
): Plantilla | undefined {
  return plantillas.value.find(
    (p) =>
      p.clave === clave &&
      p.canal === canal &&
      (p.destinatario ?? "persona") === destinatario,
  );
}

function cargarBorrador(): void {
  const existente = plantillaDe(
    editor.value.clave,
    borrador.value.canal,
    borrador.value.destinatario,
  );
  borrador.value = {
    destinatario: borrador.value.destinatario,
    canal: borrador.value.canal,
    asunto: existente?.asunto ?? "",
    cuerpo: existente?.cuerpo ?? "",
    activo: existente?.activo ?? true,
  };
}

function configurar(clave: string): void {
  editor.value = { abierto: true, clave };
  // Abre en lo que ya esté configurado (p. ej. un aviso que solo va al equipo).
  const primera = configuradas(clave)[0];
  borrador.value.destinatario = primera?.destinatario ?? "persona";
  borrador.value.canal = primera?.canal ?? "interno";
  cargarBorrador();
}

function elegirCanal(canal: Canal): void {
  borrador.value.canal = canal;
  cargarBorrador();
}

// Configurado por WhatsApp: su texto no se edita (lo pone el servidor).
const esWhatsApp = computed(() => borrador.value.canal === "whatsapp");

function elegirDestinatario(destinatario: Destinatario): void {
  borrador.value.destinatario = destinatario;
  const canales = canalesDelBorrador.value;
  if (!canales.includes(borrador.value.canal)) {
    borrador.value.canal =
      canales.find((c) => plantillaDe(editor.value.clave, c, destinatario)) ??
      canales[0] ??
      "email";
  }
  cargarBorrador();
}

async function cargarAutomaticos(): Promise<void> {
  const { data } = await api.get<{
    data: Plantilla[];
    eventos_disponibles: string[];
    canales?: Canal[];
    destinatarios?: Record<"profesional" | "equipo", string[]>;
    whatsapp?: Record<string, string> | null;
  }>(`${base.value}/plantillas-mensaje`);
  plantillas.value = data.data;
  eventos.value = data.eventos_disponibles;
  canalesAuto.value = data.canales ?? ["interno", "email"];
  textosWhatsApp.value = data.whatsapp ?? {};
  eventosPorDestinatario.value = data.destinatarios ?? {
    profesional: [],
    equipo: [],
  };
}

async function guardarPlantilla(): Promise<void> {
  guardandoPlantilla.value = true;
  try {
    await api.put(`${base.value}/plantillas-mensaje`, {
      clave: editor.value.clave,
      ...borrador.value,
    });
    toast.exito(t("comunicacionesAuto.guardado"));
    editor.value.abierto = false;
    await cargarAutomaticos();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoPlantilla.value = false;
  }
}

async function eliminarPlantilla(): Promise<void> {
  const existente = plantillaDe(
    editor.value.clave,
    borrador.value.canal,
    borrador.value.destinatario,
  );
  if (
    existente === undefined ||
    !(await confirmar(t("comunicacionesAuto.confirmarEliminar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/plantillas-mensaje/${existente.id}`);
    toast.exito(t("comunicacionesAuto.eliminado"));
    editor.value.abierto = false;
    await cargarAutomaticos();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---- Bandeja de salida ----
const mensajes = ref<Mensaje[]>([]);
const filtroEstado = ref("");

async function cargarSalida(): Promise<void> {
  const { data } = await api.get<{ data: Mensaje[] }>(
    `${base.value}/mensajes`,
    { params: filtroEstado.value !== "" ? { estado: filtroEstado.value } : {} },
  );
  mensajes.value = data.data;
}

async function irPestana(p: Pestana): Promise<void> {
  pestana.value = p;
  try {
    if (p === "automaticos") {
      await cargarAutomaticos();
    } else if (p === "salida") {
      await cargarSalida();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('comunicaciones.titulo')" />

    <div
      v-if="puedeAutomaticos || puedeSalida"
      class="tu-segmentado mt-6"
      role="group"
    >
      <button
        type="button"
        :aria-pressed="pestana === 'difusion'"
        @click="irPestana('difusion')"
      >
        {{ $t("comunicacionesAuto.difusion") }}
      </button>
      <button
        v-if="puedeAutomaticos"
        type="button"
        :aria-pressed="pestana === 'automaticos'"
        @click="irPestana('automaticos')"
      >
        {{ $t("comunicacionesAuto.automaticos") }}
      </button>
      <button
        v-if="puedeSalida"
        type="button"
        :aria-pressed="pestana === 'salida'"
        @click="irPestana('salida')"
      >
        {{ $t("comunicacionesAuto.salida") }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <template v-if="pestana === 'difusion'">
      <div
        v-if="exito !== null"
        class="mt-4 tu-card p-4 text-sm"
        :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
      >
        {{ $t("comunicaciones.enviada", { n: exito }) }}
      </div>

      <!-- Redactar difusión -->
      <div class="mt-6 tu-card p-6">
        <h3 class="text-sm font-semibold">
          {{ $t("comunicaciones.segmentoLabel") }}
        </h3>
        <p
          v-if="!cargando && segmentos.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comunicaciones.sinSegmento") }}
        </p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <button
            v-for="s in segmentos"
            :key="s.clave"
            type="button"
            class="text-left rounded-2xl border p-4 transition"
            :style="
              form.segmento === s.clave
                ? {
                    borderColor: 'var(--primario)',
                    background: 'var(--superficie-2)',
                  }
                : { borderColor: 'var(--borde)' }
            "
            @click="form.segmento = s.clave"
          >
            <div class="flex items-center justify-between">
              <span class="font-semibold">{{ s.etiqueta }}</span>
              <span class="tu-badge">{{ s.total }}</span>
            </div>
            <span
              class="block text-xs mt-1"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ s.descripcion }}</span
            >
          </button>
        </div>

        <div class="mt-5 flex items-center gap-2">
          <span class="text-sm font-semibold">{{
            $t("comunicaciones.canalLabel")
          }}</span>
          <div class="flex gap-1">
            <button
              v-for="canal in canalesDifusion"
              :key="canal"
              type="button"
              class="tu-badge cursor-pointer"
              :style="
                form.canal === canal
                  ? { background: 'var(--primario)', color: '#fff' }
                  : {}
              "
              @click="form.canal = canal"
            >
              {{ canalTexto(canal) }}
            </button>
          </div>
        </div>

        <label class="block mt-5 text-sm font-semibold">{{
          $t("comunicaciones.asuntoLabel")
        }}</label>
        <input
          v-model="form.asunto"
          type="text"
          maxlength="255"
          class="tu-input mt-1 w-full"
          :placeholder="$t('comunicaciones.asuntoPh')"
        />

        <label class="block mt-4 text-sm font-semibold">{{
          $t("comunicaciones.cuerpoLabel")
        }}</label>
        <textarea
          v-model="form.cuerpo"
          rows="4"
          maxlength="5000"
          class="tu-input mt-1 w-full"
          :placeholder="$t('comunicaciones.cuerpoPh')"
        ></textarea>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ marcadoresTexto }}
        </p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
          <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
            $t("comunicaciones.destinatarios", { n: destinatarios })
          }}</span>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="!puedeEnviar || enviando"
            @click="revisando = true"
          >
            {{ $t("operacion.comunicacion.revisar") }}
          </button>
        </div>
      </div>

      <!-- Revisión antes de enviar: destinatarios, canal y vista previa -->
      <ModalDialogo
        :abierto="revisando"
        :titulo="$t('operacion.comunicacion.confirmarTitulo')"
        tam="md"
        @cerrar="revisando = false"
      >
        <p class="font-medium">
          {{
            $t(
              "operacion.comunicacion.destinatarios",
              { n: destinatarios },
              destinatarios,
            )
          }}
        </p>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ segmentoSel?.etiqueta }} ·
          {{
            $t("operacion.comunicacion.canal", {
              canal: canalTexto(form.canal),
            })
          }}
        </p>
        <p class="mt-5 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.comunicacion.vistaPrevia") }}
        </p>
        <div
          class="mt-1 rounded-xl border p-4"
          :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
        >
          <p class="font-semibold">{{ conEjemplo(form.asunto) }}</p>
          <p class="mt-2 whitespace-pre-line text-sm">
            {{ conEjemplo(form.cuerpo) }}
          </p>
        </div>
        <p
          v-if="destinatarios === 0"
          class="mt-4 text-sm"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ $t("operacion.comunicacion.sinDestinatarios") }}
        </p>
        <div class="mt-6 flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="revisando = false"
          >
            {{ $t("operacion.comunicacion.cancelar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="enviando || destinatarios === 0"
            @click="enviar"
          >
            {{
              enviando
                ? $t("comunicaciones.enviando")
                : $t("operacion.comunicacion.enviar")
            }}
          </button>
        </div>
      </ModalDialogo>

      <!-- Historial -->
      <h3 class="mt-8 text-sm font-semibold">
        {{ $t("comunicaciones.historial") }}
      </h3>
      <p
        v-if="!cargando && difusiones.length === 0"
        class="mt-3 tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comunicaciones.historialVacio") }}
      </p>
      <div
        v-else-if="difusiones.length > 0"
        class="mt-3 tu-card overflow-hidden"
      >
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("comunicaciones.colFecha") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("comunicaciones.colSegmento") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("comunicaciones.colAsunto") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("comunicaciones.colTotal") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="d in difusiones"
              :key="d.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td
                class="px-4 py-2 whitespace-nowrap"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fecha(d.enviada_en) }}
              </td>
              <td class="px-4 py-2">
                <span class="tu-badge">{{ d.segmento_etiqueta }}</span>
                <span
                  class="block text-xs mt-1"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ canalTexto(d.canal) }}</span
                >
              </td>
              <td class="px-4 py-2">{{ d.asunto }}</td>
              <td class="px-4 py-2 text-right font-semibold">{{ d.total }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Mensajes automáticos -->
    <div v-if="pestana === 'automaticos'" class="mt-5 tu-card p-5">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("comunicacionesAuto.ayuda") }}
      </p>
      <ul class="mt-3">
        <li v-for="ev in eventos" :key="ev" class="com-fila text-sm">
          <div class="min-w-0">
            <p class="font-medium">
              {{ $t(`conexiones.webhook.tipos.${ev}`) }}
            </p>
            <p
              class="mt-0.5 flex flex-wrap gap-1.5 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <span
                v-for="p in configuradas(ev)"
                :key="p.id"
                class="tu-badge"
                :class="{ 'tu-badge-exito': p.activo }"
                >{{
                  p.destinatario === "profesional"
                    ? `${$t("comunicacionesAuto.alProfesional")} · ${canalTexto(p.canal)}`
                    : p.destinatario === "equipo"
                      ? `${$t("comunicacionesAuto.alEquipo")} · ${canalTexto(p.canal)}`
                      : canalTexto(p.canal)
                }}</span
              >
              <span v-if="configuradas(ev).length === 0">{{
                $t("comunicacionesAuto.sinConfigurar")
              }}</span>
            </p>
          </div>
          <button
            type="button"
            class="tu-enlace shrink-0"
            @click="configurar(ev)"
          >
            {{ $t("comunicacionesAuto.configurar") }}
          </button>
        </li>
      </ul>
    </div>

    <!-- Bandeja de salida -->
    <div v-if="pestana === 'salida'" class="mt-5 tu-card p-5">
      <select
        v-model="filtroEstado"
        class="tu-input max-w-[180px]"
        @change="cargarSalida"
      >
        <option value="">{{ $t("comunicacionesAuto.todos") }}</option>
        <option
          v-for="e in ['encolado', 'enviado', 'fallido', 'descartado']"
          :key="e"
          :value="e"
        >
          {{ $t(`comunicacionesAuto.estados.${e}`) }}
        </option>
      </select>
      <p
        v-if="mensajes.length === 0"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comunicacionesAuto.salidaVacia") }}
      </p>
      <ul v-else class="mt-2">
        <li v-for="m in mensajes" :key="m.id" class="com-fila text-sm">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ m.asunto }}</p>
            <p
              class="mt-0.5 text-xs truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ m.persona ?? m.destinatario ?? "—" }} ·
              {{ canalTexto(m.canal) }} · {{ fecha(m.enviado_en) }}
            </p>
            <p
              v-if="m.estado === 'fallido' && m.ultimo_error"
              class="mt-0.5 text-xs truncate"
              style="color: var(--error)"
              :title="m.ultimo_error"
              data-prueba="error-mensaje"
            >
              {{ m.ultimo_error }}
            </p>
          </div>
          <span
            class="tu-badge shrink-0"
            :class="{
              'tu-badge-exito': m.estado === 'enviado',
              'tu-badge-aviso': m.estado === 'encolado',
            }"
            >{{ estadoDeMensaje(m) }}</span
          >
        </li>
      </ul>
    </div>

    <PanelLateral
      :abierto="editor.abierto"
      :titulo="
        editor.clave
          ? $t(`conexiones.webhook.tipos.${editor.clave}`)
          : $t('comunicacionesAuto.editor')
      "
      @cerrar="editor.abierto = false"
    >
      <form
        id="form-plantilla"
        class="space-y-4 p-5"
        @submit.prevent="guardarPlantilla"
      >
        <div>
          <span class="tu-label">{{ $t("comunicacionesAuto.para") }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="d in destinatariosDe(editor.clave)"
              :key="d"
              type="button"
              :aria-pressed="borrador.destinatario === d"
              @click="elegirDestinatario(d)"
            >
              {{ textoDestinatario(d) }}
            </button>
          </div>
          <p
            v-if="borrador.destinatario !== 'persona'"
            class="mt-1 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              borrador.destinatario === "equipo"
                ? $t("comunicacionesAuto.ayudaEquipo")
                : $t("comunicacionesAuto.ayudaProfesional")
            }}
          </p>
        </div>
        <div>
          <span class="tu-label">{{ $t("comunicacionesAuto.canal") }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="canal in canalesDelBorrador"
              :key="canal"
              type="button"
              :aria-pressed="borrador.canal === canal"
              @click="elegirCanal(canal)"
            >
              {{ canalTexto(canal) }}
            </button>
          </div>
        </div>
        <div v-if="esWhatsApp" data-prueba="texto-whatsapp">
          <span class="tu-label">{{
            $t("comunicacionesAuto.textoWhatsApp")
          }}</span>
          <p class="com-fijo text-sm">
            {{ textosWhatsApp[editor.clave] }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("comunicacionesAuto.ayudaWhatsApp") }}
          </p>
        </div>
        <div v-if="!esWhatsApp">
          <label class="tu-label" for="pl-asunto">{{
            borrador.canal === "push"
              ? $t("comunicacionesAuto.asuntoPush")
              : $t("comunicacionesAuto.asunto")
          }}</label>
          <input
            id="pl-asunto"
            v-model="borrador.asunto"
            class="tu-input"
            maxlength="255"
            required
          />
        </div>
        <div v-if="!esWhatsApp">
          <label class="tu-label" for="pl-cuerpo">{{
            $t("comunicacionesAuto.cuerpo")
          }}</label>
          <textarea
            id="pl-cuerpo"
            v-model="borrador.cuerpo"
            class="tu-input"
            rows="6"
            maxlength="5000"
            required
          />
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ marcadoresAuto }}
          </p>
          <p
            v-if="borrador.canal === 'push'"
            class="mt-1 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comunicacionesAuto.ayudaPush") }}
          </p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="borrador.activo" type="checkbox" />
          {{ $t("comunicacionesAuto.activo") }}
        </label>
      </form>
      <template #pie>
        <div class="flex flex-col gap-2">
          <button
            type="submit"
            form="form-plantilla"
            class="tu-btn tu-btn-primario w-full"
            :disabled="guardandoPlantilla"
          >
            {{ $t("comunicacionesAuto.guardar") }}
          </button>
          <button
            v-if="puedeEliminar && plantillaDe(editor.clave, borrador.canal)"
            type="button"
            class="tu-btn tu-btn-fantasma w-full"
            style="color: var(--error)"
            @click="eliminarPlantilla"
          >
            {{ $t("comunicacionesAuto.eliminar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.com-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.com-fila:first-child {
  border-top: 0;
}
.com-fijo {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 10px;
  white-space: pre-line;
}
</style>
