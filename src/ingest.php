<?php

declare(strict_types=1);

/**
 * @return array<mixed>
 */
function loadReport(string $inputPath): array
{
    // Vérifier les chemins du fichier input (rapport.json)
    if (!is_file($inputPath)) {
        throw new RuntimeException(sprintf('Input file not found: %s', $inputPath));
    }

    if (!is_readable($inputPath)) {
        throw new RuntimeException(sprintf('Input file is not readable: %s', $inputPath));
    }

    // vérifier le fichier
    $content = file_get_contents($inputPath);

    if ($content === false) {
        throw new RuntimeException(sprintf('Unable to read input file: %s', $inputPath));
    }

    // décoder le fichier pour l'envoyer au prochain nœud pour le traitement et l'analyse
    try {
        $report = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            sprintf('Invalid JSON in input file: %s', $exception->getMessage()),
            previous: $exception,
        );
    }

    if (!is_array($report) || !array_is_list($report)) {
        throw new RuntimeException('The input JSON must contain a list of report entries.');
    }

    if ($report === []) {
        throw new RuntimeException('The input JSON cannot be empty.');
    }

    $numericFields = [
        'cpu_usage',
        'memory_usage',
        'latency_ms',
        'disk_usage',
        'network_in_kbps',
        'network_out_kbps',
        'io_wait',
        'thread_count',
        'active_connections',
        'error_rate',
        'uptime_seconds',
        'temperature_celsius',
        'power_consumption_watts',
    ];

    $allowedServiceStatuses = [
        'online',
        'degraded',
        'offline',
    ];

    foreach ($report as $index => $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException(
                sprintf('Report entry %d must be an object.', $index),
            );
        }

        if (
            !isset($entry['timestamp'])
            || !is_string($entry['timestamp'])
            || DateTimeImmutable::createFromFormat(
                DateTimeInterface::ATOM,
                $entry['timestamp'],
            ) === false
        ) {
            throw new RuntimeException(
                sprintf('Invalid timestamp in report entry %d.', $index),
            );
        }

        foreach ($numericFields as $field) {
            if (
                !isset($entry[$field])
                || (!is_int($entry[$field]) && !is_float($entry[$field]))
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Missing or invalid field "%s" in report entry %d.',
                        $field,
                        $index,
                    ),
                );
            }
        }

        if (
            !isset($entry['service_status'])
            || !is_array($entry['service_status'])
        ) {
            throw new RuntimeException(
                sprintf(
                    'Missing or invalid service_status in report entry %d.',
                    $index,
                ),
            );
        }

        foreach ($entry['service_status'] as $service => $status) {
            if (!in_array($status, $allowedServiceStatuses, true)) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid status "%s" for service "%s" in report entry %d.',
                        $status,
                        $service,
                        $index,
                    ),
                );
            }
        }
    }

    return $report;
}
