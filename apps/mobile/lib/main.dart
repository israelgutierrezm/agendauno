import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'features/auth/application/sesion_controller.dart';
import 'core/errores/reporte_errores.dart';
import 'core/network/auth_token.dart';
import 'core/storage/almacen_sesion.dart';
import 'core/theme/tema_agendauno.dart';
import 'features/auth/data/sesion.dart';
import 'features/auth/presentation/elegir_rol_screen.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/cuenta/presentation/cuenta_screen.dart';
import 'features/inicio/presentation/equipo_screen.dart';
import 'features/instructor/presentation/instructor_screen.dart';
import 'features/notificaciones/application/push_controller.dart';

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
  late final AppLifecycleListener _ciclo = AppLifecycleListener(
    onResume: () => ref.read(sesionProvider.notifier).revisar(),
  );

  @override
  void initState() {
    super.initState();
    _ciclo;
    // Una sesión restaurada se valida con el servidor (token revocado → login).
    ref.read(sesionProvider.notifier).refrescar();
    ReporteErrores.instancia.estudio = () => ref.read(sesionProvider)?.slug;
    _iniciarPush();
  }

  @override
  void dispose() {
    _ciclo.dispose();
    super.dispose();
  }

  /// Sin sesión no queda ninguna pantalla encima del login (detalle, hojas,
  /// diálogos). Si la terminó el servidor, el login ya lo dice: se quitan los
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
    await push.iniciar(_mostrarAviso);
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
      }
      if (nueva == null && anterior != null) {
        _alSalir();
      }
    });
    // Con varios roles, al entrar elige con cuál; luego, la pantalla de ese rol.
    final Widget inicio = sesion == null
        ? const LoginScreen()
        : sesion.eligiendoRol
        ? const ElegirRolScreen()
        : sesion.esMiembro
        ? const CuentaScreen()
        : sesion.esInstructorAcotado
        ? const InstructorScreen()
        : const EquipoScreen();

    return MaterialApp(
      title: 'AgendaUno',
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: _avisos,
      navigatorKey: _navegador,
      // El mismo tema claro de la web (tokens de AgendaUno).
      theme: TemaAgendaUno.claro(),
      // En español: los selectores de fecha y hora, sus botones y los textos del
      // sistema (sin esto salen en inglés).
      locale: AgendaUnoApp.idioma,
      supportedLocales: const [AgendaUnoApp.idioma],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: inicio,
    );
  }
}
