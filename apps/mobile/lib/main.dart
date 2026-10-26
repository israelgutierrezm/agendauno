import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'features/auth/application/sesion_controller.dart';
import 'core/config/app_config.dart';
import 'core/enlaces/regreso_pago.dart';
import 'core/errores/reporte_errores.dart';
import 'core/network/auth_token.dart';
import 'core/storage/almacen_sesion.dart';
import 'core/theme/tema_agendauno.dart';
import 'features/auth/data/sesion.dart';
import 'features/auth/presentation/actualizar_app_screen.dart';
import 'features/auth/presentation/elegir_rol_screen.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/auth/presentation/negocio_suspendido_screen.dart';
import 'features/cuenta/application/cuenta_controller.dart';
import 'features/cuenta/presentation/cuenta_screen.dart';
import 'features/inicio/presentation/equipo_screen.dart';
import 'features/instructor/presentation/instructor_screen.dart';
import 'features/notificaciones/application/aviso_abierto.dart';
import 'features/notificaciones/application/push_controller.dart';
import 'features/widget_inicio/application/widget_inicio.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Los errores que nadie atrapa llegan al monitoreo de la plataforma (ADR 0080).
  ReporteErrores.instancia.instalar();

  // Restaura la sesión guardada (cifrada) para no pedir iniciar sesión cada vez.
  final almacen = AlmacenSesion();
  final guardada = await almacen.leer();
  final sesion = guardada != null ? Sesion.desdeAlmacen(guardada) : null;

  runApp(
    ProviderScope(
      overrides: [
        almacenSesionProvider.overrideWithValue(almacen),
        tokenInicialProvider.overrideWithValue(sesion?.bearer),
        sesionInicialProvider.overrideWithValue(sesion),
      ],
      child: const AgendaUnoApp(),
    ),
  );
}

/// El color del tema: en una app de marca blanca, el de su negocio (el que manda el
/// servidor en la sesión o, antes de entrar, `COLOR_MARCA` de su archivo); en las
/// apps oficiales, ninguno (el azul de siempre).
Color? colorDeLaApp(
  Sesion? sesion, {
  bool marcaBlanca = AppConfig.marcaBlanca,
  String porOmision = AppConfig.colorMarca,
}) => marcaBlanca
    ? ColoresAgendaUno.colorDe(sesion?.colorMarca) ??
          ColoresAgendaUno.colorDe(porOmision)
    : null;

class AgendaUnoApp extends ConsumerStatefulWidget {
  const AgendaUnoApp({super.key});

  /// El idioma de la app.
  static const idioma = Locale('es');

  @override
  ConsumerState<AgendaUnoApp> createState() => _AgendaUnoAppState();
}

class _AgendaUnoAppState extends ConsumerState<AgendaUnoApp> {
  // Para mostrar las notificaciones push que llegan con la app abierta.
  final _avisos = GlobalKey<ScaffoldMessengerState>();
  // Para volver a la primera pantalla cuando la sesión termina.
  final _navegador = GlobalKey<NavigatorState>();
  // Al volver a primer plano se confirma que la sesión sigue viva (pudo cerrarse
  // en la web u otro teléfono mientras la app estaba en segundo plano).
  // Y se vuelve a registrar el teléfono para los avisos: pudo activarlos en Ajustes
  // (o en iPhone, llegar el token de APNs) mientras tanto.
  late final AppLifecycleListener _ciclo = AppLifecycleListener(
    onResume: () {
      ref.read(sesionProvider.notifier).revisar();
      final sesion = ref.read(sesionProvider);
      if (sesion != null) {
        ref.read(pushProvider).registrar(sesion);
      }
    },
  );

  // El regreso del pago en el navegador (`<esquema>://pagos?ref=…`): se atiende
  // antes que el Navigator, que no tiene esa ruta.
  late final EnlacesDeLaApp _enlaces = EnlacesDeLaApp(_alRegresarDePago);

  @override
  void initState() {
    super.initState();
    _ciclo;
    WidgetsBinding.instance.addObserver(_enlaces);
    // Si la app estaba cerrada y la abrió ese enlace.
    final alAbrir = EnlacesDeLaApp.alAbrir();
    if (alAbrir != null) {
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => _alRegresarDePago(alAbrir),
      );
    }
    // Una sesión restaurada se valida con el servidor (token revocado → login).
    ref.read(sesionProvider.notifier).refrescar();
    ReporteErrores.instancia.estudio = () => ref.read(sesionProvider)?.slug;
    _iniciarPush();
    // Sin sesión de cliente al abrir (p. ej. se cerró en otro lado), el widget de
    // inicio no se queda con lo de antes.
    if (!(ref.read(sesionProvider)?.esMiembro ?? false)) {
      ref.read(widgetInicioProvider).limpiar();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(_enlaces);
    _ciclo.dispose();
    super.dispose();
  }

