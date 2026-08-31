<?php

declare(strict_types=1);

/**
 * @param array<mixed> $analysis
 * @param array<mixed> $openAiConfig
 *
 * @return array<mixed>
 */
function generateLlmContent(array $analysis, array $openAiConfig): array
{
    /*
    * vérifier les clés
    */
    $apiKey = trim((string) ($openAiConfig['api_key'] ?? ''));
    $model = trim((string) ($openAiConfig['model'] ?? ''));
    $endpoint = trim((string) ($openAiConfig['endpoint'] ?? ''));

    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY is not configured.');
    }
    if ($model === '') {
        throw new RuntimeException('OPENAI_MODEL is not configured.');
    }
    if ($endpoint === '') {
        throw new RuntimeException('OpenAI endpoint is not configured.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }
    if (
        !isset(
            $analysis['insights'],
            $analysis['anomalies'],
            $analysis['service_status_summary'],
        )
    ) {
        throw new InvalidArgumentException(
            'The analysis payload is incomplete.',
        );
    }

    /**
     * encoder les 'insights' en JSON pour les envoyer au LLM
     */
    $analysisJson = json_encode($analysis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    /**
     * Construire le schéma de la réponse du LLM
     */
    $responseSchema = [
        'type' => 'object',
        'properties' => [
            'descriptions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'metric' => [
                            'type' => 'string',
                        ],
                        'description' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'metric',
                        'description',
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                        ],
                        'action' => [
                            'type' => 'string',
                        ],
                        'target' => [
                            'type' => 'string',
                        ],
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'implementation' => [
                                    'type' => 'string',
                                ],
                                'configuration' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'implementation',
                                'configuration',
                            ],
                            'additionalProperties' => false,
                        ],
                        'benefit_estimate' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'id',
                        'action',
                        'target',
                        'parameters',
                        'benefit_estimate',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => [
            'descriptions',
            'recommendations',
        ],
        'additionalProperties' => false,
    ];

    /**
     * Construire le "prompt" ainsi que la requête à envoyer au LLM
     */
     $payload = [
        'model' => $model,
        'instructions' => <<<'PROMPT'
            <role>
            You are a senior infrastructure optimization expert.
            </role>

            <context>
            The input contains deterministic infrastructure insights, aggregated anomalies, and service statuses produced by a PHP application.

            Treat every calculated value and service status in the input as the source of truth.

            The input contains aggregated information only. It does not contain enough information to establish temporal correlation, simultaneity, causality, or root causes.
            </context>

            <input_semantics>
            Each anomaly contains the following fields:

            - metric: the exact name of the monitored metric.
            - value: the maximum observed value for that metric across the complete report.
            - threshold: the base threshold used to determine whether a measurement is anomalous.
            - severity: the highest severity observed for that metric.
            - occurrences: the number of measurements where the metric value reached or exceeded the threshold.
            - total_measurements: the total number of measurements analyzed.

            Important interpretation rules:

            - The maximum value did not necessarily occur as many times as the occurrence count.
            - Never state or imply that the maximum value occurred in every anomalous measurement.
            - An occurrence count represents threshold breaches, not repetitions of the maximum value.
            - Equal occurrence counts for different metrics do not prove that those metrics occurred during the same measurements.
            - The input does not establish that two anomalies happened simultaneously.
            - The input does not establish that one anomaly caused another anomaly.

            The service_status_summary groups each service by its worst observed status across the complete report.

            - A degraded or offline status does not indicate how many times the status occurred.
            - It does not indicate when the status occurred.
            - It does not prove that the status occurred simultaneously with any numerical anomaly.
            - Do not describe a service as continuously or currently degraded or offline.
            </input_semantics>

            <objectives>
            1. Write exactly one description for every anomaly.
            2. Generate concrete and actionable infrastructure recommendations based on the detected anomalies and problematic service statuses.
            3. Address every service marked as degraded or offline.
            4. Group related problems when they can reasonably be addressed by the same recommendation.
            </objectives>

            <anomaly_description_rules>
            - Use only information provided in the input.
            - Preserve metric names exactly.
            - Do not modify values, thresholds, severities, occurrence counts, or total measurement counts.
            - Describe occurrences as measurements, not requests, incidents, events, or time periods.
            - Clearly distinguish observed facts from possible technical consequences.
            - Do not claim that metrics occurred simultaneously.
            - Do not claim causal relationships between metrics.
            - Use cautious terms such as "may", "could", or "might" for possible consequences.
            - Do not describe a problem as continuous, permanent, recurring, or sustained.
            - Do not say that the maximum value occurred as many times as the occurrence count.
            - Keep every description concise, factual, and understandable by a CTO.

            Use this factual structure when appropriate:

            "<metric> exceeded the threshold of <threshold> in <occurrences> out of <total_measurements> measurements, with a maximum observed value of <value>. This may indicate <possible consequence>."

            The possible consequence must be presented as a possibility, not as an observed fact or confirmed cause.
            </anomaly_description_rules>

            <service_status_rules>
            - Every service marked as degraded or offline must be explicitly addressed by at least one recommendation.
            - Do not ignore any problematic service.
            - Multiple problematic services may be grouped into the same recommendation when the proposed action reasonably applies to all of them.
            - When multiple services are grouped, explicitly mention every affected service in the action or implementation.
            - A service marked as online does not require a recommendation unless it is directly relevant to another detected problem.
            - Treat the service status as the worst observed status in the report.
            - Do not claim that a service remained in that status continuously.
            - Do not invent a cause for a degraded or offline service.
            - Do not claim that a service status caused a numerical anomaly.
            </service_status_rules>

            <recommendation_rules>
            - Generate as many recommendations as necessary to meaningfully address the detected problems.
            - Produce the smallest non-redundant set of recommendations that collectively addresses all anomalies and problematic services.
            - Do not generate recommendations only to reach a specific number.
            - Avoid redundant or duplicate recommendations.
            - Every recommendation must be connected to at least one provided anomaly or problematic service status.
            - Prefer grouping related anomalies when one technical action can reasonably address them together.
            - Keep unrelated problems in separate recommendations.
            - Prioritize actionable recommendations over generic advice.
            - Do not invent cloud providers, technologies, products, application runtimes, infrastructure components, incidents, or root causes that are not present in the input.
            - General technical solutions such as scaling, load balancing, monitoring, resource tuning, capacity management, and incident investigation are allowed.
            - Present unverified technical causes as investigation hypotheses, not as facts.
            - Do not present a proposed solution as guaranteed to solve the problem.
            - Do not claim that different metrics occurred during the same measurements.
            - When referring to anomaly data, clearly distinguish the maximum observed value from the number of threshold breaches.
            - Recommendations may propose collecting or correlating additional data, but must not claim that such correlation already exists.
            </recommendation_rules>

            <recommendation_structure_rules>
            - Use unique sequential recommendation IDs starting with REC-001.
            - The target must be a short infrastructure component identifier.
            - Do not include measurements or explanations inside the target.
            - Explain the concrete action to perform in parameters.implementation.
            - Explain the relevant configuration or operational condition in parameters.configuration.
            - Keep benefit estimates realistic, qualitative, and directly connected to the detected problem.
            - Benefit estimates must use cautious language and must not promise a guaranteed result.
            </recommendation_structure_rules>

            <target_examples>
            compute_resources
            database
            api_gateway
            cache
            storage
            connection_pool
            network
            hosts
            </target_examples>

            <language>
            Write all descriptions and recommendations in clear professional English.
            </language>

            <success_criteria>
            - Every input anomaly has exactly one description.
            - Every problematic service is explicitly addressed by at least one recommendation.
            - No metric, value, occurrence count, threshold, severity, or service status is modified.
            - Maximum values are never confused with occurrence counts.
            - No temporal correlation or simultaneity is invented.
            - Every recommendation is justified by information contained in the input.
            - Related problems are grouped when appropriate without hiding or ignoring problematic services.
            - No unsupported factual or causal claim is presented as certain.
            - Recommendations are actionable and non-redundant.
            - Every recommendation follows the required Structured Output schema.
            </success_criteria>
            PROMPT,
        'input' => sprintf(
            "Infrastructure analysis:\n%s",
            $analysisJson,
        ),
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'infrastructure_optimization',
                'strict' => true,
                'schema' => $responseSchema,
            ],
        ],
        'max_output_tokens' => 2500,
        'store' => false,
    ];

    $requestBody = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    /**
     * Exécuter la requête à l'API OpenAI
     */
    $curl = curl_init($endpoint);

    if ($curl === false) {
        throw new RuntimeException(
            'Unable to initialize the OpenAI HTTP request.',
        );
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            sprintf('Authorization: Bearer %s', $apiKey),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $requestBody,
    ]);

    $rawResponse = curl_exec($curl);

    if ($rawResponse === false) {
        $error = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException(
            sprintf('OpenAI request failed: %s', $error),
        );
    }

    $statusCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE,
    );

    curl_close($curl);

    /**
     * Vérifier la réponse de l'API
     */
    try {
        $response = json_decode(
            $rawResponse,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'OpenAI returned an invalid JSON response.',
            previous: $exception,
        );
    }
    if($statusCode < 200 || $statusCode >= 300){
        $errorMessage = $response['error']['message'] ?? sprintf('HTTP status %d', $statusCode);

        throw new RuntimeException(sprintf('OpenAI API error: %s', $errorMessage));
    }

    if (($response['status'] ?? null) !== 'completed') {
        throw new RuntimeException(sprintf('OpenAI response was not completed. Status: %s',$response['status'] ?? 'unknown'));
    }

    /**
     * Extraire le contenu généré par le LLM
     */
    $outputText = extractOpenAiOutputText($response);

    try {
        $llmContent = json_decode($outputText,true,512,JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The LLM output is not valid JSON.',previous: $exception);
    }

    if (
        !is_array($llmContent)
        || !isset(
            $llmContent['descriptions'],
            $llmContent['recommendations'],
        )
        || !is_array($llmContent['descriptions'])
        || !is_array($llmContent['recommendations'])
    ) {
        throw new RuntimeException('The LLM output does not contain the expected fields.');
    }

    /**
     * Valider les descriptions des anomalies
     */
    validateLlmDescriptions($analysis['anomalies'],$llmContent['descriptions']);

    return $llmContent;
}

