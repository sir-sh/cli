<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Process;

/**
 * AWS Lambda-based remote execution executor.
 *
 * Executes workflows as AWS Lambda functions.
 */
class LambdaExecutor implements RemoteExecutor
{
    private string $functionName;
    private string $region;
    private ?string $accessKey;
    private ?string $secretKey;

    public function __construct(
        string $functionName,
        string $region = 'us-east-1',
        ?string $accessKey = null,
        ?string $secretKey = null
    ) {
        $this->functionName = $functionName;
        $this->region = $region;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    public function execute(array $workflow, array $inputs): array
    {
        $package = [
            'workflow' => $workflow,
            'inputs' => $inputs,
            'metadata' => [
                'created_at' => date('c'),
                'executor' => 'aws-lambda',
                'function' => $this->functionName,
            ],
        ];

        // Use AWS CLI to invoke Lambda
        $payload = json_encode($package);
        $payloadFile = tempnam(sys_get_temp_dir(), 'sir-lambda-payload-');

        try {
            file_put_contents($payloadFile, $payload);

            $process = $this->invokeLambda($payloadFile);

            if (! $process->isSuccessful()) {
                throw new RemoteExecutionException(
                    'Lambda invocation failed: '.$process->getErrorOutput(),
                    'aws-lambda',
                    $this->functionName
                );
            }

            // Parse Lambda response
            $response = json_decode($process->getOutput(), true) ?? [];

            if (isset($response['errorMessage'])) {
                return [
                    'success' => false,
                    'output' => $response['errorMessage'],
                    'exitCode' => $response['errorType'] ?? 'LambdaError',
                ];
            }

            return $response;
        } finally {
            @unlink($payloadFile);
        }
    }

    public function isAvailable(): bool
    {
        // Check if aws command exists
        $process = new Process(['which', 'aws']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        // Check if credentials are configured
        $process = new Process([
            'aws', 'sts', 'get-caller-identity',
            '--region', $this->region,
            '--request-timeout', '5'
        ]);

        if (strlen($this->accessKey) > 0) {
            $process->setEnv([
                'AWS_ACCESS_KEY_ID' => $this->accessKey,
                'AWS_SECRET_ACCESS_KEY' => $this->secretKey ?? '',
                'AWS_DEFAULT_REGION' => $this->region,
            ]);
        }

        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => false,
            'artifacts' => false, // Lambda has strict payload size limits
            'sandbox' => true,
            'limits' => ['timeout'], // Lambda has built-in memory/CPU
        ];
    }

    public function getType(): string
    {
        return 'aws-lambda';
    }

    private function invokeLambda(string $payloadFile): Process
    {
        $env = [];
        if (strlen($this->accessKey ?? '') > 0) {
            $env['AWS_ACCESS_KEY_ID'] = $this->accessKey;
            $env['AWS_SECRET_ACCESS_KEY'] = $this->secretKey ?? '';
        }
        $env['AWS_DEFAULT_REGION'] = $this->region;

        $args = [
            'aws', 'lambda', 'invoke',
            '--function-name', $this->functionName,
            '--payload', 'file://'.$payloadFile,
            '--invocation-type', 'RequestResponse',
            '--log-type', 'Tail',
            '--region', $this->region,
            'outfile', '/dev/stdout'
        ];

        $process = new Process($args);
        $process->setTimeout(300);

        if (count($env) > 0) {
            $process->setEnv($env);
        }

        $process->run();

        return $process;
    }
}