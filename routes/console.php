<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exchange:sync-pma {--rate=mid}', function () {
    $rateType = strtolower((string) $this->option('rate'));
    $allowedRateTypes = ['buy', 'sell', 'mid'];

    if (! in_array($rateType, $allowedRateTypes, true)) {
        $this->error('Invalid rate option. Use buy, sell, or mid.');
        return 1;
    }

    $client = Http::timeout(20)->withoutVerifying();

    $pmaPage = $client->get('https://www.pma.ps/ar/ExchangeRates')->body();

    if (! preg_match('/id="currency-ifrm"[^>]+src="([^"]+)"/', $pmaPage, $pageMatch)) {
        $this->error('Failed to locate PMA currency widget.');
        return 1;
    }

    $embedUrl = html_entity_decode($pageMatch[1], ENT_QUOTES);
    $embedHtml = $client->get($embedUrl)->body();

    $pattern = '/<li>.*?<img[^>]+src="[^"]*\\/([a-z]{3})-ar\\.png"[^>]*>.*?<span><strong>[^<]*=\\s*<\\/strong>\\s*([0-9.]+)\\s*<\\/span>.*?<span class="separator">\\|<\\/span>.*?<span><strong>[^<]*=\\s*<\\/strong>\\s*([0-9.]+)\\s*<\\/span>.*?<\\/li>/s';
    if (! preg_match_all($pattern, $embedHtml, $matches, PREG_SET_ORDER)) {
        $this->error('Failed to parse PMA rates.');
        return 1;
    }

    $rawRates = [];
    foreach ($matches as $match) {
        $currency = strtoupper($match[1]);
        $buy = (float) $match[2];
        $sell = (float) $match[3];

        $rawRates[$currency] = [
            'buy' => $buy,
            'sell' => $sell,
            'mid' => ($buy + $sell) / 2,
        ];
    }

    if (! isset($rawRates['USD'])) {
        $this->error('USD rate missing from PMA data.');
        return 1;
    }

    $usdIls = $rawRates['USD'][$rateType];
    $toUsdRates = [];

    foreach ($rawRates as $currency => $data) {
        $ilsPerUnit = $data[$rateType];
        $toUsdRates[$currency] = $ilsPerUnit / $usdIls;
    }

    $toUsdRates['USD'] = 1.0;
    $toUsdRates['ILS'] = 1 / $usdIls;

    $now = now();

    DB::transaction(function () use ($toUsdRates, $usdIls, $now) {
        foreach ($toUsdRates as $fromCurrency => $rate) {
            DB::table('exchange_rates')->updateOrInsert(
                ['from_currency' => $fromCurrency, 'to_currency' => 'USD'],
                ['rate' => round($rate, 8), 'updated_at' => $now, 'created_at' => $now],
            );
        }

        DB::table('exchange_rates')->updateOrInsert(
            ['from_currency' => 'USD', 'to_currency' => 'ILS'],
            ['rate' => round($usdIls, 8), 'updated_at' => $now, 'created_at' => $now],
        );
    });

    $this->info('Synced exchange rates to USD: ' . implode(', ', array_keys($toUsdRates)));
    $this->info('Source: Palestine Monetary Authority (PMA).');
})->purpose('Sync exchange rates from PMA currency widget');
