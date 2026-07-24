<?php

/**
 * German validation messages — only the rules the app actually uses. English is
 * Laravel's built-in default and needs no file.
 */
return [
    'required' => 'Das Feld :attribute wird benötigt.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'string' => ':attribute muss Text sein.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'boolean' => ':attribute muss wahr oder falsch sein.',
    'array' => ':attribute muss eine Liste sein.',
    'url' => ':attribute muss eine gültige URL sein.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'different' => ':attribute und :other müssen sich unterscheiden.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'image' => ':attribute muss ein Bild sein.',
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'current_password' => 'Das Passwort stimmt nicht.',
    'present' => 'Das Feld :attribute muss vorhanden sein.',

    'min' => [
        'string' => ':attribute muss mindestens :min Zeichen haben.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'array' => ':attribute muss mindestens :min Einträge haben.',
    ],
    'max' => [
        'string' => ':attribute darf höchstens :max Zeichen haben.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'array' => ':attribute darf höchstens :max Einträge haben.',
    ],

    'attributes' => [
        'name' => 'Name',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'current_password' => 'aktuelles Passwort',
        'base_url' => 'Basis-URL',
        'username' => 'Benutzername',
        'app_password' => 'App-Passwort',
        'label' => 'Bezeichnung',
        'remote_path' => 'Pfad',
        'interval_minutes' => 'Intervall',
        'image' => 'Bild',
        'role' => 'Rolle',
        'folders' => 'Ordner',
        'q' => 'Suchbegriff',
    ],
];
