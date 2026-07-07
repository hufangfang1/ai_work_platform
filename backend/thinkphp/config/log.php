<?php

return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type' => 'File',
            'path' => '',
            'single' => false,
            'apart_level' => [],
            'max_files' => 0,
            'json' => false,
            'processor' => null,
            'close' => false,
        ],
    ],
];
