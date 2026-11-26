<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentExitReason;
use App\Http\Controllers\Concerns\ResolvesContactFilter;
use App\Models\Contact;
use App\Models\VisualAutomation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisualAutomationContactController extends Controller
{
    use ResolvesContactFilter;

    public function index(Request $request, int $visualAutomationId): Response
    {
        $automation = VisualAutomation::query()
            ->withCount('enrollments')
            ->findOrFail($visualAutomationId);

        $filter = $this->resolveFilter($request, ['completed', 'active', 'converted', 'exited']);

        $contacts = $this->paginateContacts(
            $this->contactsQuery($automation, $filter),
            $request,
        );

        return Inertia::render('visual-automations/Contacts', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'enrollments_count' => $automation->enrollments_count,
            ],
            'contacts' => $contacts,
            'filter' => $filter,
            'stats' => $this->stats($automation),
        ]);
    }

    /**
     * @return array{contacts: int, completed: int, active: int, converted: int, exited: int}
     */
    private function stats(VisualAutomation $automation): array
    {
        $enrollments = $automation->enrollments();

        return [
            'contacts' => (clone $enrollments)->count(),
            'completed' => (clone $enrollments)->where('status', 'completed')->count(),
            'active' => (clone $enrollments)->where('status', 'active')->count(),
            'converted' => (clone $enrollments)->where('exit_reason', EnrollmentExitReason::Goal)->count(),
            'exited' => (clone $enrollments)->where('exit_reason', EnrollmentExitReason::ExitCondition)->count(),
        ];
    }

    /**
     * @return Builder<Contact>
     */
    private function contactsQuery(VisualAutomation $automation, string $filter): Builder
    {
        $query = Contact::query()
            ->orderBy('name')
            ->orderBy('id');

        $scope = fn ($q) => $q->where('visual_automation_id', $automation->id);

        return $query->whereHas('visualAutomationEnrollments', fn ($q) => match ($filter) {
            'completed' => $scope($q)->where('status', 'completed'),
            'active' => $scope($q)->where('status', 'active'),
            'converted' => $scope($q)->where('exit_reason', EnrollmentExitReason::Goal),
            'exited' => $scope($q)->where('exit_reason', EnrollmentExitReason::ExitCondition),
            default => $scope($q),
        });
    }
}
