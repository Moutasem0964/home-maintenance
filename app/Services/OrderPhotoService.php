<?php

namespace App\Services;

use App\Enums\OrderPhotoKind;
use App\Models\Order;
use App\Models\OrderPhoto;
use Illuminate\Http\UploadedFile;

class OrderPhotoService
{
    /**
     * Store uploaded photos on the private disk and record their paths against the order.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Order $order, array $files, OrderPhotoKind $kind, int $uploadedBy): void
    {
        foreach ($files as $file) {
            $path = $file->store("orders/{$order->id}/{$kind->value}", 'local');

            OrderPhoto::create([
                'order_id' => $order->id,
                'kind' => $kind,
                'path' => $path,
                'uploaded_by' => $uploadedBy,
            ]);
        }
    }
}
