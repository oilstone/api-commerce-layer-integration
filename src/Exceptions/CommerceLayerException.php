<?php

namespace Oilstone\ApiCommerceLayerIntegration\Exceptions;

use Psr\Http\Message\ResponseInterface;

class CommerceLayerException extends Exception
{
    public function __construct(string $message, protected int $statusCode = 500, protected array $errors = [])
    {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(ResponseInterface $response): static
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $errors = [];
        $message = 'Commerce Layer request failed.';

        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $errors = $data['errors'];
            $message = static::concatenateErrorMessages($errors, $message);
        } elseif (isset($data['error']) && is_string($data['error'])) {
            $message = $data['error'];
        } elseif ($body !== '') {
            $message = $body;
        }

        return new static($message, $status, $errors);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    protected static function concatenateErrorMessages(array $errors, string $fallback): string
    {
        $messages = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $detail = $error['detail'] ?? $error['title'] ?? null;

            if (is_string($detail) && $detail !== '') {
                $messages[] = $detail;
            }
        }

        if ($messages === []) {
            return $fallback;
        }

        return implode('; ', $messages);
    }
}
