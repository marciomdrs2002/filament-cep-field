<?php

namespace Marciomdrs\FilamentCepField;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CepService
{
    /**
     * @return array{street:string,neighborhood?:string,city:string,state:string,source:string}|null
     */
    public static function find(?string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', (string) $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        try {
            return Cache::remember("cep_data_{$cep}", 86400 * 30, function () use ($cep): ?array {
                return self::fetchFromApis($cep);
            });
        } catch (Throwable) {
            // Some cache stores (e.g. a non-tagging store under tenant cache tagging) throw;
            // degrade gracefully to a direct lookup instead of bubbling up.
            return self::fetchFromApis($cep);
        }
    }

    /**
     * @return array{street:string,neighborhood?:string,city:string,state:string,source:string}|null
     */
    private static function fetchFromApis(string $cep): ?array
    {
        try {
            $response = Http::timeout(3)->get("https://brasilapi.com.br/api/cep/v1/{$cep}");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'street' => $data['street'] ?? '',
                    'neighborhood' => $data['neighborhood'] ?? '',
                    'city' => $data['city'] ?? '',
                    'state' => $data['state'] ?? '',
                    'source' => 'brasilapi',
                ];
            }
        } catch (Throwable $e) {
            Log::error('Erro ao buscar CEP no BrasilAPI: '.$e->getMessage());
        }

        try {
            $response = Http::timeout(3)->get("https://viacep.com.br/ws/{$cep}/json/");

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['erro'])) {
                    return null;
                }

                return [
                    'street' => $data['logradouro'] ?? '',
                    'neighborhood' => $data['bairro'] ?? '',
                    'city' => $data['localidade'] ?? '',
                    'state' => $data['uf'] ?? '',
                    'source' => 'viacep',
                ];
            }
        } catch (Throwable $e) {
            Log::error('Erro ao buscar CEP no ViaCEP: '.$e->getMessage());
        }

        return null;
    }
}
