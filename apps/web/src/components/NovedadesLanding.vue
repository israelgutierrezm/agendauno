<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import TextoDestacado from "@/components/TextoDestacado.vue";
import { MODALIDADES, type Modo } from "@/marketing/modalidades";

/*
| «Lo nuevo» de cada modalidad (/clases y /citas), alto en la página: justo después de
| los sellos de confianza, en la misma banda gris del hero (como los sellos), antes de
| la agenda de ejemplo. Seis novedades de alto impacto: las claves en
| MODALIDADES[modo].novedades y sus textos en landing.{modo}.novedades.items.{clave}
| (título, texto y tres puntos, `p1`–`p3`).
| Cada tarjeta lleva un ejemplo ilustrativo (pantallitas con datos ficticios, como los
| de FuncionesLanding): sin fotos, sin íconos teñidos, estados como punto + texto y
| sin mezclar clases con citas. Los ejemplos no llevan la marca: los textos con marca
| van en i18n (conMarca la cambia en TurnoUno). Lo que solo opera en México lleva «*»
| y la nota. El botón al registro lo pone la página (slot), con su medición.
*/
const props = defineProps<{ modo: Modo }>();
defineSlots<{ default?: () => unknown }>();
const { t } = useI18n();

/** Clave de i18n de esta sección: `landing.{modo}.novedades.{resto}`. */
const k = (resto: string): string => `landing.${props.modo}.novedades.${resto}`;
const PUNTOS = ["p1", "p2", "p3"] as const;

type Marca = "etiqueta" | "estado" | "boton" | "suave";
interface Fila {
  texto: string;
  detalle?: string;
  valor?: string;
  marca?: Marca;
}
interface Aviso {
  texto: string;
  punto?: boolean;
}
type Visual =
  | {
      tipo: "lista";
      titulo: string;
      subtitulo: string;
      filas: readonly Fila[];
      /** Control segmentado (el primero, elegido) antes de las filas. */
      opciones?: readonly [string, string];
      /** Acción principal y secundaria al pie. */
      acciones?: readonly [string, string];
      aviso?: Aviso;
    }
  | { tipo: "navegador"; url: string; filas: readonly Fila[] }
  | {
      tipo: "pase";
      negocio: string;
      nombre: string;
      datos: readonly (readonly [string, string])[];
      aviso: Aviso;
    }
  | {
      tipo: "telefono";
      titulo: string;
      lineas: readonly string[];
      /** Un visor con el código del pase (la app del equipo lo escanea). */
      qr?: boolean;
      estado?: string;
      botones?: readonly string[];
      lado: { titulo: string; lineas: readonly string[] };
    }
  | {
      tipo: "mensajes";
      mensajes: readonly {
        quien: string;
        canal: string;
        texto: string;
        nuevo?: boolean;
      }[];
      respuesta: string;
    };

