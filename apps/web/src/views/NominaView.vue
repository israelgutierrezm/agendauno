<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais, simboloMoneda } from "@/lib/formato";
import { nombreDeRol } from "@/lib/roles";
import { plural } from "@/lib/terminologia";
import { hoyComoFecha } from "@/lib/hoyNegocio";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Nómina del equipo: el esquema de pago de cada quien (por clase o cita, por
 * asistente o por hora) y lo que le toca en un periodo (al abrir, el mes en curso).
 * Quien no tiene esquema aparece sin él, para definirlo desde su fila.
 *
 * Para pagar (ADR 0153): lo devengado es solo lo ya impartido; lo que falta del
 * periodo se ve aparte como «por venir» (una proyección). Cada sesión se paga con la
 * tarifa vigente su día: si cambió en el periodo, la fila lo dice.
 */
interface Persona {
  id: string;
  nombre: string;
  rol: string;
  foto_url?: string | null;
}
type TipoPago = "por_clase" | "por_asistente" | "por_hora";
interface FilaNomina {
  usuario_id?: string | null;
  usuario: string | null;
  tipo: TipoPago;
  unidades: number;
  monto_minor?: number;
  // Lo ya impartido (devengado).
  monto_total_minor: number;
  // Lo que falta del periodo (proyección).
  unidades_por_venir?: number;
  por_venir_minor?: number;
  // Desde cuándo vale esa tarifa (null = desde siempre).
  vigente_desde?: string | null;
  moneda: string;
}
interface FilaVista {
  id: string | null;
  nombre: string;
  rol: string | null;
  foto: string | null;
  // Su tarifa vigente (la última del periodo) y, si cambió, una fila por tarifa.
  fila: FilaNomina | null;
  filas: FilaNomina[];
  devengado: number;
  porVenir: number;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const TIPOS: { valor: TipoPago; icono: string }[] = [
  { valor: "por_clase", icono: "agenda" },
  { valor: "por_asistente", icono: "personas" },
  { valor: "por_hora", icono: "reloj" },
];
const TONO_TIPO: Record<TipoPago, string> = {
  por_clase: "tu-tono-azul",
  por_asistente: "tu-tono-morado",
  por_hora: "tu-tono-naranja",
};

const equipo = ref<Persona[]>([]);
const nombresRoles = ref<Record<string, string>>({});
const cargando = ref(true);
const error = ref<string | null>(null);

const esquema = ref<{ usuarioId: string; tipo: TipoPago; monto: string }>({
  usuarioId: "",
  tipo: "por_clase",
  monto: "",
});
const editorEsquema = ref(false);
// El patrón común de los formularios: errores junto al campo, no se cierra mientras
// guarda, pregunta antes de descartar y confirma lo guardado.
const {
  guardando,
  errores,
  error: errorEsquema,
  empezar,
  cerrar: cerrarEsquema,
  marcar,
  guardar: guardarConEditor,
} = useEditorFormulario({
  abierto: editorEsquema,
  valor: () => esquema.value,
  campos: ["tipo", "monto_minor"],
  formulario: '[data-prueba="editor-esquema"]',
});
function abrirEsquema(): void {
  esquema.value = { usuarioId: "", tipo: "por_clase", monto: "" };
  empezar();
  editorEsquema.value = true;
}
const nombreEsquema = computed(
  () =>
    equipo.value.find((u) => u.id === esquema.value.usuarioId)?.nombre ?? "",
);

function iso(d: Date): string {
  const p = (n: number): string => (n < 10 ? `0${n}` : `${n}`);
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
// El mes en curso del negocio (su zona horaria).
const hoy = hoyComoFecha(sesion.zonaHoraria);
const periodo = ref({
  desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)),
  hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)),
});
// Periodo de lo que se ve en el resumen (el calculado, no el que se está editando).
const periodoCalculado = ref({ ...periodo.value });
const filas = ref<FilaNomina[]>([]);
const calculando = ref(false);
const calculado = ref(false);

const termino = computed(() => sesion.terminologia.sesion.toLowerCase());

