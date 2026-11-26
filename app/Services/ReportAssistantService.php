<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReportAssistantService
{
    public function generate(string $prompt, array $context): string
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $payload = [
            'pregunta' => $prompt,
            'datos_agregados_del_sistema' => $context,
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout', 30))
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model', 'gpt-6-luna'),
                'instructions' => implode(' ', [
                    'Eres el asistente analítico de VentasLite. Responde siempre en español claro y profesional.',
                    'Usa exclusivamente los datos agregados incluidos en el contexto para describir el desempeño.',
                    'Cita dentro de tu respuesta las cifras concretas que respalden cada hallazgo; respeta exactamente importes, cantidades y periodo.',
                    'No inventes cifras, sucursales, productos, vendedores ni explicaciones causales.',
                    'Cuando el usuario pregunte por qué ocurrió algo, distingue los datos observados de las posibles hipótesis y aclara cuando el contexto no permite determinar una causa.',
                    'Si el dato solicitado no está disponible en el contexto, dilo expresamente.',
                    'Las ventas canceladas ya están excluidas de los reportes.',
                    'No solicites, generes ni ejecutes SQL. No llames herramientas ni intentes acceder a la base de datos.',
                ]),
                'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'max_output_tokens' => 650,
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
