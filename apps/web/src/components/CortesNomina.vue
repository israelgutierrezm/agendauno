<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { aMinor, dinero as dineroDelPais } from "@/lib/formato";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Cortes de nómina: cerrar el periodo calculado (lo devengado queda fijo), registrar
 * su pago con la referencia y, ya pagado, los ajustes por lo que cambie después
 * (una asistencia corregida). Reabrir solo mientras no esté pagado.
 */
interface Corte {
  id: string;
  desde: string;
  hasta: string;
  estado: "cerrado" | "pagado";
  moneda: string;
  total_minor: number;
  ajustes_minor: number;
  total_con_ajustes_minor: number;
  cerrado_por: string | null;
  cerrado_en: string;
  pagado_por: string | null;
  pagado_en: string | null;
  referencia_pago: string | null;
}
interface Detalle extends Corte {
  lineas: {
    usuario_id: string | null;
    usuario: string | null;
    tipo: string;
    unidades: number;
    total_minor: number;
  }[];
  ajustes: {
    id: string;
    usuario: string | null;
    monto_minor: number;
    motivo: string;
    registrado_por: string | null;
    registrado_en: string | null;
  }[];
  diferencias: {
    usuario_id: string | null;
    usuario: string | null;
    en_corte_minor: number;
    // Lo ya ajustado y lo reconocido (corte + ajustes); la diferencia es lo que falta.
    ajustes_minor?: number;
    reconocido_minor?: number;
    hoy_minor: number;
    diferencia_minor: number;
    diferencia_original_minor?: number;
  }[];
}

