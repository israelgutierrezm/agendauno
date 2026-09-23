import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/health_repository.dart';

/// Minimal Sprint 0 screen: proves end-to-end connectivity to the API.
class HealthScreen extends ConsumerWidget {
  const HealthScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(healthProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('AgendaUno')),
      body: Center(
        child: health.when(
          loading: () => const CircularProgressIndicator(),
          error: (error, _) => Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              'API unreachable:\n$error',
              textAlign: TextAlign.center,
            ),
          ),
          data: (report) => Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'API status: ${report.status}',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              Text('${report.app} · ${report.environment} · v${report.version}'),
            ],
          ),
        ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => ref.invalidate(healthProvider),
        child: const Icon(Icons.refresh),
      ),
    );
  }
}
