<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Reservas desde la app de Wellhub o TotalPass en Integraciones (ADR 0149): qué clases se
 * publican, con qué producto (Wellhub) o plan (TotalPass) y de qué sucursal. Las reservas
 * que llegan ocupan lugar sin consumir créditos; se aceptan o rechazan solas.
 */
interface ClasePublicable {
  oferta_id: string;
  nombre: string;
  publicada: boolean;
  producto: string | null;
  error: string | null;
}
interface Configuracion {
  disponible: boolean;
  conectada: boolean;
  activas: boolean;
  sucursal_id: string | null;
  sucursales: { id: string; nombre: string }[];
  dias_publicados: number;
  lugares_sin_tope: number;
  avisos: { registrados: boolean };
  productos: { id: string; nombre: string; virtual: boolean }[] | null;
  clases: ClasePublicable[];
  horarios: {
    publicados: number;
    con_error: number;
    ultimo_error: string | null;
  };
  reservas_por_venir: number;
  error_avisos?: string | null;
}

// `sucursal`: la conexión de esa sede (cada sede es otro gimnasio, ADR 0202).
const props = defineProps<{ proveedor: string; sucursal?: string }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const config = ref<Configuracion | null>(null);
const productos = ref<Configuracion["productos"]>(null);
const guardando = ref(false);
const edicion = reactive<{
  activas: boolean;
  sucursal_id: string;
  clases: Record<string, { publicada: boolean; producto: string }>;
}>({ activas: false, sucursal_id: "", clases: {} });

const url = computed(
  () => `/api/v1/app/${sesion.slug}/integraciones/${props.proveedor}/reservas`,
);
const params = computed(() =>
  props.sucursal ? { sucursal: props.sucursal } : {},
);
const plataforma = computed(() =>
  t(`operacion.plataformas.proveedores.${props.proveedor}`),
);

