<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Tenancy\Http\Controllers\AccesosTenantController;
use App\Modules\Tenancy\Http\Controllers\AgendaTenantController;
use App\Modules\Tenancy\Http\Controllers\AparienciaTenantController;
use App\Modules\Tenancy\Http\Controllers\AsignacionesPersonalTenantController;
use App\Modules\Tenancy\Http\Controllers\AsistenciaTenantController;
use App\Modules\Tenancy\Http\Controllers\AuditoriaController;
use App\Modules\Tenancy\Http\Controllers\AuthTenantController;
use App\Modules\Tenancy\Http\Controllers\AutomatizacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\BloqueosAgendaTenantController;
use App\Modules\Tenancy\Http\Controllers\CalendarioTenantController;
use App\Modules\Tenancy\Http\Controllers\CapacidadCanalTenantController;
use App\Modules\Tenancy\Http\Controllers\CatalogoTenantController;
use App\Modules\Tenancy\Http\Controllers\CheckinsTenantController;
use App\Modules\Tenancy\Http\Controllers\ClimaEquipoTenantController;
use App\Modules\Tenancy\Http\Controllers\CreditosTenantController;
use App\Modules\Tenancy\Http\Controllers\DatosFiscalesTenantController;
use App\Modules\Tenancy\Http\Controllers\DifusionesTenantController;
use App\Modules\Tenancy\Http\Controllers\DirectorioController;
use App\Modules\Tenancy\Http\Controllers\DisponibilidadTenantController;
use App\Modules\Tenancy\Http\Controllers\DocumentosController;
use App\Modules\Tenancy\Http\Controllers\DunningTenantController;
use App\Modules\Tenancy\Http\Controllers\EscaparateController;
use App\Modules\Tenancy\Http\Controllers\ExcepcionesHorarioTenantController;
use App\Modules\Tenancy\Http\Controllers\ExpedienteTenantController;
use App\Modules\Tenancy\Http\Controllers\FacturacionController;
use App\Modules\Tenancy\Http\Controllers\FacturaRentaController;
use App\Modules\Tenancy\Http\Controllers\FacturasTenantController;
use App\Modules\Tenancy\Http\Controllers\FichaMiembroTenantController;
use App\Modules\Tenancy\Http\Controllers\FormulariosController;
use App\Modules\Tenancy\Http\Controllers\FrontDeskTenantController;
use App\Modules\Tenancy\Http\Controllers\GruposTenantController;
use App\Modules\Tenancy\Http\Controllers\ImportacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\IncidenciasCobroTenantController;
use App\Modules\Tenancy\Http\Controllers\InicioHoyTenantController;
use App\Modules\Tenancy\Http\Controllers\IntegracionApiTenantController;
use App\Modules\Tenancy\Http\Controllers\IntegracionesTenantController;
use App\Modules\Tenancy\Http\Controllers\InventarioTenantController;
use App\Modules\Tenancy\Http\Controllers\LealtadTenantController;
use App\Modules\Tenancy\Http\Controllers\LegalesPublicoController;
use App\Modules\Tenancy\Http\Controllers\LlavesApiTenantController;
use App\Modules\Tenancy\Http\Controllers\MarcaEstudioController;
use App\Modules\Tenancy\Http\Controllers\MembresiasTenantController;
use App\Modules\Tenancy\Http\Controllers\MensajesTenantController;
use App\Modules\Tenancy\Http\Controllers\MiDispositivosTenantController;
use App\Modules\Tenancy\Http\Controllers\MiembrosTenantController;
use App\Modules\Tenancy\Http\Controllers\MiPagoAutomaticoTenantController;
use App\Modules\Tenancy\Http\Controllers\MiPrivacidadTenantController;
use App\Modules\Tenancy\Http\Controllers\MiReprogramarTenantController;
use App\Modules\Tenancy\Http\Controllers\MisDocumentosTenantController;
use App\Modules\Tenancy\Http\Controllers\MiTenantController;
use App\Modules\Tenancy\Http\Controllers\MovimientosPagoTenantController;
use App\Modules\Tenancy\Http\Controllers\OnboardingController;
use App\Modules\Tenancy\Http\Controllers\OrdenesTenantController;
use App\Modules\Tenancy\Http\Controllers\OrganizacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\PagoRentaController;
use App\Modules\Tenancy\Http\Controllers\PagosTenantController;
use App\Modules\Tenancy\Http\Controllers\ParametrosTenantController;
use App\Modules\Tenancy\Http\Controllers\PasarelasTenantController;
use App\Modules\Tenancy\Http\Controllers\PausasMembresiaTenantController;
use App\Modules\Tenancy\Http\Controllers\PerfilPublicoController;
use App\Modules\Tenancy\Http\Controllers\PerfilTenantController;
use App\Modules\Tenancy\Http\Controllers\PlantillasHorarioTenantController;
use App\Modules\Tenancy\Http\Controllers\PlantillasMensajeTenantController;
use App\Modules\Tenancy\Http\Controllers\PlataformaCobrosController;
use App\Modules\Tenancy\Http\Controllers\PlataformaController;
use App\Modules\Tenancy\Http\Controllers\PlataformaEstudiosController;
use App\Modules\Tenancy\Http\Controllers\PlataformaOperacionController;
use App\Modules\Tenancy\Http\Controllers\PoliticasCancelacionTenantController;
use App\Modules\Tenancy\Http\Controllers\PromocionesTenantController;
use App\Modules\Tenancy\Http\Controllers\PublicoCitasController;
use App\Modules\Tenancy\Http\Controllers\PuntoDeVentaTenantController;
use App\Modules\Tenancy\Http\Controllers\RecursosTenantController;
use App\Modules\Tenancy\Http\Controllers\ReembolsosTenantController;
use App\Modules\Tenancy\Http\Controllers\RegistroAlumnoController;
use App\Modules\Tenancy\Http\Controllers\RegistroEstudioController;
use App\Modules\Tenancy\Http\Controllers\ReporteCohortesTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteDemandaTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteNegocioTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteRentabilidadTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteSucursalesTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteTendenciasTenantController;
use App\Modules\Tenancy\Http\Controllers\ReprogramarTenantController;
use App\Modules\Tenancy\Http\Controllers\ResenasTenantController;
use App\Modules\Tenancy\Http\Controllers\ReservasTenantController;
use App\Modules\Tenancy\Http\Controllers\RespuestasFormularioController;
use App\Modules\Tenancy\Http\Controllers\ResumenMiembroTenantController;
use App\Modules\Tenancy\Http\Controllers\RetencionTenantController;
use App\Modules\Tenancy\Http\Controllers\RolesTenantController;
use App\Modules\Tenancy\Http\Controllers\SolicitudesPrivacidadTenantController;
use App\Modules\Tenancy\Http\Controllers\StaffTenantController;
use App\Modules\Tenancy\Http\Controllers\SuscripcionesTenantController;
use App\Modules\Tenancy\Http\Controllers\TareasTenantController;
use App\Modules\Tenancy\Http\Controllers\TarifasPlataformaController;
use App\Modules\Tenancy\Http\Controllers\TerminologiaTenantController;
use App\Modules\Tenancy\Http\Controllers\TiposDocumentoController;
use App\Modules\Tenancy\Http\Controllers\UsuariosTenantController;
use App\Modules\Tenancy\Http\Controllers\WaiversTenantController;
use App\Modules\Tenancy\Http\Controllers\WebhookPlataformaController;
use App\Modules\Tenancy\Http\Controllers\WebhooksSalientesTenantController;
use App\Modules\Tenancy\Http\Controllers\WebhookTenantController;
use Illuminate\Support\Facades\Route;

