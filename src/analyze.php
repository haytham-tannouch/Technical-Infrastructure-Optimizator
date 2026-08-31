<?php

declare(strict_types=1);

/**
 * @param array<mixed> $reports
 * @param array<mixed> $config
 *
 * @return array<mixed>
 */
function analyzeReport(array $reports, array $config): array
{

    $thresholds = $config['thresholds'];

    $latencySum = 0.0;
    $errorRateSum = 0.0;

    $maxCpuUsage = 0;
    $maxMemoryUsage = 0;
    $maxUptime = 0;

    // init compteurs et sommes
    $anomaliesByMetric = [];
    $servicesStatus = [];

    $severityLevel = [
        'low' => 1,
        'medium' => 2,
        'high' => 3
    ];

    $statusLevels = [
        'online' => 1,
        'degraded' => 2,
        'offline' => 3,
    ];

    foreach($reports as $report){
        /*
        * calculer les sommes et max
        */ 
        $latencySum += $report['latency_ms'];
        $errorRateSum += $report['error_rate'];
        
        $maxCpuUsage = max($maxCpuUsage, $report['cpu_usage']);
        $maxMemoryUsage = max($maxMemoryUsage, $report['memory_usage']);
        $maxUptime = max($maxUptime, $report['uptime_seconds']);

        /*
        * comparer les valeurs avec les thresholds et construire le tableau des anomalies
        */
        foreach($thresholds as $th => $levels) {
            // vérifier si les métriques sont valides
            if(!isset($report[$th]) || !is_numeric($report[$th])){
                throw new RuntimeException(sprintf('Missing or invalid metric: %s', $th));
            }

            $value = $report[$th];
            $severty = null;

            if($value >= $levels['high']) {
                $severty = 'high';
            }elseif($value >= $levels['medium']) {
                $severty = 'medium';
            }elseif($value >= $levels['low']) {
                $severty = 'low';
            }

            if($severty === null){
                continue;
            }

            // ajouter la première anomalie par métrique
            if(!isset($anomaliesByMetric[$th])){
                $anomaliesByMetric[$th] = [
                    'count' => 0,
                    'max_value' => $value,
                    'threshold' => $levels['low'],
                    'severity' => $severty
                ];
            }

            $anomaliesByMetric[$th]['count']++;
            $anomaliesByMetric[$th]['max_value'] = max($anomaliesByMetric[$th]['max_value'], $value);
            $currentSeverity = $anomaliesByMetric[$th]['severity'];

            if($severityLevel[$severty] > $severityLevel[$currentSeverity]){
                $anomaliesByMetric[$th]['severity'] = $severty;
            }
        }

        /*
        * Construire l'objet de résumé des services  
        */
        foreach($report['service_status'] as $service => $status){
            if(!isset($statusLevels[$status])) {
                throw new RuntimeException(sprintf('Unknown service status: %s', $status));
            }

            if(!isset($servicesStatus[$service])){
                $servicesStatus[$service] = $status;
                continue;
            }

            $currentStatus = $servicesStatus[$service];

            if($statusLevels[$status] > $statusLevels[$currentStatus]){
                $servicesStatus[$service] = $status;
            }

        }
    }

    $reportCount = count($reports);

    $insights = [
        'average_latency_ms' => round($latencySum / $reportCount, 2),
        'max_cpu_usage' => $maxCpuUsage,
        'max_memory_usage' => $maxMemoryUsage,
        'error_rate' => round($errorRateSum / $reportCount, 5),
        'uptime_seconds' => $maxUptime
    ];
    
    $anomalies = [];

    foreach($anomaliesByMetric as $metric => $data){
        $anomalies[] = [
            'metric' => $metric,
            'value' => $data['max_value'],
            'threshold' => $data['threshold'],
            'severity' => $data['severity'],
            'occurrences' => $data['count'],
            'total_measurements' => $reportCount,
        ];
    }

    $serviceStatusSummary = [
        'online' => [],
        'degraded' => [],
        'offline' => [],
    ];

    foreach($servicesStatus as $service => $status){
        $serviceStatusSummary[$status][] = $service;
    }

    return [
        'insights' => $insights,
        'anomalies' => $anomalies,
        'service_status_summary' => $serviceStatusSummary,
    ];
}