// En la moneda del negocio (ADR 0099) y con los números de su país.
function dinero(minor: number, moneda = sesion.moneda): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}
// Junto al monto del esquema: el símbolo de la moneda del negocio.
const simbolo = computed(() => simboloMoneda(sesion.moneda, sesion.pais));
function fechaCorta(isoFecha: string): string {
  const [a, m, d] = isoFecha.split("-");
  return `${d}/${m}/${a}`;
}
function etiquetaTipo(tipo: TipoPago): string {
  if (tipo === "por_clase") {
    return t("nominaVisual.porSesion", { sesion: termino.value });
  }
  return tipo === "por_asistente"
    ? t("nomina.tipoPorAsistente")
    : t("nomina.tipoPorHora");
}
function cantidad(f: FilaNomina): string {
  const n = Number.isInteger(f.unidades)
    ? f.unidades
    : Math.round(f.unidades * 10) / 10;
  if (f.tipo === "por_clase") {
    return `${n} ${n === 1 ? termino.value : plural(termino.value)}`;
  }
  return f.tipo === "por_asistente"
    ? t("nominaVisual.unidades.asistentes", { n }, n === 1 ? 1 : 2)
    : t("nominaVisual.unidades.horas", { n }, n === 1 ? 1 : 2);
}
function rolDe(rol: string | null): string {
  if (rol === null) {
    return "";
  }
  if (rol === "instructor") {
    return sesion.terminologia.instructor;
  }
  if (nombresRoles.value[rol]) return nombresRoles.value[rol];
  return nombreDeRol(rol, undefined, (llave) => {
    const texto = t(llave);
    return texto === llave ? null : texto;
  });
}

// El equipo (sin clientes) con lo que le toca; quien tiene esquema pero ya no está
// en el equipo (p. ej. dado de baja) también se ve.
function sumas(suyas: FilaNomina[]): {
  fila: FilaNomina | null;
  filas: FilaNomina[];
  devengado: number;
  porVenir: number;
} {
  return {
    fila: suyas.at(-1) ?? null,
    filas: suyas,
    devengado: suyas.reduce((s, f) => s + f.monto_total_minor, 0),
    porVenir: suyas.reduce((s, f) => s + (f.por_venir_minor ?? 0), 0),
  };
}
const filasVista = computed<FilaVista[]>(() => {
  const usadas = new Set<FilaNomina>();
  const deEquipo = equipo.value.map((u) => {
    let suyas = filas.value.filter((f) => f.usuario_id === u.id);
    if (suyas.length === 0) {
      suyas = filas.value.filter(
        (f) => !f.usuario_id && f.usuario === u.nombre,
      );
    }
    suyas.forEach((f) => usadas.add(f));
    return {
      id: u.id,
      nombre: u.nombre,
      rol: u.rol,
      foto: u.foto_url ?? null,
      ...sumas(suyas),
    };
  });
  // Quien tiene esquema pero ya no está en el equipo, una fila por persona.
  const otras = new Map<string, FilaNomina[]>();
  for (const f of filas.value.filter((x) => !usadas.has(x))) {
    const clave = f.usuario_id ?? f.usuario ?? "—";
    otras.set(clave, [...(otras.get(clave) ?? []), f]);
  }
  return [
    ...deEquipo,
    ...[...otras.values()].map((suyas) => ({
      id: suyas[0]!.usuario_id ?? null,
      nombre: suyas[0]!.usuario ?? "—",
      rol: null,
      foto: null,
      ...sumas(suyas),
    })),
  ];
});
// Lo impartido de alguien: si su tarifa cambió de tipo en el periodo, cada una aparte.
function cantidadDe(x: FilaVista, porVenir = false): string {
  const unidades = (f: FilaNomina): number =>
    porVenir ? (f.unidades_por_venir ?? 0) : f.unidades;
  const tipos = new Set(x.filas.map((f) => f.tipo));
  if (tipos.size <= 1 && x.fila) {
    return cantidad({
      ...x.fila,
      unidades: x.filas.reduce((s, f) => s + unidades(f), 0),
    });
  }
  return x.filas
    .map((f) => cantidad({ ...f, unidades: unidades(f) }))
    .join(" + ");
}
// Desde cuándo vale su tarifa, si cambió dentro del periodo.
function cambioDeTarifa(x: FilaVista): string | null {
  const desde = x.filas.length > 1 ? x.fila?.vigente_desde : null;
  return desde
    ? t("nominaVisual.tarifaDesde", { fecha: fechaCorta(desde) })
    : null;
}
const conEsquema = computed(
  () => filasVista.value.filter((x) => x.fila !== null).length,
);
const sinEsquema = computed(
  () => filasVista.value.filter((x) => x.fila === null).length,
);
const total = computed(() =>
  filas.value.reduce((s, f) => s + f.monto_total_minor, 0),
);
const totalPorVenir = computed(() =>
  filas.value.reduce((s, f) => s + (f.por_venir_minor ?? 0), 0),
);
const moneda = computed(() => filas.value[0]?.moneda ?? sesion.moneda);

