<?php

namespace App\Services\Exchange;

use App\Contracts\Exchange\ApiInterface;
use App\Contracts\Exchange\ServiceInterface as ExchangeServiceInterface;
use App\Http\Resources\SymbolsResource;
use App\Models\Exchange\Local\ExchangeState;
use App\Models\Exchange\Local\ExchangeStorage;
use App\Models\Exchange\Order;
use App\Models\Exchange\Symbol;
use App\Services\Exchange\Binance\Api;
use App\Utilities\Data;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExchangeService implements ExchangeServiceInterface
{
    public const TIME_MS_TICKER24_UPDATE_TIME = 1000 * 60; // 1 min
    public const TIME_MS_EXCHANGE_INFO_UPDATE_TIME = 1000 * 60 * 2; // 2 mins
    public function __construct(
        protected ApiInterface $api,
        protected ExchangeState $state,
        protected ExchangeStorage $db
    ) {
    }

    public function isFutures(): bool
    {
        return $this->api->isFutures();
    }
    public function getKlineData(string $symbol, string $interval): array
    {
        $lastBar = $this->api->getLastBar($symbol, $interval);
        $klines = $this->db->getKlines($symbol, $interval);
        if (!empty($klines)) {
            if ($this->db->isTheLastBarStillOpen($lastBar)) {
                $this->db->updateLastBar($lastBar);
                $klines[count($klines) - 1] = $lastBar;
                return $klines;
            } else {
                $startTime = $this->db->getMissingKlinesStartTime($interval);
                $endTime = $lastBar['open_time'];
                $klinesMissing = $this->api->getKlineData($symbol, $interval, $startTime, $endTime, 1000);
                $this->db->updateMissingKlines($klinesMissing);
                return $this->db->getKlines($symbol, $interval);
            }
        } else {
            $klinesNew = $this->api->getKlineData($symbol, $interval);
            $this->db->createNewKlines($klinesNew);
            return $klinesNew;
        }
    }

    public function getServerTime(): array
    {
        return $this->api->getServerTime();
    }

    public function getAllSymbols(string $quoteAsset = null): array
    {
        return $this->db->getSymbols($quoteAsset);
    }

    public function getSymbolInfo(string $symbol): array
    {
        // TODO: Implement getSymbolInfo() method.
        return [];
    }

    public function getTicker24h(string $quoteAsset, string $sortByField, string $sortDir): array
    {
        $lastUpdateTime = $this->state->getTicker24LastUpdateTime();
        $nowTime = Data::getTimestampInMilliseconds();
        if (($nowTime - $lastUpdateTime) > self::TIME_MS_TICKER24_UPDATE_TIME) {
            $this->state->setTicker24LastUpdateTime($nowTime);
            $tickers = $this->api->getTicker24h();
            $this->db->updateTickers($tickers);
        }
        if (!empty($quoteAsset)) {
            return $this->db->getTickers($quoteAsset, $sortByField, $sortDir);
        }
        return $this->db->getAllTickers();
    }

    public function getPriceTicker(string $symbol = null): array
    {
        return $this->api->getPriceTicker($symbol);
    }

    public function getLastPrice(string $symbol): float
    {
        $ticker = $this->getPriceTicker($symbol);
        if (empty($ticker)) {
            return 0;
        }
        return floatval($ticker['price']);
    }

    public function calculateOrderQuantity($symbol, $balance, $percentToSpend): float {
        $info = $this->api->getExchangeInfo();
        if ($info['symbols'] && is_array($info['symbols'])) {
            foreach ($info['symbols'] as $symbolInfo) {
                if ($symbolInfo['symbol'] === $symbol) {
                    foreach ($symbolInfo['filters'] as $filter) {
                        if ($filter['filterType'] === 'MARKET_LOT_SIZE') {
                            $stepSize = $filter['stepSize'];
                            $precision = intval(-log10((float)$stepSize));
                            $amountToSpend = ($balance * $percentToSpend) / 100;
                            $currentPrice = $this->getLastPrice($symbol);
                            if ($currentPrice > 0) {
                                $quantity = $amountToSpend / $currentPrice;
                                return round($quantity - fmod($quantity, (float)$stepSize), $precision);
                            }
                        }
                    }
                }
            }
        }
        return 0;
    }

    public function getSymbols($data): AnonymousResourceCollection
    {
        $query = Symbol::query();
        if (!$query->count()) {
            $this->updateExchangeInfo();
        }
        $query = Symbol::query();
        $query->select('symbol');
        $per_page = isset($data['per_page']) && is_numeric($data['per_page']) ? intval($data['per_page']) : 10;

        if (!empty($data['search'])) {
            $query = $query->search($data['search']);
        }
        if (!empty($data['sort_by']) && !empty($data['sort'])) {
            $query = $query->orderBy($data['sort_by'], $data['sort']);
        }
        return SymbolsResource::collection($query->paginate($per_page));
    }

    public function updateExchangeInfo(): void
    {
        $lastUpdateTime = $this->state->getExchangeInfoLastUpdateTime();
        $nowTime = Data::getTimestampInMilliseconds();
        if (($nowTime - $lastUpdateTime) > self::TIME_MS_EXCHANGE_INFO_UPDATE_TIME) {
            $this->state->setExchangeInfoLastUpdateTime($nowTime);
            $info = $this->api->getExchangeInfo();
            $this->db->updateExchangeInfo($info);
        }
    }

    public function getTimeframes(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    public function updateAndGetLastBar(string $symbol, string $interval): array
    {
        $lastBar = $this->api->getLastBar($symbol, $interval);
        $this->db->updateLastBar($lastBar);
        return $lastBar;
    }

    public function getSymbolMinPrice(string $symbol): string
    {
        return $this->db->getSymbolMinPrice($symbol);
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        return $this->api->getOpenOrders($symbol);
    }

    public function getAllOrders(string $symbol, int $orderId = null, int $startTime = null, int $endTime = null, int $limit = null): array
    {
        return $this->api->getAllOrders($symbol, $orderId, $startTime, $endTime, $limit);
    }

    public function getOrder(string $symbol, int $orderId = null, string $origClientOrderId = ''): array
    {
        return $this->api->getOrder($symbol, $orderId, $origClientOrderId);
    }

    public function getBalance($symbol, $isFutures = false)
    {
        return $this->api->getBalance();
    }

    public function getFuturesBalance(string $asset = 'USDT')
    {
        if ($this->isFutures()) {
            $response = $this->api->getBalance();
            if (!empty($response)) {
                foreach ($response as $item) {
                    if ($item['asset'] == $asset) {
                        return floatval($item['balance']);
                    }
                }
            }
        }
        return 0;
    }

    public function setFuturesLeverage($symbol, $leverage)
    {
        if ($this->isFutures()) {
            $this->api->setLeverage($symbol, $leverage);
        }
    }

    public function setFuturesMarginType(string $symbol, string $marginType)
    {
        if ($this->isFutures()) {
            $this->api->setMarginType($symbol, $marginType);
        }
    }

    public function getAccountInformation()
    {

    }

    public function getMarginTypeAndLeverage(string $symbol): array
    {
        if ($this->api->isFutures()) {
            $info = $this->api->getAccountInformation();
            if (!empty($info) && !empty($info['positions'])) {
                if (is_array($info['positions'])) {
                    foreach ($info['positions'] as $position) {
                        if ($position['symbol'] == $symbol) {
                            return [
                                'marginType' => $position['isolated'] ? 'ISOLATED' : 'CROSSED',
                                'leverage' => intval($position['leverage']),
                            ];
                        }
                    }
                }
            }
        }
        return [];
    }

    public function placeOrder(Order $order): array
    {
        return $this->api->createOrder($order);
    }

    public function getListenKey()
    {
        if ($this->api->isFutures()) {
            return $this->api->getListenKey();
        }
        return '';
    }
}