// Ejemplos ilustrativos, con datos ficticios, de cada novedad.
const VISUALES: Record<Modo, Record<string, Visual>> = {
  clases: {
    plataformas: {
      tipo: "lista",
      titulo: "Pilates Reformer",
      subtitulo: "Hoy 18:00 · 8 de 8",
      filas: [
        { texto: "Ana López", valor: "Directa", marca: "etiqueta" },
        { texto: "Carlos Ruiz", valor: "Wellhub", marca: "etiqueta" },
        { texto: "Mariana Tello", valor: "TotalPass", marca: "etiqueta" },
      ],
      aviso: { texto: "Check-in validado", punto: true },
    },
    wallet: {
      tipo: "pase",
      negocio: "Estudio Alma",
      nombre: "Ana López",
      datos: [
        ["Plan", "8 clases al mes"],
        ["Vence", "30 nov"],
        ["Saldo", "5 créditos"],
      ],
      aviso: { texto: "Se actualizó solo", punto: true },
    },
    widgets: {
      tipo: "navegador",
      url: "tuestudio.com/horarios",
      filas: [
        {
          texto: "Yoga",
          detalle: "Lun 07:00",
          valor: "4 lugares",
          marca: "suave",
        },
        {
          texto: "Pilates",
          detalle: "Lun 18:00",
          valor: "Lo quiero",
          marca: "boton",
        },
        {
          texto: "Barre",
          detalle: "Mar 19:00",
          valor: "Lleno",
          marca: "suave",
        },
      ],
    },
    app: {
      tipo: "telefono",
      titulo: "Escanear pase",
      lineas: [],
      qr: true,
      estado: "Ana López · Llegó",
      lado: {
        titulo: "Pase de lista",
        lineas: ["Pilates · 18:00", "7 de 8 llegaron"],
      },
    },
    bandeja: {
      tipo: "mensajes",
      mensajes: [
        {
          quien: "Lucía",
          canal: "WhatsApp",
          texto: "¿Hay lugar mañana a las 7?",
          nuevo: true,
        },
        {
          quien: "@sofi.fit",
          canal: "Instagram",
          texto: "¿Cuánto cuesta el paquete de 8?",
        },
      ],
      respuesta: "Sí, quedan 3 lugares",
    },
    caja: {
      tipo: "lista",
      titulo: "Cierre de caja",
      subtitulo: "Sucursal Centro",
      filas: [
        { texto: "Efectivo", detalle: "contado", valor: "$2,450" },
        { texto: "Tarjeta", detalle: "según su reporte", valor: "$3,180" },
        { texto: "Retiro", detalle: "pago a proveedor", valor: "−$400" },
      ],
      aviso: { texto: "Cuadra", punto: true },
    },
  },
  citas: {
    anticipo: {
      tipo: "lista",
      titulo: "Color y corte",
      subtitulo: "Sáb 11:00 · con Ana",
      opciones: ["Anticipo $300", "Todo $1,200"],
      filas: [{ texto: "Resto, en el negocio", valor: "$900" }],
      aviso: { texto: "Se devuelve si cancela a tiempo", punto: true },
    },
    presupuestos: {
      tipo: "lista",
      titulo: "Presupuesto P-0012",
      subtitulo: "Vigente 15 días",
      filas: [
        { texto: "Facial hidratante", detalle: "× 3", valor: "$2,400" },
        { texto: "Sérum de vitamina C", valor: "$450" },
      ],
      acciones: ["Aceptar y pagar", "Rechazar"],
    },
    factura: {
      tipo: "lista",
      titulo: "Facturas de la cita",
      subtitulo: "Color y corte",
      filas: [
        { texto: "CFDI del anticipo", valor: "Timbrado", marca: "estado" },
        { texto: "CFDI del servicio", valor: "Timbrado", marca: "estado" },
        {
          texto: "CFDI de egreso",
          valor: "Aplica el anticipo",
          marca: "estado",
        },
      ],
    },
    widget: {
      tipo: "navegador",
      url: "tunegocio.com/agendar",
      filas: [
        {
          texto: "Corte",
          detalle: "45 min · $250",
          valor: "Agendar",
          marca: "boton",
        },
        {
          texto: "Corte y barba",
          detalle: "60 min · $350",
          valor: "Agendar",
          marca: "boton",
        },
        {
          texto: "Color",
          detalle: "anticipo $300",
          valor: "Agendar",
          marca: "boton",
        },
      ],
    },
    bandeja: {
      tipo: "mensajes",
      mensajes: [
        {
          quien: "Lucía",
          canal: "WhatsApp",
          texto: "¿Tienes espacio el sábado?",
          nuevo: true,
        },
        {
          quien: "@ana.nails",
          canal: "Instagram",
          texto: "¿Cuánto cuesta el gelish?",
        },
      ],
      respuesta: "Sí, a las 11:00 con Marco",
    },
    app: {
      tipo: "telefono",
      titulo: "Tu próxima cita",
      lineas: ["Corte y barba", "Vie 18:00 · con Marco"],
      botones: ["Pagar", "Reprogramar"],
      lado: {
        titulo: "Presupuesto P-0012",
        lineas: ["Por responder", "$2,850"],
      },
    },
  },
};

const novedades = computed(() =>
  MODALIDADES[props.modo].novedades.map((clave) => ({
    clave,
    titulo: t(k(`items.${clave}.titulo`)),
    texto: t(k(`items.${clave}.texto`)),
    puntos: PUNTOS.map((p) => t(k(`items.${clave}.puntos.${p}`))),
    visual: VISUALES[props.modo][clave],
  })),
);
// La nota de México solo si alguna novedad lleva «*».
const conNotaMexico = computed(() =>
  novedades.value.some((n) =>
    [n.titulo, n.texto, ...n.puntos].some((texto) => texto.includes("*")),
  ),
);
</script>