/*
| API v1. El prefijo "api" lo aplica bootstrap/app.php withRouting(), por lo que
| estas rutas resuelven bajo /api/v1/*. Rutas técnicas (health, webhooks) en
| inglés; recursos de dominio en español.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    /*
    | Control plane (SaaS multi-tenant por BD). Alta pública de estudios y
    | directorio (sin tenant), y acceso tenant-local resuelto por slug: la
    | identidad vive en la BD de cada estudio (no hay login global ni selector
    | de tenant tras el login). Ver docs/CONTROL_PLANE.md.
    */
    // Webhook publico de pasarela por estudio (data plane): resuelve el estudio por
    // slug y confirma el pago pendiente -> fulfillment. Sin sesion; idempotente.
    Route::post('/webhooks/tenant/{estudio}/{proveedor}', WebhookTenantController::class)
        ->middleware('estudio.resolver')->name('api.v1.webhooks.tenant');

    // Webhook publico de la pasarela de la PLATAFORMA: confirma el cargo de renta del
    // SaaS (plataforma -> dueño) -> pagado. Sin sesion; idempotente.
    Route::post('/webhooks/plataforma/{proveedor}', WebhookPlataformaController::class)
        ->name('api.v1.webhooks.plataforma');

    Route::post('/registro', [RegistroEstudioController::class, 'store'])->middleware('throttle:login')->name('api.v1.registro');
    Route::get('/registro/slug', [RegistroEstudioController::class, 'disponibilidad'])->middleware('throttle:60,1')->name('api.v1.registro.slug');
    Route::get('/directorio', [DirectorioController::class, 'index'])->middleware('throttle:60,1')->name('api.v1.directorio');
    // Documentos legales públicos (aviso de privacidad y términos) para el registro.
    Route::get('/legales', LegalesPublicoController::class)->middleware('throttle:60,1')->name('api.v1.legales');

    // Administracion de plataforma (PlatformAdmin): token global, sin tenant. Ve todos
    // los estudios y gestiona credenciales globales (cuenta FacturAPI).
    Route::prefix('plataforma')->middleware(['plataforma.auth', 'throttle:60,1'])->name('api.v1.plataforma.')->group(function (): void {
        Route::get('/estudios', [PlataformaController::class, 'estudios'])->name('estudios');
        Route::get('/estudios/{estudio}', [PlataformaEstudiosController::class, 'show'])->name('estudios.show');
        Route::post('/estudios/{estudio}/suspender', [PlataformaEstudiosController::class, 'suspender'])->name('estudios.suspender');
        Route::post('/estudios/{estudio}/reactivar', [PlataformaEstudiosController::class, 'reactivar'])->name('estudios.reactivar');
        Route::post('/estudios/{estudio}/extender-prueba', [PlataformaEstudiosController::class, 'extenderPrueba'])->name('estudios.extender-prueba');
        // Cómo se llaman las cosas en el negocio (ADR 0049).
        Route::get('/estudios/{estudio}/terminologia', [PlataformaEstudiosController::class, 'terminologia'])->name('estudios.terminologia');
        Route::put('/estudios/{estudio}/terminologia', [PlataformaEstudiosController::class, 'guardarTerminologia'])->name('estudios.terminologia.guardar');
        Route::get('/cobros', PlataformaCobrosController::class)->name('cobros');
        // Estado de la operación: versión, procesos, verificación, respaldos y alertas.
        Route::get('/operacion', PlataformaOperacionController::class)->name('operacion');
        Route::put('/estudios/{estudio}', [PlataformaController::class, 'actualizarEstudio'])->name('estudios.actualizar');
        Route::get('/configuracion', [PlataformaController::class, 'configuracion'])->name('configuracion');
        Route::put('/configuracion', [PlataformaController::class, 'guardarConfiguracion'])->name('configuracion.guardar');
        // Documentos legales (aviso de privacidad y términos) mostrados en el registro.
        Route::get('/parametros', [PlataformaController::class, 'parametros'])->name('parametros');
        Route::put('/parametros', [PlataformaController::class, 'guardarParametros'])->name('parametros.guardar');
        Route::get('/legales', [PlataformaController::class, 'legales'])->name('legales');
        Route::put('/legales', [PlataformaController::class, 'guardarLegales'])->name('legales.guardar');
        Route::post('/legales/{tipo}/publicar', [PlataformaController::class, 'publicarLegal'])
            ->whereIn('tipo', ['aviso_privacidad', 'terminos'])->name('legales.publicar');
        // Pasarelas de la plataforma (para cobrar la renta del SaaS): on/off + llaves test/prod.
        Route::get('/pasarelas', [PlataformaController::class, 'pasarelas'])->name('pasarelas');
        Route::put('/pasarelas/{proveedor}', [PlataformaController::class, 'guardarPasarela'])->name('pasarelas.guardar');
        // Tarifas del SaaS por modalidad (versionadas): consultar y publicar una versión nueva.
        Route::get('/tarifas', [TarifasPlataformaController::class, 'index'])->name('tarifas');
        Route::post('/tarifas/{modalidad}', [TarifasPlataformaController::class, 'publicar'])->name('tarifas.publicar');
    });

    /*
    | Rutas tenant-local. Se montan de dos formas equivalentes: por RUTA
    | (/app/{estudio}/...) y por SUBDOMINIO ({slug}.agendauno.mx/...). En ambos
    | casos `estudio.resolver` lee el param `{estudio}` (de la ruta o del dominio)
    | y activa la conexión del data plane. Ver docs/CONTROL_PLANE.md.
    */
    $rutasTenant = function (): void {
        Route::post('/login', [AuthTenantController::class, 'store'])->middleware('throttle:login')->name('login');
        Route::post('/auth/google', [AuthTenantController::class, 'google'])->middleware('throttle:login')->name('auth.google');
        Route::post('/activar', [AuthTenantController::class, 'activar'])->middleware('throttle:login')->name('activar');
        // Reenvío del correo de activación (público: el dueño aún no puede entrar).
        Route::post('/reenviar-activacion', [AuthTenantController::class, 'reenviarActivacion'])->middleware('throttle:login')->name('reenviar-activacion');
        Route::post('/recuperar-contrasena', [AuthTenantController::class, 'recuperarContrasena'])->middleware('throttle:recuperacion')->name('recuperar-contrasena');
        Route::post('/restablecer-contrasena', [AuthTenantController::class, 'restablecerContrasena'])->middleware('throttle:recuperacion')->name('restablecer-contrasena');
        Route::post('/confirmar-correo', [AuthTenantController::class, 'confirmarCorreo'])->middleware('throttle:login')->name('confirmar-correo');

        // Marca pública (branding): nombre + logo del estudio para la pantalla de
        // acceso (sin auth). Con throttle para mitigar sondeo de slugs.
        Route::get('/marca', [MarcaEstudioController::class, 'mostrar'])->middleware('throttle:60,1')->name('marca');

        // Embudo público (P0 #3): escaparate del estudio (identidad, próximas clases,
        // precios, instructores, ubicación) y registro público de alumno (self-signup
        // → auto-login). Sin auth; solo estudios listados en el directorio. Con throttle.
        Route::get('/escaparate', EscaparateController::class)->middleware('throttle:60,1')->name('escaparate');
        Route::post('/registro-alumno', RegistroAlumnoController::class)->middleware('throttle:login')->name('registro-alumno');
        // Confirmar el registro cuyo correo ya era de alguien en el negocio (enlace del correo).
        Route::post('/registro-alumno/confirmar', [RegistroAlumnoController::class, 'confirmar'])->middleware('throttle:login')->name('registro-alumno.confirmar');

        // Citas públicas (guest, sin cuenta): opciones (servicios/sucursales/barberos)
        // y disponibilidad para elegir hueco; luego agendar y pagar en línea (el
        // orden_id devuelto es la capacidad para pagar). Solo directorio.
        Route::get('/citas/opciones', [PublicoCitasController::class, 'opciones'])->middleware('throttle:60,1')->name('citas.opciones');
        Route::get('/citas/disponibilidad', [PublicoCitasController::class, 'disponibilidad'])->middleware('throttle:60,1')->name('citas.disponibilidad');
        Route::get('/citas/dias', [PublicoCitasController::class, 'dias'])->middleware('throttle:60,1')->name('citas.dias');
        Route::post('/citas', [PublicoCitasController::class, 'agendar'])->middleware('throttle:login')->name('citas.agendar');
        Route::post('/citas/pagar', [PublicoCitasController::class, 'pagar'])->middleware('throttle:login')->name('citas.pagar');
        // La cita por pagar del enlace del correo de apartado (el ULID de la orden es la capacidad).
        Route::get('/citas/orden/{orden}', [PublicoCitasController::class, 'orden'])->middleware('throttle:60,1')->name('citas.orden');

        // Calendario personal (iCal) que leen Google Calendar, Apple u Outlook con el
        // enlace privado de cada quien (sin sesión).
        Route::get('/calendario/{token}.ics', [CalendarioTenantController::class, 'feed'])->middleware('throttle:60,1')->name('calendario.feed');
        Route::get('/calendario/{token}/{evento}.ics', [CalendarioTenantController::class, 'evento'])
            ->where('evento', '(reserva|sesion)-[0-9A-Za-z]+')->middleware('throttle:60,1')->name('calendario.evento');

        Route::middleware(['estudio.auth', 'throttle:tenant'])->group(function (): void {
            Route::get('/yo', [AuthTenantController::class, 'yo'])->name('yo');
            Route::put('/yo/rol-activo', [AuthTenantController::class, 'rolActivo'])->name('yo.rol-activo');
            Route::get('/yo/calendario', [CalendarioTenantController::class, 'enlace'])->name('yo.calendario');
            Route::post('/yo/calendario/regenerar', [CalendarioTenantController::class, 'regenerar'])->name('yo.calendario.regenerar');
            // Apariencia personal (tema y colores propios), guardada en la cuenta.
            Route::get('/apariencia', [AparienciaTenantController::class, 'show'])->name('apariencia');
            Route::put('/apariencia', [AparienciaTenantController::class, 'elegir'])->name('apariencia.elegir');
            Route::put('/apariencia/color', [AparienciaTenantController::class, 'personalizar'])->name('apariencia.color');
            Route::delete('/apariencia/personalizacion', [AparienciaTenantController::class, 'restablecer'])->name('apariencia.restablecer');
            // Mi perfil: cada quien ajusta su nombre, su foto y su contraseña.
            Route::put('/yo/perfil', [PerfilTenantController::class, 'actualizar'])->name('yo.perfil');
            Route::put('/yo/contrasena', [PerfilTenantController::class, 'cambiarContrasena'])->name('yo.contrasena');
            Route::post('/yo/correo', [PerfilTenantController::class, 'solicitarCambioCorreo'])->middleware('throttle:recuperacion')->name('yo.correo.store');
            Route::delete('/yo/correo', [PerfilTenantController::class, 'cancelarCambioCorreo'])->name('yo.correo.destroy');
            Route::post('/yo/foto', [PerfilTenantController::class, 'subirFoto'])->name('yo.foto.store');
            Route::delete('/yo/foto', [PerfilTenantController::class, 'eliminarFoto'])->name('yo.foto.destroy');
            Route::post('/logout', [AuthTenantController::class, 'destroy'])->name('logout');

            // Autoservicio del miembro: opera solo sobre su propia persona (sin
            // permisos de staff). Resuelve la persona del usuario autenticado.
            Route::get('/mi/perfil', [MiTenantController::class, 'perfil'])->name('mi.perfil');
            Route::get('/mi/pase', [MiTenantController::class, 'pase'])->name('mi.pase');
            Route::get('/mi/agenda', [MiTenantController::class, 'agenda'])->name('mi.agenda');
            Route::post('/mi/reservas', [MiTenantController::class, 'reservar'])->name('mi.reservas.store');
            // Agenda una cita desde un hueco de disponibilidad (F-08): crea la sesión + reserva/pago.
            Route::get('/mi/citas/opciones', [MiTenantController::class, 'opcionesCita'])->name('mi.citas.opciones');
            Route::get('/mi/citas/disponibilidad', [MiTenantController::class, 'disponibilidadCita'])->name('mi.citas.disponibilidad');
            Route::post('/mi/citas', [MiTenantController::class, 'agendarCita'])->name('mi.citas.store');
            Route::post('/mi/reservas/{reserva}/cancelar', [MiTenantController::class, 'cancelar'])->name('mi.reservas.cancelar');
            // Cambiar el horario desde su cuenta (ADR 0044).
            Route::get('/mi/reservas/{reserva}/reprogramar', [MiReprogramarTenantController::class, 'opciones'])->name('mi.reservas.reprogramar.opciones');
            Route::post('/mi/reservas/{reserva}/reprogramar', [MiReprogramarTenantController::class, 'reprogramar'])->name('mi.reservas.reprogramar');
            Route::get('/mi/reservas/{reserva}/cancelacion', [MiTenantController::class, 'previsualizarCancelacion'])->name('mi.reservas.cancelacion');
            Route::get('/mi/derechos/{derecho}/movimientos', [MiTenantController::class, 'movimientosDerecho'])->name('mi.derechos.movimientos');
            Route::get('/mi/planes', [MiTenantController::class, 'planes'])->name('mi.planes');
            Route::get('/mi/clima', [MiTenantController::class, 'clima'])->middleware('throttle:30,1')->name('mi.clima');
            Route::post('/mi/reservas/{reserva}/aceptar', [MiTenantController::class, 'aceptar'])->name('mi.reservas.aceptar');
            Route::get('/mi/waivers', [MiTenantController::class, 'waiversPendientes'])->name('mi.waivers.index');
            Route::post('/mi/waivers/{waiver}/aceptar', [MiTenantController::class, 'aceptarWaiver'])->name('mi.waivers.aceptar');
            Route::get('/mi/formularios', [MiTenantController::class, 'formularios'])->name('mi.formularios.index');
            // Notificaciones push: la app registra el teléfono al iniciar sesión y lo quita al salir.
            Route::post('/mi/dispositivos', [MiDispositivosTenantController::class, 'registrar'])->name('mi.dispositivos.store');
            Route::delete('/mi/dispositivos', [MiDispositivosTenantController::class, 'quitar'])->name('mi.dispositivos.destroy');
            // Privacidad (ARCO): descargar mis datos, oponerme a promociones, pedir la baja.
            Route::get('/mi/privacidad', [MiPrivacidadTenantController::class, 'mostrar'])->name('mi.privacidad');
            Route::put('/mi/privacidad', [MiPrivacidadTenantController::class, 'actualizar'])->name('mi.privacidad.actualizar');
            Route::get('/mi/datos', [MiPrivacidadTenantController::class, 'datos'])->name('mi.datos');
            Route::post('/mi/privacidad/baja', [MiPrivacidadTenantController::class, 'solicitarBaja'])->name('mi.privacidad.baja');
            // Reseñas: lo que el alumno puede calificar y su calificación.
            Route::get('/mi/resenas/pendientes', [ResenasTenantController::class, 'pendientes'])->name('mi.resenas.pendientes');
            Route::post('/mi/reservas/{reserva}/resena', [ResenasTenantController::class, 'calificar'])->name('mi.reservas.resena');
            // Mis documentos: los que pide el negocio, subir el propio y descargarlo.
            Route::get('/mi/documentos', [MisDocumentosTenantController::class, 'index'])->name('mi.documentos.index');
            Route::post('/mi/documentos', [MisDocumentosTenantController::class, 'subir'])->name('mi.documentos.subir');
            Route::get('/mi/documentos/{documento}', [MisDocumentosTenantController::class, 'ver'])->name('mi.documentos.ver');
            // Ciclo comercial del alumno (P0 #4): comprar packs/membresías y pagarlos en
            // línea desde su portal. El fulfillment (créditos) lo confirma el webhook.
            Route::get('/mi/productos', [MiTenantController::class, 'productos'])->name('mi.productos.index');
            Route::get('/mi/ordenes', [MiTenantController::class, 'ordenes'])->name('mi.ordenes.index');
            Route::post('/mi/ordenes', [MiTenantController::class, 'comprar'])->name('mi.ordenes.store');
            Route::post('/mi/ordenes/{orden}/cobrar', [MiTenantController::class, 'cobrar'])->name('mi.ordenes.cobrar');
            // Pago automático: qué membresías se cobran solas y con qué tarjeta; activar,
            // quitar o cambiar la tarjeta (se autoriza en la página de la pasarela).
            Route::get('/mi/pago-automatico', [MiPagoAutomaticoTenantController::class, 'mostrar'])->name('mi.pago-automatico');
            Route::post('/mi/pago-automatico/tarjeta', [MiPagoAutomaticoTenantController::class, 'cambiarTarjeta'])->middleware('throttle:login')->name('mi.pago-automatico.tarjeta');
            Route::post('/mi/pago-automatico/{acuerdo}', [MiPagoAutomaticoTenantController::class, 'activar'])->middleware('throttle:login')->name('mi.pago-automatico.activar');
            Route::delete('/mi/pago-automatico/{acuerdo}', [MiPagoAutomaticoTenantController::class, 'desactivar'])->name('mi.pago-automatico.desactivar');

            // Invitación de personal (crea usuario tenant-local con rol + activación).
            Route::post('/usuarios/invitar', [UsuariosTenantController::class, 'invitar'])->middleware('puede:usuarios.invitar')->name('usuarios.invitar');
            Route::post('/usuarios/{usuario}/reenviar', [UsuariosTenantController::class, 'reenviar'])->middleware('puede:usuarios.invitar')->name('usuarios.reenviar');
            // Solo id + nombre: recepción lo necesita para la agenda por profesional.
            Route::get('/instructores', [UsuariosTenantController::class, 'instructores'])->middleware('puede:agenda.ver')->name('instructores.index');
            Route::get('/instructores/{usuario}', [UsuariosTenantController::class, 'instructor'])->middleware('puede:usuarios.gestionar')->name('instructores.show');

            // Apartado Usuarios: multi-rol por cuenta (rol de dueño protegido).
            Route::get('/usuarios', [UsuariosTenantController::class, 'index'])->middleware('puede:usuarios.gestionar')->name('usuarios.index');
            // Roles propios del negocio (ADR 0057): nadie da permisos que no tiene.
            Route::get('/roles', [RolesTenantController::class, 'index'])->middleware('puede:roles.gestionar')->name('roles.index');
            Route::post('/roles', [RolesTenantController::class, 'store'])->middleware('puede:roles.gestionar')->name('roles.store');
            Route::put('/roles/{rol}', [RolesTenantController::class, 'update'])->middleware('puede:roles.gestionar')->name('roles.update');
            Route::delete('/roles/{rol}', [RolesTenantController::class, 'destroy'])->middleware('puede:roles.gestionar')->name('roles.destroy');
            Route::put('/usuarios/{usuario}/roles', [UsuariosTenantController::class, 'actualizarRoles'])->middleware('puede:usuarios.gestionar')->name('usuarios.roles');
            // Baja lógica del equipo (quita el acceso, conserva el historial) y reactivación.
            Route::delete('/usuarios/{usuario}', [UsuariosTenantController::class, 'darDeBaja'])->middleware('puede:usuarios.gestionar')->name('usuarios.baja');
            Route::post('/usuarios/{usuario}/reactivar', [UsuariosTenantController::class, 'reactivar'])->middleware('puede:usuarios.gestionar')->name('usuarios.reactivar');

            // RBAC con scope por sucursal (R19): asigna a un usuario un rol EN una
            // sucursal, ampliando su rol tenant-wide.
            Route::get('/asignaciones-personal', [AsignacionesPersonalTenantController::class, 'index'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.index');
            Route::put('/asignaciones-personal', [AsignacionesPersonalTenantController::class, 'guardar'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.guardar');
            Route::delete('/asignaciones-personal/{asignacion}', [AsignacionesPersonalTenantController::class, 'eliminar'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.eliminar');

            // Operación tenant-local: alta de alumnos (data plane del estudio).
            Route::get('/miembros', [MiembrosTenantController::class, 'index'])->middleware('puede:miembros.ver')->name('miembros.index');
            // Reseñas de los alumnos (con promedios); el negocio puede ocultar una.
            Route::get('/resenas', [ResenasTenantController::class, 'index'])->middleware('puede:miembros.ver')->name('resenas.index');
            Route::put('/resenas/{resena}/visible', [ResenasTenantController::class, 'visibilidad'])->middleware('puede:miembros.gestionar')->name('resenas.visible');
            // Solicitudes de baja de datos (ARCO): el negocio las atiende o rechaza.
            Route::get('/solicitudes-privacidad', [SolicitudesPrivacidadTenantController::class, 'index'])->middleware('puede:miembros.gestionar')->name('solicitudes-privacidad.index');
            Route::post('/solicitudes-privacidad/{solicitud}/atender', [SolicitudesPrivacidadTenantController::class, 'atender'])->middleware('puede:miembros.gestionar')->name('solicitudes-privacidad.atender');
            Route::post('/solicitudes-privacidad/{solicitud}/rechazar', [SolicitudesPrivacidadTenantController::class, 'rechazar'])->middleware('puede:miembros.gestionar')->name('solicitudes-privacidad.rechazar');
            // Padrón facturable (P0): base de la renta SaaS; `?formato=csv` para exportar. Ruta literal antes de {persona}.
            Route::get('/miembros/padron', [MiembrosTenantController::class, 'padron'])->middleware('puede:facturacion.ver')->name('miembros.padron');
            Route::post('/miembros', [MiembrosTenantController::class, 'store'])->middleware('puede:miembros.gestionar')->name('miembros.store');
            // Editar datos y estado del alumno (suspender/archivar/no-facturable) con auditoría (P0).
            Route::put('/miembros/{persona}', [MiembrosTenantController::class, 'actualizar'])->middleware('puede:miembros.gestionar')->name('miembros.update');
            // Baja lógica del alumno (cierra lo vigente, conserva su historial) y reactivación.
            Route::delete('/miembros/{persona}', [MiembrosTenantController::class, 'darDeBaja'])->middleware('puede:miembros.gestionar')->name('miembros.baja');
            Route::post('/miembros/{persona}/reactivar', [MiembrosTenantController::class, 'reactivar'])->middleware('puede:miembros.gestionar')->name('miembros.reactivar');
            // Importacion CSV de miembros (R37): preview (valida) e import (todo-o-nada).
            Route::post('/importaciones/miembros/preview', [ImportacionesTenantController::class, 'previewMiembros'])->middleware('puede:miembros.gestionar')->name('importaciones.miembros.preview');
            Route::post('/importaciones/miembros', [ImportacionesTenantController::class, 'importarMiembros'])->middleware('puede:miembros.gestionar')->name('importaciones.miembros.store');
            // Importacion CSV de instructores (R37): crea cuentas de usuario rol instructor + activacion.
            Route::post('/importaciones/instructores/preview', [ImportacionesTenantController::class, 'previewInstructores'])->middleware('puede:usuarios.invitar')->name('importaciones.instructores.preview');
            Route::post('/importaciones/instructores', [ImportacionesTenantController::class, 'importarInstructores'])->middleware('puede:usuarios.invitar')->name('importaciones.instructores.store');

            // Tareas de seguimiento (R16): bandeja de pendientes del staff (manuales o automaticas).
            Route::get('/tareas', [TareasTenantController::class, 'index'])->middleware('puede:tareas.ver')->name('tareas.index');
            Route::post('/tareas', [TareasTenantController::class, 'store'])->middleware('puede:tareas.gestionar')->name('tareas.store');
            Route::post('/tareas/{tarea}/completar', [TareasTenantController::class, 'completar'])->middleware('puede:tareas.gestionar')->name('tareas.completar');
            Route::post('/tareas/{tarea}/reabrir', [TareasTenantController::class, 'reabrir'])->middleware('puede:tareas.gestionar')->name('tareas.reabrir');

            // Motor de automatizacion (R16): reglas trigger->condicion->retraso->accion (crear tarea).
            Route::get('/automatizaciones', [AutomatizacionesTenantController::class, 'index'])->middleware('puede:automatizaciones.gestionar')->name('automatizaciones.index');
            Route::post('/automatizaciones', [AutomatizacionesTenantController::class, 'store'])->middleware('puede:automatizaciones.gestionar')->name('automatizaciones.store');
            Route::put('/automatizaciones/{regla}', [AutomatizacionesTenantController::class, 'actualizar'])->middleware('puede:automatizaciones.gestionar')->name('automatizaciones.update');
            Route::delete('/automatizaciones/{regla}', [AutomatizacionesTenantController::class, 'eliminar'])->middleware('puede:automatizaciones.gestionar')->name('automatizaciones.destroy');

            // El día de hoy para el Inicio del negocio: cada bloque según los permisos.
            Route::get('/inicio/hoy', InicioHoyTenantController::class)->name('inicio.hoy');
            Route::get('/clima', ClimaEquipoTenantController::class)->middleware('throttle:30,1')->name('clima');

            // Facturación SaaS del estudio (control plane; separada de pagos de alumnos).
            Route::get('/facturacion', [FacturacionController::class, 'show'])->middleware('puede:facturacion.ver')->name('facturacion');
            Route::get('/renta', [FacturacionController::class, 'renta'])->middleware('puede:facturacion.ver')->name('renta');
            // Transparencia del cobro: a quién se contó en el periodo (alumnos o profesionales).
            Route::get('/renta/quien-cuenta', [FacturacionController::class, 'quienCuenta'])->middleware('puede:facturacion.ver')->name('renta.quien-cuenta');
            // Pago de la renta del SaaS con la pasarela de la plataforma (async -> pendiente
            // + checkout; el webhook de la plataforma confirma). El dueño paga su suscripcion.
            Route::post('/renta/cargos/{cargo}/pagar', [PagoRentaController::class, 'pagar'])->middleware('puede:facturacion.ver')->name('renta.pagar');
            // Factura (CFDI) de la renta del SaaS: emite el CFDI de un cargo pagado y
            // entrega el PDF/XML (AgendaUno emisor, el estudio receptor).
            Route::post('/renta/cargos/{cargo}/factura', [FacturaRentaController::class, 'emitir'])->middleware('puede:facturacion.ver')->name('renta.factura');
            Route::get('/renta/facturas/{factura}/{formato}', [FacturaRentaController::class, 'descargar'])->middleware('puede:facturacion.ver')->name('renta.factura.descargar');

            // Bitacora de auditoria (append-only): operaciones sensibles del estudio.
            Route::get('/auditorias', [AuditoriaController::class, 'index'])->middleware('puede:auditoria.ver')->name('auditorias.index');

            // Onboarding (guardar y continuar) y publicación en el directorio.
            Route::get('/onboarding', [OnboardingController::class, 'show'])->middleware('puede:estudio.gestionar')->name('onboarding.show');
            Route::get('/onboarding/quickstart', [OnboardingController::class, 'quickstart'])->middleware('puede:estudio.gestionar')->name('onboarding.quickstart');
            Route::put('/onboarding', [OnboardingController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('onboarding.guardar');
            Route::put('/publicacion', [OnboardingController::class, 'publicacion'])->middleware('puede:estudio.gestionar')->name('publicacion');
            // Perfil de negocio / industria (R35): defaults/terminologia/feature-flags.
            Route::put('/perfil', [OnboardingController::class, 'perfil'])->middleware('puede:estudio.gestionar')->name('perfil');

            // Logo del estudio (branding): lo gestiona el administrador.
            Route::post('/marca/logo', [MarcaEstudioController::class, 'subirLogo'])->middleware('puede:estudio.gestionar')->name('marca.logo.store');
            Route::delete('/marca/logo', [MarcaEstudioController::class, 'eliminarLogo'])->middleware('puede:estudio.gestionar')->name('marca.logo.destroy');
            // Perfil público: portada, descripción y redes (página pública y de enlaces).
            Route::post('/marca/portada', [MarcaEstudioController::class, 'subirPortada'])->middleware('puede:estudio.gestionar')->name('marca.portada.store');
            Route::delete('/marca/portada', [MarcaEstudioController::class, 'eliminarPortada'])->middleware('puede:estudio.gestionar')->name('marca.portada.destroy');
            Route::get('/perfil-publico', [PerfilPublicoController::class, 'mostrar'])->middleware('puede:estudio.gestionar')->name('perfil-publico.show');
            Route::put('/perfil-publico', [PerfilPublicoController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('perfil-publico.guardar');

            // Documentos: el admin define tipos requeridos; se cargan por persona y
            // el staff los valida (tenant-local, aislado).
            Route::get('/tipos-documento', [TiposDocumentoController::class, 'index'])->middleware('puede:miembros.ver')->name('tipos-documento.index');
            Route::post('/tipos-documento', [TiposDocumentoController::class, 'store'])->middleware('puede:documentos.gestionar')->name('tipos-documento.store');
            Route::put('/tipos-documento/{tipo}', [TiposDocumentoController::class, 'update'])->middleware('puede:documentos.gestionar')->name('tipos-documento.update');
            Route::get('/documentos', [DocumentosController::class, 'index'])->middleware('puede:miembros.ver')->name('documentos.index');
            Route::post('/documentos', [DocumentosController::class, 'subir'])->middleware('puede:documentos.subir')->name('documentos.subir');
            Route::get('/documentos/{documento}', [DocumentosController::class, 'ver'])->middleware('puede:miembros.ver')->name('documentos.ver');
            Route::post('/documentos/{documento}/validar', [DocumentosController::class, 'validar'])->middleware('puede:documentos.gestionar')->name('documentos.validar');

            // Waivers / consentimientos versionados (R27): publicar versiones y ver
            // vigentes; qué le falta firmar a un miembro (front desk). La persona los
            // acepta por autoservicio (grupo /mi).
            Route::get('/waivers', [WaiversTenantController::class, 'index'])->middleware('puede:documentos.gestionar')->name('waivers.index');
            Route::post('/waivers', [WaiversTenantController::class, 'publicar'])->middleware('puede:documentos.gestionar')->name('waivers.store');
            Route::post('/waivers/{waiver}/retirar', [WaiversTenantController::class, 'retirar'])->middleware('puede:documentos.gestionar')->name('waivers.retirar');
            Route::get('/miembros/{persona}/waivers', [WaiversTenantController::class, 'pendientesDePersona'])->middleware('puede:miembros.ver')->name('miembros.waivers.index');

            // Formularios dinámicos: el admin define formularios/campos; miembros e
            // instructores responden (validación dinámica). Tenant-local.
            Route::get('/formularios', [FormulariosController::class, 'index'])->middleware('puede:formularios.responder')->name('formularios.index');
            Route::post('/formularios', [FormulariosController::class, 'store'])->middleware('puede:formularios.gestionar')->name('formularios.store');
            Route::post('/formularios/{formulario}/campos', [FormulariosController::class, 'agregarCampo'])->middleware('puede:formularios.gestionar')->name('formularios.campos');
            Route::get('/formularios/{formulario}/respuestas', [RespuestasFormularioController::class, 'index'])->middleware('puede:formularios.gestionar')->name('formularios.respuestas.index');
            Route::post('/formularios/{formulario}/respuestas', [RespuestasFormularioController::class, 'store'])->middleware('puede:formularios.responder')->name('formularios.respuestas.store');

            // Catálogo del estudio (data plane del tenant): Programa → Actividad →
            // Nivel/Oferta. Primer módulo operativo migrado a la BD del tenant.
            Route::get('/programas', [CatalogoTenantController::class, 'programas'])->middleware('puede:catalogo.ver')->name('programas.index');
            Route::post('/programas', [CatalogoTenantController::class, 'crearPrograma'])->middleware('puede:catalogo.gestionar')->name('programas.store');
            Route::post('/programas/{programa}/actividades', [CatalogoTenantController::class, 'crearActividad'])->middleware('puede:catalogo.gestionar')->name('actividades.store');
            Route::post('/actividades/{actividad}/niveles', [CatalogoTenantController::class, 'crearNivel'])->middleware('puede:catalogo.gestionar')->name('niveles.store');
            Route::post('/actividades/{actividad}/ofertas', [CatalogoTenantController::class, 'crearOferta'])->middleware('puede:catalogo.gestionar')->name('ofertas.store');
            Route::get('/ofertas', [CatalogoTenantController::class, 'ofertas'])->middleware('puede:catalogo.ver')->name('ofertas.index');
            Route::put('/ofertas/{oferta}', [CatalogoTenantController::class, 'actualizarOferta'])->middleware('puede:catalogo.gestionar')->name('ofertas.update');

            // Capacidad por canal / marketplace (R20): reserva cupos de una oferta para un canal.
            Route::get('/ofertas/{oferta}/capacidad-canal', [CapacidadCanalTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('ofertas.capacidad-canal.index');
            Route::put('/ofertas/{oferta}/capacidad-canal', [CapacidadCanalTenantController::class, 'guardar'])->middleware('puede:agenda.gestionar')->name('ofertas.capacidad-canal.guardar');
            Route::delete('/capacidad-canal/{regla}', [CapacidadCanalTenantController::class, 'eliminar'])->middleware('puede:agenda.gestionar')->name('capacidad-canal.destroy');

            // Estructura del estudio (data plane del tenant): Organización → Sucursal.
            Route::get('/organizaciones', [OrganizacionesTenantController::class, 'organizaciones'])->middleware('puede:organizaciones.ver')->name('organizaciones.index');
            Route::post('/organizaciones', [OrganizacionesTenantController::class, 'crearOrganizacion'])->middleware('puede:organizaciones.gestionar')->name('organizaciones.store');
            Route::post('/organizaciones/{organizacion}/sucursales', [OrganizacionesTenantController::class, 'crearSucursal'])->middleware('puede:sucursales.gestionar')->name('sucursales.store');
            Route::get('/sucursales', [OrganizacionesTenantController::class, 'sucursales'])->middleware('puede:sucursales.ver')->name('sucursales.index');
            // Multi-sucursal (R18): editar la sucursal como unidad de negocio (moneda/impuesto/region).
            Route::put('/sucursales/{sucursal}', [OrganizacionesTenantController::class, 'actualizarSucursal'])->middleware('puede:sucursales.gestionar')->name('sucursales.update');
            // Foto de la sede (la que ve el cliente al elegirla, ADR 0064).
            Route::post('/sucursales/{sucursal}/foto', [OrganizacionesTenantController::class, 'subirFoto'])->middleware('puede:sucursales.gestionar')->name('sucursales.foto.store');
            Route::delete('/sucursales/{sucursal}/foto', [OrganizacionesTenantController::class, 'eliminarFoto'])->middleware('puede:sucursales.gestionar')->name('sucursales.foto.destroy');
            // Reporte consolidado por sucursal (R18).
            Route::get('/reportes/sucursales', ReporteSucursalesTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.sucursales');
            // Reporte de negocio (R29): metricas del periodo (ingresos, ocupacion, no-show, ARPU).
            Route::get('/reportes/negocio', ReporteNegocioTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.negocio');
            // Reporte de rentabilidad por clase (R30): ingreso vs costo de instructor por oferta.
            Route::get('/reportes/rentabilidad', ReporteRentabilidadTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.rentabilidad');
            // Analitica de demanda (R31): mapa dia x hora + por actividad (ocupacion y espera).
            Route::get('/reportes/demanda', ReporteDemandaTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.demanda');
            // Tendencias de ingresos (Etapa 2): serie temporal (dia/semana/mes) + desglose por producto. `?formato=csv`.
            Route::get('/reportes/tendencias', ReporteTendenciasTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.tendencias');
            // Cohortes de retención + embudo de conversión (Etapa 2): triángulo por mes de alta.
            Route::get('/reportes/cohortes', ReporteCohortesTenantController::class)->middleware('puede:facturacion.ver')->name('reportes.cohortes');
            // Retención (Etapa 2): radar de membresías por vencer / vencidas para renovar.
            // Es operativo (recepción hace la gestión), por eso `miembros.ver`. `?formato=csv`.
            Route::get('/retencion/por-vencer', [RetencionTenantController::class, 'porVencer'])->middleware('puede:miembros.ver')->name('retencion.por-vencer');

            // Agenda (data plane del tenant): materializa una Oferta en una Sucursal
            // a una hora concreta. La hora local (zona de la sucursal) se guarda en UTC
            // con snapshot de zona.
            Route::get('/sesiones', [AgendaTenantController::class, 'sesiones'])->middleware('puede:agenda.ver')->name('sesiones.index');
            // Smart-fill (R32): clases proximas con lugares libres (oportunidades de llenado).
            // Ruta literal ANTES de cualquier /sesiones/{sesion} para no ser sombreada.
            Route::get('/sesiones/oportunidades', [AgendaTenantController::class, 'oportunidades'])->middleware('puede:agenda.ver')->name('sesiones.oportunidades');
            // Verifica conflictos (instructor/sala/recurso) SIN guardar (rework Agenda).
            // Literal antes de /sesiones/{sesion} para no ser sombreada.
            Route::post('/sesiones/verificar', [AgendaTenantController::class, 'verificar'])->middleware('puede:agenda.gestionar')->name('sesiones.verificar');
            Route::post('/sesiones', [AgendaTenantController::class, 'crearSesion'])->middleware('puede:agenda.gestionar')->name('sesiones.store');
            Route::post('/sesiones/{sesion}/reprogramar', [ReprogramarTenantController::class, 'sesion'])->middleware('puede:agenda.gestionar')->name('sesiones.reprogramar');
            Route::post('/sesiones/{sesion}/cancelar', [AgendaTenantController::class, 'cancelar'])->middleware('puede:agenda.gestionar')->name('sesiones.cancelar');
            Route::get('/sesiones/{sesion}/cancelacion', [AgendaTenantController::class, 'previsualizarCancelacion'])->middleware('puede:agenda.gestionar')->name('sesiones.cancelacion');
            // El negocio agenda una cita para un cliente (recepción/teléfono): confirmada,
            // se cobra en caja. Quien gestiona reservas puede agendar.
            Route::post('/agenda/citas', [AgendaTenantController::class, 'agendarCita'])->middleware('puede:reservas.gestionar')->name('agenda.citas.store');

            // Front desk (R13): vista de un dia en una sucursal con metricas.
            Route::get('/front-desk', [FrontDeskTenantController::class, 'dia'])->middleware('puede:agenda.ver')->name('front-desk.dia');

            // Disponibilidad para citas (F-08): horario de atención del proveedor +
            // huecos libres para agendar (elegir barbero → disponibilidad → agendar).
            Route::get('/horarios-atencion', [DisponibilidadTenantController::class, 'horarios'])->middleware('puede:agenda.ver')->name('horarios-atencion.index');
            Route::put('/horarios-atencion', [DisponibilidadTenantController::class, 'guardarHorarios'])->middleware('puede:agenda.gestionar')->name('horarios-atencion.guardar');
            Route::get('/disponibilidad', [DisponibilidadTenantController::class, 'disponibilidad'])->middleware('puede:agenda.ver')->name('disponibilidad.index');

            // Agenda recurrente (R5): plantillas de horario (materializan sesiones con
            // serie_id), excepciones (feriados/cierres) y generacion bajo demanda.
            Route::get('/plantillas-horario', [PlantillasHorarioTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('plantillas-horario.index');
            Route::post('/plantillas-horario', [PlantillasHorarioTenantController::class, 'crear'])->middleware('puede:agenda.gestionar')->name('plantillas-horario.store');
            Route::delete('/plantillas-horario/{plantilla}', [PlantillasHorarioTenantController::class, 'eliminar'])->middleware('puede:agenda.gestionar')->name('plantillas-horario.eliminar');
            // "Esta y las siguientes" (2.5), con vista previa.
            Route::post('/plantillas-horario/{plantilla}/cambiar', [PlantillasHorarioTenantController::class, 'cambiar'])->middleware('puede:agenda.gestionar')->name('plantillas-horario.cambiar');
            Route::post('/plantillas-horario/{plantilla}/generar', [PlantillasHorarioTenantController::class, 'generar'])->middleware('puede:agenda.gestionar')->name('plantillas-horario.generar');
            // Bloqueos (2.2): comida/vacaciones de un profesional, cierre de sede, sala en mantenimiento.
            Route::get('/bloqueos', [BloqueosAgendaTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('bloqueos.index');
            Route::post('/bloqueos/previsualizar', [BloqueosAgendaTenantController::class, 'previsualizar'])->middleware('puede:agenda.gestionar')->name('bloqueos.previsualizar');
            Route::post('/bloqueos', [BloqueosAgendaTenantController::class, 'crear'])->middleware('puede:agenda.gestionar')->name('bloqueos.store');
            Route::delete('/bloqueos/{bloqueo}', [BloqueosAgendaTenantController::class, 'eliminar'])->middleware('puede:agenda.gestionar')->name('bloqueos.eliminar');
            Route::get('/excepciones-horario', [ExcepcionesHorarioTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('excepciones-horario.index');
            Route::post('/excepciones-horario', [ExcepcionesHorarioTenantController::class, 'crear'])->middleware('puede:agenda.gestionar')->name('excepciones-horario.store');
            Route::delete('/excepciones-horario/{excepcion}', [ExcepcionesHorarioTenantController::class, 'eliminar'])->middleware('puede:agenda.gestionar')->name('excepciones-horario.eliminar');

            // Staff multi + sustitucion + nomina (R17): asignar staff a una sesion (rol/
            // sustitucion), esquema de pago por staff y nomina de un periodo.
            Route::get('/sesiones/{sesion}/staff', [StaffTenantController::class, 'staffDeSesion'])->middleware('puede:agenda.ver')->name('sesiones.staff.index');
            Route::post('/sesiones/{sesion}/staff', [StaffTenantController::class, 'asignar'])->middleware('puede:agenda.gestionar')->name('sesiones.staff.store');
            Route::put('/staff/{usuario}/esquema-pago', [StaffTenantController::class, 'esquemaPago'])->middleware('puede:estudio.gestionar')->name('staff.esquema-pago');
            Route::get('/nomina', [StaffTenantController::class, 'nomina'])->middleware('puede:estudio.gestionar')->name('nomina');

            // Grupos / cursos con inscripcion (R25): un grupo sigue una serie; inscribir
            // auto-reserva las ocurrencias futuras.
            Route::get('/grupos', [GruposTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('grupos.index');
            Route::post('/grupos', [GruposTenantController::class, 'crear'])->middleware('puede:agenda.gestionar')->name('grupos.store');
            Route::get('/grupos/{grupo}/inscripciones', [GruposTenantController::class, 'inscripciones'])->middleware('puede:agenda.ver')->name('grupos.inscripciones.index');
            Route::post('/grupos/{grupo}/inscripciones', [GruposTenantController::class, 'inscribir'])->middleware('puede:agenda.gestionar')->name('grupos.inscripciones.store');

            // Recursos reservables (R3): salas/canchas/carriles/equipos. El motor de
            // agenda evita sobre-reservarlos (unidad = 1; pool = capacidad).
            Route::get('/recursos', [RecursosTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('recursos.index');
            Route::post('/recursos', [RecursosTenantController::class, 'crear'])->middleware('puede:agenda.gestionar')->name('recursos.store');
            Route::delete('/recursos/{recurso}', [RecursosTenantController::class, 'eliminar'])->middleware('puede:agenda.gestionar')->name('recursos.eliminar');
            Route::put('/sesiones/{sesion}/instructor', [AgendaTenantController::class, 'asignarInstructor'])->middleware('puede:agenda.gestionar')->name('sesiones.instructor');

            // Membresias (data plane del tenant): producto comercial → acuerdo →
            // derecho (entitlement) + ledger de creditos. El saldo se deriva del
            // ledger. La venta y las mutaciones del ledger son concurrency-safe.
            Route::get('/productos', [MembresiasTenantController::class, 'productos'])->middleware('puede:productos.ver')->name('productos.index');
            Route::post('/productos', [MembresiasTenantController::class, 'crearProducto'])->middleware('puede:productos.gestionar')->name('productos.store');
            // Editor completo de membresías (Etapa 2): editar plantilla / archivar-reactivar.
            Route::put('/productos/{producto}', [MembresiasTenantController::class, 'actualizarProducto'])->middleware('puede:productos.gestionar')->name('productos.update');
            Route::post('/acuerdos', [MembresiasTenantController::class, 'vender'])->middleware('puede:membresias.gestionar')->name('acuerdos.store');
            // Dunning (R10): morosidad de la membresia ante fallo de cobro.
            Route::get('/dunning', [DunningTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('dunning.index');
            Route::post('/acuerdos/{acuerdo}/cobro-fallido', [DunningTenantController::class, 'registrarFallo'])->middleware('puede:ordenes.gestionar')->name('acuerdos.cobro-fallido');
            Route::post('/acuerdos/{acuerdo}/regularizar', [DunningTenantController::class, 'regularizar'])->middleware('puede:ordenes.gestionar')->name('acuerdos.regularizar');
            // Pausar (congelar) y reanudar una membresía o paquete.
            Route::post('/acuerdos/{acuerdo}/pausar', [PausasMembresiaTenantController::class, 'pausar'])->middleware('puede:membresias.gestionar')->name('acuerdos.pausar');
            Route::post('/acuerdos/{acuerdo}/reanudar', [PausasMembresiaTenantController::class, 'reanudar'])->middleware('puede:membresias.gestionar')->name('acuerdos.reanudar');
            Route::get('/miembros/{persona}/derechos', [MembresiasTenantController::class, 'derechos'])->middleware('puede:derechos.ver')->name('miembros.derechos.index');
            Route::get('/miembros/{persona}/planes', [MembresiasTenantController::class, 'planes'])->middleware('puede:derechos.ver')->name('miembros.planes');
            // Resumen operativo del miembro para Recepcion (P0): membresia, saldo, adeudo, alertas.
            Route::get('/miembros/{persona}/resumen', ResumenMiembroTenantController::class)->middleware('puede:miembros.ver')->name('miembros.resumen');
            // Ficha 360° del alumno (P0 Etapa 1): derechos, historial de reservas y de compras.
            Route::get('/miembros/{persona}/ficha', FichaMiembroTenantController::class)->middleware('puede:miembros.ver')->name('miembros.ficha');
            // Expediente de una persona (miembro o instructor): documentos, consentimientos
            // y formularios. El de personal exige además usuarios.gestionar (en el controlador).
            Route::get('/personas/{persona}/expediente', [ExpedienteTenantController::class, 'show'])->middleware('puede:miembros.ver')->name('personas.expediente');
            Route::post('/derechos/{derecho}/topups', [MembresiasTenantController::class, 'topUp'])->middleware('puede:membresias.gestionar')->name('derechos.topups.store');

            // Creditos (data plane del tenant): consumo directo y retenciones (holds)
            // con confirmar/liberar/perder. Concurrencia protegida (lockForUpdate).
            Route::get('/derechos/{derecho}/movimientos', [CreditosTenantController::class, 'movimientos'])->middleware('puede:derechos.ver')->name('derechos.movimientos.index');
            Route::post('/derechos/{derecho}/consumos', [CreditosTenantController::class, 'consumir'])->middleware('puede:creditos.gestionar')->name('derechos.consumos.store');
            Route::post('/derechos/{derecho}/retenciones', [CreditosTenantController::class, 'retener'])->middleware('puede:creditos.gestionar')->name('derechos.retenciones.store');
            Route::post('/retenciones/{retencion}/confirmar', [CreditosTenantController::class, 'confirmar'])->middleware('puede:creditos.gestionar')->name('retenciones.confirmar');
            Route::post('/retenciones/{retencion}/liberar', [CreditosTenantController::class, 'liberar'])->middleware('puede:creditos.gestionar')->name('retenciones.liberar');
            Route::post('/retenciones/{retencion}/perder', [CreditosTenantController::class, 'perder'])->middleware('puede:creditos.gestionar')->name('retenciones.perder');

            // Reservas (booking) del tenant: motor transaccional sobre una sesion,
            // con lista de espera y politica de cancelacion por hold. La asistencia
            // liquida la retencion (presente consume, ausente pierde).
            Route::get('/sesiones/{sesion}/reservas', [ReservasTenantController::class, 'index'])->middleware('puede:reservas.ver')->name('sesiones.reservas.index');
            Route::post('/sesiones/{sesion}/reservas/preview', [ReservasTenantController::class, 'preview'])->middleware('puede:reservas.ver')->name('sesiones.reservas.preview');
            Route::post('/sesiones/{sesion}/reservas', [ReservasTenantController::class, 'reservar'])->middleware('puede:reservas.gestionar')->name('sesiones.reservas.store');
            // Reprogramar (2.1): una cita a otra hora/profesional o un alumno a otra fecha de su clase.
            Route::post('/reservas/{reserva}/reprogramar', [ReprogramarTenantController::class, 'reserva'])->middleware('puede:reservas.gestionar')->name('reservas.reprogramar');
            Route::post('/reservas/{reserva}/cancelar', [ReservasTenantController::class, 'cancelar'])->middleware('puede:reservas.gestionar')->name('reservas.cancelar');
            Route::get('/reservas/{reserva}/cancelacion', [ReservasTenantController::class, 'previsualizarCancelacion'])->middleware('puede:reservas.gestionar')->name('reservas.cancelacion');
            // Waitlist robusta (R7): el ofrecido acepta su cupo antes de que expire.
            Route::post('/reservas/{reserva}/aceptar', [ReservasTenantController::class, 'aceptar'])->middleware('puede:reservas.gestionar')->name('reservas.aceptar');
            // Smart-fill (R32): ofrece de golpe los cupos libres al inicio de la lista de espera.
            Route::post('/sesiones/{sesion}/promover', [ReservasTenantController::class, 'promover'])->middleware('puede:reservas.gestionar')->name('sesiones.promover');
            // Transferir/regalar el lugar a otra persona (R9).
            Route::post('/reservas/{reserva}/transferir', [ReservasTenantController::class, 'transferir'])->middleware('puede:reservas.gestionar')->name('reservas.transferir');
            Route::post('/reservas/{reserva}/asistencia', [AsistenciaTenantController::class, 'marcar'])->middleware('puede:asistencia.marcar')->name('reservas.asistencia.store');

            // Politica de cancelacion/no-show (R8): la reserva congela la vigente al
            // crearse; esto configura la global y overrides por actividad a futuro.
            // Parámetros configurables del negocio (ADR 0042).
            Route::get('/parametros', [ParametrosTenantController::class, 'index'])->middleware('puede:estudio.gestionar')->name('parametros.index');
            Route::put('/parametros', [ParametrosTenantController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('parametros.guardar');
            // Cómo se llaman las cosas en el negocio: clase/cita, alumno/cliente… (ADR 0049).
            Route::get('/terminologia', [TerminologiaTenantController::class, 'index'])->middleware('puede:estudio.gestionar')->name('terminologia.index');
            Route::put('/terminologia', [TerminologiaTenantController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('terminologia.guardar');
            Route::get('/politicas-cancelacion', [PoliticasCancelacionTenantController::class, 'index'])->middleware('puede:agenda.ver')->name('politicas-cancelacion.index');
            Route::put('/politicas-cancelacion', [PoliticasCancelacionTenantController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('politicas-cancelacion.guardar');

            // Ordenes (comercio del tenant): orden pendiente (precio congelado) →
            // liquidacion manual/ventanilla → fulfillment (concesion de derechos). El
            // cobro con pasarela real es un modulo posterior (cuando haya llaves).
            Route::get('/ordenes', [OrdenesTenantController::class, 'index'])->middleware('puede:ordenes.ver')->name('ordenes.index');
            Route::post('/ordenes', [OrdenesTenantController::class, 'crear'])->middleware('puede:ordenes.gestionar')->name('ordenes.store');
            Route::get('/ordenes/{orden}', [OrdenesTenantController::class, 'show'])->middleware('puede:ordenes.ver')->name('ordenes.show');
            Route::post('/ordenes/{orden}/liquidar', [OrdenesTenantController::class, 'liquidar'])->middleware('puede:ordenes.gestionar')->name('ordenes.liquidar');
            // Cobro en linea con la pasarela del estudio (asincrono -> pendiente +
            // checkout; el webhook confirma). Listo para activarse al cargar llaves.
            Route::post('/ordenes/{orden}/cobrar', [OrdenesTenantController::class, 'cobrar'])->middleware('puede:ordenes.gestionar')->name('ordenes.cobrar');

            // Promociones / cupones (R22): CRUD (admin) y validacion de un codigo en el checkout.
            Route::get('/promociones', [PromocionesTenantController::class, 'index'])->middleware('puede:promociones.gestionar')->name('promociones.index');
            Route::post('/promociones', [PromocionesTenantController::class, 'store'])->middleware('puede:promociones.gestionar')->name('promociones.store');
            Route::put('/promociones/{promocion}', [PromocionesTenantController::class, 'actualizar'])->middleware('puede:promociones.gestionar')->name('promociones.update');
            Route::delete('/promociones/{promocion}', [PromocionesTenantController::class, 'eliminar'])->middleware('puede:promociones.gestionar')->name('promociones.destroy');
            Route::post('/promociones/validar', [PromocionesTenantController::class, 'validar'])->middleware('puede:ordenes.gestionar')->name('promociones.validar');

            // Inventario + punto de venta minorista (R21): stock por sucursal (ledger) y tickets de caja.
            Route::get('/articulos', [InventarioTenantController::class, 'index'])->middleware('puede:inventario.ver')->name('articulos.index');
            Route::post('/articulos', [InventarioTenantController::class, 'store'])->middleware('puede:inventario.gestionar')->name('articulos.store');
            Route::put('/articulos/{articulo}', [InventarioTenantController::class, 'actualizar'])->middleware('puede:inventario.gestionar')->name('articulos.update');
            Route::post('/articulos/{articulo}/movimientos', [InventarioTenantController::class, 'movimiento'])->middleware('puede:inventario.gestionar')->name('articulos.movimientos.store');
            Route::get('/pos/ventas', [PuntoDeVentaTenantController::class, 'index'])->middleware('puede:inventario.ver')->name('pos.ventas.index');
            Route::post('/pos/ventas', [PuntoDeVentaTenantController::class, 'vender'])->middleware('puede:pos.vender')->name('pos.ventas.store');

            // Lealtad (R24): programa de puntos, recompensas, canjes y saldo por miembro.
            Route::get('/lealtad/programa', [LealtadTenantController::class, 'programa'])->middleware('puede:lealtad.ver')->name('lealtad.programa');
            Route::put('/lealtad/programa', [LealtadTenantController::class, 'guardarPrograma'])->middleware('puede:lealtad.gestionar')->name('lealtad.programa.guardar');
            Route::get('/lealtad/recompensas', [LealtadTenantController::class, 'recompensas'])->middleware('puede:lealtad.ver')->name('lealtad.recompensas.index');
            Route::post('/lealtad/recompensas', [LealtadTenantController::class, 'crearRecompensa'])->middleware('puede:lealtad.gestionar')->name('lealtad.recompensas.store');
            Route::put('/lealtad/recompensas/{recompensa}', [LealtadTenantController::class, 'actualizarRecompensa'])->middleware('puede:lealtad.gestionar')->name('lealtad.recompensas.update');
            Route::get('/lealtad/canjes', [LealtadTenantController::class, 'canjes'])->middleware('puede:lealtad.ver')->name('lealtad.canjes.index');
            Route::post('/lealtad/canjes', [LealtadTenantController::class, 'canjear'])->middleware('puede:lealtad.gestionar')->name('lealtad.canjes.store');
            Route::post('/lealtad/canjes/{canje}/entregar', [LealtadTenantController::class, 'entregarCanje'])->middleware('puede:lealtad.gestionar')->name('lealtad.canjes.entregar');
            Route::post('/lealtad/canjes/{canje}/cancelar', [LealtadTenantController::class, 'cancelarCanje'])->middleware('puede:lealtad.gestionar')->name('lealtad.canjes.cancelar');
            Route::get('/miembros/{persona}/puntos', [LealtadTenantController::class, 'puntosMiembro'])->middleware('puede:lealtad.ver')->name('miembros.puntos');
            Route::post('/miembros/{persona}/puntos/ajuste', [LealtadTenantController::class, 'ajustar'])->middleware('puede:lealtad.gestionar')->name('miembros.puntos.ajuste');

            // Devoluciones (refunds) de un pago: total (revierte entitlement) o parcial
            // (proporcional). Operacion sensible: exige motivo y queda auditada.
            // Pantalla de cobranza (Etapa 2): pagos capturados para consultar y reembolsar.
            Route::get('/pagos', [PagosTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('pagos.index');
            // Por conciliar: lo del dinero que alguien debe revisar (p. ej. devoluciones sin confirmar).
            Route::get('/incidencias-cobro', [IncidenciasCobroTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('incidencias-cobro.index');
            Route::post('/incidencias-cobro/{incidencia}/resolver', [IncidenciasCobroTenantController::class, 'resolver'])->middleware('puede:pagos.reembolsar')->name('incidencias-cobro.resolver');
            // Corte de caja: movimientos por fecha y por quién (cobros, devoluciones, ventas, cancelaciones).
            Route::get('/pagos/movimientos', [MovimientosPagoTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('pagos.movimientos');
            // Suscripciones recurrentes: próximas renovaciones que cobrará el scheduler (Etapa 2).
            Route::get('/suscripciones', [SuscripcionesTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('suscripciones.index');
            // Pago automático: invitar al alumno a activarlo (correo) o quitarlo a petición suya.
            Route::post('/suscripciones/{acuerdo}/pago-automatico/solicitar', [SuscripcionesTenantController::class, 'solicitarPagoAutomatico'])->middleware(['puede:ordenes.gestionar', 'throttle:login'])->name('suscripciones.pago-automatico.solicitar');
            Route::delete('/suscripciones/{acuerdo}/pago-automatico', [SuscripcionesTenantController::class, 'quitarPagoAutomatico'])->middleware('puede:ordenes.gestionar')->name('suscripciones.pago-automatico.quitar');
            Route::get('/pagos/{pago}/reembolsos', [ReembolsosTenantController::class, 'index'])->middleware('puede:pagos.reembolsar')->name('pagos.reembolsos.index');
            Route::post('/pagos/{pago}/reembolsos', [ReembolsosTenantController::class, 'store'])->middleware('puede:pagos.reembolsar')->name('pagos.reembolsos.store');

            // Pasarelas de pago del estudio: el propietario conecta sus llaves
            // (cifradas, nunca expuestas). El cobro en linea real corre cuando el
            // estudio carga sus llaves. Solo propietario (pagos.configurar).
            Route::get('/pasarelas', [PasarelasTenantController::class, 'index'])->middleware('puede:pagos.configurar')->name('pasarelas.index');
            Route::put('/pasarelas/{proveedor}', [PasarelasTenantController::class, 'upsert'])->middleware('puede:pagos.configurar')->name('pasarelas.upsert');

            // Datos fiscales del emisor (CFDI/FacturAPI): cada tenant carga los suyos.
            Route::get('/datos-fiscales', [DatosFiscalesTenantController::class, 'show'])->middleware('puede:estudio.gestionar')->name('datos-fiscales.show');
            Route::put('/datos-fiscales', [DatosFiscalesTenantController::class, 'guardar'])->middleware('puede:estudio.gestionar')->name('datos-fiscales.guardar');
            Route::post('/datos-fiscales/sello', [DatosFiscalesTenantController::class, 'subirSello'])->middleware('puede:estudio.gestionar')->name('datos-fiscales.sello');

            // Facturas (CFDI): emitir/timbrar vía FacturAPI, listar y consultar.
            Route::get('/facturas', [FacturasTenantController::class, 'index'])->middleware('puede:ordenes.ver')->name('facturas.index');
            Route::post('/facturas', [FacturasTenantController::class, 'emitir'])->middleware('puede:ordenes.gestionar')->name('facturas.store');
            Route::get('/facturas/{factura}', [FacturasTenantController::class, 'show'])->middleware('puede:ordenes.ver')->name('facturas.show');

            // Integraciones de bienestar (Wellhub / TotalPass): el propietario conecta
            // llaves (cifradas); el staff valida check-ins de esos usuarios en clases
            // (sin consumir creditos del estudio).
            Route::get('/integraciones', [IntegracionesTenantController::class, 'index'])->middleware('puede:integraciones.configurar')->name('integraciones.index');
            Route::put('/integraciones/{proveedor}', [IntegracionesTenantController::class, 'upsert'])->middleware('puede:integraciones.configurar')->name('integraciones.upsert');

            // Webhooks salientes (R40): endpoints firmados que consumen el outbox. El
            // secreto se devuelve solo al crear. Configuracion solo del propietario.
            Route::get('/webhooks-salientes', [WebhooksSalientesTenantController::class, 'index'])->middleware('puede:integraciones.configurar')->name('webhooks-salientes.index');
            Route::post('/webhooks-salientes', [WebhooksSalientesTenantController::class, 'crear'])->middleware('puede:integraciones.configurar')->name('webhooks-salientes.store');
            Route::delete('/webhooks-salientes/{webhook}', [WebhooksSalientesTenantController::class, 'eliminar'])->middleware('puede:integraciones.configurar')->name('webhooks-salientes.eliminar');
            Route::get('/webhooks-salientes/{webhook}/entregas', [WebhooksSalientesTenantController::class, 'entregas'])->middleware('puede:integraciones.configurar')->name('webhooks-salientes.entregas');

            // Llaves de API con scopes (R40): el secreto se muestra solo al crear.
            Route::get('/llaves-api', [LlavesApiTenantController::class, 'index'])->middleware('puede:integraciones.configurar')->name('llaves-api.index');
            Route::post('/llaves-api', [LlavesApiTenantController::class, 'store'])->middleware('puede:integraciones.configurar')->name('llaves-api.store');
            Route::delete('/llaves-api/{llave}', [LlavesApiTenantController::class, 'destroy'])->middleware('puede:integraciones.configurar')->name('llaves-api.destroy');

            // Comunicaciones (R28): plantillas por evento/canal y el historial de
            // mensajes generados/enviados (consumidor del outbox).
            Route::get('/plantillas-mensaje', [PlantillasMensajeTenantController::class, 'index'])->middleware('puede:comunicaciones.gestionar')->name('plantillas-mensaje.index');
            Route::put('/plantillas-mensaje', [PlantillasMensajeTenantController::class, 'guardar'])->middleware('puede:comunicaciones.gestionar')->name('plantillas-mensaje.guardar');
            Route::delete('/plantillas-mensaje/{plantilla}', [PlantillasMensajeTenantController::class, 'eliminar'])->middleware('puede:comunicaciones.gestionar')->name('plantillas-mensaje.eliminar');
            Route::get('/mensajes', [MensajesTenantController::class, 'index'])->middleware('puede:comunicaciones.ver')->name('mensajes.index');

            // Comunicaciones segmentadas (difusiones): audiencia dinámica (todos/por
            // vencer/vencidos/primerizos) + envío puntual que encola un mensaje por
            // destinatario (lo entrega el relay R28) + historial.
            Route::get('/comunicaciones/segmentos', [DifusionesTenantController::class, 'segmentos'])->middleware('puede:comunicaciones.ver')->name('difusiones.segmentos');
            Route::get('/comunicaciones/difusiones', [DifusionesTenantController::class, 'index'])->middleware('puede:comunicaciones.ver')->name('difusiones.index');
            Route::post('/comunicaciones/difusiones', [DifusionesTenantController::class, 'difundir'])->middleware('puede:comunicaciones.gestionar')->name('difusiones.store');
            Route::post('/checkins', [CheckinsTenantController::class, 'registrar'])->middleware('puede:checkins.registrar')->name('checkins.store');
            Route::get('/sesiones/{sesion}/checkins', [CheckinsTenantController::class, 'index'])->middleware('puede:checkins.registrar')->name('sesiones.checkins.index');

            // Control de acceso (R12): la puerta registra un intento y el motor decide
            // (reserva vigente u OPEN_ACCESS por membresia ilimitada); deja bitacora.
            Route::post('/accesos', [AccesosTenantController::class, 'registrar'])->middleware('puede:checkins.registrar')->name('accesos.store');
            Route::get('/accesos', [AccesosTenantController::class, 'index'])->middleware('puede:checkins.registrar')->name('accesos.index');
        });

        // API de integracion de terceros (R40): autenticada por LLAVE DE API (no por
        // sesion de usuario) y acotada por scopes. Solo lectura.
        Route::middleware(['estudio.llave', 'throttle:tenant'])->prefix('integracion')->name('integracion.')->group(function (): void {
            Route::get('/miembros', [IntegracionApiTenantController::class, 'miembros'])->middleware('alcance:miembros.ver')->name('miembros');
            Route::get('/sesiones', [IntegracionApiTenantController::class, 'sesiones'])->middleware('alcance:agenda.ver')->name('sesiones');
        });
    };

    // Acceso por ruta: /api/v1/app/{estudio}/...
    Route::prefix('app/{estudio}')->middleware('estudio.resolver')->name('api.v1.app.')->group($rutasTenant);

    // Acceso por subdominio: {slug}.agendauno.mx/api/v1/... (mismo comportamiento).
    Route::domain('{estudio}.'.config('agendauno.dominio_base'))
        ->middleware('estudio.resolver')
        ->name('api.v1.sub.')
        ->group($rutasTenant);
});