/**
 * @param array<mixed> $response
 */
function extractOpenAiOutputText(array $response): string
{
    $outputText = '';

    foreach ($response['output'] ?? [] as $outputItem) {
        if (!is_array($outputItem) || ($outputItem['type'] ?? null) !== 'message') {
            continue;
        }

        foreach ($outputItem['content'] ?? [] as $contentItem) {
            if (!is_array($contentItem)) {
                continue;
            }

            if (($contentItem['type'] ?? null) === 'refusal') {
                throw new RuntimeException(
                    sprintf('The LLM refused the request: %s',$contentItem['refusal'] ?? 'unknown reason',)
                );
            }

            if (($contentItem['type'] ?? null) === 'output_text' && isset($contentItem['text']) && is_string($contentItem['text'])) {
                $outputText .= $contentItem['text'];
            }
        }
    }

    if ($outputText === '') {
        throw new RuntimeException('The OpenAI response does not contain text output.');
    }

    return $outputText;
}

/**
 * @param array<mixed> $anomalies
 * @param array<mixed> $descriptions
 */
function validateLlmDescriptions(
    array $anomalies,
    array $descriptions,
): void {
    $expectedMetrics = array_column(
        $anomalies,
        'metric',
    );

    $returnedMetrics = [];

    foreach ($descriptions as $description) {
        if (
            !is_array($description)
            || !isset(
                $description['metric'],
                $description['description'],
            )
            || !is_string($description['metric'])
            || !is_string($description['description'])
            || trim($description['description']) === ''
        ) {
            throw new RuntimeException('The LLM returned an invalid anomaly description.');
        }

        $returnedMetrics[] = $description['metric'];
    }

    sort($expectedMetrics);
    sort($returnedMetrics);

    if ($expectedMetrics !== $returnedMetrics) {
        throw new RuntimeException('The LLM did not return exactly one description per anomaly.');
    }
}
