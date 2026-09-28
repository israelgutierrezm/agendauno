<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

const { t } = useI18n();
const abierta = ref<string | null>(null);
const funciones = [
  {
    clave: "agenda",
    icono: ["M4 7h16v13H4z", "M4 11h16M8 4v5M16 4v5M8 15h3M14 15h2"],
    detalle:
      "Asigna horarios, profesionales y recursos. Consulta clases y citas en una agenda visual para saber qué sigue y quién lo atiende.",
  },
  {
    clave: "reservas",
    icono: ["M8 3h8v18H8z", "m10 12 2 2 4-4M11 18h2"],
    detalle:
      "Comparte tu enlace de reservas. Tus clientes eligen su clase o servicio y un horario disponible sin depender de mensajes de ida y vuelta.",
  },
  {
    clave: "membresias",
    icono: [
      "M3 7h18v4a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4z",
      "M15 8v2M15 12v2M15 16v1",
    ],
    detalle:
      "Ofrece paquetes por sesiones o membresías. Revisa créditos disponibles, vigencias y renovaciones desde la ficha de cada persona.",
  },
  {
    clave: "pagos",
    icono: ["M3 6h18v13H3z", "M3 10h18M7 15h4", "m15 14 2 2 3-3"],
    detalle:
      "Conecta una pasarela compatible para cobrar en línea o registra lo recibido en recepción. Consulta pagos y saldos pendientes sin perder el contexto.",
  },
  {
    clave: "pos",
    icono: ["M4 8h16l-1 12H5z", "M8 8V6a4 4 0 0 1 8 0v2M9 13h6M12 10v6"],
    detalle:
      "Vende productos junto con tus servicios. Revisa existencias por sucursal y conserva el registro de cada venta.",
  },
  {
    clave: "reportes",
    icono: ["M4 4v16h17", "M8 16v-4M13 16V8M18 16V5", "m7 8 5-4 3 1 5-3"],
    detalle:
      "Compara ingresos, asistencia y ocupación. Identifica qué clases, servicios y horarios conviene impulsar con datos de tu operación.",
  },
] as const;
</script>
<template>
  <div class="funcionalidades">
    <p class="funciones-guia">
      <span>Explora lo que puedes hacer</span><span>Ejemplos ilustrativos</span>
    </p>
    <div class="funciones-grid">
      <article
        v-for="f in funciones"
        :key="f.clave"
        class="funcion"
        :class="{ 'funcion-abierta': abierta === f.clave }"
      >
        <div class="funcion-cabecera">
          <span class="funcion-icono" aria-hidden="true">
            <svg
              viewBox="0 0 24 24"
              width="25"
              height="25"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path v-for="(d, i) in f.icono" :key="i" :d="d" />
            </svg>
          </span>
          <h3>{{ t("landing.funciones." + f.clave) }}</h3>
        </div>
        <p class="funcion-descripcion">
          {{ t("landing.funciones." + f.clave + "Desc") }}
        </p>

        <div
          class="funcion-visual"
          :class="'visual-' + f.clave"
          aria-hidden="true"
        >
          <template v-if="f.clave === 'agenda'">
            <div class="mini-agenda-cabecera">
              <span>LUN</span><span>MAR</span><span>MIÉ</span>
            </div>
            <div class="mini-agenda-lineas"></div>
            <span class="mini-bloque mini-bloque-uno"
              >Pilates <small>09:00 · Andrea</small></span
            >
            <span class="mini-bloque mini-bloque-dos"
              >Pole dance <small>10:00 · Sofía</small></span
            >
            <span class="mini-bloque mini-bloque-tres"
              >Yoga <small>11:00 · Elena</small></span
            >
          </template>
          <template v-else-if="f.clave === 'reservas'">
            <div class="mini-reserva">
              <span>Tu próxima clase</span><strong>Pilates Reformer</strong>
              <div>
                <span>09:00</span><span class="hora-elegida">10:00</span
                ><span>11:00</span>
              </div>
            </div>
            <span class="mini-confirmacion"
              ><span>✓</span> Reserva confirmada</span
            >
          </template>
          <template v-else-if="f.clave === 'membresias'">
            <div class="mini-pase">
              <div><span>PACK DE CLASES</span><strong>8 sesiones</strong></div>
              <span class="pase-sello">A</span>
              <div class="pase-creditos">
                <i v-for="n in 8" :key="n" :class="{ usado: n <= 3 }"></i>
              </div>
              <small>5 sesiones por disfrutar</small>
            </div>
          </template>
          <template v-else-if="f.clave === 'pagos'">
            <div class="mini-cobro">
              <span>Paquete de clases</span
              ><strong>Pago registrado <span>✓</span></strong>
              <div><span>Estado de cuenta</span><b>Al día</b></div>
            </div>
          </template>
          <template v-else-if="f.clave === 'pos'">
            <div class="mini-producto">
              <svg
                viewBox="0 0 32 44"
                width="26"
                height="40"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
              >
                <path
                  d="M11 3h10v8l5 6v23H6V17l5-6zM11 7h10M10 24h12M10 28h8"
                />
              </svg>
              <div>
                <strong>Botella deportiva</strong
                ><small>Producto · Sucursal Centro</small>
              </div>
              <span>+1</span>
            </div>
            <div class="mini-stock">
              <span>Existencias</span>
              <div>
                <i v-for="n in 10" :key="n" :class="{ libre: n > 7 }"></i>
              </div>
              <strong>7 disponibles</strong>
            </div>
          </template>
          <template v-else>
            <div class="mini-reporte-cabecera">
              <span>Ocupación por día</span><span>Esta semana</span>
            </div>
            <div class="mini-reporte-barras">
              <div v-for="(alto, i) in [38, 63, 47, 83, 71, 92, 56]" :key="i">
                <i :style="{ '--alto': alto + '%' }"></i
                ><span>{{ ["L", "M", "M", "J", "V", "S", "D"][i] }}</span>
              </div>
            </div>
          </template>
        </div>
        <button
          type="button"
          class="funcion-abrir"
          :aria-expanded="abierta === f.clave"
          :aria-controls="'detalle-funcion-' + f.clave"
          @click="abierta = abierta === f.clave ? null : f.clave"
        >
          <span>{{
            abierta === f.clave ? "Cerrar detalle" : "Así te ayuda"
          }}</span>
          <svg
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            aria-hidden="true"
          >
            <path d="m9 5 7 7-7 7" />
          </svg>
        </button>
        <Transition name="detalle">
          <div
            v-show="abierta === f.clave"
            :id="'detalle-funcion-' + f.clave"
            class="funcion-detalle"
          >
            <p>{{ f.detalle }}</p>
            <RouterLink :to="{ name: 'registro' }"
              >Probar en mi negocio
              <span aria-hidden="true">↗</span></RouterLink
            >
          </div>
        </Transition>
      </article>
    </div>
  </div>
