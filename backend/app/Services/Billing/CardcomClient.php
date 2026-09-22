<?php

namespace App\Services\Billing;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cardcom JSON v11. Card details are entered only on Cardcom's hosted page.
 *
 * Sandbox is terminal 1000 on the documented secure API, not a different API host.
 * No request is retried here. A timed-out charge must be reconciled using the SAME
 * ExternalUniqTranId before deciding whether another request is appropriate.
 */
class CardcomClient
{
    public const BASE_URL = 'https://secure.cardcom.solutions/api/v11';

    public function createCheckout(array $fields): array
    {
        return $this->post('/LowProfile/Create', $this->checkoutPayload($fields), mutates: true);
    }

    /** Check all local preconditions before persisting a pending payment. No HTTP request. */
    public function validateCheckout(array $fields): void
    {
        $this->credentials();
        $this->checkoutPayload($fields);
    }

    private function checkoutPayload(array $fields): array
    {
        $this->onlyKeys($fields, [
            'Amount', 'ReturnValue', 'SuccessRedirectUrl', 'FailedRedirectUrl',
            'CancelRedirectUrl', 'WebHookUrl', 'ProductName', 'Language',
        ]);

        $payload = [
            'Operation' => 'ChargeAndCreateToken',
            'Amount' => $this->amount($fields['Amount'] ?? null),
            'ReturnValue' => $this->identifier($fields['ReturnValue'] ?? null, 250),
            'ISOCoinId' => 1,
            'AdvancedDefinition' => [
                'MinNumOfPayments' => 1,
                'MaxNumOfPayments' => 1,
                'SelectedNumOfPayments' => 1,
            ],
        ];
        foreach (['SuccessRedirectUrl', 'FailedRedirectUrl', 'WebHookUrl'] as $field) {
            $payload[$field] = $this->publicHttpsUrl($fields[$field] ?? null);
        }
        if (isset($fields['CancelRedirectUrl'])) {
            $payload['CancelRedirectUrl'] = $this->publicHttpsUrl($fields['CancelRedirectUrl']);
        }
        if (isset($fields['ProductName'])) {
            if (! is_string($fields['ProductName']) || mb_strlen($fields['ProductName']) > 50) {
                throw new CardcomException('invalid_request');
            }
            $payload['ProductName'] = $fields['ProductName'];
        }
        $language = $fields['Language'] ?? 'he';
        if (! in_array($language, ['he', 'en', 'ru', 'ar', 'sp', 'fr'], true)) {
            throw new CardcomException('invalid_request');
        }
        $payload['Language'] = $language;

        return $payload;
    }

    public function lowProfileResult(string $lowProfileId): array
    {
        return $this->post('/LowProfile/GetLpResult', [
            'LowProfileId' => $this->uuid($lowProfileId),
        ]);
    }

    public function chargeToken(array $fields): array
    {
        $this->onlyKeys($fields, [
            'Amount', 'Token', 'CardExpirationMMYY', 'ExternalUniqTranId',
            'ISOCoinId', 'Advanced',
        ]);
        $expiry = $fields['CardExpirationMMYY'] ?? null;
        if (! is_string($expiry) || ! preg_match('/^(0[1-9]|1[0-2])[0-9]{2}$/D', $expiry)) {
            throw new CardcomException('invalid_request');
        }
        if (isset($fields['ISOCoinId']) && $fields['ISOCoinId'] !== 1) {
            throw new CardcomException('invalid_request');
        }
        $payload = [
            'Amount' => $this->amount($fields['Amount'] ?? null),
            'Token' => $this->uuid($fields['Token'] ?? null),
            'CardExpirationMMYY' => $expiry,
            'ExternalUniqTranId' => $this->identifier($fields['ExternalUniqTranId'] ?? null, 250),
            // The repeated "Uniq" is the exact published v11 OpenAPI property.
            // A duplicate returns 608; query the original transaction instead.
            'ExternalUniqUniqTranIdResponse' => false,
            'NumOfPayments' => 1,
            'ISOCoinId' => 1,
        ];
        if (isset($fields['Advanced'])) {
            if (! is_array($fields['Advanced'])) {
                throw new CardcomException('invalid_request');
            }
            $this->onlyKeys($fields['Advanced'], ['IsAutoRecurringPayment']);
            if (isset($fields['Advanced']['IsAutoRecurringPayment'])) {
                if (! is_bool($fields['Advanced']['IsAutoRecurringPayment'])) {
                    throw new CardcomException('invalid_request');
                }
                $payload['Advanced'] = $fields['Advanced'];
            }
        }

        return $this->post('/Transactions/Transaction', $payload, mutates: true);
    }

