<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import {
  PRESENTACIONES,
  esEnlaceDeVideo,
  type SeccionSitio,
} from "@/lib/sitioWeb";

/**
 * Los campos de un bloque propio del sitio (ADR 0146), según su tipo: título, texto,
 * foto, botón, fotos de la galería, preguntas, testimonios, beneficios, el enlace del
 * video o el formulario, y su estilo (fondo y alineación). Edita el bloque en su lugar;
 * la API valida al guardar.
 */
interface CatalogoBloques {
  max_titulo: number;
  max_texto: number;
  max_elementos: number;
  max_preguntas: number;
  fondos: string[];
  alineaciones: string[];
  formularios: { id: string; nombre: string }[];
}

const props = defineProps<{
  catalogo: CatalogoBloques;
  // `secciones.{i}`: para encontrar sus errores de validación.
  prefijo: string;
  errores: Record<string, string[]>;
}>();
const bloque = defineModel<SeccionSitio>({ required: true });

const { t } = useI18n();
// La API siempre manda los datos de un bloque; uno armado a mano, por si acaso.
if (!bloque.value.datos) {
  bloque.value.datos = {};
}
const datos = computed(() => bloque.value.datos!);
const lleva = (...tipos: string[]): boolean =>
  tipos.includes(bloque.value.tipo);

// Un campo vacío se guarda como null.
function texto(valor: string): string | null {
  return valor.trim() === "" ? null : valor;
}
function valor(e: Event): string | null {
  return texto((e.target as HTMLInputElement | HTMLTextAreaElement).value);
}

// Los errores de este bloque (su campo, sin el prefijo).
const errores = computed(() =>
  Object.entries(props.errores)
    .filter(([clave]) => clave.startsWith(`${props.prefijo}.`))
    .map(([, mensajes]) => mensajes[0])
    .filter(Boolean),
);

// ---- Listas: fotos, preguntas, testimonios, beneficios ----
const maxItems = computed(() =>
  bloque.value.tipo === "preguntas"
    ? props.catalogo.max_preguntas
    : props.catalogo.max_elementos,
);
function agregarItem(): void {
  const items = (datos.value.items ??= []);
  if (items.length < maxItems.value) {
    items.push(
      bloque.value.tipo === "preguntas"
        ? { pregunta: null, respuesta: null }
        : bloque.value.tipo === "testimonios"
          ? { nombre: null, texto: null, foto_url: null }
          : { titulo: null, texto: null },
    );
  }
}
function quitarItem(i: number): void {
  datos.value.items?.splice(i, 1);
}
// La galería: cada foto subida se agrega; el cargador vuelve a quedar vacío.
const cargadorNuevo = ref(0);
function agregarFoto(url: string | null): void {
  const fotos = (datos.value.fotos ??= []);
  if (url && fotos.length < props.catalogo.max_elementos) {
    fotos.push({ url, pie: null });
  }
  cargadorNuevo.value++;
}

const videoInvalido = computed(
  () =>
    bloque.value.tipo === "video" &&
    (datos.value.url ?? "") !== "" &&
    !esEnlaceDeVideo(datos.value.url ?? ""),
);
const id = computed(() => bloque.value.id ?? bloque.value.tipo);
</script>

