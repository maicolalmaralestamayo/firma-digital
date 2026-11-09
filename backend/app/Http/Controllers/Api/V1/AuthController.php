<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use RespondsWithJsonApi;

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $this->claimInvitations($user);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return $this->jsonApiResponse([
            'data' => (new UserResource)->toArray($user),
            'meta' => [
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201, ['Location' => route('api.v1.auth.me')]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->whereRaw('lower(email) = ?', [$data['email']])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw new DomainException(
                401,
                'invalid_credentials',
                'Credenciales incorrectas',
                'El correo electrónico o la contraseña no son correctos.',
            );
        }

        $this->claimInvitations($user);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return $this->jsonApiResponse([
            'data' => (new UserResource)->toArray($user),
            'meta' => [
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'links' => ['self' => route('api.v1.auth.me')],
        ]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return $this->resourceResponse(new UserResource, $request->user());
    }

    /**
     * Pending invitations addressed to the new user's email become theirs as
     * soon as the account exists.
     */
    private function claimInvitations(User $user): void
    {
        DocumentSigner::query()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [strtolower($user->email)])
            ->whereHas('document', fn ($query) => $query->where('status', Document::STATUS_OPEN))
            ->update(['user_id' => $user->id]);
    }
}
