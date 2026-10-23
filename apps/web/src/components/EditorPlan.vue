<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import SelectorOpciones from "@/components/SelectorOpciones.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { instantanea } from "@/lib/cambiosPendientes";
import { confirmar } from "@/lib/confirmar";
import type { EstadoEditor } from "@/lib/editorFormulario";
import { dinero } from "@/lib/formato";
import {
  UNIDADES_POR_CLASE,
  fechaLarga,
  vigenteHasta,
  type Plan,
  type TipoPlan,
  type TipoVigencia,
} from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Alta y edición de un plan (ADR 0050): qué es (paquete, membresía, clase suelta,
 * clases extra u otro), su precio, cuántas clases incluye, cuánto dura (con el
 * ejemplo de "hasta cuándo" si se comprara hoy) y para qué clases sirve.
 *
 * Con citas (ADR 0091, 0130) solo hay bonos de sesiones (varias visitas prepagadas):
 * sin membresías ni planes que se renuevan. Un servicio suelto o un combo se dan de
 * alta en Catálogo. Un bono sirve para los servicios que se toman «con bono», que son
 * los que se pueden elegir aquí.
 *
 * Lo básico va a la vista y las condiciones (página, reglas de reserva, permanencia,
 * cuotas y acumulación) plegadas en «Más condiciones», abiertas si el plan ya trae
 * alguna. Guardar y Cancelar van en el pie del diálogo que lo contiene (PlanesView),
 * con `form="form-plan"`: por eso el editor avisa si se puede guardar (`valido`).
 */
const props = defineProps<{ plan: Plan | null }>();
const emit = defineEmits<{
  // Con los avisos de coherencia del plan guardado (no impiden guardarlo).
  guardado: [nombre: string, avisos: string[]];
  // Para su contenedor: no cerrarse a medio guardar, no descartar sin preguntar y
  // desactivar «Guardar» mientras falte algo.
  estado: [e: EstadoEditor];
}>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

interface Opcion {
  id: string;
  nombre: string;
  actividad?: string | null;
  politica_reserva?: string;
}
const ofertas = ref<Opcion[]>([]);
const sucursales = ref<Opcion[]>([]);

const p = props.plan;
// La moneda del plan (o la del negocio, si es nuevo).
const moneda = computed(() => p?.moneda ?? sesion.moneda ?? "MXN");
const form = reactive({
  tipo: (p?.tipo ?? "paquete") as TipoPlan,
  nombre: p?.nombre ?? "",
  precio: p ? String(p.precio_minor / 100) : "",
  clases:
    p?.creditos_incluidos != null
      ? String(p.creditos_incluidos / UNIDADES_POR_CLASE)
      : "8",
  ilimitado: p?.ilimitado ?? false,
  clasesPorMes:
    p?.unidades_por_ciclo != null
      ? String(p.unidades_por_ciclo / UNIDADES_POR_CLASE)
      : "8",
  ciclo: p && p.politica_reset !== "ninguno" ? p.politica_reset : "calendario",
  vigencia: (p?.vigencia_tipo ?? (p ? "ninguna" : "meses")) as
    TipoVigencia | "ninguna",
  cantidad: String(p?.vigencia_cantidad ?? 1),
  todas: (p?.ofertas.length ?? 0) === 0,
  ofertas: new Set(p?.ofertas.map((o) => o.id) ?? []),
  cobertura:
    p?.todas_sucursales === false || p?.sucursal_id ? "seleccionadas" : "todas",
  sucursalIds:
    p?.sucursales?.map((s) => s.id) ?? (p?.sucursal_id ? [p.sucursal_id] : []),
  rollover: p?.politica_rollover ?? "ninguno",
  rolloverMax:
    p?.rollover_max != null ? String(p.rollover_max / UNIDADES_POR_CLASE) : "",
  archivado: p?.archivado ?? false,
  // Para el público (M03/B03): qué incluye en una línea, el destacado y la clase de
  // prueba (solo la compra quien nunca vino; lo valida el servidor).
  descripcion: p?.descripcion ?? "",
  destacado: p?.destacado ?? false,
  primeraVez: p?.primera_vez ?? false,
  // Cuotas del negocio (B04): si al venderlo se suma la inscripción y si quien lo
  // tiene paga la anualidad.
  cobraInscripcion: p?.cobra_inscripcion ?? false,
  cobraAnualidad: p?.cobra_anualidad ?? false,
  // Permanencia de una membresía (B04): meses (vacío = sin) y qué paga si da de baja
  // antes. Lo pactado se congela en cada venta.
  permanencia: p?.permanencia_meses ? String(p.permanencia_meses) : "",
  bajaAnticipada: (p?.baja_anticipada ?? "sin_penalizacion") as
    "sin_penalizacion" | "monto_fijo" | "meses_restantes",
  penalizacion:
    p?.penalizacion_minor != null ? String(p.penalizacion_minor / 100) : "",
  // Con «meses restantes», qué parte de lo que falte (p. ej. 50 %).
  porcentaje: String(p?.penalizacion_porcentaje ?? 100),
  // Planes «socio» (ADR 0182); vacío = sin la regla.
  topeDia: p?.max_reservas_dia ? String(p.max_reservas_dia) : "",
  topeSemana: p?.max_reservas_semana ? String(p.max_reservas_semana) : "",
  anticipado: p?.acceso_anticipado_horas
    ? String(p.acceso_anticipado_horas)
    : "",
  invitados: p?.invitados_por_mes ? String(p.invitados_por_mes) : "",
});
/** Un número entero positivo, o null si está vacío (sin la regla). */
function entero(v: string): number | null {
  const n = numero(v);
  return n === null || n <= 0 ? null : Math.round(n);
}
const guardando = ref(false);
const error = ref<string | null>(null);
// El error de cada campo, junto a él (y el resumen arriba del botón).
const errores = ref<Record<string, string>>({});
const CAMPOS = ["nombre", "precio_minor", "descripcion"];
function marcar(
  campo: string,
  idError: string,
): Record<string, string | undefined> {
  return errores.value[campo]
    ? { "aria-invalid": "true", "aria-describedby": idError }
    : {};
}
// Lo capturado contra lo que se abrió (los conjuntos, como listas ordenadas).
const foto = instantanea(() => ({
  ...form,
  ofertas: [...form.ofertas].sort(),
  sucursalIds: [...form.sucursalIds].sort(),
}));

