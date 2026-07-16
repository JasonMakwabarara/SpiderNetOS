<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\Trade;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class PortfolioService
{
    public function __construct(
        private readonly EventStore $eventStore,
    ) {}

    public function createPortfolio(
        string $tenantId,
        string $name,
        string $strategy = 'balanced',
        string $riskLevel = 'medium',
        string $currency = 'USD',
    ): Portfolio {
        return Portfolio::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'strategy' => $strategy,
            'risk_level' => $riskLevel,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    public function addPosition(
        string $portfolioId,
        string $tenantId,
        string $assetType,
        string $symbol,
        string $quantity,
        string $price,
        ?string $name = null,
    ): PortfolioPosition {
        $portfolio = Portfolio::where('tenant_id', $tenantId)->findOrFail($portfolioId);

        $existing = PortfolioPosition::where('portfolio_id', $portfolioId)
            ->where('asset_type', $assetType)
            ->where('symbol', $symbol)
            ->first();

        if ($existing) {
            $totalQuantity = (float) $existing->quantity + (float) $quantity;
            $totalCost = ((float) $existing->avg_cost * (float) $existing->quantity) + ((float) $price * (float) $quantity);
            $newAvgCost = $totalCost / $totalQuantity;

            $existing->quantity = $totalQuantity;
            $existing->avg_cost = $newAvgCost;
            $existing->save();

            return $existing;
        }

        return PortfolioPosition::create([
            'portfolio_id' => $portfolioId,
            'tenant_id' => $tenantId,
            'asset_type' => $assetType,
            'symbol' => $symbol,
            'name' => $name,
            'quantity' => $quantity,
            'avg_cost' => $price,
            'current_price' => $price,
            'market_value' => (float) $quantity * (float) $price,
        ]);
    }

    public function executeTrade(
        string $portfolioId,
        string $tenantId,
        string $assetType,
        string $symbol,
        string $side,
        string $quantity,
        string $price,
        string $commission = '0',
    ): Trade {
        return DB::transaction(function () use (
            $portfolioId, $tenantId, $assetType, $symbol, $side, $quantity, $price, $commission
        ) {
            $totalValue = (float) $quantity * (float) $price + (float) $commission;

            $trade = Trade::create([
                'portfolio_id' => $portfolioId,
                'tenant_id' => $tenantId,
                'asset_type' => $assetType,
                'symbol' => $symbol,
                'side' => $side,
                'quantity' => $quantity,
                'price' => $price,
                'commission' => $commission,
                'total_value' => $totalValue,
                'status' => 'completed',
                'executed_at' => now(),
            ]);

            if ($side === 'buy') {
                $this->addPosition($portfolioId, $tenantId, $assetType, $symbol, $quantity, $price);
            } elseif ($side === 'sell') {
                $position = PortfolioPosition::where('portfolio_id', $portfolioId)
                    ->where('symbol', $symbol)
                    ->firstOrFail();

                $newQuantity = (float) $position->quantity - (float) $quantity;
                if ($newQuantity <= 0) {
                    $position->delete();
                } else {
                    $position->quantity = $newQuantity;
                    $position->save();
                }
            }

            $this->eventStore->append(
                $tenantId,
                'trade',
                $trade->id,
                'trade.executed',
                [
                    'asset_type' => $assetType,
                    'symbol' => $symbol,
                    'side' => $side,
                    'quantity' => $quantity,
                    'price' => $price,
                ]
            );

            return $trade;
        });
    }

    public function updatePositionPrices(string $portfolioId, array $prices): void
    {
        foreach ($prices as $symbol => $price) {
            $position = PortfolioPosition::where('portfolio_id', $portfolioId)
                ->where('symbol', $symbol)
                ->first();

            if ($position) {
                $position->updatePrice((float) $price);
            }
        }

        $portfolio = Portfolio::findOrFail($portfolioId);
        $totalValue = PortfolioPosition::where('portfolio_id', $portfolioId)
            ->sum('market_value');

        $portfolio->total_value = $totalValue;
        $portfolio->save();
    }

    public function getPortfolioPerformance(string $portfolioId): array
    {
        $portfolio = Portfolio::findOrFail($portfolioId);

        $positions = PortfolioPosition::where('portfolio_id', $portfolioId)->get();

        $totalValue = 0;
        $totalCost = 0;
        $totalPnl = 0;

        foreach ($positions as $position) {
            $totalValue += (float) $position->market_value;
            $totalCost += (float) $position->avg_cost * (float) $position->quantity;
            $totalPnl += (float) $position->unrealized_pnl;
        }

        return [
            'portfolio' => $portfolio,
            'total_value' => $totalValue,
            'total_cost' => $totalCost,
            'total_pnl' => $totalPnl,
            'return_percentage' => $totalCost > 0 ? round(($totalPnl / $totalCost) * 100, 2) : 0,
            'position_count' => $positions->count(),
        ];
    }
}
