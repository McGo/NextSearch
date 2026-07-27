<?php

return [
    'auth' => [
        'invalid_credentials' => 'E-Mail-Adresse oder Passwort stimmen nicht.',
        'too_many_attempts' => 'Zu viele Versuche. Bitte :seconds Sekunden warten.',
        'logged_out' => 'Abgemeldet.',
        'password_changed' => 'Passwort geändert.',
        'password_current_required' => 'Bitte das aktuelle Passwort eingeben.',
        'password_current_wrong' => 'Das aktuelle Passwort stimmt nicht.',
        'password_required' => 'Bitte ein neues Passwort eingeben.',
        'password_min' => 'Das neue Passwort braucht mindestens :min Zeichen.',
        'password_confirmed' => 'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.',
        'password_different' => 'Das neue Passwort muss sich vom aktuellen unterscheiden.',
    ],
    'admin_only' => 'Dieser Bereich ist Administratoren vorbehalten.',
    'nextcloud' => [
        'unauthorized' => 'Anmeldung abgelehnt. Benutzername oder App-Passwort stimmen nicht.',
        'not_found' => 'Pfad ":path" existiert auf der Instanz nicht.',
        'unreachable' => 'Instanz unter :url nicht erreichbar: :message',
        'unexpected_status' => 'Unerwartete Antwort :status für ":path".',
        'write_blocked' => 'NextSearch greift ausschließlich lesend auf Nextcloud zu. Die Methode :method wurde blockiert.',
    ],
    'connection' => [
        'ok' => 'Verbindung steht. :count Ordner im Wurzelverzeichnis von ":user" gefunden.',
    ],
    'document' => [
        'no_inapp_view' => 'Für dieses Format gibt es keine In-App-Ansicht.',
        'too_large' => 'Die Datei ist für die Vorschau zu groß.',
        'no_preview' => 'Für dieses Dokument gibt es kein Vorschaubild.',
        'no_access' => 'Für diesen Ordner besteht keine Freigabe.',
        'gone' => 'Dieses Dokument ist nicht mehr verfügbar.',
    ],
    'instance' => [
        'removed' => 'Instanz entfernt.',
    ],
    'twofactor' => [
        'already_enabled' => 'Zwei-Faktor ist bereits aktiv.',
        'not_pending' => 'Starte zuerst die Einrichtung.',
        'invalid_code' => 'Der Code stimmt nicht.',
        'enabled' => 'Zwei-Faktor-Authentifizierung aktiviert.',
        'disabled' => 'Zwei-Faktor-Authentifizierung deaktiviert.',
        'no_challenge' => 'Diese Anmeldung ist abgelaufen. Bitte neu einloggen.',
    ],
    'index' => [
        'cleared' => 'Suchindex geleert.',
        'rebuilding' => 'Index wird neu aufgebaut — :count Ordner eingereiht.',
    ],
    'folder' => [
        'not_a_directory' => 'Der angegebene Pfad ist kein Ordner.',
        'removed' => 'Ordner entfernt.',
        'run_started' => 'Durchlauf gestartet.',
        'run_already' => 'Für diesen Ordner läuft bereits ein Durchlauf.',
    ],
    'user' => [
        'cannot_delete_self' => 'Das eigene Konto lässt sich nicht löschen.',
        'last_admin' => 'Es muss mindestens ein Administrator übrig bleiben.',
        'removed' => 'Konto entfernt.',
    ],
];
