<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a technician's private KYC document to a logged-in admin for the Filament panel
 * (session-authed, unlike the sanctum API). Only the fixed set of document kinds is served.
 */
class AdminTechnicianDocController extends Controller
{
    /** kind => encrypted column holding the private path. */
    private const DOCS = [
        'id_front' => 'id_front_url',
        'id_back' => 'id_back_url',
        'selfie' => 'selfie_url',
        'criminal_record' => 'criminal_record_url',
        'proof' => 'proof_url',
    ];

    public function __invoke(Request $request, Technician $technician, string $kind): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403);
        abort_unless(array_key_exists($kind, self::DOCS), 404);

        /** @var string|null $path */
        $path = $technician->{self::DOCS[$kind]};
        abort_if($path === null, 404, 'No document of this kind.');
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
