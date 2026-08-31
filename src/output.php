<?php

declare(strict_types=1);

/**
 * @param array<mixed> $analysis
 * @param array<mixed> $llmContent
 */
function writeReport(string $outputPath, array $analysis, array $llmContent): void
{
    /**
     * Vérifier le contenu du LLM + les insights
     */
    if (
        !isset(
            $analysis['insights'],
            $analysis['anomalies'],
            $analysis['service_status_summary'],
            $llmContent['descriptions'],
            $llmContent['recommendations'],
        )
        || !is_array($analysis['insights'])
        || !is_array($analysis['anomalies'])
        || !is_array($analysis['service_status_summary'])
        || !is_array($llmContent['descriptions'])
        || !is_array($llmContent['recommendations'])
    ) {
        throw new InvalidArgumentException(
            'The report data is incomplete.',
        );
    }

    /**
     * Alimenter les anomalies avec descriptions plus pertinentes renvoyées par le LLM
     */
    $descriptionsByMetric = [];

    foreach ($llmContent['descriptions'] as $description) {
        if (
            !is_array($description)
            || !isset(
                $description['metric'],
                $description['description'],
            )
            || !is_string($description['metric'])
            || !is_string($description['description'])
        ) {
            throw new RuntimeException(
                'An anomaly description is invalid.',
            );
        }

        $descriptionsByMetric[$description['metric']] = $description['description'];
    }

    $anomalies = [];

    foreach ($analysis['anomalies'] as $anomaly) {
        if (
            !is_array($anomaly)
            || !isset(
                $anomaly['metric'],
                $anomaly['value'],
                $anomaly['threshold'],
                $anomaly['severity'],
            )
            || !is_string($anomaly['metric'])
        ) {
            throw new RuntimeException('An analyzed anomaly is invalid.');
        }

        $metric = $anomaly['metric'];

        if (!isset($descriptionsByMetric[$metric])) {
            throw new RuntimeException(
                sprintf('Missing description for metric: %s', $metric),
            );
        }

        $anomalies[] = [
            'metric' => $metric,
            'value' => $anomaly['value'],
            'threshold' => $anomaly['threshold'],
            'severity' => $anomaly['severity'],
            'description' => $descriptionsByMetric[$metric],
        ];
    }


    /**
     * Construire l'objet final
     */
    $report = [
        'timestamp' => (new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        ))->format('Y-m-d\TH:i:s\Z'),
        'insights' => $analysis['insights'],
        'anomalies' => $anomalies,
        'recommendations' => $llmContent['recommendations'],
        'service_status_summary' => $analysis['service_status_summary'],
    ];

    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    /**
     * Générer le fichier JSON final (output.json)
     */
    if (file_put_contents($outputPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException(
            sprintf('Unable to write output file: %s', $outputPath),
        );
    }
}
