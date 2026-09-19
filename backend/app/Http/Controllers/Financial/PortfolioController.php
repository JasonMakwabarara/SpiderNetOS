<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Models\Trade;
use App\Services\Financial\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $portfolios = Portfolio::forTenant($tenant->id)->paginate($request->get('per_page', 20));

        return response()->json(['data' => $portfolios]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $portfolio = Portfolio::forTenant($tenant->id)->with('positions')->findOrFail($id);

        return response()->json(['data' => $portfolio]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => 'required|string',
            'strategy' => 'sometimes|string',
            'risk_level' => 'sometimes|in:low,medium,high,aggressive',
            'currency' => 'sometimes|string|size:3',
        ]);

        $portfolio = $this->portfolioService->createPortfolio(
            $tenant->id,
            $validated['name'],
            $validated['strategy'] ?? 'balanced',
            $validated['risk_level'] ?? 'medium',
            $validated['currency'] ?? 'USD',
        );

        return response()->json(['data' => $portfolio], 201);
    }

    public function performance(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $portfolio = Portfolio::forTenant($tenant->id)->findOrFail($id);
        $performance = $this->portfolioService->getPortfolioPerformance($portfolio->id);

        return response()->json(['data' => $performance]);
    }

    public function executeTrade(Request $request, string $portfolioId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $portfolio = Portfolio::forTenant($tenant->id)->findOrFail($portfolioId);

        $validated = $request->validate([
            'asset_type' => 'required|in:stock,bond,crypto,forex,commodity,etf',
            'symbol' => 'required|string',
            'side' => 'required|in:buy,sell',
            'quantity' => 'required|numeric|min:0.00000001',
            'price' => 'required|numeric|min:0',
            'commission' => 'sometimes|numeric|min:0',
        ]);

        $trade = $this->portfolioService->executeTrade(
            $portfolio->id,
            $tenant->id,
            $validated['asset_type'],
            $validated['symbol'],
            $validated['side'],
            $validated['quantity'],
            $validated['price'],
            $validated['commission'] ?? '0',
        );

        return response()->json(['data' => $trade], 201);
    }

    public function updatePrices(Request $request, string $portfolioId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $portfolio = Portfolio::forTenant($tenant->id)->findOrFail($portfolioId);

        $validated = $request->validate([
            'prices' => 'required|array',
        ]);

        $this->portfolioService->updatePositionPrices($portfolio->id, $validated['prices']);

        return response()->json(['message' => 'Prices updated']);
    }

    public function trades(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $trades = Trade::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $trades]);
    }
}