const esCitas = computed(() => sesion.esCitas === true);
const TIPOS_PRINCIPALES = computed<TipoPlan[]>(() =>
  esCitas.value
    ? ["paquete"]
    : ["paquete", "membresia", "sesion_individual", "add_on"],
);
// Los textos del editor en citas hablan de servicios y sesiones, no de clases.
function tx(clave: string): string {
  return esCitas.value && te(`planes.editorCitas.${clave}`)
    ? t(`planes.editorCitas.${clave}`)
    : t(`planes.editor.${clave}`);
}
function nombreTipo(tipo: TipoPlan): string {
  return esCitas.value && te(`planes.tiposCitas.${tipo}`)
    ? t(`planes.tiposCitas.${tipo}`)
    : t(`planes.tipos.${tipo}`);
}
function ayudaTipo(tipo: TipoPlan): string {
  return esCitas.value && te(`planes.tiposAyudaCitas.${tipo}`)
    ? t(`planes.tiposAyudaCitas.${tipo}`)
    : t(`planes.tiposAyuda.${tipo}`);
}
const esOtro = computed(
  () => form.tipo === "pase_dia" || form.tipo === "taller",
);
const esMembresia = computed(() => form.tipo === "membresia");
const esExtra = computed(() => form.tipo === "add_on");
const esSuelta = computed(() => form.tipo === "sesion_individual");
const sinVigencia = computed(
  () => esExtra.value || esMembresia.value || form.vigencia === "ninguna",
);
// Una clase de prueba es un paquete o una clase suelta, no una cuota ni extras.
const admitePrimeraVez = computed(() => !esMembresia.value && !esExtra.value);
// Topes, acceso anticipado, invitados y cuotas: con clases y sin ser extras.
const admiteReglasSocio = computed(() => !esCitas.value && !esExtra.value);

// Cuántas condiciones avanzadas tiene puestas (solo las que aplican a su tipo): se
// cuentan en «Más condiciones · N activas».
const condicionesActivas = computed(
  () =>
    [
      form.destacado,
      admitePrimeraVez.value && form.primeraVez,
      admiteReglasSocio.value && entero(form.topeDia) !== null,
      admiteReglasSocio.value && entero(form.topeSemana) !== null,
      admiteReglasSocio.value && entero(form.anticipado) !== null,
      admiteReglasSocio.value && entero(form.invitados) !== null,
      esMembresia.value &&
        !esCitas.value &&
        (numero(form.permanencia) ?? 0) > 0,
      admiteReglasSocio.value && form.cobraInscripcion,
      admiteReglasSocio.value && form.cobraAnualidad,
      esMembresia.value && !form.ilimitado && form.rollover !== "ninguno",
    ].filter(Boolean).length,
);
// Abiertas al abrir si el plan ya trae alguna (o está archivado, para reactivarlo).
// Se fija una vez: marcar o desmarcar algo no pliega lo que se está editando.
const abrirAvanzado = condicionesActivas.value > 0 || form.archivado;

function numero(valor: string): number | null {
  const n = Number(String(valor).trim());
  return String(valor).trim() !== "" && Number.isFinite(n) ? n : null;
}

// Ejemplo en vivo: "si se compra hoy, se puede usar hasta el …".
const ejemplo = computed(() => {
  const cantidad = numero(form.cantidad);
  if (form.vigencia === "ninguna" || cantidad === null || cantidad < 1) {
    return null;
  }
  const hoy = new Date();
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  return t("planes.editor.ejemplo", {
    hoy: fechaLarga(iso(hoy)),
    hasta: fechaLarga(vigenteHasta(form.vigencia, cantidad, hoy)),
  });
});

// Con citas, un bono solo sirve para lo que se toma con bono.
const ofertasElegibles = computed(() =>
  esCitas.value
    ? ofertas.value.filter((o) => o.politica_reserva !== "pago")
    : ofertas.value,
);
// Clases agrupadas por actividad para elegir a cuáles aplica.
// Los servicios elegibles, agrupados por actividad en el selector.
const opcionesOfertas = computed(() =>
  ofertasElegibles.value.map((o) => ({
    id: o.id,
    nombre: o.nombre,
    grupo: o.actividad ?? null,
  })),
);