<template>
  <section
    id="novedades"
    class="tu-novedades tu-ancla"
    aria-labelledby="novedades-titulo"
    :data-modo="modo"
  >
    <div class="mx-auto max-w-6xl px-4 sm:px-6 pt-8 pb-20 sm:pt-12 sm:pb-28">
      <p class="tu-seccion-etiqueta reveal">{{ t(k("etiqueta")) }}</p>
      <h2 id="novedades-titulo" class="tu-titulo mt-3 max-w-3xl reveal">
        <TextoDestacado
          :texto="t(k('titulo'))"
          :enfasis="t(k('enfasis'))"
          negrita
        />
      </h2>
      <p class="novedades-sub mt-4 text-lg max-w-2xl reveal">
        {{ t(k("subtitulo")) }}
      </p>

      <ul class="novedades" role="list">
        <li
          v-for="(n, i) in novedades"
          :key="n.clave"
          class="novedad reveal"
          :data-novedad="n.clave"
          :style="{ transitionDelay: (i % 3) * 80 + 'ms' }"
        >
          <div
            class="novedad-visual"
            :data-visual="n.visual?.tipo"
            aria-hidden="true"
          >
            <!-- Lista: una clase, una caja, un presupuesto, unas facturas… -->
            <template v-if="n.visual?.tipo === 'lista'">
              <div class="nv-panel">
                <div class="nv-cabeza">
                  <strong>{{ n.visual.titulo }}</strong>
                  <span>{{ n.visual.subtitulo }}</span>
                </div>
                <div v-if="n.visual.opciones" class="nv-opciones">
                  <span class="nv-opcion-activa">{{
                    n.visual.opciones[0]
                  }}</span>
                  <span>{{ n.visual.opciones[1] }}</span>
                </div>
                <div v-for="f in n.visual.filas" :key="f.texto" class="nv-fila">
                  <span class="nv-texto"
                    >{{ f.texto }}
                    <small v-if="f.detalle">{{ f.detalle }}</small></span
                  >
                  <span
                    v-if="f.valor"
                    :class="f.marca ? 'nv-' + f.marca : ''"
                    >{{ f.valor }}</span
                  >
                </div>
                <div v-if="n.visual.acciones" class="nv-acciones">
                  <span class="nv-boton">{{ n.visual.acciones[0] }}</span>
                  <span class="nv-boton-borde">{{ n.visual.acciones[1] }}</span>
                </div>
              </div>
              <span v-if="n.visual.aviso" class="nv-aviso"
                ><i v-if="n.visual.aviso.punto" class="nv-punto"></i
                >{{ n.visual.aviso.texto }}</span
              >
            </template>

            <!-- La web del negocio con el widget dentro. -->
            <template v-else-if="n.visual?.tipo === 'navegador'">
              <div class="nv-navegador">
                <div class="nv-barra">
                  <i></i><i></i><i></i><span>{{ n.visual.url }}</span>
                </div>
                <div class="nv-navegador-cuerpo">
                  <div
                    v-for="f in n.visual.filas"
                    :key="f.texto"
                    class="nv-fila"
                  >
                    <span class="nv-texto"
                      ><b>{{ f.texto }}</b>
                      <small v-if="f.detalle">{{ f.detalle }}</small></span
                    >
                    <span
                      v-if="f.valor"
                      :class="f.marca ? 'nv-' + f.marca : ''"
                      >{{ f.valor }}</span
                    >
                  </div>
                </div>
              </div>
            </template>

            <!-- El pase en la cartera del teléfono. -->
            <template v-else-if="n.visual?.tipo === 'pase'">
              <div class="nv-pase">
                <div class="nv-pase-cabeza">
                  <span>PASE DE ENTRADA</span
                  ><span>{{ n.visual.negocio }}</span>
                </div>
                <strong>{{ n.visual.nombre }}</strong>
                <div class="nv-pase-datos">
                  <div v-for="[nombre, valor] in n.visual.datos" :key="nombre">
                    <small>{{ nombre }}</small
                    ><b>{{ valor }}</b>
                  </div>
                </div>
                <svg
                  class="nv-pase-qr"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                >
                  <path
                    d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"
                  />
                </svg>
              </div>
              <span class="nv-aviso"
                ><i v-if="n.visual.aviso.punto" class="nv-punto"></i
                >{{ n.visual.aviso.texto }}</span
              >
            </template>

            <!-- La app: un teléfono y lo que pasa a un lado. -->
            <template v-else-if="n.visual?.tipo === 'telefono'">
              <div class="nv-telefono">
                <span class="nv-telefono-titulo">{{ n.visual.titulo }}</span>
                <div v-if="n.visual.qr" class="nv-visor">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                  >
                    <path
                      d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"
                    />
                  </svg>
                </div>
                <div v-if="n.visual.lineas.length" class="nv-telefono-tarjeta">
                  <b>{{ n.visual.lineas[0] }}</b>
                  <span v-for="l in n.visual.lineas.slice(1)" :key="l">{{
                    l
                  }}</span>
                </div>
                <span v-if="n.visual.estado" class="nv-estado">{{
                  n.visual.estado
                }}</span>
                <div v-if="n.visual.botones" class="nv-telefono-botones">
                  <span
                    v-for="(b, j) in n.visual.botones"
                    :key="b"
                    :class="j === 0 ? 'nv-boton' : 'nv-boton-borde'"
                    >{{ b }}</span
                  >
                </div>
              </div>
              <div class="nv-lado">
                <b>{{ n.visual.lado.titulo }}</b>
                <span v-for="l in n.visual.lado.lineas" :key="l">{{ l }}</span>
              </div>
            </template>

            <!-- La bandeja: lo que escribieron y la respuesta. -->
            <template v-else-if="n.visual?.tipo === 'mensajes'">
              <div class="nv-panel nv-chat">
                <div
                  v-for="m in n.visual.mensajes"
                  :key="m.quien"
                  class="nv-chat-item"
                >
                  <div class="nv-chat-quien">
                    <b>{{ m.quien }}</b>
                    <span class="nv-etiqueta">{{ m.canal }}</span>
                    <i v-if="m.nuevo" class="nv-no-leido"></i>
                  </div>
                  <p>{{ m.texto }}</p>
                </div>
              </div>
              <span class="nv-respuesta">{{ n.visual.respuesta }}</span>
            </template>
          </div>

          <h3 class="novedad-titulo">{{ n.titulo }}</h3>
          <p class="novedad-texto">{{ n.texto }}</p>
          <ul class="novedad-puntos" role="list">
            <li v-for="p in n.puntos" :key="p">
              <svg
                viewBox="0 0 24 24"
                width="15"
                height="15"
                fill="none"
                stroke="currentColor"
                stroke-width="2.2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path d="m5 12.5 4 4 10-10" />
              </svg>
              <span>{{ p }}</span>
            </li>
          </ul>
        </li>
      </ul>

      <p v-if="conNotaMexico" class="tu-nota-mexico">
        {{ t("landing.soloMexico") }}
      </p>
      <div v-if="$slots.default" class="novedades-pie reveal">
        <slot />
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Continúa la banda gris del hero, como los sellos de confianza. */
.tu-novedades {
  background: var(--fondo);
}
/* Del tamaño de los títulos de las demás bandas. */
.tu-novedades .tu-titulo {
  font-size: clamp(1.75rem, 3.2vw, 2.75rem);
}
.novedades-sub {
  color: var(--texto-suave);
  letter-spacing: -0.01em;
}
.novedades {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.25rem;
  margin-top: 2.5rem;
}
.novedad {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 1.25rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
  transition:
    transform 0.25s ease,
    border-color 0.25s ease,
    box-shadow 0.25s ease;
}
.novedad:hover {
  transform: translateY(-3px);
  border-color: color-mix(in srgb, var(--primario) 45%, var(--borde));
  box-shadow: 0 16px 35px -24px
    color-mix(in srgb, var(--primario) 50%, transparent);
}
.novedad-titulo {
  margin-top: 1.15rem;
  font-size: 1.08rem;
  font-weight: 500;
  letter-spacing: -0.015em;
  line-height: 1.3;
  text-wrap: balance;
}
.novedad-texto {
  margin-top: 0.5rem;
  margin-bottom: 1rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
  line-height: 1.6;
}
/* Al pie de la tarjeta: los puntos de una misma fila quedan alineados. */
.novedad-puntos {
  display: grid;
  gap: 0.45rem;
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
  font-size: 0.85rem;
  line-height: 1.4;
}
.novedad-puntos li {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}
.novedad-puntos svg {
  flex: 0 0 auto;
  margin-top: 0.15rem;
  color: var(--exito);
}
.novedades-pie {
  margin-top: 2.25rem;
  text-align: center;
}

