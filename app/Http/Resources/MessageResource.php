<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'mine' => $user !== null && $this->sender_id === $user->id,
            'text' => $this->message_text,
            'has_image' => $this->image_url !== null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
