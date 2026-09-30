<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Parametros;

/**
 * Todos los límites y datos de negocio que se pueden configurar (ADR 0042). Nada de
 * esto vive fijo en el código: el valor que aplica sale del negocio, si lo ajustó; si
 * no, de la plataforma; y si tampoco, del `defecto` de aquí.
 */
final class CatalogoParametros
{
    /**
     * @return array<string, DefinicionParametro>
     */
    public static function todos(): array
    {
        $e = DefinicionParametro::ENTERO;
        $sn = DefinicionParametro::SI_NO;
        $lista = [
            // Reservas y lista de espera.
            new DefinicionParametro('reservas.minutos_para_pagar', 'Reservas y lista de espera', 'Tiempo para pagar una reserva apartada',
                'Si no se paga en este tiempo, el lugar se libera.', $e, 30, 5, 1440, 'min'),
            new DefinicionParametro('reservas.minutos_para_aceptar_lugar', 'Reservas y lista de espera', 'Tiempo para aceptar un lugar de la lista de espera',
                'Si no lo acepta a tiempo, se ofrece al siguiente.', $e, 30, 5, 1440, 'min'),

            // Recordatorios.
            new DefinicionParametro('recordatorios.primero_horas', 'Recordatorios', 'Primer recordatorio',
                'Horas antes de la clase o cita.', $e, 24, 1, 168, 'h'),
            new DefinicionParametro('recordatorios.segundo_horas', 'Recordatorios', 'Segundo recordatorio',
                'Horas antes de la clase o cita. 0 = no se envía.', $e, 2, 0, 48, 'h'),

            // Reprogramar desde la cuenta del cliente (ADR 0044).
            new DefinicionParametro('reprogramar.horas_limite_cliente', 'Cambios de horario desde la cuenta', 'El cliente puede cambiar su horario hasta',
                'Horas antes del inicio. Después, solo el negocio.', $e, 12, 0, 720, 'h'),
            new DefinicionParametro('reprogramar.maximo_cliente', 'Cambios de horario desde la cuenta', 'Cambios por reserva',
                'Cuántas veces puede cambiar él mismo el horario de una reserva. 0 = solo el negocio.', $e, 1, 0, 20),

            // Citas.
            new DefinicionParametro('citas.duracion_defecto', 'Citas', 'Duración de una cita si el servicio no la define',
                'Minutos.', $e, 30, 5, 480, 'min'),
            // ADR 0065.
            new DefinicionParametro('citas.pago_en_linea_obligatorio', 'Citas', 'Pedir el pago en línea para confirmar una cita',
                'Si lo apagas, la cita queda confirmada al agendar y el cliente paga en línea o en la sucursal. Sin cobro en línea activo, siempre se paga en la sucursal.', $sn, 1),
            new DefinicionParametro('citas.maximo_por_pagar', 'Citas', 'Citas por pagar que puede tener un cliente',
                'Con estas citas próximas sin pagar, el cliente ya no puede agendar otra en línea ni desde su cuenta (el negocio sí puede agendarle). 0 = sin límite.', $e, 5, 0, 50, 'citas'),

            // Clases recurrentes (ADR 0045).
            new DefinicionParametro('agenda.dias_a_generar', 'Clases recurrentes', 'Fechas creadas por adelantado',
                'Días hacia adelante con fechas de las clases recurrentes listas para reservar.', $e, 30, 7, 365, 'días'),

            // Acceso.
            new DefinicionParametro('acceso.minutos_antes', 'Acceso', 'Se puede entrar desde',
                'Minutos antes de que empiece su clase o cita.', $e, 30, 0, 240, 'min'),

            // Membresías (ADR 0047).
            new DefinicionParametro('membresias.dias_aviso_renovacion', 'Membresías', 'Avisar la renovación',
                'Días antes de que se renueve una membresía.', $e, 3, 1, 30, 'días'),
            new DefinicionParametro('membresias.dias_por_vencer', 'Membresías', 'Una membresía está por vencer desde',
                'Días antes de su vencimiento. Aplica en la ficha del alumno, el radar de retención y las difusiones.', $e, 14, 1, 90, 'días'),
            new DefinicionParametro('membresias.dias_vencida_recuperable', 'Membresías', 'Una membresía vencida se sigue buscando durante',
                'Días después de vencer, en el radar de retención y las difusiones.', $e, 14, 1, 365, 'días'),
            new DefinicionParametro('membresias.max_dias_pausa', 'Membresías', 'Una pausa puede durar hasta',
                'Días.', $e, 180, 1, 365, 'días'),

            // Cobranza.
            new DefinicionParametro('cobranza.dias_gracia_pago_automatico', 'Cobranza', 'Espera antes de dar por fallido un pago automático',
                'Días después de la fecha de cobro sin recibirlo.', $e, 3, 0, 30, 'días'),
            new DefinicionParametro('cobranza.dias_gracia_adeudo', 'Cobranza', 'Días de gracia con un adeudo',
                'Antes de suspender la membresía por falta de pago.', $e, 7, 0, 60, 'días'),
            new DefinicionParametro('cobranza.dias_pagar_en_tienda', 'Cobranza', 'Días para pagar en tienda (OXXO)',
                'Vigencia de la referencia de pago en efectivo de Mercado Pago u OpenPay.', $e, 3, 1, 30, 'días'),

            // Facturación (ADR 0047).
            new DefinicionParametro('facturacion.iva_porcentaje', 'Facturación', 'Tasa de IVA de las facturas',
                '8 % solo si el negocio aplica el estímulo de la región fronteriza.', $e, 16, 8, 16, '%', opciones: [16, 8]),

            // Devoluciones (ADR 0046).
            new DefinicionParametro('cancelacion.devolver_pago_si_cancela_negocio', 'Devoluciones', 'Si el negocio cancela algo ya pagado en línea, devolver el pago',
                'Se devuelve solo, por la misma pasarela. Lo pagado en efectivo se devuelve en caja.', $sn, 0),

            // Reseñas.
            new DefinicionParametro('resenas.dias_para_calificar', 'Reseñas', 'Días para calificar una clase o cita',
                'Después ya no se pide la reseña.', $e, 30, 1, 365, 'días'),

            // Cancelaciones: el negocio las ajusta en Reglas de la agenda; esto es lo que
            // aplica a los negocios que aún no definen su política.
            new DefinicionParametro('cancelacion.horas_limite', 'Cancelaciones (negocios sin política propia)', 'Cancelar sin costo hasta',
                'Horas antes del inicio.', $e, 6, 0, 720, 'h', porNegocio: false),
            new DefinicionParametro('cancelacion.penaliza_tarde', 'Cancelaciones (negocios sin política propia)', 'Cobrar el crédito si cancela tarde',
                '', $sn, 1, porNegocio: false),
            new DefinicionParametro('cancelacion.penaliza_no_show', 'Cancelaciones (negocios sin política propia)', 'Cobrar el crédito si no asiste',
                '', $sn, 1, porNegocio: false),
            new DefinicionParametro('cancelacion.tolerancia_no_show', 'Cancelaciones (negocios sin política propia)', 'Inasistencias toleradas sin cobrar',
                'Cuántas faltas no cobran el crédito antes de empezar a cobrarlo. 0 = ninguna.', $e, 0, 0, 100, '', porNegocio: false),
            new DefinicionParametro('cancelacion.ventana_no_show_dias', 'Cancelaciones (negocios sin política propia)', 'Se cuentan las inasistencias de los últimos',
                'Días.', $e, 30, 1, 365, 'días', porNegocio: false),

            // Solo la plataforma (ADR 0047). El pase se renueva cada minuto en la
            // pantalla del alumno: menos de 90 s lo dejaría vencer antes.
            new DefinicionParametro('acceso.segundos_pase_qr', 'Acceso', 'Vigencia del pase QR de entrada',
                'Segundos. Una captura de pantalla deja de servir después.', $e, 180, 90, 900, 's', porNegocio: false),
            new DefinicionParametro('importaciones.max_filas', 'Importaciones', 'Filas por archivo al importar miembros o personal',
                '', $e, 1000, 100, 10000, 'filas', porNegocio: false),

            // Cuentas: vigencia de enlaces de seguridad (solo la plataforma).
            new DefinicionParametro('cuentas.horas_confirmar_registro', 'Cuentas', 'Vigencia del enlace para confirmar un registro',
                'Horas.', $e, 24, 1, 168, 'h', porNegocio: false),
            new DefinicionParametro('cuentas.horas_confirmar_correo', 'Cuentas', 'Vigencia del enlace para confirmar un cambio de correo',
                'Horas.', $e, 24, 1, 168, 'h', porNegocio: false),
            new DefinicionParametro('cuentas.minutos_restablecer_contrasena', 'Cuentas', 'Vigencia del enlace para restablecer la contraseña',
                'Minutos.', $e, 60, 10, 1440, 'min', porNegocio: false),

            // Suspensión automática por renta vencida (ADR 0073, solo la plataforma).
            new DefinicionParametro('renta.dias_gracia_suspension', 'Renta', 'Días de gracia antes de suspender por renta vencida',
                'Pasados estos días desde el vencimiento, el negocio se suspende solo hasta que la pague. 0 = nunca.', $e, 15, 0, 90, 'días', porNegocio: false),
            new DefinicionParametro('renta.dias_aviso_suspension', 'Renta', 'Días antes de la suspensión para avisar al dueño',
                'Por correo y, si lo aceptó, por WhatsApp.', $e, 3, 1, 14, 'días', porNegocio: false),

            // Avisos a los dueños (ADR 0071, solo la plataforma).
            new DefinicionParametro('duenos.dias_aviso_prueba', 'Dueños', 'Días antes del fin de la prueba para avisar al dueño',
                'Por correo y, si lo aceptó, por WhatsApp.', $e, 3, 1, 14, 'días', porNegocio: false),

            // WhatsApp con los dueños (ADR 0070): cada código cuesta (solo la plataforma).
            new DefinicionParametro('whatsapp.codigos_por_numero_hora', 'WhatsApp', 'Códigos de verificación por número en una hora',
                'Al registrarse. Pasado el tope, el dueño sigue sin verificar.', $e, 3, 1, 10, 'códigos', porNegocio: false),
            new DefinicionParametro('whatsapp.codigos_por_dia', 'WhatsApp', 'Códigos de verificación al día (toda la plataforma)',
                'Protege el costo si alguien abusa del registro; al llegar, avisa al superadministrador.', $e, 300, 10, 100000, 'códigos', porNegocio: false),
        ];

        $porClave = [];
        foreach ($lista as $definicion) {
            $porClave[$definicion->clave] = $definicion;
        }

        return $porClave;
    }

    public static function de(string $clave): DefinicionParametro
    {
        return self::todos()[$clave] ?? throw new \InvalidArgumentException("Parámetro desconocido: {$clave}");
    }
}
