<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

/**
 * OpenTelemetry Service Provider for distributed tracing.
 * Course MGL842 — Course 07 (Distributed Tracing / Jaeger)
 *
 * Registers a tracer singleton that sends spans to Jaeger via OTLP HTTP.
 * Falls back gracefully if the OTel SDK is not installed.
 */
class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->isOtelAvailable()) {
            // Register a no-op tracer so the rest of the app does not break
            // when the OTel SDK packages are not yet installed.
            $this->app->singleton('tracer', fn () => new class {
                public function spanBuilder(string $name): object
                {
                    return new class($name) {
                        public function __construct(private string $name) {}
                        public function startSpan(): object
                        {
                            return new class {
                                public function setAttribute(string $k, mixed $v): static { return $this; }
                                public function setStatus(mixed $s, string $d = ''): static { return $this; }
                                public function end(): void {}
                                public function activate(): object
                                {
                                    return new class {
                                        public function detach(): void {}
                                    };
                                }
                            };
                        }
                        public function setAttribute(string $k, mixed $v): static { return $this; }
                        public function setParent(mixed $ctx): static { return $this; }
                    };
                }
            });

            return;
        }

        $this->registerOtelTracer();
    }

    public function boot(): void
    {
        //
    }

    private function isOtelAvailable(): bool
    {
        return class_exists(\OpenTelemetry\SDK\Trace\TracerProvider::class)
            && class_exists(\OpenTelemetry\Contrib\Otlp\SpanExporter::class);
    }

    private function registerOtelTracer(): void
    {
        $this->app->singleton('tracer', function () {
            $endpoint    = config('otel.endpoint', 'http://jaeger:4318/v1/traces');
            $serviceName = config('otel.service_name', 'snipeit');
            $serviceVersion = config('version.app_version', '1.0.0');

            try {
                $transport = (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())
                    ->create($endpoint, 'application/x-protobuf');

                $exporter = new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);

                $processor = new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter);

                $tracerProvider = (new \OpenTelemetry\SDK\Trace\TracerProviderBuilder())
                    ->addSpanProcessor($processor)
                    ->setResource(
                        \OpenTelemetry\SDK\Resource\ResourceInfo::create(
                            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                                \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAME    => $serviceName,
                                \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_VERSION => $serviceVersion,
                                'deployment.environment' => config('app.env', 'production'),
                            ])
                        )
                    )
                    ->build();

                return $tracerProvider->getTracer($serviceName, $serviceVersion);
            } catch (\Throwable $e) {
                Log::warning('OpenTelemetry tracer failed to initialise: ' . $e->getMessage());

                // Return no-op tracer on failure
                return $this->app->make('tracer');
            }
        });
    }
}