// Antes de guardar, lo que quedará en una sola vista: precio, dónde se usa, para qué
// sirve, qué incluye, cuánto dura y sus condiciones. Lo que falta, marcado.
interface FilaResumen {
  clave: string;
  etiqueta: string;
  valor: string;
  falta?: boolean;
}
function ra(clave: string, n?: number): string {
  return n === undefined
    ? t(`planes.antesDeGuardar.${clave}`)
    : t(`planes.antesDeGuardar.${clave}`, { n }, n);
}
const resumen = computed<FilaResumen[]>(() => {
  const precio = numero(form.precio);
  const clasesN = Math.max(0, Math.round(numero(form.clases) ?? 0));
  const porMesN = Math.max(0, Math.round(numero(form.clasesPorMes) ?? 0));
  const cantidad = Math.max(0, Math.round(numero(form.cantidad) ?? 0));

  const incluye = esMembresia.value
    ? form.ilimitado
      ? t("planes.resumen.ilimitado")
      : t("planes.resumen.porMes", { n: porMesN }, porMesN)
    : esSuelta.value
      ? t("planes.resumen.clases", { n: 1 }, 1)
      : esExtra.value
        ? ra("sumaClases", clasesN)
        : t("planes.resumen.clases", { n: clasesN }, clasesN);

  let vigencia: string;
  if (esExtra.value) {
    vigencia = t("planes.resumen.conElPaquete");
  } else if (esMembresia.value) {
    vigencia = ra("seRenueva");
  } else if (form.vigencia === "dias") {
    vigencia = t("planes.resumen.dias", { n: cantidad }, cantidad);
  } else if (form.vigencia === "meses") {
    vigencia = t("planes.resumen.meses", { n: cantidad }, cantidad);
  } else if (form.vigencia === "fin_de_mes") {
    vigencia = t("planes.resumen.finDeMes", { n: cantidad }, cantidad);
  } else {
    vigencia = t("planes.resumen.sinVencimiento");
  }

  const elegidas = sucursales.value
    .filter((s) => form.sucursalIds.includes(s.id))
    .map((s) => s.nombre);
  const enSucursales = esExtra.value
    ? { valor: ra("comoElPaquete") }
    : form.cobertura === "todas" || sucursales.value.length <= 1
      ? { valor: ra("todasSucursales") }
      : elegidas.length > 0
        ? { valor: elegidas.join(", ") }
        : { valor: ra("ningunaSucursal"), falta: true };

  const ofertasElegidas = ofertasElegibles.value
    .filter((o) => form.ofertas.has(o.id))
    .map((o) => o.nombre);
  const sirvePara = esExtra.value
    ? { valor: ra("mismasDelPaquete") }
    : form.todas
      ? { valor: tx("todas") }
      : ofertasElegidas.length > 0
        ? { valor: ofertasElegidas.join(", ") }
        : { valor: tx("eligeClases"), falta: true };

  const condiciones: string[] = [];
  if (admitePrimeraVez.value && form.primeraVez) {
    condiciones.push(ra("primeraVez"));
  }
  const meses = Math.round(numero(form.permanencia) ?? 0);
  if (esMembresia.value && !esCitas.value && meses > 0) {
    const modo = t(`planes.permanencia.modos.${form.bajaAnticipada}`);
    const detalle =
      form.bajaAnticipada === "monto_fijo"
        ? ` (${dinero(Math.round((numero(form.penalizacion) ?? 0) * 100), moneda.value, sesion.pais)})`
        : form.bajaAnticipada === "meses_restantes" &&
            (numero(form.porcentaje) ?? 100) < 100
          ? ` (${Math.max(1, Math.round(numero(form.porcentaje) ?? 100))} %)`
          : "";
    condiciones.push(`${ra("permanencia", meses)} · ${modo}${detalle}`);
  }
  // Las reglas de reserva (ADR 0182) y la acumulación: plegadas en el formulario, el
  // resumen es donde se ven todas juntas.
  if (admiteReglasSocio.value) {
    const reglas: [string, string][] = [
      ["topeDia", form.topeDia],
      ["topeSemana", form.topeSemana],
      ["anticipado", form.anticipado],
      ["invitados", form.invitados],
    ];
    for (const [clave, valor] of reglas) {
      const n = entero(valor);
      if (n !== null) {
        condiciones.push(ra(clave, n));
      }
    }
  }
  if (esMembresia.value && !form.ilimitado) {
    const tope = Math.round(numero(form.rolloverMax) ?? 0);
    if (form.rollover === "completo") {
      condiciones.push(ra("acumula"));
    } else if (form.rollover === "limitado" && tope > 0) {
      condiciones.push(ra("acumulaHasta", tope));
    }
  }
  if (!esCitas.value && !esExtra.value && form.cobraInscripcion) {
    condiciones.push(ra("inscripcion"));
  }
  if (!esCitas.value && !esExtra.value && form.cobraAnualidad) {
    condiciones.push(ra("anualidad"));
  }
  if (form.destacado) {
    condiciones.push(ra("destacado"));
  }
  if (props.plan && form.archivado) {
    condiciones.push(ra("archivado"));
  }

  return [
    {
      clave: "precio",
      etiqueta: ra("precio"),
      ...(precio !== null && precio >= 0
        ? { valor: dinero(Math.round(precio * 100), moneda.value, sesion.pais) }
        : { valor: ra("sinPrecio"), falta: true }),
    },
    { clave: "sucursales", etiqueta: ra("sucursales"), ...enSucursales },
    { clave: "aplica", etiqueta: ra("sirvePara"), ...sirvePara },
    { clave: "incluye", etiqueta: ra("incluye"), valor: incluye },
    { clave: "vigencia", etiqueta: ra("vigencia"), valor: vigencia },
    {
      clave: "condiciones",
      etiqueta: ra("condiciones"),
      valor:
        condiciones.length > 0 ? condiciones.join(" · ") : ra("sinCondiciones"),
    },
  ];
});

