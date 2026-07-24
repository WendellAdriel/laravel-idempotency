<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Enums;

use Illuminate\Http\Response;

enum ResponseCategory: string
{
    case Informational = 'informational';
    case Success = 'success';
    case Redirection = 'redirection';
    case ClientError = 'client_error';
    case ServerError = 'server_error';

    public static function fromStatusCode(int $status): self
    {
        return match (true) {
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => self::ServerError,
            $status >= Response::HTTP_BAD_REQUEST => self::ClientError,
            $status >= Response::HTTP_MULTIPLE_CHOICES => self::Redirection,
            $status >= Response::HTTP_OK => self::Success,
            default => self::Informational,
        };
    }

    /** @param array<string, bool> $statuses */
    public function isEnabledIn(array $statuses): bool
    {
        return $statuses[$this->value] ?? true;
    }
}