function tomar(datos: Configuracion): void {
  config.value = datos;
  // Al guardar no se vuelven a consultar: se conservan los que ya se tenían.
  if (datos.productos !== null) {
    productos.value = datos.productos;
  }
  edicion.activas = datos.activas;
  edicion.sucursal_id =
    props.sucursal ??
    datos.sucursal_id ??
    (datos.sucursales.length === 1 ? datos.sucursales[0].id : "");
  edicion.clases = Object.fromEntries(
    datos.clases.map((c) => [
      c.oferta_id,
      { publicada: c.publicada, producto: c.producto ?? "" },
    ]),
  );
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Configuracion }>(url.value, {
      params: params.value,
    });
    tomar(data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  try {
    const { data } = await api.put<{ data: Configuracion }>(
      url.value,
      {
        activas: edicion.activas,
        sucursal_id: edicion.sucursal_id || null,
        clases: Object.entries(edicion.clases).map(([oferta_id, c]) => ({
          oferta_id,
          publicada: c.publicada,
          producto: c.producto || null,
        })),
      },
      { params: params.value },
    );
    tomar(data.data);
    if (data.data.error_avisos) {
      toast.error(data.data.error_avisos);
    } else {
      toast.exito(t("operacion.plataformas.reservas.guardado"));
    }
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section
    v-if="config?.disponible"
    class="rb mt-4"
    data-prueba="reservas-plataforma"
  >
    <div class="rb-cabeza">
      <span class="tu-apartado-icono" aria-hidden="true">
        <IconoNav nombre="agenda" :tam="18" />
      </span>
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-sm font-semibold">
            {{ $t("operacion.plataformas.reservas.titulo") }}
          </p>
          <span
            class="tu-pastilla"
            :class="config.activas ? 'tu-tono-exito' : 'tu-tono-neutral'"
            data-prueba="reservas-plataforma-estado"
            >{{
              config.activas
                ? $t("operacion.plataformas.reservas.publicando")
                : $t("operacion.plataformas.reservas.sinPublicar")
            }}</span
          >
        </div>
        <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.plataformas.reservas.ayuda", { plataforma }) }}
        </p>
      </div>
    </div>

    <InterruptorSiNo
      v-model="edicion.activas"
      class="mt-4"
      :etiqueta="$t('operacion.plataformas.reservas.publicar', { plataforma })"
      prueba="reservas-activas"
    />

    <template v-if="edicion.activas">
      <!-- La conexión de una sede publica las clases de esa sede. -->
      <div v-if="!sucursal" class="mt-3 max-w-sm">
        <label class="tu-label" :for="`rb-sucursal-${proveedor}`">{{
          $t("operacion.plataformas.reservas.sucursal")
        }}</label>
        <select
          :id="`rb-sucursal-${proveedor}`"
          v-model="edicion.sucursal_id"
          class="tu-input"
        >
          <option value="" disabled>
            {{ $t("operacion.plataformas.reservas.elegirSucursal") }}
          </option>
          <option v-for="s in config.sucursales" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
      </div>

      <p class="tu-label mt-4">
        {{ $t("operacion.plataformas.reservas.clases") }}
      </p>
      <p
        v-if="productos === null"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t(`operacion.plataformas.reservas.sinProductos.${proveedor}`) }}
      </p>
      <p
        v-if="config.clases.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("operacion.plataformas.reservas.sinClases") }}
      </p>
      <ul v-else class="rb-clases mt-1">
        <li
          v-for="c in config.clases"
          :key="c.oferta_id"
          class="rb-clase"
          data-prueba="reservas-clase"
        >
          <label class="flex min-w-0 items-center gap-2 text-sm cursor-pointer">
            <input
              v-model="edicion.clases[c.oferta_id].publicada"
              type="checkbox"
            />
            <span class="truncate">{{ c.nombre }}</span>
          </label>
          <div v-if="edicion.clases[c.oferta_id].publicada" class="rb-producto">
            <select
              v-if="productos !== null"
              v-model="edicion.clases[c.oferta_id].producto"
              class="tu-input text-sm"
              :aria-label="
                $t(`operacion.plataformas.reservas.producto.${proveedor}`)
              "
            >
              <option value="" disabled>
                {{
                  $t(
                    `operacion.plataformas.reservas.elegirProducto.${proveedor}`,
                  )
                }}
              </option>
              <option v-for="p in productos" :key="p.id" :value="p.id">
                {{ p.nombre }}
              </option>
            </select>
            <input
              v-else
              v-model="edicion.clases[c.oferta_id].producto"
              class="tu-input text-sm"
              inputmode="numeric"
              :placeholder="
                $t(`operacion.plataformas.reservas.productoManual.${proveedor}`)
              "
              :aria-label="
                $t(`operacion.plataformas.reservas.producto.${proveedor}`)
              "
            />
          </div>
          <p
            v-if="c.error"
            class="tu-estado rb-error text-sm"
            :style="{ '--tono': 'var(--error)' }"
          >
            {{ c.error }}
          </p>
        </li>
      </ul>
      <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          $t("operacion.plataformas.reservas.alcance", {
            dias: config.dias_publicados,
            lugares: config.lugares_sin_tope,
          })
        }}
      </p>
    </template>

    <p
      v-if="config.activas"
      class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm"
      data-prueba="reservas-plataforma-resumen"
    >
      <span>{{
        $t("operacion.plataformas.reservas.publicados", {
          n: config.horarios.publicados,
        })
      }}</span>
      <span>{{
        $t("operacion.plataformas.reservas.porVenir", {
          n: config.reservas_por_venir,
        })
      }}</span>
      <span
        v-if="config.horarios.con_error > 0"
        class="tu-estado"
        :style="{ '--tono': 'var(--aviso)' }"
        :title="config.horarios.ultimo_error ?? undefined"
        >{{
          $t("operacion.plataformas.reservas.conError", {
            n: config.horarios.con_error,
          })
        }}</span
      >
    </p>
    <p
      v-if="config.activas"
      class="tu-estado mt-1 text-sm"
      :style="{
        '--tono': config.avisos.registrados ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{
        config.avisos.registrados
          ? $t("operacion.plataformas.reservas.avisosRegistrados", {
              plataforma,
            })
          : $t("operacion.plataformas.reservas.avisosPendientes", {
              plataforma,
            })
      }}
    </p>

    <button
      type="button"
      class="tu-btn tu-btn-solido tu-tono-info mt-4 text-sm"
      :disabled="guardando"
      data-prueba="reservas-guardar"
      @click="guardar"
    >
      {{
        guardando
          ? $t("operacion.plataformas.reservas.guardando")
          : $t("operacion.plataformas.reservas.guardar")
      }}
    </button>
  </section>
</template>

<style scoped>
.rb {
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: color-mix(in srgb, var(--superficie-2) 60%, var(--superficie));
}
.rb-cabeza {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}
.rb-clases {
  display: grid;
  gap: 0.5rem;
}
.rb-clase {
  display: grid;
  gap: 0.4rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  background: var(--superficie);
}
@media (min-width: 640px) {
  .rb-clase {
    grid-template-columns: minmax(0, 1fr) minmax(0, 16rem);
    align-items: center;
  }
  .rb-error {
    grid-column: 1 / -1;
  }
}
</style>
