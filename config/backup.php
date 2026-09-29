<?php

return [
    // Pasta dos dumps do Postgres (a mesma montada no container "backup").
    'path' => env('BACKUP_PATH', './backups'),
];