const indicadores = computed<Indicador[]>(() => [
  {
    clave: "con",
    etiqueta: t("nominaVisual.kpi.conEsquema"),
    valor: t("nominaVisual.kpi.conEsquemaValor", {
      n: conEsquema.value,
      total: filasVista.value.length,
    }),
    icono: "personas",
    tono: "azul",
  },
  {
    clave: "sin",
    etiqueta: t("nominaVisual.kpi.sinEsquema"),
    valor: String(sinEsquema.value),
    icono: "reloj",
    tono: "naranja",
    aviso: sinEsquema.value > 0,
  },
  {
    clave: "total",
    etiqueta: t("nominaVisual.kpi.total"),
    valor: calculado.value ? dinero(total.value, moneda.value) : "—",
    icono: "dinero",
    tono: "verde",
  },
  {
    clave: "alCierre",
    etiqueta: t("nominaVisual.kpi.alCierre"),
    valor: calculado.value
      ? dinero(total.value + totalPorVenir.value, moneda.value)
      : "—",
    icono: "agenda",
    tono: "azul",
  },
]);

async function cargarEquipo(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: Persona[];
      roles_detalle?: { clave: string; nombre: string | null }[];
    }>(`${base.value}/usuarios`, {
      // Solo el equipo: sin alcance llegarían también todas las cuentas de clientes.
      params: { alcance: "equipo" },
    });
    nombresRoles.value = Object.fromEntries(
      (data.roles_detalle ?? [])
        .filter((r) => r.nombre)
        .map((r) => [r.clave, r.nombre as string]),
    );
    // La nómina es del equipo: los clientes con cuenta no cobran.
    equipo.value = data.data.filter((u) => u.rol !== "miembro");
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function calcular(): Promise<void> {
  calculando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: FilaNomina[] }>(
      `${base.value}/nomina`,
      { params: periodo.value },
    );
    filas.value = data.data;
    periodoCalculado.value = { ...periodo.value };
    calculado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    calculando.value = false;
  }
}

async function guardarEsquema(): Promise<void> {
  if (guardando.value || !esquema.value.usuarioId || esquema.value.monto === "")
    return;
  const listo = await guardarConEditor(
    () =>
      api.put(`${base.value}/staff/${esquema.value.usuarioId}/esquema-pago`, {
        tipo: esquema.value.tipo,
        monto_minor: Math.round(Number(esquema.value.monto) * 100),
        moneda: sesion.moneda,
      }),
    t("nominaVisual.esquemaGuardado", { nombre: nombreEsquema.value }),
  );
  if (listo !== undefined) {
    editorEsquema.value = false;
    await calcular();
  }
}

// Desde la fila: el formulario con su esquema (o vacío si no tiene) y a la vista.
function editar(x: FilaVista): void {
  if (x.id === null) {
    return;
  }
  esquema.value = {
    usuarioId: x.id,
    tipo: x.fila?.tipo ?? "por_clase",
    monto:
      x.fila?.monto_minor !== undefined ? String(x.fila.monto_minor / 100) : "",
  };
  empezar();
  editorEsquema.value = true;
}

// CSV del resumen (abre en Excel: BOM UTF-8 y separador coma).
function exportar(): void {
  const celda = (v: string): string => `"${v.replaceAll('"', '""')}"`;
  const lineas = [
    [
      t("nominaVisual.colMiembro"),
      t("nominaVisual.colEsquema"),
      t("nominaVisual.colCantidad"),
      t("nominaVisual.colTotal"),
      t("nominaVisual.colPorVenir"),
    ],
    ...filasVista.value
      .filter((x) => x.fila !== null)
      .map((x) => [
        x.nombre,
        etiquetaTipo(x.fila!.tipo),
        cantidadDe(x),
        (x.devengado / 100).toFixed(2),
        (x.porVenir / 100).toFixed(2),
      ]),
  ].map((l) => l.map(celda).join(","));
  const blob = new Blob(["\uFEFF" + lineas.join("\r\n")], {
    type: "text/csv;charset=utf-8",
  });
  const enlace = document.createElement("a");
  enlace.href = URL.createObjectURL(blob);
  enlace.download = `nomina_${periodoCalculado.value.desde}_${periodoCalculado.value.hasta}.csv`;
  enlace.click();
  URL.revokeObjectURL(enlace.href);
}

