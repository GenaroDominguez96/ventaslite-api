<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_returns_a_clear_message_when_api_key_is_missing(): void
    {
        config(['services.openai.api_key' => null]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/reports/assistant', [
                'question' => '¿Cuál fue la venta total?',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'El asistente de IA no está configurado. Agrega OPENAI_API_KEY en el archivo .env del backend. Los reportes siguen disponibles.');
    }

    public function test_assistant_sends_aggregated_report_context_to_openai(): void
    {
        config([
            'services.openai.api_key' => 'test-api-key',
            'services.openai.model' => 'gpt-6-luna',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => 'Las ventas del periodo fueron de $0.00 MXN.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/reports/assistant', [
                'question' => '¿Cuánto se vendió?',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.answer', 'Las ventas del periodo fueron de $0.00 MXN.');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-api-key')
                && str_contains((string) $request['input'], 'indicadores')
                && str_contains((string) $request['input'], 'ventas_por_mes')
                && ! str_contains((string) $request['input'], 'SELECT ');
        });
    }

    public function test_executive_summary_uses_the_selected_report_period(): void
    {
        config([
            'services.openai.api_key' => 'test-api-key',
            'services.openai.model' => 'gpt-6-luna',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => 'Resumen ejecutivo del periodo seleccionado.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/reports/executive-summary', [
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
            ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Resumen ejecutivo del periodo seleccionado.');

        Http::assertSent(fn ($request): bool => str_contains(
            (string) $request['input'],
            '2026-05-01'
        ) && str_contains((string) $request['input'], '2026-05-31'));
    }
}
