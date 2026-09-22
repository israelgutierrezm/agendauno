<?php

declare(strict_types=1);

use App\Modules\Agenda\Http\Controllers\AsignacionSesionController;
use App\Modules\Agenda\Http\Controllers\MiAgendaController;
use App\Modules\Agenda\Http\Controllers\PlantillaHorarioController;
use App\Modules\Agenda\Http\Controllers\SesionController;
use App\Modules\Asistencia\Http\Controllers\AsistenciaController;
use App\Modules\Catalogo\Http\Controllers\ActividadController;
use App\Modules\Catalogo\Http\Controllers\NivelController;
use App\Modules\Catalogo\Http\Controllers\OfertaController;
use App\Modules\Catalogo\Http\Controllers\ProgramaController;
use App\Modules\Creditos\Http\Controllers\ConsumoController;
use App\Modules\Creditos\Http\Controllers\RetencionController;
use App\Modules\Identity\Http\Controllers\Auth\SessionController;
use App\Modules\Identity\Http\Controllers\Auth\TokenController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\UsuarioController;
use App\Modules\Membresias\Http\Controllers\AcuerdoController;
use App\Modules\Membresias\Http\Controllers\DerechoController;
use App\Modules\Membresias\Http\Controllers\ProductoComercialController;
use App\Modules\Membresias\Http\Controllers\TopUpController;
use App\Modules\Ordenes\Http\Controllers\OrdenController;
use App\Modules\Organizaciones\Http\Controllers\OrganizacionController;
use App\Modules\Organizaciones\Http\Controllers\PersonalSucursalController;
use App\Modules\Organizaciones\Http\Controllers\SucursalController;
use App\Modules\Pagos\Http\Controllers\ConfiguracionPasarelaController;
use App\Modules\Pagos\Http\Controllers\PagoController;
use App\Modules\Pagos\Http\Controllers\VentanillaController;
use App\Modules\Pagos\Http\Controllers\WebhookMercadoPagoController;
use App\Modules\Pagos\Http\Controllers\WebhookOpenPayController;
use App\Modules\Pagos\Http\Controllers\WebhookPagoController;
use App\Modules\Pagos\Http\Controllers\WebhookStripeController;
use App\Modules\Personas\Http\Controllers\PerfilPersonaController;
use App\Modules\Personas\Http\Controllers\PersonaController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Portal\Http\Controllers\AgendaController as PortalAgendaController;
use App\Modules\Portal\Http\Controllers\CompraController as PortalCompraController;
use App\Modules\Portal\Http\Controllers\PasarelaPublicaController;
use App\Modules\Portal\Http\Controllers\PerfilController as PortalPerfilController;
use App\Modules\Recursos\Http\Controllers\InstalacionController;
use App\Modules\Recursos\Http\Controllers\RecursoController;
use App\Modules\Reservas\Http\Controllers\ReservaController;
use App\Modules\Tenancy\Http\Controllers\AccesosTenantController;
use App\Modules\Tenancy\Http\Controllers\AgendaTenantController;
use App\Modules\Tenancy\Http\Controllers\AsignacionesPersonalTenantController;
use App\Modules\Tenancy\Http\Controllers\AsistenciaTenantController;
use App\Modules\Tenancy\Http\Controllers\AuditoriaController;
use App\Modules\Tenancy\Http\Controllers\AuthTenantController;
use App\Modules\Tenancy\Http\Controllers\AutomatizacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\CapacidadCanalTenantController;
use App\Modules\Tenancy\Http\Controllers\CatalogoTenantController;
use App\Modules\Tenancy\Http\Controllers\CheckinsTenantController;
use App\Modules\Tenancy\Http\Controllers\CreditosTenantController;
use App\Modules\Tenancy\Http\Controllers\DatosFiscalesTenantController;
use App\Modules\Tenancy\Http\Controllers\DifusionesTenantController;
use App\Modules\Tenancy\Http\Controllers\DirectorioController;
use App\Modules\Tenancy\Http\Controllers\DisponibilidadTenantController;
use App\Modules\Tenancy\Http\Controllers\DocumentosController;
use App\Modules\Tenancy\Http\Controllers\DunningTenantController;
use App\Modules\Tenancy\Http\Controllers\EscaparateController;
use App\Modules\Tenancy\Http\Controllers\ExcepcionesHorarioTenantController;
use App\Modules\Tenancy\Http\Controllers\FacturacionController;
use App\Modules\Tenancy\Http\Controllers\FacturaRentaController;
use App\Modules\Tenancy\Http\Controllers\FacturasTenantController;
use App\Modules\Tenancy\Http\Controllers\FichaMiembroTenantController;
use App\Modules\Tenancy\Http\Controllers\FormulariosController;
use App\Modules\Tenancy\Http\Controllers\FrontDeskTenantController;
use App\Modules\Tenancy\Http\Controllers\GruposTenantController;
use App\Modules\Tenancy\Http\Controllers\ImportacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\IntegracionApiTenantController;
use App\Modules\Tenancy\Http\Controllers\IntegracionesTenantController;
use App\Modules\Tenancy\Http\Controllers\InventarioTenantController;
use App\Modules\Tenancy\Http\Controllers\LealtadTenantController;
use App\Modules\Tenancy\Http\Controllers\LlavesApiTenantController;
use App\Modules\Tenancy\Http\Controllers\MarcaEstudioController;
use App\Modules\Tenancy\Http\Controllers\MembresiasTenantController;
use App\Modules\Tenancy\Http\Controllers\MensajesTenantController;
use App\Modules\Tenancy\Http\Controllers\MiembrosTenantController;
use App\Modules\Tenancy\Http\Controllers\MiTenantController;
use App\Modules\Tenancy\Http\Controllers\OnboardingController;
use App\Modules\Tenancy\Http\Controllers\OrdenesTenantController;
use App\Modules\Tenancy\Http\Controllers\OrganizacionesTenantController;
use App\Modules\Tenancy\Http\Controllers\PagoRentaController;
use App\Modules\Tenancy\Http\Controllers\PagosTenantController;
use App\Modules\Tenancy\Http\Controllers\PasarelasTenantController;
use App\Modules\Tenancy\Http\Controllers\PlantillasHorarioTenantController;
use App\Modules\Tenancy\Http\Controllers\PlantillasMensajeTenantController;
use App\Modules\Tenancy\Http\Controllers\PlataformaController;
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
use App\Modules\Tenancy\Http\Controllers\ReservasTenantController;
use App\Modules\Tenancy\Http\Controllers\RespuestasFormularioController;
use App\Modules\Tenancy\Http\Controllers\ResumenMiembroTenantController;
use App\Modules\Tenancy\Http\Controllers\RetencionTenantController;
use App\Modules\Tenancy\Http\Controllers\StaffTenantController;
use App\Modules\Tenancy\Http\Controllers\SuscripcionesTenantController;
use App\Modules\Tenancy\Http\Controllers\TareasTenantController;
use App\Modules\Tenancy\Http\Controllers\TiposDocumentoController;
use App\Modules\Tenancy\Http\Controllers\UsuariosTenantController;
use App\Modules\Tenancy\Http\Controllers\WaiversTenantController;
use App\Modules\Tenancy\Http\Controllers\WebhookPlataformaController;
use App\Modules\Tenancy\Http\Controllers\WebhooksSalientesTenantController;
use App\Modules\Tenancy\Http\Controllers\WebhookTenantController;
use Illuminate\Support\Facades\Route;

