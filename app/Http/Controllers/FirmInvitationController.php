<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Models\FirmClientInvitation;
use App\Services\Firm\FirmInvitationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles a client accepting a firm invitation. The boundary is re-derived here
 * and never trusted from the URL: the token is matched by hash, the signed-in
 * user's email must equal the invited address, and only an owner may bind one of
 * their own businesses to the firm.
 */
class FirmInvitationController extends Controller
{
    public function __construct(private readonly FirmInvitationService $invitations) {}

    /**
     * Show the confirmation page listing the owner's businesses to assign.
     */
    public function show(Request $request, string $token): View
    {
        $invitation = $this->resolveInvitationOrAbort($token);
        $this->authorizeRecipient($request, $invitation);

        return view('firm.invitation-accept', [
            'token' => $token,
            'invitation' => $invitation,
            'firmName' => $invitation->accountingFirm?->name ?? 'The firm',
            'entities' => $request->user()->ownedEntities()->orderBy('name')->get(),
        ]);
    }

    /**
     * Bind the chosen business to the inviting firm and mark the invite accepted.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitationOrAbort($token);
        $this->authorizeRecipient($request, $invitation);

        $owner = $request->user();

        $validated = $request->validate([
            'business_entity_id' => ['required', 'string'],
        ]);

        $entity = BusinessEntity::query()
            ->whereKey($validated['business_entity_id'])
            ->where('owner_id', $owner->getKey())
            ->first();

        if ($entity === null) {
            throw ValidationException::withMessages([
                'business_entity_id' => 'Select one of your own businesses.',
            ]);
        }

        $this->invitations->accept($invitation, $entity, $owner);

        return redirect('/app/'.$entity->getKey())
            ->with('status', "{$invitation->accountingFirm?->name} now has access to {$entity->name}.");
    }

    private function resolveInvitationOrAbort(string $token): FirmClientInvitation
    {
        $invitation = $this->invitations->findPendingByToken($token);

        abort_if($invitation === null, Response::HTTP_NOT_FOUND);

        return $invitation->load('accountingFirm');
    }

    /**
     * The signed-in user must be the invited recipient (email match) and an
     * owner-role account, or the request is forbidden.
     */
    private function authorizeRecipient(Request $request, FirmClientInvitation $invitation): void
    {
        $user = $request->user();

        abort_unless($user->isOwner(), Response::HTTP_FORBIDDEN);
        abort_unless(
            hash_equals($invitation->email, Str::lower($user->email)),
            Response::HTTP_FORBIDDEN,
        );
    }
}
