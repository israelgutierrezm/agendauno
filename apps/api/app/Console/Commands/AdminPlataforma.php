<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Da de alta (o actualiza) a un operador de la plataforma: entra a /plataforma con su
 * correo, su contraseña, el token de la plataforma y un código por correo (ADR 0126).
 * Sin `--password` la pide sin mostrarla; sin consola, genera una y la muestra una vez.
 */
class AdminPlataforma extends Command
{
    protected $signature = 'agendauno:admin-plataforma
        {email : Correo del operador (ahí le llega el código)}
        {--nombre= : Nombre para mostrar}
        {--password= : Contraseña (si no, se pide)}
        {--desactivar : Quita su acceso sin borrarlo}';

    protected $description = 'Da de alta o actualiza a un operador del superadmin (/plataforma)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Ese correo no es válido.');

            return self::FAILURE;
        }

        $administrador = AdministradorPlataforma::query()->whereRaw('lower(email) = ?', [$email])->first()
            ?? new AdministradorPlataforma(['email' => $email]);

        if ($this->option('desactivar')) {
            if (! $administrador->exists) {
                $this->error('No hay un operador con ese correo.');

                return self::FAILURE;
            }
            $administrador->update(['activo' => false]);
            $this->info("{$email} ya no puede entrar a la plataforma.");

            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?? '');
        $generada = false;
        if ($password === '' && ! $administrador->exists) {
            $password = $this->input->isInteractive() ? (string) $this->secret('Contraseña (12 caracteres o más)') : '';
            if ($password === '') {
                $password = Str::password(20);
                $generada = true;
            }
        }
        if ($password !== '' && mb_strlen($password) < 12 && ! $this->getLaravel()->environment('local', 'testing')) {
            $this->error('La contraseña debe tener 12 caracteres o más.');

            return self::FAILURE;
        }

        $administrador->fill([
            'nombre' => (string) ($this->option('nombre') ?: ($administrador->nombre ?: Str::before($email, '@'))),
            'activo' => true,
        ]);
        if ($password !== '') {
            $administrador->password = $password;
        }
        $administrador->save();

        $this->info("{$email} puede entrar a /plataforma (correo, contraseña, token y código por correo).");
        if ($generada) {
            $this->warn("Contraseña generada (guárdala ahora; no se vuelve a mostrar): {$password}");
        }

        return self::SUCCESS;
    }
}