const valido = computed(
  () =>
    form.nombre.trim() !== "" &&
    (form.cobertura === "todas" || form.sucursalIds.length > 0) &&
    numero(form.precio) !== null &&
    (esExtra.value || form.todas || form.ofertas.size > 0),
);
watch(
  () => ({
    guardando: guardando.value,
    sucio: foto.cambio(),
    valido: valido.value,
  }),
  (e) => emit("estado", e),
  { immediate: true },
);

async function guardar(): Promise<void> {
  // El botón está en el pie del contenedor: un Enter en un campo también llega aquí.
  if (!valido.value || guardando.value) {
    return;
  }
  // Archivar es la baja del plan: se confirma aparte (ADR 0166), no pasa con «Guardar».
  if (
    p &&
    form.archivado &&
    !p.archivado &&
    !(await confirmar(
      t("planes.editor.confirmarArchivar", { nombre: form.nombre.trim() }),
      { peligro: true, aceptar: t("planes.editor.archivarAceptar") },
    ))
  ) {
    return;
  }
  guardando.value = true;
  error.value = null;
  errores.value = {};
  const unidades = (v: string) =>
    Math.round((numero(v) ?? 0) * UNIDADES_POR_CLASE);
  const recurrente = esMembresia.value;
  const carga: Record<string, unknown> = {
    nombre: form.nombre.trim(),
    tipo: form.tipo,
    precio_minor: Math.round((numero(form.precio) ?? 0) * 100),
    moneda: p?.moneda ?? sesion.moneda,
    ilimitado: recurrente && form.ilimitado,
    creditos_incluidos:
      recurrente || esSuelta.value ? null : unidades(form.clases),
    politica_reset: recurrente ? form.ciclo : "ninguno",
    unidades_por_ciclo:
      recurrente && !form.ilimitado ? unidades(form.clasesPorMes) : null,
    politica_rollover:
      recurrente && !form.ilimitado ? form.rollover : "ninguno",
    rollover_max:
      recurrente && form.rollover === "limitado"
        ? unidades(form.rolloverMax)
        : null,
    // Las extras vencen con el paquete y una membresía dura mientras se paga: no
    // llevan vigencia propia.
    vigencia_tipo: sinVigencia.value ? null : form.vigencia,
    vigencia_cantidad: sinVigencia.value ? null : numero(form.cantidad),
    // Las extras sirven para lo mismo que el paquete al que se suman.
    ofertas: esExtra.value || form.todas ? [] : [...form.ofertas],
    sucursal_ids: form.cobertura === "todas" ? null : form.sucursalIds,
    descripcion: form.descripcion.trim() || null,
    destacado: form.destacado,
    primera_vez: admitePrimeraVez.value && form.primeraVez,
    ...(esCitas.value || esExtra.value
      ? {}
      : {
          max_reservas_dia: entero(form.topeDia),
          max_reservas_semana: entero(form.topeSemana),
          acceso_anticipado_horas: entero(form.anticipado),
          invitados_por_mes: entero(form.invitados),
        }),
    ...(esCitas.value
      ? {}
      : {
          cobra_inscripcion: form.cobraInscripcion,
          cobra_anualidad: form.cobraAnualidad,
        }),
    ...(esMembresia.value && !esCitas.value
      ? {
          permanencia_meses: numero(form.permanencia) ?? 0,
          baja_anticipada: form.bajaAnticipada,
          penalizacion_minor:
            form.bajaAnticipada === "monto_fijo"
              ? Math.round((numero(form.penalizacion) ?? 0) * 100)
              : null,
          penalizacion_porcentaje:
            form.bajaAnticipada === "meses_restantes"
              ? Math.min(100, Math.max(1, numero(form.porcentaje) ?? 100))
              : null,
        }
      : {}),
  };
  try {
    type Respuesta = { meta?: { avisos?: { mensaje: string }[] } };
    const { data } = p
      ? await api.put<Respuesta>(`${base.value}/productos/${p.id}`, {
          ...carga,
          archivado: form.archivado,
        })
      : await api.post<Respuesta>(`${base.value}/productos`, carga);
    foto.fijar();
    emit(
      "guardado",
      form.nombre.trim(),
      (data?.meta?.avisos ?? []).map((a) => a.mensaje),
    );
  } catch (e) {
    // Lo capturado se queda: solo se corrige lo marcado.
    errores.value = erroresPorCampo(e);
    const claves = Object.keys(errores.value);
    error.value =
      claves.length > 0 && claves.every((c) => CAMPOS.includes(c))
        ? t("comun.revisaCampos")
        : mensajeDeError(e);
    await nextTick();
    const campo = document.querySelector<HTMLElement>(
      '#form-plan [aria-invalid="true"]',
    );
    if (campo) {
      campo.focus();
    } else {
      // Sin campo marcado, el aviso a la vista (en el teléfono va al final).
      document
        .getElementById("ep-error")
        ?.scrollIntoView?.({ block: "nearest" });
    }
  } finally {
    guardando.value = false;
  }
}

