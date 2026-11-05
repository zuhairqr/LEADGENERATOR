<?php
namespace App\Services;

class ConnectorFailure extends \RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly ?int $httpStatus = null)
    {
        $messages = [
            'BAD_REQUEST'=>'Request rejected; check JSON and filters.',
            'INVALID_API_KEY'=>'API key was rejected.',
            'ACCESS_DENIED_OR_SCOPE'=>'API key or account lacks access to People Search.',
            'INVALID_SEARCH_FILTERS'=>'Search filters were rejected.',
            'RATE_LIMITED'=>'Provider rate limit reached.',
            'API_KEY_NOT_SAVED'=>'Save an API key in the private connector form.',
            'INVALID_PEOPLE_ARRAY'=>'Response did not contain a people array.',
            'INVALID_RESPONSE_JSON'=>'Provider returned invalid JSON.',
            'NETWORK_OR_ENDPOINT_ERROR'=>'Could not reach the provider endpoint.',
        ];
        $message = $messages[$safeCode] ?? $safeCode;
        parent::__construct($httpStatus ? "HTTP {$httpStatus}: {$message}" : $message);
    }
}