    public function transactionByExternalId(string $externalId): array
    {
        return $this->post('/Transactions/GetTransactionByExternalUniqTran', [
            'ExternalUniqTranId' => $this->identifier($externalId, 250),
        ]);
    }

    /** A successful HTTP response can still contain a declined/pending provider code. */
    private function post(string $path, array $payload, bool $mutates = false): array
    {
        $credentials = $this->credentials();
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(25)
                ->withoutRedirecting()
                ->withOptions(['verify' => true])
                ->post(self::BASE_URL.$path, [...$payload, ...$credentials]);
        } catch (ConnectionException|RequestException|TransferException) {
            // No previous exception: HTTP exception messages can contain tokens/body data.
            throw new CardcomException('transport_error', ambiguous: $mutates);
        }

        if (! $response->successful()) {
            // An HTTP failure does not prove that a charge was not performed.
            throw new CardcomException('http_error', ambiguous: $mutates, httpStatus: $response->status());
        }
        $result = $response->json();
        if (! is_array($result) || array_is_list($result) || ! array_key_exists('ResponseCode', $result)
            || ! is_int($result['ResponseCode'])) {
            throw new CardcomException('invalid_response', ambiguous: $mutates, httpStatus: $response->status());
        }

        return $result;
    }

    private function credentials(): array
    {
        $environment = config('business_pro.environment');
        $terminal = config('business_pro.cardcom.terminal_number');
        $apiName = config('business_pro.cardcom.api_name');
        if (! in_array($environment, ['sandbox', 'production'], true)
            || ! is_numeric($terminal) || (int) $terminal <= 0 || (string) (int) $terminal !== (string) $terminal
            || ! is_string($apiName) || trim($apiName) === '') {
            throw new CardcomException('unsafe_configuration');
        }
        if (($environment === 'sandbox' && (int) $terminal !== 1000)
            || ($environment === 'production' && ((int) $terminal === 1000
                || config('business_pro.cardcom.production_enabled') !== true))) {
            throw new CardcomException('unsafe_configuration');
        }

        // ApiPassword is for refunds/cancellations; these methods neither need nor send it.
        return ['TerminalNumber' => (int) $terminal, 'ApiName' => $apiName];
    }

    private function onlyKeys(array $fields, array $allowed): void
    {
        if (array_diff(array_keys($fields), $allowed) !== []) {
            throw new CardcomException('invalid_request');
        }
    }

    private function amount(mixed $amount): float
    {
        if ((! is_int($amount) && ! is_float($amount) && ! is_string($amount))
            || ! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $amount)
            || ! is_finite((float) $amount) || (float) $amount <= 0) {
            throw new CardcomException('invalid_request');
        }

        return (float) $amount;
    }

    private function identifier(mixed $value, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new CardcomException('invalid_request');
        }

        return $value;
    }

    private function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value)) {
            throw new CardcomException('invalid_request');
        }

        return $value;
    }

    private function publicHttpsUrl(mixed $url): string
    {
        if (! is_string($url) || strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new CardcomException('invalid_callback_url');
        }
        $parts = parse_url($url);
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $ipHost = trim($host, '[]');
        // Local sandbox development can return the browser to the local app for
        // explicit verification. Cardcom cannot deliver a server webhook there.
        if (config('business_pro.environment') === 'sandbox'
            && in_array(config('app.env'), ['local', 'testing'], true)
            && config('business_pro.cardcom.allow_local_callbacks') === true
            && in_array($ipHost, ['127.0.0.1', 'localhost', '::1'], true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])) {
            return $url;
        }
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || $host === '' || ! str_contains($host, '.')
            || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/iD', $host)
            || (filter_var($ipHost, FILTER_VALIDATE_IP) && ! filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new CardcomException('invalid_callback_url');
        }

        return $url;
    }
}