/* ---------- Ejemplos ilustrativos ---------- */
.novedad-visual {
  position: relative;
  height: 11rem;
  padding: 0.9rem;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--borde) 65%, transparent);
  border-radius: 1rem;
  background: linear-gradient(
    145deg,
    var(--fondo),
    color-mix(in srgb, var(--primario) 7%, var(--superficie))
  );
  color: var(--texto);
  font-size: 0.66rem;
  line-height: 1.35;
}
.nv-panel,
.nv-navegador,
.nv-lado,
.nv-telefono {
  border: 1px solid var(--borde);
  background: var(--superficie);
  box-shadow: 0 8px 20px rgb(0 0 0 / 5%);
}
.nv-panel {
  padding: 0.55rem 0.7rem;
  border-radius: 0.75rem;
  transition: transform 0.4s;
}
.nv-cabeza {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding-bottom: 0.35rem;
}
.nv-cabeza strong {
  font-size: 0.74rem;
  font-weight: 600;
}
.nv-cabeza span,
.nv-texto small,
.nv-suave {
  color: var(--texto-suave);
}
.nv-fila {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.32rem 0;
  border-top: 1px solid var(--borde);
}
.nv-texto {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nv-texto small {
  margin-left: 0.2rem;
  font-size: 0.6rem;
}
.nv-fila > span:last-child {
  flex: 0 0 auto;
}
.nv-etiqueta {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  color: var(--texto-suave);
  font-size: 0.58rem;
  white-space: nowrap;
}
.nv-estado,
.nv-aviso {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.nv-estado {
  color: var(--texto-suave);
}
.nv-estado::before,
.nv-punto {
  content: "";
  display: inline-block;
  width: 0.42rem;
  height: 0.42rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--exito);
}
.nv-boton,
.nv-boton-borde {
  padding: 0.18rem 0.5rem;
  border-radius: 0.4rem;
  font-weight: 600;
  white-space: nowrap;
}
.nv-boton {
  background: var(--primario);
  color: var(--primario-contraste, #fff);
}
.nv-boton-borde {
  border: 1px solid var(--borde);
  color: var(--texto-suave);
}
.nv-acciones {
  display: flex;
  gap: 0.4rem;
  padding-top: 0.45rem;
  border-top: 1px solid var(--borde);
}
.nv-opciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.2rem;
  margin-bottom: 0.4rem;
  padding: 0.2rem;
  border-radius: 0.5rem;
  background: var(--fondo);
}
.nv-opciones span {
  padding: 0.3rem;
  border-radius: 0.38rem;
  color: var(--texto-suave);
  text-align: center;
}
.nv-opciones .nv-opcion-activa {
  background: var(--superficie);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--primario) 45%, var(--borde));
  color: var(--texto);
  font-weight: 600;
}
.nv-aviso {
  position: absolute;
  right: 0.7rem;
  bottom: 0.7rem;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--borde);
  border-radius: 0.7rem;
  background: var(--superficie);
  box-shadow: 0 8px 20px rgb(0 0 0 / 6%);
  font-weight: 600;
  transition: transform 0.4s;
}
.novedad:hover .nv-aviso,
.novedad:hover .nv-respuesta {
  transform: translateY(-5px);
}

