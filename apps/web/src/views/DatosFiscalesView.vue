<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoRegion from "@/components/AvisoRegion.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  opcionesRegimen,
  regimenCorresponde,
  regimenFiscal,
  tipoPersonaDeRfc,
  type TipoPersona,
} from "@/lib/catalogosSat";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Datos fiscales del negocio (el emisor de sus facturas). El régimen se elige del
 * catálogo del SAT con buscador; arriba, como lista, lo que falta para timbrar:
 * datos del emisor, sello digital (CSD) y timbres.
 */
interface DatosFiscales {
  razon_social: string;
  rfc: string;
  regimen_fiscal: string;
  codigo_postal: string;
  sellos_cargados: boolean;
  // Su alta en FacturAPI (ADR 0212).
  facturacion_lista?: boolean;
  pendientes?: string[];
  error?: string | null;
  portal_manifiesto?: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const form = ref({
  razon_social: "",
  rfc: "",
  regimen_fiscal: "",
  codigo_postal: "",
});
// Lo último guardado: la lista de requisitos habla de esto, no de lo que se escribe.
const guardados = ref<DatosFiscales | null>(null);
const sellosCargados = ref(false);
const cargando = ref(true);
// ¿Puede facturar? Solo en pesos mexicanos y en México.
const disponible = ref(true);
const errorCarga = ref<string | null>(null);
// Timbres disponibles (si puede ver la facturación); null = no se sabe.
const timbres = ref<number | null>(null);

// El patrón común de los formularios: el error junto a su campo, un resumen arriba,
// y preguntar antes de salir con cambios sin guardar.
const siempreAbierto = ref(true);
const {
  guardando,
  errores,
  error,
  empezar,
  marcar,
  guardar: guardarFormulario,
} = useEditorFormulario({
  abierto: siempreAbierto,
  valor: () => form.value,
  campos: ["razon_social", "rfc", "regimen_fiscal", "codigo_postal"],
  formulario: "#form-fiscales",
});

const cer = ref<File | null>(null);
const key = ref<File | null>(null);
const selloPassword = ref("");
const subiendoSello = ref(false);
const mensajeSello = ref<string | null>(null);
const errorSello = ref<string | null>(null);

// Régimen: del catálogo del SAT, primero los que corresponden al RFC escrito.
const tipoPersona = computed(() => tipoPersonaDeRfc(form.value.rfc));
function textoPersonas(personas: TipoPersona[]): string {
  return personas.length > 1
    ? t("datosFiscalesSat.regimen.personas.ambas")
    : t(`datosFiscalesSat.regimen.personas.${personas[0] ?? "fisica"}`);
}
const opciones = computed(() =>
  opcionesRegimen(tipoPersona.value, {
    persona: textoPersonas,
    paraTuRfc: t("datosFiscalesSat.regimen.paraTuRfc"),
    otros: t("datosFiscalesSat.regimen.otros"),
  }),
);
const avisoRegimen = computed(() => {
  const tipo = tipoPersona.value;
  return tipo !== null &&
    form.value.regimen_fiscal !== "" &&
    !regimenCorresponde(form.value.regimen_fiscal, tipo)
    ? t(`datosFiscalesSat.regimen.noCorresponde.${tipo}`)
    : null;
});

// Lo que se necesita para timbrar, con lo guardado.
interface Requisito {
  clave: string;
  hecho: boolean;
  detalle?: string;
}
const requisitos = computed<Requisito[]>(() => {
  const d = guardados.value;
  const tipo = d === null ? null : tipoPersonaDeRfc(d.rfc);
  const lista: Requisito[] = [
    {
      clave: "razonRfc",
      hecho: d !== null && d.razon_social.trim() !== "" && tipo !== null,
    },
    {
      clave: "regimen",
      hecho:
        d !== null &&
        regimenFiscal(d.regimen_fiscal) !== undefined &&
        regimenCorresponde(d.regimen_fiscal, tipo),
    },
    {
      clave: "codigoPostal",
      hecho: d !== null && /^[0-9]{5}$/.test(d.codigo_postal),
    },
    { clave: "sellos", hecho: sellosCargados.value },
  ];
  if (timbres.value !== null) {
    lista.push({
      clave: "timbres",
      hecho: timbres.value > 0,
      detalle: t("datosFiscalesSat.requisitos.timbresCuantos", {
        n: timbres.value,
      }),
    });
  }
  return lista;
});
const faltan = computed(() => requisitos.value.filter((r) => !r.hecho).length);

function aplicar(d: DatosFiscales): void {
  form.value = {
    razon_social: d.razon_social,
    rfc: d.rfc,
    regimen_fiscal: d.regimen_fiscal,
    codigo_postal: d.codigo_postal,
  };
  guardados.value = d;
  sellosCargados.value = d.sellos_cargados;
  empezar();
}

// Vuelve a preguntar a FacturAPI si ya puede facturar (tras firmar la carta).
const revisando = ref(false);
async function revisarAlta(): Promise<void> {
  revisando.value = true;
  try {
    const { data } = await api.post<{ data: DatosFiscales }>(
      `${base.value}/datos-fiscales/revisar`,
      {},
    );
    guardados.value = data.data;
    toast.exito(t("cfdi.alta.revisado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    revisando.value = false;
  }
}

async function cargarTimbres(): Promise<void> {
  if (!sesion.puede("facturacion.ver")) {
    return;
  }
  try {
    const { data } = await api.get<{ data: { disponibles: number } }>(
      `${base.value}/timbres`,
    );
    timbres.value = data.data.disponibles;
  } catch {
    // Sin el dato, la lista no lo muestra (no es «sin timbres»).
    timbres.value = null;
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const { data } = await api.get<{
      data: DatosFiscales | null;
      meta?: { disponible: boolean };
    }>(`${base.value}/datos-fiscales`);
    disponible.value = data.meta?.disponible !== false;
    if (data.data !== null) {
      aplicar(data.data);
    } else {
      empezar();
    }
    if (disponible.value) {
      await cargarTimbres();
    }
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(): Promise<void> {
  const respuesta = await guardarFormulario(
    () =>
      api.put<{ data: DatosFiscales }>(
        `${base.value}/datos-fiscales`,
        form.value,
      ),
    t("datosFiscalesSat.guardado", { razon: form.value.razon_social.trim() }),
  );
  if (respuesta !== undefined) {
    aplicar(respuesta.data.data);
  }
}

async function subirSello(): Promise<void> {
  if (cer.value === null || key.value === null || selloPassword.value === "") {
    return;
  }
  subiendoSello.value = true;
  errorSello.value = null;
  mensajeSello.value = null;
  try {
    const fd = new FormData();
    fd.append("certificado", cer.value);
    fd.append("llave", key.value);
    fd.append("password", selloPassword.value);
    const { data } = await api.post<{ data: DatosFiscales }>(
      `${base.value}/datos-fiscales/sello`,
      fd,
    );
    sellosCargados.value = data.data.sellos_cargados;
    guardados.value = data.data;
    selloPassword.value = "";
    cer.value = null;
    key.value = null;
    mensajeSello.value = "ok";
  } catch (e) {
    errorSello.value = mensajeDeError(e);
  } finally {
    subiendoSello.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('datosFiscales.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <AvisoTono
      v-else-if="errorCarga"
      tono="peligro"
      class="mt-6"
      role="alert"
      data-prueba="error-carga"
    >
      {{ errorCarga }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <!-- Solo en pesos mexicanos y en México (ADR 0099): ni se cargan datos. -->
    <AvisoRegion v-else-if="!disponible" class="mt-6" tipo="facturacion" />

    <template v-else>
      <!-- Lo que falta para timbrar, con lo guardado -->
      <div class="mt-6 tu-card p-6" data-prueba="requisitos">
        <h2 class="font-medium">
          {{ $t("datosFiscalesSat.requisitos.titulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            faltan === 0
              ? $t("datosFiscalesSat.requisitos.listo")
              : $t("datosFiscalesSat.requisitos.faltan", faltan)
          }}
        </p>
        <ul class="mt-4">
          <li
            v-for="r in requisitos"
            :key="r.clave"
            class="df-requisito text-sm"
            :data-prueba="`requisito-${r.clave}`"
          >
            <span class="min-w-0">
              {{ $t(`datosFiscalesSat.requisitos.${r.clave}`) }}
              <span v-if="r.detalle" :style="{ color: 'var(--texto-suave)' }">
                · {{ r.detalle }}</span
              >
              <RouterLink
                v-if="r.clave === 'timbres'"
                :to="{ name: 'timbres' }"
                class="tu-enlace ml-2"
                >{{ $t("datosFiscalesSat.requisitos.verTimbres") }}</RouterLink
              >
            </span>
            <span
              class="tu-estado shrink-0"
              :style="{ '--tono': r.hecho ? 'var(--exito)' : 'var(--aviso)' }"
              data-prueba="estado-requisito"
              >{{
                r.hecho
                  ? $t("datosFiscalesSat.requisitos.hecho")
                  : $t("datosFiscalesSat.requisitos.falta")
              }}</span
            >
          </li>
        </ul>
      </div>

      <!-- Datos fiscales -->
      <div class="mt-6 tu-card p-6">
        <form
          id="form-fiscales"
          class="space-y-4"
          novalidate
          @submit.prevent="guardar"
        >
          <AvisoTono v-if="error" tono="peligro" role="alert">
            {{ error }}
          </AvisoTono>
          <div>
            <label class="tu-label" for="rs">{{
              $t("datosFiscales.razonSocial")
            }}</label>
            <input
              id="rs"
              v-bind="marcar('razon_social', 'rs-error')"
              v-model="form.razon_social"
              class="tu-input"
              maxlength="255"
              required
            />
            <span
              v-if="errores.razon_social"
              id="rs-error"
              class="tu-error-campo"
              >{{ errores.razon_social }}</span
            >
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="tu-label" for="rfc">{{
                $t("datosFiscales.rfc")
              }}</label>
              <input
                id="rfc"
                v-bind="marcar('rfc', 'rfc-error')"
                v-model="form.rfc"
                class="tu-input uppercase"
                maxlength="13"
                autocomplete="off"
                required
              />
              <span v-if="errores.rfc" id="rfc-error" class="tu-error-campo">{{
                errores.rfc
              }}</span>
              <p
                v-else
                class="mt-1 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("datosFiscalesSat.rfcAyuda") }}
              </p>
            </div>
            <div>
              <label class="tu-label" for="cp">{{
                $t("datosFiscales.codigoPostal")
              }}</label>
              <input
                id="cp"
                v-bind="marcar('codigo_postal', 'cp-error')"
                v-model="form.codigo_postal"
                class="tu-input"
                inputmode="numeric"
                maxlength="5"
                required
              />
              <span
                v-if="errores.codigo_postal"
                id="cp-error"
                class="tu-error-campo"
                >{{ errores.codigo_postal }}</span
              >
              <p
                v-else
                class="mt-1 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("datosFiscalesSat.codigoPostalAyuda") }}
              </p>
            </div>
          </div>
          <div>
            <label class="tu-label" for="reg">{{
              $t("datosFiscalesSat.regimen.etiqueta")
            }}</label>
            <SelectorBuscable
              id="reg"
              v-bind="marcar('regimen_fiscal', 'reg-error')"
              v-model="form.regimen_fiscal"
              :opciones="opciones"
              :placeholder="$t('datosFiscalesSat.regimen.placeholder')"
              data-prueba="regimen"
            />
            <span
              v-if="errores.regimen_fiscal"
              id="reg-error"
              class="tu-error-campo"
              >{{ errores.regimen_fiscal }}</span
            >
            <p
              v-else-if="avisoRegimen"
              class="mt-1 text-xs"
              :style="{ color: 'var(--aviso)' }"
              role="status"
              data-prueba="aviso-regimen"
            >
              {{ avisoRegimen }}
            </p>
            <p
              v-else
              class="mt-1 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("datosFiscalesSat.regimen.ayuda") }}
            </p>
          </div>

          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="guardando"
          >
            {{
              guardando
                ? $t("datosFiscales.guardando")
                : $t("datosFiscales.guardar")
            }}
          </button>
        </form>
      </div>

      <!-- Sello digital (CSD) -->
      <div class="mt-6 tu-card p-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="font-medium">{{ $t("datosFiscales.sellos.titulo") }}</h2>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("datosFiscales.sellos.subtitulo") }}
            </p>
          </div>
          <span
            class="tu-estado shrink-0 text-sm"
            :style="{
              '--tono': sellosCargados ? 'var(--exito)' : 'var(--aviso)',
            }"
          >
            {{
              sellosCargados
                ? $t("datosFiscales.sellos.cargados")
                : $t("datosFiscales.sellos.pendientes")
            }}
          </span>
        </div>

        <p
          v-if="guardados === null"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("datosFiscales.sellos.requiereDatos") }}
        </p>

        <form v-else class="mt-4 space-y-4" @submit.prevent="subirSello">
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="tu-label" for="cer">{{
                $t("datosFiscales.sellos.certificado")
              }}</label>
              <ZonaArchivo
                id="cer"
                compacta
                icono="archivo"
                accept=".cer"
                :ayuda="$t('zonaArchivo.formatos.cer')"
                :cargado="cer?.name ?? null"
                :ocupado="subiendoSello"
                @archivo="cer = $event"
              />
            </div>
            <div>
              <label class="tu-label" for="key">{{
                $t("datosFiscales.sellos.llave")
              }}</label>
              <ZonaArchivo
                id="key"
                compacta
                icono="archivo"
                accept=".key"
                :ayuda="$t('zonaArchivo.formatos.key')"
                :cargado="key?.name ?? null"
                :ocupado="subiendoSello"
                @archivo="key = $event"
              />
            </div>
          </div>
          <div>
            <label class="tu-label" for="sp">{{
              $t("datosFiscales.sellos.password")
            }}</label>
            <input
              id="sp"
              v-model="selloPassword"
              class="tu-input"
              type="password"
              autocomplete="off"
              required
            />
          </div>

          <AvisoTono v-if="mensajeSello" tono="exito">
            {{ $t("datosFiscales.sellos.cargado") }}
          </AvisoTono>
          <AvisoTono v-if="errorSello" tono="peligro" role="alert">
            {{ errorSello }}
          </AvisoTono>

          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="subiendoSello"
          >
            {{
              subiendoSello
                ? $t("datosFiscales.sellos.cargando")
                : $t("datosFiscales.sellos.cargar")
            }}
          </button>
        </form>

        <!-- Su alta en FacturAPI (ADR 0212): ya factura o lo que le falta. -->
        <div
          v-if="sellosCargados && guardados"
          class="mt-5 space-y-3"
          data-prueba="alta-facturapi"
        >
          <AvisoTono v-if="guardados.facturacion_lista" tono="exito">
            {{ $t("cfdi.alta.lista") }}
          </AvisoTono>
          <template v-else>
            <AvisoTono tono="aviso">
              <p>{{ $t("cfdi.alta.pendiente") }}</p>
              <ul class="mt-1 list-disc pl-5">
                <li v-for="p in guardados.pendientes ?? []" :key="p">
                  {{ p }}
                </li>
                <li v-if="(guardados.pendientes ?? []).length === 0">
                  {{ $t("cfdi.alta.firmarCarta") }}
                </li>
              </ul>
            </AvisoTono>
            <AvisoTono v-if="guardados.error" tono="peligro" role="alert">
              {{ $t("cfdi.alta.error", { motivo: guardados.error }) }}
            </AvisoTono>
            <div class="flex flex-wrap gap-2">
              <a
                v-if="guardados.portal_manifiesto"
                class="tu-btn tu-btn-secundario"
                :href="guardados.portal_manifiesto"
                target="_blank"
                rel="noopener"
                >{{ $t("cfdi.alta.abrirPortal") }}</a
              >
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="revisando"
                data-prueba="revisar-alta"
                @click="revisarAlta"
              >
                {{ $t("cfdi.alta.revisar") }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.df-requisito {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 0;
  border-top: 1px solid var(--borde);
}
.df-requisito:first-child {
  border-top: 0;
}
</style>
