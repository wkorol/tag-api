<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExchangeRateController
{
    #[Route('/api/eur-rate', name: 'eur_rate', methods: ['GET'])]
    public function eurRate(
        CacheInterface $cache,
        HttpClientInterface $httpClient
    ): JsonResponse {
        $rate = $cache->get('eur_rate_pln', function (ItemInterface $item) use ($httpClient): ?float {
            $item->expiresAfter(60 * 60 * 6);

            $candidates = [
                'https://api.exchangerate.host/latest?base=PLN&symbols=EUR',
                'https://api.frankfurter.app/latest?from=PLN&to=EUR',
            ];

            foreach ($candidates as $endpoint) {
                try {
                    $response = $httpClient->request('GET', $endpoint);
                    if ($response->getStatusCode() !== Response::HTTP_OK) {
                        continue;
                    }
                    $data = $response->toArray(false);
                    $value = $data['rates']['EUR'] ?? null;
                    if (is_numeric($value)) {
                        return (float) $value;
                    }
                } catch (\Throwable) {
                    // try next candidate
                }
            }

            return null;
        });

        if ($rate === null) {
            return new JsonResponse(['rate' => null], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'rate' => $rate,
            'base' => 'PLN',
            'target' => 'EUR',
            'cachedForSeconds' => 21600,
        ]);
    }
}