</template>
<style scoped>
.funcionalidades {
  margin-top: 2.5rem;
}
.funciones-guia {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
  font-size: 0.72rem;
  color: var(--texto-suave);
}
.funciones-guia > span:first-child {
  font-weight: 600;
  color: var(--primario-fuerte);
}
.funciones-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.25rem;
  align-items: start;
}
.funcion {
  padding: 1.4rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
  min-width: 0;
  transition:
    transform 0.25s ease,
    border-color 0.25s ease,
    box-shadow 0.25s ease;
}
.funcion:hover,
.funcion:focus-within,
.funcion-abierta {
  border-color: color-mix(in srgb, var(--primario) 55%, var(--borde));
  box-shadow: 0 16px 35px -24px
    color-mix(in srgb, var(--primario) 50%, transparent);
}
.funcion:hover {
  transform: translateY(-4px);
}
.funcion-cabecera {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  min-height: 3.7rem;
}
.funcion-icono {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.9rem;
  height: 2.9rem;
  border-radius: 0.95rem;
  color: var(--primario-fuerte);
  background: var(--primario-suave);
  transition:
    transform 0.3s,
    background 0.3s,
    color 0.3s;
}
.funcion:hover .funcion-icono,
.funcion:focus-within .funcion-icono {
  transform: rotate(-6deg) scale(1.05);
  background: var(--primario);
  color: white;
}
.funcion-cabecera h3 {
  font-size: 1.04rem;
  font-weight: 300;
  letter-spacing: -0.02em;
  line-height: 1.3;
}
.funcion-descripcion {
  min-height: 6.5rem;
  margin-top: 1rem;
  color: var(--texto-suave);
  line-height: 1.65;
  font-size: 0.83rem;
}
.funcion-visual {
  position: relative;
  height: 10rem;
  margin-top: 0.9rem;
  padding: 1rem;
  border: 1px solid color-mix(in srgb, var(--borde) 65%, transparent);
  border-radius: 1rem;
  overflow: hidden;
  background: linear-gradient(
    145deg,
    var(--fondo),
    color-mix(in srgb, var(--primario) 7%, var(--superficie))
  );
}
.funcion-abrir {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 1rem 0 0;
  border: 0;
  background: transparent;
  color: var(--primario-fuerte);
  cursor: pointer;
  font-size: 0.79rem;
  font-weight: 600;
}
.funcion-abrir svg {
  transition: transform 0.25s;
}
.funcion-abrir[aria-expanded="true"] svg {
  transform: rotate(90deg);
}
.funcion-abrir:focus-visible,
.funcion-detalle a:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 5px;
  border-radius: 0.2rem;
}
.funcion-detalle {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
  font-size: 0.82rem;
  line-height: 1.65;
  color: var(--texto-suave);
}
.funcion-detalle a {
  display: inline-block;
  margin-top: 0.85rem;
  color: var(--primario-fuerte);
  font-weight: 600;
  text-decoration: none;
}
.detalle-enter-active,
.detalle-leave-active {
  transition:
    opacity 0.2s,
    transform 0.2s;
}
.detalle-enter-from,
.detalle-leave-to {
  opacity: 0;
  transform: translateY(-5px);
}
.mini-agenda-cabecera {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  text-align: center;
  font-size: 0.55rem;
  color: var(--texto-suave);
}
.mini-agenda-lineas {
  position: absolute;
  inset: 2.3rem 0.7rem 0.7rem;
  background: repeating-linear-gradient(
    transparent 0 1.7rem,
    var(--borde) 1.7rem calc(1.7rem + 1px)
  );
}
.mini-bloque {
  position: absolute;
  width: 30%;
  padding: 0.45rem;
  border-left: 2px solid var(--primario);
  border-radius: 0.45rem;
  background: color-mix(in srgb, var(--primario) 16%, var(--superficie));
  font-size: 0.64rem;
  font-weight: 600;
  transition: transform 0.4s;
}
.mini-bloque small {
  display: block;
  font-size: 0.49rem;
  font-weight: 400;
  color: var(--texto-suave);
  margin-top: 0.15rem;
}
.mini-bloque-uno {
  left: 3%;
  top: 2.6rem;
}
.mini-bloque-dos {
  left: 35%;
  top: 4.2rem;
}
.mini-bloque-tres {
  left: 67%;
  top: 6rem;
}
.funcion:hover .mini-bloque,
.funcion:focus-within .mini-bloque {
  transform: translateY(-4px);
}
.mini-reserva {
  background: var(--superficie);
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  padding: 0.65rem 0.8rem;
  width: 88%;
  transform: rotate(-3deg);
}
.mini-reserva > span {
  font-size: 0.58rem;
  color: var(--texto-suave);
}
.mini-reserva strong {
  display: block;
  margin-top: 0.1rem;
  font-size: 0.78rem;
}
.mini-reserva > div {
  display: flex;
  gap: 0.45rem;
  margin-top: 0.6rem;
}
.mini-reserva > div span {
  padding: 0.25rem 0.35rem;
  border: 1px solid var(--borde);
  border-radius: 0.35rem;
  font-size: 0.58rem;
}
.mini-reserva .hora-elegida {
  background: var(--primario);
  color: white;
  border-color: var(--primario);
}
.mini-confirmacion {
  position: absolute;
  right: 0.65rem;
  bottom: 0.8rem;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--borde);
  border-radius: 0.7rem;
  background: var(--superficie);
  box-shadow: 0 8px 20px rgb(0 0 0 / 6%);
  font-size: 0.63rem;
  font-weight: 600;
  transition: transform 0.4s;
}
.mini-confirmacion > span {
  color: var(--primario-fuerte);
}
.funcion:hover .mini-confirmacion,
.funcion:focus-within .mini-confirmacion {
  transform: translateY(-6px);
}
.mini-pase {
  position: relative;
  background: #072453;
  color: #fff;
  padding: 0.85rem 1rem;
  border-radius: 0.9rem;
  transform: rotate(-3deg);
  transition: transform 0.4s;
}
.mini-pase > div:first-child > span {
  font-size: 0.48rem;
  letter-spacing: 0.12em;
}
.mini-pase strong {
  display: block;
  font-size: 1rem;
}
.pase-sello {
  position: absolute;
  right: 1rem;
  top: 0.9rem;
  border: 1px solid rgb(255 255 255 / 40%);
  border-radius: 50%;
  padding: 0.2rem 0.45rem;
  font-size: 0.6rem;
}
.pase-creditos {
  display: flex;
  gap: 0.35rem;
  margin: 0.65rem 0 0.15rem;
}
.pase-creditos i {
  width: 0.8rem;
  height: 0.8rem;
  border: 1px solid #6acbff;
  border-radius: 50%;
  background: #6acbff;
}
.pase-creditos i.usado {
  background: transparent;
  border-color: #526582;
}
.mini-pase > small {
  font-size: 0.56rem;
  opacity: 0.8;
}
.funcion:hover .mini-pase,
.funcion:focus-within .mini-pase {
  transform: rotate(0deg);
}
.mini-cobro {
  margin: 0.25rem auto;
  padding: 0.8rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  background: var(--superficie);
}
.mini-cobro > span {
  font-size: 0.6rem;
  color: var(--texto-suave);
}
.mini-cobro > strong {
  display: flex;
  justify-content: space-between;
  font-size: 0.9rem;
  margin-top: 0.4rem;
}
.mini-cobro strong span {
  color: var(--primario-fuerte);
  transition: transform 0.3s;
}
.mini-cobro > div {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.7rem;
  padding-top: 0.5rem;
  border-top: 1px dashed var(--borde);
  font-size: 0.56rem;
  color: var(--texto-suave);
}
.mini-cobro b {
  color: var(--primario-fuerte);
}
.funcion:hover .mini-cobro strong span {
  transform: scale(1.3);
}
.mini-producto {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem;
  background: var(--superficie);
  border: 1px solid var(--borde);
  border-radius: 0.7rem;
}
.mini-producto svg {
  color: var(--primario-fuerte);
  flex-shrink: 0;
}
.mini-producto strong {
  display: block;
  font-size: 0.62rem;
}
.mini-producto small {
  display: block;
  font-size: 0.48rem;
  color: var(--texto-suave);
}
.mini-producto > span {
  margin-left: auto;
  color: var(--primario-fuerte);
  font-size: 0.75rem;
  font-weight: 700;
}
.mini-stock {
  margin: 0.7rem 0.25rem;
  font-size: 0.58rem;
}
.mini-stock > div {
  display: flex;
  gap: 0.25rem;
  margin: 0.3rem 0;
}
.mini-stock i {
  width: 10%;
  height: 0.45rem;
  border-radius: 0.15rem;
  background: var(--primario);
}
.mini-stock i.libre {
  background: var(--borde);
}
.mini-stock > strong {
  font-size: 0.5rem;
  color: var(--texto-suave);
}
.mini-reporte-cabecera {
  display: flex;
  justify-content: space-between;
  font-size: 0.54rem;
  color: var(--texto-suave);
}
.mini-reporte-barras {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 0.7rem;
  height: 6.4rem;
  padding-top: 0.8rem;
}
.mini-reporte-barras > div {
  display: flex;
  flex: 1;
  height: 100%;
  flex-direction: column;
  justify-content: end;
  align-items: center;
  gap: 0.35rem;
}
.mini-reporte-barras i {
  display: block;
  width: 100%;
  height: var(--alto);
  border-radius: 0.3rem 0.3rem 0.1rem 0.1rem;
  background: linear-gradient(0deg, var(--primario), #52bfff);
  transform-origin: bottom;
  transition: transform 0.45s;
}
.mini-reporte-barras span {
  font-size: 0.5rem;
  color: var(--texto-suave);
}
.funcion:hover .mini-reporte-barras i,
.funcion:focus-within .mini-reporte-barras i {
  transform: scaleY(0.85);
}
@media (max-width: 1023px) {
  .funciones-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 639px) {
  .funciones-grid {
    grid-template-columns: 1fr;
  }
  .funcion-descripcion {
    min-height: 0;
  }
  .funciones-guia {
    align-items: start;
    font-size: 0.66rem;
  }
  .funcion {
    padding: 1.25rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    transition: none !important;
  }
  .funcion:hover {
    transform: none;
  }
}
</style>