  /// Volvió del pago: el cliente ve sus pagos, ya leídos de nuevo (el webhook pudo
  /// confirmar el pago mientras tanto), y lo que pasó.
  void _alRegresarDePago(RegresoDePago regreso) {
    final sesion = ref.read(sesionProvider);
    if (sesion == null || !sesion.esMiembro) {
      return;
    }
    _navegador.currentState?.popUntil((ruta) => ruta.isFirst);
    ref.invalidate(cuentaProvider);
    ref.read(avisoAbiertoProvider.notifier).irA(DestinoPush.pagos);
    _mostrarAviso(regreso.mensaje, '');
  }

  /// Sin sesión (o con el negocio suspendido) no queda ninguna pantalla encima del
  /// login o del aviso (detalle, hojas, diálogos). Si la terminó el servidor, el login ya lo dice: se quitan los
  /// «No autenticado.» que dejaron las peticiones que fallaron.
  void _alSalir() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _navegador.currentState?.popUntil((ruta) => ruta.isFirst);
      if (ref.read(sesionTerminadaProvider) != null) {
        _avisos.currentState?.clearSnackBars();
      }
    });
  }

  Future<void> _iniciarPush() async {
    final push = ref.read(pushProvider);
    await push.iniciar(
      _mostrarAviso,
      abrir: (datos) => ref.read(avisoAbiertoProvider.notifier).abrir(datos),
      // Un aviso con la app abierta (una reserva que cambió, un pago): la cuenta
      // del cliente se lee de nuevo, y con ella su widget de inicio.
      recibido: (_) {
        if (ref.read(sesionProvider)?.esMiembro ?? false) {
          ref.invalidate(cuentaProvider);
        }
      },
    );
    final sesion = ref.read(sesionProvider);
    if (sesion != null) {
      await push.registrar(sesion);
    }
  }

  void _mostrarAviso(String titulo, String cuerpo) {
    _avisos.currentState?.showSnackBar(
      SnackBar(
        content: Text([titulo, cuerpo].where((t) => t.isNotEmpty).join('\n')),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Sin login global: con sesion tenant-local, el cliente ve su cuenta, quien
    // solo imparte su portal (sus clases) y el resto del personal la agenda; sin
    // sesion, el acceso por negocio.
    final sesion = ref.watch(sesionProvider);
    // Al iniciar sesión (o cambiar de negocio), este teléfono recibe sus push.
    ref.listen(sesionProvider, (anterior, nueva) {
      if (nueva != null && anterior?.bearer != nueva.bearer) {
        ref.read(pushProvider).registrar(nueva);
        // Recién entró: /yo dice qué versión mínima de la app acepta el servidor.
        ref.read(sesionProvider.notifier).revisar();
      }
      if (nueva == null && anterior != null) {
        _alSalir();
      }
      // Ya no es la cuenta de un cliente (salió, cambió de rol o de negocio): el
      // widget de inicio deja de mostrar lo suyo.
      if ((anterior?.esMiembro ?? false) &&
          (nueva == null ||
              !nueva.esMiembro ||
              nueva.slug != anterior?.slug ||
              nueva.bearer != anterior?.bearer)) {
        ref.read(widgetInicioProvider).limpiar();
      }
      // Se suspendió con la app abierta: no queda nada encima del aviso.
      if ((nueva?.negocioSuspendido ?? false) &&
          !(anterior?.negocioSuspendido ?? false)) {
        _alSalir();
      }
    });
    // Con varios roles, al entrar elige con cuál; luego, la pantalla de ese rol. Si
    // el servidor ya no acepta esta versión de la app, primero hay que actualizarla;
    // con el negocio suspendido por falta de pago, solo se dice eso.
    final Widget inicio = sesion == null
        ? const LoginScreen()
        : sesion.debeActualizar
        ? const ActualizarAppScreen()
        : sesion.negocioSuspendido
        ? const NegocioSuspendidoScreen()
        : sesion.eligiendoRol
        ? const ElegirRolScreen()
        : sesion.esMiembro
        ? const CuentaScreen()
        : sesion.esInstructorAcotado
        ? const InstructorScreen()
        : const EquipoScreen();

    return MaterialApp(
      // El nombre de esta app: AgendaUno, TurnoUno o el de la marca blanca.
      title: AppConfig.nombreApp,
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: _avisos,
      navigatorKey: _navegador,
      // Un solo diseño, el del tema AgendaUno de la web (los temas a elegir son solo
      // de la web), en claro y en oscuro: sigue el modo del teléfono. Una app de
      // marca blanca lleva el color de su negocio (ADR 0208).
      theme: TemaAgendaUno.claro(marca: colorDeLaApp(sesion)),
      darkTheme: TemaAgendaUno.oscuro(marca: colorDeLaApp(sesion)),
      themeMode: ThemeMode.system,
      // En español: los selectores de fecha y hora, sus botones y los textos del
      // sistema (sin esto salen en inglés).
      locale: AgendaUnoApp.idioma,
      supportedLocales: const [AgendaUnoApp.idioma],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      // Siempre la pantalla de la sesión: un enlace que abre la app (el regreso del
      // pago) lo atiende [EnlacesDeLaApp], no el Navigator.
      initialRoute: '/',
      home: inicio,
    );
  }
}
