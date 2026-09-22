<?php

namespace Tests\Unit;

use App\Services\Billing\CardcomClient;
use App\Services\Billing\CardcomException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardcomClientTest extends TestCase
{
    private const PROFILE_ID = 'c6d041e9-1457-44b9-b39f-693ad2252f82';

    private const TOKEN = '0a6e4a6e-9b3b-4a97-b05d-c7857aad0ca9';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('business_pro.environment', 'sandbox');
        config()->set('business_pro.cardcom', [
            'terminal_number' => 1000,
            'api_name' => 'mock-api-name',
            'api_password' => 'never-forward-password',
            'production_enabled' => false,
            'allow_local_callbacks' => false,
        ]);
        Http::preventStrayRequests();
    }

    public function test_checkout_uses_published_schema_one_payment_and_hosted_tokenization(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/Create' => Http::response([
            'ResponseCode' => 0,
            'LowProfileId' => self::PROFILE_ID,
            'Url' => 'https://secure.cardcom.solutions/EA/LPC6/1000/'.self::PROFILE_ID,
        ])]);
        $response = app(CardcomClient::class)->createCheckout($this->checkout());
        $this->assertSame(self::PROFILE_ID, $response['LowProfileId']);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request['TerminalNumber'] === 1000
                && $request['ApiName'] === 'mock-api-name'
                && $request['Operation'] === 'ChargeAndCreateToken'
                && $request['Amount'] === 49.0
                && $request['ISOCoinId'] === 1
                && $request['AdvancedDefinition']['MaxNumOfPayments'] === 1
                && ! array_key_exists('ApiPassword', $data)
                && ! array_key_exists('CVV2', $data)
                && ! array_key_exists('CardNumber', $data);
        });
        Http::assertSentCount(1);
    }

    public function test_webhook_result_is_a_separate_server_to_server_post_and_preserves_official_spelling(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'TranzactionId' => 123456789,
            'TranzactionInfo' => ['ResponseCode' => 0, 'Amount' => 49, 'CoinId' => 1],
            'TokenInfo' => ['Token' => self::TOKEN, 'CardMonth' => 12, 'CardYear' => 2030],
        ])]);
        $response = app(CardcomClient::class)->lowProfileResult(self::PROFILE_ID);
        $this->assertSame(123456789, $response['TranzactionId']);
        $this->assertSame(self::TOKEN, $response['TokenInfo']['Token']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['LowProfileId'] === self::PROFILE_ID);
    }

    public function test_token_charge_sends_per_attempt_id_and_duplicate_is_not_silently_retried(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/Transactions/Transaction' => Http::response([
            'ResponseCode' => 608, 'Description' => 'Duplicate',
        ])]);
        $response = app(CardcomClient::class)->chargeToken($this->charge());
        $this->assertSame(608, $response['ResponseCode']);
        Http::assertSent(fn (Request $request) => $request['ExternalUniqTranId'] === 'sveevee-payment-uuid'
            && $request['ExternalUniqUniqTranIdResponse'] === false
            && $request['NumOfPayments'] === 1
            && $request['Token'] === self::TOKEN
            && $request['CardExpirationMMYY'] === '1230'
            && ! array_key_exists('CVV2', $request->data()));
        Http::assertSentCount(1);
    }

    public function test_reconciliation_queries_the_same_external_transaction_id(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/Transactions/GetTransactionByExternalUniqTran' => Http::response([
            'ResponseCode' => 0, 'TranzactionId' => 901, 'Amount' => 49, 'CoinId' => 1,
        ])]);
        $result = app(CardcomClient::class)->transactionByExternalId('sveevee-payment-uuid');
        $this->assertSame(901, $result['TranzactionId']);
        Http::assertSent(fn (Request $request) => $request['ExternalUniqTranId'] === 'sveevee-payment-uuid'
            && $request->url() === CardcomClient::BASE_URL.'/Transactions/GetTransactionByExternalUniqTran');
    }

    public function test_ambiguous_charge_timeout_is_redacted_and_never_retried(): void
    {
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;
            throw new ConnectionException('Error token='.self::TOKEN.' api=mock-api-name password=never-forward-password');
        });
        try {
            app(CardcomClient::class)->chargeToken($this->charge());
            $this->fail('An ambiguous charge timeout must raise a safe exception.');
        } catch (CardcomException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertSame('transport_error', $exception->reason);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::TOKEN, (string) $exception);
            $this->assertStringNotContainsString('mock-api-name', (string) $exception);
            $this->assertStringNotContainsString('never-forward-password', (string) $exception);
        }
        $this->assertSame(1, $requests);
    }

    public function test_provider_http_error_body_is_never_copied_into_exception(): void
    {
        Http::fake(fn () => Http::response(['ResponseCode' => 1, 'Description' => self::TOKEN], 503));
        try {
            app(CardcomClient::class)->chargeToken($this->charge());
            $this->fail('A provider HTTP error must not be treated as a confirmed decline.');
        } catch (CardcomException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertSame(503, $exception->httpStatus);
            $this->assertStringNotContainsString(self::TOKEN, (string) $exception);
        }
        Http::assertSentCount(1);
    }

    public function test_malformed_response_cannot_be_treated_as_a_success(): void
    {
        Http::fake(fn () => Http::response('<html>Proxy error</html>', 200));
        try {
            app(CardcomClient::class)->chargeToken($this->charge());
            $this->fail('Malformed payment response must raise an ambiguous error.');
        } catch (CardcomException $exception) {
            $this->assertSame('invalid_response', $exception->reason);
            $this->assertTrue($exception->ambiguous);
        }
    }

    public function test_business_decline_is_returned_without_granting_success_or_retrying(): void
    {
        Http::fake(fn () => Http::response(['ResponseCode' => 4, 'Description' => 'Declined']));
        $this->assertSame(4, app(CardcomClient::class)->chargeToken($this->charge())['ResponseCode']);
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_environments_and_terminals_are_blocked_before_any_network_request(array $overrides): void
    {
        config()->set($overrides);
        Http::fake();
        try {
            app(CardcomClient::class)->lowProfileResult(self::PROFILE_ID);
            $this->fail('Unsafe payment configuration was accepted.');
        } catch (CardcomException $exception) {
            $this->assertSame('unsafe_configuration', $exception->reason);
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'sandbox real terminal' => [['business_pro.cardcom.terminal_number' => 1234]],
            'production test terminal' => [['business_pro.environment' => 'production', 'business_pro.cardcom.production_enabled' => true]],
            'production not explicitly enabled' => [['business_pro.environment' => 'production', 'business_pro.cardcom.terminal_number' => 1234]],
            'unknown environment' => [['business_pro.environment' => 'test']],
            'empty api name' => [['business_pro.cardcom.api_name' => '']],
            'fractional terminal' => [['business_pro.cardcom.terminal_number' => '1000.1']],
        ];
    }

    public function test_explicit_production_configuration_uses_the_same_documented_api(): void
    {
        config()->set([
            'business_pro.environment' => 'production',
            'business_pro.cardcom.terminal_number' => 1234,
            'business_pro.cardcom.production_enabled' => true,
        ]);
        Http::fake(fn () => Http::response(['ResponseCode' => 0]));
        app(CardcomClient::class)->lowProfileResult(self::PROFILE_ID);
        Http::assertSent(fn (Request $request) => $request['TerminalNumber'] === 1234
            && $request->url() === CardcomClient::BASE_URL.'/LowProfile/GetLpResult');
    }

    #[DataProvider('unsafeCheckoutFields')]
    public function test_checkout_refuses_card_data_override_and_nonpublic_callback_urls(array $fields): void
    {
        Http::fake();
        try {
            app(CardcomClient::class)->createCheckout([...$this->checkout(), ...$fields]);
            $this->fail('Unsafe checkout fields were accepted.');
        } catch (CardcomException $exception) {
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public static function unsafeCheckoutFields(): array
    {
        return [
            'raw card' => [['CardNumber' => 'never-accept-card-number']],
            'credentials override' => [['ApiName' => 'different-terminal']],
            'amount precision' => [['Amount' => 49.001]],
            'nan amount' => [['Amount' => NAN]],
            'localhost webhook' => [['WebHookUrl' => 'https://localhost/webhook']],
            'private ip webhook' => [['WebHookUrl' => 'https://192.168.0.1/webhook']],
            'http webhook' => [['WebHookUrl' => 'http://example.com/webhook']],
            'callback credentials' => [['WebHookUrl' => 'https://name:secret@example.com/webhook']],
        ];
    }

    public function test_token_charge_refuses_raw_card_data_and_missing_idempotency_key(): void
    {
        Http::fake();
        foreach ([['CVV2' => '123'], ['CardNumber' => '4580280000000008'], ['ExternalUniqTranId' => '']] as $overrides) {
            try {
                app(CardcomClient::class)->chargeToken([...$this->charge(), ...$overrides]);
                $this->fail('Unsafe token charge accepted.');
            } catch (CardcomException $exception) {
                $this->assertSame('invalid_request', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_readiness_validation_sends_no_request(): void
    {
        Http::fake();
        app(CardcomClient::class)->validateCheckout($this->checkout());
        Http::assertNothingSent();
    }

    public function test_loopback_callbacks_are_only_allowed_for_explicit_local_sandbox_development(): void
    {
        config()->set('business_pro.cardcom.allow_local_callbacks', true);
        config()->set('app.env', 'testing');
        Http::fake();
        $fields = [...$this->checkout(), 'SuccessRedirectUrl' => 'http://127.0.0.1:5178/business-pro/payment/id',
            'WebHookUrl' => 'http://localhost:8000/api/v1/billing/cardcom/webhook'];
        app(CardcomClient::class)->validateCheckout($fields);
        foreach ([
            ['business_pro.environment' => 'production', 'business_pro.cardcom.terminal_number' => 1234, 'business_pro.cardcom.production_enabled' => true],
            ['business_pro.environment' => 'sandbox', 'business_pro.cardcom.terminal_number' => 1000, 'app.env' => 'production'],
        ] as $configuration) {
            config()->set($configuration);
            try {
                app(CardcomClient::class)->validateCheckout($fields);
                $this->fail('A production application must not accept loopback callbacks.');
            } catch (CardcomException $exception) {
                $this->assertSame('invalid_callback_url', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }

    private function checkout(): array
    {
        return [
            'Amount' => 49,
            'ReturnValue' => 'sveevee-payment-uuid',
            'SuccessRedirectUrl' => 'https://example.com/business-pro?result=success',
            'FailedRedirectUrl' => 'https://example.com/business-pro?result=failed',
            'CancelRedirectUrl' => 'https://example.com/business-pro?result=cancelled',
            'WebHookUrl' => 'https://example.com/api/cardcom/webhook',
            'ProductName' => 'Sveevee Business Pro',
            'Language' => 'he',
        ];
    }

    private function charge(): array
    {
        return [
            'Amount' => 49,
            'Token' => self::TOKEN,
            'CardExpirationMMYY' => '1230',
            'ExternalUniqTranId' => 'sveevee-payment-uuid',
        ];
    }
}
