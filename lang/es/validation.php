<?php

declare(strict_types=1);

/*
 * Mensajes de validación en español.
 *
 * Laravel solo trae los mensajes en inglés dentro del framework, así que sin
 * este archivo la interfaz en español mostraba errores como "The iD field is
 * required." (detectado en el primer despliegue real, 2026-07-27, con el panel
 * puesto en español). Cubre las 136 claves del validation.php de Laravel 12
 * para que no quede ninguna cayendo al inglés.
 *
 * El archivo equivalente en inglés NO hace falta: para el locale `en` Laravel
 * usa el que trae el framework.
 */

return [

    'accepted' => 'Debe aceptar :attribute.',
    'accepted_if' => 'Debe aceptar :attribute cuando :other sea :value.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute solo puede contener letras.',
    'alpha_dash' => ':attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo puede contener letras y números.',
    'any_of' => ':attribute no es válido.',
    'array' => ':attribute debe ser un conjunto de valores.',
    'ascii' => ':attribute solo puede contener caracteres y símbolos alfanuméricos de un byte.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute debe ser verdadero o falso.',
    'can' => ':attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'A :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'declined' => ':attribute debe ser rechazado.',
    'declined_if' => ':attribute debe ser rechazado cuando :other sea :value.',
    'different' => ':attribute y :other deben ser diferentes.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene dimensiones de imagen no válidas.',
    'distinct' => ':attribute tiene un valor duplicado.',
    'doesnt_contain' => ':attribute no puede contener ninguno de los valores indicados.',
    'doesnt_end_with' => ':attribute no puede terminar con ninguno de estos valores: :values.',
    'doesnt_start_with' => ':attribute no puede comenzar con ninguno de estos valores: :values.',
    'email' => ':attribute no es un correo electrónico válido.',
    'encoding' => ':attribute debe usar la codificación :encoding.',
    'ends_with' => ':attribute debe terminar con uno de estos valores: :values.',
    'enum' => 'El valor seleccionado en :attribute no es válido.',
    'exists' => 'El valor seleccionado en :attribute no existe.',
    'extensions' => ':attribute debe tener una de estas extensiones: :values.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => ':attribute debe tener un valor.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => ':attribute debe ser mayor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => ':attribute debe ser un color hexadecimal válido.',
    'image' => ':attribute debe ser una imagen.',
    'in' => 'El valor seleccionado en :attribute no es válido.',
    'in_array' => ':attribute no existe en :other.',
    'in_array_keys' => ':attribute debe incluir al menos una de estas claves: :values.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',
    'list' => ':attribute debe ser una lista.',
    'lowercase' => ':attribute debe estar en minúsculas.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no debe tener más de :value elementos.',
        'file' => ':attribute debe pesar :value kilobytes o menos.',
        'numeric' => ':attribute debe ser menor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => ':attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => ':attribute no debe tener más de :max elementos.',
        'file' => ':attribute no debe pesar más de :max kilobytes.',
        'numeric' => ':attribute no debe ser mayor que :max.',
        'string' => ':attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => ':attribute no debe tener más de :max dígitos.',
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => ':attribute debe tener al menos :min dígitos.',
    'missing' => ':attribute debe estar ausente.',
    'missing_if' => ':attribute debe estar ausente cuando :other sea :value.',
    'missing_unless' => ':attribute debe estar ausente a menos que :other sea :value.',
    'missing_with' => ':attribute debe estar ausente cuando :values esté presente.',
    'missing_with_all' => ':attribute debe estar ausente cuando :values estén presentes.',
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => 'El valor seleccionado en :attribute no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'password' => [
        'letters' => ':attribute debe contener al menos una letra.',
        'mixed' => ':attribute debe contener al menos una mayúscula y una minúscula.',
        'numbers' => ':attribute debe contener al menos un número.',
        'symbols' => ':attribute debe contener al menos un símbolo.',
        'uncompromised' => 'La :attribute indicada apareció en una filtración de datos. Elija otra.',
    ],
    'present' => ':attribute debe estar presente.',
    'present_if' => ':attribute debe estar presente cuando :other sea :value.',
    'present_unless' => ':attribute debe estar presente a menos que :other sea :value.',
    'present_with' => ':attribute debe estar presente cuando :values esté presente.',
    'present_with_all' => ':attribute debe estar presente cuando :values estén presentes.',
    'prohibited' => ':attribute está prohibido.',
    'prohibited_if' => ':attribute está prohibido cuando :other sea :value.',
    'prohibited_if_accepted' => ':attribute está prohibido cuando se acepta :other.',
    'prohibited_if_declined' => ':attribute está prohibido cuando se rechaza :other.',
    'prohibited_unless' => ':attribute está prohibido a menos que :other sea :values.',
    'prohibits' => ':attribute impide que :other esté presente.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => ':attribute debe contener entradas para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other sea :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => 'El campo :attribute es obligatorio a menos que :other esté en :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando :values están presentes.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe contener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe comenzar con uno de estos valores: :values.',
    'string' => ':attribute debe ser una cadena de texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => ':attribute debe estar en mayúsculas.',
    'url' => ':attribute debe ser una URL válida.',
    'ulid' => ':attribute debe ser un ULID válido.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
     * Mensajes por campo. Filament pasa la etiqueta del campo como
     * :attribute, así que acá solo hacen falta los casos que no cubre.
     */
    'custom' => [
        'attribute-name' => [
            'rule-name' => 'mensaje personalizado',
        ],
    ],

    'attributes' => [],

];
