<?php

declare(strict_types=1);

// Mensajes de validación en español (es-MX). Las claves no traducidas caen al
// idioma de respaldo (en) definido en APP_FALLBACK_LOCALE.
return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'string' => 'El campo :attribute debe ser texto.',
    'max' => [
        'string' => 'El campo :attribute no debe ser mayor que :max caracteres.',
    ],
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'unique' => 'El campo :attribute ya está en uso.',
    'exists' => 'El :attribute seleccionado no es válido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',

    'attributes' => [
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'nombre' => 'nombre',
    ],
];