onMounted(async () => {
  await Promise.all([cargarEquipo(), calcular()]);
  cargando.value = false;
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('nomina.titulo')"
      :subtitulo="$t('nominaVisual.subtitulo')"
    >
      <template #acciones
        ><button
          type="button"
          class="tu-btn tu-btn-primario"
          @click="abrirEsquema"
        >
          {{ $t("adminUsabilidad.nomina.definir") }}
        </button></template
      >
    </EncabezadoSeccion>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div class="nm-arriba mt-6">
      <div class="space-y-5 min-w-0">
        <!-- Esquema de pago -->
        <ModalDialogo
          :abierto="editorEsquema"
          :titulo="
            nombreEsquema
              ? $t('adminUsabilidad.nomina.editar', { nombre: nombreEsquema })
              : $t('adminUsabilidad.nomina.definir')
          "
          :descripcion="$t('adminUsabilidad.nomina.ayuda')"
          icono="nomina"
          :ocupado="guardando"
          @cerrar="cerrarEsquema"
        >
          <form
            data-prueba="editor-esquema"
            class="space-y-4"
            @submit.prevent="guardarEsquema"
          >
            <AvisoTono v-if="errorEsquema" tono="peligro" role="alert">
              {{ errorEsquema }}
            </AvisoTono>
            <header class="nm-cabeza">
              <span class="tu-icono-tono tu-tono-azul">
                <IconoNav nombre="nomina" :tam="20" />
              </span>
              <span>
                <h2 class="font-semibold">{{ $t("nomina.esquemas") }}</h2>
                <p class="nm-sub">{{ $t("nominaVisual.esquemasAyuda") }}</p>
              </span>
            </header>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
              <div>
                <label class="tu-label" for="es">{{
                  $t("nominaVisual.quien")
                }}</label>
                <label class="tu-select-icono flex">
                  <IconoNav nombre="personas" :tam="16" />
                  <select
                    id="es"
                    v-bind="marcar('usuario_id', 'es-error')"
                    v-model="esquema.usuarioId"
                    class="tu-input"
                    required
                  >
                    <option value="" disabled>
                      {{ $t("nominaVisual.elegir") }}
                    </option>
                    <option v-for="u in equipo" :key="u.id" :value="u.id">
                      {{ u.nombre }}
                    </option>
                  </select>
                  <span
                    v-if="errores.usuario_id"
                    id="es-error"
                    class="tu-error-campo"
                    >{{ errores.usuario_id }}</span
                  >
                </label>
              </div>
              <div>
                <label class="tu-label" for="em">{{
                  $t("nomina.monto")
                }}</label>
                <div class="nm-monto">
                  <span aria-hidden="true" data-prueba="simbolo-monto">{{
                    simbolo
                  }}</span>
                  <input
                    id="em"
                    v-bind="marcar('monto_minor', 'em-error')"
                    v-model="esquema.monto"
                    class="tu-input"
                    type="number"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0.00"
                    required
                  />
                  <span
                    v-if="errores.monto_minor"
                    id="em-error"
                    class="tu-error-campo"
                    >{{ errores.monto_minor }}</span
                  >
                </div>
                <p class="tu-hint">
                  {{
                    $t(`nominaVisual.ayudaMonto.${esquema.tipo}`, {
                      sesion: termino,
                    })
                  }}
                </p>
              </div>
            </div>

            <fieldset class="mt-4">
              <legend class="tu-label">{{ $t("nominaVisual.tipo") }}</legend>
              <div class="nm-chips">
                <label
                  v-for="x in TIPOS"
                  :key="x.valor"
                  class="nm-chip"
                  :class="{ 'nm-chip-activo': esquema.tipo === x.valor }"
                >
                  <input
                    v-model="esquema.tipo"
                    type="radio"
                    name="tipo-esquema"
                    :value="x.valor"
                    class="sr-only"
                  />
                  <IconoNav :nombre="x.icono" :tam="16" />
                  {{ etiquetaTipo(x.valor) }}
                </label>
              </div>
            </fieldset>
            <!-- Cambiar la tarifa no recalcula lo ya trabajado (ADR 0153). -->
            <p class="tu-hint mt-3">{{ $t("nominaVisual.cambioDesdeHoy") }}</p>

            <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="guardando"
                @click="cerrarEsquema"
              >
                {{ $t("comun.cancelar") }}
              </button>
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="
                  guardando || esquema.usuarioId === '' || esquema.monto === ''
                "
              >
                {{ $t("nomina.guardarEsquema") }}
              </button>
            </div>
          </form>
        </ModalDialogo>

        <!-- Periodo -->
        <div class="tu-card p-5">
          <header class="nm-cabeza">
            <span class="tu-icono-tono tu-tono-azul">
              <IconoNav nombre="agenda" :tam="20" />
            </span>
            <span>
              <h2 class="font-semibold">{{ $t("nomina.periodo") }}</h2>
              <p class="nm-sub">{{ $t("nominaVisual.periodoAyuda") }}</p>
            </span>
          </header>
          <form
            class="mt-4 flex flex-wrap items-end gap-3"
            @submit.prevent="calcular"
          >
            <div>
              <label class="tu-label" for="nd">{{ $t("nomina.desde") }}</label>
              <input
                id="nd"
                v-model="periodo.desde"
                class="tu-input w-auto"
                type="date"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="nh">{{ $t("nomina.hasta") }}</label>
              <input
                id="nh"
                v-model="periodo.hasta"
                class="tu-input w-auto"
                type="date"
                :min="periodo.desde"
                required
              />
            </div>
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="calculando"
            >
              {{ $t("nomina.calcular") }}
            </button>
          </form>
        </div>
      </div>

      <!-- Indicadores -->
      <TarjetasIndicadores class="nm-kpis" :tarjetas="indicadores" />
    </div>

    <!-- Resumen del periodo -->
    <div class="tu-card mt-5 p-5">
      <header class="nm-cabeza flex-wrap">
        <span class="tu-icono-tono tu-tono-azul">
          <IconoNav nombre="reportes" :tam="20" />
        </span>
        <span class="min-w-0 flex-1">
          <h2 class="font-semibold">{{ $t("nominaVisual.resumen") }}</h2>
          <p class="nm-sub">{{ $t("nominaVisual.resumenAyuda") }}</p>
        </span>
        <span class="flex flex-wrap items-center gap-3">
          <span v-if="calculado" class="nm-sub tabular-nums">{{
            $t("nominaVisual.periodoDe", {
              desde: fechaCorta(periodoCalculado.desde),
              hasta: fechaCorta(periodoCalculado.hasta),
            })
          }}</span>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="conEsquema === 0"
            @click="exportar"
          >
            <IconoNav nombre="abajo" :tam="16" />
            {{ $t("nominaVisual.exportar") }}
          </button>
        </span>
      </header>

      <p v-if="cargando" class="mt-4 nm-sub">{{ $t("comun.cargando") }}</p>
      <EstadoVacio
        v-else-if="filasVista.length === 0"
        class="py-6"
        icono="nomina"
        compacto
        :titulo="
          calculado ? $t('nominaVisual.vacio') : $t('nominaVisual.sinCalcular')
        "
      />
      <!-- relative: lo oculto para lectores de pantalla no ensancha la página. -->
      <div v-else class="relative mt-4 overflow-x-auto">
        <table class="nm-tabla">
          <thead>
            <tr>
              <th>{{ $t("nominaVisual.colMiembro") }}</th>
              <th>{{ $t("nominaVisual.colEsquema") }}</th>
              <th>{{ $t("nominaVisual.colCantidad") }}</th>
              <th class="text-right">{{ $t("nominaVisual.colTotal") }}</th>
              <th class="text-right">{{ $t("nominaVisual.colPorVenir") }}</th>
              <th>{{ $t("nominaVisual.colEstado") }}</th>
              <th>
                <span class="sr-only">{{
                  $t("nominaVisual.editarEsquema")
                }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(x, i) in filasVista" :key="x.id ?? `f${i}`">
              <td>
                <span class="flex items-center gap-3">
                  <AvatarIniciales :nombre="x.nombre" :foto="x.foto" tam="sm" />
                  <span class="min-w-0">
                    <span class="block truncate font-medium">{{
                      x.nombre
                    }}</span>
                    <span v-if="x.rol" class="block nm-sub">{{
                      rolDe(x.rol)
                    }}</span>
                  </span>
                </span>
              </td>
              <td>
                <span
                  v-if="x.fila"
                  class="nm-pastilla"
                  :class="TONO_TIPO[x.fila.tipo]"
                  >{{ etiquetaTipo(x.fila.tipo) }}</span
                >
                <span v-else class="nm-sub">—</span>
                <span
                  v-if="cambioDeTarifa(x)"
                  class="block nm-sub mt-1"
                  data-prueba="cambio-tarifa"
                  >{{ cambioDeTarifa(x) }}</span
                >
              </td>
              <td class="tabular-nums">
                {{ x.fila ? cantidadDe(x) : "—" }}
              </td>
              <td class="text-right font-semibold tabular-nums">
                {{ x.fila ? dinero(x.devengado, x.fila.moneda) : "—" }}
              </td>
              <td class="text-right tabular-nums nm-sub">
                <template v-if="x.fila && x.porVenir > 0">
                  {{ dinero(x.porVenir, x.fila.moneda) }}
                  <span class="block">{{ cantidadDe(x, true) }}</span>
                </template>
                <template v-else>—</template>
              </td>
              <td>
                <span
                  :class="x.fila ? 'tu-badge-exito' : 'tu-badge-aviso'"
                  class="tu-badge"
                  >{{
                    x.fila
                      ? $t("nominaVisual.calculado")
                      : $t("nominaVisual.sinEsquema")
                  }}</span
                >
              </td>
              <td class="text-right">
                <button
                  v-if="x.id !== null"
                  type="button"
                  class="tu-enlace text-sm whitespace-nowrap"
                  @click="editar(x)"
                >
                  {{
                    x.fila
                      ? $t("nominaVisual.editarEsquema")
                      : $t("nominaVisual.definirEsquema")
                  }}
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="conEsquema > 0">
            <tr>
              <td colspan="3" class="font-semibold">
                {{ $t("nomina.totalPeriodo") }}
              </td>
              <td class="text-right font-semibold tabular-nums">
                {{ dinero(total, moneda) }}
              </td>
              <td class="text-right tabular-nums nm-sub">
                {{ totalPorVenir > 0 ? dinero(totalPorVenir, moneda) : "—" }}
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Arriba: lo que se edita a la izquierda y los indicadores a la derecha. */
.nm-arriba {
  display: grid;
  gap: 1.25rem;
}
@media (min-width: 1024px) {
  .nm-arriba {
    grid-template-columns: minmax(0, 1fr) 19rem;
    align-items: start;
  }
}
.nm-cabeza {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}
.nm-sub {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
/* Monto con el signo de pesos pegado al campo. */
.nm-monto {
  display: flex;
  align-items: stretch;
}
.nm-monto > span {
  display: inline-flex;
  align-items: center;
  padding: 0 0.85rem;
  border: 1px solid var(--borde);
  border-right: 0;
  border-radius: 0.75rem 0 0 0.75rem;
  background: var(--superficie-2);
  color: var(--texto-suave);
}
.nm-monto > input {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
.nm-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.nm-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 0.95rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: var(--superficie);
  color: var(--texto);
  font-size: 0.875rem;
  cursor: pointer;
}
.nm-chip:hover {
  border-color: color-mix(in srgb, var(--primario) 40%, var(--borde));
}
.nm-chip:focus-within {
  outline: 2px solid color-mix(in srgb, var(--primario) 45%, transparent);
  outline-offset: 1px;
}
.nm-chip-activo {
  border-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario);
  font-weight: 500;
}
.nm-tabla {
  width: 100%;
  min-width: 44rem;
  font-size: 0.875rem;
  border-collapse: collapse;
}
.nm-tabla th {
  padding: 0.6rem 0.75rem;
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-weight: 500;
  text-align: left;
}
/* Sin capa, el text-align de arriba anularía la utilidad text-right. */
.nm-tabla th.text-right {
  text-align: right;
}
.nm-tabla th:first-child {
  border-radius: 0.6rem 0 0 0.6rem;
}
.nm-tabla th:last-child {
  border-radius: 0 0.6rem 0.6rem 0;
}
.nm-tabla td {
  padding: 0.7rem 0.75rem;
  border-bottom: 1px solid var(--borde);
}
.nm-tabla tfoot td {
  border-bottom: 0;
}
.nm-pastilla {
  display: inline-block;
  padding: 0.2rem 0.65rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--tono) 12%, transparent);
  color: var(--tono);
  font-size: 0.8rem;
  font-weight: 500;
  white-space: nowrap;
}
</style>