<template>
  <div class="space-y-4" :data-prueba="`editor-${bloque.tipo}`">
    <div>
      <label class="tu-label" :for="`eb-titulo-${id}`">{{
        t("sitioWeb.bloques.campos.titulo")
      }}</label>
      <input
        :id="`eb-titulo-${id}`"
        class="tu-input"
        :value="bloque.titulo ?? ''"
        :maxlength="catalogo.max_titulo"
        :placeholder="t(`sitioWeb.bloques.nombres.${bloque.tipo}`)"
        @input="bloque.titulo = valor($event)"
      />
    </div>

    <div v-if="lleva('texto_imagen', 'llamado', 'video', 'formulario')">
      <label class="tu-label" :for="`eb-texto-${id}`">{{
        t("sitioWeb.bloques.campos.texto")
      }}</label>
      <textarea
        :id="`eb-texto-${id}`"
        class="tu-input"
        rows="4"
        :value="bloque.texto ?? ''"
        :maxlength="catalogo.max_texto"
        @input="bloque.texto = valor($event)"
      ></textarea>
    </div>

    <!-- Texto con imagen: la foto y de qué lado va -->
    <template v-if="lleva('texto_imagen')">
      <div>
        <span class="tu-label">{{ t("sitioWeb.bloques.campos.foto") }}</span>
        <CargadorImagen
          :url="bloque.foto_url"
          ruta="sitio/imagenes"
          campo="imagen"
          clave="url"
          quitar-sin-borrar
          proporcion="4 / 3"
          :arrastra="t('sitioWeb.secciones.campos.fotoArrastra')"
          @update:url="bloque.foto_url = $event"
        />
      </div>
      <div>
        <span class="tu-label">{{ t("sitioWeb.bloques.campos.lado") }}</span>
        <div class="tu-segmentado" role="group">
          <button
            v-for="lado in ['izquierda', 'derecha'] as const"
            :key="lado"
            type="button"
            :aria-pressed="(datos.lado ?? 'izquierda') === lado"
            @click="datos.lado = lado"
          >
            {{ t(`sitioWeb.bloques.campos.${lado}`) }}
          </button>
        </div>
      </div>
    </template>

    <!-- Botón (texto con imagen y llamado) -->
    <div
      v-if="lleva('texto_imagen', 'llamado')"
      class="grid gap-3 sm:grid-cols-2"
    >
      <div>
        <label class="tu-label" :for="`eb-boton-${id}`">{{
          t("sitioWeb.bloques.campos.botonTexto")
        }}</label>
        <input
          :id="`eb-boton-${id}`"
          class="tu-input"
          maxlength="40"
          :value="datos.boton_texto ?? ''"
          @input="datos.boton_texto = valor($event)"
        />
      </div>
      <div>
        <label class="tu-label" :for="`eb-enlace-${id}`">{{
          t("sitioWeb.bloques.campos.botonEnlace")
        }}</label>
        <input
          :id="`eb-enlace-${id}`"
          class="tu-input"
          maxlength="300"
          placeholder="#agenda"
          :value="datos.boton_enlace ?? ''"
          @input="datos.boton_enlace = valor($event)"
        />
      </div>
      <p class="eb-ayuda sm:col-span-2">
        {{ t("sitioWeb.banners.campos.enlaceAyuda") }}
      </p>
    </div>

    <!-- Cómo se muestra (ADR 0167, Plus): la galería y los testimonios -->
    <div v-if="lleva('galeria', 'testimonios')">
      <span class="tu-label">{{ t("sitioWeb.presentacion.titulo") }}</span>
      <div
        class="tu-segmentado"
        role="group"
        :aria-label="t('sitioWeb.presentacion.titulo')"
      >
        <button
          v-for="opcion in [null, ...(PRESENTACIONES[bloque.tipo] ?? [])]"
          :key="opcion ?? 'siempre'"
          type="button"
          :aria-pressed="(datos.presentacion ?? null) === opcion"
          :data-prueba="`presentacion-${opcion ?? 'siempre'}`"
          @click="datos.presentacion = opcion"
        >
          {{ t(`sitioWeb.presentacion.${opcion ?? "siempre"}`) }}
        </button>
      </div>
    </div>

    <!-- Galería -->
    <div v-if="lleva('galeria')">
      <span class="tu-label">{{ t("sitioWeb.bloques.campos.fotos") }}</span>
      <ul class="eb-fotos" data-prueba="galeria-fotos">
        <li v-for="(f, i) in datos.fotos ?? []" :key="f.url">
          <img :src="f.url" alt="" class="eb-miniatura" />
          <input
            class="tu-input text-sm"
            maxlength="120"
            :aria-label="t('sitioWeb.bloques.campos.pie')"
            :placeholder="t('sitioWeb.bloques.campos.pie')"
            :value="f.pie ?? ''"
            @input="f.pie = valor($event)"
          />
          <button
            type="button"
            class="tu-enlace text-sm"
            @click="datos.fotos?.splice(i, 1)"
          >
            {{ t("sitioWeb.bloques.campos.quitarFoto") }}
          </button>
        </li>
      </ul>
      <CargadorImagen
        v-if="(datos.fotos?.length ?? 0) < catalogo.max_elementos"
        :key="cargadorNuevo"
        class="mt-2"
        :url="null"
        ruta="sitio/imagenes"
        campo="imagen"
        clave="url"
        quitar-sin-borrar
        proporcion="16 / 9"
        :arrastra="t('sitioWeb.secciones.campos.fotoArrastra')"
        @update:url="agregarFoto"
      />
    </div>

    <!-- Preguntas, testimonios y beneficios -->
    <div v-if="lleva('preguntas', 'testimonios', 'beneficios')">
      <ol class="space-y-3" data-prueba="bloque-items">
        <li v-for="(item, i) in datos.items ?? []" :key="i" class="eb-item">
          <template v-if="bloque.tipo === 'preguntas'">
            <input
              class="tu-input"
              maxlength="200"
              :aria-label="t('sitioWeb.bloques.campos.pregunta')"
              :placeholder="t('sitioWeb.bloques.campos.pregunta')"
              :value="item.pregunta ?? ''"
              @input="item.pregunta = valor($event)"
            />
            <textarea
              class="tu-input mt-2"
              rows="2"
              maxlength="1000"
              :aria-label="t('sitioWeb.bloques.campos.respuesta')"
              :placeholder="t('sitioWeb.bloques.campos.respuesta')"
              :value="item.respuesta ?? ''"
              @input="item.respuesta = valor($event)"
            ></textarea>
          </template>
          <template v-else-if="bloque.tipo === 'testimonios'">
            <input
              class="tu-input"
              maxlength="80"
              :aria-label="t('sitioWeb.bloques.campos.nombre')"
              :placeholder="t('sitioWeb.bloques.campos.nombre')"
              :value="item.nombre ?? ''"
              @input="item.nombre = valor($event)"
            />
            <textarea
              class="tu-input mt-2"
              rows="2"
              maxlength="500"
              :aria-label="t('sitioWeb.bloques.campos.testimonio')"
              :placeholder="t('sitioWeb.bloques.campos.testimonio')"
              :value="item.texto ?? ''"
              @input="item.texto = valor($event)"
            ></textarea>
          </template>
          <template v-else>
            <input
              class="tu-input"
              :maxlength="catalogo.max_titulo"
              :aria-label="t('sitioWeb.bloques.campos.beneficio')"
              :placeholder="t('sitioWeb.bloques.campos.beneficio')"
              :value="item.titulo ?? ''"
              @input="item.titulo = valor($event)"
            />
            <input
              class="tu-input mt-2"
              maxlength="500"
              :aria-label="t('sitioWeb.bloques.campos.detalle')"
              :placeholder="t('sitioWeb.bloques.campos.detalle')"
              :value="item.texto ?? ''"
              @input="item.texto = valor($event)"
            />
          </template>
          <button
            type="button"
            class="tu-enlace mt-1 text-sm"
            @click="quitarItem(i)"
          >
            {{ t("sitioWeb.bloques.campos.quitar") }}
          </button>
        </li>
      </ol>
      <button
        v-if="(datos.items?.length ?? 0) < maxItems"
        type="button"
        class="tu-btn tu-btn-secundario mt-2"
        data-prueba="agregar-item"
        @click="agregarItem"
      >
        {{
          t(
            bloque.tipo === "preguntas"
              ? "sitioWeb.bloques.campos.agregarPregunta"
              : bloque.tipo === "testimonios"
                ? "sitioWeb.bloques.campos.agregarTestimonio"
                : "sitioWeb.bloques.campos.agregarBeneficio",
          )
        }}
      </button>
    </div>

    <!-- Video: solo su enlace (nunca código) y una portada opcional -->
    <template v-if="lleva('video')">
      <div>
        <label class="tu-label" :for="`eb-video-${id}`">{{
          t("sitioWeb.bloques.campos.video")
        }}</label>
        <input
          :id="`eb-video-${id}`"
          class="tu-input"
          type="url"
          maxlength="300"
          inputmode="url"
          placeholder="https://www.youtube.com/watch?v=…"
          :value="datos.url ?? ''"
          :aria-invalid="videoInvalido"
          data-prueba="video-url"
          @input="datos.url = valor($event)"
        />
        <p
          class="eb-ayuda"
          :style="videoInvalido ? { color: 'var(--error)' } : {}"
        >
          {{
            videoInvalido
              ? t("sitioWeb.bloques.campos.videoNoValido")
              : t("sitioWeb.bloques.campos.videoAyuda")
          }}
        </p>
      </div>
      <div>
        <span class="tu-label">{{
          t("sitioWeb.bloques.campos.portadaVideo")
        }}</span>
        <CargadorImagen
          :url="bloque.foto_url"
          ruta="sitio/imagenes"
          campo="imagen"
          clave="url"
          quitar-sin-borrar
          proporcion="16 / 9"
          :arrastra="t('sitioWeb.secciones.campos.fotoArrastra')"
          @update:url="bloque.foto_url = $event"
        />
      </div>
    </template>

    <!-- Formulario de captación -->
    <div v-if="lleva('formulario')">
      <label class="tu-label" :for="`eb-formulario-${id}`">{{
        t("sitioWeb.bloques.campos.formulario")
      }}</label>
      <p
        v-if="catalogo.formularios.length === 0"
        class="eb-ayuda"
        data-prueba="sin-formularios"
      >
        {{ t("sitioWeb.bloques.campos.sinFormularios") }}
      </p>
      <select
        v-else
        :id="`eb-formulario-${id}`"
        class="tu-input"
        :value="datos.formulario_id ?? ''"
        @change="datos.formulario_id = valor($event)"
      >
        <option value="">
          {{ t("sitioWeb.bloques.campos.elegirFormulario") }}
        </option>
        <option v-for="f in catalogo.formularios" :key="f.id" :value="f.id">
          {{ f.nombre }}
        </option>
      </select>
      <!-- Su formulario se apagó o se borró: el bloque no sale en la página. -->
      <AvisoTono
        v-if="
          datos.formulario_id &&
          !catalogo.formularios.some((f) => f.id === datos.formulario_id)
        "
        tono="aviso"
        class="mt-2"
        data-prueba="formulario-apagado"
      >
        {{ t("operacion.sitio.formularioApagado") }}
      </AvisoTono>
    </div>

    <!-- Estilo -->
    <fieldset class="eb-estilo">
      <legend class="tu-label">
        {{ t("sitioWeb.bloques.campos.estilo") }}
      </legend>
      <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
        <div class="flex items-center gap-2">
          <span class="text-sm eb-suave">{{
            t("sitioWeb.bloques.campos.fondo")
          }}</span>
          <div class="tu-segmentado" role="group" data-prueba="bloque-fondo">
            <button
              v-for="fondo in catalogo.fondos"
              :key="fondo"
              type="button"
              :aria-pressed="(bloque.estilo?.fondo ?? 'normal') === fondo"
              @click="
                bloque.estilo = {
                  alineacion: bloque.estilo?.alineacion ?? 'izquierda',
                  fondo: fondo as 'normal',
                }
              "
            >
              {{ t(`sitioWeb.bloques.fondos.${fondo}`) }}
            </button>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-sm eb-suave">{{
            t("sitioWeb.bloques.campos.alineacion")
          }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="alineacion in catalogo.alineaciones"
              :key="alineacion"
              type="button"
              :aria-pressed="
                (bloque.estilo?.alineacion ?? 'izquierda') === alineacion
              "
              @click="
                bloque.estilo = {
                  fondo: bloque.estilo?.fondo ?? 'normal',
                  alineacion: alineacion as 'izquierda',
                }
              "
            >
              {{ t(`sitioWeb.bloques.alineaciones.${alineacion}`) }}
            </button>
          </div>
        </div>
      </div>
    </fieldset>

    <p
      v-for="(mensaje, i) in errores"
      :key="i"
      class="text-sm"
      style="color: var(--error)"
    >
      {{ mensaje }}
    </p>
  </div>
</template>

<style scoped>
.eb-ayuda {
  margin-top: 0.3rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.eb-suave {
  color: var(--texto-suave);
}
.eb-fotos {
  display: grid;
  gap: 0.6rem;
}
.eb-fotos li {
  display: grid;
  grid-template-columns: 4.5rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.6rem;
}
.eb-miniatura {
  width: 4.5rem;
  height: 3rem;
  border-radius: 0.5rem;
  object-fit: cover;
}
.eb-item {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
}
.eb-estilo {
  padding-top: 0.75rem;
  border-top: 1px solid var(--borde);
}
</style>
