<?php declare(strict_types=1);

namespace App\Controller\ActivityPub\User;

use Symfony\Component\HttpFoundation\JsonResponse;

class UserOutboxController
{
    public function __construct()
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse();
    }
}