/*
| API v1. El prefijo "api" lo aplica bootstrap/app.php withRouting(), por lo que
| estas rutas resuelven bajo /api/v1/*. Rutas técnicas (auth, me, health) en
| inglés; recursos de dominio en español.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    // Autenticación (sin sesión previa). Con rate limit por identidad+IP (SEC-03).
    Route::post('/auth/token', [TokenController::class, 'store'])->middleware('throttle:login')->name('api.v1.auth.token');
    Route::post('/auth/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('api.v1.auth.login');

    // Webhook de pagos: público (sin sesión ni tenant); idempotente. La firma del
    // proveedor debe verificarse antes de producción.
    Route::post('/webhooks/pagos/{proveedor}', WebhookPagoController::class)->name('api.v1.webhooks.pagos');
    Route::post('/webhooks/stripe', WebhookStripeController::class)->name('api.v1.webhooks.stripe');
    Route::post('/webhooks/openpay', WebhookOpenPayController::class)->name('api.v1.webhooks.openpay');
    Route::post('/webhooks/mercadopago/{tenant}', WebhookMercadoPagoController::class)->name('api.v1.webhooks.mercadopago');

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

    // Administracion de plataforma (PlatformAdmin): token global, sin tenant. Ve todos
    // los estudios y gestiona credenciales globales (cuenta FacturAPI).
    Route::prefix('plataforma')->middleware(['plataforma.auth', 'throttle:60,1'])->name('api.v1.plataforma.')->group(function (): void {
        Route::get('/estudios', [PlataformaController::class, 'estudios'])->name('estudios');
        Route::put('/estudios/{estudio}', [PlataformaController::class, 'actualizarEstudio'])->name('estudios.actualizar');
        Route::get('/configuracion', [PlataformaController::class, 'configuracion'])->name('configuracion');
        Route::put('/configuracion', [PlataformaController::class, 'guardarConfiguracion'])->name('configuracion.guardar');
        // Pasarelas de la plataforma (para cobrar la renta del SaaS): on/off + llaves test/prod.
        Route::get('/pasarelas', [PlataformaController::class, 'pasarelas'])->name('pasarelas');
        Route::put('/pasarelas/{proveedor}', [PlataformaController::class, 'guardarPasarela'])->name('pasarelas.guardar');
    });

    /*
    | Rutas tenant-local. Se montan de dos formas equivalentes: por RUTA
    | (/app/{estudio}/...) y por SUBDOMINIO ({slug}.turnouno.com/...). En ambos
    | casos `estudio.resolver` lee el param `{estudio}` (de la ruta o del dominio)
    | y activa la conexión del data plane. Ver docs/CONTROL_PLANE.md.
    */
    $rutasTenant = function (): void {
        Route::post('/login', [AuthTenantController::class, 'store'])->middleware('throttle:login')->name('login');
        Route::post('/auth/google', [AuthTenantController::class, 'google'])->middleware('throttle:login')->name('auth.google');
        Route::post('/activar', [AuthTenantController::class, 'activar'])->middleware('throttle:login')->name('activar');
        // Reenvío del correo de activación (público: el dueño aún no puede entrar).
        Route::post('/reenviar-activacion', [AuthTenantController::class, 'reenviarActivacion'])->middleware('throttle:login')->name('reenviar-activacion');

        // Marca pública (branding): nombre + logo del estudio para la pantalla de
        // acceso (sin auth). Con throttle para mitigar sondeo de slugs.
        Route::get('/marca', [MarcaEstudioController::class, 'mostrar'])->middleware('throttle:60,1')->name('marca');

        // Embudo público (P0 #3): escaparate del estudio (identidad, próximas clases,
        // precios, instructores, ubicación) y registro público de alumno (self-signup
        // → auto-login). Sin auth; solo estudios listados en el directorio. Con throttle.
        Route::get('/escaparate', EscaparateController::class)->middleware('throttle:60,1')->name('escaparate');
        Route::post('/registro-alumno', RegistroAlumnoController::class)->middleware('throttle:login')->name('registro-alumno');

        // Citas públicas (guest, sin cuenta): agendar una cita desde un hueco y pagarla
        // en línea (el orden_id devuelto es la capacidad para pagar). Solo directorio.
        Route::post('/citas', [PublicoCitasController::class, 'agendar'])->middleware('throttle:login')->name('citas.agendar');
        Route::post('/citas/pagar', [PublicoCitasController::class, 'pagar'])->middleware('throttle:login')->name('citas.pagar');

        Route::middleware(['estudio.auth', 'throttle:tenant'])->group(function (): void {
            Route::get('/yo', [AuthTenantController::class, 'yo'])->name('yo');
            Route::post('/logout', [AuthTenantController::class, 'destroy'])->name('logout');

            // Autoservicio del miembro: opera solo sobre su propia persona (sin
            // permisos de staff). Resuelve la persona del usuario autenticado.
            Route::get('/mi/perfil', [MiTenantController::class, 'perfil'])->name('mi.perfil');
            Route::get('/mi/agenda', [MiTenantController::class, 'agenda'])->name('mi.agenda');
            Route::post('/mi/reservas', [MiTenantController::class, 'reservar'])->name('mi.reservas.store');
            // Agenda una cita desde un hueco de disponibilidad (F-08): crea la sesión + reserva/pago.
            Route::post('/mi/citas', [MiTenantController::class, 'agendarCita'])->name('mi.citas.store');
            Route::post('/mi/reservas/{reserva}/cancelar', [MiTenantController::class, 'cancelar'])->name('mi.reservas.cancelar');
            Route::post('/mi/reservas/{reserva}/aceptar', [MiTenantController::class, 'aceptar'])->name('mi.reservas.aceptar');
            Route::get('/mi/waivers', [MiTenantController::class, 'waiversPendientes'])->name('mi.waivers.index');
            Route::post('/mi/waivers/{waiver}/aceptar', [MiTenantController::class, 'aceptarWaiver'])->name('mi.waivers.aceptar');
            // Ciclo comercial del alumno (P0 #4): comprar packs/membresías y pagarlos en
            // línea desde su portal. El fulfillment (créditos) lo confirma el webhook.
            Route::get('/mi/productos', [MiTenantController::class, 'productos'])->name('mi.productos.index');
            Route::get('/mi/ordenes', [MiTenantController::class, 'ordenes'])->name('mi.ordenes.index');
            Route::post('/mi/ordenes', [MiTenantController::class, 'comprar'])->name('mi.ordenes.store');
            Route::post('/mi/ordenes/{orden}/cobrar', [MiTenantController::class, 'cobrar'])->name('mi.ordenes.cobrar');

            // Invitación de personal (crea usuario tenant-local con rol + activación).
            Route::post('/usuarios/invitar', [UsuariosTenantController::class, 'invitar'])->middleware('puede:usuarios.invitar')->name('usuarios.invitar');
            Route::post('/usuarios/{usuario}/reenviar', [UsuariosTenantController::class, 'reenviar'])->middleware('puede:usuarios.invitar')->name('usuarios.reenviar');
            Route::get('/instructores', [UsuariosTenantController::class, 'instructores'])->middleware('puede:agenda.gestionar')->name('instructores.index');

            // Apartado Usuarios: multi-rol por cuenta (rol de dueño protegido).
            Route::get('/usuarios', [UsuariosTenantController::class, 'index'])->middleware('puede:usuarios.gestionar')->name('usuarios.index');
            Route::put('/usuarios/{usuario}/roles', [UsuariosTenantController::class, 'actualizarRoles'])->middleware('puede:usuarios.gestionar')->name('usuarios.roles');

            // RBAC con scope por sucursal (R19): asigna a un usuario un rol EN una
            // sucursal, ampliando su rol tenant-wide.
            Route::get('/asignaciones-personal', [AsignacionesPersonalTenantController::class, 'index'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.index');
            Route::put('/asignaciones-personal', [AsignacionesPersonalTenantController::class, 'guardar'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.guardar');
            Route::delete('/asignaciones-personal/{asignacion}', [AsignacionesPersonalTenantController::class, 'eliminar'])->middleware('puede:usuarios.invitar')->name('asignaciones-personal.eliminar');

            // Operación tenant-local: alta de alumnos (data plane del estudio).
            Route::get('/miembros', [MiembrosTenantController::class, 'index'])->middleware('puede:miembros.ver')->name('miembros.index');
            // Padrón facturable (P0): base de la renta SaaS; `?formato=csv` para exportar. Ruta literal antes de {persona}.
            Route::get('/miembros/padron', [MiembrosTenantController::class, 'padron'])->middleware('puede:facturacion.ver')->name('miembros.padron');
            Route::post('/miembros', [MiembrosTenantController::class, 'store'])->middleware('puede:miembros.gestionar')->name('miembros.store');
            // Editar datos y estado del alumno (suspender/archivar/no-facturable) con auditoría (P0).
            Route::put('/miembros/{persona}', [MiembrosTenantController::class, 'actualizar'])->middleware('puede:miembros.gestionar')->name('miembros.update');
            // Importacion CSV de miembros (R37): preview (valida) e import (todo-o-nada).
            Route::post('/importaciones/miembros/preview', [ImportacionesTenantController::class, 'previewMiembros'])->middleware('puede:miembros.gestionar')->name('importaciones.miembros.preview');
            Route::post('/importaciones/miembros', [ImportacionesTenantController::class, 'importarMiembros'])->middleware('puede:miembros.gestionar')->name('importaciones.miembros.store');

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

            // Facturación SaaS del estudio (control plane; separada de pagos de alumnos).
            Route::get('/facturacion', [FacturacionController::class, 'show'])->middleware('puede:facturacion.ver')->name('facturacion');
            Route::get('/renta', [FacturacionController::class, 'renta'])->middleware('puede:facturacion.ver')->name('renta');
            // Pago de la renta del SaaS con la pasarela de la plataforma (async -> pendiente
            // + checkout; el webhook de la plataforma confirma). El dueño paga su suscripcion.
            Route::post('/renta/cargos/{cargo}/pagar', [PagoRentaController::class, 'pagar'])->middleware('puede:facturacion.ver')->name('renta.pagar');
            // Factura (CFDI) de la renta del SaaS: emite el CFDI de un cargo pagado y
            // entrega el PDF/XML (TurnoUno emisor, el estudio receptor).
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
            Route::post('/sesiones/{sesion}/cancelar', [AgendaTenantController::class, 'cancelar'])->middleware('puede:agenda.gestionar')->name('sesiones.cancelar');

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
            Route::post('/plantillas-horario/{plantilla}/generar', [PlantillasHorarioTenantController::class, 'generar'])->middleware('puede:agenda.gestionar')->name('plantillas-horario.generar');
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
            Route::get('/miembros/{persona}/derechos', [MembresiasTenantController::class, 'derechos'])->middleware('puede:derechos.ver')->name('miembros.derechos.index');
            // Resumen operativo del miembro para Recepcion (P0): membresia, saldo, adeudo, alertas.
            Route::get('/miembros/{persona}/resumen', ResumenMiembroTenantController::class)->middleware('puede:miembros.ver')->name('miembros.resumen');
            // Ficha 360° del alumno (P0 Etapa 1): derechos, historial de reservas y de compras.
            Route::get('/miembros/{persona}/ficha', FichaMiembroTenantController::class)->middleware('puede:miembros.ver')->name('miembros.ficha');
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
            Route::post('/reservas/{reserva}/cancelar', [ReservasTenantController::class, 'cancelar'])->middleware('puede:reservas.gestionar')->name('reservas.cancelar');
            // Waitlist robusta (R7): el ofrecido acepta su cupo antes de que expire.
            Route::post('/reservas/{reserva}/aceptar', [ReservasTenantController::class, 'aceptar'])->middleware('puede:reservas.gestionar')->name('reservas.aceptar');
            // Smart-fill (R32): ofrece de golpe los cupos libres al inicio de la lista de espera.
            Route::post('/sesiones/{sesion}/promover', [ReservasTenantController::class, 'promover'])->middleware('puede:reservas.gestionar')->name('sesiones.promover');
            // Transferir/regalar el lugar a otra persona (R9).
            Route::post('/reservas/{reserva}/transferir', [ReservasTenantController::class, 'transferir'])->middleware('puede:reservas.gestionar')->name('reservas.transferir');
            Route::post('/reservas/{reserva}/asistencia', [AsistenciaTenantController::class, 'marcar'])->middleware('puede:asistencia.marcar')->name('reservas.asistencia.store');

            // Politica de cancelacion/no-show (R8): la reserva congela la vigente al
            // crearse; esto configura la global y overrides por actividad a futuro.
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
            // Suscripciones recurrentes: próximas renovaciones que cobrará el scheduler (Etapa 2).
            Route::get('/suscripciones', [SuscripcionesTenantController::class, 'index'])->middleware('puede:facturacion.ver')->name('suscripciones.index');
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

    // Acceso por subdominio: {slug}.turnouno.com/api/v1/... (mismo comportamiento).
    Route::domain('{estudio}.'.config('turnouno.dominio_base'))
        ->middleware('estudio.resolver')
        ->name('api.v1.sub.')
        ->group($rutasTenant);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/token', [TokenController::class, 'destroy'])->name('api.v1.auth.token.destroy');
        Route::post('/auth/logout', [SessionController::class, 'destroy'])->name('api.v1.auth.logout');

        Route::middleware('tenant.resolve')->group(function (): void {
            Route::get('/me', MeController::class)->name('api.v1.me');

            Route::middleware('tenant.require')->group(function (): void {
                Route::get('/usuarios', [UsuarioController::class, 'index'])->name('api.v1.usuarios.index');

                Route::get('/personas', [PersonaController::class, 'index'])->name('api.v1.personas.index');
                Route::post('/personas', [PersonaController::class, 'store'])->name('api.v1.personas.store');
                Route::get('/personas/{persona}', [PersonaController::class, 'show'])->name('api.v1.personas.show');
                Route::post('/personas/{persona}/perfiles', [PerfilPersonaController::class, 'store'])->name('api.v1.personas.perfiles.store');

                // Catálogo: Programa → Actividad → (Nivel, Oferta).
                Route::get('/programas', [ProgramaController::class, 'index'])->name('api.v1.programas.index');
                Route::post('/programas', [ProgramaController::class, 'store'])->name('api.v1.programas.store');
                Route::get('/programas/{programa}', [ProgramaController::class, 'show'])->name('api.v1.programas.show');
                Route::post('/programas/{programa}/actividades', [ActividadController::class, 'store'])->name('api.v1.programas.actividades.store');
                Route::get('/actividades', [ActividadController::class, 'index'])->name('api.v1.actividades.index');
                Route::get('/actividades/{actividad}', [ActividadController::class, 'show'])->name('api.v1.actividades.show');
                Route::post('/actividades/{actividad}/niveles', [NivelController::class, 'store'])->name('api.v1.actividades.niveles.store');
                Route::get('/ofertas', [OfertaController::class, 'index'])->name('api.v1.ofertas.index');
                Route::post('/actividades/{actividad}/ofertas', [OfertaController::class, 'store'])->name('api.v1.actividades.ofertas.store');

                // Membresías: producto comercial → acuerdo → derecho (+ ledger).
                Route::get('/productos', [ProductoComercialController::class, 'index'])->name('api.v1.productos.index');
                Route::post('/productos', [ProductoComercialController::class, 'store'])->name('api.v1.productos.store');
                Route::post('/personas/{persona}/acuerdos', [AcuerdoController::class, 'store'])->name('api.v1.personas.acuerdos.store');
                Route::get('/personas/{persona}/derechos', [DerechoController::class, 'index'])->name('api.v1.personas.derechos.index');
                Route::post('/derechos/{derecho}/topups', [TopUpController::class, 'store'])->name('api.v1.derechos.topups.store');

                // Órdenes y pagos: orden pendiente → cobro (pasarela) → fulfillment (derechos).
                Route::get('/ordenes', [OrdenController::class, 'index'])->name('api.v1.ordenes.index');
                Route::post('/ordenes', [OrdenController::class, 'store'])->name('api.v1.ordenes.store');
                Route::get('/ordenes/{orden}', [OrdenController::class, 'show'])->name('api.v1.ordenes.show');
                Route::post('/ordenes/{orden}/pagos', [PagoController::class, 'store'])->name('api.v1.ordenes.pagos.store');
                Route::post('/pagos/{pago}/reembolso', [PagoController::class, 'reembolsar'])->name('api.v1.pagos.reembolso');

                // Ventanilla: el miembro sube comprobante; el staff aprueba/rechaza.
                Route::get('/pagos/ventanilla/pendientes', [VentanillaController::class, 'pendientes'])->name('api.v1.pagos.ventanilla.pendientes');
                Route::post('/pagos/{pago}/comprobante', [VentanillaController::class, 'subir'])->name('api.v1.pagos.comprobante.subir');
                Route::get('/pagos/{pago}/comprobante', [VentanillaController::class, 'ver'])->name('api.v1.pagos.comprobante.ver');
                Route::post('/pagos/{pago}/aprobar', [VentanillaController::class, 'aprobar'])->name('api.v1.pagos.aprobar');
                Route::post('/pagos/{pago}/rechazar', [VentanillaController::class, 'rechazar'])->name('api.v1.pagos.rechazar');

                // Configuración de pasarelas por tenant (encender/apagar + llaves).
                Route::get('/pasarelas', [ConfiguracionPasarelaController::class, 'index'])->name('api.v1.pasarelas.index');
                Route::get('/pasarelas/activas', [ConfiguracionPasarelaController::class, 'activas'])->name('api.v1.pasarelas.activas');
                Route::put('/pasarelas/{proveedor}', [ConfiguracionPasarelaController::class, 'upsert'])->name('api.v1.pasarelas.upsert');

                // Créditos: consumo y retenciones (holds) del ledger.
                Route::post('/derechos/{derecho}/consumos', [ConsumoController::class, 'store'])->name('api.v1.derechos.consumos.store');
                Route::post('/derechos/{derecho}/retenciones', [RetencionController::class, 'store'])->name('api.v1.derechos.retenciones.store');
                Route::post('/retenciones/{retencion}/confirmar', [RetencionController::class, 'confirmar'])->name('api.v1.retenciones.confirmar');
                Route::post('/retenciones/{retencion}/liberar', [RetencionController::class, 'liberar'])->name('api.v1.retenciones.liberar');
                Route::post('/retenciones/{retencion}/perder', [RetencionController::class, 'perder'])->name('api.v1.retenciones.perder');

                // Agenda: Oferta → Plantilla de horario → Sesión (materializada) + staff.
                Route::post('/ofertas/{oferta}/plantillas-horario', [PlantillaHorarioController::class, 'store'])->name('api.v1.ofertas.plantillas-horario.store');
                Route::post('/plantillas-horario/{plantilla}/sesiones', [PlantillaHorarioController::class, 'generar'])->name('api.v1.plantillas-horario.sesiones.generar');
                Route::get('/sucursales/{sucursal}/sesiones', [SesionController::class, 'index'])->name('api.v1.sucursales.sesiones.index');
                Route::post('/sucursales/{sucursal}/sesiones', [SesionController::class, 'store'])->name('api.v1.sucursales.sesiones.store');
                Route::post('/sesiones/{sesion}/cancelar', [SesionController::class, 'cancelar'])->name('api.v1.sesiones.cancelar');
                Route::post('/sesiones/{sesion}/asignaciones', [AsignacionSesionController::class, 'store'])->name('api.v1.sesiones.asignaciones.store');
                Route::get('/mis-sesiones', MiAgendaController::class)->name('api.v1.mis-sesiones');

                // Reservas (booking): motor transaccional sobre una sesión.
                Route::get('/sesiones/{sesion}/reservas', [ReservaController::class, 'index'])->name('api.v1.sesiones.reservas.index');
                Route::post('/sesiones/{sesion}/reservas', [ReservaController::class, 'store'])->name('api.v1.sesiones.reservas.store');
                Route::post('/reservas/{reserva}/cancelar', [ReservaController::class, 'cancelar'])->name('api.v1.reservas.cancelar');

                // Portal del miembro (self-service): opera sobre la persona del usuario.
                Route::get('/mi/perfil', [PortalPerfilController::class, 'show'])->name('api.v1.mi.perfil');
                Route::get('/mi/agenda', [PortalAgendaController::class, 'index'])->name('api.v1.mi.agenda');
                Route::post('/mi/reservas', [PortalAgendaController::class, 'reservar'])->name('api.v1.mi.reservas.store');
                Route::post('/mi/reservas/{reserva}/cancelar', [PortalAgendaController::class, 'cancelar'])->name('api.v1.mi.reservas.cancelar');
                Route::get('/mi/productos', [PortalCompraController::class, 'productos'])->name('api.v1.mi.productos');
                Route::get('/mi/ordenes', [PortalCompraController::class, 'ordenes'])->name('api.v1.mi.ordenes.index');
                Route::post('/mi/ordenes', [PortalCompraController::class, 'crearOrden'])->name('api.v1.mi.ordenes.store');
                Route::post('/mi/ordenes/{orden}/pagos', [PortalCompraController::class, 'pagar'])->name('api.v1.mi.ordenes.pagos.store');
                Route::get('/mi/pasarelas', [PasarelaPublicaController::class, 'index'])->name('api.v1.mi.pasarelas');
                Route::post('/reservas/{reserva}/asistencia', [AsistenciaController::class, 'store'])->name('api.v1.reservas.asistencia.store');

                // Recursos: Sucursal → Instalación → Recurso (jerárquico).
                Route::get('/sucursales/{sucursal}/instalaciones', [InstalacionController::class, 'index'])->name('api.v1.sucursales.instalaciones.index');
                Route::post('/sucursales/{sucursal}/instalaciones', [InstalacionController::class, 'store'])->name('api.v1.sucursales.instalaciones.store');
                Route::get('/instalaciones/{instalacion}', [InstalacionController::class, 'show'])->name('api.v1.instalaciones.show');
                Route::post('/instalaciones/{instalacion}/recursos', [RecursoController::class, 'store'])->name('api.v1.instalaciones.recursos.store');

                Route::get('/organizaciones', [OrganizacionController::class, 'index'])->name('api.v1.organizaciones.index');
                Route::post('/organizaciones', [OrganizacionController::class, 'store'])->name('api.v1.organizaciones.store');

                Route::get('/sucursales', [SucursalController::class, 'index'])->name('api.v1.sucursales.index');
                Route::post('/sucursales', [SucursalController::class, 'store'])->name('api.v1.sucursales.store');
                Route::get('/sucursales/{sucursal}', [SucursalController::class, 'show'])->name('api.v1.sucursales.show');

                Route::get('/sucursales/{sucursal}/personal', [PersonalSucursalController::class, 'index'])->name('api.v1.sucursales.personal.index');
                Route::post('/sucursales/{sucursal}/personal', [PersonalSucursalController::class, 'store'])->name('api.v1.sucursales.personal.store');
            });
        });
    });
});
