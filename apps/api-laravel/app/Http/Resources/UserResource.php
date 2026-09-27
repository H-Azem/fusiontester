<?php

namespace App\Http\Resources;

use App\Models\Contracts\UserInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard reads `{id, username, role}` and nothing else, so the resource
 * keeps to that contract rather than exposing the whole model.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            UserInterface::ID => $this->getId(),
            UserInterface::USERNAME => $this->getUsername(),
            UserInterface::ROLE => $this->getRole(),
        ];
    }
}
