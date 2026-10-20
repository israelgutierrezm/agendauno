// Confirmaciones de lo delicado: lo que mueve dinero, créditos o puntos, o no se
// deshace. Cada una dice qué va a pasar (lib/confirmar.ts).
export default {
  venta:
    "¿Cobrar {producto} ({monto}) en {metodo} a {persona}? Queda registrada la venta y su pago.",
  cobrar: "Sí, cobrar",
  pos: "¿Cobrar {total} en {metodo}? La venta se registra y se descuenta del inventario.",
  factura:
    "¿Timbrar la factura por {total} para {rfc}? Un CFDI timbrado no se edita; solo se cancela ante el SAT.",
  facturaRenta:
    "¿Timbrar la factura de este cargo? Un CFDI timbrado no se edita; solo se cancela ante el SAT.",
  timbrar: "Timbrar",
  pagarRenta:
    "¿Pagar {monto} de tu suscripción? Te llevamos a la página de pago segura de Stripe.",
  pagar: "Pagar",
  recargar:
    "¿Agregar {n} créditos a su saldo? Queda en sus movimientos con el motivo.",
  agregar: "Agregar",
  pausar:
    "¿Pausar la membresía hasta el {fecha}? Mientras tanto no puede reservar.",
  pausarAceptar: "Pausar",
  canjear: "¿Canjear la recompensa? Se le descuentan los puntos.",
  canjearAceptar: "Canjear",
  entregarCanje: "¿Marcar el canje como entregado?",
  entregar: "Entregar",
  cancelarCanje: "¿Cancelar el canje? Se le devuelven los puntos.",
  cancelarCanjeAceptar: "Cancelar canje",
  ajustarPuntos: "¿Ajustar {puntos} puntos? Queda en su historial.",
  ajustar: "Ajustar",
  regularizar:
    "¿Registrar que {persona} ya pagó su adeudo? Se cierra su proceso de cobranza.",
  regularizarAceptar: "Registrar pago",
  promover:
    "¿Ofrecer los lugares libres a la lista de espera? Se avisa a quienes siguen en la fila.",
  promoverAceptar: "Ofrecer lugares",
  aceptarLugar:
    "¿Aceptar el lugar a nombre de {persona}? Queda confirmado en la clase y se usa su plan, como si lo hubiera aceptado.",
  aceptarLugarAceptar: "Aceptar el lugar",
  quitarDeEspera:
    "¿Quitar a {persona} de la lista de espera? Ya no se le ofrecerá un lugar en esta clase.",
  quitarDeEsperaAceptar: "Quitar de la espera",
  publicarDocumento:
    "¿Publicar «{titulo}»? Tus clientes verán esta versión y tendrán que aceptarla.",
  publicar: "Publicar",
  quitarImagen:
    "¿Quitar la imagen? Para volver a ponerla tendrás que subirla de nuevo.",
  quitarLogo:
    "¿Quitar el logo? Para volver a ponerlo tendrás que subirlo de nuevo.",
  quitar: "Quitar",
  ocultarPagina:
    "¿Dejar de mostrar tu página? Tus clientes ya no la verán ni podrán agendar en línea.",
  ocultar: "Ocultar página",
  asistencia: {
    noVino:
      "¿Marcar que {persona} no vino? Según la política del negocio, puede perder el crédito.",
    // Con el efecto exacto que calcula el sistema con su política.
    noVinoCon: "¿Marcar que {persona} no vino? {efecto}",
    efecto: {
      pierde: "Pierde 1 crédito de su plan. | Pierde {n} créditos de su plan.",
      tolerada:
        "Esta falta entra en la tolerancia de su política: no pierde el crédito.",
      noPenaliza:
        "Su política no cobra las faltas: el crédito vuelve a su saldo.",
      sinCredito: "No usó créditos en esta reserva: su saldo no cambia.",
      // Lo mismo dice la app del equipo (cita_sheet y pase de lista).
      conservaAnticipo:
        "El anticipo de {monto} no se devuelve: el negocio lo conserva.",
      conservaPago: "Ya pagó {monto}: no se devuelve por no asistir.",
      devuelveEnLinea:
        "Lo demás que pagó en línea ({monto}) se le devuelve solo.",
      devuelveEnCaja: "Devuélvele en caja {monto} que pagó de más.",
    },
    correccion:
      "{persona} está marcado como «{antes}». ¿Cambiarlo a «{ahora}»? Si aplica, el crédito se ajusta en su saldo.",
    llego: "Llegó",
    noVinoCorto: "No vino",
  },
};
