<?php

namespace App\Http\Controllers;

use App\Actions\SendSequenceStepTestMail;
use App\Exceptions\TestMailException;
use App\Models\Sequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SequenceStepTestMailController extends Controller
{
    public function __construct(private readonly SendSequenceStepTestMail $sender) {}

    public function __invoke(Request $request, int $sequenceId, int $sequenceStepId): RedirectResponse
    {
        $user = $request->user();

        $sequence = Sequence::query()
            ->findOrFail($sequenceId);

        $step = $sequence->steps()
            ->with(['message.emailIdentity'])
            ->findOrFail($sequenceStepId);

        $step->setRelation('sequence', $sequence);

        try {
            $this->sender->handle($user, $step);
        } catch (TestMailException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Test email sent to '.$user->email);
    }
}
