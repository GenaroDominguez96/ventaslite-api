<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Services\\ReportAssistantService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Validation\\ValidationException;
use Throwable;

class ReportAssistantController extends Controller
{
    public function ask(
        Request $request,
        ReportController $reports,
        ReportAssistantService $assistant
    ): JsonResponse {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        return $this->respond(
            $request,
            $reports,
            $assistant,
            $validated['question'],
            'La respuesta del asistente se generó correctamente.'
        );
    }

    public function executiveSummary(
        Request $request,
        ReportController $reports,
        ReportAssistantService $assistant
    ): JsonResponse {
        return $this->respond(
            $request,
            $reports,
            $assistant,
            'Genera un resumen ejecutivo en un solo párrafo del periodo filtrado. Incluye ventas totales, número de ventas, ticket promedio, variación contra el periodo anterior y los hallazgos relevantes de productos, vendedores, sucursales y margen. No inventes causas que no puedan deducirse de los datos.',
            'El resumen ejecutivo se generó correctamente.'
        );
    }

    private function respond(
        Request $request,
        ReportController $reports,
        ReportAssistantService $assistant,
        string $prompt,
        string $message
    ): JsonResponse {
        if (! config('services.openai.api_key')) {
            return response()->json([
                'success' => false,
                'message' => 'El asistente de IA no está configurado. Agrega OPENAI_API_KEY en el archivo .env del backend. Los reportes siguen disponibles.',
                'data' => null,
            ], 503);
        }

        try {
            $context = $this->aggregatedContext($request, $reports);
            $answer = $assistant->generate($prompt, $context);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => ['answer' => $answer],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('No fue posible generar una respuesta de IA para reportes.', [
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No fue posible consultar el asistente de IA en este momento. Los reportes siguen disponibles.',
                'data' => null,
            ], 502);
        }
    }

    private function aggregatedContext(Request $request, ReportController $reports): array
    {
        $dashboard = $reports->dashboard($request)->getData(true);
        $monthlySales = $reports->monthlySales($request)->getData(true);
        $topProducts = $reports->topProducts($request)->getData(true);
        $sellers = $reports->sellers($request)->getData(true);
        $branchCategory = $reports->branchCategory($request)->getData(true);
        $margins = $reports->margins($request)->getData(true);

        return [
            'periodo_y_filtros' => $dashboard['meta']['filters'] ?? [],
            'indicadores' => $dashboard['data'] ?? [],
            'ventas_por_mes' => $monthlySales['data'] ?? [],
            'top_10_productos_por_unidades' => $topProducts['data'] ?? [],
            'ranking_de_vendedores' => $sellers['data'] ?? [],
            'ventas_por_sucursal_y_categoria' => $branchCategory['data'] ?? [],
            'utilidad_bruta_por_categoria' => $margins['data'] ?? [],
        ];
    }
}
