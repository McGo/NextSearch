<?php

return [
    'auth' => [
        'invalid_credentials' => 'Email address or password is incorrect.',
        'too_many_attempts' => 'Too many attempts. Please wait :seconds seconds.',
        'logged_out' => 'Signed out.',
        'password_changed' => 'Password changed.',
        'password_current_required' => 'Please enter your current password.',
        'password_current_wrong' => 'Your current password is incorrect.',
        'password_required' => 'Please enter a new password.',
        'password_min' => 'The new password needs at least :min characters.',
        'password_confirmed' => 'The repetition does not match the new password.',
        'password_different' => 'The new password must differ from the current one.',
    ],
    'admin_only' => 'This area is reserved for administrators.',
    'nextcloud' => [
        'unauthorized' => 'Sign-in refused. Username or app password is wrong.',
        'not_found' => 'Path ":path" does not exist on the instance.',
        'unreachable' => 'Instance at :url is unreachable: :message',
        'unexpected_status' => 'Unexpected response :status for ":path".',
        'write_blocked' => 'NextSearch accesses Nextcloud read-only. The :method method was blocked.',
    ],
    'connection' => [
        'ok' => 'Connection works. Found :count folders in the root of ":user".',
    ],
    'document' => [
        'no_inapp_view' => 'There is no in-app view for this format.',
        'too_large' => 'The file is too large for a preview.',
        'no_preview' => 'There is no preview image for this document.',
        'no_access' => 'This folder is not shared with you.',
        'gone' => 'This document is no longer available.',
    ],
    'instance' => [
        'removed' => 'Instance removed.',
    ],
    'index' => [
        'cleared' => 'Search index cleared.',
        'rebuilding' => 'Rebuilding the index — :count folder(s) queued.',
    ],
    'folder' => [
        'not_a_directory' => 'The given path is not a folder.',
        'removed' => 'Folder removed.',
        'run_started' => 'Run started.',
        'run_already' => 'A run is already in progress for this folder.',
    ],
    'user' => [
        'cannot_delete_self' => 'You cannot delete your own account.',
        'last_admin' => 'At least one administrator must remain.',
        'removed' => 'Account removed.',
    ],
];
