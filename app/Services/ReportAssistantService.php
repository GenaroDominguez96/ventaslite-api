<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReportAssistantService
{
    private const INSTRUCTIONS = 'Eres el asistente analítico de VentasLite. Responde siempre en español claro y profesional. Usa exclusivamente los datos agregados incluidos en el contexto. Cita cifras concretas y respeta importes, cantidades y periodos. No inventes cifras, sucursales, productos, vendedores ni explicaciones causales. Distingue los datos observados de las hipótesis. Si un dato no está disponible, dilo expresamente. Las ventas canceladas ya están excluidas. No solicites, generes ni ejecutes SQL ni intentes acceder a la base de datos.';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function generate(string $prompt, array $context): string
    {
        $provider = (string) config('services.ai.provider', 'gemini');
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw new RuntimeException(strtoupper($provider).' API key is not configured.');
        }

        $payload = [
            'pregunta' => $prompt,
            'datos_agregados_del_sistema' => $context,
        ];

        return match ($provider) {
            'gemini' => $this->generateWithGemini($apiKey, $payload),
            'openai' => $this->generateWithOpenAI($apiKey, $payload),
            default => throw new RuntimeException('Unsupported AI provider: '.$provider),
        };
    }

    private function apiKey(): string
    {
        return match (config('services.ai.provider', 'gemini')) {
            'gemini' => (string) config('services.gemini.api_key'),
            'openai' => (string) config('services.openai.api_key'),
            default => '',
        };
    }

    private function generateWithGemini(string $apiKey, array $payload): string
    {
        $primaryModel = (string) config('services.gemini.model', 'gemini-3.5-flash-lite');
        $fallbackModel = (string) config('services.gemini.fallback_model', 'gemini-3.8-flash');

        // Si ambos modelos quedaron configurados iguales, usa el otro modelo como respaldo.
        if ($fallbackModel === '' || $fallbackModel === $primaryModel) {
            $fallbackModel = $primaryModel === 'gemini-3.5-flash-lite'
                ? 'gemini-3.8-flash'
                : 'gemini-3.5-flash-lite';
        }

        $models = array_values(array_unique([$primaryModel, $fallbackModel]));
        // Es una interacción en pantalla: limitar cada modelo a 12 segundos como máximo.
        $timeout = min(max((int) config('services.ai.timeout', 12), 1), 12);

        foreach ($models as $index => $model) {
            try {
                $response = Http::withHeaders([
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'systemInstruction' => [
                            'parts' => [['text' => self::INSTRUCTIONS]],
                        ],
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [[
                                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]],
                        ]],
                        'generationConfig' => [
                            'maxOutputTokens' => (int) config('services.ai.max_output_tokens', 800),
                            'thinkingConfig' => [
                                'thinkingLevel' => str_contains($model, 'flash-lite') ? 'MINIMAL' : 'LOW',
                            ],
                        ],
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('Gemini agotó el tiempo de espera.', [
                    'model' => $model,
                    'timeout_seconds' => $timeout,
                    'using_fallback' => isset($models[$index + 1]),
                ]);

                if (isset($models[$index + 1])) {
                    continue;
                }

                throw new RuntimeException(
                    'Gemini no respondió dentro del tiempo límite de '.$timeout.' segundos.',
                    0,
                    $exception
                );
            }

            if (! $response->successful()) {
                $status = $response->status();
                $providerMessage = $response->json('error.message', 'No se recibió detalle del proveedor.');
                $isTransient = in_array($status, [500, 502, 503, 504], true);

                Log::warning('Gemini rechazó la solicitud del asistente de reportes.', [
                    'model' => $model,
                    'status' => $status,
                    'provider_message' => $providerMessage,
                    'using_fallback' => $isTransient && isset($models[$index + 1]),
                ]);

                if ($isTransient && isset($models[$index + 1])) {
                    continue;
                }

                throw new RuntimeException(
                    'Gemini respondió HTTP '.$status.': '.$providerMessage
                );
            }

            $answer = collect($response->json('candidates', []))
                ->flatMap(fn (array $candidate) => $candidate['content']['parts'] ?? [])
                ->pluck('text')
                ->filter(fn ($text) => is_string($text) && trim($text) !== '')
                ->implode(PHP_EOL);

            if (trim($answer) === '') {
                $finishReason = collect($response->json('candidates', []))
                    ->pluck('finishReason')
                    ->filter()
                    ->implode(', ');
                $blockReason = $response->json('promptFeedback.blockReason');

                throw new RuntimeException(sprintf(
                    'Gemini no devolvió texto. finishReason=%s; blockReason=%s',
                    $finishReason !== '' ? $finishReason : 'sin candidato',
                    $blockReason ?: 'ninguno'
                ));
            }

            return trim($answer);
        }

        throw new RuntimeException('No fue posible obtener una respuesta de los modelos Gemini configurados.');
    }

    private function generateWithOpenAI(string $apiKey, array $payload): string
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.ai.timeout', 30))
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model', 'gpt-6-luna'),
                'instructions' => self::INSTRUCTIONS,
                'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => (int) config('services.ai.max_output_tokens', 650),
                'store' => false,
            ]);

        if (! $response->successful()) {
            Log::warning('OpenAI rechazó la solicitud del asistente de reportes.', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('OpenAI request failed.');
        }

        $answer = collect($response->json('output', []))
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->filter(fn (array $content) => ($content['type'] ?? null) === 'output_text')
            ->pluck('text')
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')
            ->implode(PHP_EOL);

        if (trim($answer) === '') {
            throw new RuntimeException('OpenAI returned an empty response.');
        }

        return trim($answer);
    }
}
