import 'package:flutter/material.dart';

/// Lo que se muestra cuando una pantalla no pudo cargar: qué falló y un botón
/// «Reintentar» (el mismo patrón en todas, en lugar de un enlace con todo el texto).
class ErrorDeCarga extends StatelessWidget {
  const ErrorDeCarga({
    super.key,
    required this.mensaje,
    required this.onReintentar,
  });

  final String mensaje;
  final VoidCallback onReintentar;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(mensaje, textAlign: TextAlign.center),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: onReintentar,
            child: const Text('Reintentar'),
          ),
        ],
      ),
    ),
  );
}
