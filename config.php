<?php

declare(strict_types=1);

return [
    'paths' => [
        'input' => __DIR__ . '/rapport.json',
        'output' => __DIR__ . '/output.json',
    ],
    'openai' => [
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
        'model' => $_ENV['OPENAI_MODEL'] ?? '',
        'endpoint' => 'https://api.openai.com/v1/responses',
    ],
    'thresholds' => [
        'cpu_usage' => [
            'low' => 80,
            'medium' => 90,
            'high' => 95,
        ],
        'memory_usage' => [
            'low' => 75,
            'medium' => 85,
            'high' => 90,
        ],
        'latency_ms' => [
            'low' => 200,
            'medium' => 300,
            'high' => 350,
        ],
        'disk_usage' => [
            'low' => 80,
            'medium' => 90,
            'high' => 95,
        ],
        'network_in_kbps' => [
            'low' => 1800,
            'medium' => 2300,
            'high' => 2700,
        ],
        'network_out_kbps' => [
            'low' => 1700,
            'medium' => 2000,
            'high' => 2200,
        ],
        'io_wait' => [
            'low' => 5,
            'medium' => 10,
            'high' => 12,
        ],
        'thread_count' => [
            'low' => 160,
            'medium' => 175,
            'high' => 185,
        ],
        'active_connections' => [
            'low' => 75,
            'medium' => 110,
            'high' => 130,
        ],
        'error_rate' => [
            'low' => 0.04,
            'medium' => 0.07,
            'high' => 0.10,
        ],
        'temperature_celsius' => [
            'low' => 75,
            'medium' => 80,
            'high' => 85,
        ],
        'power_consumption_watts' => [
            'low' => 300,
            'medium' => 350,
            'high' => 375,
        ],
    ],
];

