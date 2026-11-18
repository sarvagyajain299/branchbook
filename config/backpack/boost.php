<?php

declare(strict_types=1);

use Backpack\CRUD\app\Mcp\Tools\SearchBackpackDocs;

return [
    'mcp' => [
        'tools' => [
            'include' => [
                SearchBackpackDocs::class,
            ],
        ],
    ],
];
