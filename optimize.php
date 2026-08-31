<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

require_once __DIR__ . '/src/ingest.php';
require_once __DIR__ . '/src/analyze.php';
require_once __DIR__ . '/src/llm.php';
require_once __DIR__ . '/src/output.php';

$config = require __DIR__ . '/config.php';

if (in_array($argv[1] ?? null, ['-h', '--help'], true)) {
    fwrite(STDOUT, <<<TEXT
Usage:
  php optimize.php [input.json] [output.json]

Arguments:
  input.json   Input report path (default: rapport.json)
  output.json  Output report path (default: output.json)

TEXT);

    exit(0);
}

$inputPath = $argv[1] ?? $config['paths']['input'];
$outputPath = $argv[2] ?? $config['paths']['output'];

try {
    $report = loadReport($inputPath);
    $analysis = analyzeReport($report, $config);
  
    $llmContent = generateLlmContent($analysis, $config['openai']);
    writeReport($outputPath, $analysis, $llmContent);

    fwrite(
        STDOUT,
        sprintf("Report written to: %s\n", $outputPath),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Error: %s\n", $exception->getMessage()));

    exit(1);
}