onMounted(async () => {
  try {
    const [o, s] = await Promise.all([
      api.get<{ data: Opcion[] }>(`${base.value}/ofertas`),
      api.get<{ data: Opcion[] }>(`${base.value}/sucursales`),
    ]);
    ofertas.value = o.data.data;
    sucursales.value = s.data.data;
  } catch {
    // Sin catálogo o sin sedes, el plan sirve para todas (no bloquea).
  }
});
</script>

<template>
  <form id="form-plan" class="ep-rejilla" @submit.prevent="guardar">
    <div class="min-w-0 space-y-5">
      <!-- ¿Qué es? -->
      <fieldset>
        <legend class="tu-label">{{ $t("planes.editor.queEs") }}</legend>
        <div class="mt-1 grid gap-2 sm:grid-cols-2">
          <button
            v-for="tipo in TIPOS_PRINCIPALES"
            :key="tipo"
            type="button"
            class="ep-tipo"
            :aria-pressed="form.tipo === tipo"
            :data-prueba="`tipo-${tipo}`"
            @click="form.tipo = tipo"
          >
            <span class="font-medium">{{ nombreTipo(tipo) }}</span>
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
              ayudaTipo(tipo)
            }}</span>
          </button>
        </div>
        <p
          v-if="esCitas"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="servicio-o-combo"
        >
          {{ $t("planes.editorCitas.servicioOCombo") }}
          <RouterLink :to="{ name: 'catalogo' }" class="tu-enlace">{{
            $t("planes.editorCitas.irCatalogo")
          }}</RouterLink>
        </p>
        <label v-else class="mt-2 flex items-center gap-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t("planes.editor.otro")
          }}</span>
          <select
            class="tu-input w-auto"
            :value="esOtro ? form.tipo : ''"
            @change="
              form.tipo =
                (($event.target as HTMLSelectElement).value as TipoPlan) ||
                form.tipo
            "
          >
            <option value="">—</option>
            <option value="pase_dia">{{ $t("planes.tipos.pase_dia") }}</option>
            <option value="taller">{{ $t("planes.tipos.taller") }}</option>
          </select>
        </label>
      </fieldset>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="tu-label" for="ep-nombre">{{
            $t("planes.editor.nombre")
          }}</label>
          <input
            id="ep-nombre"
            v-bind="marcar('nombre', 'ep-nombre-error')"
            v-model="form.nombre"
            class="tu-input"
            :placeholder="tx('nombrePh')"
            required
            maxlength="255"
          />
          <span
            v-if="errores.nombre"
            id="ep-nombre-error"
            class="tu-error-campo"
            >{{ errores.nombre }}</span
          >
        </div>
        <div>
          <label class="tu-label" for="ep-precio">{{
            $t("planes.editor.precio")
          }}</label>
          <input
            id="ep-precio"
            v-bind="marcar('precio_minor', 'ep-precio-error')"
            v-model="form.precio"
            class="tu-input"
            type="number"
            min="0"
            step="0.01"
            inputmode="decimal"
            required
          />
          <span
            v-if="errores.precio_minor"
            id="ep-precio-error"
            class="tu-error-campo"
            >{{ errores.precio_minor }}</span
          >
        </div>
        <div class="sm:col-span-2">
          <label class="tu-label" for="ep-descripcion">{{
            $t("planes.editor.descripcion")
          }}</label>
          <input
            id="ep-descripcion"
            v-bind="marcar('descripcion', 'ep-descripcion-error')"
            v-model="form.descripcion"
            class="tu-input"
            maxlength="300"
            :placeholder="tx('descripcionPh')"
          />
          <span
            v-if="errores.descripcion"
            id="ep-descripcion-error"
            class="tu-error-campo"
            >{{ errores.descripcion }}</span
          >
        </div>
      </div>

      <!-- Cuántas clases -->
      <div v-if="esMembresia" class="grid gap-3 sm:grid-cols-2">
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="form.ilimitado" type="checkbox" />
          {{ $t("planes.editor.ilimitada") }}
        </label>
        <div v-if="!form.ilimitado">
          <label class="tu-label" for="ep-pormes">{{
            tx("clasesPorMes")
          }}</label>
          <input
            id="ep-pormes"
            v-model="form.clasesPorMes"
            class="tu-input"
            type="number"
            min="1"
          />
        </div>
        <div>
          <label class="tu-label" for="ep-ciclo">{{
            $t("planes.editor.ciclo")
          }}</label>
          <select id="ep-ciclo" v-model="form.ciclo" class="tu-input">
            <option value="calendario">
              {{ $t("planes.editor.cicloCalendario") }}
            </option>
            <option value="aniversario">
              {{ $t("planes.editor.cicloAniversario") }}
            </option>
          </select>
        </div>
      </div>
      <p
        v-else-if="esSuelta"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("planes.editor.unaClase") }}
      </p>
      <div v-else class="sm:w-1/2">
        <label class="tu-label" for="ep-clases">{{
          esExtra ? $t("planes.editor.clasesExtra") : tx("clases")
        }}</label>
        <input
          id="ep-clases"
          v-model="form.clases"
          class="tu-input"
          type="number"
          min="1"
          required
        />
      </div>

      <!-- Cuánto dura -->
      <p
        v-if="esExtra"
        class="rounded-lg p-3 text-sm"
        :style="{ background: 'var(--superficie-2)' }"
      >
        {{ $t("planes.editor.extraVence") }}
      </p>
      <fieldset v-else-if="!esMembresia">
        <legend class="tu-label">{{ $t("planes.editor.vigencia") }}</legend>
        <div class="mt-1 flex flex-wrap gap-2">
          <label
            v-for="v in ['ninguna', 'dias', 'meses', 'fin_de_mes'] as const"
            :key="v"
            class="ep-opcion"
          >
            <input v-model="form.vigencia" type="radio" :value="v" />
            {{ $t(`planes.editor.vigencias.${v}`) }}
          </label>
        </div>
        <div v-if="form.vigencia !== 'ninguna'" class="mt-3 sm:w-1/2">
          <template v-if="form.vigencia === 'fin_de_mes'">
            <label class="tu-label" for="ep-cant">{{
              $t("planes.editor.cantidadFinDeMes")
            }}</label>
            <select id="ep-cant" v-model="form.cantidad" class="tu-input">
              <option value="1">{{ $t("planes.editor.finDeEsteMes") }}</option>
              <option value="2">
                {{ $t("planes.editor.finDelSiguiente") }}
              </option>
              <option v-for="n in [3, 6, 12]" :key="n" :value="String(n)">
                {{ $t("planes.editor.finDeMasMeses", { n }) }}
              </option>
            </select>
          </template>
          <template v-else>
            <label class="tu-label" for="ep-cant">{{
              form.vigencia === "dias"
                ? $t("planes.editor.cantidadDias")
                : $t("planes.editor.cantidadMeses")
            }}</label>
            <input
              id="ep-cant"
              v-model="form.cantidad"
              class="tu-input"
              type="number"
              min="1"
              :max="form.vigencia === 'dias' ? 366 : 24"
            />
          </template>
        </div>
        <p
          v-if="ejemplo"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          role="status"
        >
          {{ ejemplo }}
        </p>
      </fieldset>

      <!-- Para qué clases -->
      <fieldset v-if="!esExtra">
        <legend class="tu-label">{{ tx("aplicaA") }}</legend>
        <div class="mt-1 flex flex-wrap gap-2">
          <label class="ep-opcion">
            <input v-model="form.todas" type="radio" :value="true" />
            {{ tx("todas") }}
          </label>
          <label class="ep-opcion">
            <input v-model="form.todas" type="radio" :value="false" />
            {{ tx("algunas") }}
          </label>
        </div>
        <AvisoTono
          v-if="esCitas && ofertasElegibles.length === 0"
          tono="aviso"
          class="mt-2"
          data-prueba="sin-servicios-con-bono"
        >
          {{ $t("planes.editorCitas.sinServiciosConBono") }}
          <RouterLink :to="{ name: 'catalogo' }" class="tu-enlace">{{
            $t("planes.editorCitas.irCatalogo")
          }}</RouterLink>
        </AvisoTono>
        <div v-if="!form.todas" class="mt-3 space-y-3">
          <p
            v-if="ofertasElegibles.length === 0 && !esCitas"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ tx("sinClases") }}
          </p>
          <!-- En filas por actividad, con búsqueda: el catálogo crece. -->
          <SelectorOpciones
            v-if="ofertasElegibles.length > 0"
            :opciones="opcionesOfertas"
            :model-value="[...form.ofertas]"
            @update:model-value="form.ofertas = new Set($event)"
          />
          <AvisoTono
            v-if="ofertasElegibles.length > 0 && form.ofertas.size === 0"
            tono="aviso"
          >
            {{ tx("eligeClases") }}
          </AvisoTono>
        </div>
      </fieldset>

      <fieldset v-if="sucursales.length > 1 && !esExtra" class="space-y-3">
        <legend class="font-medium">
          {{ $t("planes.editor.cobertura") }}
        </legend>
        <select
          v-model="form.cobertura"
          class="tu-input"
          data-prueba="cobertura-sucursal"
          :aria-label="$t('planes.editor.cobertura')"
        >
          <option value="todas">{{ $t("sucursalOperativa.todas") }}</option>
          <option value="seleccionadas">
            {{ $t("planes.editor.coberturaAlgunas") }}
          </option>
        </select>
        <div
          v-if="form.cobertura === 'seleccionadas'"
          class="grid gap-2 sm:grid-cols-2"
        >
          <label
            v-for="s in sucursales"
            :key="s.id"
            class="flex items-center gap-2"
          >
            <input v-model="form.sucursalIds" type="checkbox" :value="s.id" />{{
              s.nombre
            }}
          </label>
        </div>
        <p class="text-sm" style="color: var(--texto-suave)">
          {{
            form.cobertura === "todas"
              ? $t("planes.editor.coberturaTodasAyuda")
              : $t("planes.editor.coberturaAlgunasAyuda")
          }}
          {{ $t("planes.editor.coberturaCompras") }}
        </p>
      </fieldset>

      <!-- Condiciones avanzadas: plegadas; abiertas si el plan ya trae alguna. -->
      <details
        class="tu-avanzado"
        :open="abrirAvanzado"
        data-prueba="mas-condiciones"
      >
        <summary>
          {{
            condicionesActivas > 0
              ? $t(
                  "planes.editor.avanzadoActivas",
                  { n: condicionesActivas },
                  condicionesActivas,
                )
              : $t("planes.editor.avanzado")
          }}
        </summary>
        <div class="space-y-5">
          <!-- En la página del negocio -->
          <fieldset class="space-y-2">
            <legend class="tu-label">
              {{ $t("planes.editor.enTuPagina") }}
            </legend>
            <label class="flex items-start gap-2 text-sm">
              <input v-model="form.destacado" type="checkbox" class="mt-1" />
              <span>
                {{ $t("planes.editor.destacado") }}
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t("planes.editor.destacadoAyuda") }}</span
                >
              </span>
            </label>
            <label
              v-if="admitePrimeraVez"
              class="flex items-start gap-2 text-sm"
              data-prueba="primera-vez"
            >
              <input v-model="form.primeraVez" type="checkbox" class="mt-1" />
              <span>
                {{ tx("primeraVez") }}
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ tx("primeraVezAyuda") }}</span
                >
              </span>
            </label>
          </fieldset>

          <!-- Planes «socio» (ADR 0182): topes, acceso anticipado e invitados. -->
          <fieldset
            v-if="!esCitas && !esExtra"
            class="space-y-3"
            data-prueba="reglas-socio"
          >
            <legend class="tu-label">{{ $t("planes.socio.titulo") }}</legend>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("planes.socio.ayuda") }}
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
              <label>
                <span class="tu-label">{{ $t("planes.socio.topeDia") }}</span>
                <input
                  v-model="form.topeDia"
                  class="tu-input"
                  type="number"
                  min="1"
                  max="20"
                  :placeholder="$t('planes.socio.sinTope')"
                  data-prueba="tope-dia"
                />
              </label>
              <label>
                <span class="tu-label">{{
                  $t("planes.socio.topeSemana")
                }}</span>
                <input
                  v-model="form.topeSemana"
                  class="tu-input"
                  type="number"
                  min="1"
                  max="100"
                  :placeholder="$t('planes.socio.sinTope')"
                />
              </label>
              <label>
                <span class="tu-label">{{
                  $t("planes.socio.anticipado")
                }}</span>
                <input
                  v-model="form.anticipado"
                  class="tu-input"
                  type="number"
                  min="1"
                  max="720"
                  :placeholder="$t('planes.socio.sinAnticipado')"
                />
              </label>
              <label>
                <span class="tu-label">{{ $t("planes.socio.invitados") }}</span>
                <input
                  v-model="form.invitados"
                  class="tu-input"
                  type="number"
                  min="1"
                  max="20"
                  :placeholder="$t('planes.socio.sinInvitados')"
                  data-prueba="invitados-mes"
                />
              </label>
            </div>
          </fieldset>

          <!-- Permanencia (B04): solo en membresías. -->
          <fieldset
            v-if="esMembresia && !esCitas"
            class="space-y-3"
            data-prueba="permanencia-plan"
          >
            <legend class="tu-label">
              {{ $t("planes.permanencia.titulo") }}
            </legend>
            <div class="sm:w-1/2">
              <label class="tu-label" for="ep-permanencia">{{
                $t("planes.permanencia.meses")
              }}</label>
              <input
                id="ep-permanencia"
                v-model="form.permanencia"
                class="tu-input"
                type="number"
                min="0"
                max="60"
                :placeholder="$t('planes.permanencia.sin')"
              />
            </div>
            <template v-if="(numero(form.permanencia) ?? 0) > 0">
              <p class="text-sm">{{ $t("planes.permanencia.siDaDeBaja") }}</p>
              <div class="flex flex-wrap gap-2" role="radiogroup">
                <label
                  v-for="m in [
                    'sin_penalizacion',
                    'monto_fijo',
                    'meses_restantes',
                  ] as const"
                  :key="m"
                  class="ep-opcion"
                >
                  <input
                    v-model="form.bajaAnticipada"
                    type="radio"
                    :value="m"
                  />
                  {{ $t(`planes.permanencia.modos.${m}`) }}
                </label>
              </div>
              <div v-if="form.bajaAnticipada === 'monto_fijo'" class="sm:w-1/2">
                <label class="tu-label" for="ep-penalizacion">{{
                  $t("planes.permanencia.monto")
                }}</label>
                <input
                  id="ep-penalizacion"
                  v-model="form.penalizacion"
                  class="tu-input"
                  type="number"
                  min="0"
                  step="0.01"
                  inputmode="decimal"
                />
              </div>
              <div
                v-if="form.bajaAnticipada === 'meses_restantes'"
                class="sm:w-1/2"
              >
                <label class="tu-label" for="ep-porcentaje">{{
                  $t("planes.permanencia.porcentaje")
                }}</label>
                <input
                  id="ep-porcentaje"
                  v-model="form.porcentaje"
                  class="tu-input"
                  type="number"
                  min="1"
                  max="100"
                  step="1"
                  inputmode="numeric"
                />
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("planes.permanencia.porcentajeAyuda") }}
                </p>
              </div>
              <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("planes.permanencia.ayuda") }}
              </p>
            </template>
          </fieldset>

          <!-- Cuotas del negocio (B04): el monto se configura en «Cuotas». -->
          <fieldset
            v-if="!esCitas && !esExtra"
            class="space-y-2"
            data-prueba="cuotas-plan"
          >
            <legend class="tu-label">{{ $t("planes.cuotas.enElPlan") }}</legend>
            <label class="flex items-start gap-2 text-sm">
              <input
                v-model="form.cobraInscripcion"
                type="checkbox"
                class="mt-1"
              />
              <span>
                {{ $t("planes.cuotas.cobraInscripcion") }}
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t("planes.cuotas.cobraInscripcionAyuda") }}</span
                >
              </span>
            </label>
            <label class="flex items-start gap-2 text-sm">
              <input
                v-model="form.cobraAnualidad"
                type="checkbox"
                class="mt-1"
              />
              <span>
                {{ $t("planes.cuotas.cobraAnualidad") }}
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t("planes.cuotas.cobraAnualidadAyuda") }}</span
                >
              </span>
            </label>
          </fieldset>

          <!-- Acumulación: lo que no se usa en el mes (membresías con clases). -->
          <div
            v-if="esMembresia && !form.ilimitado"
            class="grid gap-3 sm:grid-cols-2"
          >
            <div>
              <label class="tu-label" for="ep-acumula">{{
                $t("planes.editor.acumula")
              }}</label>
              <select id="ep-acumula" v-model="form.rollover" class="tu-input">
                <option value="ninguno">
                  {{ $t("planes.editor.acumulaNinguno") }}
                </option>
                <option value="completo">
                  {{ $t("planes.editor.acumulaTodo") }}
                </option>
                <option value="limitado">
                  {{ $t("planes.editor.acumulaLimitado") }}
                </option>
              </select>
            </div>
            <div v-if="form.rollover === 'limitado'">
              <label class="tu-label" for="ep-acmax">{{
                $t("planes.editor.acumulaMax")
              }}</label>
              <input
                id="ep-acmax"
                v-model="form.rolloverMax"
                class="tu-input"
                type="number"
                min="1"
              />
            </div>
          </div>
          <!-- Archivar es la baja del plan (ADR 0077); reactivarlo es editar. -->
          <label
            v-if="
              plan && (plan.archivado || sesion.puede('productos.eliminar'))
            "
            class="flex items-center gap-2 text-sm"
          >
            <input v-model="form.archivado" type="checkbox" />
            {{ $t("planes.editor.archivar") }}
          </label>
        </div>
      </details>
    </div>

    <!-- Al lado de los campos en pantallas anchas (siempre a la vista); al final
         en el teléfono, justo arriba del pie con Guardar. -->
    <aside class="ep-lateral space-y-3">
      <AvisoTono v-if="error" id="ep-error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
      <!-- Así quedará: lo que se guarda, revisado antes de guardar -->
      <section
        class="ep-resumen"
        aria-labelledby="ep-resumen-titulo"
        data-prueba="resumen-plan"
      >
        <header>
          <h3 id="ep-resumen-titulo">
            {{ $t("planes.antesDeGuardar.titulo") }}
          </h3>
          <p>{{ $t("planes.antesDeGuardar.ayuda") }}</p>
        </header>
        <dl>
          <div
            v-for="f in resumen"
            :key="f.clave"
            :data-prueba="`resumen-${f.clave}`"
          >
            <dt>{{ f.etiqueta }}</dt>
            <dd
              :class="{ 'tu-estado': f.falta }"
              :style="f.falta ? { '--tono': 'var(--aviso)' } : undefined"
            >
              {{ f.valor }}
            </dd>
          </div>
        </dl>
      </section>
      <p v-if="plan" class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("planes.editor.soloFuturo") }}
      </p>
    </aside>
  </form>
