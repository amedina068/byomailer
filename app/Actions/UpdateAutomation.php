<?php

namespace App\Actions;

use App\Models\Automation;
use App\Rules\SequenceMustBeLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAutomation
{
    /**
     * Save a new definition of the automation.
     *
     * The automation row is locked, and the live flag is read again. A live
     * automation must point to live sequences. Request validation checks
     * this too, but the automation can go live after that check.
     *
     * @param  array{name: string, type: string, condition: array<string, mixed>, actions: list<array<string, mixed>>}  $definition
     *
     * @throws ValidationException
     */
    public function handle(Automation $automation, array $definition): Automation
    {
        $locked = DB::transaction(function () use ($automation, $definition): Automation {
            $locked = Automation::query()->lockForUpdate()->findOrFail($automation->id);

            if ($locked->is_live) {
                $this->refuseSequencesThatAreNotLive($definition['actions']);
            }

            $locked->update($definition);

            return $locked;
        });

        $automation->setRawAttributes($locked->getAttributes(), true);

        return $automation;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     *
     * @throws ValidationException
     */
    private function refuseSequencesThatAreNotLive(array $actions): void
    {
        $errors = [];

        foreach ($actions as $index => $action) {
            if (($action['type'] ?? null) === 'add_sequence' && ! SequenceMustBeLive::allLive([$action['value'] ?? null])) {
                $errors["actions.{$index}.value"] = SequenceMustBeLive::MESSAGE;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