/* La web del negocio. */
.nv-navegador {
  overflow: hidden;
  border-radius: 0.7rem;
}
.nv-barra {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.35rem 0.5rem;
  border-bottom: 1px solid var(--borde);
  background: var(--fondo);
}
.nv-barra i {
  width: 0.38rem;
  height: 0.38rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--borde);
}
.nv-barra span {
  flex: 1;
  min-width: 0;
  margin-left: 0.4rem;
  padding: 0.08rem 0.5rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--superficie);
  color: var(--texto-suave);
  font-size: 0.58rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nv-navegador-cuerpo {
  padding: 0.15rem 0.65rem 0.4rem;
}
.nv-navegador-cuerpo .nv-fila:first-child {
  border-top: 0;
}

/* El pase: invertido (claro en tema oscuro, oscuro en tema claro). */
.nv-pase {
  position: relative;
  max-width: 17rem;
  margin: 0.2rem auto 0;
  padding: 0.75rem 0.85rem;
  border-radius: 0.85rem;
  background: var(--texto);
  color: var(--superficie);
  transform: rotate(-2deg);
  transition: transform 0.4s;
}
.novedad:hover .nv-pase {
  transform: rotate(0deg);
}
.nv-pase-cabeza {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.5rem;
  letter-spacing: 0.1em;
  opacity: 0.75;
}
.nv-pase > strong {
  display: block;
  margin-top: 0.3rem;
  font-size: 0.92rem;
  font-weight: 600;
}
.nv-pase-datos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.4rem;
  margin-top: 0.55rem;
  padding-right: 2.6rem;
}
.nv-pase-datos small {
  display: block;
  font-size: 0.5rem;
  opacity: 0.7;
}
.nv-pase-datos b {
  font-size: 0.64rem;
  font-weight: 600;
}
.nv-pase-qr {
  position: absolute;
  right: 0.8rem;
  bottom: 0.75rem;
  width: 2.1rem;
  height: 2.1rem;
}