</template>

<style scoped>
/* Los campos y, al lado, «Así quedará»: fijo arriba mientras se baja por el
   formulario (en el diálogo amplio); en el teléfono, al final. */
.ep-rejilla {
  display: grid;
  gap: 1.25rem;
}
.ep-tipo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  text-align: left;
  background: var(--superficie);
}
.ep-tipo[aria-pressed="true"] {
  border-color: var(--primario);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.ep-opcion {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  font-size: 0.875rem;
  cursor: pointer;
}
.ep-opcion:has(input:checked) {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 8%, var(--superficie));
}
/* Así quedará: una ficha sobria, con cada dato en su renglón. */
.ep-resumen {
  display: grid;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: color-mix(in srgb, var(--superficie-2) 45%, var(--superficie));
}
.ep-resumen h3 {
  font-size: 0.95rem;
  font-weight: 600;
}
.ep-resumen header p {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ep-resumen dl {
  display: grid;
  gap: 0.45rem;
  font-size: 0.875rem;
}
.ep-resumen dl > div {
  display: grid;
  grid-template-columns: minmax(6.5rem, 9rem) 1fr;
  gap: 0.75rem;
}
.ep-resumen dt {
  color: var(--texto-suave);
}
.ep-resumen dd {
  min-width: 0;
  overflow-wrap: anywhere;
}
@media (min-width: 1024px) {
  .ep-rejilla {
    grid-template-columns: minmax(0, 1fr) 20rem;
    align-items: start;
  }
  .ep-lateral {
    position: sticky;
    top: 0;
  }
  .ep-resumen dl > div {
    grid-template-columns: minmax(5.5rem, 7.5rem) 1fr;
  }
}
</style>
