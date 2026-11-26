<?php

namespace App\Services;

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
        $model = (string) config('services.gemini.model', 'gemini-3.8-flash');
        $response = Http::acceptJson()
            ->timeout((int) config('services.ai.timeout', 30))
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
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
                    'maxOutputTokens' => (int) config('services.ai.max_output_tokens', 650),
                    'temperature' => 0.3,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Gemini rechazó la solicitud del asistente de reportes.', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Gemini request failed.');
        }

        $answer = collect($response->json('candidates', []))
            ->flatMap(fn (array $candidate) => $candidate['content']['parts'] ?? [])
            ->pluck('text')
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')
            ->implode(PHP_EOL);

        if (trim($answer) === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        return trim($answer);
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