/* La app. */
.nv-telefono {
  position: absolute;
  top: 0.9rem;
  left: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  width: 7.6rem;
  height: 12rem;
  padding: 0.7rem 0.6rem;
  border-radius: 1.1rem;
  transition: transform 0.4s;
}
.novedad:hover .nv-telefono {
  transform: translateY(-4px);
}
.nv-telefono-titulo {
  font-weight: 600;
}
.nv-visor {
  position: relative;
  display: grid;
  place-items: center;
  height: 4.2rem;
  border-radius: 0.6rem;
  background: var(--fondo);
  color: var(--texto-suave);
}
.nv-visor::before {
  content: "";
  position: absolute;
  inset: 0.45rem 1.4rem;
  border: 1.5px dashed color-mix(in srgb, var(--primario) 60%, var(--borde));
  border-radius: 0.4rem;
}
.nv-visor svg {
  width: 1.9rem;
  height: 1.9rem;
}
.nv-telefono-tarjeta {
  display: grid;
  gap: 0.15rem;
  padding: 0.45rem;
  border-radius: 0.55rem;
  background: var(--fondo);
}
.nv-telefono-tarjeta span {
  color: var(--texto-suave);
}
.nv-telefono-botones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}
.nv-lado {
  position: absolute;
  top: 2.2rem;
  right: 0.8rem;
  display: grid;
  gap: 0.15rem;
  width: calc(100% - 10.2rem);
  max-width: 9.5rem;
  padding: 0.55rem 0.65rem;
  border-radius: 0.7rem;
}
.nv-lado span {
  color: var(--texto-suave);
}

/* La bandeja. */
.nv-chat {
  display: grid;
  gap: 0.45rem;
  max-width: 88%;
}
.nv-chat-quien {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.nv-chat p {
  margin-top: 0.2rem;
  padding: 0.3rem 0.5rem;
  border-radius: 0.2rem 0.6rem 0.6rem 0.6rem;
  background: var(--fondo);
}
.nv-no-leido {
  width: 0.42rem;
  height: 0.42rem;
  margin-left: auto;
  border-radius: 50%;
  background: var(--primario);
}
.nv-respuesta {
  position: absolute;
  right: 0.8rem;
  bottom: 0.75rem;
  max-width: 70%;
  padding: 0.38rem 0.6rem;
  border-radius: 0.6rem 0.6rem 0.2rem 0.6rem;
  background: color-mix(in srgb, var(--primario) 14%, var(--superficie));
  font-weight: 500;
  transition: transform 0.4s;
}

@media (max-width: 1023px) {
  .novedades {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 639px) {
  .novedades {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  .novedad {
    padding: 1.1rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .novedad,
  .nv-aviso,
  .nv-respuesta,
  .nv-pase,
  .nv-telefono,
  .nv-panel {
    transition: none;
  }
  .novedad:hover,
  .novedad:hover .nv-aviso,
  .novedad:hover .nv-respuesta,
  .novedad:hover .nv-telefono {
    transform: none;
  }
  .novedad:hover .nv-pase {
    transform: rotate(-2deg);
  }
}
</style>