const props = defineProps<{
  // El periodo calculado en pantalla (null si aún no se calcula).
  periodo: { desde: string; hasta: string } | null;
  devengado: number;
  equipo: { id: string; nombre: string }[];
}>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/nomina/cortes`);

const cortes = ref<Corte[]>([]);
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
const pagina = ref(1);
const cargando = ref(true);
const errorCarga = ref(false);
const cerrando = ref(false);

function dinero(minor: number, moneda = sesion.moneda): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}
// Pagado en verde; cerrado sin pagar, en ámbar (falta registrar el pago).
function tonoEstado(estado: Corte["estado"]): string {
  return estado === "pagado" ? "var(--exito)" : "var(--aviso)";
}
function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-");
  return `${d}/${m}/${a}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = false;
  try {
    const { data } = await api.get<{ data: Corte[]; meta: typeof meta.value }>(
      base.value,
      { params: { page: pagina.value } },
    );
    cortes.value = data.data;
    meta.value = data.meta;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

// Cerrar: el periodo calculado, si ya terminó y no se encima con uno de la lista.
const terminado = computed(
  () =>
    props.periodo !== null &&
    props.periodo.hasta < hoyEnNegocio(sesion.zonaHoraria),
);
const encimado = computed(
  () =>
    props.periodo !== null &&
    cortes.value.some(
      (c) =>
        c.desde <= (props.periodo?.hasta ?? "") &&
        c.hasta >= (props.periodo?.desde ?? ""),
    ),
);
const motivoNoCerrar = computed(() => {
  if (props.periodo === null) return null;
  if (!terminado.value) return t("nominaVisual.cortes.soloTerminado");
  if (encimado.value) return t("nominaVisual.cortes.yaCerrado");
  return null;
});

async function cerrarPeriodo(): Promise<void> {
  const periodo = props.periodo;
  if (periodo === null || motivoNoCerrar.value !== null || cerrando.value)
    return;
  const si = await confirmar(
    t("nominaVisual.cortes.confirmarCerrar", {
      desde: fecha(periodo.desde),
      hasta: fecha(periodo.hasta),
      total: dinero(props.devengado),
    }),
    { aceptar: t("nominaVisual.cortes.cerrar") },
  );
  if (!si) return;
  cerrando.value = true;
  try {
    await api.post(base.value, periodo);
    toast.exito(t("nominaVisual.cortes.cerrado"));
    pagina.value = 1;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cerrando.value = false;
  }
}

// Detalle de un corte, con su pago y sus ajustes.
const abierto = ref(false);
const detalle = ref<Detalle | null>(null);
const cargandoDetalle = ref(false);
const errorDetalle = ref(false);
const pago = ref({ referencia: "" });
const ajuste = ref({
  usuario_id: "",
  sentido: "favor" as "favor" | "contra",
  monto: "",
  motivo: "",
});
const abiertoPago = computed(
  () => abierto.value && detalle.value?.estado === "cerrado",
);
const abiertoAjuste = computed(
  () => abierto.value && detalle.value?.estado === "pagado",
);
const editorPago = useEditorFormulario({
  abierto: abiertoPago,
  valor: () => pago.value,
  campos: ["referencia"],
  formulario: "#form-pago-nomina",
});
const editorAjuste = useEditorFormulario({
  abierto: abiertoAjuste,
  valor: () => ajuste.value,
  campos: ["usuario_id", "monto_minor", "motivo"],
  formulario: "#form-ajuste-nomina",
});

async function verCorte(id: string): Promise<void> {
  abierto.value = true;
  cargandoDetalle.value = true;
  errorDetalle.value = false;
  try {
    const { data } = await api.get<{ data: Detalle }>(`${base.value}/${id}`);
    detalle.value = data.data;
    pago.value = { referencia: "" };
    ajuste.value = { usuario_id: "", sentido: "favor", monto: "", motivo: "" };
    editorPago.empezar();
    editorAjuste.empezar();
  } catch {
    errorDetalle.value = true;
  } finally {
    cargandoDetalle.value = false;
  }
}
async function cerrarDetalle(): Promise<void> {
  const editor = detalle.value?.estado === "pagado" ? editorAjuste : editorPago;
  if (await editor.cerrar()) {
    abierto.value = false;
  }
}

async function registrarPago(): Promise<void> {
  const corte = detalle.value;
  if (corte === null) return;
  const listo = await editorPago.guardar(
    () =>
      api.post(`${base.value}/${corte.id}/pagar`, {
        referencia: pago.value.referencia,
      }),
    t("nominaVisual.cortes.pago.hecho"),
  );
  if (listo !== undefined) {
    await Promise.all([verCorte(corte.id), cargar()]);
  }
}

async function reabrir(): Promise<void> {
  const corte = detalle.value;
  if (corte === null) return;
  const si = await confirmar(
    t("nominaVisual.cortes.confirmarReabrir", {
      desde: fecha(corte.desde),
      hasta: fecha(corte.hasta),
    }),
    { aceptar: t("nominaVisual.cortes.reabrir"), tono: "aviso" },
  );
  if (!si) return;
  try {
    await api.delete(`${base.value}/${corte.id}`);
    toast.exito(t("nominaVisual.cortes.reabierto"));
    editorPago.empezar();
    abierto.value = false;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

function usarDiferencia(d: Detalle["diferencias"][number]): void {
  ajuste.value = {
    usuario_id: d.usuario_id ?? "",
    sentido: d.diferencia_minor >= 0 ? "favor" : "contra",
    monto: String(Math.abs(d.diferencia_minor) / 100),
    motivo: ajuste.value.motivo,
  };
}

async function agregarAjuste(): Promise<void> {
  const corte = detalle.value;
  if (corte === null) return;
  const importe = aMinor(ajuste.value.monto, sesion.pais);
  const listo = await editorAjuste.guardar(
    () =>
      api.post(`${base.value}/${corte.id}/ajustes`, {
        usuario_id: ajuste.value.usuario_id,
        monto_minor:
          importe === null
            ? null
            : ajuste.value.sentido === "contra"
              ? -importe
              : importe,
        motivo: ajuste.value.motivo,
      }),
    t("nominaVisual.cortes.ajuste.hecho"),
  );
  if (listo !== undefined) {
    await Promise.all([verCorte(corte.id), cargar()]);
  }
}

// Al cambiar de página, se recarga.
watch(pagina, () => void cargar());
defineExpose({ cargar });
</script>

<template>
  <div class="tu-card mt-5 p-5" data-prueba="cortes-nomina">
    <header class="nm-cabeza flex-wrap">
      <span class="tu-icono-tono tu-tono-verde">
        <IconoNav nombre="cobrar" :tam="20" />
      </span>
      <span class="min-w-0 flex-1">
        <h2 class="font-semibold">{{ $t("nominaVisual.cortes.titulo") }}</h2>
        <p class="nm-sub">{{ $t("nominaVisual.cortes.ayuda") }}</p>
      </span>
      <span class="flex flex-col items-end gap-1">
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="
            periodo === null || motivoNoCerrar !== null || cerrando || cargando
          "
          data-prueba="cerrar-periodo"
          @click="cerrarPeriodo"
        >
          {{
            cerrando
              ? $t("nominaVisual.cortes.cerrando")
              : $t("nominaVisual.cortes.cerrar")
          }}
        </button>
        <span
          v-if="motivoNoCerrar"
          class="nm-sub"
          data-prueba="motivo-no-cerrar"
          >{{ motivoNoCerrar }}</span
        >
      </span>
    </header>

    <p v-if="cargando" class="mt-4 nm-sub">
      {{ $t("nominaVisual.cortes.cargando") }}
    </p>
    <AvisoTono
      v-else-if="errorCarga"
      tono="peligro"
      class="mt-4"
      role="alert"
      data-prueba="error-cortes"
    >
      <span class="flex flex-wrap items-center justify-between gap-3">
        {{ $t("nominaVisual.cortes.errorCarga") }}
        <button type="button" class="tu-btn tu-btn-fantasma" @click="cargar">
          {{ $t("comun.reintentar") }}
        </button>
      </span>
    </AvisoTono>
    <EstadoVacio
      v-else-if="cortes.length === 0"
      class="py-6"
      icono="nomina"
      compacto
      :titulo="$t('nominaVisual.cortes.vacio')"
    />
    <div v-else class="relative mt-4 overflow-x-auto">
      <table class="tu-tabla">
        <thead>
          <tr>
            <th>{{ $t("nominaVisual.cortes.colPeriodo") }}</th>
            <th>{{ $t("nominaVisual.cortes.colEstado") }}</th>
            <th class="text-right">{{ $t("nominaVisual.cortes.colTotal") }}</th>
            <th>{{ $t("nominaVisual.cortes.colCerrado") }}</th>
            <th>{{ $t("nominaVisual.cortes.colPago") }}</th>
            <th>
              <span class="sr-only">{{ $t("nominaVisual.cortes.ver") }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cortes" :key="c.id">
            <td class="tabular-nums">
              {{
                $t("nominaVisual.cortes.del", {
                  desde: fecha(c.desde),
                  hasta: fecha(c.hasta),
                })
              }}
            </td>
            <td>
              <span
                class="tu-estado"
                :style="{ '--tono': tonoEstado(c.estado) }"
                >{{ $t(`nominaVisual.cortes.estados.${c.estado}`) }}</span
              >
            </td>
            <td class="text-right tabular-nums">
              {{ dinero(c.total_minor, c.moneda) }}
              <span v-if="c.ajustes_minor !== 0" class="block nm-sub">{{
                $t("nominaVisual.cortes.conAjustes", {
                  total: dinero(c.total_con_ajustes_minor, c.moneda),
                })
              }}</span>
            </td>
            <td>
              {{ c.cerrado_por ?? "—" }}
              <span class="block nm-sub">{{ fecha(c.cerrado_en) }}</span>
            </td>
            <td>
              <template v-if="c.estado === 'pagado'">
                {{ c.referencia_pago }}
                <span v-if="c.pagado_en" class="block nm-sub">{{
                  fecha(c.pagado_en)
                }}</span>
              </template>
              <span v-else class="nm-sub">—</span>
            </td>
            <td class="text-right">
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :data-prueba="`ver-corte-${c.id}`"
                @click="verCorte(c.id)"
              >
                {{ $t("nominaVisual.cortes.ver") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <PaginacionListado
        v-if="meta && meta.ultima_pagina > 1"
        :page="meta.page"
        :ultima-pagina="meta.ultima_pagina"
        :total="meta.total"
        :per-page="meta.per_page"
        @ir="pagina = $event"
      />
    </div>

    <PanelLateral
      :abierto="abierto"
      lateral
      :titulo="
        detalle
          ? $t('nominaVisual.cortes.del', {
              desde: fecha(detalle.desde),
              hasta: fecha(detalle.hasta),
            })
          : $t('nominaVisual.cortes.titulo')
      "
      :ocupado="editorPago.guardando.value || editorAjuste.guardando.value"
      @cerrar="cerrarDetalle"
    >
      <p v-if="cargandoDetalle" class="nm-sub">{{ $t("comun.cargando") }}</p>
      <AvisoTono v-else-if="errorDetalle" tono="peligro" role="alert">
        {{ $t("nominaVisual.cortes.errorDetalle") }}
      </AvisoTono>
      <div v-else-if="detalle" class="space-y-5" data-prueba="detalle-corte">
        <div>
          <span
            class="tu-estado"
            :style="{ '--tono': tonoEstado(detalle.estado) }"
            >{{ $t(`nominaVisual.cortes.estados.${detalle.estado}`) }}</span
          >
          <p class="mt-2 text-2xl font-semibold tabular-nums">
            {{ dinero(detalle.total_minor, detalle.moneda) }}
          </p>
          <p v-if="detalle.ajustes_minor !== 0" class="nm-sub">
            {{
              $t("nominaVisual.cortes.conAjustes", {
                total: dinero(detalle.total_con_ajustes_minor, detalle.moneda),
              })
            }}
          </p>
          <p class="mt-1 nm-sub">
            {{
              $t("nominaVisual.cortes.cerradoPor", {
                nombre: detalle.cerrado_por ?? "—",
                fecha: fecha(detalle.cerrado_en),
              })
            }}
          </p>
          <p v-if="detalle.pagado_en" class="nm-sub">
            {{
              $t("nominaVisual.cortes.pagadoPor", {
                nombre: detalle.pagado_por ?? "—",
                fecha: fecha(detalle.pagado_en),
              })
            }}
            · {{ $t("nominaVisual.cortes.referencia") }}:
            {{ detalle.referencia_pago }}
          </p>
        </div>

        <section>
          <h3 class="font-semibold">{{ $t("nominaVisual.cortes.lineas") }}</h3>
          <ul class="mt-2 divide-y" :style="{ borderColor: 'var(--borde)' }">
            <li
              v-for="(l, i) in detalle.lineas"
              :key="`${l.usuario_id}-${i}`"
              class="flex justify-between gap-3 py-2"
            >
              <span>{{ l.usuario ?? "—" }}</span>
              <span class="tabular-nums">{{
                dinero(l.total_minor, detalle.moneda)
              }}</span>
            </li>
          </ul>
        </section>

        <AvisoTono
          v-if="detalle.diferencias.length > 0"
          :tono="
            detalle.diferencias.some((d) => d.diferencia_minor !== 0)
              ? 'aviso'
              : 'info'
          "
          data-prueba="diferencias-corte"
        >
          <p class="font-medium">
            {{
              detalle.diferencias.some((d) => d.diferencia_minor !== 0)
                ? $t("nominaVisual.cortes.diferencias")
                : $t("nominaVisual.cortes.diferenciasAtendidas")
            }}
          </p>
          <ul class="mt-1 space-y-1">
            <li
              v-for="d in detalle.diferencias"
              :key="d.usuario_id ?? 'sin'"
              class="flex flex-wrap items-center justify-between gap-2"
            >
              <span>{{
                d.diferencia_minor === 0
                  ? $t("nominaVisual.cortes.diferenciaAtendida", {
                      nombre: d.usuario ?? "—",
                      antes: dinero(d.en_corte_minor, detalle.moneda),
                      ajustes: dinero(d.ajustes_minor ?? 0, detalle.moneda),
                      hoy: dinero(d.hoy_minor, detalle.moneda),
                    })
                  : (d.ajustes_minor ?? 0) !== 0
                    ? $t("nominaVisual.cortes.diferenciaConAjustes", {
                        nombre: d.usuario ?? "—",
                        antes: dinero(d.en_corte_minor, detalle.moneda),
                        ajustes: dinero(d.ajustes_minor ?? 0, detalle.moneda),
                        hoy: dinero(d.hoy_minor, detalle.moneda),
                        diferencia:
                          (d.diferencia_minor > 0 ? "+" : "−") +
                          dinero(Math.abs(d.diferencia_minor), detalle.moneda),
                      })
                    : $t("nominaVisual.cortes.diferenciaDe", {
                        nombre: d.usuario ?? "—",
                        antes: dinero(d.en_corte_minor, detalle.moneda),
                        hoy: dinero(d.hoy_minor, detalle.moneda),
                        diferencia:
                          (d.diferencia_minor > 0 ? "+" : "−") +
                          dinero(Math.abs(d.diferencia_minor), detalle.moneda),
                      })
              }}</span>
              <button
                v-if="
                  detalle.estado === 'pagado' &&
                  d.usuario_id &&
                  d.diferencia_minor !== 0
                "
                type="button"
                class="tu-btn tu-btn-fantasma"
                @click="usarDiferencia(d)"
              >
                {{ $t("nominaVisual.cortes.usarDiferencia") }}
              </button>
            </li>
          </ul>
        </AvisoTono>

        <!-- Cerrado: registrar el pago o reabrir. -->
        <form
          v-if="detalle.estado === 'cerrado'"
          id="form-pago-nomina"
          class="space-y-3"
          novalidate
          @submit.prevent="registrarPago"
        >
          <h3 class="font-semibold">
            {{ $t("nominaVisual.cortes.pago.titulo") }}
          </h3>
          <AvisoTono
            v-if="editorPago.error.value"
            tono="peligro"
            role="alert"
            >{{ editorPago.error.value }}</AvisoTono
          >
          <div>
            <label class="tu-label" for="ref-pago">{{
              $t("nominaVisual.cortes.pago.referencia")
            }}</label>
            <input
              id="ref-pago"
              v-model="pago.referencia"
              class="tu-input"
              maxlength="120"
              required
              data-prueba="referencia-pago"
              v-bind="editorPago.marcar('referencia', 'err-ref-pago')"
            />
            <p
              v-if="editorPago.errores.value.referencia"
              id="err-ref-pago"
              class="tu-error-campo"
            >
              {{ editorPago.errores.value.referencia }}
            </p>
            <p v-else class="nm-sub mt-1">
              {{ $t("nominaVisual.cortes.pago.ayuda") }}
            </p>
          </div>
          <div class="flex flex-wrap justify-between gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              data-prueba="reabrir-corte"
              @click="reabrir"
            >
              {{ $t("nominaVisual.cortes.reabrir") }}
            </button>
            <button
              type="submit"
              class="tu-btn tu-btn-primario"
              :disabled="
                editorPago.guardando.value || pago.referencia.trim() === ''
              "
            >
              {{ $t("nominaVisual.cortes.pago.guardar") }}
            </button>
          </div>
        </form>

        <!-- Pagado: lo que cambie después va en ajustes. -->
        <template v-else>
          <section>
            <h3 class="font-semibold">
              {{ $t("nominaVisual.cortes.ajustes") }}
            </h3>
            <p v-if="detalle.ajustes.length === 0" class="mt-1 nm-sub">
              {{ $t("nominaVisual.cortes.sinAjustes") }}
            </p>
            <ul v-else class="mt-2 space-y-2">
              <li v-for="a in detalle.ajustes" :key="a.id">
                <span class="flex justify-between gap-3">
                  <span>{{ a.usuario ?? "—" }}</span>
                  <span class="tabular-nums">{{
                    (a.monto_minor > 0 ? "+" : "−") +
                    dinero(Math.abs(a.monto_minor), detalle.moneda)
                  }}</span>
                </span>
                <span class="block nm-sub"
                  >{{ a.motivo }} · {{ a.registrado_por ?? "—" }}</span
                >
              </li>
            </ul>
          </section>
          <form
            id="form-ajuste-nomina"
            class="space-y-3"
            novalidate
            @submit.prevent="agregarAjuste"
          >
            <h3 class="font-semibold">
              {{ $t("nominaVisual.cortes.ajuste.titulo") }}
            </h3>
            <AvisoTono
              v-if="editorAjuste.error.value"
              tono="peligro"
              role="alert"
              >{{ editorAjuste.error.value }}</AvisoTono
            >
            <div>
              <label class="tu-label" for="aj-persona">{{
                $t("nominaVisual.cortes.ajuste.persona")
              }}</label>
              <select
                id="aj-persona"
                v-model="ajuste.usuario_id"
                class="tu-input"
                required
                data-prueba="ajuste-persona"
                v-bind="editorAjuste.marcar('usuario_id', 'err-aj-persona')"
              >
                <option value="" disabled>—</option>
                <option v-for="p in equipo" :key="p.id" :value="p.id">
                  {{ p.nombre }}
                </option>
              </select>
              <p
                v-if="editorAjuste.errores.value.usuario_id"
                id="err-aj-persona"
                class="tu-error-campo"
              >
                {{ editorAjuste.errores.value.usuario_id }}
              </p>
            </div>
            <fieldset>
              <legend class="tu-label">
                {{ $t("nominaVisual.cortes.ajuste.sentido") }}
              </legend>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="ajuste.sentido" type="radio" value="favor" />
                {{ $t("nominaVisual.cortes.ajuste.aFavor") }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="ajuste.sentido" type="radio" value="contra" />
                {{ $t("nominaVisual.cortes.ajuste.enContra") }}
              </label>
            </fieldset>
            <div>
              <label class="tu-label" for="aj-monto">{{
                $t("nominaVisual.cortes.ajuste.monto")
              }}</label>
              <input
                id="aj-monto"
                v-model="ajuste.monto"
                class="tu-input"
                inputmode="decimal"
                required
                data-prueba="ajuste-monto"
                v-bind="editorAjuste.marcar('monto_minor', 'err-aj-monto')"
              />
              <p
                v-if="editorAjuste.errores.value.monto_minor"
                id="err-aj-monto"
                class="tu-error-campo"
              >
                {{ editorAjuste.errores.value.monto_minor }}
              </p>
            </div>
            <div>
              <label class="tu-label" for="aj-motivo">{{
                $t("nominaVisual.cortes.ajuste.motivo")
              }}</label>
              <input
                id="aj-motivo"
                v-model="ajuste.motivo"
                class="tu-input"
                maxlength="250"
                required
                data-prueba="ajuste-motivo"
                v-bind="editorAjuste.marcar('motivo', 'err-aj-motivo')"
              />
              <p
                v-if="editorAjuste.errores.value.motivo"
                id="err-aj-motivo"
                class="tu-error-campo"
              >
                {{ editorAjuste.errores.value.motivo }}
              </p>
            </div>
            <div class="flex justify-end">
              <button
                type="submit"
                class="tu-btn tu-btn-primario"
                :disabled="editorAjuste.guardando.value"
                data-prueba="guardar-ajuste"
              >
                {{ $t("nominaVisual.cortes.ajuste.guardar") }}
              </button>
            </div>
          </form>
        </template>
      </div>
    </PanelLateral>
  </div>
</template>

<style scoped>
.nm-cabeza {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}
.nm-sub {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
</style>
