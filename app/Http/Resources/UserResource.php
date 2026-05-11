<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'title' => $this->title,
            'status' => $this->status,
            'department' => $this->whenLoaded('department'),
            'skills' => $this->whenLoaded('skills'),
            'projects' => $this->whenLoaded('projects'),
            'leaves' => $this->whenLoaded('leaves'),
            'created_at' => $this->created_at,
        ];
    }
}
