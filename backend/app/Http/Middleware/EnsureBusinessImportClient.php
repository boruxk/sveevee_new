<?php

namespace App\Http\Middleware;

use App\Models\BusinessImportClient;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessImportClient extends EnsureClientIsResourceOwner
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $this->validateToken($request);
        $this->validate($token, ...$scopes);

        $clientId = (string) $token->oauth_client_id;
        $grantedScopes = array_values(array_filter(
            (array) ($token->oauth_scopes ?? []),
            fn ($scope): bool => is_string($scope) && $scope !== ''
        ));

        $request->attributes->set('oauth_client_id', $clientId);
        $request->attributes->set('oauth_access_token_id', (string) ($token->oauth_access_token_id ?? ''));
        $request->attributes->set('oauth_scopes', $grantedScopes);

        $client = BusinessImportClient::query()
            ->whereKey($clientId)
            ->where('active', true)
            ->first();

        if (! $client) {
            throw new AuthorizationException('This OAuth client is not enabled for business imports.');
        }

        $allowedScopes = array_values(array_filter((array) $client->allowed_scopes, 'is_string'));
        if (in_array('*', $grantedScopes, true) || array_diff($grantedScopes, $allowedScopes) !== []) {
            throw new AuthorizationException('The token contains scopes that are not allowed for this client.');
        }

        $request->attributes->set('business_import_client', $client);

        return $next($request);
    }
}
